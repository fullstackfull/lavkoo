<?php

namespace Tests\Feature\Payments;

use App\Models\PaymentRequest;
use App\Services\Payments\WhatWasPaidMatchesTheOrder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Address\ApiAddressTestCase;

/**
 * D2: the settlement compares the money the gateway took with the orders it built, and says so when they differ.
 *
 * Nothing compared them. The settlement reads a paid payment, builds the orders of the cart as it finds it NOW and marks them
 * paid, so a basket that moved between the payment starting and the callback arriving leaves an order whose total is not the
 * figure the customer was charged -- and both records say `paid`. The figures reproduced when this was recorded are 275 paid
 * against a 375 order, which is the case driven below.
 *
 * It REPORTS and never refuses, which the last test here asserts rather than describes: the order is still built, still paid
 * and still carries its reference. The gateway has taken the money by the time this runs, so refusing would leave the customer
 * paid with nothing to show for it.
 */
class ASettlementReportsWhatDoesNotMatchTest extends ApiAddressTestCase
{
    private const PAYMENT_ROW = '3a71bb10-9c42-4e51-8c0a-61ad39cc7714';

    private const REFERENCE = 'TXN-AMOUNT-CHECK';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPipelineTables();
        $this->useTaxIncludedPrices();
    }

    public function test_a_settlement_whose_orders_add_up_to_what_was_paid_says_nothing(): void
    {
        $this->expectNoCriticalLine();

        $this->settle(paid: 100.0);

        $this->assertSame(100.0, (float) DB::table('orders')->sum('order_amount'), 'the control: the product is 100 and the payment was 100');
    }

    public function test_a_settlement_that_took_two_hundred_and_seventy_five_for_a_three_hundred_and_seventy_five_order_is_reported(): void
    {
        $reported = $this->captureTheCriticalLine();

        $this->settle(paid: 275.0, unitPrice: 375.0);

        $this->assertNotNull($reported(), 'a settled payment that does not match its orders must be reported');
        $this->assertSame(275.0, $reported()['paid']);
        $this->assertSame(375.0, $reported()['orders_total']);
        $this->assertSame('SAR', $reported()['currency']);
        $this->assertSame(self::PAYMENT_ROW, $reported()['payment_id'], 'and it names the payment, so a person can reconcile it against a real record');
    }

    public function test_the_order_is_still_built_paid_and_referenced_when_the_figures_do_not_match(): void
    {
        $this->settle(paid: 275.0, unitPrice: 375.0);

        $order = DB::table('orders')->where('transaction_ref', self::REFERENCE)->first();

        $this->assertNotNull($order, 'the money has moved: a discrepancy must never cost the customer their order');
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(1, DB::table('orders')->count());
    }

    public function test_a_difference_of_one_minor_unit_is_not_reported(): void
    {
        $this->expectNoCriticalLine();

        $this->settle(paid: 99.99);

        $this->assertSame(1, DB::table('orders')->count(), 'the converter rounds to the decimals in force, so one halala either way is its own rounding and not a discrepancy');
    }

    public function test_under_multi_currency_the_sum_is_put_through_the_same_conversion_that_charged_the_gateway(): void
    {
        // updateOrInsert and not insert: this world already seeds a SAR row at a rate of 1, and a second SAR row would not
        // replace it -- `usdToAnotherCurrencyConverter()` asks `Currency::where(code)->first()` with no ordering, so it would
        // have gone on reading the first of the two and this test would have measured nothing.
        DB::table('currencies')->updateOrInsert(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$', 'exchange_rate' => 1, 'status' => 1, 'updated_at' => now()]);
        DB::table('currencies')->updateOrInsert(['code' => 'SAR'], ['name' => 'Saudi Riyal', 'symbol' => 'SAR', 'exchange_rate' => 3.75, 'status' => 1, 'updated_at' => now()]);
        DB::table('business_settings')->updateOrInsert(['type' => 'currency_model'], ['value' => 'multi_currency']);
        DB::table('business_settings')->updateOrInsert(['type' => 'decimal_point_settings'], ['value' => '2']);
        Cache::flush();
        clearWebConfigCacheKeys();
        session()->flush();
        DB::table('orders')->insert(['id' => 501, 'order_amount' => 100, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(
            375.0,
            app(WhatWasPaidMatchesTheOrder::class)->chargedFor(orderIds: [501], currencyCode: 'SAR'),
            'a 100 stored-unit order was charged as 375 riyals, so 375 is the figure to compare with what the gateway took',
        );
    }

    public function test_under_a_single_currency_platform_the_sum_is_compared_without_any_conversion(): void
    {
        DB::table('orders')->insert(['id' => 502, 'order_amount' => 100, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(
            100.0,
            app(WhatWasPaidMatchesTheOrder::class)->chargedFor(orderIds: [502], currencyCode: 'SAR'),
            'the single-currency payment was started with the stored total and never converted, so converting it here would flag every legitimate order',
        );
    }

    public function test_a_settlement_that_built_no_orders_is_not_reported_as_a_mismatch(): void
    {
        $this->expectNoCriticalLine();

        $this->assertSame(
            0.0,
            app(WhatWasPaidMatchesTheOrder::class)->chargedFor(orderIds: [], currencyCode: 'SAR'),
            'nothing built is the OTHER critical line the settlement already writes, and reporting it twice under two names would hide both',
        );
        app(WhatWasPaidMatchesTheOrder::class)->report(paymentId: self::PAYMENT_ROW, paymentAmount: 275.0, currencyCode: 'SAR', orderIds: []);
    }

    /** @return callable(): (array<string, mixed>|null) */
    private function captureTheCriticalLine(): callable
    {
        $captured = null;
        Log::listen(function ($message) use (&$captured): void {
            if ($message->level === 'critical' && str_contains((string) $message->message, 'does not match the orders')) {
                $captured = $message->context;
            }
        });

        // A by-reference closure and NOT an arrow function: `fn () => $captured` captures the variable by VALUE when it is
        // created, which is before the listener has run, so it would answer null however many lines were logged.
        return static function () use (&$captured): ?array {
            return $captured;
        };
    }

    private function expectNoCriticalLine(): void
    {
        Log::listen(function ($message): void {
            if ($message->level === 'critical') {
                $this->fail('nothing should have been reported: ' . $message->message . ' ' . json_encode($message->context));
            }
        });
    }

    private function settle(float $paid, float $unitPrice = 100.0): void
    {
        $customer = $this->makeCustomer();
        $this->putOneProductInTheCart(callerId: $customer->id, stock: 10);
        DB::table('products')->where('id', 1)->update(['unit_price' => $unitPrice]);
        DB::table('carts')->where('product_id', 1)->update(['price' => $unitPrice]);
        $addressId = $this->makeLegacyAddress(customerId: $customer->id)->id;

        DB::table('payment_requests')->insert([
            'id' => self::PAYMENT_ROW, 'payment_method' => 'ssl_commerz', 'transaction_id' => self::REFERENCE,
            'payment_amount' => $paid, 'currency_code' => 'SAR', 'is_paid' => 1, 'payer_id' => (string) $customer->id,
            'created_at' => now(), 'updated_at' => now(),
            'additional_data' => json_encode([
                'payment_mode' => 'web', 'customer_id' => $customer->id, 'is_guest' => 0, 'is_guest_in_order' => 0,
                'address_id' => $addressId, 'billing_address_id' => null, 'order_note' => null, 'coupon_code' => null,
            ]),
        ]);

        digital_payment_place_orders(PaymentRequest::findOrFail(self::PAYMENT_ROW));
    }
}
