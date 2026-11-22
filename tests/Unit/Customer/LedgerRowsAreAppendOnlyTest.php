<?php

namespace Tests\Unit\Customer;

use App\Contracts\Repositories\AppendOnlyRepositoryInterface;
use App\Contracts\Repositories\LoyaltyPointTransactionRepositoryInterface;
use App\Contracts\Repositories\RepositoryInterface;
use App\Contracts\Repositories\WalletTransactionRepositoryInterface;
use App\Http\Controllers\Admin\Settings\DatabaseSettingController;
use App\Repositories\LoyaltyPointTransactionRepository;
use App\Repositories\WalletTransactionRepository;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\PlatformWriteScanner;
use Tests\TestCase;

/**
 * The two money ledgers are append-only: a wallet or a loyalty movement is written once and never changed or removed afterwards (E-08.0).
 *
 * Checked in the source, the way PlatformTablesStayThePlatformsTest checks that the modules keep off the platform's tables, by the scanner
 * in `tests/Support/PlatformWriteScanner.php` -- here through `rowsEditedIn()`, which counts only what CHANGES or REMOVES a row that is
 * already there, so the six writers that append a movement are not in its way. Every PHP file of app/, Modules/, routes/, config/,
 * database/ and resources/ is read, and a file whose table name the scan cannot read is reported too, because a name built at run time is
 * the way past every rule that reads a name.
 *
 * Reading text is not watching behaviour, so the two means that used to exist are pinned by reflection as well: neither ledger repository,
 * and neither of their interfaces, carries a method that could edit a row.
 *
 * One check here belongs to E-08.1 rather than E-08.0, and is here because it is the same question asked of the same files: the customer
 * row IS the balance record and the only parent the two ledgers have, so the code that removes one is the code that destroys a balance.
 * Three ways in were tested and a fourth, in the admin panel, was missed; a list of the files allowed to remove a customer row is what
 * would have found it.
 */
class LedgerRowsAreAppendOnlyTest extends TestCase
{
    /** The roots every file of which is read. */
    private const ROOTS = ['app', 'Modules', 'routes', 'config', 'database', 'resources'];

    /** The ledgers, as a table and as a model: an edit of any of these four names fails this check. */
    private const LEDGERS = ['wallet_transactions', 'loyalty_point_transactions', 'model WalletTransaction', 'model LoyaltyPointTransaction'];

    private const LEDGER_MODELS = ['WalletTransaction', 'LoyaltyPointTransaction'];

    /** The ledgers as tables alone, for the question about their shape: a model has no shape to drop. */
    private const LEDGER_TABLES = ['wallet_transactions', 'loyalty_point_transactions'];

    /**
     * Every way the source removes a customer row, and the one file allowed to. The row carries `wallet_balance` and `loyalty_point` and is
     * the only parent the two ledgers have, so removing it destroys a balance record and orphans its history whatever the ledger tables
     * themselves are protected against.
     *
     * @var array<string, string>
     */
    private const REMOVES_A_CUSTOMER_ROW = [
        'a customer model deleted' => '/\$(?:customer|user)\w*\s*->\s*(?:delete|forceDelete)\s*\(/',
        'a customer repository asked to delete' => '/(?:customerRepo|customerRepository|userRepo)\s*->\s*delete\s*\(/',
    ];

    /** The only file that may remove a customer row, because it is the one that decides whether the account holds money first. */
    private const MAY_REMOVE_A_CUSTOMER_ROW = 'app/Services/CustomerAccountDeletionService.php';

    /**
     * The files that change or remove rows in a table they name at run time, and why each one cannot reach a ledger. The scan cannot read
     * these names, so they are listed and accounted for rather than skipped; a new one appearing fails this check and has to be accounted
     * for here too.
     *
     * @var array<string, string>
     */
    private const EDITS_A_TABLE_NAMED_AT_RUN_TIME = [
        'app/Console/Commands/AnalyticsRollup.php' => 'prunes rows past their retention from the four analytics_* tables its own callers name as literals',
        'app/Http/Controllers/Admin/Settings/DatabaseSettingController.php' => 'empties the tables an admin ticks, and both ledgers are in TABLES_NEVER_EMPTIED, which LedgerTablesAreNeverEmptiedTest proves it honours',
        'app/Services/SalesChannels/Dispatch/BuyerEchoScrubber.php' => 'scrubs the buyer\'s words out of the three provider_* tables its own callers name as literals',
        'app/Services/Staff/StaffCountryAssignments.php' => 'inserts into and deletes from admin_country_scopes alone, named as StaffCountryScope::TABLE -- a constant of another class, which this one-file reader cannot resolve, so the name reads as one given at run time. Checked: the file names exactly two tables, the literal admins and that constant, and neither ledger appears in it anywhere',
    ];

