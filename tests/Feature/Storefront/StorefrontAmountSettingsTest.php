<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\StorefrontCurrency;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsStorefrontMoneyWorld;
use Tests\TestCase;

/**
 * GCC-05, slice 5: the amounts of money that the cart, the checkout and the wallet compare with a customer's amounts (the minimum order, the
 * free-delivery thresholds, the limits of a wallet top-up) are rows of the platform's settings, typed in the platform's money. A Kuwaiti customer
 * compared with them would meet a minimum of a hundred dinars where the shop meant a hundred riyals. A storefront that trades in its own currency
 * therefore reads the amount an operator gave it, in its own currency and as given, or the neutral value; it never reads the platform's row.
 */
class StorefrontAmountSettingsTest extends TestCase
{
    use BuildsStorefrontMoneyWorld;

    private const SETTINGS = ['minimum_order_amount', 'free_delivery_over_amount', 'free_delivery_over_amount_seller', 'minimum_add_fund_amount', 'maximum_add_fund_amount'];

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

    /** @return array<string, mixed> every amount setting as getWebConfig answers it */
    private function amountsRead(): array
    {
        return array_combine(self::SETTINGS, array_map(static fn (string $setting): mixed => getWebConfig($setting), self::SETTINGS));
    }

    public function test_the_default_storefront_reads_the_platform_amounts_while_the_switch_is_on(): void
    {
        $platform = $this->amountsRead();

        $this->giveStorefrontAmounts(country: 'KW', currency: 'KWD', amounts: ['minimum_order_amount' => '5.000']);

        $this->assertSame(['100', '500', '300', '10', '1000'], array_values($platform));
        $this->assertSame($platform, $this->inStorefront('SA', fn (): array => $this->amountsRead()));
    }

    public function test_every_storefront_reads_the_platform_amounts_while_the_switch_is_off(): void
    {
        config(['gcc.storefronts.currency.enabled' => false]);
        $this->giveStorefrontAmounts(country: 'KW', currency: 'KWD', amounts: ['minimum_order_amount' => '5.000']);
        $platform = $this->amountsRead();

        foreach (['SA', 'AE', 'KW', 'QA', 'BH', 'OM'] as $country) {
            $this->assertSame($platform, $this->inStorefront($country, fn (): array => $this->amountsRead()), $country);
        }
    }

    public function test_the_switch_does_nothing_while_storefronts_are_off(): void
    {
        config(['gcc.storefronts.enabled' => false]);
        $this->giveStorefrontAmounts(country: 'KW', currency: 'KWD', amounts: ['minimum_order_amount' => '5.000']);

        $this->assertSame(['100', '500', '300', '10', '1000'], array_values($this->amountsRead()));
        $this->assertSame(['100', '500', '300', '10', '1000'], array_values($this->inStorefront('KW', fn (): array => $this->amountsRead())));
    }

    public function test_a_process_that_serves_no_storefront_reads_the_platform_amounts_whatever_the_storefronts_were_given(): void
    {
        foreach (self::storefrontsWithTheirOwnCurrency() as [$country, $currency]) {
            $this->giveStorefrontAmounts(country: $country, currency: $currency, amounts: ['minimum_order_amount' => '5', 'free_delivery_over_amount' => '50']);
        }

        $this->assertSame(['100', '500', '300', '10', '1000'], array_values($this->amountsRead()));
    }

    #[DataProvider('storefrontsWithTheirOwnCurrency')]
    public function test_a_storefront_reads_the_amounts_it_was_given_as_they_were_given(string $country, string $currency): void
    {
        $this->giveStorefrontAmounts(country: $country, currency: $currency, amounts: ['minimum_order_amount' => '5.005', 'free_delivery_over_amount' => '50.500']);

        $read = $this->inStorefront($country, fn (): array => $this->amountsRead());

        $this->assertSame('5.005', $read['minimum_order_amount'], 'no rate, no rounding');
        $this->assertSame('50.500', $read['free_delivery_over_amount']);
    }

