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
    private const OUR_INVOICE_NUMBER = '100002-TP1';

    private const WAREHOUSE_BARCODE = 'O0714001615223';

    private const PACKAGE_BARCODE = '100551776918';

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

    private function submitted(array $attrs = [], int $orderId = 100): ProviderFulfillment
    {
        $fulfillment = $this->torodFulfillment(orderId: $orderId);
        $fulfillment->forceFill($attrs + [
            'status' => FulfillmentStatus::Submitted, 'provider_reference' => 'O0015030400001', 'submitted_at' => now(),
        ])->save();

        return $fulfillment->fresh();
    }

    /**
     * The shipment of order 100002 as production has it: our invoice number is `100002-TP1`, the warehouse order's barcode is
     * `O0714001615223`, and its last-mile package is `100551776918`.
     */
    private function productionShipment(): ProviderFulfillment
    {
        return $this->submitted(['provider_reference' => self::WAREHOUSE_BARCODE], orderId: 100002);
    }

    /** A Lastmile body as the live webhook sends it: `invoiceNumber` carries the WAREHOUSE barcode, not our invoice number. */
    private function productionPayload(string $status, int|string|null $time = null, array $extra = []): array
    {
        return $this->lastmilePayload($status, barcode: self::PACKAGE_BARCODE, time: $time, extra: $extra + ['invoiceNumber' => self::WAREHOUSE_BARCODE]);
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

    // ---- the invoice number the live webhook actually sends -------------------------------------------------------------

    /**
     * Order 100002 in production: every event of it was recorded `ignored_unmatched` because `invoiceNumber` carried the
     * warehouse order's barcode (`O0714001615223`) and not our invoice number (`100002-TP1`), which is the only one the field
     * was read against. Both name the same shipment of ours, and both are unique, so either must find it.
     */
    public function test_an_event_whose_invoice_number_is_the_warehouse_order_barcode_is_matched_to_our_shipment(): void
    {
        $fulfillment = $this->productionShipment();
        $this->assertSame(self::OUR_INVOICE_NUMBER, $fulfillment->reference, 'the fixture is the production shipment');
        Http::fake();

        $result = $this->receive('lastmile', $this->productionPayload('OUT_FOR_DELIVERY'));

        $this->assertSame(EventOutcome::APPLIED, $result['outcome']);
        $fresh = $fulfillment->fresh();
        $this->assertSame(FulfillmentStatus::OutForDelivery, $fresh->status);
        $this->assertSame(self::PACKAGE_BARCODE, $fresh->provider_secondary_reference, 'the last-mile barcode is learned from the event that matched');
        $this->assertSame(self::PACKAGE_BARCODE, $fresh->tracking_number);
        $this->assertSame(self::WAREHOUSE_BARCODE, $fresh->provider_reference, 'and the warehouse barcode is left as it was');
    }

    public function test_an_event_whose_invoice_number_is_our_own_still_matches_the_same_shipment(): void
    {
        $fulfillment = $this->productionShipment();
        Http::fake();

        $result = $this->receive('lastmile', $this->lastmilePayload('OUT_FOR_DELIVERY', barcode: self::PACKAGE_BARCODE, extra: ['invoiceNumber' => self::OUR_INVOICE_NUMBER]));

        $this->assertSame(EventOutcome::APPLIED, $result['outcome']);
        $this->assertSame(FulfillmentStatus::OutForDelivery, $fulfillment->fresh()->status);
        $this->assertSame(self::PACKAGE_BARCODE, $fulfillment->fresh()->provider_secondary_reference);
    }

    /** The production sequence: three events to delivered, a repeat of the last one, and a later scan that must not undo it. */
    public function test_the_production_sequence_reaches_delivered_once_and_is_never_pulled_back(): void
    {
        $fulfillment = $this->productionShipment();
        Http::fake([self::V2 . '/guests/packages/status*' => Http::response(['status' => 'DELIVERED_TO_RECIPIENT', 'cod' => 0])]);
        $start = (int) (microtime(true) * 1000) - 300_000;

        $accepted = $this->receive('lastmile', $this->productionPayload('ACCEPTED_BY_DRIVER_AND_PENDING_PICKUP', time: $start));
        $out = $this->receive('lastmile', $this->productionPayload('OUT_FOR_DELIVERY', time: $start + 60_000));
        $delivered = $this->receive('lastmile', $this->productionPayload('DELIVERED_TO_RECIPIENT', time: $start + 120_000));
        $repeat = $this->receive('lastmile', $this->productionPayload('DELIVERED_TO_RECIPIENT', time: $start + 120_000));
        $later = $this->receive('lastmile', $this->productionPayload('SCANNED_BY_DRIVER_AND_IN_CAR', time: $start + 180_000));

        $this->assertSame(
            [EventOutcome::APPLIED, EventOutcome::APPLIED, EventOutcome::APPLIED],
            [$accepted['outcome'], $out['outcome'], $delivered['outcome']],
        );
        $this->assertSame('duplicate', $repeat['status'], 'the provider may send the same event again; it is stored and applied once');
        $this->assertSame(EventOutcome::IGNORED_TERMINAL, $later['outcome']);
        $this->assertSame(FulfillmentStatus::Delivered, $fulfillment->fresh()->status);
        $this->assertNotNull($fulfillment->fresh()->delivered_at);
        $this->assertSame(4, ProviderFulfillmentEvent::count(), 'four distinct events, the repeat not among them');
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'barcode=' . self::PACKAGE_BARCODE));
    }

    public function test_an_invoice_number_that_is_neither_of_ours_matches_nothing_and_teaches_nothing(): void
    {
        $fulfillment = $this->productionShipment();
        Http::fake();

        $result = $this->receive('lastmile', $this->lastmilePayload('OUT_FOR_DELIVERY', barcode: self::PACKAGE_BARCODE, extra: ['invoiceNumber' => 'O0714001615999']));

        $this->assertSame(EventOutcome::IGNORED_UNMATCHED, $result['outcome']);
        $this->assertSame('no_fulfillment_for_references', ProviderFulfillmentEvent::query()->latest('id')->first()->outcome_note);
        $this->assertSame(FulfillmentStatus::Submitted, $fulfillment->fresh()->status);
        $this->assertNull($fulfillment->fresh()->provider_secondary_reference);
    }

    public function test_a_package_barcode_and_an_invoice_number_naming_two_shipments_leave_the_event_unmatched(): void
    {
        $byPackage = $this->submitted(['provider_secondary_reference' => self::PACKAGE_BARCODE], orderId: 100002);
        $byWarehouseBarcode = $this->submitted(['provider_reference' => self::WAREHOUSE_BARCODE], orderId: 100003);
        Http::fake();

        $result = $this->receive('lastmile', $this->productionPayload('DELIVERED_TO_RECIPIENT'));

        $this->assertSame(EventOutcome::IGNORED_UNMATCHED, $result['outcome']);
        $this->assertSame(FulfillmentStatus::Submitted, $byPackage->fresh()->status);
        $this->assertSame(FulfillmentStatus::Submitted, $byWarehouseBarcode->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_an_invoice_number_two_shipments_both_answer_to_is_left_unmatched(): void
    {
        $asOurInvoiceNumber = $this->submitted(orderId: 100002);
        $asAWarehouseBarcode = $this->submitted(['provider_reference' => self::OUR_INVOICE_NUMBER], orderId: 100003);
        Http::fake();

        $result = $this->receive('lastmile', $this->lastmilePayload('OUT_FOR_DELIVERY', barcode: self::PACKAGE_BARCODE, extra: ['invoiceNumber' => self::OUR_INVOICE_NUMBER]));

        $this->assertSame(EventOutcome::IGNORED_UNMATCHED, $result['outcome'], 'one shipment carries it as our invoice number, the other as its warehouse barcode');
        $this->assertSame(FulfillmentStatus::Submitted, $asOurInvoiceNumber->fresh()->status);
        $this->assertSame(FulfillmentStatus::Submitted, $asAWarehouseBarcode->fresh()->status);
        $this->assertNull($asOurInvoiceNumber->fresh()->provider_secondary_reference);
    }

    /**
     * The event passes every plausibility check — it names OUR invoice number and our cash — and the package it names is still
     * another shipment's. A reference is learned, never taken.
     */
    public function test_a_package_barcode_another_shipment_already_carries_is_never_moved_onto_this_one(): void
    {
        $theirs = $this->submitted(['provider_secondary_reference' => self::PACKAGE_BARCODE], orderId: 100003);
        $ours = $this->productionShipment();
        $event = $this->adapter()->normalizeWebhook('lastmile', $this->lastmilePayload(
            'OUT_FOR_DELIVERY',
            barcode: self::PACKAGE_BARCODE,
            extra: ['invoiceNumber' => self::OUR_INVOICE_NUMBER],
        ));

        $this->adapter()->learnReferences($ours, $event);

        $this->assertNull($ours->fresh()->provider_secondary_reference, 'that package belongs to the other shipment and stays there');
        $this->assertNull($ours->fresh()->tracking_number);
        $this->assertSame(self::PACKAGE_BARCODE, $theirs->fresh()->provider_secondary_reference);
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

    public function test_postponed_and_failed_deliveries_keep_the_date_and_status_but_not_the_couriers_free_text(): void
    {
        $fulfillment = $this->submitted();
        $now = (int) (microtime(true) * 1000);

        $this->receive('lastmile', $this->lastmilePayload('POSTPONED_DELIVERY', time: $now, extra: ['notes' => 'customer asked for tomorrow', 'postponedDeliveryDate' => '2026-02-20T12:09:17.563Z']));
        $postponed = $fulfillment->fresh();
        $this->assertSame(FulfillmentStatus::DeliveryPostponed, $postponed->status);
        $this->assertSame('2026-02-20T12:09:17.563Z', $postponed->metaValue('postponed_until'));
        $this->assertNull($postponed->metaValue('last_note'), 'nothing reads a courier\'s note, and it can name or describe the customer: it is not kept');

        $this->receive('lastmile', $this->lastmilePayload('FAILED', time: $now + 1000, extra: ['notes' => 'order refused']));
        $this->assertSame(FulfillmentStatus::DeliveryFailed, $fulfillment->fresh()->status);
        $this->assertNull($fulfillment->fresh()->metaValue('last_note'));
    }

    public function test_proof_of_delivery_links_are_not_kept_on_the_shipment(): void
    {
        $fulfillment = $this->submitted();
        Http::fake([self::V2 . '/guests/packages/status*' => Http::response(['status' => 'DELIVERED_TO_RECIPIENT'])]);

        $this->receive('lastmile', $this->lastmilePayload('DELIVERED_TO_RECIPIENT', extra: ['attachmentUrls' => ['https://bucket.example/proof.png', 'http://insecure.example/x.png', 'javascript:alert(1)']]));

        $this->assertSame(FulfillmentStatus::Delivered, $fulfillment->fresh()->status);
        $this->assertNull($fulfillment->fresh()->metaValue('proof_urls'), 'an image of the delivery can show a person, and nothing in the application opens it');
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

    public function test_a_body_whose_values_are_nested_where_text_was_documented_is_read_as_far_as_it_can_be_and_never_breaks_the_receiver(): void
    {
        $fulfillment = $this->submitted();

        foreach ([['time' => ['ms' => 1]], ['packageId' => ['nested' => 1]], ['invoiceNumber' => ['x']], ['postponedDeliveryDate' => ['y']], ['postponedDate' => ['y']], ['time' => ['ms' => 1], 'packageId' => ['a'], 'invoiceNumber' => ['b'], 'postponedDeliveryDate' => ['c'], 'notes' => ['d']]] as $extra) {
            $received = $this->receive('lastmile', $this->lastmilePayload('OUT_FOR_DELIVERY', extra: $extra));

            $this->assertContains($received['status'], ['accepted', 'duplicate'], json_encode($extra) . ' (a body that adds nothing the first one lacked is the same event)');
        }
        $this->assertNotNull($fulfillment->fresh()->provider_status);
    }

    public function test_ingest_queues_processing_and_stores_a_redacted_payload(): void
    {
        $this->submitted();

        $this->processor()->ingest(ProviderCode::TorodPoint, 'lastmile', $this->lastmilePayload('OUT_FOR_DELIVERY'));

        Queue::assertPushed(ProcessProviderEvent::class);
        $stored = json_encode(ProviderFulfillmentEvent::first()->payload);
        $this->assertStringNotContainsString('0598985000', $stored, 'the driver phone is masked in the stored copy');
    }

    public function test_the_stored_copy_of_a_webhook_holds_the_fields_that_are_read_and_nothing_else(): void
    {
        $fulfillment = $this->submitted();
        $received = $this->processor()->ingest(ProviderCode::TorodPoint, 'lastmile', $this->lastmilePayload('OUT_FOR_DELIVERY', extra: [
            'receiverName' => 'Sara Alharbi', 'receiver' => ['name' => 'Sara', 'address' => 'King Fahd Rd 12'], 'customer_mobile' => '0551234567', 'consignee_phone' => '0551234567',
            'driverName' => 'Khalid Driver', 'courierEmail' => 'khalid@example.test', 'attachmentUrls' => ['https://example-bucket.s3.amazonaws.com/proof.png'],
        ]));

        $stored = ProviderFulfillmentEvent::findOrFail($received['event_id'])->payload;

        $this->assertEqualsCanonicalizing(['packageId', 'newStatus', 'barcode', 'cod', 'invoiceNumber', 'time'], array_keys($stored));
        foreach (['Sara', 'King Fahd', '0551234567', 'Khalid', 'khalid@example.test', 'proof.png'] as $personal) {
            $this->assertStringNotContainsString($personal, json_encode($stored), $personal);
        }
        $this->assertSame(EventOutcome::APPLIED, $this->processor()->process($received['event_id']), 'the reduced copy is all that is needed to apply it');
        $this->assertSame(FulfillmentStatus::OutForDelivery, $fulfillment->fresh()->status);
    }

    public function test_a_couriers_note_that_explains_an_issue_reaches_the_operator_without_its_phone_numbers(): void
    {
        $fulfillment = $this->submitted();

        $received = $this->receive('lastmile', $this->lastmilePayload('OPENED_ISSUE_AND_WAITING_FOR_MANAGEMENT', extra: ['notes' => 'Customer refused the parcel, call 0551234567 / 0559876543']));

        $this->assertSame(FulfillmentStatus::NeedsAttention, $fulfillment->fresh()->status);
        $this->assertStringContainsString('Customer refused the parcel', (string) $fulfillment->fresh()->last_error_message);
        foreach (['0551234567', '0559876543'] as $number) {
            $this->assertStringNotContainsString($number, (string) $fulfillment->fresh()->last_error_message);
            $this->assertStringNotContainsString($number, json_encode(ProviderFulfillmentEvent::findOrFail($received['event_id'])->payload));
        }
    }

    public function test_a_status_word_that_carries_a_phone_number_is_stored_without_it(): void
    {
        $fulfillment = $this->submitted();

        $received = $this->receive('lastmile', $this->lastmilePayload('Delivered to Sara 0551234567'));

        $event = ProviderFulfillmentEvent::findOrFail($received['event_id']);
        foreach ([(string) $fulfillment->fresh()->provider_status, (string) $event->provider_status, (string) $event->outcome_note, json_encode($event->payload)] as $stored) {
            $this->assertStringNotContainsString('0551234567', $stored);
        }
        $this->assertStringContainsString('Delivered to Sara', (string) $fulfillment->fresh()->provider_status);
    }

    public function test_the_sync_state_records_the_last_received_update(): void
    {
        $this->submitted();

        $this->processor()->ingest(ProviderCode::TorodPoint, 'lastmile', $this->lastmilePayload('OUT_FOR_DELIVERY'));

        $this->assertNotNull(DB::table('provider_sync_states')->where(['provider' => 'torod_point', 'capability' => 'webhooks'])->value('last_received_at'));
    }
}
