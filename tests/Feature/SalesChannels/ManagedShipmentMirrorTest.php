<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\DispatchState;
use App\Models\ChannelOrderDispatch;
use App\Models\ExternalOrderMapping;
use App\Models\InventoryReservation;
use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\Data\SubmissionResult;
use App\Services\Fulfillment\FulfillmentRecovery;
use App\Services\Fulfillment\FulfillmentRouter;
use App\Services\Fulfillment\ProviderOrderDispatcher;
use App\Services\SalesChannels\Dispatch\ManagedShipmentMirror;
use Illuminate\Support\Facades\DB;

/** The platform's shipment seen from the channel order: linked once the provider holds it, flagged when it fails, and closed when the channel cancels the order. */
class ManagedShipmentMirrorTest extends DispatchTestCase
{
    private function mirror(): ManagedShipmentMirror
    {
        return app(ManagedShipmentMirror::class);
    }

    /** A dispatched order whose provider shipment exists but has not been sent yet. */
    private function routed(): array
    {
        $mapping = $this->placed();
        $orderId = (int) $this->dispatcher()->dispatch($mapping)->internalOrderId;
        app(FulfillmentRouter::class)->route($orderId);

        return [$mapping->refresh(), ProviderFulfillment::where('order_id', $orderId)->firstOrFail()];
    }

    private function acceptedByTorod(ProviderFulfillment $fulfillment): void
    {
        $this->torod->submitQueue = [SubmissionResult::accepted('O-TOROD-1', providerInternalId: '55', trackingNumber: 'TRK-TOROD-1')];
        $this->enableProvider(ProviderCode::TorodPoint, ['dry_run' => false, 'auto_dispatch' => true]);
        app(ProviderOrderDispatcher::class)->dispatch($fulfillment->id);
    }

    private function reservationState(ExternalOrderMapping $mapping): ?string
    {
        return InventoryReservation::where('external_order_mapping_id', $mapping->id)->value('state');
    }

    public function test_nothing_is_linked_while_the_provider_has_not_accepted_the_shipment(): void
    {
        [$mapping] = $this->routed();

        $this->assertSame(ManagedShipmentMirror::UNTOUCHED, $this->mirror()->mirror($mapping));
        $mapping->refresh();
        $this->assertNull($mapping->shipment_reference);
        $this->assertNotSame(ExternalOrderMapping::LINK_LINKED, $mapping->shipment_link_state);
        $this->assertSame(InventoryReservation::RESERVED, $this->reservationState($mapping), 'the units stay promised until the provider has the order');
    }

    public function test_once_the_provider_accepts_the_order_is_linked_and_its_reservation_is_committed(): void
    {
        [$mapping, $fulfillment] = $this->routed();
        $this->acceptedByTorod($fulfillment);

        $this->assertSame(ManagedShipmentMirror::LINKED, $this->mirror()->mirror($mapping));

        $mapping->refresh();
        $this->assertSame(['torod_point', ExternalOrderMapping::LINK_LINKED, 'O-TOROD-1', 'TRK-TOROD-1', 'submitted', 'managed'], [$mapping->provider, $mapping->shipment_link_state, $mapping->shipment_reference, $mapping->tracking_number, $mapping->shipment_status, $mapping->execution_policy]);
        $this->assertSame(InventoryReservation::COMMITTED, $this->reservationState($mapping));
        $row = ChannelOrderDispatch::firstOrFail();
        $this->assertSame(DispatchState::Accepted->value, $row->state);
        $this->assertNotNull($row->accepted_at);
    }

    public function test_running_it_again_changes_nothing_and_a_new_status_or_tracking_number_is_carried_over(): void
    {
        [$mapping, $fulfillment] = $this->routed();
        $this->acceptedByTorod($fulfillment);
        $this->mirror()->mirror($mapping);
        $audited = DB::table('audit_logs')->where('action', 'sales_channel.managed_shipment_linked')->count();

        $this->assertSame(ManagedShipmentMirror::UNTOUCHED, $this->mirror()->mirror($mapping->refresh()));

        $fulfillment->forceFill(['status' => FulfillmentStatus::InTransit, 'tracking_number' => 'TRK-NEW'])->save();
        $this->assertSame(ManagedShipmentMirror::UPDATED, $this->mirror()->mirror($mapping->refresh()));
        $mapping->refresh();
        $this->assertSame(['in_transit', 'TRK-NEW', 1], [$mapping->shipment_status, $mapping->tracking_number, $audited]);
        $this->assertSame($audited, DB::table('audit_logs')->where('action', 'sales_channel.managed_shipment_linked')->count(), 'the link is recorded once');
    }

