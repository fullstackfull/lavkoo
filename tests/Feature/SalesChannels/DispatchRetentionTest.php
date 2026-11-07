<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\DispatchState;
use App\Models\ChannelOrderDispatch;
use App\Models\ExternalOrderMapping;
use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\Data\SubmissionResult;
use App\Services\Fulfillment\FulfillmentRouter;
use App\Services\Fulfillment\ProviderOrderDispatcher;
use App\Services\SalesChannels\Dispatch\BuyerDataPurger;
use App\Services\SalesChannels\Dispatch\ManagedShipmentMirror;
use Illuminate\Support\Facades\DB;

/**
 * The buyer's details leave the platform on time in every way an order can end, with the scheduled sweeps running in between as they do in life: the regressions an independent audit found
 * (a cancelled or re-dispatched order kept its details for ever, rows that cannot be purged yet kept the others out of the window, a cancelled shipment the provider cannot stop looked cancelled).
 */
class DispatchRetentionTest extends DispatchTestCase
{
    private function mirror(): ManagedShipmentMirror
    {
        return app(ManagedShipmentMirror::class);
    }

    private function purger(): BuyerDataPurger
    {
        return app(BuyerDataPurger::class);
    }

    private function dispatched(string $orderId = '10234567890', string $package = '3012345678'): array
    {
        $mapping = $this->placed(orderId: $orderId, package: $package);
        $internalId = (int) $this->dispatcher()->dispatch($mapping)->internalOrderId;
        app(FulfillmentRouter::class)->route($internalId);

        return [$mapping->refresh(), ProviderFulfillment::where('order_id', $internalId)->firstOrFail()];
    }

    private function accepted(ProviderFulfillment $fulfillment, string $reference = 'O-TOROD-1'): void
    {
        $this->torod->submitQueue = [SubmissionResult::accepted($reference, providerInternalId: '55', trackingNumber: 'TRK-' . $reference)];
        $this->enableProvider(ProviderCode::TorodPoint, ['dry_run' => false, 'auto_dispatch' => true]);
        app(ProviderOrderDispatcher::class)->dispatch($fulfillment->id);
    }

    private function address(ExternalOrderMapping $mapping): string
    {
        return (string) DB::table('orders')->where('id', ChannelOrderDispatch::where('mapping_id', $mapping->id)->value('internal_order_id') ?? $mapping->order_id)->value('shipping_address_data');
    }

    public function test_a_channel_cancelled_order_is_purged_after_the_grace_period_while_the_sweeps_keep_running(): void
    {
        [$mapping] = $this->dispatched();
        $mapping->forceFill(['status' => ChannelOrderStatus::Cancelled->value])->save();
        $this->mirror()->sweep();
        $closedAt = ChannelOrderDispatch::firstOrFail()->closed_at;

        $this->travel(12)->hours();
        $this->mirror()->sweep();
        $this->assertEquals($closedAt, ChannelOrderDispatch::firstOrFail()->closed_at, 'a sweep that finds nothing new does not move the clock');
        $this->travel(13)->hours();
        $this->mirror()->sweep();

        $this->assertSame(1, $this->purger()->run()[BuyerDataPurger::PURGED]);
        $this->assertStringNotContainsString(self::NAME, $this->address($mapping));
    }

    public function test_an_order_reset_after_a_rehearsal_and_dispatched_again_is_purged_like_any_other(): void
    {
        [$mapping] = $this->dispatched();
        $this->travel(73)->hours();
        $this->assertSame(1, $this->purger()->run()[BuyerDataPurger::RESET]);

        // The provider goes live only now, and an order bought before go-live is never sent by the platform's own rule: this buyer orders again after it.
        $mapping->refresh()->forceFill(['purchased_at' => now()->addMinute()])->save();
        $second = $this->dispatcher()->dispatch($mapping->refresh());
        $this->assertSame('materialized', $second->state);
        $this->assertNull(ChannelOrderDispatch::firstOrFail()->buyer_data_purged_at, 'a new order has its own clock');
        app(FulfillmentRouter::class)->route((int) $second->internalOrderId);
        $this->accepted(ProviderFulfillment::where('order_id', $second->internalOrderId)->firstOrFail());
        $this->mirror()->sweep();

        $this->travel(25)->hours();
        $this->assertSame(1, $this->purger()->run()[BuyerDataPurger::PURGED]);
        $this->assertStringNotContainsString(self::NAME, $this->address($mapping));
    }

