<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\EventOutcome;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentEvent;
use App\Services\Fulfillment\Contracts\HandlesWebhooks;
use App\Services\Fulfillment\Support\ProviderSyncRecorder;
use App\Services\Fulfillment\Support\SecretRedactor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Inbound provider events, in two steps so the HTTP endpoint stays trivial and safe to repeat.
 *
 *  ingest()  Called by the webhook controller. Stores the event exactly once (the dedupe key is unique:
 *            a provider that retries gets the same answer and no second row) and queues processing.
 *  process() Runs from the queue. Finds OUR fulfillment, optionally confirms the claim with the provider,
 *            and hands the status to ShipmentTracker, whose state machine refuses stale and terminal moves.
 *
 * An event that matches nothing is kept, not discarded: it is retried while it is young, because the
 * reference that identifies it (a barcode we learn later) may simply not be known yet.
 */
class WebhookEventProcessor
{
    public const UNMATCHED_RETRY_HOURS = 24;

    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ShipmentTracker $tracker,
        private readonly FulfillmentQueue $queue,
        private readonly ProviderSyncRecorder $sync,
    ) {
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return array{status: string, event_id: int|null}
     */
    public function ingest(ProviderCode $provider, string $type, array $payload): array
    {
        $adapter = $this->registry->adapter($provider);
        if (!$adapter instanceof HandlesWebhooks) {
            return ['status' => 'unsupported', 'event_id' => null];
        }

        $normalized = $adapter->normalizeWebhook($type, $payload);
        if ($normalized === null) {
            return ['status' => 'invalid', 'event_id' => null];
        }

        $this->sync->received($provider->value, ProviderSyncRecorder::WEBHOOKS);

        try {
            $event = ProviderFulfillmentEvent::create([
                'provider' => $provider->value,
                'source' => 'webhook',
                'event_type' => $type,
                'dedupe_key' => $normalized->dedupeKey,
                'external_reference' => $normalized->reference('barcode'),
                'provider_status' => $normalized->providerStatus,
                'occurred_at' => $normalized->occurredAt,
                'received_at' => now(),
                'outcome' => EventOutcome::RECEIVED,
                'payload' => SecretRedactor::redact($payload),
            ]);
        } catch (UniqueConstraintViolationException) {
            return ['status' => 'duplicate', 'event_id' => null];
        }

        $this->queue->dispatchEvent($event->id);

        return ['status' => 'accepted', 'event_id' => $event->id];
    }

    /** Process one stored event. Returns the outcome recorded. */
    public function process(int $eventId): string
    {
        $event = ProviderFulfillmentEvent::find($eventId);
        if ($event === null || !in_array($event->outcome, [EventOutcome::RECEIVED, EventOutcome::IGNORED_UNMATCHED], true)) {
            return $event?->outcome ?? EventOutcome::FAILED;
        }

        $provider = ProviderCode::from($event->provider);
        $adapter = $this->registry->adapter($provider);
        if (!$adapter instanceof HandlesWebhooks) {
            return $this->close($event, EventOutcome::FAILED, 'adapter_cannot_handle_webhooks');
        }

        $normalized = $adapter->normalizeWebhook($event->event_type, (array) $event->payload);
        if ($normalized === null) {
            return $this->close($event, EventOutcome::FAILED, 'payload_not_understood');
        }

        $fulfillment = $adapter->locateFulfillment($normalized);
        if ($fulfillment === null) {
            return $this->close($event, EventOutcome::IGNORED_UNMATCHED, 'no_fulfillment_for_references');
        }
        if ($fulfillment->provider !== $provider->value) {
            // Never apply one provider's event to another provider's fulfillment.
            return $this->close($event, EventOutcome::IGNORED_UNMATCHED, 'provider_mismatch');
        }

        $adapter->learnReferences($fulfillment, $normalized);
        $fulfillment = ProviderFulfillment::findOrFail($fulfillment->id);

        if ($normalized->informational) {
            return $this->applyInformational($event, $fulfillment, $normalized->warehouseStatus, $normalized->occurredAt);
        }

        $status = $normalized->providerStatus === null ? null : $adapter->mapStatus($normalized->providerStatus);
        if ($status === null) {
            DB::table('provider_fulfillments')->where('id', $fulfillment->id)->update(['provider_status' => $normalized->providerStatus, 'last_received_at' => now()]);

            return $this->close($event, EventOutcome::IGNORED_UNMAPPED, 'status_not_mapped:' . $normalized->providerStatus, $fulfillment->id);
        }

        $confirmed = $adapter->confirmStatus($fulfillment, $normalized);
        if ($confirmed === false) {
            return $this->close($event, EventOutcome::REJECTED_UNVERIFIED, 'provider_contradicts_event', $fulfillment->id);
        }

        return $this->tracker->applyProviderStatus(
            fulfillmentId: $fulfillment->id,
            incoming: $status,
            occurredAt: $normalized->occurredAt,
            source: 'webhook',
            providerStatus: $normalized->providerStatus,
            event: $event,
            extra: $normalized->meta,
            attentionNote: $normalized->note,
        );
    }

    /** Re-queue young events that matched nothing: the reference that identifies them may be known by now. */
    public function retryUnmatched(): int
    {
        $ids = ProviderFulfillmentEvent::where('outcome', EventOutcome::IGNORED_UNMATCHED)
            ->where('received_at', '>=', now()->subHours(self::UNMATCHED_RETRY_HOURS))
            ->orderBy('id')
            ->limit(200)
            ->pluck('id');

        foreach ($ids as $id) {
            $this->process((int) $id);
        }

        return $ids->count();
    }

    private function applyInformational(ProviderFulfillmentEvent $event, ProviderFulfillment $fulfillment, ?string $warehouseStatus, mixed $occurredAt): string
    {
        // Warehouse progress (created / picked / packed) is display-only. It is never delivery.
        if ($warehouseStatus !== null) {
            DB::table('provider_fulfillments')->where('id', $fulfillment->id)->update([
                'warehouse_status' => strtolower($warehouseStatus),
                'last_received_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $this->close($event, EventOutcome::INFORMATIONAL, 'warehouse_status:' . $warehouseStatus, $fulfillment->id);
    }

    private function close(ProviderFulfillmentEvent $event, string $outcome, string $note, ?int $fulfillmentId = null): string
    {
        $event->forceFill([
            'outcome' => $outcome,
            'outcome_note' => mb_substr($note, 0, 255),
            'provider_fulfillment_id' => $fulfillmentId ?? $event->provider_fulfillment_id,
            'processed_at' => now(),
        ])->save();

        return $outcome;
    }
}
