<?php

namespace Tests\Feature\Wallet;

use App\Enums\Wallet\MarketBalanceKind;
use App\Enums\Wallet\MarketBalanceRefusal;
use App\Services\Wallet\CustomerMarketBalances;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMarketAccountSchema;
use Tests\TestCase;

/**
 * What a market account is once the key is on and the expand migration has run (GCC-15; E-01, E-02, E-03): one account per customer per
 * market, holding that market's currency, and no path that moves money from one market to another.
 *
 * Nothing in the application calls the service yet, so these are the properties a later slice wires the writers against.
 */
class MarketAccountIsolationTest extends TestCase
{
    use BuildsMarketAccountSchema;

    private const CUSTOMER = 1;

    private CustomerMarketBalances $balances;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMarketAccountSchema();
        $this->runMarketAccountMigration();
        $this->withMarketAccountsOn();
        $this->seedCustomer(self::CUSTOMER, walletBalance: 250.5, loyaltyPoints: 80);
        $this->balances = app(CustomerMarketBalances::class);
    }

    /** @return array<string, array{MarketBalanceKind}> */
    public static function balances(): array
    {
        return ['the wallet' => [MarketBalanceKind::Wallet], 'the loyalty points' => [MarketBalanceKind::Loyalty]];
    }

    private function amount(MarketBalanceKind $kind, string $market): float
    {
        return $this->balances->amount(kind: $kind, customerId: self::CUSTOMER, market: $market);
    }

    // ---- one market's money is its own ---------------------------------------------------------------------------------------

    #[DataProvider('balances')]
    public function test_a_credit_to_kuwait_leaves_the_saudi_account_and_the_saudi_ledger_untouched(MarketBalanceKind $kind): void
    {
        $this->balances->credit(kind: $kind, customerId: self::CUSTOMER, market: 'SA', amount: 40);
        $this->recordMovement($kind->ledgerTable(), customerId: self::CUSTOMER, market: 'SA', credit: 40);
        $saudiLedger = DB::table($kind->ledgerTable())->where('storefront_country', 'SA')->orderBy('id')->get()->toArray();

        $credit = $this->balances->credit(kind: $kind, customerId: self::CUSTOMER, market: 'KW', amount: 100);

        $this->assertTrue($credit->applied);
        $this->assertSame(100.0, $credit->amount);
        $this->assertSame('KWD', $credit->currencyCode);
        $this->assertSame(40.0, $this->amount($kind, 'SA'), 'the Saudi account is what it was');
        $this->assertEquals($saudiLedger, DB::table($kind->ledgerTable())->where('storefront_country', 'SA')->orderBy('id')->get()->toArray(), 'the Saudi ledger is what it was');
    }

    #[DataProvider('balances')]
    public function test_a_credit_to_saudi_arabia_leaves_the_kuwaiti_account_and_ledger_untouched(MarketBalanceKind $kind): void
    {
        $this->balances->credit(kind: $kind, customerId: self::CUSTOMER, market: 'KW', amount: 100);
        $this->recordMovement($kind->ledgerTable(), customerId: self::CUSTOMER, market: 'KW', credit: 100);
        $kuwaitiLedger = DB::table($kind->ledgerTable())->where('storefront_country', 'KW')->orderBy('id')->get()->toArray();

        $this->balances->credit(kind: $kind, customerId: self::CUSTOMER, market: 'SA', amount: 40);

        $this->assertSame(100.0, $this->amount($kind, 'KW'), 'the Kuwaiti account is what it was');
        $this->assertEquals($kuwaitiLedger, DB::table($kind->ledgerTable())->where('storefront_country', 'KW')->orderBy('id')->get()->toArray());
    }

    #[DataProvider('balances')]
    public function test_a_debit_tagged_for_one_market_cannot_reduce_another_markets_account(MarketBalanceKind $kind): void
    {
        $this->balances->credit(kind: $kind, customerId: self::CUSTOMER, market: 'KW', amount: 100);

        $debit = $this->balances->debit(kind: $kind, customerId: self::CUSTOMER, market: 'AE', amount: 50);

        $this->assertFalse($debit->applied, 'the Emirati account holds nothing');
        $this->assertSame(MarketBalanceRefusal::Overdraft, $debit->refusal);
        $this->assertSame(100.0, $this->amount($kind, 'KW'), 'the Kuwaiti account still holds everything');
        $this->assertSame(0.0, $this->amount($kind, 'AE'));
    }

    #[DataProvider('balances')]
    public function test_a_debit_takes_from_the_market_it_names_and_from_no_other(MarketBalanceKind $kind): void
    {
        $this->balances->credit(kind: $kind, customerId: self::CUSTOMER, market: 'KW', amount: 100);
        $this->balances->credit(kind: $kind, customerId: self::CUSTOMER, market: 'AE', amount: 100);

        $debit = $this->balances->debit(kind: $kind, customerId: self::CUSTOMER, market: 'AE', amount: 50);

        $this->assertTrue($debit->applied);
        $this->assertSame(50.0, $debit->amount);
        $this->assertSame(50.0, $this->amount($kind, 'AE'));
        $this->assertSame(100.0, $this->amount($kind, 'KW'));
    }

    #[DataProvider('balances')]
    public function test_each_of_the_six_markets_keeps_its_own_amount_in_its_own_currency(MarketBalanceKind $kind): void
    {
        $given = ['SA' => 10, 'AE' => 20, 'KW' => 30, 'QA' => 40, 'BH' => 50, 'OM' => 60];

        foreach ($given as $market => $amount) {
            $this->balances->credit(kind: $kind, customerId: self::CUSTOMER, market: $market, amount: $amount);
        }

        foreach ($given as $market => $amount) {
            $this->assertSame((float) $amount, $this->amount($kind, $market), "market {$market}");
        }

        $this->assertSame(
            ['AE' => 'AED', 'BH' => 'BHD', 'KW' => 'KWD', 'OM' => 'OMR', 'QA' => 'QAR', 'SA' => 'SAR'],
            DB::table($kind->accountsTable())->orderBy('market')->pluck('currency_code', 'market')->all(),
        );
    }

    public function test_the_wallet_and_the_loyalty_points_of_one_market_are_two_separate_accounts(): void
    {
        $this->balances->credit(kind: MarketBalanceKind::Wallet, customerId: self::CUSTOMER, market: 'KW', amount: 100);

        $this->assertSame(100.0, $this->amount(MarketBalanceKind::Wallet, 'KW'));
        $this->assertSame(0.0, $this->amount(MarketBalanceKind::Loyalty, 'KW'));
        $this->assertSame(0, DB::table('customer_market_loyalty_accounts')->count());
    }

    // ---- the account ---------------------------------------------------------------------------------------------------------

    #[DataProvider('balances')]
    public function test_a_market_the_customer_has_no_account_in_reads_zero(MarketBalanceKind $kind): void
    {
        $this->assertSame(0.0, $this->amount($kind, 'OM'));
        $this->assertSame(0, DB::table($kind->accountsTable())->count(), 'reading opens no account');
    }

    #[DataProvider('balances')]
    public function test_a_customer_who_does_not_exist_reads_zero(MarketBalanceKind $kind): void
    {
        $this->assertSame(0.0, $this->balances->amount(kind: $kind, customerId: 99, market: 'KW'));
    }

    #[DataProvider('balances')]
    public function test_two_credits_to_one_market_add_up_in_the_one_account(MarketBalanceKind $kind): void
    {
        $this->balances->credit(kind: $kind, customerId: self::CUSTOMER, market: 'KW', amount: 100);
        $second = $this->balances->credit(kind: $kind, customerId: self::CUSTOMER, market: 'KW', amount: 12.5);

        $this->assertTrue($second->applied);
        $this->assertSame(112.5, $second->amount);
        $this->assertSame(1, DB::table($kind->accountsTable())->where(['customer_id' => self::CUSTOMER, 'market' => 'KW'])->count());
    }

    #[DataProvider('balances')]
    public function test_the_one_global_balance_is_never_written_by_this_service(MarketBalanceKind $kind): void
    {
        $before = DB::table('users')->where('id', self::CUSTOMER)->get()->toArray();

        $this->balances->credit(kind: $kind, customerId: self::CUSTOMER, market: 'KW', amount: 100);
        $this->balances->debit(kind: $kind, customerId: self::CUSTOMER, market: 'KW', amount: 50);

        $this->assertEquals($before, DB::table('users')->where('id', self::CUSTOMER)->get()->toArray());
    }

    // ---- what is refused -----------------------------------------------------------------------------------------------------

    #[DataProvider('balances')]
    public function test_a_debit_may_empty_an_account_but_not_take_it_below_zero(MarketBalanceKind $kind): void
    {
        $this->balances->credit(kind: $kind, customerId: self::CUSTOMER, market: 'KW', amount: 100);

        $toTheLast = $this->balances->debit(kind: $kind, customerId: self::CUSTOMER, market: 'KW', amount: 100);
        $oneMore = $this->balances->debit(kind: $kind, customerId: self::CUSTOMER, market: 'KW', amount: 0.5);

        $this->assertTrue($toTheLast->applied, 'the exact amount is allowed');
        $this->assertSame(0.0, $toTheLast->amount);
        $this->assertFalse($oneMore->applied);
        $this->assertSame(MarketBalanceRefusal::Overdraft, $oneMore->refusal);
        $this->assertSame(0.0, $this->amount($kind, 'KW'));
    }

    #[DataProvider('balances')]
    public function test_a_market_this_platform_does_not_serve_is_refused_and_opens_no_account(MarketBalanceKind $kind): void
    {
        $change = $this->balances->credit(kind: $kind, customerId: self::CUSTOMER, market: 'US', amount: 100);

        $this->assertFalse($change->applied);
        $this->assertSame(MarketBalanceRefusal::UnknownMarket, $change->refusal);
        $this->assertSame(0.0, $this->amount($kind, 'US'));
        $this->assertSame(0, DB::table($kind->accountsTable())->count());
    }

    /** @return array<string, array{float}> */
    public static function amountsThatMoveNothing(): array
    {
        return ['nothing' => [0.0], 'a negative amount' => [-10.0]];
    }

    #[DataProvider('amountsThatMoveNothing')]
    public function test_an_amount_that_is_not_a_positive_number_moves_nothing(float $amount): void
    {
        $this->balances->credit(kind: MarketBalanceKind::Wallet, customerId: self::CUSTOMER, market: 'KW', amount: 100);

        foreach ([
            $this->balances->credit(kind: MarketBalanceKind::Wallet, customerId: self::CUSTOMER, market: 'KW', amount: $amount),
            $this->balances->debit(kind: MarketBalanceKind::Wallet, customerId: self::CUSTOMER, market: 'KW', amount: $amount),
        ] as $change) {
            $this->assertFalse($change->applied);
            $this->assertSame(MarketBalanceRefusal::NotAnAmount, $change->refusal);
        }

        $this->assertSame(100.0, $this->amount(MarketBalanceKind::Wallet, 'KW'));
    }

    #[DataProvider('balances')]
    public function test_an_account_kept_in_another_currency_than_its_market_trades_in_is_refused(MarketBalanceKind $kind): void
    {
        DB::table($kind->accountsTable())->insert([
            'customer_id' => self::CUSTOMER,
            'market' => 'KW',
            'currency_code' => 'USD',
            $kind->amountColumn() => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $change = $this->balances->credit(kind: $kind, customerId: self::CUSTOMER, market: 'KW', amount: 50);

        $this->assertFalse($change->applied);
        $this->assertSame(MarketBalanceRefusal::CurrencyMismatch, $change->refusal);
        $this->assertSame('USD', $change->currencyCode);
        $this->assertSame(100.0, $this->amount($kind, 'KW'), 'nothing is credited at another currency\'s rate');
    }

    // ---- the market is read as the configuration names it --------------------------------------------------------------------

    #[DataProvider('balances')]
    public function test_the_market_is_read_however_a_caller_spells_it(MarketBalanceKind $kind): void
    {
        $this->balances->credit(kind: $kind, customerId: self::CUSTOMER, market: ' kw ', amount: 100);

        $this->assertSame(100.0, $this->amount($kind, 'KW'));
        $this->assertSame(1, DB::table($kind->accountsTable())->where('market', 'KW')->count());
    }
}
