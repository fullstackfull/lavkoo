<?php

namespace App\Services\Storefront;

use App\Models\StorefrontEntityVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a catalogue entity is visible in a storefront (B-11, B-13, B-15, B-16).
 *
 * One mapping table, one answer, one query helper the readers share. The central row of a category, a brand, a banner, a coupon or a
 * campaign is never touched and never carries a country: hiding is a mapping, so removing the mapping restores the entity exactly.
 *
 * Two entity types, two different rules, kept apart on purpose:
 *
 *  - {@see MODE_HIDDEN} (category, brand) — visible everywhere until a row hides it in one storefront. No row is today's behaviour.
 *  - {@see MODE_ASSIGNED} (banner, coupon, deal, clearance sale, campaign, attribute) — a storefront shows its own plus the unassigned
 *    ones: an entity with no row at all is shown everywhere, and one with any row is shown only where its rows name.
 *
 * A further entity type costs one line of {@see ENTITY_MODES} and the trait on its model, not a table — that is what the attribute type
 * (B-12) cost on the storefront side. A panel screen that works on one country's catalogue while belonging to no storefront names the
 * country instead, through {@see offeredIn()}; that is the one thing the storefront-request narrowing could not express.
 *
 * Nothing is narrowed while storefronts are switched off, for the admin and vendor panels, the seller APIs, a queue or a console
 * command, before this migration has run, or — so the live storefront keeps the queries it has today — while no mapping row exists for
 * the entity type at all.
 */
final class StorefrontVisibility
{
    public const MODE_HIDDEN = 'hidden';

    public const MODE_ASSIGNED = 'assigned';

    public const TABLE = 'storefront_entity_visibility';

    public const CATEGORY = 'category';

    public const BRAND = 'brand';

    public const BANNER = 'banner';

    public const COUPON = 'coupon';

    public const FLASH_DEAL = 'flash_deal';

    public const DEAL_OF_THE_DAY = 'deal_of_the_day';

    public const CLEARANCE_SALE = 'clearance_sale';

    public const EXPERIENCE_CAMPAIGN = 'experience_campaign';

    public const ATTRIBUTE = 'attribute';

    /** What a mapping row means for each entity type. Read this twice before adding one: the two modes are opposite defaults. */
    private const ENTITY_MODES = [
        self::CATEGORY => self::MODE_HIDDEN,
        self::BRAND => self::MODE_HIDDEN,
        self::BANNER => self::MODE_ASSIGNED,
        self::COUPON => self::MODE_ASSIGNED,
        self::FLASH_DEAL => self::MODE_ASSIGNED,
        self::DEAL_OF_THE_DAY => self::MODE_ASSIGNED,
        self::CLEARANCE_SALE => self::MODE_ASSIGNED,
        self::EXPERIENCE_CAMPAIGN => self::MODE_ASSIGNED,
        self::ATTRIBUTE => self::MODE_ASSIGNED,
    ];

    /** How long the table's presence and the mapped entity types are remembered, so a worker notices a migration or a first mapping without a restart. */
    private const ANSWER_SECONDS = 30;

    /** @var array{value: bool, at: int}|null */
    private static ?array $tableAnswer = null;

    /** @var array{value: list<string>, at: int}|null */
    private static ?array $mappedTypesAnswer = null;

    /** @return list<string> every entity type a mapping may be written for */
    public static function entityTypes(): array
    {
        return array_keys(self::ENTITY_MODES);
    }

    /** What a mapping row means for this entity type, or null when the type has none. */
    public static function modeOf(string $entityType): ?string
    {
        return self::ENTITY_MODES[$entityType] ?? null;
    }

    /** Whether mappings can be read and written at all: storefronts are on and the mapping migration has run. */
    public static function isTracked(): bool
    {
        return (bool) config('gcc.storefronts.enabled') && self::tableReady();
    }

    /**
     * The one query helper every reader shares, through the global scope or by hand where a reader drops every scope. It adds a
     * predicate only when this request belongs to a storefront and the entity type has at least one mapping row; otherwise the query is
     * handed back exactly as it came in.
     *
     * @param string|null $idColumn the column holding the entity's id, for a query that reaches the table through a join; the model's
     *                              own key by default
     */
    public static function restrict(Builder $query, string $entityType, ?string $idColumn = null): Builder
    {
        $country = self::narrowedCountry($entityType);

        if ($country === null) {
            return $query;
        }

        return self::narrowFor(
            query: $query,
            entityType: $entityType,
            idColumn: $idColumn ?? $query->qualifyColumn($query->getModel()->getKeyName()),
            country: $country,
        );
    }