    #[DataProvider('storefrontsWithTheirOwnCurrency')]
    public function test_the_other_three_amounts_are_neutral_on_a_storefront_with_its_own_currency(string $country, string $currency): void
    {
        $this->giveStorefrontAmounts(country: $country, currency: $currency, amounts: ['minimum_order_amount' => '5', 'free_delivery_over_amount' => '50']);

        $read = $this->inStorefront($country, fn (): array => $this->amountsRead());

        $this->assertSame(
            ['0', '0', '0'],
            [$read['free_delivery_over_amount_seller'], $read['minimum_add_fund_amount'], $read['maximum_add_fund_amount']],
            'a market given no top-up limits of its own still reads zero, which refuses every top-up: that is the closure, unchanged',
        );
    }

    /**
     * D2-V2: a market can now be GIVEN its own top-up limits, in its own currency, which is what E-01's wallet per market made meaningful.
     * Before that they answered a flat zero everywhere but the default market -- a closure rather than a limit, because there was no wallet
     * to limit. A market that is given none still answers zero, which the test above holds.
     */
    #[DataProvider('storefrontsWithTheirOwnCurrency')]
    public function test_a_storefront_can_be_given_its_own_top_up_limits(string $country, string $currency): void
    {
        $this->giveStorefrontAmounts(country: $country, currency: $currency, amounts: ['minimum_add_fund_amount' => '2', 'maximum_add_fund_amount' => '500']);

        $read = $this->inStorefront($country, fn (): array => $this->amountsRead());

        $this->assertSame(['2', '500'], [$read['minimum_add_fund_amount'], $read['maximum_add_fund_amount']]);
        $this->assertSame('0', $read['free_delivery_over_amount_seller'], "the seller threshold is still the Saudi sellers' and is not a market's to set");
    }

    /** And the limits of one market reach no other: each reads its own, or zero. */
    public function test_one_markets_top_up_limits_reach_no_other_market(): void
    {
        $this->giveStorefrontAmounts(country: 'KW', currency: 'KWD', amounts: ['maximum_add_fund_amount' => '500']);

        $this->assertSame('500', $this->inStorefront('KW', fn (): array => $this->amountsRead())['maximum_add_fund_amount']);
        $this->assertSame('0', $this->inStorefront('AE', fn (): array => $this->amountsRead())['maximum_add_fund_amount']);
    }

    #[DataProvider('storefrontsWithTheirOwnCurrency')]
    public function test_a_storefront_that_was_given_nothing_reads_the_neutral_value_never_the_platform_amount(string $country): void
    {
        $read = $this->inStorefront($country, fn (): array => $this->amountsRead());

        $this->assertSame(array_fill_keys(self::SETTINGS, StorefrontCurrency::NEUTRAL_AMOUNT), $read);
    }

    public function test_a_row_tagged_with_another_currency_is_ignored_not_converted(): void
    {
        $this->giveStorefrontAmounts(country: 'KW', currency: 'SAR', amounts: ['minimum_order_amount' => '100']);

        $this->assertSame('0', $this->inStorefront('KW', fn () => getWebConfig('minimum_order_amount')));

        $this->giveStorefrontAmounts(country: 'KW', currency: 'kwd', amounts: ['minimum_order_amount' => '5.000']);

        $this->assertSame('5.000', $this->inStorefront('KW', fn () => getWebConfig('minimum_order_amount')), 'the tag is read without regard to case');
    }

    /** @return array<string, array{0: mixed}> */
    public static function amountsThatReadAsNothing(): array
    {
        return [
            'negative' => ['-5'],
            'text' => ['five'],
            'an exponent' => ['1e3'],
            'a comma' => ['5,000'],
            'empty' => [''],
            'a list' => [[5]],
            'null' => [null],
            'a very large float' => [1.0E+25],
        ];
    }

    #[DataProvider('amountsThatReadAsNothing')]
    public function test_an_amount_that_is_not_a_plain_number_that_is_not_negative_reads_as_nothing(mixed $amount): void
    {
        $this->giveStorefrontAmounts(country: 'KW', currency: 'KWD', amounts: ['minimum_order_amount' => $amount, 'free_delivery_over_amount' => '50.000']);

        $read = $this->inStorefront('KW', fn (): array => $this->amountsRead());

        $this->assertSame('0', $read['minimum_order_amount']);
        $this->assertSame('50.000', $read['free_delivery_over_amount'], 'the other amount of the row is unaffected');
    }

