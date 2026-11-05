<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Services\Fulfillment\ShipmentTracker;
use Carbon\Carbon;

/**
 * Regression: provider times are UTC instants, Eloquent stores the Carbon's own wall-clock and reads it back
 * in the application timezone. Without normalising, stored event times drifted by the application's UTC
 * offset and the "older than the last event" check passed events it should have refused.
 */
class ProviderTimeTest extends TorodTestCase
{
    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTimezone = date_default_timezone_get();
        date_default_timezone_set('Asia/Riyadh');
        config(['app.timezone' => 'Asia/Riyadh']);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);
        parent::tearDown();
    }

    public function test_a_utc_provider_instant_survives_storage_and_comparison_in_a_non_utc_application(): void
    {
        $fulfillment = $this->torodFulfillment();
        $fulfillment->forceFill(['status' => FulfillmentStatus::Submitted])->save();
        $instant = Carbon::parse('2026-03-01 09:00:00', 'UTC');

        app(ShipmentTracker::class)->applyProviderStatus($fulfillment->id, FulfillmentStatus::OutForDelivery, $instant, 'webhook', 'OUT_FOR_DELIVERY');

        $this->assertSame($instant->getTimestamp(), $fulfillment->fresh()->last_event_at->getTimestamp(), 'the instant must round-trip unchanged');
    }

    public function test_an_event_one_hour_older_in_utc_is_stale_in_a_non_utc_application(): void
    {
        $fulfillment = $this->torodFulfillment();
        $fulfillment->forceFill(['status' => FulfillmentStatus::Submitted])->save();
        $tracker = app(ShipmentTracker::class);

        $tracker->applyProviderStatus($fulfillment->id, FulfillmentStatus::OutForDelivery, Carbon::parse('2026-03-01 09:00:00', 'UTC'), 'webhook', 'OUT_FOR_DELIVERY');
        $outcome = $tracker->applyProviderStatus($fulfillment->id, FulfillmentStatus::InTransit, Carbon::parse('2026-03-01 08:00:00', 'UTC'), 'webhook', 'SCANNED_BY_DRIVER_AND_IN_CAR');

        $this->assertSame('ignored_stale', $outcome);
        $this->assertSame(FulfillmentStatus::OutForDelivery, $fulfillment->fresh()->status);
    }

    public function test_epoch_milliseconds_become_the_same_instant_in_the_application_timezone(): void
    {
        $webhookMs = Carbon::parse('2026-03-01 09:00:00', 'UTC')->getTimestamp() * 1000;

        $converted = \App\Services\Fulfillment\Support\AppTime::fromMilliseconds($webhookMs);

        $this->assertSame($webhookMs / 1000, $converted->getTimestamp());
        $this->assertSame('Asia/Riyadh', $converted->getTimezone()->getName());
        $this->assertSame('2026-03-01 12:00:00', $converted->format('Y-m-d H:i:s'));
    }
}
