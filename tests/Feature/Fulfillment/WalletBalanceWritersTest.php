<?php

namespace Tests\Feature\Fulfillment;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\PlatformWriteScanner;

/**
 * E-03 of the master plan: a customer's balances are changed by their writers and by nothing else. The wallet balance (users.wallet_balance) has four writers and the
 * loyalty points (users.loyalty_point) two, every one of them taking the row lock and writing the ledger row with the balance. Anything else that assigns, updates,
 * increments or rewrites either column skips the lock or the ledger and cannot be reconciled (wallet:reconcile), so this test fails when a new one appears. It reads
 * the source and needs no database.
 *
 * A new writer is added to the list below with the reason, in the same change that adds it. Until the balances are per market (GCC-15) a writer also has to take the
 * market it writes in, so the list is also the list of the places that will.
 *
 * The migrations are read with the rest of the application: a migration runs as root with no lock and no ledger, so a balance written there is the one write that
 * nobody can reconcile afterwards. Reading them costs a false alarm the other way round -- the migrations do change the shape of these columns -- so the scanner is
 * told a schema change from a write of a value by the form of the write and not by a list of files that may make one: see PlatformWriteScanner::columnWritesIn().
 * The tests are deliberately not read: a test sets a balance up on purpose, and its fixtures are not a path a customer's money can take.
 */
class WalletBalanceWritersTest extends TestCase
{
    /** @var array<string, int> file => number of writes of users.wallet_balance */
    private const WALLET_WRITERS = [
        'app/Repositories/WalletTransactionRepository.php' => 1,
        'app/Traits/CustomerTrait.php' => 1,
        'app/Utils/CustomerManager.php' => 1,
        'app/Utils/OrderManager.php' => 1,
    ];

    /** @var array<string, int> file => number of writes of users.loyalty_point */
    private const LOYALTY_WRITERS = [
        'app/Repositories/LoyaltyPointTransactionRepository.php' => 1,
        'app/Utils/CustomerManager.php' => 1,
    ];

    /** @var list<string> where the application's code lives, and the migrations that can write a row before the application is ever asked to */
    private const ROOTS = ['app', 'Modules', 'routes', 'config', 'database'];

    /**
     * A file that every root holds, so that the reach of the scan is asserted and not assumed: a root that is dropped, renamed or narrowed fails this test instead of
     * making the two above pass by reading less. Without it `database` can be taken off the list again and nothing says so.
     *
     * @var array<string, string> root => a file of it
     */
    private const A_FILE_OF_EVERY_ROOT = [
        'app' => 'app/Utils/CustomerManager.php',
        'Modules' => 'Modules/AI/AIProviders/AIProviderManager.php',
        'routes' => 'routes/web/routes.php',
        'config' => 'config/app.php',
        'database' => 'database/migrations/2026_05_07_000001_update_all_decimal_columns_to_40_20.php',
    ];

    /**
     * Every migration that names a balance column, and what it does with it. None of them writes a balance: this is not a list of files that are allowed to, it is the
     * measured answer to what reading the migrations reports, asserted to be nothing. A migration that names one of these columns and is not here fails the test, so a
     * new one is read by a person before it is read by the scanner.
     *
     * @var array<string, string> file => what it does
     */
    private const MIGRATIONS_THAT_NAME_A_BALANCE_COLUMN = [
        'database/migrations/2022_05_12_104511_add_two_column_to_users_table.php' => 'adds both columns to users, and drops them again on the way down',
        'database/migrations/2025_04_16_154104_modify_loyalty_point_column_in_users_table.php' => 'widens loyalty_point and places it after wallet_balance',
        'database/migrations/2025_07_13_021823_modify_wallet_balance_column_in_users_table.php' => 'widens wallet_balance',
        'database/migrations/2025_07_24_104352_create_order_details_rewards_table.php' => 'names loyalty_point in the comment of a reward_type column',
        'database/migrations/2026_05_07_000001_update_all_decimal_columns_to_40_20.php' => '91 ALTER statements that widen the money columns of users, orders, order_details and carts',
    ];

    /** The column of the users table, not a table whose name begins with it: `loyalty_point_transactions` is the ledger. */
    private const NAMES_A_BALANCE_COLUMN = '/\b(?:wallet_balance|loyalty_point)\b/';

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Every write of one balance column in a piece of PHP source. The shared scanner reads it, so that the forms a write can take -- an assignment, an array key given a
     * value, an increment, setAttribute, raw SQL that sets or inserts the column, each of them under a name the code spells indirectly -- are defined once for every
     * guard that asks, and a form added for one guard is seen by all of them.
     *
     * @return list<string>
     */
    public static function writesOf(string $column, string $source): array
    {
        return PlatformWriteScanner::columnWritesIn($source, $column);
    }

    /** @return list<string> every file the scan reads, relative to the project root, sorted */
    private function scannedFiles(): array
    {
        $files = [];

        foreach (self::ROOTS as $root) {
            $directory = self::root() . '/' . $root;
            if (!is_dir($directory)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = substr($file->getPathname(), strlen(self::root()) + 1);
                }
            }
        }

