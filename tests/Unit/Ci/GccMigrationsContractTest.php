<?php

namespace Tests\Unit\Ci;

use PHPUnit\Framework\TestCase;

/**
 * Every migration this programme adds can be run again after a run that stopped half way, and only ever adds (M2-16).
 *
 * ## Why a half-applied run is the case that matters
 *
 * A deploy of six markets onto a live single-market shop runs these migrations against a database nobody here has seen. A statement that
 * takes a metadata lock and times out, a connection the proxy drops, a runner killed by its own ceiling: each leaves the batch PART applied,
 * and Laravel has no record of a migration it did not finish, so `migrate` runs that file again from the top. An unguarded
 * `Schema::create('platform_admin_grants', ...)` then meets its own table and the deploy dies on the second attempt, with the first half
 * already in place and no way forward but by hand. The guard is one line and the absence of it is only visible on the day it costs the most,
 * which is why this is a scan and not a review note.
 *
 * ## What it reads, and why from the source
 *
 * The migration SOURCE, not a database: a new migration is caught by the file that adds it, in a job that needs no engine, rather than by
 * the deploy that runs it. For each file of this programme:
 *
 *   - every `Schema::create(X, ...)` needs a `hasTable(X)` somewhere up() can reach;
 *   - every `Schema::table(X, ...)` that adds a COLUMN needs a `hasColumn(X, ...)`;
 *   - `up()` may not drop, rename or `change()` anything: expand-only is what makes the old code keep working beside the new;
 *   - raw DDL, which no scan of the Blueprint API can read, has to be listed and read by a person;
 *   - `down()` must exist, so what was added can be taken back.
 *
 * The argument is matched AS WRITTEN rather than resolved to a table name. `Schema::create(self::TABLE, ...)` is satisfied by
 * `hasTable(self::TABLE)` and `Schema::table($name, ...)` inside a loop by `hasColumn($name, $column)` -- the thirteen files that name their
 * tables through a constant or a loop variable are read exactly as well as the ones that quote a literal, which a name resolver could not
 * do.
 *
 * Only what `up()` can REACH is read: its own body and, transitively, the private methods it calls. The first version read the whole file
 * with the `down()` body cut out, and it accused two migrations of dropping a column on the way up when the `dropColumn` was in a helper
 * only the rollback calls (`add_stock_allocation`, `add_purchase_order_sources_to_cost_tables`). A guard that cries wolf gets an exemption
 * written for it, which is the failure this file exists to prevent.
 *
 * ## The boundary, and why it is pinned by a count
 *
 * `2026_11_05_100000` is the first migration of this programme; the 300 before it are the single-market platform's and are not rewritten.
 * A new migration dated into that era would escape this contract silently, so the count of the files before the boundary is pinned: adding
 * one fails here and has to be explained rather than discovered.
 *
 * Two allow lists hold the files whose guard is of another shape, each with the reason. An entry that no longer violates the rule it is
 * listed for, or that names a file that is gone, fails too -- so the list cannot rot into a general exemption.
 */
class GccMigrationsContractTest extends TestCase
{
    /** The first migration of this programme. The platform's own are dated 2026_05_10 and earlier. */
    private const FIRST_OF_THIS_PROGRAMME = '2026_11_05_100000';

    /**
     * How many migration files are dated before the boundary. Pinned so that a new file BACKDATED into the platform's era -- which would
     * not be read by any test below -- fails here instead of passing unseen.
     */
    private const MIGRATIONS_BEFORE_THE_BOUNDARY = 300;

    /** `Schema::create` without a `hasTable`, and why re-running it is safe all the same. */
    private const CREATES_WITHOUT_A_GUARD = [];

    /** A column added without a `hasColumn`, and why re-running it is safe all the same. */
    private const COLUMNS_ADDED_WITHOUT_A_GUARD = [];

