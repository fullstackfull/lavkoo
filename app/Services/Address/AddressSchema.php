<?php

namespace App\Services\Address;

use App\Models\AddressCity;
use App\Models\BusinessSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Is the structured address available on this database, and how strictly is it applied?
 *
 * Code is deployed before its migrations run and migrations run before the city list is imported, so every place that touches
 * the feature asks here first and otherwise behaves exactly as the store did before. The mode is a business setting an
 * administrator changes on purpose:
 *
 *   off     the old free-text address everywhere (the default)
 *   soft    customers on the website and staff in the panel must use the structured address for Saudi addresses; the mobile app
 *           and other older clients may still send the old fields; those orders are accepted, and at fulfilment their
 *           typed city is used only when it is certain, otherwise a person looks at the order first
 *   strict  every client must send the structured address for Saudi addresses
 */
final class AddressSchema
{
    public const SETTING = 'unified_address_mode';

    public const ZIP_RESTRICTION_SETTING = 'delivery_zip_code_area_restriction';

    public const MODE_OFF = 'off';

    public const MODE_SOFT = 'soft';

    public const MODE_STRICT = 'strict';

    public const MODES = [self::MODE_OFF, self::MODE_SOFT, self::MODE_STRICT];

    public const ADDRESS_COLUMNS = ['region_id', 'city_id', 'district', 'national_address'];

    /** What a provider's city list keeps in `provider_cities` beyond the old columns (see the directory migration). */
    public const DIRECTORY_COLUMNS = ['region_name_ar', 'region_name_en', 'prefix', 'is_selected', 'last_seen_at', 'missing_since'];

    /**
     * How long a "no" is remembered. A web request lives for less than that, so it asks the database once; a queue worker or a scheduler
     * that was started before the migration or the import asks again within this time and notices it, without a restart. A "yes" about
     * the schema is kept for the life of the process: tables do not disappear under a running store.
     */
    private const NEGATIVE_ANSWER_SECONDS = 30;

    /** @var array<string, array{value: bool, at: int}> */
    private static array $answers = [];

    public static function mastersReady(): bool
    {
        return self::remembered('masters', static fn (): bool => Schema::hasTable('address_regions') && Schema::hasTable('address_cities') && Schema::hasTable('address_city_aliases'));
    }

    public static function columnsReady(): bool
    {
        return self::remembered('columns', static function (): bool {
            if (!Schema::hasTable('shipping_addresses')) {
                return false;
            }

            foreach (self::ADDRESS_COLUMNS as $column) {
                if (!Schema::hasColumn('shipping_addresses', $column)) {
                    return false;
                }
            }

            return true;
        });
    }

    /** Provider mappings and the city sync history: needed by the shipping integrations, not by the address book. */
    public static function mappingsReady(): bool
    {
        return self::remembered('mappings', static fn (): bool => Schema::hasTable('provider_city_mappings') && Schema::hasTable('provider_city_sync_runs'));
    }

    /** The columns a provider's city list promotes out of its records into `provider_cities`. */
    public static function directoryColumnsReady(): bool
    {
        return self::remembered('directory', static fn (): bool => Schema::hasTable('provider_cities') && Schema::hasColumns('provider_cities', self::DIRECTORY_COLUMNS));
    }

    public static function ready(): bool
    {
        return self::mastersReady() && self::columnsReady();
    }

    /**
     * Can a customer finish an address? Only a city that is active in a region that is active can be chosen, so an empty list, or a list
     * whose regions are all switched off, must never lock customers out of checkout.
     */
    public static function hasCities(): bool
    {
        if (!self::mastersReady()) {
            return false;
        }

        return self::remembered(
            'cities',
            static fn (): bool => AddressCity::query()->active()->whereHas('region', static fn (Builder $query) => $query->active())->exists(),
            keepYes: false,
        );
    }

    public static function configuredMode(): string
    {
        $value = function_exists('getWebConfig') ? getWebConfig(name: self::SETTING) : null;

        return is_string($value) && in_array($value, self::MODES, true) ? $value : self::MODE_OFF;
    }

    /** Is the ZIP-code delivery restriction switched on? Absent means off. */
    public static function zipRestrictionOn(): bool
    {
        return function_exists('getWebConfig') && (bool) getWebConfig(name: self::ZIP_RESTRICTION_SETTING);
    }

    /**
     * The mode in force: `off` unless the schema, the city list and the setting all say otherwise, and always `off` while the ZIP-code
     * delivery restriction is on. A ZIP allow-list cannot be evaluated for an address that carries only a National Address (the ZIP is never
     * derived from it), so the store keeps asking for the ZIP code and keeps applying the restriction exactly as before.
     */
    public static function mode(): string
    {
        if (!self::ready() || !self::hasCities() || self::zipRestrictionOn()) {
            return self::MODE_OFF;
        }

        return self::configuredMode();
    }

    public static function enabled(): bool
    {
        return self::mode() !== self::MODE_OFF;
    }

    public static function strict(): bool
    {
        return self::mode() === self::MODE_STRICT;
    }

    public static function setMode(string $mode): void
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('Unknown address mode.');
        }

        BusinessSetting::updateOrCreate(['type' => self::SETTING], ['value' => json_encode($mode)]);

        if (function_exists('clearWebConfigCacheKeys')) {
            clearWebConfigCacheKeys();
        }
    }

    public static function flush(): void
    {
        self::$answers = [];
    }

    /** @param bool $keepYes false for an answer that can turn from yes to no while the process runs (the last active city is switched off) */
    private static function remembered(string $question, callable $ask, bool $keepYes = true): bool
    {
        $known = self::$answers[$question] ?? null;
        if ($known !== null && $known['value'] && $keepYes) {
            return true;
        }

        $now = now()->getTimestamp();
        if ($known !== null && $now - $known['at'] < self::NEGATIVE_ANSWER_SECONDS) {
            return $known['value'];
        }

        self::$answers[$question] = ['value' => $value = $ask(), 'at' => $now];

        return $value;
    }
}
