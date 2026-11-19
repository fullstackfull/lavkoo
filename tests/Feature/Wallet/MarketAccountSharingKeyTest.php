<?php

namespace Tests\Feature\Wallet;

use App\Enums\Wallet\MarketBalanceKind;
use App\Models\CustomerMarketLoyaltyAccount;
use App\Models\CustomerMarketWalletAccount;
use App\Services\Wallet\CustomerMarketBalances;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Tests\Support\BuildsMarketAccountSchema;
use Tests\TestCase;

/**
 * E-02's fourth criterion: a sharing key is there in the structure, nothing reads it, and turning sharing on takes a code change.
 *
 * All three halves are asserted and each fails on its own. The column is there and is nullable with no default, so adding it changed no
 * existing row. NOTHING names it: every PHP, JavaScript and Vue file of the application is read, and the only two files allowed to say
 * `sharing_group` are the migration that adds it and this test. And the key does nothing: two markets of one customer carrying the SAME
 * key still hold their own balances, and a market with nothing of its own is still refused the money of a market it is grouped with — so
 * a person who wants sharing has to write the code that groups by it, which is the criterion, and the scan then fails on that code, as it
 * should, until it is reviewed and this list is changed deliberately.
 *
 * The scan is the part with teeth. It fails for a file that merely WRITES the key as well as one that reads it, because a value written is
 * a value something later believes; it reads PHP token by token, so the migration's own explanation of the key does not count as a reader
 * while a `->sharing_group` or a `'sharing_group'` anywhere does; and it fails from the other side as well, for a migration or a test that
 * stops naming it, so the guard cannot be left asserting a column nobody adds any more.
 */
class MarketAccountSharingKeyTest extends TestCase
{
    use BuildsMarketAccountSchema;

    /** The nullable group key the structure leaves room for, added by 2026_12_05_140000_add_sharing_key_to_market_balance_accounts. */
    private const SHARING_KEY = 'sharing_group';

    private const SHARING_KEY_MIGRATION = '2026_12_05_140000_add_sharing_key_to_market_balance_accounts.php';

    private const CUSTOMER = 1;

    /** The only files of the repository that may name the key: the migration that adds it, and this test. */
    private const MAY_NAME_THE_KEY = [
        'database/migrations/' . self::SHARING_KEY_MIGRATION,
        'tests/Feature/Wallet/MarketAccountSharingKeyTest.php',
    ];

    /** Where the application's own code lives. `vendor` is somebody else's code and `storage` is not code at all. */
    private const SOURCE_DIRECTORIES = ['app', 'config', 'database', 'routes', 'resources', 'Modules', 'tests'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMarketAccountSchema();
        $this->runMarketAccountMigration();
        $this->seedCustomer(self::CUSTOMER, walletBalance: 250.5, loyaltyPoints: 80);
    }

    /** @return array<string, array{MarketBalanceKind}> */
    public static function balances(): array
    {
        return ['the wallet' => [MarketBalanceKind::Wallet], 'the loyalty points' => [MarketBalanceKind::Loyalty]];
    }

    // ---- the key is there, and adding it changed nothing ---------------------------------------------------------------------

    #[DataProvider('balances')]
    public function test_the_key_is_there_and_is_nullable_with_no_default(MarketBalanceKind $kind): void
    {
        $this->runSharingKeyMigration();

        $accounts = $kind->accountsTable();

        $this->assertTrue(
            Schema::hasColumn($accounts, self::SHARING_KEY),
            "The structure leaves no room for a sharing key: {$accounts} has no " . self::SHARING_KEY . ' column.',
        );

        $column = $this->columnOf(table: $accounts, column: self::SHARING_KEY);

        $this->assertTrue($column['nullable'], 'A sharing key that is not nullable would have to be filled for every account that exists.');
        $this->assertTrue(
            $this->meansNoDefault($column['default']),
            'A default would group accounts the moment the migration ran, which is not a code change. The database records the default as '
            . var_export($column['default'], true) . '.',
        );
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->runSharingKeyMigration();

        $before = $this->columnsOfTheAccounts();

        $this->runSharingKeyMigration();

        $this->assertSame($before, $this->columnsOfTheAccounts());
    }