    public function test_a_failed_shipment_is_flagged_for_a_person_and_the_flag_clears_when_it_is_sent_after_all(): void
    {
        [$mapping, $fulfillment] = $this->routed();
        $fulfillment->forceFill(['status' => FulfillmentStatus::Failed])->save();

        $this->assertSame(ManagedShipmentMirror::ATTENTION, $this->mirror()->mirror($mapping));
        $this->assertSame([ManagedShipmentMirror::REASON_FAILED, DispatchState::Failed->value], [$mapping->refresh()->review_reason, ChannelOrderDispatch::firstOrFail()->state]);
        $this->assertSame(ManagedShipmentMirror::UNTOUCHED, $this->mirror()->mirror($mapping), 'flagging is not repeated');

        $fulfillment->forceFill(['status' => FulfillmentStatus::Submitted, 'provider_reference' => 'O-LATE'])->save();
        $this->assertSame(ManagedShipmentMirror::LINKED, $this->mirror()->mirror($mapping->refresh()));
        $this->assertNull($mapping->refresh()->review_reason);
    }

    public function test_a_provider_reference_that_belongs_to_another_order_is_a_conflict_never_a_second_link(): void
    {
        [$mapping, $fulfillment] = $this->routed();
        $this->acceptedByTorod($fulfillment);
        $other = $this->placed(orderId: '10999999999', package: '3099999999');
        $other->forceFill(['provider' => 'torod_point', 'shipment_reference' => 'O-TOROD-1', 'shipment_link_state' => ExternalOrderMapping::LINK_LINKED])->save();

        $this->assertSame(ManagedShipmentMirror::ATTENTION, $this->mirror()->mirror($mapping));
        $mapping->refresh();
        $this->assertSame([ExternalOrderMapping::LINK_CONFLICT, ManagedShipmentMirror::REASON_LINKED_ELSEWHERE, null], [$mapping->shipment_link_state, $mapping->review_reason, $mapping->shipment_reference]);
    }

    public function test_when_the_channel_cancels_an_order_not_yet_on_the_road_the_internal_order_is_closed_and_the_platform_cancels_the_shipment(): void
    {
        [$mapping, $fulfillment] = $this->routed();
        $mapping->forceFill(['status' => ChannelOrderStatus::Cancelled->value])->save();

        $this->assertSame(ManagedShipmentMirror::CANCELLED, $this->mirror()->mirror($mapping));

        $this->assertSame('canceled', DB::table('orders')->where('id', $mapping->order_id)->value('order_status'));
        $this->assertSame(InventoryReservation::RELEASED, $this->reservationState($mapping));
        $this->assertSame(DispatchState::Cancelled->value, ChannelOrderDispatch::firstOrFail()->state);
        $this->assertSame(1, app(FulfillmentRecovery::class)->cancelForClosedOrders(), 'the fulfilment pipeline now sees a closed order and cancels its shipment by its own rules');
        $this->assertSame(ManagedShipmentMirror::UNTOUCHED, $this->mirror()->mirror($mapping->refresh()), 'and doing it twice does nothing');
    }

    public function test_a_parcel_already_on_the_road_is_never_cancelled_automatically(): void
    {
        [$mapping, $fulfillment] = $this->routed();
        $this->acceptedByTorod($fulfillment);
        $this->mirror()->mirror($mapping);
        $fulfillment->forceFill(['status' => FulfillmentStatus::InTransit])->save();
        $mapping->refresh()->forceFill(['status' => ChannelOrderStatus::Cancelled->value])->save();

        $this->assertSame(ManagedShipmentMirror::ATTENTION, $this->mirror()->mirror($mapping));

        $this->assertSame('confirmed', DB::table('orders')->where('id', $mapping->order_id)->value('order_status'));
        $this->assertSame(ManagedShipmentMirror::REASON_CANCELLED_AFTER_DISPATCH, $mapping->refresh()->review_reason);
    }

    public function test_the_sweep_goes_through_the_open_managed_orders_and_skips_finished_ones(): void
    {
        [, $fulfillment] = $this->routed();
        $this->acceptedByTorod($fulfillment);
        $finished = $this->placed(orderId: '10777777777', package: '3077777777');
        $finished->forceFill(['execution_policy' => 'managed', 'order_id' => 9999, 'shipment_status' => 'delivered'])->save();

        $counts = $this->mirror()->sweep();

        $this->assertSame([1, 0], [$counts[ManagedShipmentMirror::LINKED], $counts[ManagedShipmentMirror::CANCELLED]]);
        $this->assertSame(1, array_sum($counts), 'the delivered order was not even looked at');
    }
}