    /** A migration that drops, renames or changes on the way up, and why that is what the change needed. */
    private const NOT_EXPAND_ONLY = [
        '2026_12_14_100000_carry_three_decimals_on_provider_fulfillment_money.php'
            => 'widens the SCALE of five decimal columns (14,2 -> 14,3) so a fils amount is not rounded away on the way in, which a new '
            . 'column beside them could not do: the existing writers write these. Widening a decimal is lossless (12.34 becomes 12.340) '
            . 'and the migration re-runs: setScaleTo() reads the current scale and does nothing when it is already three',
        '2026_12_23_100000_add_country_to_provider_credentials_and_cities.php'
            => 'replaces the key (provider, external_id) with (provider, country, external_id) so two countries may map their own city of '
            . 'that id. The wide key is added BEFORE the narrow one is dropped, so there is no window in which a city could be imported '
            . 'twice; both steps ask hasIndex() first and are skipped when they are already done',
        '2026_12_28_100000_add_country_to_address_city_aliases.php'
            => 'the same shape for address_city_aliases.normalized_alias, which was unique platform-wide: one country recording "al khobar" '
            . 'would have stopped every other country recording its own, and the legacy matcher would have resolved a typed city to ANOTHER '
            . "country's. Wide key first, narrow key dropped after, each guarded by hasIndex()",
    ];

    /**
     * Raw DDL on the way up, which the Blueprint scan above cannot read a guard from, and what makes each re-runnable. Listed rather than
     * forbidden: a live ALTER wants ALGORITHM=INPLACE, LOCK=NONE and a lock_wait_timeout, and the query builder writes none of them.
     */
    private const RAW_DDL = [
        '2026_11_06_100000_widen_order_details_delivery_status_for_fulfillment.php'
            => 'LegacyColumnWidener::widenString() builds one ALTER TABLE ... MODIFY COLUMN from what information_schema says the column is '
            . 'now, returns without a statement when it is already wide enough, and gives up after a bounded lock wait, altering nothing',
        '2026_11_06_100100_widen_order_details_variation_for_fulfillment.php'
            => 'the same widener on order_details.variation',
        '2026_11_27_100000_add_order_currency_to_order_money_rows.php'
            => 'ADD COLUMN with ALGORITHM=INPLACE, LOCK=NONE so the money tables of a live shop are not locked for writes; the columns it '
            . 'adds are read with hasColumn() first and the statement is built only for the ones that are missing',
    ];

    /** A migration with no `down()`, and why nothing can be taken back. */
    private const WITHOUT_A_ROLLBACK = [];

    /** Methods a `Schema::table` callback may call without adding a column: an index or a key needs no `hasColumn`. */
    private const NOT_A_COLUMN = [
        'index', 'unique', 'primary', 'spatialIndex', 'fullText', 'foreign', 'rawIndex',
        'dropIndex', 'dropUnique', 'dropPrimary', 'dropForeign', 'dropColumn', 'dropIfExists',
        'references', 'on', 'onDelete', 'onUpdate', 'cascadeOnDelete', 'nullOnDelete', 'restrictOnDelete', 'name', 'algorithm',
    ];

    public function test_the_boundary_of_this_programme_is_where_it_was(): void
    {
        $all = $this->allMigrationFiles();
        $this->assertNotEmpty($all, 'no migration files were found, so this guard proved nothing');

        $before = array_values(array_filter(
            array_map('basename', $all),
            static fn (string $file): bool => strcmp(substr($file, 0, 17), self::FIRST_OF_THIS_PROGRAMME) < 0,
        ));

        $this->assertCount(
            self::MIGRATIONS_BEFORE_THE_BOUNDARY,
            $before,
            'A migration file is dated before ' . self::FIRST_OF_THIS_PROGRAMME . ', the first of this programme, so none of the rules below '
            . 'read it. If it is really the platform\'s own, raise MIGRATIONS_BEFORE_THE_BOUNDARY and say why in the commit; if it is new, '
            . 'date it after the boundary.',
        );

        $this->assertNotEmpty($this->migrationsOfThisProgramme(), 'no migration of this programme was found, so the rules below read nothing');
    }

    public function test_every_table_this_programme_creates_is_created_only_when_it_is_absent(): void
    {
        $unguarded = [];
        foreach ($this->migrationsOfThisProgramme() as $file => $source) {
            if (array_key_exists($file, self::CREATES_WITHOUT_A_GUARD)) {
                continue;
            }

            foreach ($this->unguardedCreates($source) as $table) {
                $unguarded[] = "{$file}: Schema::create({$table}) with no hasTable({$table})";
            }
        }

        $this->assertSame(
            [],
            $unguarded,
            "A migration that stopped half way leaves these tables behind, and running it again fails on the table it made itself:\n  "
            . implode("\n  ", $unguarded)
            . "\n\nWrap the create in `if (!Schema::hasTable(X))`, or list the file in CREATES_WITHOUT_A_GUARD with the reason.",
        );
    }