    /**
     * Every public method each ledger repository and its interface is allowed to have, in order. A name list of the editing verbs was not
     * enough: `zeroOut()` is none of them and rewrites a money row just the same, so what is allowed is written down instead of what is
     * forbidden. Each entry here reads the ledger, appends to it, or works a figure out and touches no row.
     *
     * @var array<class-string, list<string>>
     */
    private const EVERY_PUBLIC_METHOD = [
        WalletTransactionRepository::class => ['__construct', 'add', 'addFundToWalletBonus', 'addWalletTransaction', 'getFirstWhere', 'getList', 'getListWhere', 'getListWhereSelect'],
        LoyaltyPointTransactionRepository::class => ['__construct', 'add', 'addLoyaltyPointTransaction', 'getFirstWhere', 'getList', 'getListWhere', 'getListWhereSelect'],
        WalletTransactionRepositoryInterface::class => ['add', 'addFundToWalletBonus', 'addWalletTransaction', 'getFirstWhere', 'getList', 'getListWhere', 'getListWhereSelect'],
        LoyaltyPointTransactionRepositoryInterface::class => ['add', 'addLoyaltyPointTransaction', 'getFirstWhere', 'getList', 'getListWhere', 'getListWhereSelect'],
    ];

    /**
     * The files allowed to destroy the shape of a ledger, and why each is. A table keeps every row it was given and still loses them to a
     * migration that drops it or one of its columns, which no guard over row edits can see; so the shape is asked about separately, and
     * against this list.
     *
     * @var array<string, string>
     */
    private const MAY_DESTROY_THE_SHAPE_OF_A_LEDGER = [
        'database/migrations/2022_05_12_104247_create_wallet_transactions_table.php' => 'the wallet ledger\'s own create migration: its down() drops the table it made',
        'database/migrations/2022_05_14_063309_create_loyalty_point_transactions_table.php' => 'the loyalty ledger\'s own create migration: its down() drops the table it made',
        'database/migrations/2023_08_26_173523_add_payment_method_column_to_wallet_transactions_table.php' => 'its down() drops the one column its up() added, and payment_method holds no money',
    ];

    /** The six files that append a movement: the check has to be reading them, or its silence means nothing. */
    private const THE_WRITERS = [
        'app/Repositories/WalletTransactionRepository.php',
        'app/Repositories/LoyaltyPointTransactionRepository.php',
        'app/Traits/CustomerTrait.php',
        'app/Utils/CustomerManager.php',
        'app/Utils/OrderManager.php',
        'app/Http/Controllers/Web/UserLoyaltyController.php',
    ];

    /** @var array<string, string>|null every file of the roots, its code without comments, by path */
    private static ?array $code = null;

    public function test_no_file_changes_or_removes_a_wallet_or_loyalty_ledger_row(): void
    {
        $offenders = [];
        foreach (self::code() as $path => $code) {
            $edited = array_values(array_intersect($this->rowsEditedIn($code), self::LEDGERS));
            if ($edited !== []) {
                $offenders[$path] = $edited;
            }
        }
        ksort($offenders);

        $this->assertSame([], $offenders, 'A money movement is a record of something that happened: it is appended, and nothing goes back and edits it.');
    }

    public function test_the_only_files_that_edit_a_table_named_at_run_time_are_the_ones_accounted_for(): void
    {
        $found = [];
        foreach (self::code() as $path => $code) {
            if (in_array(PlatformWriteScanner::UNKNOWN_TABLE, $this->rowsEditedIn($code), true)) {
                $found[] = $path;
            }
        }
        sort($found);

        $this->assertSame(array_keys(self::EDITS_A_TABLE_NAMED_AT_RUN_TIME), $found, 'A table named at run time is a name this check cannot read: each one is accounted for above, or a ledger could be emptied through it unseen.');
    }

    public function test_the_only_files_that_drop_a_ledger_or_a_column_of_one_are_the_ones_allowed_to(): void
    {
        $found = [];
        foreach (self::code() as $path => $code) {
            $destroyed = array_values(array_intersect((new PlatformWriteScanner(self::LEDGER_MODELS))->schemaDestroyedIn($code), self::LEDGER_TABLES));
            if ($destroyed !== []) {
                $found[$path] = $destroyed;
            }
        }
        ksort($found);

        $this->assertSame(array_keys(self::MAY_DESTROY_THE_SHAPE_OF_A_LEDGER), array_keys($found), 'A migration that drops a money ledger, or a column of one, destroys every movement in it without editing a single row. Only the files named above may, and each one says why.');
    }