    public function test_it_rolls_back_while_nothing_is_grouped(): void
    {
        $before = $this->columnsOfTheAccounts();

        $this->runSharingKeyMigration();
        $this->rollBackSharingKeyMigration();

        $this->assertSame($before, $this->columnsOfTheAccounts());
    }

    public function test_rolling_back_is_refused_once_accounts_are_grouped(): void
    {
        $this->withMarketAccountsOn();
        $this->runSharingKeyMigration();
        $this->openAccount(kind: MarketBalanceKind::Wallet, market: 'KW', amount: 25.0);
        $this->groupEveryAccount(kind: MarketBalanceKind::Wallet);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/grouped by a sharing key/');

        $this->rollBackSharingKeyMigration();
    }

    // ---- nothing reads it ----------------------------------------------------------------------------------------------------

    public function test_no_file_of_the_application_names_the_key(): void
    {
        $named = $this->filesNamingTheKey();

        $this->assertSame(
            self::MAY_NAME_THE_KEY,
            $named,
            'Only the migration that adds the sharing key and this test may name it, so that turning sharing on is a code change a person '
            . 'writes and another reviews. Files naming it now: ' . implode(', ', $named),
        );
    }

    #[DataProvider('balances')]
    public function test_an_account_the_service_opens_carries_no_key(MarketBalanceKind $kind): void
    {
        $this->withMarketAccountsOn();
        $this->runSharingKeyMigration();
        $this->openAccount(kind: $kind, market: 'KW', amount: 25.0);

        $key = DB::table($kind->accountsTable())->where('market', 'KW')->value(self::SHARING_KEY);

        $this->assertNull($key, 'The service wrote a sharing key. Nothing may write one until sharing is designed.');
    }

    #[DataProvider('balances')]
    public function test_the_key_cannot_be_set_by_filling_a_model(MarketBalanceKind $kind): void
    {
        $model = $kind === MarketBalanceKind::Wallet ? new CustomerMarketWalletAccount() : new CustomerMarketLoyaltyAccount();

        $this->assertNotContains(
            self::SHARING_KEY,
            $model->getFillable(),
            $model::class . ' would let a mass assignment set the sharing key.',
        );
    }

    // ---- and it does nothing, so enabling sharing needs a code change --------------------------------------------------------

    #[DataProvider('balances')]
    public function test_two_markets_given_the_same_key_still_hold_their_own_money(MarketBalanceKind $kind): void
    {
        $this->withMarketAccountsOn();
        $this->runSharingKeyMigration();
        $this->openAccount(kind: $kind, market: 'KW', amount: 30.0);
        $this->openAccount(kind: $kind, market: 'BH', amount: 70.0);
        $this->groupEveryAccount(kind: $kind);

        $balances = app(CustomerMarketBalances::class);

        $this->assertSame(30.0, $balances->amount(kind: $kind, customerId: self::CUSTOMER, market: 'KW'));
        $this->assertSame(70.0, $balances->amount(kind: $kind, customerId: self::CUSTOMER, market: 'BH'));

        $spent = $balances->debit(kind: $kind, customerId: self::CUSTOMER, market: 'KW', amount: 30.0);

        $this->assertTrue($spent->applied, 'The debit of the first market was refused, so what it left the second holding proves nothing.');
        $this->assertSame(0.0, $balances->amount(kind: $kind, customerId: self::CUSTOMER, market: 'KW'));
        $this->assertSame(
            70.0,
            $balances->amount(kind: $kind, customerId: self::CUSTOMER, market: 'BH'),
            'Spending one market emptied another that carries its key. The key must do nothing until sharing is written.',
        );
    }

    #[DataProvider('balances')]
    public function test_a_market_the_key_groups_is_still_refused_the_money_of_another(MarketBalanceKind $kind): void
    {
        $this->withMarketAccountsOn();
        $this->runSharingKeyMigration();
        $this->openAccount(kind: $kind, market: 'BH', amount: 70.0);
        $this->groupEveryAccount(kind: $kind);

        $balances = app(CustomerMarketBalances::class);
        $refused = $balances->debit(kind: $kind, customerId: self::CUSTOMER, market: 'KW', amount: 10.0);

        $this->assertFalse($refused->applied, 'A market holding nothing was allowed to spend the money of a market carrying its key.');
        $this->assertSame(70.0, $balances->amount(kind: $kind, customerId: self::CUSTOMER, market: 'BH'));
    }

