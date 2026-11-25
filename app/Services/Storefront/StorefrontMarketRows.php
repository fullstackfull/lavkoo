<?php

namespace App\Services\Storefront;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The one rule for reading rows that belong to a market, for every table that has them (H1-14).
 *
 * The platform's decision is that local shipping rules belong to a storefront and its selling company and are never inherited by another. The rule for
 * reading them is subtle enough that a second copy of it would drift:
 *
 *  - a row with NO market is the DEFAULT storefront's, because every such row on this platform was made for the only storefront there has ever been;
 *  - so the default market reads its own rows AND the unmarked ones, and every other market reads only rows that name it;
 *  - a market with no rows of its own therefore reads none, which is the correct answer and not an accident: giving it another market's rows is the
 *    inheritance the decision forbids;
 *  - the market is compared BYTE FOR BYTE, as the columns are declared (ascii_bin) and as PHP compares it, so the value written must be upper case and
 *    that is what `stampFor()` is for;
 *  - and none of it applies while `gcc.storefronts.enabled` is off or before the table has the column, when nothing is narrowed at all and every listing
 *    is the listing this platform has always had.
 *
 * Each table gets a small class of its own for its own vocabulary (`StorefrontShippingMethods`, `StorefrontCategoryShippingCosts`); the rule lives here
 * once. One instance serves a whole request, because a basket of twenty lines asks these questions twenty times.
 */
#[Singleton]
final class StorefrontMarketRows
{
    public const COLUMN = 'storefront_country';

    /**
     * The market the unmarked rows belong to while the configuration names no default.
     *
     * Read from the configuration and not through `StorefrontResolver`, which refuses to be built on a default it cannot serve: a shipping
     * listing must never throw because the default storefront is misconfigured, and the honest answer when it is, is the market whose rows
     * these unmarked ones were written for.
     */
    private const UNMARKED_ROWS_BELONG_TO = 'SA';

    /** @var array<string, bool> */
    private array $columnReady = [];

    /** @var array<string, bool> */
    private array $ownRows = [];

    /** Whether rows of this table can carry a market at all, and whether anything should read it. */
    public function applies(string $table): bool
    {
        if (!(bool) config('gcc.storefronts.enabled', false)) {
            return false;
        }

        return $this->tableCarriesAMarket($table);
    }

    /**
     * Narrows a query to the storefront given, or to the one the request is being served from.
     *
     * @param Builder<covariant \Illuminate\Database\Eloquent\Model> $query
     */
    public function narrow(Builder $query, string $table, ?string $country = null): Builder
    {
        if (!$this->applies($table)) {
            return $query;
        }

        $market = $this->stampFor($table, $country);

        if ($market === null) {
            return $query;
        }

        return $market === $this->defaultCountry()
            ? $query->where(fn (Builder $scoped): Builder => $scoped->where(self::COLUMN, $market)->orWhereNull(self::COLUMN))
            : $query->where(self::COLUMN, $market);
    }

    /**
     * Whether ONE row, already in hand, is served in the market given, or in the one the request is being served from.
     *
     * The same decision `narrow()` makes in SQL, for a caller that holds the rows rather than a query: a set small enough to be cached whole
     * and filtered per request, which is what the SEO redirect rules are. It is here and not written out at the caller for the reason the
     * rest of this class exists -- there is one rule about which market a row belongs to, and an unmarked row is the default storefront's.
     */
    public function rowIsServedIn(string $table, ?string $rowMarket, ?string $country = null): bool
    {
        if (!$this->applies($table)) {
            return true;
        }

        $market = $this->stampFor($table, $country);

        if ($market === null) {
            return true;
        }

        $row = trim((string) $rowMarket) === '' ? null : strtoupper(trim((string) $rowMarket));

        return $row === $market || ($row === null && $market === $this->defaultCountry());
    }

    /**
     * Whether this market has rows of its OWN in this table -- rows that name it, not the unmarked rows of the default storefront.
     *
     * Asked of the money: a market whose rows are its own has costs typed in its own currency, and the unmarked ones never are.
     */
    public function marketOwnsRows(string $table, ?string $country = null, ?string $activeColumn = 'status'): bool
    {
        if (!$this->applies($table)) {
            return false;
        }

        $market = $this->stampFor($table, $country);

        if ($market === null) {
            return false;
        }

        return $this->ownRows["{$table}:{$market}"] ??= DB::table($table)
            ->where(self::COLUMN, $market)
            ->when(
                $activeColumn !== null && Schema::hasColumn($table, $activeColumn),
                static fn ($query) => $query->where($activeColumn, 1),
            )
            ->exists();
    }

    /** The market a row is given when one is made in a storefront, or null while this does not apply to that table. */
    public function stampFor(string $table, ?string $country = null): ?string
    {
        if (!$this->applies($table)) {
            return null;
        }

        $market = strtoupper(trim((string) ($country ?? StorefrontContext::current()->country)));

        return $market === '' ? null : $market;
    }