    /**
     * Narrows a list to what a *named* storefront offers, rather than to the one the request is served from (B-12).
     *
     * The admin and vendor panels belong to no storefront on purpose — that is what keeps every central row reachable there — so a panel
     * screen that works on one country's catalogue, such as the attribute picker on a product form, has no storefront to be narrowed by.
     * It names the country instead. Same mapping, same two modes, same predicate: no second table and no second scope.
     *
     * Rows whose ids are in $alwaysOffered are kept whatever the mapping says, so an entity a row already carries is never dropped from
     * the form that edits it. Nothing is narrowed while the mechanism is dormant, nor for a country that is not configured, and then the
     * query is handed back exactly as it came in — ids and all.
     *
     * @param list<int|string> $alwaysOffered
     */
    public static function offeredIn(Builder $query, string $entityType, string $country, array $alwaysOffered = []): Builder
    {
        $country = self::narrowedCountryOf(entityType: $entityType, country: $country);

        if ($country === null) {
            return $query;
        }

        $idColumn = $query->qualifyColumn($query->getModel()->getKeyName());

        return $query->where(static function (Builder $offered) use ($entityType, $idColumn, $country, $alwaysOffered): void {
            self::narrowFor(query: $offered, entityType: $entityType, idColumn: $idColumn, country: $country);

            if ($alwaysOffered !== []) {
                $offered->orWhereIn($idColumn, $alwaysOffered);
            }
        });
    }

    /**
     * Whether this storefront shows the entity. The country is the one given, else the storefront the request is served from; a request
     * that belongs to no storefront, and an entity type with no mapping rows, sees everything.
     */
    public static function isVisible(string $entityType, int|string $entityId, ?string $country = null): bool
    {
        $country = $country === null ? self::narrowedCountry($entityType) : strtoupper($country);

        if ($country === null || !isset(self::ENTITY_MODES[$entityType]) || !self::isTracked()) {
            return true;
        }

        $mode = self::ENTITY_MODES[$entityType];
        $rows = self::rowsOf(entityType: $entityType, entityId: $entityId, mode: $mode);

        return $mode === self::MODE_HIDDEN
            ? !in_array($country, $rows, true)
            : ($rows === [] || in_array($country, $rows, true));
    }

    /**
     * The storefronts this entity's mapping names: the ones it is hidden in, or the ones it is assigned to, by the entity type's mode.
     *
     * @return list<string>
     */
    public static function storefrontsOf(string $entityType, int|string $entityId): array
    {
        $mode = self::ENTITY_MODES[$entityType] ?? null;

        if ($mode === null || !self::isTracked()) {
            return [];
        }

        return self::rowsOf(entityType: $entityType, entityId: $entityId, mode: $mode);
    }

    /**
     * Replaces the storefronts this entity's mapping names, keeping the central row untouched. An empty list removes the mapping, which
     * restores the entity in every storefront. A country that is not configured is dropped rather than stored.
     *
     * @param list<string> $countries
     */
    public static function set(string $entityType, int|string $entityId, array $countries): void
    {
        $mode = self::ENTITY_MODES[$entityType] ?? null;

        if ($mode === null || !self::isTracked()) {
            return;
        }

        $configured = StorefrontResolver::fromConfig()->configuredCountries();
        $wanted = array_values(array_unique(array_filter(
            array_map(static fn ($country): string => strtoupper(trim((string) $country)), $countries),
            static fn (string $country): bool => in_array($country, $configured, true),
        )));

        StorefrontEntityVisibility::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->when($wanted !== [], fn (Builder $query) => $query->whereNotIn('storefront_country', $wanted))
            ->delete();

        foreach ($wanted as $country) {
            StorefrontEntityVisibility::query()->updateOrCreate(
                ['entity_type' => $entityType, 'entity_id' => $entityId, 'storefront_country' => $country],
                ['mode' => $mode],
            );
        }

        self::flush();
    }

    /** Forgets what was learnt about the mapping, for a test that builds its own tables and for a write that has just changed it. */
    public static function flush(): void
    {
        self::$tableAnswer = null;
        self::$mappedTypesAnswer = null;
    }

