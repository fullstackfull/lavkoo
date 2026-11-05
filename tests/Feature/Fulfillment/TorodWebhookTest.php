<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\EventOutcome;
use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Jobs\Fulfillment\ProcessProviderEvent;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentEvent;
use App\Services\Fulfillment\FulfillmentRecovery;
use App\Services\Fulfillment\WebhookEventProcessor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

class TorodWebhookTest extends TorodTestCase
{
    private function processor(): WebhookEventProcessor
    {
        return app(WebhookEventProcessor::class);
    }

    /** Receive and process in one go, as the queue would. */
    private function receive(string $type, array $payload): array
    {
        $ingested = $this->processor()->ingest(ProviderCode::TorodPoint, $type, $payload);
        $outcome = $ingested['event_id'] === null ? null : $this->processor()->process($ingested['event_id']);

        return $ingested + ['outcome' => $outcome];
    }

    private function submitted(array $attrs = []): ProviderFulfillment
    {
        $fulfillment = $this->torodFulfillment();
        $fulfillment->forceFill($attrs + [
            'status' => FulfillmentStatus::Submitted, 'provider_reference' => 'O0015030400001', 'submitted_at' => now(),
        ])->save();

        return $fulfillment->fresh();
    }

    public function test_a_lastmile_event_is_matched_by_our_invoice_number_and_teaches_us_the_package_barcode(): void
    {
        $fulfillment = $this->submitted();
        Http::fake();

        $result = $this->receive('lastmile', $this->lastmilePayload('OUT_FOR_DELIVERY'));

        $this->assertSame('accepted', $result['status']);
        $this->assertSame(EventOutcome::APPLIED, $result['outcome']);
        $fresh = $fulfillment->fresh();
        $this->assertSame(FulfillmentStatus::OutForDelivery, $fresh->status);
        $this->assertSame('KSA100422577363', $fresh->provider_secondary_reference, 'the last-mile package barcode');
        $this->assertSame('KSA100422577363', $fresh->tracking_number, 'the customer-facing tracking number');
        $this->assertSame('368200054', $fresh->provider_internal_id);
        $this->assertNotNull($fresh->shipped_at);
    }

    public function test_the_tracking_url_comes_from_a_configured_template(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['tracking_url_template' => 'https://track.example.test/?code={tracking}', 'company_id' => '714']);
        $fulfillment = $this->submitted();

        $this->receive('lastmile', $this->lastmilePayload('OUT_FOR_DELIVERY'));