    public function test_every_column_this_programme_adds_is_added_only_when_it_is_absent(): void
    {
        $unguarded = [];
        foreach ($this->migrationsOfThisProgramme() as $file => $source) {
            if (array_key_exists($file, self::COLUMNS_ADDED_WITHOUT_A_GUARD)) {
                continue;
            }

            foreach ($this->unguardedColumnAdditions($source) as $table) {
                $unguarded[] = "{$file}: Schema::table({$table}) adds a column with no hasColumn({$table}, ...)";
            }
        }

        $this->assertSame(
            [],
            $unguarded,
            "Running these again meets the column they added themselves and the deploy stops:\n  " . implode("\n  ", $unguarded)
            . "\n\nGuard with `Schema::hasColumn(X, 'c')`, or list the file in COLUMNS_ADDED_WITHOUT_A_GUARD with the reason.",
        );
    }

    public function test_no_migration_of_this_programme_drops_renames_or_changes_on_the_way_up(): void
    {
        $narrowing = [];
        foreach ($this->migrationsOfThisProgramme() as $file => $source) {
            if (array_key_exists($file, self::NOT_EXPAND_ONLY)) {
                continue;
            }

            foreach ($this->narrowingStatements($source) as $statement) {
                $narrowing[] = "{$file}: {$statement}";
            }
        }

        $this->assertSame(
            [],
            $narrowing,
            "These migrations narrow the schema on the way up, so the code that ran before the deploy stops working the moment they run:\n  "
            . implode("\n  ", $narrowing)
            . "\n\nAdd beside, never in place of; or list the file in NOT_EXPAND_ONLY with the decision that allowed it.",
        );
    }

    public function test_every_raw_statement_on_the_way_up_is_listed_with_what_makes_it_re_runnable(): void
    {
        $unread = [];
        foreach ($this->migrationsOfThisProgramme() as $file => $source) {
            if (array_key_exists($file, self::RAW_DDL)) {
                continue;
            }

            foreach ($this->rawStatements($source) as $statement) {
                $unread[] = "{$file}: {$statement}";
            }
        }

        $this->assertSame(
            [],
            $unread,
            "These migrations run SQL this scan cannot read a guard from, so nothing above says whether they can be run twice:\n  "
            . implode("\n  ", $unread)
            . "\n\nUse the schema builder, or list the file in RAW_DDL with what makes the statement re-runnable.",
        );
    }

    public function test_every_migration_of_this_programme_can_be_taken_back(): void
    {
        $without = [];
        foreach ($this->migrationsOfThisProgramme() as $file => $source) {
            if (array_key_exists($file, self::WITHOUT_A_ROLLBACK)) {
                continue;
            }

            if (preg_match('/function\s+down\s*\(/', $source) !== 1) {
                $without[] = $file;
            }
        }

        $this->assertSame(
            [],
            $without,
            "These migrations cannot be taken back:\n  " . implode("\n  ", $without)
            . "\n\nAdd a down() that removes only what up() added, or list the file in WITHOUT_A_ROLLBACK with the reason.",
        );
    }

    public function test_no_entry_of_an_allow_list_has_gone_stale(): void
    {
        $files = $this->migrationsOfThisProgramme();
        $stale = [];

        $lists = [
            'CREATES_WITHOUT_A_GUARD' => [self::CREATES_WITHOUT_A_GUARD, fn (string $source): bool => $this->unguardedCreates($source) !== []],
            'COLUMNS_ADDED_WITHOUT_A_GUARD' => [self::COLUMNS_ADDED_WITHOUT_A_GUARD, fn (string $source): bool => $this->unguardedColumnAdditions($source) !== []],
            'NOT_EXPAND_ONLY' => [self::NOT_EXPAND_ONLY, fn (string $source): bool => $this->narrowingStatements($source) !== []],
            'RAW_DDL' => [self::RAW_DDL, fn (string $source): bool => $this->rawStatements($source) !== []],
            'WITHOUT_A_ROLLBACK' => [self::WITHOUT_A_ROLLBACK, static fn (string $source): bool => preg_match('/function\s+down\s*\(/', $source) !== 1],
        ];

        foreach ($lists as $name => [$entries, $stillBreaksTheRule]) {
            foreach ($entries as $file => $reason) {
                if (!array_key_exists($file, $files)) {
                    $stale[] = "{$name}: {$file} is not a migration of this programme";
                    continue;
                }
                if (trim((string) $reason) === '') {
                    $stale[] = "{$name}: {$file} has no reason";
                    continue;
                }
                if (!$stillBreaksTheRule($files[$file])) {
                    $stale[] = "{$name}: {$file} follows the rule now";
                }
            }
        }

        $this->assertSame([], $stale, "Correct these allow-list entries:\n  " . implode("\n  ", $stale));
    }

