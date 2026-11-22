<?php

namespace Tests\Feature\Payments;

use App\Services\Payments\CheckoutCurrency;
use App\Traits\PaymentGatewayTrait;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\TestCase;

/**
 * The currency an order's money is written down in is the customer's, or the platform's default --
 * and never USD because a lookup found nothing (D2-V1).
 *
 * `orders.bring_change_amount_currency` is printed beside the amount on the admin order screen, so it
 * is what an operator reads as the currency the courier collects. It had four writers from four
 * sources, and one of them asked a GATEWAY CAPABILITY MAP what currency a cash payment is in.
 * `cash_on_delivery` has no entry in that map, so the lookup matched nothing and fell through to the
 * map's last line, `return 'USD'`.
 */
class TheOrderCurrencyIsNeverUsdByAccidentTest extends TestCase
{
    use BuildsFulfillmentSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCoreSchema();

        DB::table('currencies')->insert([
            ['name' => 'Saudi Riyal', 'symbol' => 'SAR', 'code' => 'SAR', 'exchange_rate' => 1, 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Kuwaiti Dinar', 'symbol' => 'KWD', 'code' => 'KWD', 'exchange_rate' => 1, 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Bahraini Dinar', 'symbol' => 'BHD', 'code' => 'BHD', 'exchange_rate' => 1, 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->theDefaultCurrencyIs('SAR');
        $this->currencyModelIs('single_currency');
    }

    private function theDefaultCurrencyIs(string $code): void
    {
        $id = DB::table('currencies')->where('code', $code)->value('id');
        DB::table('business_settings')->updateOrInsert(['type' => 'system_default_currency'], ['value' => $id, 'updated_at' => now()]);
        Cache::flush();
    }

    private function currencyModelIs(string $model): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => 'currency_model'], ['value' => $model, 'updated_at' => now()]);
        Cache::flush();
    }

    /** The gateway capability map, as the old writer consulted it. */
    private function gatewayMapAnswerFor(string $method, ?string $shoppingIn): string
    {
        return (new class { use PaymentGatewayTrait; })->getPaymentGatewayCurrencyCode($method, $shoppingIn);
    }

    public function test_the_defect_it_replaces_measured_rather_than_described(): void
    {
        // Still true of the gateway map, which is the point: cash is not a gateway, so asking the map
        // about it can only ever reach the map's last line. Every currency below is active.
        foreach ([null, 'SAR', 'KWD', 'BHD'] as $shoppingIn) {
            $this->assertSame(
                'USD',
                $this->gatewayMapAnswerFor('cash_on_delivery', $shoppingIn),
                'the gateway capability map no longer answers USD for cash, so this characterisation wants rewriting'
            );
        }

        // And USD is not even an active currency here, so the answer was not merely surprising.
        $this->assertNull(DB::table('currencies')->where('code', 'USD')->value('id'));
    }

    public function test_a_cash_order_is_recorded_in_the_currency_the_customer_is_shopping_in(): void
    {
        $this->currencyModelIs('multi_currency');

        foreach (['SAR', 'KWD', 'BHD'] as $shoppingIn) {
            $this->assertSame($shoppingIn, CheckoutCurrency::forOrder($shoppingIn));
            $this->assertNotSame('USD', CheckoutCurrency::forOrder($shoppingIn));
        }
    }

    public function test_with_no_currency_picked_it_is_the_platform_default_and_not_usd(): void
    {
        $this->currencyModelIs('multi_currency');

        foreach ([null, '', '   '] as $nothing) {
            $this->assertSame('SAR', CheckoutCurrency::forOrder($nothing), 'an empty pick fell through to something other than the default');
        }
    }

    public function test_in_single_currency_mode_the_customer_cannot_move_it_off_the_default(): void
    {
        $this->currencyModelIs('single_currency');

        // The customer has no currency to pick in this mode, so a code arriving on the request -- from
        // an older client, or a crafted one -- must not relabel the order's money.
        foreach (['KWD', 'BHD', 'USD'] as $claimed) {
            $this->assertSame('SAR', CheckoutCurrency::forOrder($claimed));
        }
    }

    public function test_the_six_markets_each_keep_their_own_currency(): void
    {
        $this->currencyModelIs('multi_currency');

        foreach (['SAR', 'AED', 'KWD', 'QAR', 'BHD', 'OMR'] as $currency) {
            $this->assertSame($currency, CheckoutCurrency::forOrder($currency), "a {$currency} order was relabelled");
        }
    }

    public function test_a_lower_case_code_is_recorded_as_a_currency_code(): void
    {
        $this->currencyModelIs('multi_currency');

        $this->assertSame('SAR', CheckoutCurrency::forOrder('sar'));
        $this->assertSame('KWD', CheckoutCurrency::forOrder(' kwd '));
    }

    public function test_the_platform_default_is_read_from_the_setting_and_not_assumed(): void
    {
        $this->theDefaultCurrencyIs('KWD');
        $this->currencyModelIs('single_currency');

        $this->assertSame('KWD', CheckoutCurrency::platformDefault());
        $this->assertSame('KWD', CheckoutCurrency::forOrder('SAR'));
    }
}