    public function test_an_order_the_provider_cancelled_and_one_cancelled_after_it_was_on_the_road_are_purged_too(): void
    {
        [$mapping, $fulfillment] = $this->dispatched();
        $this->accepted($fulfillment);
        $this->mirror()->sweep();
        $fulfillment->forceFill(['status' => FulfillmentStatus::Cancelled])->save();
        $this->mirror()->sweep();
        $this->assertSame([DispatchState::Failed->value, ManagedShipmentMirror::REASON_CANCELLED], [ChannelOrderDispatch::firstOrFail()->state, $mapping->refresh()->review_reason]);

        [$second, $inTransit] = $this->dispatched('10234567891', '3012345679');
        $this->accepted($inTransit, 'O-TOROD-2');
        $this->mirror()->sweep();
        $inTransit->forceFill(['status' => FulfillmentStatus::InTransit])->save();
        $second->refresh()->forceFill(['status' => ChannelOrderStatus::Cancelled->value])->save();
        $this->mirror()->sweep();
        $this->assertSame(ManagedShipmentMirror::REASON_CANCELLED_AFTER_DISPATCH, $second->refresh()->review_reason);

        $this->travel(25)->hours();

        $this->assertSame(2, $this->purger()->run()[BuyerDataPurger::PURGED]);
        $this->assertStringNotContainsString(self::NAME, $this->address($mapping) . $this->address($second));
    }

    public function test_rows_that_cannot_be_purged_yet_do_not_keep_the_others_out_of_the_window(): void
    {
        [, $first] = $this->dispatched('10234567891', '3012345671');
        [, $second] = $this->dispatched('10234567892', '3012345672');
        [$third] = $this->dispatched('10234567893', '3012345673');
        foreach ([$first, $second] as $unsettled) {
            $unsettled->forceFill(['status' => FulfillmentStatus::ReconcilePending])->save();
        }
        $this->travel(80)->hours();

        $one = $this->purger()->run(limit: 2);
        $two = $this->purger()->run(limit: 2);

        $this->assertSame([2, 0], [$one[BuyerDataPurger::SKIPPED], $one[BuyerDataPurger::RESET]]);
        $this->assertSame(1, $two[BuyerDataPurger::RESET], 'the third order was reached on the next run');
        $this->assertNull($third->refresh()->order_id);
    }

    public function test_the_mirror_sweep_is_fair_too_orders_that_need_a_person_do_not_keep_a_new_shipment_out(): void
    {
        $stuck = [];
        foreach ([1, 2, 3] as $number) {
            [, $fulfillment] = $this->dispatched('1023456789' . $number, '301234567' . $number);
            $fulfillment->forceFill(['status' => FulfillmentStatus::Failed])->save();
            $stuck[] = $fulfillment;
        }
        [$mapping, $fresh] = $this->dispatched('10234567899', '3012345679');
        $this->accepted($fresh, 'O-FRESH');
        $this->travel(1)->minutes();

        $this->mirror()->sweep(limit: 3);
        $this->mirror()->sweep(limit: 3);

        $this->assertSame([ExternalOrderMapping::LINK_LINKED, 'O-FRESH'], [$mapping->refresh()->shipment_link_state, $mapping->shipment_reference]);
    }

    public function test_no_buyers_details_outlive_the_hard_limit_even_for_a_send_nobody_settled(): void
    {
        [$mapping, $fulfillment] = $this->dispatched();
        $fulfillment->forceFill(['status' => FulfillmentStatus::ReconcilePending])->save();

        $this->travel(29)->days();
        $this->assertSame(1, $this->purger()->run()[BuyerDataPurger::SKIPPED], 'an unsettled send may still have to be sent again');
        $this->travel(2)->days();

        $this->assertSame(1, $this->purger()->run()[BuyerDataPurger::PURGED]);
        $this->assertStringNotContainsString(self::NAME, $this->address($mapping));
    }

    public function test_a_channel_cancelling_an_order_the_provider_holds_closes_it_here_but_is_not_taken_as_stopped_until_the_provider_says_so(): void
    {
        [$mapping, $fulfillment] = $this->dispatched();
        $this->accepted($fulfillment);
        $this->mirror()->sweep();
        $mapping->refresh()->forceFill(['status' => ChannelOrderStatus::Cancelled->value])->save();

        $this->mirror()->sweep();

        $this->assertSame('canceled', DB::table('orders')->where('id', $mapping->order_id)->value('order_status'));
        $this->assertSame([DispatchState::Accepted->value, ManagedShipmentMirror::REASON_CANCEL_NOT_CONFIRMED], [ChannelOrderDispatch::firstOrFail()->state, $mapping->refresh()->review_reason], 'the parcel may still go out: a person must make sure');

        $fulfillment->forceFill(['status' => FulfillmentStatus::Cancelled])->save();
        $this->mirror()->sweep();

        $this->assertSame([DispatchState::Cancelled->value, null], [ChannelOrderDispatch::firstOrFail()->state, $mapping->refresh()->review_reason], 'the provider stopped it: now it is settled');
    }
}
