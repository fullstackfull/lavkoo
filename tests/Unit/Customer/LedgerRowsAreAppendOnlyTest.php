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
 */
class LedgerRowsAreAppendOnlyTest extends TestCase
{
    /** The roots every file of which is read. */
    private const ROOTS = ['app', 'Modules', 'routes', 'config', 'database', 'resources'];

    /** The ledgers, as a table and as a model: an edit of any of these four names fails this check. */
    private const LEDGERS = ['wallet_transactions', 'loyalty_point_transactions', 'model WalletTransaction', 'model LoyaltyPointTransaction'];

    private const LEDGER_MODELS = ['WalletTransaction', 'LoyaltyPointTransaction'];

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

    public function test_neither_ledger_repository_carries_a_method_that_could_edit_a_row(): void
    {
        foreach ([WalletTransactionRepository::class, LoyaltyPointTransactionRepository::class, WalletTransactionRepositoryInterface::class, LoyaltyPointTransactionRepositoryInterface::class] as $class) {
            $methods = array_map(static fn (ReflectionMethod $method): string => $method->getName(), (new ReflectionClass($class))->getMethods());
            $editing = array_values(array_intersect($methods, ['update', 'delete', 'destroy', 'forceDelete', 'truncate', 'upsert', 'updateOrCreate', 'save']));

            $this->assertSame([], $editing, $class . ' offers a way to edit a money row; an unused one is an accident waiting for a caller.');
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