    /** The storefront whose answer this query must give, or null when nothing is narrowed for this entity type. */
    private static function narrowedCountry(string $entityType): ?string
    {
        return self::narrows($entityType) ? StorefrontCatalog::country() : null;
    }

    /**
     * The storefront a caller named, on the same two conditions a storefront request is narrowed by. A country that is not configured
     * narrows nothing, so a stale or mistyped code shows the whole list instead of an empty one.
     */
    private static function narrowedCountryOf(string $entityType, string $country): ?string
    {
        if (!self::narrows($entityType)) {
            return null;
        }

        $country = strtoupper(trim($country));

        return in_array($country, StorefrontResolver::fromConfig()->configuredCountries(), true) ? $country : null;
    }

    /** Whether this entity type is narrowed at all: the mechanism is tracked and the type has at least one mapping row. */
    private static function narrows(string $entityType): bool
    {
        return isset(self::ENTITY_MODES[$entityType])
            && self::isTracked()
            && in_array($entityType, self::mappedEntityTypes(), true);
    }

    /** The entity type's own rule, the one place the two opposite modes are chosen between. */
    private static function narrowFor(Builder $query, string $entityType, string $idColumn, string $country): Builder
    {
        return self::ENTITY_MODES[$entityType] === self::MODE_HIDDEN
            ? self::narrowToVisible(query: $query, entityType: $entityType, idColumn: $idColumn, country: $country)
            : self::narrowToAssigned(query: $query, entityType: $entityType, idColumn: $idColumn, country: $country);
    }

    /** @return list<string> */
    private static function rowsOf(string $entityType, int|string $entityId, string $mode): array
    {
        return DB::table(self::TABLE)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('mode', $mode)
            ->pluck('storefront_country')
            ->map(static fn ($country): string => strtoupper((string) $country))
            ->values()
            ->all();
    }

    private static function narrowToVisible(Builder $query, string $entityType, string $idColumn, string $country): Builder
    {
        return $query->whereNotExists(static fn (QueryBuilder $mapping) => self::mappingRowsOf(
            mapping: $mapping,
            entityType: $entityType,
            idColumn: $idColumn,
            mode: self::MODE_HIDDEN,
        )->where('storefront_country', $country));
    }

    private static function narrowToAssigned(Builder $query, string $entityType, string $idColumn, string $country): Builder
    {
        return $query->where(static function (Builder $inner) use ($entityType, $idColumn, $country): void {
            $inner
                ->whereNotExists(static fn (QueryBuilder $mapping) => self::mappingRowsOf(
                    mapping: $mapping,
                    entityType: $entityType,
                    idColumn: $idColumn,
                    mode: self::MODE_ASSIGNED,
                ))
                ->orWhereExists(static fn (QueryBuilder $mapping) => self::mappingRowsOf(
                    mapping: $mapping,
                    entityType: $entityType,
                    idColumn: $idColumn,
                    mode: self::MODE_ASSIGNED,
                )->where('storefront_country', $country));
        });
    }

    private static function mappingRowsOf(QueryBuilder $mapping, string $entityType, string $idColumn, string $mode): QueryBuilder
    {
        return $mapping
            ->selectRaw('1')
            ->from(self::TABLE)
            ->whereColumn(self::TABLE . '.entity_id', $idColumn)
            ->where(self::TABLE . '.entity_type', $entityType)
            ->where(self::TABLE . '.mode', $mode);
    }

    /**
     * The entity types a mapping row exists for. While a type has none, its readers keep the query they have today, which is what the
     * live Saudi storefront runs: an empty mapping table changes no SQL anywhere.
     *
     * @return list<string>
     */
    private static function mappedEntityTypes(): array
    {
        $answer = self::$mappedTypesAnswer;

        if ($answer !== null && time() - $answer['at'] < self::ANSWER_SECONDS) {
            return $answer['value'];
        }

        $types = DB::table(self::TABLE)->distinct()->pluck('entity_type')->map(static fn ($type): string => (string) $type)->values()->all();
        self::$mappedTypesAnswer = ['value' => $types, 'at' => time()];

        return $types;
    }

    private static function tableReady(): bool
    {
        $answer = self::$tableAnswer;

        if ($answer !== null && ($answer['value'] || time() - $answer['at'] < self::ANSWER_SECONDS)) {
            return $answer['value'];
        }

        $ready = Schema::hasTable(self::TABLE);
        self::$tableAnswer = ['value' => $ready, 'at' => time()];

        return $ready;
    }
}
