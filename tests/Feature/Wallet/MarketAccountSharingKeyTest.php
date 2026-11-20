<?php

namespace Tests\Feature\Wallet;

use App\Enums\Wallet\MarketBalanceKind;
use App\Models\CustomerMarketLoyaltyAccount;
use App\Models\CustomerMarketWalletAccount;
use App\Services\Wallet\CustomerMarketBalances;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BuildsMarketAccountSchema;
use Tests\Support\CapturesTableState;
use Tests\TestCase;

/**
 * E-02's fourth criterion, the two halves of it that need a database: the key is there and adding it changed nothing, and the key does
 * nothing, so turning sharing on takes a code change. The third half — that no file of the application names the column — is a source scan
 * with nothing to build, and lives next door in `NothingReadsTheSharingKeyTest`.
 *
 * ADDING IT CHANGED NOTHING is the claim the slice rests on, and a column list cannot show it: names sorted into an array see no row, no
 * default, no type, no index and no position, so a migration that backfilled every account with a group and zeroed every customer's wallet
 * on the way past would pass one. An account of each kind is opened BEFORE the migration runs and every table of the database is read whole
 * on both sides of it (`CapturesTableState`): the one difference allowed is the column itself, appended, nullable, with no default and no
 * index, declared exactly as a 64-character string is declared on whichever engine the suite is pointed at.
 *
 * IT DOES NOTHING is asserted from both sides: two markets of one customer carrying the SAME key still hold their own balances, and a
 * market with nothing of its own is still refused the money of a market it is grouped with. So a person who wants sharing has to write the
 * code that groups by it, and the scan next door then fails on that code, as it should, until it is reviewed deliberately.
 *
 * THE ROLLBACK is refused once any account of EITHER kind carries a key, which is what the migration's docblock promises, and both kinds
 * are asserted: a refusal that looked only at the wallet accounts would drop a loyalty group silently.
 */
class MarketAccountSharingKeyTest extends TestCase
{
    use BuildsMarketAccountSchema;
    use CapturesTableState;

    /** The nullable group key the structure leaves room for, added by 2026_12_05_140000_add_sharing_key_to_market_balance_accounts. */
    private const SHARING_KEY = 'sharing_group';

    private const SHARING_KEY_MIGRATION = '2026_12_05_140000_add_sharing_key_to_market_balance_accounts.php';

    private const CUSTOMER = 1;

    /** A table of a name no migration of this platform uses, made and dropped to read how this engine declares the column the migration asks for. */
    private const HOW_THE_KEY_IS_DECLARED = 'sharing_key_declaration_probe';

    /** The keys an account answers with, which are its own columns and never the group key. */
    private const KEYS_AN_ACCOUNT_ANSWERS_WITH = [
        'wallet' => ['id', 'customer_id', 'market', 'currency_code', 'legal_entity', 'balance', 'created_at', 'updated_at'],
        'loyalty' => ['id', 'customer_id', 'market', 'currency_code', 'legal_entity', 'points', 'created_at', 'updated_at'],
    ];

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

        $column = $this->columnsOf(table: $accounts)[self::SHARING_KEY];

