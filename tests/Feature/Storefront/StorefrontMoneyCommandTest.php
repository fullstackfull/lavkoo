<?php

namespace Tests\Feature\Storefront;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsStorefrontMoneyWorld;
use Tests\TestCase;

/**
 * GCC-05, slice 5: gcc:storefront:money gives a storefront that trades in its own currency its minimum order and its free-delivery threshold, in
 * that currency and as typed. It is the only way to write them: the platform's own amount screens type in the platform's money, and an amount
 * typed there for a Kuwaiti storefront would be divided by the exchange rate.
 */
class StorefrontMoneyCommandTest extends TestCase
{
    use BuildsStorefrontMoneyWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontMoneyWorld();
    }

    protected function tearDown(): void
    {
        $this->dropStorefrontMoneyWorld();

        parent::tearDown();
    }

    private function storedRow(string $country): ?array
    {
        $value = DB::table('business_settings')->where('type', 'storefront_money_' . strtolower($country))->value('value');

        return $value === null ? null : json_decode($value, true);
    }

    /** @return list<array<string, mixed>> */
    private function platformRows(): array
    {
        return DB::table('business_settings')->where('type', 'not like', 'storefront_money_%')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }

    public function test_it_gives_a_storefront_its_minimum_in_its_own_currency_with_the_decimals_of_that_currency(): void
    {
        $this->artisan('gcc:storefront:money', ['country' => 'KW', 'setting' => 'minimum_order_amount', 'amount' => '5'])
            ->expectsOutputToContain('KW minimum_order_amount: not set -> 5.000 (KWD).')
            ->assertExitCode(0);

        $this->assertSame(['currency' => 'KWD', 'minimum_order_amount' => '5.000'], $this->storedRow('KW'));
        $this->assertSame('5.000', $this->inStorefront('KW', fn () => getWebConfig('minimum_order_amount')));
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> */
    public static function amountsTypedAndStored(): array
    {
        return [
            'dinar, whole' => ['KW', 'KWD', '5', '5.000'],
            'dinar, one decimal' => ['KW', 'KWD', '12.5', '12.500'],
            'dinar, three decimals' => ['KW', 'KWD', '12.505', '12.505'],
            'rial of Oman' => ['OM', 'OMR', '3.5', '3.500'],
            'dinar of Bahrain' => ['BH', 'BHD', '2', '2.000'],
            'dirham' => ['AE', 'AED', '12.5', '12.50'],
            'Qatari riyal' => ['QA', 'QAR', '40', '40.00'],
            'leading zeros' => ['KW', 'KWD', '007', '7.000'],
            'zero' => ['KW', 'KWD', '0', '0.000'],
        ];
    }

    #[DataProvider('amountsTypedAndStored')]
    public function test_the_amount_is_stored_with_exactly_the_decimals_of_the_currency(string $country, string $currency, string $typed, string $stored): void
    {
        $this->artisan('gcc:storefront:money', ['country' => $country, 'setting' => 'free_delivery_over_amount', 'amount' => $typed])->assertExitCode(0);

        $this->assertSame(['currency' => $currency, 'free_delivery_over_amount' => $stored], $this->storedRow($country));
    }

    public function test_a_second_amount_keeps_the_first_and_a_change_says_what_it_replaced(): void
    {
        $this->artisan('gcc:storefront:money', ['country' => 'KW', 'setting' => 'minimum_order_amount', 'amount' => '5']);
        $this->artisan('gcc:storefront:money', ['country' => 'KW', 'setting' => 'free_delivery_over_amount', 'amount' => '50'])->assertExitCode(0);
        $this->artisan('gcc:storefront:money', ['country' => 'kw', 'setting' => 'minimum_order_amount', 'amount' => '6.5'])
            ->expectsOutputToContain('KW minimum_order_amount: 5.000 -> 6.500 (KWD).')
            ->assertExitCode(0);

        $this->assertSame(['currency' => 'KWD', 'minimum_order_amount' => '6.500', 'free_delivery_over_amount' => '50.000'], $this->storedRow('KW'));
    }

    public function test_a_row_for_another_currency_is_started_over_not_converted(): void
    {
        $this->giveStorefrontAmounts(country: 'KW', currency: 'SAR', amounts: ['minimum_order_amount' => '100', 'free_delivery_over_amount' => '500']);

        $this->artisan('gcc:storefront:money', ['country' => 'KW', 'setting' => 'minimum_order_amount', 'amount' => '5'])
            ->expectsOutputToContain('KW minimum_order_amount: not set -> 5.000 (KWD).')
            ->assertExitCode(0);

        $this->assertSame(['currency' => 'KWD', 'minimum_order_amount' => '5.000'], $this->storedRow('KW'), 'nothing of the Saudi row is carried over');
    }

    public function test_unsetting_removes_the_amount_and_the_row_when_nothing_is_left(): void
    {
        $this->artisan('gcc:storefront:money', ['country' => 'KW', 'setting' => 'minimum_order_amount', 'amount' => '5']);
        $this->artisan('gcc:storefront:money', ['country' => 'KW', 'setting' => 'free_delivery_over_amount', 'amount' => '50']);

        $this->artisan('gcc:storefront:money', ['country' => 'KW', 'setting' => 'minimum_order_amount', '--unset' => true])
            ->expectsOutputToContain('KW minimum_order_amount: 5.000 -> not set (KWD).')
            ->assertExitCode(0);
        $this->assertSame(['currency' => 'KWD', 'free_delivery_over_amount' => '50.000'], $this->storedRow('KW'));

        $this->artisan('gcc:storefront:money', ['country' => 'KW', 'setting' => 'free_delivery_over_amount', '--unset' => true])->assertExitCode(0);
        $this->assertNull($this->storedRow('KW'), 'the row goes with its last amount');

        $this->artisan('gcc:storefront:money', ['country' => 'KW', 'setting' => 'free_delivery_over_amount', '--unset' => true])
            ->expectsOutputToContain('not set -> not set')
            ->assertExitCode(0);
    }

    public function test_the_storefront_reads_what_was_given_at_once_because_the_settings_cache_is_dropped(): void
    {
        $this->assertSame('0', $this->inStorefront('KW', fn () => getWebConfig('minimum_order_amount')));

        $this->artisan('gcc:storefront:money', ['country' => 'KW', 'setting' => 'minimum_order_amount', 'amount' => '5'])
            ->expectsOutputToContain('The storefront reads this value now')
            ->assertExitCode(0);
        $this->assertSame('5.000', $this->inStorefront('KW', fn () => getWebConfig('minimum_order_amount')));

        $this->artisan('gcc:storefront:money', ['country' => 'KW', 'setting' => 'minimum_order_amount', '--unset' => true])->assertExitCode(0);
        $this->assertSame('0', $this->inStorefront('KW', fn () => getWebConfig('minimum_order_amount')));
    }

    public function test_it_works_while_the_switches_are_off_and_says_nothing_reads_the_value_yet(): void
    {
        config(['gcc.storefronts.currency.enabled' => false]);

        $this->artisan('gcc:storefront:money', ['country' => 'KW', 'setting' => 'minimum_order_amount', 'amount' => '5'])
            ->expectsOutputToContain('nothing reads this value yet')
            ->assertExitCode(0);

        $this->assertSame('100', $this->inStorefront('KW', fn () => getWebConfig('minimum_order_amount')), 'the platform amount stands while the switch is off');
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function refusals(): array
    {
        return [
            'the default storefront' => [['country' => 'SA', 'setting' => 'minimum_order_amount', 'amount' => '5'], "SA is the default storefront: its amounts are the platform's, set in the admin panel."],
            'a country that is not a storefront' => [['country' => 'XX', 'setting' => 'minimum_order_amount', 'amount' => '5'], 'XX is not a configured storefront.'],
            'the wallet limit' => [['country' => 'KW', 'setting' => 'maximum_add_fund_amount', 'amount' => '5'], 'not set per storefront'],
            'the other wallet limit' => [['country' => 'KW', 'setting' => 'minimum_add_fund_amount', 'amount' => '5'], 'not set per storefront'],
            'the seller threshold' => [['country' => 'KW', 'setting' => 'free_delivery_over_amount_seller', 'amount' => '5'], 'not set per storefront'],
            'a setting that is not an amount' => [['country' => 'KW', 'setting' => 'currency_model', 'amount' => '5'], 'is not an amount a storefront can be given'],
            'no amount' => [['country' => 'KW', 'setting' => 'minimum_order_amount'], 'Give the amount in KWD, or --unset'],
            'an amount and --unset' => [['country' => 'KW', 'setting' => 'minimum_order_amount', 'amount' => '5', '--unset' => true], 'not both'],
            'a negative amount' => [['country' => 'KW', 'setting' => 'minimum_order_amount', 'amount' => '-5'], 'plain number that is not negative'],
            'text' => [['country' => 'KW', 'setting' => 'minimum_order_amount', 'amount' => 'five'], 'plain number that is not negative'],
            'a comma' => [['country' => 'KW', 'setting' => 'minimum_order_amount', 'amount' => '5,000'], 'plain number that is not negative'],
            'an exponent' => [['country' => 'KW', 'setting' => 'minimum_order_amount', 'amount' => '1e3'], 'plain number that is not negative'],
            'a fourth decimal of a dinar' => [['country' => 'KW', 'setting' => 'minimum_order_amount', 'amount' => '5.0005'], 'at most 3 decimals (KWD)'],
            'a third decimal of a dirham' => [['country' => 'AE', 'setting' => 'minimum_order_amount', 'amount' => '5.005'], 'at most 2 decimals (AED)'],
        ];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('refusals')]
    public function test_it_refuses_and_writes_nothing(array $arguments, string $said): void
    {
        $platformBefore = $this->platformRows();
        $rowsBefore = DB::table('business_settings')->count();

        $this->artisan('gcc:storefront:money', $arguments)->expectsOutputToContain($said)->assertExitCode(1);

        $this->assertSame($rowsBefore, DB::table('business_settings')->count());
        $this->assertSame($platformBefore, $this->platformRows());
    }

    public function test_it_never_writes_a_platform_row(): void
    {
        $platformBefore = $this->platformRows();

        $this->artisan('gcc:storefront:money', ['country' => 'KW', 'setting' => 'minimum_order_amount', 'amount' => '5'])->assertExitCode(0);
        $this->artisan('gcc:storefront:money', ['country' => 'AE', 'setting' => 'free_delivery_over_amount', 'amount' => '40'])->assertExitCode(0);

        $this->assertSame($platformBefore, $this->platformRows());
        $this->assertSame(2, DB::table('business_settings')->where('type', 'like', 'storefront_money_%')->count());
    }
}