    /** @return list<string> the argument, as written, of every `Schema::create` with no matching `hasTable` on the way up */
    private function unguardedCreates(string $source): array
    {
        $onTheWayUp = $this->reachableFromUp($source);

        $unguarded = [];
        foreach ($this->firstArgumentsOf($onTheWayUp, 'create') as $table) {
            if (!$this->sourceAsks($onTheWayUp, 'hasTable', $table)) {
                $unguarded[] = $table;
            }
        }

        return array_values(array_unique($unguarded));
    }

    /** @return list<string> the argument, as written, of every column-adding `Schema::table` with no matching `hasColumn` on the way up */
    private function unguardedColumnAdditions(string $source): array
    {
        $onTheWayUp = $this->reachableFromUp($source);

        $unguarded = [];
        foreach ($this->schemaCalls($onTheWayUp, 'table') as [$table, $body]) {
            if (!$this->addsAColumn($body)) {
                continue;
            }
            if (!$this->sourceAsks($onTheWayUp, 'hasColumn', $table)) {
                $unguarded[] = $table;
            }
        }

        return array_values(array_unique($unguarded));
    }

    /** @return list<string> every statement on the way up that takes something away or alters it in place */
    private function narrowingStatements(string $source): array
    {
        $onTheWayUp = $this->reachableFromUp($source);

        $found = [];
        foreach (['dropColumn', 'dropIfExists', 'dropTable', 'renameColumn', 'rename', 'dropIndex', 'dropUnique', 'dropPrimary'] as $method) {
            if (preg_match('/(?<![A-Za-z_])' . $method . '\s*\(/', $onTheWayUp) === 1) {
                $found[] = $method . '()';
            }
        }
        if (preg_match('/->\s*change\s*\(\s*\)/', $onTheWayUp) === 1) {
            $found[] = 'change()';
        }

        return $found;
    }

