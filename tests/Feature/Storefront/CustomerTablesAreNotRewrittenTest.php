<?php

namespace Tests\Feature\Storefront;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsAddressSchema;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\PlatformWriteScanner;
use Tests\Support\WritesTemporaryFiles;
use Tests\TestCase;
use Throwable;

/**
 * A2-14 of the master plan: existing accounts, ids and history are preserved. DECISIONS guardrail 2 states
 * the rule this checks — new behaviour is additive, and the Saudi experience is unchanged until a storefront
 * is switched on.
 *
 * The five tables the criterion names hold the live platform's customers and their history: `users`, `carts`,
 * `orders`, `order_details`, `shipping_addresses`. `guest_users` is guarded with them, because a guest's order
 * is history too. A migration of this chain that dropped, renamed or retyped a column of one of them, or
 * deleted rows from one, would take something away from a customer who has been shopping on the Saudi
 * platform for years, and no later switch could give it back.
 *
 * Until now the check was a list: the migration names of pull requests 83 and 75 read by hand for the data
 * pack. A list read once cannot see the fifteenth migration.
 *
 * ## What is compared, and what is allowed
 *
 * `tests/Fixtures/migrations-on-main.txt` is the migration files on origin/main, sorted, one path per line:
 * `database/migrations` and the modules' own directories both, because a module's service provider registers
 * its migrations with the migrator and `php artisan migrate` runs those too. Every migration file on this tree
 * that is not in the fixture is this chain's, and each of those is read.
 *
 * What is read of each is everything it does on a deploy: `up()`, the constants it names a table with, and the
 * private methods `up()` calls. The body of `down()` is cut out and read by nothing. A rollback is SUPPOSED to
 * drop what `up()` added — the storefront-country migration on this very tree drops its column and its index
 * in `down()`, as it should. Unless a deploy reaches it: `$this->down()` inside `up()` is the undo-then-redo
 * idiom, and a `down()` on another class of the file is a method with an innocent name, so when what a deploy
 * runs calls a `down()` at all, nothing is cut.
 *
 * Which private methods a rollback reaches is more than reading text can tell, so a method is read whoever
 * calls it, and a rollback that drops a column from a method `down()` calls is refused along with the rest.
 * The remedy is to drop it in `down()` itself, which is what the fourteen migrations of this chain do. That is
 * the error this guard prefers: it fails in CI, in the open, and it never lets a write of a customer's history
 * through on the strength of where its author put it.
 *
 * Against one of the guarded tables, these are refused: dropColumn, dropIfExists, drop, renameColumn, rename,
 * a `->change()` that retypes an existing column, and a write of its rows — insert, update, upsert, delete,
 * truncate, increment, decrement, a write through a model of one of those tables, or raw SQL that names one.
 * A table the guard cannot put a name to is refused with them, because it could be any of the six.
 *
 * That last one is a demand on how a migration of THIS CHAIN is written, and it is worth knowing its price. Of
 * the 350 migrations on main, read the way this guard reads them, three would be refused for it: the two that
 * take a table from a variable in `Schema::table($table, ...)` (`2026_11_10_100000_add_stock_allocation.php`,
 * `2026_11_11_100000_add_purchase_order_sources_to_cost_tables.php`) and the TaxModule one that loops over a
 * list of its tables. (A fourth, `2026_05_07_000001_update_all_decimal_columns_to_40_20.php`, would be refused
 * because it really does retype columns of `users`, `orders`, `order_details` and `carts`.) So a migration of
 * this chain may not choose its table at run time, and the remedy is to write the name: `Schema::table('orders',
 * ...)`. That is deliberate. The alternative is to let `DB::table(config('tables.users'))` through, which is
 * how a reviewer zeroed every wallet past the version of this guard that tried to be clever instead.
 *
 * Adding a column IS allowed, on one condition the test enforces: it has to be nullable or carry a default, so
 * that every row already in the table stays exactly as it was. That is what "additive" means here, and it is
 * what lets GCC-05 add `storefront_country`, `currency_code` and `currency_rate` to `orders` on another branch
 * of this chain without touching a single existing order.
 *
 * ## The first version of this guard refused no write at all
 *
 * It was written with a list of regular expressions, and the list had nothing for a migration that writes rows.
 * Its one write-ish pattern was `truncate` or `->delete()`. A reviewer of another pull request put
 * `DB::table('users')->update(['wallet_balance' => 0, 'loyalty_point' => 0]);` in the `up()` of a migration of
 * this chain — every Saudi customer's wallet and loyalty points to zero, on the live platform — and both gates
 * of this file passed it. Of the writes the provider below now lists, that version refused three.
 * A regular expression is what let the rest through, so a write is no longer looked for
 * with one: it is read by `tests/Support/PlatformWriteScanner`, the repository's own token-by-token scanner, which
 * resolves a table named by a string, a constant, an alias or a variable, a builder kept in a variable, raw
 * SQL and a model, and reports a name it cannot resolve instead of passing it over in silence.
 *
 * ## The second version read the text, and twelve spellings of the same write got past it
 *
 * An independent reviewer then wrote twenty-two more migrations and ran this file against each. Twelve were
 * invisible to all of it and nine were noticed only by the census test at the bottom, whose own message calls
 * what it found "not automatically wrong". Every one of them is a case of the providers below now. They fell
 * into groups, and so do the answers:
 *
 * - The table named by anything but a string, a constant or a variable — `config('tables.users')`, a model's
 *   `getTable()`, `trim(' users ')`, a method of the migration. The scanner matched none of those and so
 *   reported nothing at all, when its own promise is that what it cannot resolve it names. The rule it needed
 *   was already in this repository, in `tests/Unit/SalesChannels/StockNeverWritesPlatformTablesTest`: anything
 *   after `DB::table(` that is not a quote chooses the table at run time.
 * - A writer called `down`, reached from `up()`.
 * - A write through a relation of a model this guard holds (`$customer->orders()->delete()`), and a builder or
 *   a model handed on to a second variable.
 * - Raw SQL assembled at run time, or begun by a string joined to another, or hidden behind a `;` written
 *   inside a SQL comment, or `LOAD DATA INFILE`.
 * - A column dropped by a method that never says `column`: `Schema::dropColumns()`, `dropRememberToken()`,
 *   `dropTimestamps()`, `dropSoftDeletes()`, `dropMorphs()`. And a NOT NULL column added to `orders`, which
 *   rewrites every order there is, by a type name that was not among the twenty-one this file listed, or by
 *   `->nullable(false)`, which the check read as nullable.
 * - The same unchanged write, in `Modules/Blog/database/migrations`, which a deploy runs and this guard did
 *   not read.
 *
 * The lists of types and of drops are no longer written here: they are read from the installed framework, so
 * they cannot go stale (`PlatformWriteScanner::blueprintMethodsThatAddAColumn()`, and the drops beside it).
 *
 * ## Two gates, because reading text was never going to be enough
 *
 * The deeper fault was the shape of the answer. This repository already answers this question twice, by running
 * the migration instead of reading it: `tests/Feature/Fulfillment/MigrationsTest` and
 * `tests/Feature/SalesChannels/MigrationsTest` each seed a row, run `up()`, and compare the column listing and
 * the row. A third, text-only answer was written beside them, and a text scanner can always be out-spelled.
 * So the migrations of this chain are now run for real against a seeded copy of the six tables
 * (test_running_every_migration_of_this_chain_leaves_every_customer_row_and_column_as_it_was): every statement
 * the engine was given is read, every column and every row is compared before and after, and the database
 * itself says whether a column that arrived is nullable.
 *
 * Neither gate replaces the other. The text gate reads a write a deploy would not reach — one behind a
 * condition that is false on a test database, or in a `down()` nobody calls — it names the table in words, and
 * it needs no engine. The deploy gate sees a write the text cannot: one reached by calling out of the file,
 * into a trait, a seeder or a command, and one spelled in a way nobody thought to look for. A write reached out
 * of the file was the limit this guard used to declare it could not see; it is the deploy that covers it now.
 *
 * ## What is true on this tree today
 *
 * Fourteen migrations are this chain's, and not one of them names any of the six guarded tables. The only one
 * that touches a table that existed on main at all is the storefront country on `products`, which is a
 * catalogue table, not a customer's history. That is recorded as its own test, because it is the criterion's
 * own sentence and it is worth knowing the day it stops being true.
 */