        $this->assertSame('https://track.example.test/?code=KSA100422577363', $fulfillment->fresh()->tracking_url);
    }

    public function test_a_repeated_delivery_of_the_same_event_is_stored_and_applied_exactly_once(): void
    {
        $this->submitted();
        $payload = $this->lastmilePayload('OUT_FOR_DELIVERY', time: 1762347300095);

        $first = $this->receive('lastmile', $payload);
        $second = $this->receive('lastmile', $payload);
        $third = $this->receive('lastmile', $payload);

        $this->assertSame('accepted', $first['status']);
        $this->assertSame('duplicate', $second['status']);
        $this->assertSame('duplicate', $third['status']);
        $this->assertSame(1, ProviderFulfillmentEvent::count());
    }

    public function test_an_older_event_arriving_late_never_overwrites_a_newer_state(): void
    {
        $fulfillment = $this->submitted();
        $now = (int) (microtime(true) * 1000);

        $this->receive('lastmile', $this->lastmilePayload('OUT_FOR_DELIVERY', time: $now));
        $late = $this->receive('lastmile', $this->lastmilePayload('SCANNED_BY_DRIVER_AND_IN_CAR', time: $now - 3_600_000));

        $this->assertSame(EventOutcome::IGNORED_STALE, $late['outcome']);
        $this->assertSame(FulfillmentStatus::OutForDelivery, $fulfillment->fresh()->status);
        $this->assertSame(2, ProviderFulfillmentEvent::count(), 'the stale event is still kept in the history');
    }

    public function test_events_delivered_out_of_order_converge_on_the_newest(): void
    {
        $fulfillment = $this->submitted();
        $base = (int) (microtime(true) * 1000) - 10_000_000;

        foreach ([['OUT_FOR_DELIVERY', 3], ['ACCEPTED_BY_DRIVER_AND_PENDING_PICKUP', 1], ['SCANNED_BY_DRIVER_AND_IN_CAR', 2]] as [$status, $step]) {
            $this->receive('lastmile', $this->lastmilePayload($status, time: $base + $step * 1_000_000));
        }

        $this->assertSame(FulfillmentStatus::OutForDelivery, $fulfillment->fresh()->status);
    }

    public function test_a_delivered_event_is_confirmed_with_the_provider_before_it_is_applied(): void
    {
        $fulfillment = $this->submitted();
        Http::fake([self::V2 . '/guests/packages/status*' => Http::response(['status' => 'DELIVERED_TO_RECIPIENT', 'cod' => 0, 'cost' => 12, 'id' => 1])]);

        $result = $this->receive('lastmile', $this->lastmilePayload('DELIVERED_TO_RECIPIENT'));

        $this->assertSame(EventOutcome::APPLIED, $result['outcome']);
        $this->assertSame(FulfillmentStatus::Delivered, $fulfillment->fresh()->status);
        $this->assertNotNull($fulfillment->fresh()->delivered_at);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'barcode=KSA100422577363'));
    }

    public function test_a_forged_delivered_claim_the_provider_denies_never_marks_the_shipment_delivered(): void
    {
        $fulfillment = $this->submitted();
        Http::fake([self::V2 . '/guests/packages/status*' => Http::response(['status' => 'OUT_FOR_DELIVERY'])]);

        $result = $this->receive('lastmile', $this->lastmilePayload('DELIVERED_TO_RECIPIENT'));

        $this->assertSame(EventOutcome::RECEIVED, $result['outcome'], 'waits; is not applied on trust');
        $this->assertSame(FulfillmentStatus::Submitted, $fulfillment->fresh()->status);
        $this->assertNull($fulfillment->fresh()->delivered_at);
    }

    public function test_a_claim_for_a_package_the_provider_does_not_know_is_rejected(): void
    {
        $fulfillment = $this->submitted();
        Http::fake([self::V2 . '/guests/packages/status*' => Http::response(['message' => 'Package not found'], 404)]);

        $result = $this->receive('lastmile', $this->lastmilePayload('DELIVERED_TO_RECIPIENT'));

        $this->assertSame(EventOutcome::REJECTED_UNVERIFIED, $result['outcome']);
        $this->assertSame(FulfillmentStatus::Submitted, $fulfillment->fresh()->status);
    }

    public function test_a_payload_pairing_our_invoice_with_someone_elses_package_cannot_confirm_our_shipment(): void
    {
        $fulfillment = $this->submitted(['provider_secondary_reference' => 'KSA-OURS']);
        Http::fake([self::V2 . '/guests/packages/status*' => fn (Request $request) => str_contains($request->url(), 'KSA-OTHER')
            ? Http::response(['status' => 'DELIVERED_TO_RECIPIENT', 'cod' => 0])
            : Http::response(['status' => 'OUT_FOR_DELIVERY', 'cod' => 0])]);

        $result = $this->receive('lastmile', $this->lastmilePayload('DELIVERED_TO_RECIPIENT', barcode: 'KSA-OTHER'));

        $this->assertSame(EventOutcome::RECEIVED, $result['outcome'], 'the claim waits: OUR package is still out for delivery');
        $this->assertSame(FulfillmentStatus::Submitted, $fulfillment->fresh()->status);
        $this->assertSame('KSA-OURS', $fulfillment->fresh()->provider_secondary_reference, 'we keep the barcode we already had');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'KSA-OTHER'));
    }

    public function test_a_package_barcode_is_not_learned_from_an_event_whose_cash_is_not_ours(): void
    {
        $fulfillment = $this->submitted();
        Http::fake();

        $result = $this->receive('lastmile', $this->lastmilePayload('DELIVERED_TO_RECIPIENT', barcode: 'KSA-FOREIGN', extra: ['cod' => 55]));

        $this->assertNull($fulfillment->fresh()->provider_secondary_reference, 'a prepaid shipment does not adopt a package that collects 55');
        $this->assertSame(EventOutcome::RECEIVED, $result['outcome'], 'and with no package of ours to ask about, a delivered claim waits');
        $this->assertSame(FulfillmentStatus::Submitted, $fulfillment->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_an_event_whose_references_point_at_two_different_shipments_is_left_unmatched(): void
    {
        $first = $this->submitted(['provider_secondary_reference' => 'KSA-A']);
        $second = $this->torodFulfillment(orderId: 101);
        $second->forceFill(['status' => FulfillmentStatus::Submitted, 'provider_reference' => 'O002', 'submitted_at' => now()])->save();
        Http::fake();

        $result = $this->receive('lastmile', $this->lastmilePayload('OUT_FOR_DELIVERY', barcode: 'KSA-A', extra: ['invoiceNumber' => '101-TP1']));

        $this->assertSame(EventOutcome::IGNORED_UNMATCHED, $result['outcome']);
        $this->assertSame(FulfillmentStatus::Submitted, $first->fresh()->status);
        $this->assertSame(FulfillmentStatus::Submitted, $second->fresh()->status);
    }

    public function test_a_package_reporting_different_cash_than_we_asked_for_does_not_confirm_a_claim(): void
    {
        $fulfillment = $this->submitted(['provider_secondary_reference' => 'KSA100422577363']);
        Http::fake([self::V2 . '/guests/packages/status*' => Http::response(['status' => 'DELIVERED_TO_RECIPIENT', 'cod' => 99])]);

        $result = $this->receive('lastmile', $this->lastmilePayload('DELIVERED_TO_RECIPIENT'));

        $this->assertSame(EventOutcome::REJECTED_UNVERIFIED, $result['outcome']);
        $this->assertSame(FulfillmentStatus::Submitted, $fulfillment->fresh()->status);
    }

    public function test_payload_keys_are_read_whatever_their_casing(): void
    {
        $fulfillment = $this->submitted();
        Http::fake();

        $result = $this->receive('lastmile', ['PackageId' => 1, 'NewStatus' => 'OUT_FOR_DELIVERY', 'Barcode' => 'KSA100422577363', 'Cod' => 0, 'InvoiceNumber' => '100-TP1', 'Time' => (int) (microtime(true) * 1000)]);

        $this->assertSame(EventOutcome::APPLIED, $result['outcome']);
        $this->assertSame(FulfillmentStatus::OutForDelivery, $fulfillment->fresh()->status);
        $this->assertSame('KSA100422577363', $fulfillment->fresh()->provider_secondary_reference);
    }

    public function test_when_the_provider_cannot_be_asked_the_claim_waits_and_is_applied_once_it_can_be_confirmed(): void
    {
        $fulfillment = $this->submitted();
        $providerIsUp = false;
        Http::fake([self::V2 . '/guests/packages/status*' => function () use (&$providerIsUp) {
            return $providerIsUp ? Http::response(['status' => 'DELIVERED_TO_RECIPIENT']) : Http::response('down', 503);
        }]);

        $waiting = $this->receive('lastmile', $this->lastmilePayload('DELIVERED_TO_RECIPIENT'));

        $this->assertSame(EventOutcome::RECEIVED, $waiting['outcome']);
        $this->assertSame(FulfillmentStatus::Submitted, $fulfillment->fresh()->status);

        // The provider recovers and confirms; the sweep re-runs the waiting event.
        $providerIsUp = true;
        $this->travel(2)->minutes();
        $retried = app(FulfillmentRecovery::class)->retryUnmatchedEvents();

        $this->assertSame(1, $retried);
        $this->assertSame(FulfillmentStatus::Delivered, $fulfillment->fresh()->status);
    }

    public function test_non_terminal_statuses_are_applied_without_an_extra_provider_call(): void
    {
        $fulfillment = $this->submitted();
        Http::fake();

        $this->receive('lastmile', $this->lastmilePayload('OUT_FOR_DELIVERY'));

        $this->assertSame(FulfillmentStatus::OutForDelivery, $fulfillment->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_verification_can_be_switched_off_for_providers_that_cannot_be_asked(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['verify_terminal_webhooks' => false, 'company_id' => '714']);
        $fulfillment = $this->submitted();
        Http::fake();

        $this->receive('lastmile', $this->lastmilePayload('DELIVERED_TO_RECIPIENT'));

        $this->assertSame(FulfillmentStatus::Delivered, $fulfillment->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_a_delivered_shipment_is_not_pulled_back_by_a_later_non_terminal_event(): void
    {
        $fulfillment = $this->submitted();
        Http::fake([self::V2 . '/guests/packages/status*' => Http::response(['status' => 'DELIVERED_TO_RECIPIENT'])]);
        $now = (int) (microtime(true) * 1000);
        $this->receive('lastmile', $this->lastmilePayload('DELIVERED_TO_RECIPIENT', time: $now));

        $late = $this->receive('lastmile', $this->lastmilePayload('OUT_FOR_DELIVERY', time: $now + 60_000));

        $this->assertSame(EventOutcome::IGNORED_TERMINAL, $late['outcome']);
        $this->assertSame(FulfillmentStatus::Delivered, $fulfillment->fresh()->status);
    }

    public function test_postponed_and_failed_deliveries_keep_the_reason_and_date(): void
    {
        $fulfillment = $this->submitted();
        $now = (int) (microtime(true) * 1000);

        $this->receive('lastmile', $this->lastmilePayload('POSTPONED_DELIVERY', time: $now, extra: ['notes' => 'customer asked for tomorrow', 'postponedDeliveryDate' => '2026-02-20T12:09:17.563Z']));
        $postponed = $fulfillment->fresh();
        $this->assertSame(FulfillmentStatus::DeliveryPostponed, $postponed->status);
        $this->assertSame('2026-02-20T12:09:17.563Z', $postponed->metaValue('postponed_until'));
        $this->assertSame('customer asked for tomorrow', $postponed->metaValue('last_note'));

        $this->receive('lastmile', $this->lastmilePayload('FAILED', time: $now + 1000, extra: ['notes' => 'order refused']));
        $this->assertSame(FulfillmentStatus::DeliveryFailed, $fulfillment->fresh()->status);
        $this->assertSame('order refused', $fulfillment->fresh()->metaValue('last_note'));
    }

    public function test_proof_of_delivery_links_are_kept_only_if_https(): void
    {
        $fulfillment = $this->submitted();
        Http::fake([self::V2 . '/guests/packages/status*' => Http::response(['status' => 'DELIVERED_TO_RECIPIENT'])]);

        $this->receive('lastmile', $this->lastmilePayload('DELIVERED_TO_RECIPIENT', extra: ['attachmentUrls' => ['https://bucket.example/proof.png', 'http://insecure.example/x.png', 'javascript:alert(1)']]));

        $this->assertSame(['https://bucket.example/proof.png'], $fulfillment->fresh()->metaValue('proof_urls'));
    }

    public function test_an_unmapped_status_is_recorded_raw_and_changes_nothing(): void
    {
        $fulfillment = $this->submitted();

        $result = $this->receive('lastmile', $this->lastmilePayload('BRAND_NEW_STATUS'));

        $this->assertSame(EventOutcome::IGNORED_UNMAPPED, $result['outcome']);
        $this->assertSame(FulfillmentStatus::Submitted, $fulfillment->fresh()->status);
        $this->assertSame('BRAND_NEW_STATUS', $fulfillment->fresh()->provider_status);
    }

    public function test_completed_without_a_delivery_event_needs_attention_and_after_delivery_is_ignored(): void
    {
        $fulfillment = $this->submitted();
        Http::fake([self::V2 . '/guests/packages/status*' => Http::sequence()->push(['status' => 'COMPLETED'])->push(['status' => 'DELIVERED_TO_RECIPIENT'])->push(['status' => 'COMPLETED'])]);
        $now = (int) (microtime(true) * 1000);

        $this->receive('lastmile', $this->lastmilePayload('COMPLETED', time: $now));
        $this->assertSame(FulfillmentStatus::NeedsAttention, $fulfillment->fresh()->status);
        $this->assertSame('provider_attention', $fulfillment->fresh()->last_error_code);

        $this->receive('lastmile', $this->lastmilePayload('DELIVERED_TO_RECIPIENT', time: $now + 1000));
        $this->assertSame(FulfillmentStatus::Delivered, $fulfillment->fresh()->status);

        $late = $this->receive('lastmile', $this->lastmilePayload('COMPLETED', time: $now + 2000));
        $this->assertSame(EventOutcome::IGNORED_TERMINAL, $late['outcome']);
    }

    public function test_a_timestamp_from_a_skewed_provider_clock_is_clamped_so_it_cannot_pin_the_shipment(): void
    {
        $fulfillment = $this->submitted();
        $farFuture = (int) ((time() + 86400 * 365) * 1000);

        $this->receive('lastmile', $this->lastmilePayload('OUT_FOR_DELIVERY', time: $farFuture));

        $this->assertTrue($fulfillment->fresh()->last_event_at->lessThan(now()->addMinutes(10)));
    }

    // ---- fulfillment (warehouse) webhook -------------------------------------------------------------

    public function test_warehouse_progress_is_display_only_and_never_means_delivery(): void
    {
        $fulfillment = $this->submitted();

        foreach (['CREATED', 'PICKED', 'PACKED'] as $step) {
            $result = $this->receive('fulfillment', ['status' => $step, 'barcode' => 'O0015030400001', 'packageBarcode' => null]);
            $this->assertSame(EventOutcome::INFORMATIONAL, $result['outcome']);
            $this->assertSame(strtolower($step), $fulfillment->fresh()->warehouse_status);
        }

        $this->assertSame(FulfillmentStatus::Submitted, $fulfillment->fresh()->status, 'packing is not shipping, let alone delivery');
        $this->assertNull($fulfillment->fresh()->delivered_at);
    }

    public function test_the_warehouse_event_links_the_package_barcode_to_our_fulfillment(): void
    {
        $fulfillment = $this->submitted();

        $this->receive('fulfillment', ['status' => 'PACKED', 'barcode' => 'O0015030400001', 'packageBarcode' => 'KSA555000111']);

        $fresh = $fulfillment->fresh();
        $this->assertSame('KSA555000111', $fresh->provider_secondary_reference);
        $this->assertSame('KSA555000111', $fresh->tracking_number);
    }

    public function test_a_warehouse_event_is_matched_by_package_barcode_when_the_order_barcode_was_never_learned(): void
    {
        $fulfillment = $this->submitted(['provider_reference' => null, 'provider_secondary_reference' => 'KSA555000111']);

        $result = $this->receive('fulfillment', ['status' => 'PICKED', 'barcode' => 'O0015030400009', 'packageBarcode' => 'KSA555000111']);

        $this->assertSame(EventOutcome::INFORMATIONAL, $result['outcome']);
        $this->assertSame('O0015030400009', $fulfillment->fresh()->provider_reference, 'the order barcode is learned');
    }

    public function test_an_event_that_matches_nothing_is_kept_and_retried_once_we_know_the_reference(): void
    {
        $fulfillment = $this->torodFulfillment();
        $fulfillment->forceFill(['status' => FulfillmentStatus::Submitted, 'provider_reference' => null])->save();

        $first = $this->receive('fulfillment', ['status' => 'PICKED', 'barcode' => 'O777', 'packageBarcode' => null]);
        $this->assertSame(EventOutcome::IGNORED_UNMATCHED, $first['outcome']);

        $fulfillment->forceFill(['provider_reference' => 'O777'])->save();
        $retried = app(FulfillmentRecovery::class)->retryUnmatchedEvents();

        $this->assertSame(1, $retried);
        $this->assertSame('picked', $fulfillment->fresh()->warehouse_status);
        $this->assertSame(EventOutcome::INFORMATIONAL, ProviderFulfillmentEvent::first()->outcome);
    }

    // ---- isolation ------------------------------------------------------------------------------

    public function test_a_torod_event_can_never_touch_an_m5azn_fulfillment_even_if_references_collide(): void
    {
        $torod = $this->submitted(['provider_secondary_reference' => null, 'provider_reference' => null]);
        $m5azn = $this->fulfillmentFor(100, ProviderCode::M5azn);
        $m5azn->forceFill(['status' => FulfillmentStatus::Submitted, 'provider_reference' => 'KSA100422577363', 'provider_secondary_reference' => 'KSA100422577363'])->save();
        Http::fake();

        $result = $this->receive('lastmile', $this->lastmilePayload('OUT_FOR_DELIVERY', barcode: 'KSA100422577363', extra: ['invoiceNumber' => '100-MZ1']));

        $this->assertSame(EventOutcome::IGNORED_UNMATCHED, $result['outcome'], 'an M5AZN reference is not matched by Torod Point');
        $this->assertSame(FulfillmentStatus::Submitted, $m5azn->fresh()->status);
        $this->assertSame(FulfillmentStatus::Submitted, $torod->fresh()->status);
    }

    public function test_an_event_for_our_invoice_number_does_not_leak_into_the_other_providers_fulfillment(): void
    {
        $torod = $this->submitted();
        $m5azn = $this->fulfillmentFor(100, ProviderCode::M5azn);
        Http::fake();

        $this->receive('lastmile', $this->lastmilePayload('OUT_FOR_DELIVERY'));

        $this->assertSame(FulfillmentStatus::OutForDelivery, $torod->fresh()->status);
        $this->assertSame(FulfillmentStatus::Pending, $m5azn->fresh()->status);
    }

    public function test_malformed_payloads_are_rejected_without_creating_events(): void
    {
        foreach ([[], ['newStatus' => 'OUT_FOR_DELIVERY'], ['barcode' => 'X'], ['newStatus' => '', 'barcode' => 'X']] as $payload) {
            $this->assertSame('invalid', $this->processor()->ingest(ProviderCode::TorodPoint, 'lastmile', $payload)['status']);
        }
        $this->assertSame('invalid', $this->processor()->ingest(ProviderCode::TorodPoint, 'bogus', ['status' => 'X', 'barcode' => 'Y'])['status']);
        $this->assertSame(0, ProviderFulfillmentEvent::count());
    }

    public function test_ingest_queues_processing_and_stores_a_redacted_payload(): void
    {
        $this->submitted();

        $this->processor()->ingest(ProviderCode::TorodPoint, 'lastmile', $this->lastmilePayload('OUT_FOR_DELIVERY'));

        Queue::assertPushed(ProcessProviderEvent::class);
        $stored = json_encode(ProviderFulfillmentEvent::first()->payload);
        $this->assertStringNotContainsString('0598985000', $stored, 'the driver phone is masked in the stored copy');
    }

    public function test_the_sync_state_records_the_last_received_update(): void
    {
        $this->submitted();

        $this->processor()->ingest(ProviderCode::TorodPoint, 'lastmile', $this->lastmilePayload('OUT_FOR_DELIVERY'));

        $this->assertNotNull(DB::table('provider_sync_states')->where(['provider' => 'torod_point', 'capability' => 'webhooks'])->value('last_received_at'));
    }
}