    /**
     * Whether every one of these rows exists and belongs to one of these countries (GCC-14c slice 6; H1-14).
     *
     * The same rule as {@see narrow()}, asked of the countries a STAFF MEMBER works with rather than of the storefront a request is served
     * from: a row that names no market is the DEFAULT storefront's, so holding the default market covers the unmarked rows and holding any
     * other does not. It does NOT ask whether storefronts are switched on, because the question is who may change a row rather than which
     * rows a shop offers: before the table has the column every row is the default storefront's, since that is the only market this platform
     * has ever had rows for, and an employee who does not hold it holds none of them.
     *
     * A row that does not exist is not covered, so which ids are rows is not something an employee can find out by asking.
     *
     * @param list<int|string> $ids
     * @param list<string> $countries
     */
    public function allRowsBelongTo(string $table, array $ids, array $countries, string $idColumn = 'id'): bool
    {
        $ids = array_values(array_unique($ids));

        if ($ids === [] || $countries === []) {
            return $ids === [];
        }

        $holdsTheDefault = in_array($this->defaultCountry(), $countries, true);
        $rows = DB::table($table)->whereIn($idColumn, $ids);

        if (!$this->tableCarriesAMarket($table)) {
            return $holdsTheDefault && $rows->count() === count($ids);
        }

        $rows->where(static function (QueryBuilder $inner) use ($countries, $holdsTheDefault): void {
            $inner->whereIn(self::COLUMN, $countries);

            if ($holdsTheDefault) {
                $inner->orWhereNull(self::COLUMN);
            }
        });

        return $rows->count() === count($ids);
    }

    /**
     * Narrows a listing to the countries a STAFF MEMBER works with, by the same rule as everything else here.
     *
     * The same answer `allRowsBelongTo()` gives one row at a time, asked of a whole query: the rows that name one of those countries, and the
     * unmarked rows as well when the employee holds the default market. An employee restricted to NOTHING sees nothing, and before the table
     * carries a market every row is the default storefront's -- so an employee who does not hold it sees none of them, which is the same
     * answer and not a special case.
     *
     * It narrows whatever the switch says, because the caller decides whether to narrow at all: the only caller is the global scope, which
     * asks only for a request that a country was bound to.
     *
     * @param Builder<covariant \Illuminate\Database\Eloquent\Model> $query
     * @param list<string> $countries
     */
    public function narrowToCountries(Builder $query, string $table, array $countries): Builder
    {
        if ($countries === []) {
            return $query->whereRaw('1 = 0');
        }

        $holdsTheDefault = in_array($this->defaultCountry(), $countries, true);

        if (!$this->tableCarriesAMarket($table)) {
            return $holdsTheDefault ? $query : $query->whereRaw('1 = 0');
        }

        $column = $query->getModel()->qualifyColumn(self::COLUMN);

        return $query->where(static function (Builder $inner) use ($column, $countries, $holdsTheDefault): void {
            $inner->whereIn($column, $countries);

            if ($holdsTheDefault) {
                $inner->orWhereNull($column);
            }
        });
    }

    /**
     * The market of one row, as a key a writer can add to the row it means to change, or an empty array while the table has no market column.
     *
     * A writer that keys a row by anything else -- a category and a seller, say -- would match the row of whichever market the database
     * happened to answer with, so a market's own cost could be overwritten from another market's screen. An id that names no row answers
     * NULL, which is the default storefront's row and is what such a write made before there were markets.
     *
     * @return array<string, string|null>
     */
    public function marketKeyOf(string $table, int|string|null $id): array
    {
        if (!$this->tableCarriesAMarket($table)) {
            return [];
        }

        $market = $id === null ? null : DB::table($table)->where('id', $id)->value(self::COLUMN);

        return [self::COLUMN => is_string($market) && $market !== '' ? $market : null];
    }

    public function forget(): void
    {
        $this->columnReady = [];
        $this->ownRows = [];
    }

    /** Whether the table is there and carries the market column: a fact about the database, which a writer needs as much as a reader. */
    public function tableHasTheColumn(string $table): bool
    {
        return $this->tableCarriesAMarket($table);
    }

    /** Whether the table is there and carries the market column. Asked without the switch, because who may CHANGE a row is not a question about which shop is open. */
    private function tableCarriesAMarket(string $table): bool
    {
        return $this->columnReady[$table] ??= Schema::hasTable($table) && Schema::hasColumn($table, self::COLUMN);
    }

    /** The storefront whose rows are the ones that name no market. */
    /**
     * The market the unmarked rows of every table belong to. Public because it is the ONE answer to "which market is the default one" that
     * every other market-owning rule has to agree with: a second copy of it, spelt `'SA'` at another call site, is how two tables come to
     * disagree, and it is also another Saudi literal for SaudiLiteralsAllowListTest to carry.
     */
    public function defaultCountry(): string
    {
        $configured = strtoupper(trim((string) config('gcc.storefronts.default_country')));

        return $configured === '' ? self::UNMARKED_ROWS_BELONG_TO : $configured;
    }
}
