<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\HoldReason;
use App\Enums\Fulfillment\PaymentMode;
use App\Enums\Fulfillment\ProviderCode;
use App\Jobs\Fulfillment\CancelProviderFulfillment;
use App\Jobs\Fulfillment\DispatchProviderFulfillment;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentItem;
use App\Services\Fulfillment\CustomerFulfillmentPresenter;
use App\Services\Fulfillment\FulfillmentAggregator;
use App\Services\Fulfillment\FulfillmentCancellation;
use App\Services\Fulfillment\FulfillmentRecovery;
use App\Services\Fulfillment\FulfillmentRouter;
use App\Services\Fulfillment\OrderItemOwnershipSnapshotter;
use App\Services\Fulfillment\ShipmentTracker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * The money and routing rules, exercised the way checkout produces them.
 *
 * Every line goes through the REAL ownership snapshotter with the arguments OrderManager passes it
 * ((price - discount) x qty as the net amount, the line tax, the tax model), and the order_details row is
 * written the way checkout writes it, so allocation sees what production would. The order total is built the
 * platform's way: lines (+ tax when exclusive) + shipping - coupon. Cents matter: every assertion that two
 * parts add up to a whole is exact.
 */
class OrderScenarioMatrixTest extends FulfillmentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([1 => ProviderCode::TorodPoint, 2 => ProviderCode::M5azn, 3 => ProviderCode::TorodPoint, 4 => null, 5 => ProviderCode::M5azn] as $id => $provider) {
            $this->makeProduct($id, $provider);
        }
    }

    /**
     * @param array<int, array{product: int, qty: int, price: float, discount?: float, tax?: float}> $lines
     */
    private function placeOrder(int $orderId, array $lines, string $taxModel = 'include', float $shipping = 0.0, float $coupon = 0.0, bool $cod = false, float $paid = 0.0): void
    {
        $lineTotals = 0.0;
        foreach ($lines as $line) {
            $discountPerUnit = $line['discount'] ?? 0.0;
            $tax = $line['tax'] ?? 0.0;
            $lineTotals += ($line['price'] - $discountPerUnit) * $line['qty'] + ($taxModel === 'exclude' ? $tax : 0.0);
        }

        $this->makeOrder($orderId, [
            'order_amount' => round($lineTotals + $shipping - $coupon, 2),
            'shipping_cost' => $shipping,
            'paid_amount' => $paid,
            'payment_method' => $cod ? 'cash_on_delivery' : 'ssl_commerz',
            'payment_status' => $cod ? 'unpaid' : 'paid',
        ]);

        foreach ($lines as $line) {
            $product = (array) DB::table('products')->find($line['product']);
            $discountPerUnit = $line['discount'] ?? 0.0;
            $tax = $line['tax'] ?? 0.0;
            $detailId = DB::table('order_details')->insertGetId([
                'order_id' => $orderId, 'product_id' => $line['product'], 'qty' => $line['qty'], 'price' => $line['price'],
                'discount' => $discountPerUnit * $line['qty'], 'tax' => $tax, 'tax_model' => $taxModel,
                'product_details' => json_encode($product), 'variant' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);

            app(OrderItemOwnershipSnapshotter::class)->capture(
                orderId: $orderId, orderDetailId: $detailId, product: $product, variant: null, quantity: $line['qty'],
                unitPrice: (float) $line['price'], netAmount: ($line['price'] - $discountPerUnit) * $line['qty'], lineTax: (float) $tax, taxModel: $taxModel,
            );
        }
    }

    private function route(int $orderId): array
    {
        app(FulfillmentRouter::class)->route($orderId);

        return ProviderFulfillment::where('order_id', $orderId)->with('items')->orderBy('provider')->get()->keyBy('provider')->all();
    }

    private function sum(array $fulfillments, string $column): float
    {
        return round(array_sum(array_map(static fn (ProviderFulfillment $fulfillment): float => (float) $fulfillment->{$column}, $fulfillments)), 2);
    }

    // ---- single provider ---------------------------------------------------------------------------------

    public function test_a_prepaid_single_provider_order_is_one_shipment_carrying_the_whole_total(): void
    {
        $this->placeOrder(100, [['product' => 1, 'qty' => 2, 'price' => 40.0]], shipping: 15.0);

        $shipments = $this->route(100);

        $this->assertSame(['torod_point'], array_keys($shipments));
        $torod = $shipments['torod_point'];
        $this->assertSame(FulfillmentStatus::Pending, $torod->status);
        $this->assertSame(PaymentMode::Prepaid, $torod->payment_mode);
        $this->assertEquals(0.0, (float) $torod->cod_amount);
        $this->assertEquals(80.0, (float) $torod->items_total);
        $this->assertEquals(95.0, (float) $torod->total_amount, 'items 80 + shipping 15 = what the customer paid');
        $this->assertEquals(15.0, (float) $torod->shipping_allocation);
        Queue::assertPushed(DispatchProviderFulfillment::class, 1);
    }

    public function test_cash_on_delivery_for_one_provider_asks_that_provider_for_everything_still_owed(): void
    {
        $this->placeOrder(100, [['product' => 1, 'qty' => 1, 'price' => 100.0]], shipping: 20.0, cod: true);

        $torod = $this->route(100)['torod_point'];

        $this->assertSame(PaymentMode::CashOnDelivery, $torod->payment_mode);
        $this->assertEquals(120.0, (float) $torod->cod_amount);
    }

    public function test_a_part_paid_cod_order_collects_only_the_balance(): void
    {
        $this->placeOrder(100, [['product' => 1, 'qty' => 1, 'price' => 100.0]], shipping: 20.0, cod: true, paid: 50.0);

        $this->assertEquals(70.0, (float) $this->route(100)['torod_point']->cod_amount, 'wallet / loyalty already paid 50');
    }

    public function test_a_fully_paid_order_that_was_placed_as_cod_collects_nothing(): void
    {
        $this->placeOrder(100, [['product' => 1, 'qty' => 1, 'price' => 100.0]], cod: true, paid: 100.0);

        $torod = $this->route(100)['torod_point'];

        $this->assertSame(PaymentMode::Prepaid, $torod->payment_mode);
        $this->assertEquals(0.0, (float) $torod->cod_amount);
    }

    public function test_vat_inclusive_pricing_does_not_add_tax_again(): void
    {
        $this->placeOrder(100, [['product' => 1, 'qty' => 2, 'price' => 57.5, 'tax' => 15.0]], taxModel: 'include');

        $torod = $this->route(100)['torod_point'];

        $this->assertEquals(115.0, (float) $torod->items_total, 'the shelf price already contains the 15% VAT');
        $this->assertEquals(115.0, (float) $torod->total_amount);
        $this->assertEquals(15.0, (float) $torod->items->first()->line_tax, 'the VAT is still recorded on the line');
    }

    public function test_vat_exclusive_pricing_adds_the_line_tax_to_what_the_provider_is_told(): void
    {
        $this->placeOrder(100, [['product' => 1, 'qty' => 2, 'price' => 50.0, 'tax' => 15.0]], taxModel: 'exclude', cod: true);

        $torod = $this->route(100)['torod_point'];

        $this->assertEquals(115.0, (float) $torod->items_total, '2 x 50 + 15 tax');
        $this->assertEquals(115.0, (float) $torod->cod_amount, 'cash collected equals what the customer was charged, tax included');
    }

    public function test_product_discounts_and_a_coupon_flow_through_to_the_amounts(): void
    {
        $this->placeOrder(100, [['product' => 1, 'qty' => 2, 'price' => 50.0, 'discount' => 5.0]], shipping: 12.0, coupon: 10.0, cod: true);

        $torod = $this->route(100)['torod_point'];

        $this->assertEquals(90.0, (float) $torod->items_total, '(50 - 5) x 2');
        $this->assertEquals(92.0, (float) $torod->total_amount, '90 + 12 shipping - 10 coupon');
        $this->assertEquals(92.0, (float) $torod->cod_amount);
        $this->assertEquals(2.0, (float) $torod->shipping_allocation, 'the non-line remainder: +12 shipping -10 coupon');
    }

    // ---- mixed providers ---------------------------------------------------------------------------------

    public function test_a_mixed_prepaid_order_splits_into_one_shipment_per_provider_with_nothing_crossing_over(): void
    {
        $this->placeOrder(100, [
            ['product' => 1, 'qty' => 1, 'price' => 60.0],
            ['product' => 2, 'qty' => 2, 'price' => 20.0],
            ['product' => 3, 'qty' => 1, 'price' => 10.0],
        ], shipping: 14.0);

        $shipments = $this->route(100);

        $this->assertSame(['m5azn', 'torod_point'], array_keys($shipments));
        $this->assertSame([2], $shipments['m5azn']->items->pluck('product_id')->all());
        $this->assertSame([1, 3], $shipments['torod_point']->items->pluck('product_id')->sort()->values()->all());
        foreach ($shipments as $provider => $shipment) {
            $this->assertSame([$provider], $shipment->items->pluck('provider')->unique()->values()->all(), 'a shipment holds only its own provider\'s lines');
        }
        $this->assertSame(124.0, $this->sum($shipments, 'total_amount'), 'the shares add up to the order total: 110 of lines + 14 shipping');
        $this->assertSame(110.0, $this->sum($shipments, 'items_total'));
        $this->assertSame(['100-MZ1', '100-TP1'], collect($shipments)->pluck('reference')->sort()->values()->all());
    }

    public function test_indivisible_cents_never_make_the_shares_drift_from_the_total(): void
    {
        $this->placeOrder(100, [
            ['product' => 1, 'qty' => 1, 'price' => 33.33],
            ['product' => 2, 'qty' => 1, 'price' => 33.33],
        ], shipping: 0.01);

        $shipments = $this->route(100);

        $this->assertSame(66.67, $this->sum($shipments, 'total_amount'), '33.33 + 33.33 + 0.01 shipping');
    }

    public function test_a_mixed_cod_order_is_held_not_split_unless_mixed_cod_is_enabled_and_verified(): void
    {
        $this->placeOrder(100, [['product' => 1, 'qty' => 1, 'price' => 60.0], ['product' => 2, 'qty' => 1, 'price' => 40.0]], cod: true);

        $shipments = $this->route(100);

        foreach ($shipments as $shipment) {
            $this->assertSame(FulfillmentStatus::Held, $shipment->status);
            $this->assertSame(HoldReason::MIXED_COD_BLOCKED, $shipment->hold_reason);
            $this->assertEquals(0.0, (float) $shipment->cod_amount, 'neither provider is asked for cash');
        }
        Queue::assertNothingPushed();
    }

    public function test_when_mixed_cod_is_allowed_each_provider_collects_its_share_and_the_shares_sum_exactly(): void
    {
        config(['fulfillment.cod.allow_mixed' => true]);
        foreach ([ProviderCode::TorodPoint, ProviderCode::M5azn] as $provider) {
            $this->enableProvider($provider, ['cod_verified' => true]);
        }
        $this->placeOrder(100, [['product' => 1, 'qty' => 1, 'price' => 33.34], ['product' => 2, 'qty' => 1, 'price' => 66.67]], shipping: 10.0, cod: true);

        $shipments = $this->route(100);

        $this->assertSame(110.01, $this->sum($shipments, 'cod_amount'), 'exactly what the customer owes - never two full amounts');
        $this->assertLessThan(110.01, (float) $shipments['torod_point']->cod_amount);
        $this->assertLessThan(110.01, (float) $shipments['m5azn']->cod_amount);
        $this->assertSame(110.01, $this->sum($shipments, 'total_amount'));
    }

    public function test_one_unverified_provider_keeps_mixed_cod_held_even_when_it_is_enabled_globally(): void
    {
        config(['fulfillment.cod.allow_mixed' => true]);
        $this->enableProvider(ProviderCode::TorodPoint, ['cod_verified' => true]);
        $this->enableProvider(ProviderCode::M5azn, ['cod_verified' => false]);
        $this->placeOrder(100, [['product' => 1, 'qty' => 1, 'price' => 60.0], ['product' => 2, 'qty' => 1, 'price' => 40.0]], cod: true);

        foreach ($this->route(100) as $shipment) {
            $this->assertSame(HoldReason::MIXED_COD_BLOCKED, $shipment->hold_reason);
        }
    }

    // ---- provider + in-house ----------------------------------------------------------------------------

    public function test_a_provider_shipment_is_worth_its_lines_plus_its_share_of_the_extras_never_the_whole_order(): void
    {
        $this->placeOrder(100, [['product' => 4, 'qty' => 1, 'price' => 60.0], ['product' => 1, 'qty' => 1, 'price' => 40.0]], shipping: 10.0);

        $torod = $this->route(100)['torod_point'];

        $this->assertEquals(40.0, (float) $torod->items_total);
        $this->assertEquals(44.0, (float) $torod->total_amount, '40 + 40% of the 10 shipping - not the 110 whole order');
        $this->assertLessThan(110.0, (float) $torod->total_amount);
    }

    public function test_cash_on_delivery_is_never_split_between_a_provider_and_in_house_delivery(): void
    {
        $this->placeOrder(100, [['product' => 4, 'qty' => 1, 'price' => 60.0], ['product' => 1, 'qty' => 1, 'price' => 40.0]], cod: true);
        config(['fulfillment.cod.allow_mixed' => true]);
        $this->enableProvider(ProviderCode::TorodPoint, ['cod_verified' => true]);

        $torod = $this->route(100)['torod_point'];

        $this->assertSame(HoldReason::MIXED_COD_BLOCKED, $torod->hold_reason, 'the in-house flow books the whole amount as the delivery man\'s cash');
    }

    // ---- idempotency ------------------------------------------------------------------------------------

    public function test_routing_again_and_again_never_creates_a_second_shipment(): void
    {
        $this->placeOrder(100, [['product' => 1, 'qty' => 1, 'price' => 60.0], ['product' => 2, 'qty' => 1, 'price' => 40.0]]);

        for ($run = 0; $run < 4; $run++) {
            app(FulfillmentRouter::class)->route(100);
        }

        $this->assertSame(2, ProviderFulfillment::where('order_id', 100)->count());
        $this->assertSame(2, ProviderFulfillmentItem::where('order_id', 100)->where('status', ProviderFulfillmentItem::STATUS_ROUTED)->count());
        Queue::assertPushed(DispatchProviderFulfillment::class, 2);
    }

    public function test_a_concurrent_router_that_loses_the_race_creates_nothing_and_does_not_fail(): void
    {
        $this->placeOrder(100, [['product' => 1, 'qty' => 1, 'price' => 60.0]]);
        $item = ProviderFulfillmentItem::where('order_id', 100)->firstOrFail();
        // The winner's row, written between this router's read and its insert.
        ProviderFulfillment::create([
            'reference' => '100-TP1', 'order_id' => 100, 'provider' => 'torod_point', 'generation' => 1,
            'idempotency_key' => hash('sha256', implode('|', ['v1', 100, 'torod_point', 1, (string) $item->id])), 'status' => FulfillmentStatus::Pending,
        ]);

        $created = app(FulfillmentRouter::class)->route(100);

        $this->assertSame([], $created);
        $this->assertSame(1, ProviderFulfillment::where('order_id', 100)->count());
    }

    public function test_the_same_line_can_never_belong_to_two_shipments(): void
    {
        $this->placeOrder(100, [['product' => 1, 'qty' => 1, 'price' => 60.0]]);
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        ProviderFulfillmentItem::create(ProviderFulfillmentItem::firstOrFail()->only(['order_id', 'order_detail_id', 'product_id', 'provider', 'name', 'quantity']));
    }

    // ---- cancellation and refunds ------------------------------------------------------------------------

    public function test_cancelling_the_order_before_dispatch_cancels_each_shipment_locally_and_sends_nothing(): void
    {
        $this->placeOrder(100, [['product' => 1, 'qty' => 1, 'price' => 60.0], ['product' => 2, 'qty' => 1, 'price' => 40.0]]);
        $this->route(100);
        Queue::fake();
        DB::table('orders')->where('id', 100)->update(['order_status' => 'canceled']);

        $queued = app(FulfillmentRecovery::class)->cancelForClosedOrders();
        Queue::assertPushed(CancelProviderFulfillment::class, 2);
        foreach (ProviderFulfillment::where('order_id', 100)->pluck('id') as $id) {
            $this->assertSame('cancelled_locally', app(FulfillmentCancellation::class)->cancel((int) $id));
        }

        $this->assertSame(2, $queued);
        $this->assertSame([FulfillmentStatus::Cancelled, FulfillmentStatus::Cancelled], ProviderFulfillment::where('order_id', 100)->get()->pluck('status')->all());
        $this->assertSame(FulfillmentAggregator::CANCELLED, app(FulfillmentAggregator::class)->summarize(100)->status);
        $this->assertSame([], array_merge($this->m5azn->calls, $this->torod->calls), 'nothing was ever sent to, or cancelled at, a provider');
    }

    public function test_an_order_cancelled_before_it_was_ever_routed_never_produces_a_shipment(): void
    {
        $this->placeOrder(100, [['product' => 1, 'qty' => 1, 'price' => 60.0]]);
        DB::table('orders')->where('id', 100)->update(['order_status' => 'canceled']);

        $this->assertSame([], app(FulfillmentRouter::class)->route(100));
        app(FulfillmentRecovery::class)->closeUnroutedItemsOfClosedOrders();

        $this->assertSame(0, ProviderFulfillment::count());
        $this->assertSame(ProviderFulfillmentItem::STATUS_CANCELLED, ProviderFulfillmentItem::firstOrFail()->status);
    }

    public function test_cancelling_one_providers_shipment_leaves_the_other_running_and_the_order_open(): void
    {
        $this->placeOrder(100, [['product' => 1, 'qty' => 1, 'price' => 60.0], ['product' => 2, 'qty' => 1, 'price' => 40.0]]);
        $shipments = $this->route(100);
        $shipments['m5azn']->forceFill(['status' => FulfillmentStatus::Cancelled])->save();
        $shipments['torod_point']->forceFill(['status' => FulfillmentStatus::InTransit])->save();

        $summary = app(FulfillmentAggregator::class)->summarize(100);

        $this->assertSame(FulfillmentAggregator::SHIPPED, $summary->status, 'the cancelled part is no longer required');
        $this->assertNotNull($summary->deliveryBlockedReason, 'but the order is not delivered until the remaining parcel is');
    }

    public function test_a_refund_or_later_price_change_cannot_rewrite_what_was_sold_and_sent(): void
    {
        $this->placeOrder(100, [['product' => 1, 'qty' => 2, 'price' => 40.0]]);
        $item = ProviderFulfillmentItem::firstOrFail();

        DB::table('products')->where('id', 1)->update(['unit_price' => 999, 'fulfillment_provider' => null]);

        $fresh = $item->fresh();
        $this->assertEquals(80.0, (float) $fresh->line_total);
        $this->assertSame('torod_point', $fresh->provider, 'moving the product later does not move an order already placed');
        $this->expectException(\LogicException::class);
        $fresh->forceFill(['provider' => 'm5azn'])->save();
    }

    // ---- customer tracking ----------------------------------------------------------------------------------

    public function test_the_customer_sees_one_order_with_a_shipment_per_provider_and_delivered_only_when_all_are(): void
    {
        $this->placeOrder(100, [['product' => 1, 'qty' => 1, 'price' => 60.0], ['product' => 2, 'qty' => 1, 'price' => 40.0]]);
        $shipments = $this->route(100);
        $tracker = app(ShipmentTracker::class);
        $presenter = app(CustomerFulfillmentPresenter::class);

        $shipments['torod_point']->forceFill(['status' => FulfillmentStatus::Delivered, 'delivered_at' => now()])->save();
        $shipments['m5azn']->forceFill(['status' => FulfillmentStatus::InTransit])->save();
        $oneOfTwo = $presenter->forOrder(100);

        $this->assertCount(2, $oneOfTwo['shipments']);
        $this->assertNotNull($oneOfTwo['notice']);
        $this->assertSame(FulfillmentAggregator::PARTIALLY_DELIVERED, $oneOfTwo['status'], 'one delivered parcel out of two is not a delivered order');

        $shipments['m5azn']->forceFill(['status' => FulfillmentStatus::Delivered, 'delivered_at' => now()])->save();
        $this->assertSame(FulfillmentAggregator::DELIVERED, $presenter->forOrder(100)['status']);
        $this->assertSame('confirmed', DB::table('orders')->where('id', 100)->value('order_status'), 'the platform order status is still the platform\'s to change');
        $this->assertInstanceOf(ShipmentTracker::class, $tracker);
    }

    public function test_a_single_provider_order_shows_one_shipment_and_no_split_notice(): void
    {
        $this->placeOrder(100, [['product' => 1, 'qty' => 1, 'price' => 60.0]]);
        $this->route(100);

        $view = app(CustomerFulfillmentPresenter::class)->forOrder(100);

        $this->assertCount(1, $view['shipments']);
        $this->assertNull($view['notice']);
    }

    // ---- multi-currency ----------------------------------------------------------------------------------------

    public function test_in_a_usd_based_store_the_provider_is_told_the_riyal_amount_the_customer_saw(): void
    {
        $this->multiCurrencyUsdBase();
        $this->placeOrder(100, [['product' => 1, 'qty' => 1, 'price' => 20.0]], shipping: 4.0, cod: true);

        $torod = $this->route(100)['torod_point'];

        $this->assertEquals(90.0, (float) $torod->cod_amount, '(20 + 4) USD x 3.75 = 90 SAR');
        $this->assertEquals(75.0, (float) $torod->items_total);
        $this->assertSame('SAR', $torod->currency);
    }

    public function test_an_unknown_exchange_rate_holds_the_order_rather_than_sending_a_guess(): void
    {
        $this->multiCurrencyUsdBase();
        DB::table('currencies')->where('code', 'SAR')->delete();
        \Illuminate\Support\Facades\Cache::flush();
        $this->placeOrder(100, [['product' => 1, 'qty' => 1, 'price' => 20.0]], cod: true);

        $torod = $this->route(100)['torod_point'];

        $this->assertSame(FulfillmentStatus::Held, $torod->status);
        $this->assertSame(HoldReason::CURRENCY_UNCONVERTIBLE, $torod->hold_reason);
        Queue::assertNothingPushed();
    }

    // ---- ineligible orders -----------------------------------------------------------------------------------------

    public function test_an_unpaid_online_order_and_a_pos_order_are_not_routed(): void
    {
        $this->placeOrder(100, [['product' => 1, 'qty' => 1, 'price' => 60.0]]);
        DB::table('orders')->where('id', 100)->update(['payment_status' => 'unpaid']);
        $this->placeOrder(101, [['product' => 3, 'qty' => 1, 'price' => 60.0]]);
        DB::table('orders')->where('id', 101)->update(['order_type' => 'POS']);

        $this->assertSame([], app(FulfillmentRouter::class)->route(100), 'waits for payment');
        $this->assertSame([], app(FulfillmentRouter::class)->route(101), 'a counter sale is never shipped by a provider');
    }

    public function test_a_pending_order_waits_until_it_is_confirmed(): void
    {
        $this->placeOrder(100, [['product' => 1, 'qty' => 1, 'price' => 60.0]], cod: true);
        DB::table('orders')->where('id', 100)->update(['order_status' => 'pending']);
        $this->assertSame([], app(FulfillmentRouter::class)->route(100));

        DB::table('orders')->where('id', 100)->update(['order_status' => 'confirmed']);
        $this->assertCount(1, app(FulfillmentRouter::class)->route(100));
    }
}