class CustomerTablesAreNotRewrittenTest extends TestCase
{
    use BuildsAddressSchema;
    use BuildsFulfillmentSchema;
    use WritesTemporaryFiles;

    /**
     * Every directory a deploy takes migrations from. `database/migrations` is not the only one: each module registers its own with the migrator from its service provider
     * (`loadMigrationsFrom(module_path(..., 'database/migrations'))`, e.g. Modules/Blog/app/Providers/BlogServiceProvider.php), so `php artisan migrate` runs those too. This guard
     * read only the first of them, and the wallet-zeroing migration moved into `Modules/Blog/database/migrations` was invisible to all of it.
     */
    private const MIGRATION_DIRECTORIES = ['database/migrations', 'Modules/*/database/migrations'];

    /** Rebuilt from main, never edited by hand: `git ls-tree -r --name-only origin/main | grep -E '^(database/migrations|Modules/[A-Za-z0-9]+/database/migrations)/[^/]+\.php$' | sort`. */
    private const BASELINE = __DIR__ . '/../../Fixtures/migrations-on-main.txt';

    private const MIGRATIONS_ON_MAIN = 350;

    /** The tables that hold a customer and their history. The criterion names the first five; a guest's order is history too. */
    private const GUARDED = ['users', 'carts', 'orders', 'order_details', 'shipping_addresses', 'guest_users'];

    /** The models of those tables: a migration that wrote rows through one of them would be writing the table. */
    private const GUARDED_MODELS = ['User', 'Cart', 'Order', 'OrderDetail', 'ShippingAddress', 'GuestUser'];

    /**
     * The changes of shape that may never be made to one of those tables, each a regular expression over what the migration does on a deploy. The methods that drop a column are read
     * from the framework by the scanner, not listed here: `dropColumn` was the whole list, and `Schema::dropColumns()`, `dropRememberToken()`, `dropTimestamps()`,
     * `dropSoftDeletes()` and `dropMorphs()` all take a column away from a table without ever writing the word. A table's rows are a separate question, asked of the scanner and of
     * a real deploy, because a regular expression is what missed a write the first time.
     *
     * @return array<string, string>
     */
    private function refusedChangesOfShape(): array
    {
        return [
            'a column is dropped' => '/->\s*(?:' . implode('|', PlatformWriteScanner::blueprintMethodsThatDropAColumn()) . ')\s*\(|Schema::\s*dropColumns\s*\(/',
            'a column or table is renamed' => '/->\s*renameColumn\s*\(|Schema::\s*rename\s*\(/',
            'the table is dropped' => '/Schema::\s*drop(IfExists)?\s*\(/',
            'an existing column is retyped' => '/->\s*change\s*\(\s*\)/',
        ];
    }

    /** @return array<string, string> what each migration this chain adds does on a deploy, by its path from the root of the repository */
    private function deployedBodiesOfTheChain(): array
    {
        $bodies = [];

        foreach ($this->migrationsOfTheChain() as $name => $path) {
            $bodies[$name] = $this->whatRunsOnADeploy(source: (string) file_get_contents($path));
        }

        return $bodies;
    }

    /** @return array<string, string> the path of every migration this chain adds, by its path from the root of the repository */
    private function migrationsOfTheChain(): array
    {
        return array_diff_key($this->migrationsOnThisTree(), array_flip($this->migrationsOnMain()));
    }

    /** @return array<string, string> every migration file a deploy of this tree would run, by its path from the root of the repository */
    private function migrationsOnThisTree(): array
    {
        $migrations = [];

        foreach (self::MIGRATION_DIRECTORIES as $directory) {
            foreach ((array) glob(base_path($directory . '/*.php')) as $path) {
                $migrations[str_replace(base_path() . '/', '', (string) $path)] = (string) $path;
            }
        }
        ksort($migrations);

        return $migrations;
    }

    /**
     * The migration without the body of down(): up(), the constants it names its tables with, and the private methods it calls, which is everything a deploy runs. Reading the text
     * of up() alone was not enough — a write moved into a method declared below down() was read by nothing — and a rollback is read by nothing, because undoing is its job.
     *
     * Unless a deploy runs it. `$this->down()` inside `up()` is the idiom for undo-then-redo, and a `down()` declared on a second class of the file and called from `up()` is just a
     * method with an innocent name; both were a way to put a write where this guard had promised not to look. So when what a deploy runs calls a `down()` at all, nothing is cut and
     * the whole file is read.
     */
    private function whatRunsOnADeploy(string $source): string
    {
        $withoutTheRollback = $this->sourceWithoutTheBodyOfDown(source: $source);

        return preg_match('/(?:->|::)\s*down\s*\(/', PlatformWriteScanner::codeOf($withoutTheRollback)) === 1 ? $source : $withoutTheRollback;
    }

    /** The source with the body, the name and the signature of every function called `down` taken out. */
    private function sourceWithoutTheBodyOfDown(string $source): string
    {
        $tokens = token_get_all($source);
        $kept = '';

        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            if (is_array($tokens[$index]) && $tokens[$index][0] === T_FUNCTION && $this->functionNameAt($tokens, $index) === 'down') {
                $index = $this->endOfTheBodyAt($tokens, $index);
                continue;
            }

            $kept .= is_array($tokens[$index]) ? $tokens[$index][1] : $tokens[$index];
        }