    public function test_the_only_code_that_removes_a_customer_row_is_the_service_that_keeps_the_money_first(): void
    {
        $offenders = [];
        foreach (self::code() as $path => $code) {
            foreach (self::REMOVES_A_CUSTOMER_ROW as $description => $pattern) {
                if (preg_match($pattern, $code) === 1 && $path !== self::MAY_REMOVE_A_CUSTOMER_ROW) {
                    $offenders[$path] = $description;
                }
            }
        }
        ksort($offenders);

        $this->assertSame([], $offenders, 'The customer row is the balance record and the only parent the two ledgers have. Every way of closing an account goes through CustomerAccountDeletionService, which keeps the row when it holds money; a path that removes it directly destroys a balance and orphans its history.');
    }

    public function test_the_check_reads_every_root_it_claims_to_and_the_six_writers_still_name_a_ledger(): void
    {
        $paths = array_keys(self::code());

        $this->assertGreaterThan(4000, count($paths), 'The check reads the whole of the six roots.');
        foreach (self::ROOTS as $root) {
            $this->assertNotEmpty(array_filter($paths, static fn (string $path): bool => str_starts_with($path, $root . '/')), "Nothing was read under {$root}/.");
        }
        foreach (self::THE_WRITERS as $writer) {
            $this->assertArrayHasKey($writer, self::code(), 'A file that appends a movement is not being read, so this check proves nothing about it.');
            $this->assertMatchesRegularExpression('/wallet_transactions|loyalty_point_transactions|WalletTransaction|LoyaltyPointTransaction/', self::code()[$writer], "{$writer} no longer names a ledger: either it stopped writing one, or the root is wrong.");
        }
    }

    public function test_appending_a_movement_is_not_an_edit_so_the_writers_are_not_in_the_checks_way(): void
    {
        foreach (self::THE_WRITERS as $writer) {
            $this->assertSame([], array_values(array_intersect($this->rowsEditedIn(self::code()[$writer]), self::LEDGERS)), $writer);
        }
    }

    public function test_neither_ledger_repository_offers_anything_but_the_methods_named_here(): void
    {
        foreach (self::EVERY_PUBLIC_METHOD as $class => $allowed) {
            $methods = array_map(static fn (ReflectionMethod $method): string => $method->getName(), (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC));
            sort($methods);

            $this->assertSame($allowed, $methods, $class . ' has gained or lost a public method. Each one here reads the ledger, appends to it, or works a figure out; a method that is not on this list has to be put on it deliberately, because the container hands callers the concrete class and an undeclared public method on it is callable.');
        }
    }

    public function test_the_append_only_contract_has_no_update_or_delete_and_every_other_repository_still_does(): void
    {
        $appendOnly = array_map(static fn (ReflectionMethod $method): string => $method->getName(), (new ReflectionClass(AppendOnlyRepositoryInterface::class))->getMethods());
        sort($appendOnly);

        $this->assertSame(['add', 'getFirstWhere', 'getList', 'getListWhere'], $appendOnly);
        $this->assertTrue((new ReflectionClass(RepositoryInterface::class))->hasMethod('update'), 'Only the ledgers lose update() and delete(): every other repository keeps them.');
        $this->assertTrue((new ReflectionClass(RepositoryInterface::class))->hasMethod('delete'));
        foreach ([WalletTransactionRepositoryInterface::class, LoyaltyPointTransactionRepositoryInterface::class] as $interface) {
            $this->assertTrue((new ReflectionClass($interface))->isSubclassOf(AppendOnlyRepositoryInterface::class), $interface);
            $this->assertFalse((new ReflectionClass($interface))->isSubclassOf(RepositoryInterface::class), $interface . ' is back on the contract that carries update() and delete().');
        }
    }

