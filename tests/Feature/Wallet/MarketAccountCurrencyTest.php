<?php

namespace Tests\Feature\Wallet;

use App\Enums\Wallet\MarketBalanceKind;
use App\Enums\Wallet\MarketBalanceRefusal;
use App\Services\Wallet\CustomerMarketBalances;
use App\Services\Wallet\MarketBalanceChange;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMarketAccountSchema;
use Tests\TestCase;

/**
 * What currency a market account is kept in (GCC-15, E-02). It is the currency the market SELLS in, which only
 * `App\Services\Storefront\StorefrontCurrency` can say: while the storefront currency is switched off every storefront is still selling in
 * the platform's own money, and a storefront whose currency is missing or inactive may not sell at all. In each of those states there is no
 * market currency to open an account in, so the write is refused rather than labelled with the code the country map happens to carry —
 * once stamped, a currency is what every later write is held to.
 */
class MarketAccountCurrencyTest extends TestCase
{
    use BuildsMarketAccountSchema;

    private const CUSTOMER = 1;

    private CustomerMarketBalances $balances;

    /** @return array<string, array{MarketBalanceKind}> */
    public static function balances(): array
    {
        return ['the wallet' => [MarketBalanceKind::Wallet], 'the loyalty points' => [MarketBalanceKind::Loyalty]];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMarketAccountSchema();
        $this->runMarketAccountMigration();
        $this->withMarketAccountsOn();
        $this->seedCustomer(self::CUSTOMER, walletBalance: 250.5, loyaltyPoints: 80);
        $this->balances = app(CustomerMarketBalances::class);
    }

    private function credit(MarketBalanceKind $kind, string $market, float $amount = 100): MarketBalanceChange
    {
        return $this->balances->credit(kind: $kind, customerId: self::CUSTOMER, market: $market, amount: $amount);
    }

    private function assertNothingWasOpened(MarketBalanceKind $kind): void
    {
        $this->assertSame(0, DB::table($kind->accountsTable())->count(), 'not even an empty account is left behind');
    }

    // ---- the currency the market sells in ------------------------------------------------------------------------------------

    #[DataProvider('balances')]
    public function test_an_account_is_stamped_with_the_currency_its_market_sells_in(MarketBalanceKind $kind): void
    {
        $change = $this->credit($kind, market: 'KW');

        $this->assertTrue($change->applied);
        $this->assertSame('KWD', $change->currencyCode);
        $this->assertSame('KWD', DB::table($kind->accountsTable())->where('market', 'KW')->value('currency_code'));
    }

    #[DataProvider('balances')]
    public function test_the_company_the_platform_sells_as_is_recorded_as_the_account_is_opened(MarketBalanceKind $kind): void
    {
        config(['gcc.storefronts.legal.legal_entity' => 'Lavkoo Trading Company']);

        $this->credit($kind, market: 'KW');
        $this->credit($kind, market: 'AE');

        $this->assertSame(
            ['AE' => 'Lavkoo Trading Company', 'KW' => 'Lavkoo Trading Company'],
            DB::table($kind->accountsTable())->orderBy('market')->pluck('legal_entity', 'market')->all(),
            'one platform-wide value, so every market carries the same one',
        );
    }

    #[DataProvider('balances')]
    public function test_an_account_records_no_company_while_none_is_configured(MarketBalanceKind $kind): void
    {
        config(['gcc.storefronts.legal.legal_entity' => null]);

        $this->credit($kind, market: 'KW');

        $this->assertNull(DB::table($kind->accountsTable())->where('market', 'KW')->value('legal_entity'));
    }

    // ---- when the currency cannot be told ------------------------------------------------------------------------------------

    #[DataProvider('balances')]
    public function test_a_credit_is_refused_while_the_storefront_currency_is_switched_off(MarketBalanceKind $kind): void
    {
        config(['gcc.storefronts.currency.enabled' => false]);

        $change = $this->credit($kind, market: 'AE');

        $this->assertFalse($change->applied, 'the storefront is still selling in the platform\'s own money, not in dirhams');
        $this->assertSame(MarketBalanceRefusal::CurrencyUnknown, $change->refusal);
        $this->assertNull($change->currencyCode);
        $this->assertNothingWasOpened($kind);
    }

    #[DataProvider('balances')]
    public function test_a_credit_is_refused_while_the_markets_currency_is_inactive(MarketBalanceKind $kind): void
    {
        DB::table('currencies')->where('code', 'KWD')->update(['status' => 0]);
        Cache::flush();

        $change = $this->credit($kind, market: 'KW');

        $this->assertFalse($change->applied, 'a storefront whose currency is inactive may not sell');
        $this->assertSame(MarketBalanceRefusal::CurrencyUnknown, $change->refusal);
        $this->assertNothingWasOpened($kind);
    }

    #[DataProvider('balances')]
    public function test_a_credit_is_refused_while_the_markets_currency_is_not_in_the_currencies_table(MarketBalanceKind $kind): void
    {
        DB::table('currencies')->where('code', 'OMR')->delete();
        Cache::flush();

        $change = $this->credit($kind, market: 'OM');

        $this->assertFalse($change->applied);
        $this->assertSame(MarketBalanceRefusal::CurrencyUnknown, $change->refusal);
        $this->assertNothingWasOpened($kind);
    }

    #[DataProvider('balances')]
    public function test_a_credit_to_the_default_market_is_refused_while_the_platform_converts_from_a_base_currency(MarketBalanceKind $kind): void
    {
        DB::table('business_settings')->where('type', 'currency_model')->update(['value' => 'multi_currency']);
        Cache::flush();

        $change = $this->credit($kind, market: 'SA');

        $this->assertFalse($change->applied, 'the amount a visitor sees is their own choice of currency, so the balance is in none of them');
        $this->assertSame(MarketBalanceRefusal::CurrencyUnknown, $change->refusal);
        $this->assertNothingWasOpened($kind);
    }

    #[DataProvider('balances')]
    public function test_a_debit_is_refused_too_and_the_one_global_balance_is_untouched(MarketBalanceKind $kind): void
    {
        config(['gcc.storefronts.currency.enabled' => false]);
        $before = DB::table('users')->where('id', self::CUSTOMER)->get()->toArray();

        $change = $this->balances->debit(kind: $kind, customerId: self::CUSTOMER, market: 'AE', amount: 10);

        $this->assertFalse($change->applied);
        $this->assertSame(MarketBalanceRefusal::CurrencyUnknown, $change->refusal);
        $this->assertEquals($before, DB::table('users')->where('id', self::CUSTOMER)->get()->toArray());
    }
}
