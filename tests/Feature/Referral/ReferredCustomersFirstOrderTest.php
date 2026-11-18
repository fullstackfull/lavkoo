<?php

namespace Tests\Feature\Referral;

use App\Events\OrderPlacedEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\KeepsOrderDebugLogClean;

/**
 * The first order of a referred customer tells the customer who referred them. That notification was added to the list of notifications as
 * one event where the code that sends them reads a group of events, so the order was committed and then the request failed: the cart was not
 * emptied, the payment hook reported failure, and a second delivery of the same callback built a second order.
 */
class ReferredCustomersFirstOrderTest extends ApiAddressTestCase
{
    use KeepsOrderDebugLogClean;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPipelineTables();
        $this->useTaxIncludedPrices();
        // The order pipeline's stand-in lacks the columns the notification reads, so the real migration builds the table.
        Schema::dropIfExists('referral_customers');
        (require database_path('migrations/2025_06_03_171327_create_referral_customers_table.php'))->up();
        $this->rememberOrderDebugLog();
    }

    protected function tearDown(): void
    {
        $this->restoreOrderDebugLog();

        parent::tearDown();
    }

    private function referredCustomerPaysThroughTheApp(): int
    {
        $referrer = $this->makeCustomer();
        $payer = $this->makeCustomer();
        DB::table('referral_customers')->insert(['user_id' => $payer->id, 'refer_by' => $referrer->id, 'is_used' => 0, 'ordered_notify' => 0]);
        $this->putOneProductInTheCart($payer->id);

        request()->replace([]);
        digital_payment_success([
            'is_paid' => 1, 'payment_method' => 'ssl_commerz', 'transaction_id' => 'TXN-1',
            'additional_data' => json_encode([
                'customer_id' => $payer->id, 'is_guest' => 0, 'is_guest_in_order' => 0, 'order_note' => null, 'coupon_code' => null,
                'payment_mode' => 'app', 'payment_request_from' => 'app', 'new_customer_id' => 0,
            ]),
        ]);

        return $payer->id;
    }

    public function test_the_first_order_of_a_referred_customer_completes_and_empties_the_cart(): void
    {
        Event::fake([OrderPlacedEvent::class]);

        $payerId = $this->referredCustomerPaysThroughTheApp();

        $this->assertSame(1, DB::table('orders')->where('customer_id', $payerId)->count());
        $this->assertSame(0, DB::table('carts')->where('customer_id', $payerId)->count(), 'the cart was emptied after the order');
        $this->assertSame(1, (int) DB::table('referral_customers')->where('user_id', $payerId)->value('is_used'));
    }

    public function test_the_customer_who_referred_is_told_once_the_first_order_is_placed(): void
    {
        Event::fake([OrderPlacedEvent::class]);

        $this->referredCustomerPaysThroughTheApp();

        Event::assertDispatched(OrderPlacedEvent::class, fn (OrderPlacedEvent $event) => ($event->notification->type ?? null) === 'promoter');
    }
}