    public function test_a_row_that_is_not_a_json_object_reads_as_nothing(): void
    {
        DB::table('business_settings')->insert(['type' => 'storefront_money_kw', 'value' => '5.000']);
        clearWebConfigCacheKeys();

        $this->assertSame('0', $this->inStorefront('KW', fn () => getWebConfig('minimum_order_amount')));

        DB::table('business_settings')->where('type', 'storefront_money_kw')->update(['value' => '{broken']);
        clearWebConfigCacheKeys();

        $this->assertSame('0', $this->inStorefront('KW', fn () => getWebConfig('minimum_order_amount')));
    }

    public function test_a_number_in_the_row_is_read_as_the_text_of_the_number(): void
    {
        $this->giveStorefrontAmounts(country: 'KW', currency: 'KWD', amounts: ['minimum_order_amount' => 5, 'free_delivery_over_amount' => 12.5]);

        $read = $this->inStorefront('KW', fn (): array => $this->amountsRead());

        $this->assertSame(['5', '12.5'], [$read['minimum_order_amount'], $read['free_delivery_over_amount']]);
    }

    public function test_a_storefront_never_reads_the_row_of_another(): void
    {
        $this->giveStorefrontAmounts(country: 'KW', currency: 'KWD', amounts: ['minimum_order_amount' => '5.000']);
        $this->giveStorefrontAmounts(country: 'AE', currency: 'AED', amounts: ['minimum_order_amount' => '40.00']);

        $this->assertSame('5.000', $this->inStorefront('KW', fn () => getWebConfig('minimum_order_amount')));
        $this->assertSame('40.00', $this->inStorefront('AE', fn () => getWebConfig('minimum_order_amount')));
        $this->assertSame('0', $this->inStorefront('OM', fn () => getWebConfig('minimum_order_amount')));
    }

    public function test_reading_inside_a_storefront_leaves_the_platform_amounts_and_their_cache_as_they_were(): void
    {
        $this->giveStorefrontAmounts(country: 'KW', currency: 'KWD', amounts: ['minimum_order_amount' => '5.000']);
        $before = $this->amountsRead();

        $this->inStorefront('KW', fn (): array => $this->amountsRead());

        $this->assertSame($before, $this->amountsRead());
        $this->assertSame('100', getWebConfig('minimum_order_amount'));
    }

    public function test_a_storefront_without_its_currency_keeps_the_platform_amounts_and_may_not_sell(): void
    {
        DB::table('currencies')->where('code', 'KWD')->delete();
        Cache::forget('active_currency_list');
        $this->giveStorefrontAmounts(country: 'KW', currency: 'KWD', amounts: ['minimum_order_amount' => '5.000']);

        $this->assertSame('100', $this->inStorefront('KW', fn () => getWebConfig('minimum_order_amount')));
    }

    public function test_the_name_of_the_row_is_not_a_setting_the_platform_reads(): void
    {
        $name = StorefrontCurrency::amountRowName('KW');

        $this->assertSame('storefront_money_kw', $name);
        $this->assertNotContains($name, StorefrontCurrency::OVERRIDDEN_SETTINGS, 'a row that asked for itself would never end');
        foreach (['wallet_', 'loyalty_point_', 'ref_earning_'] as $prefix) {
            $this->assertFalse(str_starts_with($name, $prefix), "the customer settings screen lists the settings that start with {$prefix}");
        }
    }

    public function test_the_two_amounts_an_operator_may_give_are_amounts_the_platform_holds(): void
    {
        $this->assertSame([], array_diff(StorefrontCurrency::STOREFRONT_AMOUNTS, StorefrontCurrency::AMOUNT_SETTINGS));
        $this->assertSame([], array_diff(StorefrontCurrency::AMOUNT_SETTINGS, StorefrontCurrency::OVERRIDDEN_SETTINGS));
    }
}