        return $kept;
    }

    /**
     * The name of the function whose `function` keyword is at $index, or '' for a closure.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function functionNameAt(array $tokens, int $index): string
    {
        for ($next = $index + 1, $count = count($tokens); $next < $count; $next++) {
            if (is_array($tokens[$next])) {
                if ($tokens[$next][0] === T_STRING) {
                    return $tokens[$next][1];
                }
                if ($tokens[$next][0] !== T_WHITESPACE) {
                    return '';
                }
                continue;
            }

            return '';
        }

        return '';
    }

    /**
     * The index of the brace that closes the body of the function whose `function` keyword is at $index. A brace that opens an interpolation inside a string counts, because the one
     * that closes it does.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function endOfTheBodyAt(array $tokens, int $index): int
    {
        $depth = 0;

        for ($next = $index, $count = count($tokens); $next < $count; $next++) {
            $token = $tokens[$next];

            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($token === '}' && --$depth === 0) {
                return $next;
            }
        }

        return $count - 1;
    }

    /** @return list<string> */
    private function migrationsOnMain(): array
    {
        $lines = explode("\n", (string) file_get_contents(self::BASELINE));
        $lines = array_filter(array_map(fn (string $line): string => trim($line), $lines), fn (string $line): bool => $line !== '');

        return array_values($lines);
    }

    /**
     * The migrations whose table names this guard cannot read, and every table each one really changes.
     *
     * All four make one change to several tables and name them in a constant of their own, then hand the name on: the loop reads
     * `self::TABLES`, the body calls a method of the migration, and inside that method the name is a `string $table` PARAMETER by the time it
     * reaches `Schema::table()`. The reader is one statement at a time, so at that point the table is a variable and it answers "a table named
     * in a variable" -- and a name it cannot read it refuses, with all six customer tables, which is the right default and is why these four
     * were reported. Unrolling the loop does not help, because the name does not reach the call from the loop; it reaches it through an
     * argument.
     *
     * So they are written down, the way LedgerRowsAreAppendOnlyTest writes down the files that name a table at run time, and what they name is
     * not taken on trust: test_the_tables_these_migrations_name_in_a_constant_are_the_ones_written_down_here reads each file's own constants
     * and compares them with these lists, so a fifth table added to one of them fails. Of the eleven tables named across the four, exactly one
     * is a customer table -- order_details -- and the only thing done to it is adding two nullable columns, which the two tests above hold it
     * to like any other migration.
     *
     * @var array<string, list<string>>
     */
    private const NAMES_ITS_TABLES_IN_A_CONSTANT = [
        // D1-06: currency_code and currency_rate on the four money-row tables of an order. order_details is a customer table; the other three are not.
        'database/migrations/2026_11_27_100000_add_order_currency_to_order_money_rows.php' => ['order_details', 'order_transactions', 'refund_requests', 'refund_transactions'],
        // D1-10: the order's market, money and selling company on the two refund tables. Neither is a customer table.
        'database/migrations/2026_12_03_100000_add_order_money_to_refund_tables.php' => ['refund_requests', 'refund_transactions'],
        // The per-market balance accounts, and the market column on the two ledgers. None of the four is a customer table.
        'database/migrations/2026_12_05_120000_create_customer_market_balance_accounts.php' => ['customer_market_wallet_accounts', 'customer_market_loyalty_accounts', 'wallet_transactions', 'loyalty_point_transactions'],
        // E-02.3: the sharing key on those two account tables. Neither is a customer table.
        'database/migrations/2026_12_05_140000_add_sharing_key_to_market_balance_accounts.php' => ['customer_market_wallet_accounts', 'customer_market_loyalty_accounts'],
        // GCC-19: the merchant account and the company that took the payment, on the two refund tables. Neither is a customer table.
        'database/migrations/2026_12_04_100000_add_payment_account_to_refund_tables.php' => ['refund_requests', 'refund_transactions'],
        // E-05: what stands behind a movement, on the two ledgers. Neither is a customer table.
        'database/migrations/2026_12_05_100000_add_attribution_to_wallet_and_loyalty_ledgers.php' => ['wallet_transactions', 'loyalty_point_transactions'],
    ];

    /**
     * The migrations of this chain that name a customer table, and the one additive, nullable column each adds to it. The criterion's own
     * sentence was that none of them named one at all; three do now, and each is here rather than the sentence being quietly dropped.
     *
     * Naming one is not the offence. What is forbidden is taking something away or writing a row, which
     * test_no_migration_of_this_chain_rewrites_a_customer_table checks, and adding a column that is neither nullable nor defaulted, which
     * test_a_column_this_chain_adds_to_a_customer_table_leaves_every_existing_row_as_it_was checks; both are green for all three. The list
     * exists so that a fourth has to be looked at by somebody.
     *
     * @var list<string>
     */
    private const NAMES_A_CUSTOMER_TABLE = [
        // orders.storefront_country, orders.currency_code, orders.currency_rate: what market an order was sold in and what its amounts are counted in.
        'database/migrations/2026_11_26_100000_add_storefront_snapshot_to_orders_table.php (orders)',
        // order_details.currency_code and .currency_rate, so a sold line says what its own amount is counted in (D1-06).
        'database/migrations/2026_11_27_100000_add_order_currency_to_order_money_rows.php (order_details)',
        // orders.legal_entity_id and the frozen identity columns beside it: which registered company sold the order.
        'database/migrations/2026_11_27_100100_add_legal_entity_to_orders_table.php (orders)',
        // orders.shipping_kind and .shipping_decision: whether the order crossed a border and what the engine considered when it said so
        // (H1-06). Both nullable and empty for every order that exists, which is read as the local order it is. A decision that is
        // recomputed is a decision that changes, so it is written down once as it was given.
        'database/migrations/2026_12_20_100200_add_shipping_decision_to_orders_table.php (orders)',
        // orders.customs_quote: what was quoted for customs duty, as a whole (H1-09). "Not quoted" is a value and the important one -- a
        // route nobody has written a rule for says so rather than recording a zero, which would read as "these goods cross free".
        // Nullable, empty for every order that exists, and nothing writes it while duty is off.
        'database/migrations/2026_12_20_100400_add_customs_quote_to_orders_table.php (orders)',
    ];
    /** @return list<string> the guarded tables this body names */
    private function guardedTablesIn(string $deployed): array
    {
        return array_values(array_filter(
            self::GUARDED,
            fn (string $table): bool => (bool) preg_match('/[\'"]' . preg_quote($table, '/') . '[\'"]/', $deployed),
        ));
    }

    /**
     * Why this guard refuses what a migration does on a deploy, in words; nothing when it is clean. A change of shape is matched against the guarded tables the body names; a write
     * of rows is read by the scanner, which names the table itself however the code names it, so a write is caught in a body that never spells the table out.
     *
     * @return list<string>
     */
    private function reasonsToRefuse(string $deployed): array
    {
        $reasons = [];
        $tables = $this->guardedTablesIn(deployed: $deployed);

        if ($tables !== []) {
            foreach ($this->refusedChangesOfShape() as $what => $pattern) {
                if (preg_match($pattern, $deployed)) {
                    $reasons[] = $what . ' (' . implode(', ', $tables) . ')';
                }
            }
        }

        foreach ($this->customerRowsWrittenIn(deployed: $deployed) as $written) {
            $reasons[] = 'a customer table is written (' . $written . ')';
        }
        if ($this->namesATableTheGuardCannotResolve(deployed: $deployed)) {
            $reasons[] = 'a table the guard cannot name is written or changed';
        }

        return $reasons;
    }

    /**
     * The guarded tables, and the models of them, whose rows a deploy of this migration writes: what the scanner reads in the calls it knows, and what it reads in the SQL of a
     * string handed to anything at all, which is how a write reaches `whereRaw`, `selectRaw` or a connection's own `exec`.
     *
     * @return list<string>
     */
    private function customerRowsWrittenIn(string $deployed): array
    {
        $models = $this->modelNamesIn(deployed: $deployed);
        $refused = array_map(static fn (string $model): string => 'model ' . $model, $models);
        $scanner = new PlatformWriteScanner($models);

        $written = [
            ...array_intersect($scanner->rowWritesIn($deployed), [...self::GUARDED, ...$refused]),
            ...PlatformWriteScanner::tablesAStringWrites($deployed, self::GUARDED),
        ];

        return array_values(array_unique($written));
    }

    /**
     * Does a deploy of this migration write or alter a table the scanner could not put a name to — one chosen at run time, named by a constant it cannot see, or spelled by half a
     * string? Such a table could be any of the six, so it is refused with them: this guard reads text, and what it cannot read it does not wave through.
     */
    private function namesATableTheGuardCannotResolve(string $deployed): bool
    {
        return in_array(PlatformWriteScanner::UNKNOWN_TABLE, (new PlatformWriteScanner(self::GUARDED_MODELS))->writesIn($deployed), true);
    }

    /**
     * The names a migration can write a customer table through: the models of the guarded tables, and whatever a migration renames one to in an import — `use App\Models\User as
     * Customer` makes `Customer` a way to write `users`.
     *
     * @return list<string>
     */
    private function modelNamesIn(string $deployed): array
    {
        preg_match_all('/\buse\s+\\\\?App\\\\Models\\\\(?:' . implode('|', self::GUARDED_MODELS) . ')\s+as\s+(\w+)\s*;/i', $deployed, $found);

        return [...self::GUARDED_MODELS, ...array_values(array_unique($found[1]))];
    }

    public function test_the_baseline_fixture_is_the_migration_set_it_says_it_is(): void
    {
        $onMain = $this->migrationsOnMain();

        $this->assertFileExists(self::BASELINE);
        $this->assertCount(self::MIGRATIONS_ON_MAIN, $onMain, 'the fixture has been edited: rebuild it from main in a commit of its own instead');
        $this->assertSame($onMain, array_values(array_unique($onMain)), 'the fixture lists a migration twice');
    }

    public function test_this_tree_really_does_add_migrations_of_its_own(): void
    {
        $this->assertNotEmpty($this->deployedBodiesOfTheChain(), 'nothing to check means the fixture matches the whole directory, so this test proves nothing');
    }

    public function test_no_migration_of_this_chain_rewrites_a_customer_table(): void
    {
        $offences = [];

        foreach ($this->deployedBodiesOfTheChain() as $name => $deployed) {
            foreach ($this->reasonsToRefuse(deployed: $deployed) as $reason) {
                $offences[] = $name . ': ' . $reason;
            }
        }

        $declared = [];
        foreach (array_keys(self::NAMES_ITS_TABLES_IN_A_CONSTANT) as $migration) {
            $declared[] = $migration . ': a table the guard cannot name is written or changed';
        }
        sort($offences);
        sort($declared);

        $this->assertSame(
            $declared,
            $offences,
            'a migration of this chain takes something away from a customer table, or writes its rows, on a deploy; the body of down() is not read, unless a deploy reaches it, because a rollback is supposed to undo. '
            . 'If the reason is that the guard cannot name the table: write the name instead of taking it from a variable, a method or the configuration, so that a migration of this chain says which table it changes. '
            . 'The six that hand the name on through an argument are written down in NAMES_ITS_TABLES_IN_A_CONSTANT with every table each really changes, and that is checked against their own constants.',
        );
    }

    /**
     * What the migrations of NAMES_ITS_TABLES_IN_A_CONSTANT name is read out of them rather than believed: every table name their own
     * constants hold is compared with the list written down there, so a fifth table added to one of those constants fails this test and has to
     * be looked at. Without this the accounting above would be a note, and a note is not a guard.
     */
    public function test_the_tables_these_migrations_name_in_a_constant_are_the_ones_written_down_here(): void
    {
        foreach (self::NAMES_ITS_TABLES_IN_A_CONSTANT as $migration => $tables) {
            $source = (string) file_get_contents(base_path($migration));
            preg_match_all('/const\s+[A-Z][A-Z0-9_]*\s*=\s*(?:\[([^\]]*)\]|(\x27[a-z0-9_]+\x27))\s*;/', $source, $constants, PREG_SET_ORDER);

            $named = [];
            foreach ($constants as $constant) {
                preg_match_all('/\x27([a-z0-9_]+)\x27/', $constant[1] . ' ' . ($constant[2] ?? ''), $names);
                $named = [...$named, ...$names[1]];
            }

            // A constant that holds a column name rather than a table name is in there too, so what is asserted is that every TABLE written
            // down is still named, and that nothing named looks like a table this list does not carry.
            $missing = array_values(array_diff($tables, $named));
            $this->assertSame([], $missing, $migration . ': a table written down in NAMES_ITS_TABLES_IN_A_CONSTANT is no longer named in it, so that accounting is stale');

            $tablesItNames = array_values(array_intersect($named, $this->everyTableThisTreeHasAMigrationFor()));
            sort($tablesItNames);
            $expected = array_values(array_intersect($tables, $tablesItNames));
            sort($expected);
            $this->assertSame($expected, $tablesItNames, $migration . ': it names a table that is not written down in NAMES_ITS_TABLES_IN_A_CONSTANT');
        }
    }

    /** @return list<string> every table this tree's migrations create, so that a name in a constant can be told from a column name */
    private function everyTableThisTreeHasAMigrationFor(): array
    {
        $tables = [];

        foreach ($this->migrationsOnThisTree() as $path) {
            preg_match_all('/Schema::create\(\s*\x27([a-z0-9_]+)\x27/', (string) file_get_contents($path), $created);
            $tables = [...$tables, ...$created[1]];
        }

        return array_values(array_unique([...$tables, ...self::GUARDED]));
    }
    public function test_a_column_this_chain_adds_to_a_customer_table_leaves_every_existing_row_as_it_was(): void
    {
        $offences = [];

        foreach ($this->deployedBodiesOfTheChain() as $name => $deployed) {
            foreach ($this->columnsAddedToACustomerTableIn(deployed: $deployed) as $statement) {
                $offences[] = $name . ': ' . trim($statement);
            }
        }

        $this->assertSame([], $offences, 'a column added to a customer table is neither nullable nor defaulted, so every row already in the table has to be written to');
    }

    public function test_no_migration_of_this_chain_names_a_customer_table_at_all(): void
    {
        $touching = [];

        foreach ($this->deployedBodiesOfTheChain() as $name => $deployed) {
            $tables = $this->guardedTablesIn(deployed: $deployed);

            if ($tables !== []) {
                $touching[] = $name . ' (' . implode(', ', $tables) . ')';
            }
        }

        sort($touching);
        $declared = self::NAMES_A_CUSTOMER_TABLE;
        sort($declared);

        $this->assertSame(
            $declared,
            $touching,
            'a migration of this chain names a customer table and is not written down. That is not automatically wrong — an additive, nullable column is allowed, and the two tests above are what check it — '
            . 'but it is a fact worth recording, so add it to NAMES_A_CUSTOMER_TABLE with the column it adds, and update the tracker with it, rather than deleting this test.',
        );
    }

    /**
     * The ways a write of a customer table can be put into a migration, and the reason this guard refuses each. The first of them is the reviewer's. Of these thirty-five, the first
     * version of this guard refused three — a `truncate()`, a `delete()` and a dropped column — and let the other twenty-eight through, the wallets among them.
     *
     * @return array<string, array{0: string, 1: string}> the migration's source, and the reason it has to be refused for
     */
    public static function writesOfACustomerTable(): array
    {
        $written = 'a customer table is written';
        $unnameable = 'a table the guard cannot name is written or changed';

        return [
            'every customer\'s wallet and loyalty points zeroed' => [self::migrationThatDoes(up: "DB::table('users')->update(['wallet_balance' => 0, 'loyalty_point' => 0]);"), $written . ' (users)'],
            'an order inserted' => [self::migrationThatDoes(up: "DB::table('orders')->insert(['user_id' => 1, 'order_amount' => 0]);"), $written . ' (orders)'],
            'an order id taken from an insert' => [self::migrationThatDoes(up: "DB::table('orders')->insertGetId(['user_id' => 1]);"), $written . ' (orders)'],
            'a cart quantity incremented' => [self::migrationThatDoes(up: "DB::table('carts')->increment('quantity');"), $written . ' (carts)'],
            'loyalty points decremented' => [self::migrationThatDoes(up: "DB::table('users')->decrement('loyalty_point', 10);"), $written . ' (users)'],
            'an order line deleted' => [self::migrationThatDoes(up: "DB::table('order_details')->where('id', 1)->delete();"), $written . ' (order_details)'],
            'the guests emptied' => [self::migrationThatDoes(up: "DB::table('guest_users')->truncate();"), $written . ' (guest_users)'],
            'an address upserted' => [self::migrationThatDoes(up: "DB::table('shipping_addresses')->upsert([['id' => 1, 'zip' => null]], ['id']);"), $written . ' (shipping_addresses)'],
            'a user written whether the row is there or not' => [self::migrationThatDoes(up: "DB::table('users')->updateOrInsert(['id' => 1], ['wallet_balance' => 0]);"), $written . ' (users)'],
            'the column named by two halves joined' => [self::migrationThatDoes(up: "DB::table('users')->update(['wallet' . '_balance' => 0]);"), $written . ' (users)'],
            'the builder kept in a variable and written on the next statement' => [self::migrationThatDoes(up: "\$rows = DB::table('users')->where('id', '>', 0);\n        \$rows->update(['wallet_balance' => 0]);"), $written . ' (users)'],
            'a connection of its own' => [self::migrationThatDoes(up: "DB::connection('mysql')->table('users')->update(['wallet_balance' => 0]);"), $written . ' (users)'],
            'the table named by a constant of the migration' => [self::migrationThatDoes(up: "DB::table(self::TABLE)->update(['wallet_balance' => 0]);", above: "    private const TABLE = 'users';\n"), $written . ' (users)'],
            'the write moved into a method declared below down()' => [self::migrationThatDoes(up: '$this->zeroTheWallets();', below: "    private function zeroTheWallets(): void\n    {\n        DB::table('users')->update(['wallet_balance' => 0]);\n    }\n"), $written . ' (users)'],
            'raw SQL that updates' => [self::migrationThatDoes(up: "DB::statement('UPDATE shipping_addresses SET zip = NULL');"), $written . ' (shipping_addresses)'],
            'raw SQL that replaces' => [self::migrationThatDoes(up: "DB::statement('REPLACE INTO orders (id, user_id) VALUES (1, 1)');"), $written . ' (orders)'],
            'raw SQL that deletes, unprepared' => [self::migrationThatDoes(up: "DB::unprepared('delete from carts');"), $written . ' (carts)'],
            'raw SQL through the update helper' => [self::migrationThatDoes(up: "DB::update('update users set wallet_balance = 0');"), $written . ' (users)'],
            'raw SQL that alters the table' => [self::migrationThatDoes(up: "DB::statement('ALTER TABLE users DROP COLUMN wallet_balance');"), $written . ' (users)'],
            'a second statement hidden in one string' => [self::migrationThatDoes(up: "DB::unprepared(\"UPDATE storefront_legal_documents SET body = ''; UPDATE users SET wallet_balance = 0\");"), $written . ' (users)'],
            'a write through the model of the table' => [self::migrationThatDoes(up: "User::query()->update(['wallet_balance' => 0]);"), $written . ' (model User)'],
            'a model fetched on one statement and saved on the next' => [self::migrationThatDoes(up: "\$user = User::find(1);\n        \$user->wallet_balance = 0;\n        \$user->save();"), $written . ' (model User)'],
            'the table chosen at run time' => [self::migrationThatDoes(up: "\$table = 'users';\n        DB::table(\$table)->update(['wallet_balance' => 0]);"), $unnameable],
            'the schema builder handed a table chosen at run time' => [self::migrationThatDoes(up: "Schema::table(\$this->table, fn (Blueprint \$table) => \$table->dropColumn('wallet_balance'));"), $unnameable],
            'a column dropped, which the first version did refuse' => [self::migrationThatDoes(up: "Schema::table('users', fn (Blueprint \$table) => \$table->dropColumn('wallet_balance'));"), 'a column is dropped (users)'],
            'the table spelled by half a string' => [self::migrationThatDoes(up: "DB::table('us' . 'ers')->update(['wallet_balance' => 0]);"), $unnameable],
            'the table interpolated into a string' => [self::migrationThatDoes(up: 'DB::table("{$prefix}users")->update([\'wallet_balance\' => 0]);'), $unnameable],
            'the table spelled by a constant and a suffix' => [self::migrationThatDoes(up: "DB::table(self::HALF . 'ers')->update(['wallet_balance' => 0]);", above: "    private const HALF = 'us';\n"), $unnameable],
            'the builder kept in a property' => [self::migrationThatDoes(up: "\$this->rows = DB::table('users')->where('id', '>', 0);\n        \$this->rows->update(['wallet_balance' => 0]);"), $written . ' (users)'],
            'raw SQL that names the database as well as the table' => [self::migrationThatDoes(up: "DB::statement('UPDATE pharmacy.users SET wallet_balance = 0');"), $written . ' (users)'],
            'the model imported under another name' => [self::migrationThatDoes(up: "Customer::query()->update(['wallet_balance' => 0]);", imports: 'use App\\Models\\User as Customer;'), $written . ' (model Customer)'],
            'the SQL hidden in a selectRaw' => [self::migrationThatDoes(up: "DB::table('products')->selectRaw('(update users set wallet_balance = 0)')->get();"), $written . ' (users)'],
            'the SQL run by the connection\'s own pdo' => [self::migrationThatDoes(up: "DB::connection()->getPdo()->exec('UPDATE users SET wallet_balance = 0');"), $written . ' (users)'],
            'the SQL built on one line and run on another' => [self::migrationThatDoes(up: "\$sql = 'delete from shipping_addresses';\n        \$this->run(\$sql);"), $written . ' (shipping_addresses)'],
            'the schema builder handed half a string' => [self::migrationThatDoes(up: "Schema::table('us' . 'ers', fn (Blueprint \$table) => \$table->dropColumn('wallet_balance'));"), $unnameable],
            'the table taken from the model at run time' => [self::migrationThatDoes(up: "DB::table((new User())->getTable())->update(['wallet_balance' => 0]);"), $unnameable],
            'the table read from the configuration' => [self::migrationThatDoes(up: "DB::table(config('tables.users'))->update(['wallet_balance' => 0]);"), $unnameable],
            'the table handed through a function' => [self::migrationThatDoes(up: "DB::table(trim(' users '))->update(['wallet_balance' => 0]);"), $unnameable],
            'the table returned by a method of the migration' => [self::migrationThatDoes(up: "DB::table(self::customerTable())->update(['wallet_balance' => 0]);", below: "    private static function customerTable(): string\n    {\n        return 'users';\n    }\n"), $unnameable],
            'the schema builder handed a method of the migration' => [self::migrationThatDoes(up: "Schema::table(self::customerTable(), fn (Blueprint \$table) => \$table->dropColumn('wallet_balance'));", below: "    private static function customerTable(): string\n    {\n        return 'users';\n    }\n"), $unnameable],
            'up() calls down(), which writes' => [self::migrationThatDoes(up: '$this->down();', down: "DB::table('users')->update(['wallet_balance' => 0, 'loyalty_point' => 0]);"), $written . ' (users)'],
            'a down() of another class, called from up()' => [self::migrationThatDoes(
                up: '(new WalletMaintenance())->down();',
                beforeTheMigration: "class WalletMaintenance\n{\n    public function down(): void\n    {\n        DB::table('users')->update(['wallet_balance' => 0]);\n    }\n}\n",
            ), $written . ' (users)'],
            'the orders of a customer deleted through the relation' => [self::migrationThatDoes(up: "\$customer = User::find(1);\n        \$customer->orders()->delete();"), $written . ' (model User)'],
            'the addresses of a customer emptied through the relation' => [self::migrationThatDoes(up: "\$customer = User::find(1);\n        \$customer->addresses()->update(['zip' => null]);"), $written . ' (model User)'],
            'the lines of an order deleted through the relation' => [self::migrationThatDoes(up: "\$order = Order::find(1);\n        \$order->details()->delete();"), $written . ' (model Order)'],
            'the builder handed to a second variable' => [self::migrationThatDoes(up: "\$rows = DB::table('users')->where('id', '>', 0);\n        \$target = \$rows;\n        \$target->update(['wallet_balance' => 0]);"), $written . ' (users)'],
            'the model handed to a second variable' => [self::migrationThatDoes(up: "\$u = User::query();\n        \$v = \$u;\n        \$v->update(['wallet_balance' => 0]);"), $written . ' (model User)'],
            'the verb and the table of a raw statement joined' => [self::migrationThatDoes(up: "DB::statement('UPDATE ' . 'users SET wallet_balance = 0');"), $unnameable],
            'the SQL of a raw statement built by sprintf' => [self::migrationThatDoes(up: "DB::statement(sprintf('UPDATE %s SET wallet_balance = 0', 'users'));"), $unnameable],
            'the SQL of a raw statement built by str_replace' => [self::migrationThatDoes(up: "DB::unprepared(str_replace('TBL', 'users', 'UPDATE TBL SET wallet_balance = 0'));"), $unnameable],
            'the wallets loaded from a file' => [self::migrationThatDoes(up: "DB::unprepared(\"LOAD DATA LOCAL INFILE '/tmp/wallets.csv' INTO TABLE users FIELDS TERMINATED BY ','\");"), $written . ' (users)'],
            'a semicolon inside a SQL comment between the verb and the table' => [self::migrationThatDoes(up: "DB::unprepared(\"UPDATE -- the wallet of every customer;\\n users SET wallet_balance = 0\");"), $written . ' (users)'],
            'the columns dropped by the schema builder itself' => [self::migrationThatDoes(up: "Schema::dropColumns('users', ['wallet_balance', 'loyalty_point']);"), 'a column is dropped (users)'],
            'the columns dropped by a method that does not say column' => [self::migrationThatDoes(up: "Schema::table('users', function (Blueprint \$table) {\n            \$table->dropRememberToken();\n            \$table->dropTimestamps();\n        });"), 'a column is dropped (users)'],
            'the facade imported under another name' => [self::migrationThatDoes(up: "Database::table('users')->update(['wallet_balance' => 0]);", imports: 'use Illuminate\Support\Facades\DB as Database;'), $written . ' (users)'],
        ];
    }

    /**
     * Columns a migration of this chain may not add to a customer table, because every row already in it would have to be written to. The first is the one the earlier version of this
     * guard refused; the others are what its list of twenty-one type names and its reading of `nullable()` let through.
     *
     * @return array<string, array{0: string}>
     */
    public static function columnsThatWouldRewriteEveryExistingRow(): array
    {
        return [
            'a fixed-width column with no default' => [self::migrationThatDoes(up: "Schema::table('orders', fn (Blueprint \$table) => \$table->char('storefront_country', 2));")],
            'a type the old list had never heard of' => [self::migrationThatDoes(up: "Schema::table('orders', function (Blueprint \$table) {\n            \$table->mediumInteger('storefront_rank');\n            \$table->unsignedTinyInteger('storefront_tier');\n        });")],
            'a year, an address and a blob' => [self::migrationThatDoes(up: "Schema::table('orders', function (Blueprint \$table) {\n            \$table->year('storefront_year');\n            \$table->ipAddress('storefront_ip');\n            \$table->binary('storefront_blob');\n        });")],
            'nullable, in words, but false' => [self::migrationThatDoes(up: "Schema::table('orders', fn (Blueprint \$table) => \$table->char('storefront_country', 2)->nullable(false));")],
            'the blueprint called what this repository calls it' => [self::migrationThatDoes(up: "Schema::table('orders', fn (Blueprint \$blueprint) => \$blueprint->string('storefront_slug', 24));")],
        ];
    }

    /**
     * What a migration of this chain is allowed to do, each of which has to come back clean: the additive column GCC-05 adds to `orders`, a read, a write of a table that is not a
     * customer's, and the rollback that undoes an additive change.
     *
     * @return array<string, array{0: string}>
     */
    public static function whatAMigrationOfThisChainMayDo(): array
    {
        return [
            'a nullable column added to orders' => [self::migrationThatDoes(
                up: "Schema::table('orders', fn (Blueprint \$table) => \$table->char('storefront_country', 2)->nullable());",
                down: "Schema::table('orders', fn (Blueprint \$table) => \$table->dropColumn('storefront_country'));",
            )],
            'a defaulted column added to orders' => [self::migrationThatDoes(up: "Schema::table('orders', fn (Blueprint \$table) => \$table->decimal('currency_rate', 12, 6)->default(1));")],
            'an index added to orders' => [self::migrationThatDoes(up: "Schema::table('orders', fn (Blueprint \$table) => \$table->index('storefront_country'));")],
            'the customer tables read' => [self::migrationThatDoes(up: "\$exists = DB::table('users')->where('wallet_balance', '>', 0)->exists();")],
            'a table of this chain written' => [self::migrationThatDoes(up: "DB::table('platform_admin_audit_tail')->insert(['id' => 1, 'last_hash' => null]);")],
            'a table that is not a customer\'s written' => [self::migrationThatDoes(up: "DB::table('products')->update(['storefront_country' => 'SA']);")],
            'a write of users commented out' => [self::migrationThatDoes(up: "// DB::table('users')->update(['wallet_balance' => 0]);\n        \$done = true;")],
            'the whole table dropped on a rollback' => [self::migrationThatDoes(up: "Schema::create('storefront_quotes', fn (Blueprint \$table) => \$table->id());", down: "Schema::dropIfExists('storefront_quotes');")],
            'the rows of a customer table deleted on a rollback' => [self::migrationThatDoes(
                up: "Schema::table('orders', fn (Blueprint \$table) => \$table->char('storefront_country', 2)->nullable());",
                down: "DB::table('orders')->update(['storefront_country' => null]);",
            )],
        ];
    }

    #[DataProvider('writesOfACustomerTable')]
    public function test_a_write_of_a_customer_table_is_refused_however_the_migration_writes_it(string $source, string $reason): void
    {
        $this->assertContains($reason, $this->reasonsToRefuse(deployed: $this->whatRunsOnADeploy(source: $source)), 'not refused: ' . $source);
    }

    #[DataProvider('whatAMigrationOfThisChainMayDo')]
    public function test_what_a_migration_of_this_chain_may_do_is_not_refused(string $source): void
    {
        $deployed = $this->whatRunsOnADeploy(source: $source);

        $this->assertSame([], $this->reasonsToRefuse(deployed: $deployed), 'refused although it is allowed: ' . $source);
        $this->assertSame([], $this->columnsAddedToACustomerTableIn(deployed: $deployed), 'refused although it is allowed: ' . $source);
    }

    public function test_a_rollback_that_drops_a_column_from_a_method_it_calls_is_refused_with_the_rest(): void
    {
        $source = self::migrationThatDoes(
            up: "Schema::table('orders', fn (Blueprint \$table) => \$table->char('storefront_country', 2)->nullable());",
            down: '$this->takeTheColumnBack();',
            below: "    private function takeTheColumnBack(): void\n    {\n        Schema::table('orders', fn (Blueprint \$table) => \$table->dropColumn('storefront_country'));\n    }\n",
        );

        $this->assertSame(
            ['a column is dropped (orders)'],
            $this->reasonsToRefuse(deployed: $this->whatRunsOnADeploy(source: $source)),
            'reading text cannot tell which methods a rollback reaches, so this guard refuses a drop wherever it is but inside down(). It is written down here so that it is a decision and not a surprise: move the drop into down() itself, as the fourteen migrations of this chain do.',
        );
    }

    #[DataProvider('columnsThatWouldRewriteEveryExistingRow')]
    public function test_a_column_added_to_a_customer_table_without_a_default_is_still_refused(string $source): void
    {
        $this->assertNotSame([], $this->columnsAddedToACustomerTableIn(deployed: $this->whatRunsOnADeploy(source: $source)), 'not refused: ' . $source);
    }

    public function test_the_blueprint_methods_this_guard_reads_from_the_framework_are_the_ones_it_needs(): void
    {
        $adds = PlatformWriteScanner::blueprintMethodsThatAddAColumn();
        $drops = PlatformWriteScanner::blueprintMethodsThatDropAColumn();

        foreach (['string', 'char', 'decimal', 'mediumInteger', 'unsignedTinyInteger', 'year', 'ipAddress', 'binary', 'foreignId', 'timestamps', 'morphs'] as $method) {
            $this->assertContains($method, $adds, 'the framework is read for the methods that add a column and this one was not found: the derivation has stopped working, which would quietly weaken the check');
        }
        foreach (['dropColumn', 'dropRememberToken', 'dropTimestamps', 'dropSoftDeletes', 'dropMorphs'] as $method) {
            $this->assertContains($method, $drops, 'the framework is read for the methods that drop a column and this one was not found');
        }
        $this->assertNotContains('index', $adds, 'an index is not a column');
        $this->assertNotContains('dropIndex', $drops, 'dropping an index takes nothing away from a row');
    }

    public function test_the_migrations_this_guard_reads_are_every_one_a_deploy_would_run(): void
    {
        $onThisTree = $this->migrationsOnThisTree();

        $this->assertArrayHasKey('database/migrations/2026_11_25_100000_add_storefront_country_to_products.php', $onThisTree);
        $this->assertArrayHasKey(
            'Modules/Blog/database/migrations/2024_12_26_210457_create_blogs_table.php',
            $onThisTree,
            'a module registers its own migrations with the migrator, so `php artisan migrate` runs them: the same wallet-zeroing migration moved into one of those directories was read by nothing',
        );
        $this->assertSame(
            [],
            array_values(array_filter(array_keys($this->migrationsOfTheChain()), static fn (string $path): bool => !str_starts_with($path, 'database/migrations/'))),
            'this chain adds no module migration yet; when it does, it is read with the rest, and this line is what will say so',
        );
    }

    /**
     * What a deploy of the given migrations really does to the six tables: every statement it ran that writes a row of one of them, every column one of them lost, every column it
     * gained that is neither nullable nor defaulted, every row that is not what it was, and a migration that would not run at all. The two migration tests this repository already
     * has (`tests/Feature/Fulfillment/MigrationsTest`, `tests/Feature/SalesChannels/MigrationsTest`) answer the same question about their own features' migrations in exactly this
     * shape: seed a row, run `up()`, compare the column listing and the row. This guard was written as a third, text-only answer beside them, and a reviewer then found twelve ways
     * of spelling a write that no text scanner saw. Running it needs no spelling: `DB::table(config('tables.users'))`, `$customer->orders()->delete()` and
     * `DB::unprepared(str_replace(...))` all reach the database, and the database is what is asked here.
     *
     * A migration that cannot be run is an offence and not a pass: what it does to a customer's history would be unproven. The remedy is to give the schema below what the migration
     * needs, which is what the other two migration tests ask of their own features.
     *
     * @param array<string, string> $migrations the path of each, by the name to report it under
     * @return list<string>
     */
    private function offencesOfADeployOf(array $migrations): array
    {
        $before = $this->shapeAndRowsOfTheCustomerTables();
        $statements = [];
        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $offences = [];
        foreach ($migrations as $name => $path) {
            try {
                (require $path)->up();
            } catch (Throwable $failure) {
                $offences[] = $name . ': a deploy of it does not run against the customer tables, so what it does to them is unproven (' . $failure->getMessage() . ')';
            }
        }

        foreach ($this->rowsOfACustomerTableWrittenBy(statements: $statements) as $statement) {
            $offences[] = 'a statement of the deploy writes the rows of a customer table: ' . $statement;
        }

        return [...$offences, ...$this->whatChangedInTheCustomerTables(was: $before, now: $this->shapeAndRowsOfTheCustomerTables())];
    }

    /**
     * The statements of a deploy that write a row of one of the guarded tables, as the engine was given them. A column added or an index made is not one of them: those are judged by
     * what changed in the tables, because an additive nullable column is allowed and this chain is expected to add one.
     *
     * @param list<string> $statements
     * @return list<string>
     */
    private function rowsOfACustomerTableWrittenBy(array $statements): array
    {
        $written = [];

        foreach ($statements as $statement) {
            if (preg_match('/^\s*(?:insert|update|delete|replace|truncate|load\s+data)\b/i', $statement) !== 1) {
                continue;
            }
            foreach (self::GUARDED as $table) {
                if (preg_match('/\b' . preg_quote($table, '/') . '\b/i', $statement) === 1) {
                    $written[] = $statement;
                }
            }
        }

        return array_values(array_unique($written));
    }

    /**
     * The six tables as they are now: every column with whether a row already in the table can keep its value when that column appears (nullable, or defaulted), and every row in the
     * order of its id. The nullable flag is read from the database and not from the text of the migration, which is why this and not a regular expression is what judges whether a
     * column added to `orders` leaves the orders alone.
     *
     * @return array<string, array{columns: array<string, bool>, rows: list<array<string, mixed>>}>
     */
    private function shapeAndRowsOfTheCustomerTables(): array
    {
        $snapshot = [];

        foreach (self::GUARDED as $table) {
            if (!Schema::hasTable($table)) {
                $snapshot[$table] = ['columns' => [], 'rows' => []];
                continue;
            }

            $columns = [];
            foreach (Schema::getColumns($table) as $column) {
                $columns[$column['name']] = (bool) $column['nullable'] || $column['default'] !== null;
            }
            $snapshot[$table] = [
                'columns' => $columns,
                'rows' => array_map(static fn (object $row): array => (array) $row, DB::table($table)->orderBy('id')->get()->all()),
            ];
        }

        return $snapshot;
    }

    /**
     * What is not as it was, in words. A column that is gone, a column that arrived without a way for the rows already there to have a value for it, and a row whose values moved are
     * each one entry; a row is compared on the columns it had, so that adding a nullable column is not read as rewriting every row.
     *
     * @param array<string, array{columns: array<string, bool>, rows: list<array<string, mixed>>}> $was
     * @param array<string, array{columns: array<string, bool>, rows: list<array<string, mixed>>}> $now
     * @return list<string>
     */
    private function whatChangedInTheCustomerTables(array $was, array $now): array
    {
        $changes = [];

        foreach ($was as $table => $before) {
            foreach (array_diff(array_keys($before['columns']), array_keys($now[$table]['columns'])) as $column) {
                $changes[] = $table . ': a column is gone (' . $column . ')';
            }
            foreach (array_diff(array_keys($now[$table]['columns']), array_keys($before['columns'])) as $column) {
                if (!$now[$table]['columns'][$column]) {
                    $changes[] = $table . ': a column was added that is neither nullable nor defaulted (' . $column . ')';
                }
            }
            $rows = array_map(static fn (array $row): array => array_intersect_key($row, $before['columns']), $now[$table]['rows']);
            if ($rows !== $before['rows']) {
                $changes[] = $table . ': the rows are not what they were';
            }
        }

        return $changes;
    }

    /**
     * The six tables as a deploy finds them, with a row in each. `orders`, `order_details` and `carts` come from the fulfilment tests' inferred core schema, which also brings the
     * `products` and `admins` the migrations of this chain need; `shipping_addresses` comes from the address tests'; `users` and `guest_users` are built here, because no builder in
     * `tests/Support` creates those two beside that core — the one that creates them (`BuildsStorefrontAddressSchema`) brings an `orders` and a `carts` of its own. Inferred, like
     * every schema in `tests/Support`: the installation dump that owns these tables is not in the repository, so this proves what the migrations do and not the live schema.
     */
    private function buildTheCustomerTables(): void
    {
        $this->buildCoreSchema();
        $this->createLegacyAddressTables();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->decimal('wallet_balance', 24, 2)->default(0);
            $table->integer('loyalty_point')->default(0);
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('guest_users', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address')->nullable();
            $table->string('fcm_token')->nullable();
            $table->timestamps();
        });

        $this->buildTheOtherTablesTheChainReaches();
    }

    /**
     * The tables of this chain's OWN migrations that are not customer tables, so that every migration of it can really run here.
     *
     * The point of the real run is to watch a deploy against seeded customer rows; a migration that falls over because a table it changes is
     * not in this world proves nothing about the customer tables either way, which is exactly what the guard reports when one does. So the
     * tables it needs are built, empty: a migration that only adds a column to one of them has something to add it to, and the customer rows
     * beside it are still watched for every write and every change of shape.
     */
    private function buildTheOtherTablesTheChainReaches(): void
    {
        // 2026_12_01_100000_add_storefront_country_to_coupons_table adds a column to this; the chain creates every other table it touches.
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('code')->nullable();
            $table->timestamps();
        });
    }

    /** One customer with money in the wallet, points, a cart line, an order, its line and an address: values a write would have to move, so that moving them shows. */
    private function seedOneCustomerAndTheirHistory(): void
    {
        DB::table('users')->insert(['id' => 1, 'f_name' => 'Sara', 'l_name' => 'Alharbi', 'email' => 'sara@example.test', 'phone' => '0551234567', 'wallet_balance' => 77.5, 'loyalty_point' => 42]);
        DB::table('guest_users')->insert(['id' => 1, 'ip_address' => '10.0.0.1']);
        DB::table('carts')->insert(['id' => 1, 'customer_id' => 1, 'cart_group_id' => 'group-1', 'product_id' => 1]);
        DB::table('orders')->insert(['id' => 1001, 'customer_id' => 1, 'order_amount' => 250.75, 'payment_status' => 'paid']);
        DB::table('order_details')->insert(['id' => 1, 'order_id' => 1001, 'product_id' => 1, 'qty' => 2, 'price' => 125.5]);
        DB::table('shipping_addresses')->insert(['id' => 1, 'customer_id' => '1', 'contact_person_name' => 'Sara Alharbi', 'address' => 'King Fahd Rd 12', 'city' => 'Riyadh', 'zip' => '12211', 'country' => 'Saudi Arabia', 'phone' => '0551234567']);
    }

    public function test_running_every_migration_of_this_chain_leaves_every_customer_row_and_column_as_it_was(): void
    {
        $this->buildTheCustomerTables();
        $this->seedOneCustomerAndTheirHistory();

        $this->assertSame(
            [],
            $this->offencesOfADeployOf($this->migrationsOfTheChain()),
            'a deploy of this chain, run for real against a seeded copy of the six customer tables, writes one of them or changes its shape',
        );
    }

    /**
     * Writes that reading the text of a migration cannot see, and that running it finds anyway: the table named by the model at run time, SQL assembled by `str_replace`, a write
     * through a relation, a drop that never says `dropColumn`, and a NOT NULL column added to `orders`. Each was invisible to every test of this file until this gate existed; the
     * text gate above now names them too, and this one does not care how they are spelled.
     *
     * @return array<string, array{0: string, 1: string}> the migration's source, and the table whose name has to appear in the refusal
     */
    public static function writesOfACustomerTableThatOnlyRunningFinds(): array
    {
        return [
            'the table taken from the model at run time' => [self::migrationThatDoes(up: "DB::table((new User())->getTable())->update(['wallet_balance' => 0, 'loyalty_point' => 0]);"), 'users'],
            'the SQL assembled at run time' => [self::migrationThatDoes(up: "DB::unprepared(str_replace('TBL', 'users', 'UPDATE TBL SET wallet_balance = 0'));"), 'users'],
            'the orders of a customer deleted through the relation' => [self::migrationThatDoes(up: "\$customer = User::find(1);\n        \$customer->orders()->delete();"), 'orders'],
            'the columns dropped by the schema builder itself' => [self::migrationThatDoes(up: "Schema::dropColumns('users', ['wallet_balance', 'loyalty_point']);"), 'users'],
            'a NOT NULL column added to orders' => [self::migrationThatDoes(up: "Schema::table('orders', fn (Blueprint \$table) => \$table->char('storefront_country', 2)->nullable(false));"), 'orders'],
        ];
    }

    /** @return array<string, array{0: string}> what a migration of this chain may do to a customer table, which a real deploy of it has to come back clean from */
    public static function whatAMigrationOfThisChainMayDoToACustomerTable(): array
    {
        return [
            'the nullable column GCC-05 adds to orders' => [self::migrationThatDoes(
                up: "Schema::table('orders', fn (Blueprint \$table) => \$table->char('storefront_country', 2)->nullable());",
                down: "Schema::table('orders', fn (Blueprint \$table) => \$table->dropColumn('storefront_country'));",
            )],
            'a defaulted column added to orders' => [self::migrationThatDoes(up: "Schema::table('orders', fn (Blueprint \$table) => \$table->decimal('currency_rate', 12, 6)->default(1));")],
            'an index added to orders' => [self::migrationThatDoes(up: "Schema::table('orders', fn (Blueprint \$table) => \$table->index('customer_id', 'orders_customer_idx'));")],
            'the customer tables read' => [self::migrationThatDoes(up: "\$exists = DB::table('users')->where('wallet_balance', '>', 0)->exists();")],
        ];
    }

    #[DataProvider('writesOfACustomerTableThatOnlyRunningFinds')]
    public function test_a_write_of_a_customer_table_is_refused_when_the_migration_really_runs(string $source, string $table): void
    {
        $this->buildTheCustomerTables();
        $this->seedOneCustomerAndTheirHistory();

        $offences = $this->offencesOfADeployOf(['the migration' => $this->temporaryFile('php', $source)]);

        $this->assertNotSame([], $offences, 'running it left the customer tables as they were, which this write cannot have done: ' . $source);
        $this->assertStringContainsString($table, implode(' | ', $offences), 'refused, but not for the table it writes');
    }

    #[DataProvider('whatAMigrationOfThisChainMayDoToACustomerTable')]
    public function test_what_a_migration_of_this_chain_may_do_is_not_refused_when_it_really_runs(string $source): void
    {
        $this->buildTheCustomerTables();
        $this->seedOneCustomerAndTheirHistory();

        $this->assertSame([], $this->offencesOfADeployOf(['the migration' => $this->temporaryFile('php', $source)]), 'refused although it is allowed: ' . $source);
    }

    /** The source of a migration of this chain, written around what it does, so a case of the providers goes through everything a real migration goes through. */
    private static function migrationThatDoes(string $up, string $above = '', string $down = '', string $below = '', string $imports = '', string $beforeTheMigration = ''): string
    {
        return implode("\n", [
            '<?php',
            '',
            'use App\Models\Order;',
            'use App\Models\User;',
            'use Illuminate\Database\Migrations\Migration;',
            'use Illuminate\Database\Schema\Blueprint;',
            'use Illuminate\Support\Facades\DB;',
            'use Illuminate\Support\Facades\Schema;',
            $imports,
            '',
            $beforeTheMigration,
            '',
            'return new class extends Migration {',
            $above,
            '    public function up(): void',
            '    {',
            '        ' . $up,
            '    }',
            '',
            '    public function down(): void',
            '    {',
            '        ' . $down,
            '    }',
            '',
            $below,
            '};',
            '',
        ]);
    }

    /**
     * One entry per column a deploy adds to a customer table that is neither nullable nor defaulted. The column methods come from the framework through the scanner, because the
     * list written out here had twenty-one names in it and the blueprint has seventy: `mediumInteger`, `unsignedTinyInteger`, `year`, `ipAddress` and `binary` were all missing, and
     * each adds a NOT NULL column to `orders` as surely as `string()` does. The blueprint's variable is matched whatever it is called, because this repository's own migrations name
     * it `$blueprint`. `nullable(false)` is not nullable, however it reads.
     *
     * This is the reader for a migration nobody runs; the gate that judges the migrations of this chain is
     * test_running_every_migration_of_this_chain_leaves_every_customer_row_and_column_as_it_was, which asks the database itself whether a column it gained is nullable.
     *
     * @return list<string>
     */
    private function columnsAddedToACustomerTableIn(string $deployed): array
    {
        if ($this->guardedTablesIn(deployed: $deployed) === []) {
            return [];
        }

        preg_match_all('/\$\w+\s*->\s*(?:' . implode('|', PlatformWriteScanner::blueprintMethodsThatAddAColumn()) . ')\s*\([^;]*;/', $deployed, $found);

        return array_values(array_filter($found[0], static fn (string $statement): bool => !preg_match('/->\s*nullable\s*\(\s*(?:true\s*)?\)|->\s*default\s*\(/', $statement)));
    }
}
