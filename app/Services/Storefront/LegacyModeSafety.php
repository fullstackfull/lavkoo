<?php

namespace App\Services\Storefront;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\QueryException;
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
 * **It never answers "safe" because it could not look.** Three outcomes, not two:
 *
 *  - rows found: the platform is in that state, and an action that would open a market is refused;
 *  - nothing found, and the question was answerable: safe;
 *  - **the question could not be answered** — a database that cannot be reached, a connection that fails mid-query, a table whose schema
 *    cannot be read. That is NOT safe, and it is not the same as a table that is simply not there yet. A table absent before its migration
 *    is a fact about this installation, and the answer "no rows of another market" is then true. A database that cannot answer tells us
 *    nothing at all, and an operator must not read silence as a yes.
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
     * A table that is not there contributes nothing, which is true. A table that cannot be read contributes nothing **and** is reported by
     * {@see tablesThatCouldNotBeRead}, so a caller can tell the two apart.
     *
     * @return array<string, array<string, int>> market => table => rows
     */
    public function rowsOfOtherMarkets(): array
    {
        return $this->look()['rows'];
    }

    /**
     * The tables whose rows could not be read, each with what went wrong, or an empty list when every table answered.
     *
     * @return array<string, string> table => the kind of failure
     */
    public function tablesThatCouldNotBeRead(): array
    {
        return $this->look()['unreadable'];
    }

    /**
     * The refusal an action that would open a market gets, or null when there is nothing to refuse.
     *
     * Two different refusals, because they call for two different things from the operator: rows of another market while the switch is off
     * means *turn the switch back on and close the country instead*; a table that cannot be read means *find out why before you open
     * anything*, because the platform cannot tell whether it is in that state.
     */
    public function refusalOfAnActionThatOpensAMarket(): ?string
    {
        if (!$this->isLegacyMode()) {
            return null;
        }

        $looked = $this->look();

        if ($looked['unreadable'] !== []) {
            return 'Whether another market already holds rows cannot be read right now: ' . $this->unreadableInWords($looked['unreadable'])
                . '. While the storefronts switch is off, rows of another market are served as the default storefront\'s own, so a market is '
                . 'not opened on an answer nobody has. Find out why the table cannot be read, then ask again with '
                . '`php artisan gcc:storefront:safety`.';
        }

        if ($looked['rows'] === []) {
            return null;
        }

        return 'The storefronts switch is off while ' . $this->inWords($looked['rows']) . '. In that state nothing narrows the catalogue, so those rows '
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

    /** @param array<string, string> $unreadable */
    private function unreadableInWords(array $unreadable): string
    {
        $parts = [];

        foreach ($unreadable as $table => $because) {
            $parts[] = $table . ' (' . $because . ')';
        }

        return implode(', ', $parts);
    }

    /**
     * Asks both tables once, and keeps the two kinds of silence apart.
     *
     * @return array{rows: array<string, array<string, int>>, unreadable: array<string, string>}
     */
    private function look(): array
    {
        $default = StorefrontResolver::fromConfig()->defaultCountry();
        $rows = [];
        $unreadable = [];

        foreach (self::SOURCES as $table => $column) {
            $answer = $this->marketsIn(table: $table, column: $column, except: $default);

            if (is_string($answer)) {
                $unreadable[$table] = $answer;

                continue;
            }

            foreach ($answer as $market => $held) {
                $rows[$market][$table] = $held;
            }
        }

        ksort($rows);
        ksort($unreadable);

        return ['rows' => $rows, 'unreadable' => $unreadable];
    }

    /**
     * How many rows of this table each market other than the default one holds, or a description of why the question could not be answered.
     *
     * A table or a column that is **not there** answers an empty list: that is a fact about this installation, and "no market holds a row of
     * this table" is true of it. Anything else — the connection, the credentials, the driver, a query that fails — answers a string, because
     * the honest answer is "unknown" and an operator must not be told "safe" on the strength of a failed query.
     *
     * @return array<string, int>|string
     */
    private function marketsIn(string $table, string $column, string $except): array|string
    {
        try {
            if (!Schema::hasTable($table)) {
                return [];
            }

            if (!Schema::hasColumn($table, $column)) {
                return [];
            }

            $rows = DB::table($table)
                ->select($column, DB::raw('count(*) as rows_held'))
                ->whereNotNull($column)
                ->where($column, '!=', $except)
                ->groupBy($column)
                ->pluck('rows_held', $column);
        } catch (QueryException $theDatabaseCouldNotAnswer) {
            report($theDatabaseCouldNotAnswer);

            // The driver's own message names the database, the user and the SQL, and this answer is printed and logged: the kind of failure
            // is what an operator needs, and the rest is in the log the line above wrote.
            return 'the database refused the query';
        } catch (Throwable $somethingElseWentWrong) {
            report($somethingElseWentWrong);

            return 'the table could not be read (' . $somethingElseWentWrong::class . ')';
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
