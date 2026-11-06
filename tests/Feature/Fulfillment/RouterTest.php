<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\HoldReason;
use App\Enums\Fulfillment\PaymentMode;
use App\Enums\Fulfillment\ProviderCode;
use App\Jobs\Fulfillment\DispatchProviderFulfillment;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentItem;
use App\Services\Fulfillment\FulfillmentRouter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class RouterTest extends FulfillmentTestCase
{
    private function router(): FulfillmentRouter
    {
        return app(FulfillmentRouter::class);
    }

    /** Product A from M5AZN; B and C from Torod Point. */
    private function mixedOrder(int $orderId = 100, array $orderOverrides = []): void
    {
        $this->makeOrder($orderId, $orderOverrides + ['order_amount' => 100]);
        $this->addLine($orderId, 1, 1, 40, ProviderCode::M5azn);
        $this->addLine($orderId, 2, 1, 35, ProviderCode::TorodPoint);
        $this->addLine($orderId, 3, 2, 25, ProviderCode::TorodPoint);
    }

    public function test_one_customer_order_splits_into_one_fulfillment_per_provider(): void
    {
        $this->mixedOrder();

        $created = $this->router()->route(100);

        $this->assertCount(2, $created);
        $m5azn = $this->fulfillmentFor(100, ProviderCode::M5azn);
        $torod = $this->fulfillmentFor(100, ProviderCode::TorodPoint);
        $this->assertSame([1], $m5azn->items->pluck('product_id')->all());
        $this->assertEqualsCanonicalizing([2, 3], $torod->items->pluck('product_id')->all());
        $this->assertSame('100-MZ1', $m5azn->reference);
        $this->assertSame('100-TP1', $torod->reference);
        $this->assertNotSame($m5azn->idempotency_key, $torod->idempotency_key);
        $this->assertSame(1, DB::table('orders')->count(), 'the customer still has exactly one order');
    }

    public function test_each_provider_receives_only_its_own_items(): void
    {
        $this->mixedOrder();
        $this->router()->route(100);

        foreach (ProviderFulfillment::with('items')->get() as $fulfillment) {
            foreach ($fulfillment->items as $item) {
                $this->assertSame($fulfillment->provider, $item->provider, 'a line leaked into another provider\'s fulfillment');
            }
        }
        $this->assertSame(3, ProviderFulfillmentItem::where('status', ProviderFulfillmentItem::STATUS_ROUTED)->count());
    }

    public function test_totals_of_the_children_add_up_to_the_parent_order_total(): void
    {
        $this->mixedOrder();
        $this->router()->route(100);

        $this->assertEqualsWithDelta(100.0, (float) ProviderFulfillment::sum('total_amount'), 0.01);
    }

    public function test_routing_the_same_order_twice_creates_nothing_the_second_time(): void
    {
        $this->mixedOrder();

        $this->router()->route(100);
        $second = $this->router()->route(100);

        $this->assertSame([], $second);
        $this->assertSame(2, ProviderFulfillment::count());
    }

    public function test_an_in_house_only_order_is_never_touched(): void
    {
        $this->makeOrder(100);
        $this->addLine(100, 1, 1, 50, null);

        $this->assertSame([], $this->router()->route(100));
        $this->assertSame(0, ProviderFulfillment::count());
    }

    public function test_in_house_lines_stay_with_the_platform_in_a_mixed_order(): void
    {
        $this->makeOrder(100);
        $this->addLine(100, 1, 1, 50, null);
        $this->addLine(100, 2, 1, 50, ProviderCode::TorodPoint);

        $this->router()->route(100);

        $this->assertSame(1, ProviderFulfillment::count());
        $this->assertSame([2], ProviderFulfillment::first()->items->pluck('product_id')->all());
    }

    public function test_an_unconfirmed_order_is_not_routed_yet(): void
    {
        $this->mixedOrder(100, ['order_status' => 'pending']);

        $this->assertSame([], $this->router()->route(100));
        $this->assertSame(2, ProviderFulfillmentItem::where('status', ProviderFulfillmentItem::STATUS_UNROUTED)->distinct()->count('provider'));
    }

    public function test_an_unpaid_online_order_is_not_routed(): void
    {
        $this->mixedOrder(100, ['payment_status' => 'unpaid', 'payment_method' => 'ssl_commerz']);

        $this->assertSame([], $this->router()->route(100));
    }

    public function test_a_paid_online_order_confirmed_is_routed_and_a_cod_order_confirmed_is_too(): void
    {
        $this->makeOrder(100, ['payment_status' => 'paid', 'payment_method' => 'ssl_commerz']);
        $this->addLine(100, 1, 1, 50, ProviderCode::TorodPoint);
        $this->makeOrder(101, ['payment_status' => 'unpaid', 'payment_method' => 'cash_on_delivery']);
        $this->addLine(101, 1, 1, 50, ProviderCode::TorodPoint);

        $this->assertCount(1, $this->router()->route(100));
        $this->assertCount(1, $this->router()->route(101));
    }

    public function test_pos_paused_and_cancelled_orders_are_never_routed(): void
    {
        foreach ([['order_type' => 'POS'], ['is_pause' => true], ['order_status' => 'canceled']] as $i => $overrides) {
            $id = 200 + $i;
            $this->makeOrder($id, $overrides);
            $this->addLine($id, 1, 1, 50, ProviderCode::TorodPoint);

            $this->assertSame([], $this->router()->route($id), 'order ' . json_encode($overrides) . ' must not route');
        }
    }

    public function test_a_provider_can_narrow_which_order_statuses_trigger_dispatch(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['dispatch_order_statuses' => ['processing']]);
        $this->makeOrder(100, ['order_status' => 'confirmed']);
        $this->addLine(100, 1, 1, 50, ProviderCode::TorodPoint);

        $this->assertSame([], $this->router()->route(100));

        DB::table('orders')->where('id', 100)->update(['order_status' => 'processing']);

        $this->assertCount(1, $this->router()->route(100));
    }

    public function test_creating_a_fulfillment_queues_its_dispatch_after_the_transaction(): void
    {
        $this->mixedOrder();

        $created = $this->router()->route(100);

        Queue::assertPushed(DispatchProviderFulfillment::class, 2);
        foreach ($created as $id) {
            Queue::assertPushed(DispatchProviderFulfillment::class, static fn ($job) => $job->fulfillmentId === $id);
        }
    }

    public function test_on_the_sync_queue_driver_nothing_runs_inline_and_the_sweep_takes_over(): void
    {
        config(['queue.default' => 'sync']);
        $this->mixedOrder();

        $this->router()->route(100);

        Queue::assertNothingPushed();
        $this->assertSame(2, ProviderFulfillment::where('status', FulfillmentStatus::Pending->value)->count());
    }

    public function test_a_prepaid_order_collects_nothing_on_delivery(): void
    {
        $this->mixedOrder();
        $this->router()->route(100);

        foreach (ProviderFulfillment::all() as $fulfillment) {
            $this->assertSame(PaymentMode::Prepaid, $fulfillment->payment_mode);
            $this->assertEqualsWithDelta(0.0, (float) $fulfillment->cod_amount, 0.001);
        }
    }

    public function test_a_cod_order_with_one_provider_makes_that_provider_collect_everything_owed(): void
    {
        $this->makeOrder(100, ['payment_method' => 'cash_on_delivery', 'payment_status' => 'unpaid', 'order_amount' => 120]);
        $this->addLine(100, 1, 1, 100, ProviderCode::TorodPoint);

        $this->router()->route(100);

        $fulfillment = $this->fulfillmentFor(100, ProviderCode::TorodPoint);
        $this->assertSame(PaymentMode::CashOnDelivery, $fulfillment->payment_mode);
        $this->assertEqualsWithDelta(120.0, (float) $fulfillment->cod_amount, 0.001);
        $this->assertSame(FulfillmentStatus::Pending, $fulfillment->status);
    }

    public function test_a_cod_amount_already_paid_in_part_is_not_collected_again(): void
    {
        $this->makeOrder(100, ['payment_method' => 'cash_on_delivery', 'payment_status' => 'unpaid', 'order_amount' => 120, 'paid_amount' => 20]);
        $this->addLine(100, 1, 1, 100, ProviderCode::TorodPoint);

        $this->router()->route(100);

        $this->assertEqualsWithDelta(100.0, (float) $this->fulfillmentFor(100, ProviderCode::TorodPoint)->cod_amount, 0.001);
    }

    public function test_mixed_provider_cod_is_held_so_two_providers_never_both_collect_the_full_amount(): void
    {
        $this->mixedOrder(100, ['payment_method' => 'cash_on_delivery', 'payment_status' => 'unpaid', 'order_amount' => 100]);

        $this->router()->route(100);

        foreach (ProviderFulfillment::all() as $fulfillment) {
            $this->assertSame(FulfillmentStatus::Held, $fulfillment->status);
            $this->assertSame(HoldReason::MIXED_COD_BLOCKED, $fulfillment->hold_reason);
            $this->assertEqualsWithDelta(0.0, (float) $fulfillment->cod_amount, 0.001, 'no collector may be told to collect while the split is unverified');
        }
        Queue::assertNothingPushed();
    }

    public function test_mixed_cod_needs_both_the_environment_switch_and_each_provider_verified(): void
    {
        config(['fulfillment.cod.allow_mixed' => true]);
        $this->enableProvider(ProviderCode::M5azn, ['cod_verified' => true]);
        $this->enableProvider(ProviderCode::TorodPoint, ['cod_verified' => false]);
        $this->mixedOrder(100, ['payment_method' => 'cash_on_delivery', 'payment_status' => 'unpaid']);

        $this->router()->route(100);

        $this->assertSame(HoldReason::MIXED_COD_BLOCKED, $this->fulfillmentFor(100, ProviderCode::M5azn)->hold_reason);
    }

    public function test_verified_mixed_cod_is_split_by_value_and_sums_exactly_to_what_is_owed(): void
    {
        config(['fulfillment.cod.allow_mixed' => true]);
        $this->enableProvider(ProviderCode::M5azn, ['cod_verified' => true]);
        $this->enableProvider(ProviderCode::TorodPoint, ['cod_verified' => true]);
        $this->mixedOrder(100, ['payment_method' => 'cash_on_delivery', 'payment_status' => 'unpaid', 'order_amount' => 100]);

        $this->router()->route(100);

        $m5azn = (float) $this->fulfillmentFor(100, ProviderCode::M5azn)->cod_amount;
        $torod = (float) $this->fulfillmentFor(100, ProviderCode::TorodPoint)->cod_amount;
        $this->assertEqualsWithDelta(40.0, $m5azn, 0.001);
        $this->assertEqualsWithDelta(60.0, $torod, 0.001);
        $this->assertEqualsWithDelta(100.0, $m5azn + $torod, 0.0001);
        $this->assertLessThan(100.0, $m5azn);
        $this->assertLessThan(100.0, $torod);
    }

    public function test_a_cod_order_split_between_a_provider_and_in_house_delivery_is_always_held(): void
    {
        config(['fulfillment.cod.allow_mixed' => true]);
        $this->enableProvider(ProviderCode::TorodPoint, ['cod_verified' => true]);
        $this->makeOrder(100, ['payment_method' => 'cash_on_delivery', 'payment_status' => 'unpaid', 'order_amount' => 100]);
        $this->addLine(100, 1, 1, 50, null);
        $this->addLine(100, 2, 1, 50, ProviderCode::TorodPoint);

        $this->router()->route(100);

        $this->assertSame(HoldReason::MIXED_COD_BLOCKED, $this->fulfillmentFor(100, ProviderCode::TorodPoint)->hold_reason);
    }

    public function test_a_held_mixed_cod_order_is_released_once_it_becomes_safe(): void
    {
        $this->mixedOrder(100, ['payment_method' => 'cash_on_delivery', 'payment_status' => 'unpaid']);
        $this->router()->route(100);
        $held = $this->fulfillmentFor(100, ProviderCode::M5azn);

        $this->assertFalse($this->router()->reevaluateHold($held->id));

        DB::table('orders')->where('id', 100)->update(['payment_status' => 'paid']);

        $this->assertTrue($this->router()->reevaluateHold($held->id));
        $this->assertSame(FulfillmentStatus::Pending, $held->fresh()->status);
        $this->assertSame(PaymentMode::Prepaid, $held->fresh()->payment_mode);
    }

    public function test_an_unconvertible_amount_holds_the_fulfillment_instead_of_sending_a_wrong_figure(): void
    {
        DB::table('currencies')->truncate();
        DB::table('currencies')->insert(['id' => 1, 'name' => 'US Dollar', 'code' => 'USD', 'symbol' => '$', 'exchange_rate' => 1, 'status' => 1]);
        $this->makeOrder(100, ['payment_method' => 'cash_on_delivery', 'payment_status' => 'unpaid']);
        $this->addLine(100, 1, 1, 50, ProviderCode::TorodPoint);

        $this->router()->route(100);

        $this->assertSame(HoldReason::CURRENCY_UNCONVERTIBLE, $this->fulfillmentFor(100, ProviderCode::TorodPoint)->hold_reason);
    }

    public function test_a_cod_total_is_the_riyal_figure_the_customer_saw_in_a_usd_based_store(): void
    {
        $this->multiCurrencyUsdBase();
        $this->makeOrder(100, ['payment_method' => 'cash_on_delivery', 'payment_status' => 'unpaid', 'order_amount' => 40]);
        $this->addLine(100, 1, 1, 40, ProviderCode::TorodPoint);

        $this->router()->route(100);

        $this->assertEqualsWithDelta(150.0, (float) $this->fulfillmentFor(100, ProviderCode::TorodPoint)->cod_amount, 0.001);
    }

    public function test_the_database_refuses_a_duplicate_fulfillment_for_the_same_order_provider_and_generation(): void
    {
        $this->mixedOrder();
        $this->router()->route(100);
        $existing = $this->fulfillmentFor(100, ProviderCode::TorodPoint);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        ProviderFulfillment::create([
            'reference' => 'OTHER-REF', 'order_id' => 100, 'provider' => 'torod_point', 'generation' => 1,
            'idempotency_key' => 'different-key', 'status' => 'pending',
        ]);

        $this->assertNotNull($existing);
    }

    public function test_a_second_router_run_racing_the_first_finds_the_work_already_done(): void
    {
        $this->mixedOrder();

        $first = $this->router()->route(100);
        $raced = $this->router()->route(100);

        $this->assertCount(2, $first);
        $this->assertSame([], $raced);
    }
}