    // ---- helpers -------------------------------------------------------------------------------------------------------------

    private function openAccount(MarketBalanceKind $kind, string $market, float $amount): void
    {
        $opened = app(CustomerMarketBalances::class)->credit(kind: $kind, customerId: self::CUSTOMER, market: $market, amount: $amount);

        $this->assertTrue($opened->applied, "The {$market} account was not opened, so this case would prove nothing.");
    }

    /** Every account of this kind given one group, which is what a later sharing slice would write and what nothing writes today. */
    private function groupEveryAccount(MarketBalanceKind $kind): void
    {
        $grouped = DB::table($kind->accountsTable())->update([self::SHARING_KEY => 'gulf']);

        $this->assertGreaterThan(0, $grouped, 'No account was grouped, so this case would prove nothing about a key that is set.');
    }

    private function runSharingKeyMigration(): void
    {
        (require database_path('migrations/' . self::SHARING_KEY_MIGRATION))->up();
    }

    private function rollBackSharingKeyMigration(): void
    {
        (require database_path('migrations/' . self::SHARING_KEY_MIGRATION))->down();
    }

    /** @return array<string, list<string>> every account table's columns, so that a change to any of them is seen */
    private function columnsOfTheAccounts(): array
    {
        $columns = [];

        foreach (MarketBalanceKind::cases() as $kind) {
            $accounts = $kind->accountsTable();
            $columns[$accounts] = Schema::hasTable($accounts) ? Schema::getColumnListing($accounts) : [];
            sort($columns[$accounts]);
        }

        return $columns;
    }

    /**
     * Whether a recorded column default means "there is none", asked in the one way every database this suite runs on answers alike.
     *
     * SQLite hands back PHP null for a column with no default. MariaDB hands back the bare four-letter word NULL, because that is what
     * `information_schema.COLUMN_DEFAULT` holds for one, and PHPUnit's assertNull then fails on a column that is in fact exactly as the
     * criterion asks. Reading that bare word as "none" hides no real default: MariaDB records a string default WITH its quotes, so a
     * column that genuinely defaulted to the text NULL would read as "'NULL'" here and still fail, as would 'grp' or any other group.
     */
    private function meansNoDefault(mixed $recorded): bool
    {
        return $recorded === null || $recorded === 'NULL';
    }

    /** @return array{nullable: bool, default: mixed} what the database itself says about one column */
    private function columnOf(string $table, string $column): array
    {
        foreach (Schema::getColumns($table) as $described) {
            if ($described['name'] === $column) {
                return ['nullable' => $described['nullable'], 'default' => $described['default']];
            }
        }

        throw new RuntimeException("{$table} has no {$column} column to describe.");
    }

    /**
     * Every file of the application that names the sharing key, read as code rather than as text: a comment or a docblock that mentions it
     * is prose, not a reader, and the migration's own explanation is full of the name. A quoted string, an identifier (`->sharing_group`)
     * and the markup of a Blade file all count, because each of the three is a way to read or write the column.
     *
     * @return list<string> paths relative to the repository root, sorted, so that the failure message reads as a list
     */
    private function filesNamingTheKey(): array
    {
        $root = base_path();
        $named = [];

        foreach (self::SOURCE_DIRECTORIES as $directory) {
            if (!is_dir($root . '/' . $directory)) {
                continue;
            }

            $tree = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . '/' . $directory, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($tree as $file) {
                if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'js', 'vue'], true)) {
                    continue;
                }

                if ($this->namesTheKey(code: (string) file_get_contents($file->getPathname()), extension: $file->getExtension())) {
                    $named[] = str_replace($root . '/', '', $file->getPathname());
                }
            }
        }

        sort($named);

        return $named;
    }

    /** Whether one file's code names the key. PHP is read token by token so that comments do not count; anything else is read as text. */
    private function namesTheKey(string $code, string $extension): bool
    {
        if (!str_contains($code, self::SHARING_KEY)) {
            return false;
        }

        if ($extension !== 'php') {
            return true;
        }

        foreach (token_get_all($code) as $token) {
            if (is_array($token) && !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) && str_contains($token[1], self::SHARING_KEY)) {
                return true;
            }
        }

        return false;
    }
}
