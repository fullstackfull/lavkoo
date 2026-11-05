<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderFulfillmentEvent;
use App\Services\Fulfillment\ShipmentTracker;
use App\Services\Fulfillment\WebhookEventProcessor;

/**
 * The words a provider uses for a status are stored on the shipment and in its history. A provider that puts a phone number or a
 * name in one of them does not get it kept: whoever reports the status, the stored words are scrubbed.
 */
class StatusTextHygieneTest extends FulfillmentTestCase
{
    private const WORDS = 'Delivered to Sara 0551234567';

    private function sentShipment(): \App\Models\ProviderFulfillment
    {
        $this->makeOrder(100);
        $this->addLine(100, 2, 1, 35, ProviderCode::TorodPoint);
        app(\App\Services\Fulfillment\FulfillmentRouter::class)->route(100);
        $shipment = $this->fulfillmentFor(100, ProviderCode::TorodPoint);
        $shipment->forceFill(['status' => FulfillmentStatus::Submitted, 'provider_reference' => 'O-100', 'submitted_at' => now()])->save();

        return $shipment->fresh();
    }

    public function test_a_status_a_poll_reports_is_stored_and_recorded_without_contact_details(): void
    {
        $shipment = $this->sentShipment();
        $tracker = app(ShipmentTracker::class);

        $tracker->noteUnmappedStatus($shipment->id, self::WORDS);
        $tracker->noteUnmappedStatus($shipment->id, self::WORDS);
        $tracker->applyProviderStatus($shipment->id, FulfillmentStatus::InTransit, now(), 'poll', self::WORDS);

        $stored = [$shipment->fresh()->provider_status, ...ProviderFulfillmentEvent::pluck('provider_status')->all()];
        $this->assertCount(3, $stored, 'one unmapped note (the repeat is ignored) and one applied status');
        foreach ($stored as $words) {
            $this->assertStringNotContainsString('0551234567', (string) $words);
            $this->assertStringContainsString('Delivered to Sara', (string) $words);
        }
    }

    public function test_a_status_a_webhook_carries_is_stored_without_contact_details_whatever_the_adapter_kept(): void
    {
        $shipment = $this->sentShipment();
        $processor = app(WebhookEventProcessor::class);

        $ingested = $processor->ingest(ProviderCode::TorodPoint, 'any', ['ref' => 'O-100', 'status' => self::WORDS]);
        $processor->process($ingested['event_id']);

        $event = ProviderFulfillmentEvent::findOrFail($ingested['event_id']);
        foreach ([(string) $shipment->fresh()->provider_status, (string) $event->provider_status, (string) $event->outcome_note] as $stored) {
            $this->assertStringNotContainsString('0551234567', $stored);
        }
        $this->assertStringContainsString('Delivered to Sara', (string) $shipment->fresh()->provider_status);
    }

    public function test_a_warehouse_status_that_is_longer_than_its_column_is_cut_not_refused_by_the_database(): void
    {
        $shipment = $this->sentShipment();
        $processor = app(WebhookEventProcessor::class);

        $ingested = $processor->ingest(ProviderCode::TorodPoint, 'any', ['ref' => 'O-100', 'status' => 'PICKED', 'informational' => true, 'warehouse' => 'picked by Sara 0551234567 and packed for the long drive']);
        $processor->process($ingested['event_id']);

        $stored = (string) $shipment->fresh()->warehouse_status;
        $this->assertLessThanOrEqual(32, mb_strlen($stored));
        $this->assertStringNotContainsString('0551234567', $stored);
        $this->assertStringStartsWith('picked by sara', $stored);
    }
}