    /** True when the file asks the schema the question about this table, written the same way the create or the alter writes it. */
    private function sourceAsks(string $source, string $question, string $table): bool
    {
        foreach ($this->firstArgumentsOf($source, $question) as $asked) {
            if ($asked === $table) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when a `Schema::table` callback adds a column rather than only an index or a key. Read by elimination: anything the blueprint is
     * asked for that is not one of the known index and key methods is a column, so a type this list has never heard of still counts.
     */
    private function addsAColumn(string $callback): bool
    {
        if (preg_match_all('/->\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $callback, $matches) === 0) {
            return false;
        }

        foreach ($matches[1] as $method) {
            if (!in_array($method, self::NOT_A_COLUMN, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> the first argument, as written, of every `Schema::<method>` call */
    private function firstArgumentsOf(string $source, string $method): array
    {
        return array_map(static fn (array $call): string => $call[0], $this->schemaCalls($source, $method));
    }

    /**
     * Every `Schema::<method>(...)` call of the source, as its first argument and the rest of its arguments.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function schemaCalls(string $source, string $method): array
    {
        $calls = [];
        $needle = 'Schema::' . $method . '(';
        $at = 0;

        while (($found = strpos($source, $needle, $at)) !== false) {
            $open = $found + strlen($needle) - 1;
            $close = $this->matchingParenthesis($source, $open);
            $at = $found + strlen($needle);

            if ($close === null) {
                continue;
            }

            $arguments = substr($source, $open + 1, $close - $open - 1);
            $comma = $this->firstTopLevelComma($arguments);
            $first = trim($comma === null ? $arguments : substr($arguments, 0, $comma));
            $rest = $comma === null ? '' : substr($arguments, $comma + 1);

            $calls[] = [$this->normalised($first), $rest];
        }

        return $calls;
    }

    private function matchingParenthesis(string $source, int $open): ?int
    {
        $depth = 0;
        $length = strlen($source);

        for ($at = $open; $at < $length; $at++) {
            $character = $source[$at];
            if ($character === "'" || $character === '"') {
                $at = $this->endOfString($source, $at);
                continue;
            }
            if ($character === '(') {
                $depth++;
            } elseif ($character === ')' && --$depth === 0) {
                return $at;
            }
        }

        return null;
    }

    private function endOfString(string $source, int $open): int
    {
        $quote = $source[$open];
        $length = strlen($source);

        for ($at = $open + 1; $at < $length; $at++) {
            if ($source[$at] === '\\') {
                $at++;
                continue;
            }
            if ($source[$at] === $quote) {
                return $at;
            }
        }

        return $length - 1;
    }

    private function firstTopLevelComma(string $arguments): ?int
    {
        $depth = 0;
        $length = strlen($arguments);

        for ($at = 0; $at < $length; $at++) {
            $character = $arguments[$at];
            if ($character === "'" || $character === '"') {
                $at = $this->endOfString($arguments, $at);
                continue;
            }
            if ($character === '(' || $character === '[') {
                $depth++;
            } elseif ($character === ')' || $character === ']') {
                $depth--;
            } elseif ($character === ',' && $depth === 0) {
                return $at;
            }
        }

        return null;
    }

    /** `table: self::TABLE` and `self::TABLE` are the same argument; whitespace inside one is not a difference either. */
    private function normalised(string $argument): string
    {
        $argument = preg_replace('/^[a-zA-Z_][a-zA-Z0-9_]*\s*:(?!:)\s*/', '', trim($argument)) ?? $argument;

        return (string) preg_replace('/\s+/', '', $argument);
    }

    /** @return list<string> the raw SQL calls on the way up, which carry their own guard or none and cannot be read as a Blueprint call */
    private function rawStatements(string $source): array
    {
        $onTheWayUp = $this->reachableFromUp($source);

        $found = [];
        foreach (['DB::statement', 'DB::unprepared', 'DB::raw', 'LegacyColumnWidener::'] as $call) {
            if (str_contains($onTheWayUp, $call)) {
                $found[] = $call . '()';
            }
        }

        return $found;
    }

    /**
     * The code `up()` can reach: its own body and, transitively, the bodies of the methods of this migration that it calls. A helper only
     * the rollback calls is not read, which is the difference between this and cutting `down()` out of the file.
     */
    private function reachableFromUp(string $source): string
    {
        $bodies = $this->methodBodies($source);
        if (!array_key_exists('up', $bodies)) {
            return '';
        }

        $reached = ['up' => $bodies['up']];
        $queue = ['up'];

        while ($method = array_shift($queue)) {
            preg_match_all('/(?:\$this->|self::|static::)([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $reached[$method], $calls);

            foreach ($calls[1] as $called) {
                if (!array_key_exists($called, $reached) && array_key_exists($called, $bodies)) {
                    $reached[$called] = $bodies[$called];
                    $queue[] = $called;
                }
            }
        }

        return implode("\n", $reached);
    }

    /** @return array<string, string> the body of every method the file declares, by name */
    private function methodBodies(string $source): array
    {
        preg_match_all('/function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE);

        $bodies = [];
        foreach ($matches[1] as $index => [$name, $_]) {
            $signatureAt = $matches[0][$index][1];
            $brace = strpos($source, '{', $signatureAt);
            if ($brace === false) {
                continue;
            }

            $close = $this->matchingBrace($source, $brace);
            if ($close !== null) {
                $bodies[$name] = substr($source, $brace, $close - $brace + 1);
            }
        }

        return $bodies;
    }

    private function matchingBrace(string $source, int $open): ?int
    {
        $depth = 0;
        $length = strlen($source);

        for ($at = $open; $at < $length; $at++) {
            $character = $source[$at];
            if ($character === "'" || $character === '"') {
                $at = $this->endOfString($source, $at);
                continue;
            }
            if ($character === '{') {
                $depth++;
            } elseif ($character === '}' && --$depth === 0) {
                return $at;
            }
        }

        return null;
    }

    /** @return array<string, string> the source of every migration of this programme, by file name */
    private function migrationsOfThisProgramme(): array
    {
        $files = [];
        foreach ($this->allMigrationFiles() as $path) {
            $file = basename($path);
            if (strcmp(substr($file, 0, 17), self::FIRST_OF_THIS_PROGRAMME) >= 0) {
                $files[$file] = (string) file_get_contents($path);
            }
        }

        ksort($files);

        return $files;
    }

    /** @return list<string> */
    private function allMigrationFiles(): array
    {
        return glob(dirname(__DIR__, 3) . '/database/migrations/*.php') ?: [];
    }
}
