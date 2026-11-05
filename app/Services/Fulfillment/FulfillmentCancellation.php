<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\Data\CancellationResult;
use App\Services\Fulfillment\Exceptions\ProviderUnavailableException;
use App\Enums\Fulfillment\EventOutcome;

/**
 * Cancels ONE provider fulfillment. Never touches another provider's fulfillment of the same order:
 * the caller (a parent-order cancellation, or an operator) decides which fulfillments to cancel.
 *
 *  - Not yet with the provider (pending, held, retry, dry-run, failed): cancelled locally, nothing to call.
 *  - Outcome unknown (dispatching, reconcile_pending): we cannot cancel what we cannot find. The request is
 *    remembered, and honoured the moment reconciliation proves the provider holds it.
 *  - With the provider: ask it. An answer of "ok" only means the request was RECEIVED — the fulfillment
 *    becomes cancelled when the provider reports it so (webhook or status poll), never before.
 */
class FulfillmentCancellation
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ShipmentTracker $tracker,
    ) {
    }

    public function cancel(int $fulfillmentId): string
    {
        $fulfillment = ProviderFulfillment::find($fulfillmentId);
        if ($fulfillment === null || $fulfillment->isTerminal()) {
            return 'noop';
        }

        if (in_array($fulfillment->status, [
            FulfillmentStatus::Pending, FulfillmentStatus::Held, FulfillmentStatus::RetryWait,
            FulfillmentStatus::DryRun, FulfillmentStatus::Failed,
        ], true)) {
            $this->tracker->cancelLocally($fulfillment->id, 'cancel_requested_before_dispatch');

            return 'cancelled_locally';
        }

        if (in_array($fulfillment->status, [FulfillmentStatus::Dispatching, FulfillmentStatus::ReconcilePending], true)) {
            $this->tracker->noteCancelRequested($fulfillment->id);

            return 'deferred_until_reconciled';
        }

        try {
            $result = $this->registry->adapter($fulfillment->provider)->cancel($fulfillment);
        } catch (ProviderUnavailableException) {
            return 'unavailable';
        }

        $this->tracker->noteCancelRequested($fulfillment->id);
        $this->tracker->record(
            provider: $fulfillment->provider,
            source: 'system',
            eventType: 'cancel',
            fulfillmentId: $fulfillment->id,
            externalReference: $fulfillment->provider_reference,
            providerStatus: null,
            internalStatus: null,
            occurredAt: null,
            outcome: $result->outcome === CancellationResult::REQUESTED ? EventOutcome::APPLIED : EventOutcome::FAILED,
            note: $result->outcome . ($result->message ? ': ' . $result->message : ''),
        );

        return $result->outcome;
    }
}
