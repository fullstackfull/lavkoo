<?php

namespace Tests\Feature\Wallet;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsMarketAccountSchema;
use Tests\TestCase;

/**
 * E-07: the balances a customer already holds are carried into the accounts of one market, once, and only from a copy that reconciles.
 *
 * The `users` row stays the authority after a run — nothing here writes to it — so what the command buys is a market account that says
 * the same thing, which is what lets a market later be switched over to read its own account without a customer's money appearing or
 * disappearing at the moment of the switch.
 *
 * The three refusals matter as much as the filling: a dormant key, a copy whose balances do not equal their ledgers, and a run that was
 * not asked to write. Each one has to leave the database exactly as it was.
 */
class MarketAccountBackfillTest extends TestCase
{
    use BuildsMarketAccountSchema;

    private const CUSTOMER = 41;

    private const OTHER_CUSTOMER = 42;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMarketAccountSchema();
    }

    protected function tearDown(): void
    {
        $this->startFromAnEmptyMarketAccountDatabase();

        parent::tearDown();
    }

    /**
     * A customer whose wallet and points equal the ledger rows behind them, which is what the reconciliation asks for.
     */
    private function aReconcilingCustomer(int $customerId, float $wallet, float $points): void
    {
        $this->seedCustomer($customerId, walletBalance: $wallet, loyaltyPoints: $points);

        if ($wallet > 0) {
            $this->recordMovement('wallet_transactions', $customerId, null, credit: $wallet);
        }
        if ($points > 0) {
            $this->recordMovement('loyalty_point_transactions', $customerId, null, credit: $points);
        }
    }

    /** @param array<string, mixed> $options */
    private function backfill(array $options = []): int
    {
        return $this->artisan('wallet:backfill-market-accounts', $options + ['--market' => 'SA'])->run();
    }

    /** @return array<string, float|null> what both account tables hold for the customer in the market */
    private function accounts(int $customerId, string $market = 'SA'): array
    {
        return [
            'wallet' => $this->marketWalletBalance($customerId, $market),
            'loyalty' => $this->marketLoyaltyPoints($customerId, $market),
        ];
    }

    public function test_it_carries_each_balance_into_the_account_of_the_market(): void
    {
        $this->withMarketAccountsOn();
        $this->runMarketAccountMigration();
        $this->aReconcilingCustomer(self::CUSTOMER, wallet: 150.5, points: 300);

        $this->assertSame(0, $this->backfill(['--write' => true]));

        $this->assertSame(['wallet' => 150.5, 'loyalty' => 300.0], $this->accounts(self::CUSTOMER));
        $this->assertSame(
            [150.5, 300.0],
            [
                (float) DB::table('users')->where('id', self::CUSTOMER)->value('wallet_balance'),
                (float) DB::table('users')->where('id', self::CUSTOMER)->value('loyalty_point'),
            ],
            'the row the platform still reads from is untouched',
        );
    }

    public function test_the_account_it_opens_is_in_the_currency_the_market_sells_in(): void
    {
        $this->withMarketAccountsOn();
        $this->runMarketAccountMigration();
        $this->aReconcilingCustomer(self::CUSTOMER, wallet: 40, points: 0);

        $this->backfill(['--write' => true, '--ledger' => 'wallet']);

        $this->assertSame('SAR', (string) DB::table('customer_market_wallet_accounts')
            ->where(['customer_id' => self::CUSTOMER, 'market' => 'SA'])->value('currency_code'));
    }

    public function test_running_it_again_adds_nothing(): void
    {
        $this->withMarketAccountsOn();
        $this->runMarketAccountMigration();
        $this->aReconcilingCustomer(self::CUSTOMER, wallet: 150.5, points: 300);
        $this->backfill(['--write' => true]);

        $this->assertSame(0, $this->backfill(['--write' => true]));

        $this->assertSame(['wallet' => 150.5, 'loyalty' => 300.0], $this->accounts(self::CUSTOMER), 'the second run left the accounts where the first put them');
        $this->assertSame(1, DB::table('customer_market_wallet_accounts')->where('customer_id', self::CUSTOMER)->count());
    }

    /** An account emptied to zero is still an account: a run that read what it holds rather than whether it is there would refill it. */
    public function test_an_account_that_has_been_emptied_is_not_filled_again(): void
    {
        $this->withMarketAccountsOn();
        $this->runMarketAccountMigration();
        $this->aReconcilingCustomer(self::CUSTOMER, wallet: 150.5, points: 0);
        $this->backfill(['--write' => true, '--ledger' => 'wallet']);
        DB::table('customer_market_wallet_accounts')->where('customer_id', self::CUSTOMER)->update(['balance' => 0]);

        $this->backfill(['--write' => true, '--ledger' => 'wallet']);

        $this->assertSame(0.0, $this->marketWalletBalance(self::CUSTOMER, 'SA'), 'the spent account was left as it was');
    }

    public function test_a_customer_with_nothing_to_carry_gets_no_account(): void
    {
        $this->withMarketAccountsOn();
        $this->runMarketAccountMigration();
        $this->aReconcilingCustomer(self::CUSTOMER, wallet: 0, points: 0);

        $this->backfill(['--write' => true]);

        $this->assertSame(['wallet' => null, 'loyalty' => null], $this->accounts(self::CUSTOMER), 'an empty balance opens no account');
    }

    public function test_without_write_it_reports_and_changes_nothing(): void
    {
        $this->withMarketAccountsOn();
        $this->runMarketAccountMigration();
        $this->aReconcilingCustomer(self::CUSTOMER, wallet: 150.5, points: 300);

        $this->assertSame(0, $this->backfill());

        $this->assertSame(['wallet' => null, 'loyalty' => null], $this->accounts(self::CUSTOMER));
        $this->assertSame(0, DB::table('customer_market_wallet_accounts')->count());
    }

    public function test_it_refuses_while_the_market_accounts_are_off(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.currency.enabled' => true, 'gcc.storefronts.market_accounts.enabled' => false]);
        $this->runMarketAccountMigration();
        $this->aReconcilingCustomer(self::CUSTOMER, wallet: 150.5, points: 300);

        $this->assertSame(1, $this->backfill(['--write' => true]), 'a dormant writer would have reported a filled account for every customer');

        $this->assertSame(0, DB::table('customer_market_wallet_accounts')->count());
    }

    public function test_it_refuses_while_the_storefront_currencies_are_off(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.currency.enabled' => false, 'gcc.storefronts.market_accounts.enabled' => true]);
        $this->runMarketAccountMigration();
        $this->aReconcilingCustomer(self::CUSTOMER, wallet: 150.5, points: 300);

        $this->assertSame(1, $this->backfill(['--write' => true]), 'there is no market currency to open an account in');

        $this->assertSame(0, DB::table('customer_market_wallet_accounts')->count());
    }

    /** The ledgers have no market column before this migration either, so the customer is seeded without movements: the refusal comes first. */
    public function test_it_refuses_before_the_migration_has_run(): void
    {
        $this->withMarketAccountsOn();
        $this->seedCustomer(self::CUSTOMER, walletBalance: 150.5, loyaltyPoints: 300);

        $this->assertSame(1, $this->backfill(['--write' => true]));
        $this->assertFalse(Schema::hasTable('customer_market_wallet_accounts'));
    }

    public function test_it_refuses_a_market_the_configuration_does_not_name(): void
    {
        $this->withMarketAccountsOn();
        $this->runMarketAccountMigration();
        $this->aReconcilingCustomer(self::CUSTOMER, wallet: 150.5, points: 300);

        $this->assertSame(1, $this->artisan('wallet:backfill-market-accounts', ['--market' => 'ZZ', '--write' => true])->run());

        $this->assertSame(0, DB::table('customer_market_wallet_accounts')->count());
    }

    /**
     * The gate the migration plan asks for: a copy where a balance does not equal the ledger behind it is a copy where nobody knows which
     * side is right, so nothing is filled from it -- not even the accounts of the customers who do reconcile.
     */
    public function test_it_fills_nothing_while_a_balance_does_not_equal_its_ledger(): void
    {
        $this->withMarketAccountsOn();
        $this->runMarketAccountMigration();
        $this->aReconcilingCustomer(self::CUSTOMER, wallet: 150.5, points: 0);
        $this->seedCustomer(self::OTHER_CUSTOMER, walletBalance: 90, loyaltyPoints: 0);

        $this->assertSame(1, $this->backfill(['--write' => true, '--ledger' => 'wallet']));

        $this->assertSame(
            [null, null],
            [$this->marketWalletBalance(self::CUSTOMER, 'SA'), $this->marketWalletBalance(self::OTHER_CUSTOMER, 'SA')],
            'neither customer was carried over, the reconciling one included',
        );
    }

    /** The same difference, explained with evidence the operator wrote down, is no longer a reason to refuse. */
    public function test_a_difference_the_operator_has_explained_does_not_stop_it(): void
    {
        $this->withMarketAccountsOn();
        $this->runMarketAccountMigration();
        $this->aReconcilingCustomer(self::CUSTOMER, wallet: 150.5, points: 0);
        $this->seedCustomer(self::OTHER_CUSTOMER, walletBalance: 90, loyaltyPoints: 0);
        $evidence = tempnam(sys_get_temp_dir(), 'explained');
        file_put_contents((string) $evidence, "# the balance of this customer was settled by hand\n" . self::OTHER_CUSTOMER . "\n");

        $exit = $this->backfill(['--write' => true, '--ledger' => 'wallet', '--explained' => (string) $evidence]);
        unlink((string) $evidence);

        $this->assertSame(0, $exit);
        $this->assertSame(
            [150.5, 90.0],
            [$this->marketWalletBalance(self::CUSTOMER, 'SA'), $this->marketWalletBalance(self::OTHER_CUSTOMER, 'SA')],
            'both balances were carried, the explained one included',
        );
    }

    /** Only the market that was asked for is filled: this command never spreads one balance over six accounts. */
    public function test_it_fills_the_one_market_it_was_asked_for(): void
    {
        $this->withMarketAccountsOn();
        $this->runMarketAccountMigration();
        $this->aReconcilingCustomer(self::CUSTOMER, wallet: 150.5, points: 0);

        $this->backfill(['--write' => true, '--ledger' => 'wallet']);

        $this->assertSame(150.5, $this->marketWalletBalance(self::CUSTOMER, 'SA'));
        foreach (['AE', 'KW', 'QA', 'BH', 'OM'] as $market) {
            $this->assertNull($this->marketWalletBalance(self::CUSTOMER, $market), "no account was opened in {$market}");
        }
    }

    public function test_it_fills_only_the_ledger_it_was_asked_for(): void
    {
        $this->withMarketAccountsOn();
        $this->runMarketAccountMigration();
        $this->aReconcilingCustomer(self::CUSTOMER, wallet: 150.5, points: 300);

        $this->backfill(['--write' => true, '--ledger' => 'loyalty']);

        $this->assertSame(['wallet' => null, 'loyalty' => 300.0], $this->accounts(self::CUSTOMER));
    }

    public function test_it_carries_every_customer_who_holds_something(): void
    {
        $this->withMarketAccountsOn();
        $this->runMarketAccountMigration();
        $this->aReconcilingCustomer(self::CUSTOMER, wallet: 10, points: 0);
        $this->aReconcilingCustomer(self::OTHER_CUSTOMER, wallet: 25.25, points: 0);

        $this->backfill(['--write' => true, '--ledger' => 'wallet', '--chunk' => 1]);

        $this->assertSame(
            [10.0, 25.25],
            [$this->marketWalletBalance(self::CUSTOMER, 'SA'), $this->marketWalletBalance(self::OTHER_CUSTOMER, 'SA')],
            'a chunk of one still reaches both',
        );
    }
}
