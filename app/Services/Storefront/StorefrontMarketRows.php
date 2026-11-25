<?php

namespace App\Services\Storefront;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
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

        return $this->columnReady[$table] ??= Schema::hasTable($table) && Schema::hasColumn($table, self::COLUMN);
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

    public function forget(): void
    {
        $this->columnReady = [];
        $this->ownRows = [];
    }

    /** The storefront whose rows are the ones that name no market. */
    private function defaultCountry(): string
    {
        $configured = strtoupper(trim((string) config('gcc.storefronts.default_country')));

        return $configured === '' ? self::UNMARKED_ROWS_BELONG_TO : $configured;
    }
}
