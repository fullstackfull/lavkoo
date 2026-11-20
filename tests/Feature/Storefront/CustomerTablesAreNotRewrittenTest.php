<?php

namespace Tests\Feature\Storefront;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PlatformWriteScanner;
use Tests\TestCase;

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
 * `tests/Fixtures/migrations-on-main.txt` is the migration file names on origin/main, sorted, one per line.
 * Everything in `database/migrations` that is not in it is this chain's, and each of those is read.
 *
 * What is read of each is everything it does on a deploy: `up()`, the constants it names a table with, and the
 * private methods `up()` calls. The body of `down()` is cut out and read by nothing. A rollback is SUPPOSED to
 * drop what `up()` added — the storefront-country migration on this very tree drops its column and its index
 * in `down()`, as it should.
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
 * of this file passed it. Of the thirty-five writes the provider below now lists, that version refused three.
 * A regular expression is what let the rest through, so a write is no longer looked for
 * with one: it is read by `tests/Support/PlatformWriteScanner`, the repository's own token-by-token scanner, which
 * resolves a table named by a string, a constant, an alias or a variable, a builder kept in a variable, raw
 * SQL and a model, and reports a name it cannot resolve instead of passing it over in silence.
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
    private const MIGRATIONS = __DIR__ . '/../../../database/migrations';

    private const BASELINE = __DIR__ . '/../../Fixtures/migrations-on-main.txt';

    private const MIGRATIONS_ON_MAIN = 336;

    /** The tables that hold a customer and their history. The criterion names the first five; a guest's order is history too. */
    private const GUARDED = ['users', 'carts', 'orders', 'order_details', 'shipping_addresses', 'guest_users'];

    /** The models of those tables: a migration that wrote rows through one of them would be writing the table. */
    private const GUARDED_MODELS = ['User', 'Cart', 'Order', 'OrderDetail', 'ShippingAddress', 'GuestUser'];

    /**
     * The changes of shape that may never be made to one of those tables. Each is a regular expression over what the migration does on a deploy; a table's rows are a separate
     * question, asked of the scanner, because a regular expression is what missed a write the first time.
     */
    private const REFUSED = [
        'a column is dropped' => '/->\s*dropColumn\s*\(/',
        'a column or table is renamed' => '/->\s*renameColumn\s*\(|Schema::\s*rename\s*\(/',
        'the table is dropped' => '/Schema::\s*drop(IfExists)?\s*\(/',
        'an existing column is retyped' => '/->\s*change\s*\(\s*\)/',
    ];

    /** @return array<string, string> what each migration this chain adds does on a deploy, by file name */
    private function deployedBodiesOfTheChain(): array
    {
        $onMain = array_flip($this->migrationsOnMain());
        $bodies = [];

        foreach ((array) glob(self::MIGRATIONS . '/*.php') as $path) {
            $name = basename((string) $path);

            if (isset($onMain[$name])) {
                continue;
            }

            $bodies[$name] = $this->whatRunsOnADeploy(source: (string) file_get_contents((string) $path));
        }

        return $bodies;
    }

    /**
     * The migration without the body of down(): up(), the constants it names its tables with, and the private methods it calls, which is everything a deploy runs. Reading the text
     * of up() alone was not enough — a write moved into a method declared below down() was read by nothing — and a rollback is still read by nothing, because undoing is its job.
     */
    private function whatRunsOnADeploy(string $source): string
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
            foreach (self::REFUSED as $what => $pattern) {
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
            ...$scanner->tablesAStringWrites($deployed, self::GUARDED),
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

        $this->assertSame([], $offences, 'a migration of this chain takes something away from a customer table, or writes its rows, on a deploy; the body of down() is not read, because a rollback is supposed to undo');
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

        $this->assertSame(
            [],
            $touching,
            'this is the criterion\'s own sentence and it has stopped being true. That is not automatically wrong — an additive, nullable column is allowed and the tests above check it — but it is a fact worth recording, so update this test and the tracker together rather than deleting it.',
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

    public function test_a_column_added_to_a_customer_table_without_a_default_is_still_refused(): void
    {
        $source = self::migrationThatDoes(up: "Schema::table('orders', fn (Blueprint \$table) => \$table->char('storefront_country', 2));");

        $this->assertNotSame([], $this->columnsAddedToACustomerTableIn(deployed: $this->whatRunsOnADeploy(source: $source)));
    }

    /** The source of a migration of this chain, written around what it does, so a case of the providers goes through everything a real migration goes through. */
    private static function migrationThatDoes(string $up, string $above = '', string $down = '', string $below = '', string $imports = ''): string
    {
        return implode("\n", [
            '<?php',
            '',
            'use App\Models\User;',
            'use Illuminate\Database\Migrations\Migration;',
            'use Illuminate\Database\Schema\Blueprint;',
            'use Illuminate\Support\Facades\DB;',
            'use Illuminate\Support\Facades\Schema;',
            $imports,
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

    /** @return list<string> one entry per column a deploy adds to a customer table that is neither nullable nor defaulted */
    private function columnsAddedToACustomerTableIn(string $deployed): array
    {
        if ($this->guardedTablesIn(deployed: $deployed) === []) {
            return [];
        }

        $types = 'string|char|text|longText|integer|bigInteger|unsignedBigInteger|unsignedInteger|tinyInteger|smallInteger|boolean|decimal|double|float|date|dateTime|timestamp|json|uuid|foreignId|enum';
        preg_match_all('/\$table\s*->\s*(?:' . $types . ')\s*\([^;]*;/', $deployed, $found);

        return array_values(array_filter($found[0], static fn (string $statement): bool => !preg_match('/->\s*nullable\s*\(|->\s*default\s*\(/', $statement)));
    }
}