        sort($files);

        return $files;
    }

    /** @return array<string, list<string>> relative path => the writes of the column found in it */
    private function writersOf(string $column): array
    {
        $found = [];

        foreach ($this->scannedFiles() as $file) {
            $writes = self::writesOf($column, (string) file_get_contents(self::root() . '/' . $file));
            if ($writes !== []) {
                $found[$file] = $writes;
            }
        }

        ksort($found);

        return $found;
    }

    /** @param array<string, int> $allowed */
    private function assertOnlyTheseWrite(string $column, array $allowed): void
    {
        $actual = array_map('count', $this->writersOf($column));

        $this->assertSame(
            $allowed,
            $actual,
            "users.{$column} is written by code that is not on the list (or a listed writer changed the number of its writes). Go through one of the writers, which take the row "
            . 'lock and write the ledger row, or add this one to the list with the reason.',
        );
    }

    public function test_the_wallet_balance_is_written_by_its_four_writers_and_nothing_else(): void
    {
        $this->assertOnlyTheseWrite(column: 'wallet_balance', allowed: self::WALLET_WRITERS);
    }

    public function test_the_loyalty_points_are_written_by_their_two_writers_and_nothing_else(): void
    {
        $this->assertOnlyTheseWrite(column: 'loyalty_point', allowed: self::LOYALTY_WRITERS);
    }

    public function test_the_scan_reads_every_root_it_names_so_a_narrowed_root_cannot_make_it_pass(): void
    {
        $this->assertSame(
            self::ROOTS,
            array_keys(self::A_FILE_OF_EVERY_ROOT),
            'Every root is named with a file of it, so that the scan is known to have read it. A root added or removed is added or removed in both places.',
        );

        $scanned = $this->scannedFiles();

        $this->assertGreaterThan(500, count($scanned));
        foreach (self::A_FILE_OF_EVERY_ROOT as $root => $file) {
            $this->assertDirectoryExists(self::root() . '/' . $root);
            $this->assertContains($file, $scanned, "The scan does not read {$root}: a balance written there is invisible to this test.");
        }
        foreach ([...array_keys(self::WALLET_WRITERS), ...array_keys(self::LOYALTY_WRITERS)] as $writer) {
            $this->assertFileExists(self::root() . '/' . $writer);
            $this->assertContains($writer, $scanned);
        }
    }

    public function test_the_migrations_that_name_a_balance_column_change_its_shape_and_write_no_balance(): void
    {
        $naming = array_values(array_filter(
            $this->scannedFiles(),
            fn (string $file): bool => str_starts_with($file, 'database/migrations/')
                && preg_match(self::NAMES_A_BALANCE_COLUMN, (string) file_get_contents(self::root() . '/' . $file)) === 1,
        ));

        $this->assertSame(
            array_keys(self::MIGRATIONS_THAT_NAME_A_BALANCE_COLUMN),
            $naming,
            'A migration names a balance column and is not on the list: say there what it does with it, and leave it off the writers above only because it writes no balance.',
        );

        foreach (array_keys(self::MIGRATIONS_THAT_NAME_A_BALANCE_COLUMN) as $migration) {
            $source = (string) file_get_contents(self::root() . '/' . $migration);
            $this->assertSame([], self::writesOf('wallet_balance', $source), $migration);
            $this->assertSame([], self::writesOf('loyalty_point', $source), $migration);
        }
    }

    /** @return array<string, array{string, string, int}> a source line, the column and how many writes of it the scan must find */
    public static function writesAndNonWrites(): array
    {
        return [
            'assignment' => ['$user->wallet_balance = $current + $credit;', 'wallet_balance', 1],
            'compound assignment' => ['$user->wallet_balance += 5; $user->wallet_balance -= 2; $user->wallet_balance ??= 0;', 'wallet_balance', 3],
            'update array' => ["User::where('id', 1)->update(['wallet_balance' => \$new]);", 'wallet_balance', 1],
            'fill array' => ["\$user->forceFill(['loyalty_point' => \$points])->save();", 'loyalty_point', 1],
            'raw value in an array' => ["->update(['wallet_balance' => DB::raw('wallet_balance + 5')]);", 'wallet_balance', 1],
            'increment' => ["\$user->increment('wallet_balance', 5); \$user->decrementQuietly('wallet_balance', 1);", 'wallet_balance', 2],
            'setAttribute' => ["\$user->setAttribute('loyalty_point', 3);", 'loyalty_point', 1],
            'raw SQL' => ['DB::statement("update users set wallet_balance = wallet_balance - ? where id = ?");', 'wallet_balance', 1],
            'raw SQL with the identifier in backticks' => ['DB::statement("UPDATE `users` SET `wallet_balance` = 0");', 'wallet_balance', 1],
            'raw SQL that names the column of a joined table' => ['DB::statement("UPDATE `users` u JOIN tops t ON t.user_id = u.id SET u.`wallet_balance` = t.amount");', 'wallet_balance', 1],
            'raw SQL that inserts the column' => ['DB::statement("INSERT INTO users (id, wallet_balance) VALUES (1, 500)");', 'wallet_balance', 1],
            'raw SQL that upserts the column' => ['DB::statement("INSERT INTO users (id) VALUES (1) ON DUPLICATE KEY UPDATE loyalty_point = 5");', 'loyalty_point', 1],
            'raw SQL in a heredoc' => ["DB::unprepared(<<<SQL\nUPDATE `users` SET `wallet_balance` = 0 WHERE id > 0\nSQL);", 'wallet_balance', 1],
            'the column named by a constant' => ["const WALLET = 'wallet_balance';\nDB::table('users')->update([self::WALLET => 0]);", 'wallet_balance', 1],
            'the column built by concatenation' => ["DB::table('users')->update(['wallet' . '_balance' => 0]);", 'wallet_balance', 1],
            'the column spelled with an escape sequence' => ['DB::table("users")->update(["loyalty\x5fpoint" => 0]);', 'loyalty_point', 1],
            'the column bound by a loop over a list of columns' => ["const MONEY = ['wallet_balance', 'loyalty_point'];\nforeach (self::MONEY as \$column) { DB::table('users')->update([\$column => 0]); }", 'wallet_balance', 1],
            'the column held by a variable' => ["\$column = 'wallet_balance';\n\$user->{\$column} = 0;", 'wallet_balance', 1],
            'a write made by a helper and not by up()' => ["public function up(): void { \$this->zero(); }\nprivate function zero(): void { DB::table('users')->update(['wallet_balance' => 0]); }", 'wallet_balance', 1],
            'a read' => ['$balance = $user->wallet_balance; if ($user->wallet_balance == 0) { echo $user->wallet_balance; }', 'wallet_balance', 0],
            'a comparison' => ['if ($user->loyalty_point === 5 || $user->loyalty_point >= 3) {}', 'loyalty_point', 0],
            'a cast' => ["protected \$casts = ['wallet_balance' => 'float', 'loyalty_point' => 'float'];", 'wallet_balance', 0],
            'a name map' => ["['loyalty_point' => 'loyalty_point']", 'loyalty_point', 0],
            'a comment' => ["// \$user->wallet_balance = 5;\n/* ->update(['wallet_balance' => 1]) */\n# \$user->wallet_balance -= 1;", 'wallet_balance', 0],
            'the other column' => ['$user->loyalty_point = 5;', 'wallet_balance', 0],
            'a longer name' => ['$user->total_wallet_balance = 5; $x->wallet_balance_before = 1;', 'wallet_balance', 0],
            'a column widened by the schema builder' => ["\$table->decimal('wallet_balance', 18, 12)->change();", 'wallet_balance', 0],
            'a column added and dropped by the schema builder' => ["\$table->float('wallet_balance')->nullable();\n\$table->dropColumn('wallet_balance');", 'wallet_balance', 0],
            'a column widened by raw DDL' => ['DB::statement("ALTER TABLE `users` MODIFY COLUMN `wallet_balance` DECIMAL(40,20) NULL DEFAULT NULL");', 'wallet_balance', 0],
            'a column given a default by raw DDL' => ['DB::statement("ALTER TABLE users ALTER COLUMN wallet_balance SET DEFAULT 0");', 'wallet_balance', 0],
            'a column of a table being created' => ['DB::statement("CREATE TABLE users (id int, wallet_balance decimal(40,20))");', 'wallet_balance', 0],
            'a column named as the position of another' => ["\$table->decimal('loyalty_point', 18, 4)->after('wallet_balance')->default(0)->nullable()->change();", 'wallet_balance', 0],
            'a column named in the comment of another' => ["\$table->string('reward_type')->comment('coupon, loyalty_point and others')->nullable();", 'loyalty_point', 0],
            'a table whose name begins with the column' => ["Schema::create('loyalty_point_transactions', function (Blueprint \$table) { \$table->id(); });", 'loyalty_point', 0],
            'the shape of the tables of a constant changed in a loop' => ["const TABLES = ['users', 'wallet_transactions'];\nforeach (self::TABLES as \$name) { Schema::table(\$name, function (Blueprint \$table) { \$table->decimal('wallet_balance', 40, 20)->change(); }); }", 'wallet_balance', 0],
            'the column read by raw SQL' => ['DB::statement("SELECT wallet_balance = 0 FROM users");', 'wallet_balance', 0],
        ];
    }

    #[DataProvider('writesAndNonWrites')]
    public function test_the_scan_finds_every_kind_of_write_and_ignores_reads_casts_and_schema_changes(string $line, string $column, int $expected): void
    {
        $this->assertCount($expected, self::writesOf($column, "<?php\n" . $line . "\n"));
    }
}