        $this->assertTrue($column['nullable'], 'A sharing key that is not nullable would have to be filled for every account that exists.');
        $this->assertTrue(
            $this->meansNoDefault($column['default']),
            'A default would group accounts the moment the migration ran, which is not a code change. The database records the default as '
            . var_export($column['default'], true) . '.',
        );
    }

    public function test_it_changes_nothing_but_adding_the_one_column(): void
    {
        $this->withMarketAccountsOn();
        $this->openAccount(kind: MarketBalanceKind::Wallet, market: 'KW', amount: 30.0);
        $this->openAccount(kind: MarketBalanceKind::Loyalty, market: 'BH', amount: 70.0);
        $this->recordMovement('wallet_transactions', customerId: self::CUSTOMER, market: 'KW', credit: 30);

        $tables = $this->everyTableOfTheDatabase();
        $before = $this->stateOf(tables: $tables);

        $this->runSharingKeyMigration();

        $this->assertSame($this->withTheKeyAppended(before: $before), $this->stateOf(tables: $tables));
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->runSharingKeyMigration();

        $before = $this->stateOf(tables: $this->everyTableOfTheDatabase());

        $this->runSharingKeyMigration();

        $this->assertSame($before, $this->stateOf(tables: $this->everyTableOfTheDatabase()));
    }

    public function test_it_rolls_back_while_nothing_is_grouped(): void
    {
        $tables = $this->everyTableOfTheDatabase();
        $before = $this->stateOf(tables: $tables);

        $this->runSharingKeyMigration();
        $this->rollBackSharingKeyMigration();

        $this->assertSame($before, $this->stateOf(tables: $tables));
    }

    #[DataProvider('balances')]
    public function test_rolling_back_is_refused_once_accounts_are_grouped(MarketBalanceKind $kind): void
    {
        $this->withMarketAccountsOn();
        $this->runSharingKeyMigration();
        $this->openAccount(kind: $kind, market: 'KW', amount: 25.0);
        $this->groupEveryAccount(kind: $kind);

        $refusal = $this->refusalOf(migration: $this->rollBackSharingKeyMigration(...));

        $this->assertNotNull(
            $refusal,
            "Rolling back forgot the group of every account in {$kind->accountsTable()}, which cannot be worked out again.",
        );
        $this->assertStringContainsString('grouped by a sharing key', $refusal->getMessage());
        $this->assertSame(
            'gulf',
            DB::table($kind->accountsTable())->value(self::SHARING_KEY),
            'The refusal came after the column was dropped somewhere, so the group it refused to forget is gone anyway.',
        );
    }

    // ---- and it refuses to run before the tables it adds to exist ------------------------------------------------------------

    public function test_it_refuses_to_run_before_the_accounts_exist(): void
    {
        $this->rollBackMarketAccountMigration();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/2026_12_05_120000_create_customer_market_balance_accounts/');

        $this->runSharingKeyMigration();
    }

    /**
     * The order that is the whole reason the refusal is not a skip. A migration that returns quietly is recorded as run and never offered
     * again, so a skip here would leave the column permanently absent on a database where this ran first — which a branch retargeted off
     * 2026_12_05_120000, or a rollback of it, is enough to produce. It throws instead, nothing is recorded, and the column is there as soon
     * as the two have run in either order.
     */
    public function test_the_column_is_there_whichever_order_the_two_migrations_run_in(): void
    {
        $this->runSharingKeyMigration();

        $this->assertTrue(Schema::hasColumn('customer_market_wallet_accounts', self::SHARING_KEY));
        $this->assertTrue(Schema::hasColumn('customer_market_loyalty_accounts', self::SHARING_KEY));

        $this->rollBackSharingKeyMigration();
        $this->rollBackMarketAccountMigration();

        $refusal = $this->refusalOf(migration: $this->runSharingKeyMigration(...));

        $this->assertNotNull(
            $refusal,
            'The sharing key migration ran before the accounts existed. Laravel would record it and never offer it again, so the column '
            . 'would be missing for good.',
        );

        $this->runMarketAccountMigration();
        $this->runSharingKeyMigration();

        $this->assertTrue(Schema::hasColumn('customer_market_wallet_accounts', self::SHARING_KEY));
        $this->assertTrue(Schema::hasColumn('customer_market_loyalty_accounts', self::SHARING_KEY));
    }

    // ---- nothing reads it, and nothing serves it ------------------------------------------------------------------------------

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
        $model = $this->accountModel($kind);

        $this->assertNotContains(
            self::SHARING_KEY,
            $model->getFillable(),
            $model::class . ' would let a mass assignment set the sharing key.',
        );
    }

    /**
     * The one way a column nothing reads still leaves the platform: a model serialising itself. Both models hide the key, as
     * `WalletTransaction` and `LoyaltyPointTransaction` hide what E-05 added to the ledgers, so the first API answer to serialise an
     * account cannot carry a group the moment a later slice begins to fill it.
     */
    #[DataProvider('balances')]
    public function test_an_account_that_carries_a_key_never_serialises_it(MarketBalanceKind $kind): void
    {
        $this->withMarketAccountsOn();
        $this->runSharingKeyMigration();
        $this->openAccount(kind: $kind, market: 'KW', amount: 25.0);
        $this->groupEveryAccount(kind: $kind);

        $account = $this->accountModel($kind)->newQuery()->where('market', 'KW')->firstOrFail();

        $this->assertSame(
            'gulf',
            $account->getAttribute(self::SHARING_KEY),
            'The row this model read carries no key, so what it leaves out of its answer proves nothing.',
        );
        $this->assertSame(
            self::KEYS_AN_ACCOUNT_ANSWERS_WITH[$kind->value],
            array_keys($account->toArray()),
            $account::class . ' answers with keys that are not its own columns.',
        );
        $this->assertStringNotContainsString(self::SHARING_KEY, $account->toJson(), 'A serialised account carries the group key.');
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

    private function accountModel(MarketBalanceKind $kind): Model
    {
        return match ($kind) {
            MarketBalanceKind::Wallet => new CustomerMarketWalletAccount(),
            MarketBalanceKind::Loyalty => new CustomerMarketLoyaltyAccount(),
        };
    }

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

    /**
     * The refusal a migration throws, or null when it ran. Every assertion about it is made afterwards, outside the catch: PHPUnit's own
     * `AssertionFailedError` IS a `RuntimeException` (through `PHPUnit\Framework\Exception`), so a `fail()` inside a `catch (RuntimeException)`
     * would be swallowed by the very block meant to prove the refusal, and the mutation that removed the refusal would pass.
     */
    private function refusalOf(callable $migration): ?RuntimeException
    {
        try {
            $migration();
        } catch (RuntimeException $refusal) {
            return $refusal;
        }

        return null;
    }

    private function runSharingKeyMigration(): void
    {
        (require database_path('migrations/' . self::SHARING_KEY_MIGRATION))->up();
    }

    private function rollBackSharingKeyMigration(): void
    {
        (require database_path('migrations/' . self::SHARING_KEY_MIGRATION))->down();
    }

    /**
     * Every table THIS database holds, read from the database rather than listed here, so that the snapshot cannot fall behind a table a
     * later slice adds.
     *
     * Each name is put back to the connection with `hasTable`, which answers for the database the connection is on, because
     * `Schema::getTables()` does not: with no schema named it asks `information_schema` for every table of every database on the server
     * (`MySqlGrammar::compileSchemaWhereClause` excludes only MySQL's own five), so on a shared test server this listed other runs' tables
     * and the list changed under the test while it ran. SQLite's own bookkeeping tables are not the platform's either.
     *
     * @return list<string> sorted
     */
    private function everyTableOfTheDatabase(): array
    {
        $tables = array_values(array_unique(array_filter(
            array_map(static fn (array $table): string => (string) $table['name'], Schema::getTables()),
            static fn (string $table): bool => !str_starts_with($table, 'sqlite_') && Schema::hasTable($table),
        )));
        sort($tables);

        $this->assertContains('customer_market_wallet_accounts', $tables, 'The snapshot is reading a database without the account tables.');
        $this->assertContains('users', $tables);

        return $tables;
    }

    /**
     * The snapshot as it should read after the migration: the key appended to each account table's columns, declared the way this engine
     * declares what the migration asks for, and empty on every row that was already there. Appended, because `Schema::getColumns()` is read
     * in table order and a row is read in the same order, so a column added anywhere but the end is a different answer here — which is the
     * point: an instant add before MySQL 8.0.29 has to be the last column.
     *
     * @param array<string, mixed> $before
     * @return array<string, mixed>
     */
    private function withTheKeyAppended(array $before): array
    {
        $declaration = $this->howThisEngineDeclaresTheKey();

        foreach ([MarketBalanceKind::Wallet, MarketBalanceKind::Loyalty] as $kind) {
            $accounts = $kind->accountsTable();
            $before[$accounts]['columns'][self::SHARING_KEY] = $declaration;
            $before[$accounts]['rows'] = array_map(
                static fn (array $row): array => [...$row, self::SHARING_KEY => null],
                $before[$accounts]['rows'],
            );
        }

        return $before;
    }

    /**
     * What a 64-character nullable string with no default and no index reads as on the engine the suite is pointed at, asked by declaring
     * one and reading it back. Asking the engine rather than writing `varchar(64)` here is what makes the type assertion mean anything on
     * MySQL and MariaDB, where a widened column reads as `varchar(255)`; SQLite declares every string `varchar` and cannot see a width.
     *
     * @return array<string, mixed>
     */
    private function howThisEngineDeclaresTheKey(): array
    {
        Schema::create(self::HOW_THE_KEY_IS_DECLARED, function (Blueprint $table): void {
            $table->string(self::SHARING_KEY, 64)->nullable();
        });

        $declaration = $this->columnsOf(table: self::HOW_THE_KEY_IS_DECLARED)[self::SHARING_KEY];

        Schema::drop(self::HOW_THE_KEY_IS_DECLARED);

        return $declaration;
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
}
