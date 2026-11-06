<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\EventOutcome;
use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentEvent;
use App\Models\ProviderSyncState;
use App\Services\Fulfillment\Data\StatusSnapshot;
use App\Services\Fulfillment\Data\SubmissionResult;
use App\Services\Fulfillment\FulfillmentRecovery;
use App\Services\Fulfillment\FulfillmentRouter;
use App\Services\Fulfillment\ProviderOrderDispatcher;
use App\Services\Fulfillment\Readiness\FulfillmentPreflight;
use App\Services\Fulfillment\Readiness\PreflightCheck;
use App\Services\Fulfillment\ShipmentTracker;
use App\Services\Fulfillment\WebhookEventProcessor;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The background work has to reach every row, however many cannot progress, and tracking has to say so when it goes
 * quiet instead of looking healthy.
 */
class SweepFairnessAndTrackingTest extends FulfillmentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableProvider(ProviderCode::M5azn);
        $this->enableProvider(ProviderCode::TorodPoint);
    }

    private function recovery(): FulfillmentRecovery
    {
        return app(FulfillmentRecovery::class);
    }

    private function pendingShipment(int $orderId): ProviderFulfillment
    {
        $this->makeOrder($orderId);
        $this->addLine($orderId, 2, 1, 35, ProviderCode::TorodPoint);
        app(FulfillmentRouter::class)->route($orderId);

        return $this->fulfillmentFor($orderId, ProviderCode::TorodPoint);
    }

    private function submittedShipment(int $orderId = 100): ProviderFulfillment
    {
        $shipment = $this->pendingShipment($orderId);
        app(ProviderOrderDispatcher::class)->dispatch($shipment->id);
        $this->torod->calls = [];

        return $shipment->refresh();
    }

    /** @return list<string> */
    private function submitCalls(): array
    {
        return array_values(array_filter($this->torod->calls, static fn (string $call): bool => str_starts_with($call, 'submit:')));
    }

    // ---- sweep fairness -----------------------------------------------------------------------------------------

    public function test_the_dispatch_sweep_reaches_every_row_even_when_the_first_ones_can_never_progress(): void
    {
        config(['queue.default' => 'sync']);
        foreach ([100, 101, 102, 103] as $orderId) {
            $this->pendingShipment($orderId);
        }
        $reachable = $this->pendingShipment(104);
        DB::table('orders')->whereIn('id', [100, 101, 102, 103])->update(['is_pause' => true]);

        $this->recovery()->dispatchDue(limit: 2);
        $this->recovery()->dispatchDue(limit: 2);
        $this->assertSame([], $this->submitCalls(), 'four rows that cannot be sent used up two sweeps of two');

        $this->recovery()->dispatchDue(limit: 2);

        $this->assertCount(1, $this->submitCalls(), 'the third sweep got past them');
        $this->assertSame(FulfillmentStatus::Submitted, $reachable->refresh()->status);
    }

    public function test_the_routing_sweep_reaches_every_order_even_when_the_first_ones_cannot_be_routed(): void
    {
        config(['queue.default' => 'sync']);
        foreach ([100, 101, 102] as $orderId) {
            $this->makeOrder($orderId, ['is_pause' => true]);
            $this->addLine($orderId, 2, 1, 35, ProviderCode::TorodPoint);
        }
        $this->makeOrder(103);
        $this->addLine(103, 2, 1, 35, ProviderCode::TorodPoint);

        $this->recovery()->routeEligibleOrders(limit: 2);
        $this->assertSame(0, ProviderFulfillment::count());

        $this->recovery()->routeEligibleOrders(limit: 2);

        $this->assertSame(1, ProviderFulfillment::where('order_id', 103)->count(), 'the second sweep reached the one order that could be routed');
    }

    public function test_a_sweep_remembers_where_it_stopped(): void
    {
        config(['queue.default' => 'sync']);
        foreach ([100, 101, 102] as $orderId) {
            $this->pendingShipment($orderId);
        }
        DB::table('orders')->update(['is_pause' => true]);

        $this->recovery()->dispatchDue(limit: 2);

        $cursor = ProviderSyncState::where('provider', 'system')->where('capability', 'cursor:dispatch')->firstOrFail()->cursor;
        $this->assertSame(ProviderFulfillment::orderBy('id')->skip(1)->value('id'), $cursor['after']);
    }

    public function test_unmatched_events_are_retried_newest_first_so_old_noise_cannot_starve_them(): void
    {
        foreach (range(1, 201) as $number) {
            ProviderFulfillmentEvent::create([
                'provider' => ProviderCode::TorodPoint->value, 'source' => 'webhook', 'event_type' => 'status',
                'outcome' => EventOutcome::IGNORED_UNMATCHED, 'received_at' => now(), 'payload' => ['n' => $number],
            ]);
        }

        $this->assertSame(200, app(WebhookEventProcessor::class)->retryUnmatched());

        $this->assertSame(EventOutcome::FAILED, ProviderFulfillmentEvent::orderByDesc('id')->firstOrFail()->outcome, 'the newest was looked at again');
        $this->assertSame(EventOutcome::IGNORED_UNMATCHED, ProviderFulfillmentEvent::orderBy('id')->firstOrFail()->outcome, 'the oldest waited its turn');
    }

    // ---- event times --------------------------------------------------------------------------------------------

    public function test_events_inside_the_same_second_are_ordered_by_their_milliseconds(): void
    {
        $shipment = $this->submittedShipment();
        $tracker = app(ShipmentTracker::class);
        $second = Carbon::parse('2026-11-05 12:00:00');

        $newer = $tracker->applyProviderStatus($shipment->id, FulfillmentStatus::OutForDelivery, $second->copy()->addMilliseconds(700), 'webhook', 'out_for_delivery');
        $older = $tracker->applyProviderStatus($shipment->id, FulfillmentStatus::InTransit, $second->copy()->addMilliseconds(200), 'webhook', 'in_transit');

        $this->assertSame(EventOutcome::APPLIED, $newer);
        $this->assertSame(EventOutcome::IGNORED_STALE, $older, 'an event 500 ms older must not overwrite a newer one');
        $shipment->refresh();
        $this->assertSame(FulfillmentStatus::OutForDelivery, $shipment->status);
        $this->assertSame('700', $shipment->last_event_at->format('v'));
    }

    // ---- polling ------------------------------------------------------------------------------------------------

    public function test_the_poll_also_asks_about_shipments_that_need_attention_or_are_partly_delivered(): void
    {
        foreach ([FulfillmentStatus::NeedsAttention, FulfillmentStatus::PartiallyDelivered] as $offset => $status) {
            $shipment = $this->submittedShipment(100 + $offset);
            $shipment->forceFill(['status' => $status, 'provider_reference' => 'REF-' . $offset])->save();
        }
        $this->torod->statusFor = static fn (ProviderFulfillment $shipment): StatusSnapshot => new StatusSnapshot('delivered');

        $this->assertSame(2, $this->recovery()->pollOpenStatuses());

        $this->assertSame(2, ProviderFulfillment::where('status', FulfillmentStatus::Delivered->value)->count());
    }

    public function test_a_status_we_cannot_map_stays_visible_and_is_noted_once(): void
    {
        $shipment = $this->submittedShipment();
        $this->torod->statusFor = static fn (ProviderFulfillment $shipment): StatusSnapshot => new StatusSnapshot('RETURN_TO_VENDOR_PENDING');

        $this->recovery()->pollOpenStatuses();
        DB::table('provider_fulfillments')->update(['last_synced_at' => null]);
        $this->recovery()->pollOpenStatuses();

        $shipment->refresh();
        $this->assertSame(FulfillmentStatus::Submitted, $shipment->status, 'an unknown status never moves the shipment');
        $this->assertSame('RETURN_TO_VENDOR_PENDING', $shipment->provider_status);
        $this->assertSame(1, ProviderFulfillmentEvent::where('provider_fulfillment_id', $shipment->id)->where('outcome', EventOutcome::IGNORED_UNMAPPED)->count());
    }

    public function test_a_poll_that_gets_no_answer_changes_nothing_and_is_not_repeated_every_minute(): void
    {
        $shipment = $this->submittedShipment();
        $calls = 0;
        $this->torod->statusFor = function () use (&$calls): ?StatusSnapshot {
            $calls++;

            return null;
        };

        $this->recovery()->pollOpenStatuses();
        $this->recovery()->pollOpenStatuses();

        $this->assertSame(1, $calls, 'asked once, then left alone until the poll interval passes');
        $this->assertSame(FulfillmentStatus::Submitted, $shipment->refresh()->status);
    }

    // ---- a reference another shipment already carries ----------------------------------------------------------------

    public function test_a_reference_another_shipment_carries_flags_the_shipment_instead_of_stranding_it(): void
    {
        $first = $this->submittedShipment(100);
        $second = $this->pendingShipment(101);
        app(ShipmentTracker::class)->recordAcceptance($second->id, SubmissionResult::accepted($first->provider_reference));

        $second->refresh();
        $this->assertSame(FulfillmentStatus::NeedsAttention, $second->status);
        $this->assertSame('duplicate_provider_reference', $second->last_error_code);
        $this->assertNull($second->provider_reference, 'the other shipment keeps the reference');
        $this->assertSame(FulfillmentStatus::Submitted, $first->refresh()->status);
    }

    // ---- readiness ----------------------------------------------------------------------------------------------

    private function trackingCheck(): PreflightCheck
    {
        foreach (app(FulfillmentPreflight::class)->run() as $check) {
            if ($check->name === 'shipment tracking') {
                return $check;
            }
        }

        $this->fail('no shipment tracking check');
    }

    public function test_readiness_warns_when_an_open_shipment_has_heard_nothing_from_its_provider_for_days(): void
    {
        $shipment = $this->submittedShipment();
        $shipment->forceFill(['last_received_at' => now()])->save();
        $this->assertSame(PreflightCheck::PASS, $this->trackingCheck()->status);

        $shipment->forceFill(['last_received_at' => now()->subDays(3)])->save();

        $check = $this->trackingCheck();
        $this->assertSame(PreflightCheck::WARN, $check->status);
        $this->assertStringContainsString('1 open shipment', $check->detail);
    }

    public function test_finished_and_not_yet_sent_shipments_are_not_counted_as_silent(): void
    {
        $delivered = $this->submittedShipment(100);
        $delivered->forceFill(['status' => FulfillmentStatus::Delivered, 'last_received_at' => now()->subDays(9)])->save();
        $waiting = $this->pendingShipment(101);
        $waiting->forceFill(['last_received_at' => now()->subDays(9)])->save();

        $this->assertSame(PreflightCheck::PASS, $this->trackingCheck()->status);
    }
}