    public function test_the_screen_that_empties_tables_protects_both_ledgers(): void
    {
        $protected = (new ReflectionClass(DatabaseSettingController::class))->getConstant('TABLES_NEVER_EMPTIED');

        $this->assertContains('wallet_transactions', $protected);
        $this->assertContains('loyalty_point_transactions', $protected);
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function edits(): array
    {
        return [
            'a query that updates the wallet ledger' => ["DB::table('wallet_transactions')->where('id', 1)->update(['credit' => 0]);", ['wallet_transactions']],
            'a query that deletes a loyalty row' => ["DB::table('loyalty_point_transactions')->where('id', 1)->delete();", ['loyalty_point_transactions']],
            'a truncate' => ["DB::table('wallet_transactions')->truncate();", ['wallet_transactions']],
            'an increment of a money column' => ["DB::table('wallet_transactions')->where('id', 1)->increment('credit');", ['wallet_transactions']],
            'the table name folded out of two literals' => ["DB::table('wallet_' . 'transactions')->where('id', 1)->update(['credit' => 0]);", ['wallet_transactions']],
            'the table name held in a variable' => ["\$table = 'wallet_transactions';\nDB::table(\$table)->where('id', 1)->delete();", [PlatformWriteScanner::UNKNOWN_TABLE]],
            'half the table name built at run time' => ["DB::table('wallet_' . \$suffix)->where('id', 1)->update(['credit' => 0]);", [PlatformWriteScanner::UNKNOWN_TABLE, 'wallet_']],
            'the table name out of a call' => ["DB::table(config('ledger.table'))->where('id', 1)->delete();", [PlatformWriteScanner::UNKNOWN_TABLE]],
            'the table named by a class constant' => ["private const LEDGER = 'wallet_transactions';\nDB::table(self::LEDGER)->where('id', 1)->update(['credit' => 0]);", ['wallet_transactions']],
            'a builder kept in a variable and written later' => ["\$query = DB::table('wallet_transactions')->where('id', 1);\n\$query->update(['credit' => 0]);", ['wallet_transactions']],
            'the ledger model through a static call' => ["WalletTransaction::where('id', 1)->update(['credit' => 0]);", ['model WalletTransaction']],
            'the ledger model fetched and saved again' => ["\$row = WalletTransaction::find(1);\n\$row->credit = 0;\n\$row->save();", ['model WalletTransaction']],
            'the table named by a named argument, which is the house style' => ["DB::table(table: 'wallet_transactions')->where('id', 1)->delete();", ['wallet_transactions']],
            'a named argument with an alias beside it' => ["DB::table(table: 'wallet_transactions', as: 'w')->where('id', 1)->delete();", ['wallet_transactions']],
            'a named argument to from()' => ["DB::query()->from(table: 'loyalty_point_transactions')->update(['credit' => 0]);", ['loyalty_point_transactions']],
            'a class name inside a call is not an argument label' => ["app(WalletTransaction::class)->where('id', 1)->delete();", ['model WalletTransaction']],
            'the ledger model fetched through a held property and saved again' => ["class R {\npublic function __construct(private readonly WalletTransaction \$walletTransaction)\n{\n}\n\npublic function zeroOut(string \$id): bool\n{\n\$movement = \$this->walletTransaction->find(\$id);\n\$movement->credit = 0;\n\$movement->save();\n\nreturn true;\n} }", ['model WalletTransaction']],
            'the ledger model fetched through a held property and updated' => ["class R {\npublic function __construct(private readonly LoyaltyPointTransaction \$loyaltyPointTransaction)\n{\n}\n\npublic function z(string \$id): void\n{\n\$row = \$this->loyaltyPointTransaction->find(\$id);\n\$row->update(['balance' => 0]);\n} }", ['model LoyaltyPointTransaction']],
            'the ledger model held in a property' => ["class R { public function __construct(private readonly WalletTransaction \$walletTransaction) {}\npublic function wipe(array \$params): bool { \$this->walletTransaction->where(\$params)->delete(); return true; } }", ['model WalletTransaction']],
            'the ledger model handed in as a parameter' => ["function reset(LoyaltyPointTransaction \$row): void { \$row->update(['balance' => 0]); }", ['model LoyaltyPointTransaction']],
            'the ledger through a relation' => ["\$user->walletTransaction()->update(['credit' => 0]);", ['model WalletTransaction']],
            'raw SQL' => ["DB::statement('DELETE FROM loyalty_point_transactions WHERE id = 1');", ['loyalty_point_transactions']],
            'raw SQL with the table interpolated' => ["DB::statement(\"UPDATE {\$table} SET credit = 0\");", [PlatformWriteScanner::UNKNOWN_TABLE]],
            'raw SQL built by concatenation' => ["DB::update('update ' . \$table . ' set credit = 0');", [PlatformWriteScanner::UNKNOWN_TABLE]],
            'an insert is not an edit' => ["DB::table('wallet_transactions')->insert(['credit' => 1]);", []],
            'a model created is not an edit' => ["WalletTransaction::create(['credit' => 1]);", []],
            'a new model saved is not an edit' => ["\$row = new WalletTransaction();\n\$row->credit = 1;\n\$row->save();", []],
            'a read is not an edit' => ["DB::table('wallet_transactions')->where('user_id', 1)->get();", []],
            'a comment that names an edit is not one' => ["// DB::table('wallet_transactions')->update(['credit' => 0]);\n\$x = 1;", []],
            'an update of the balance on the customer row is not a ledger edit' => ["DB::table('users')->where('id', 1)->update(['wallet_balance' => 0]);", ['users']],
            'a table whose name only starts like a ledger' => ["DB::table('wallet_transaction_exports')->where('id', 1)->delete();", ['wallet_transaction_exports']],
            'a repository whose name starts like the property is not the model' => ["class C { public function __construct(private readonly WalletTransaction \$walletTransaction) {}\npublic function go(): void { \$this->walletTransactionRepo->update('1', []); } }", []],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('edits')]
    public function test_the_check_sees_an_edit_in_each_of_the_ways_a_ledger_row_could_be_changed(string $code, array $expected): void
    {
        $this->assertSame($expected, $this->rowsEditedIn($code));
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function shapeChanges(): array
    {
        return [
            'the table dropped if it is there' => ["Schema::dropIfExists('wallet_transactions');", ['wallet_transactions']],
            'the table dropped' => ["Schema::drop('loyalty_point_transactions');", ['loyalty_point_transactions']],
            'the table renamed out of the way, which is the name that loses its rows' => ["Schema::rename('wallet_transactions', 'wallet_transactions_old');", ['wallet_transactions']],
            'a money column dropped by an arrow function' => ["Schema::table('wallet_transactions', fn (Blueprint \$table) => \$table->dropColumn('credit'));", ['wallet_transactions']],
            'a money column dropped further down a closure' => ["Schema::table('wallet_transactions', function (Blueprint \$table) {\n\$table->string('note')->nullable();\n\$table->dropColumn('credit');\n});", ['wallet_transactions']],
            'a money column renamed' => ["Schema::table('loyalty_point_transactions', function (Blueprint \$table) { \$table->renameColumn('credit', 'cr'); });", ['loyalty_point_transactions']],
            'the table named by a named argument' => ["Schema::dropIfExists(table: 'wallet_transactions');", ['wallet_transactions']],
            'adding a column is not destroying one' => ["Schema::table('wallet_transactions', function (Blueprint \$table) { \$table->string('payment_method')->nullable(); });", []],
            'changing a column is not destroying it' => ["Schema::table('wallet_transactions', function (Blueprint \$table) { \$table->decimal('credit', 40, 20)->change(); });", []],
            'making the table is not destroying it' => ["Schema::create('wallet_transactions', function (Blueprint \$table) { \$table->id(); });", []],
            'an index dropped is not a row lost' => ["Schema::table('wallet_transactions', function (Blueprint \$table) { \$table->dropIndex('wallet_transactions_user_id_index'); });", []],
            'a table whose name only starts like a ledger' => ["Schema::dropIfExists('wallet_transaction_exports');", ['wallet_transaction_exports']],
            'a comment that names a drop is not one' => ["// Schema::dropIfExists('wallet_transactions');\n\$x = 1;", []],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('shapeChanges')]
    public function test_the_check_sees_each_way_the_shape_of_a_ledger_could_be_destroyed(string $code, array $expected): void
    {
        $this->assertSame($expected, (new PlatformWriteScanner(self::LEDGER_MODELS))->schemaDestroyedIn($code));
    }

    /** @return list<string> */
    private function rowsEditedIn(string $code): array
    {
        return (new PlatformWriteScanner(self::LEDGER_MODELS))->rowsEditedIn($code);
    }

    /** @return array<string, string> every PHP file of the roots, its code without comments, by path from the project root */
    private static function code(): array
    {
        if (self::$code !== null) {
            return self::$code;
        }

        $code = [];
        foreach (self::ROOTS as $root) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($root), FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $code[str_replace(base_path() . '/', '', $file->getPathname())] = self::withoutComments((string) file_get_contents($file->getPathname()));
            }
        }
        ksort($code);

        return self::$code = $code;
    }

    /** A sentence that names a table in a comment is not an edit. */
    private static function withoutComments(string $contents): string
    {
        $code = '';
        foreach (token_get_all($contents) as $token) {
            $code .= is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]) : $token;
        }

        return $code;
    }
}
