<?php

namespace App\Services\Storefront;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The one state the platform must not be operated in, and the check that keeps an operator out of it (A1-02).
 *
 * The owner's decision of 2026-11-24 (`docs/gcc/DECISIONS.md`): the master storefront switch off means **legacy mode**, so the Saudi
 * behaviour stays what it is and nothing is narrowed; a single market is closed through its own country settings and its lifecycle, never by
 * turning that switch off; and once more than one market is live the switch **is not a kill switch**.
 *
 * What makes that a state rather than a preference: while the switch is off nothing narrows the catalogue, so the moment a second market
 * holds rows, every market's rows read as the default market's — a Kuwaiti product on the Saudi storefront, priced in the platform's money
 * and sold by the wrong company. The decision accepts that the code does not correct it (correcting it would contradict the Saudi
 * compatibility the switch exists to protect) and requires instead that nobody operate the platform there unknowingly.
 *
 * So this class answers one question — *is the platform in legacy mode while another market already holds rows?* — and the actions that
 * would **open** a market ask it first and refuse. The actions that **close** one never ask: pausing, resuming and switching a country off
 * are the way out of this state, and a guard that blocked the way out would be worse than the state.
 *
 * It only reads, and it reads the two tables that decide it: the products a market owns and the orders it sold. A table or a column that is
 * not there, and a database that cannot be reached, all answer "nothing found" — so an installation that has not run the migrations, and one
 * whose database is down, are never told they are unsafe on the strength of a failed query.
 */
#[Singleton]
final class LegacyModeSafety
{
    /** The tables that say a market holds something of its own, and the column each keeps its market in. */
    private const SOURCES = ['products' => StorefrontCatalog::COLUMN, 'orders' => OrderStorefrontSnapshot::COLUMN];

    /** Whether the platform is running in legacy mode: the storefronts switch is off, which is every Saudi-only installation. */
    public function isLegacyMode(): bool
    {
        return !(bool) config('gcc.storefronts.enabled');
    }

    /**
     * What each market other than the default one holds, by table, leaving out the markets that hold nothing.
     *
     * @return array<string, array<string, int>> market => table => rows
     */
    public function rowsOfOtherMarkets(): array
    {
        $default = StorefrontResolver::fromConfig()->defaultCountry();
        $found = [];

        foreach (self::SOURCES as $table => $column) {
            foreach ($this->marketsIn(table: $table, column: $column, except: $default) as $market => $rows) {
                $found[$market][$table] = $rows;
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * The refusal an action that would open a market gets while the platform is in legacy mode and another market already holds rows, or
     * null when there is nothing to refuse — which is every installation that serves one market, and every installation whose switch is on.
     */
    public function refusalOfAnActionThatOpensAMarket(): ?string
    {
        if (!$this->isLegacyMode()) {
            return null;
        }

        $held = $this->rowsOfOtherMarkets();

        if ($held === []) {
            return null;
        }

        return 'The storefronts switch is off while ' . $this->inWords($held) . '. In that state nothing narrows the catalogue, so those rows '
            . 'are served as the default storefront\'s own, and the switch is not a way to close a market (docs/gcc/DECISIONS.md, 2026-11-24). '
            . 'Turn GCC_STOREFRONTS_ENABLED back on, then close the country itself: set its `active` to false in config/gcc.php, or pause it '
            . 'with `php artisan gcc:storefront:state <CC> pause`. `php artisan gcc:storefront:safety` reports what is held.';
    }

    /** @param array<string, array<string, int>> $held */
    private function inWords(array $held): string
    {
        $markets = [];

        foreach ($held as $market => $tables) {
            $parts = [];

            foreach ($tables as $table => $rows) {
                $parts[] = $rows . ' ' . $table;
            }

            $markets[] = $market . ' holds ' . implode(' and ', $parts);
        }

        return implode(', ', $markets);
    }

    /**
     * How many rows of this table each market other than the default one holds. Anything that stops the question being asked — no table, no
     * column, no database — answers nothing found.
     *
     * @return array<string, int>
     */
    private function marketsIn(string $table, string $column, string $except): array
    {
        try {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
                return [];
            }

            $rows = DB::table($table)
                ->select($column, DB::raw('count(*) as rows_held'))
                ->whereNotNull($column)
                ->where($column, '!=', $except)
                ->groupBy($column)
                ->pluck('rows_held', $column);
        } catch (Throwable) {
            return [];
        }

        $held = [];

        foreach ($rows as $market => $count) {
            $code = strtoupper(trim((string) $market));

            if ($code !== '' && $code !== $except) {
                $held[$code] = (int) $count;
            }
        }

        return $held;
    }
}
