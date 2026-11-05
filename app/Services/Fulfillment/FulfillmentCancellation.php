<?php

namespace App\Services\Fulfillment;

use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\Data\CancellationResult;
use App\Services\Fulfillment\Exceptions\ProviderUnavailableException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cancels ONE provider fulfillment. Never touches another provider's fulfillment of the same order:
 * the caller (a parent-order cancellation, or an operator) decides which fulfillments to cancel.
 *
 *  - Not yet with the provider (pending, held, retry, dry-run, failed): cancelled locally, nothing to call.
 *  - Outcome unknown (dispatching, reconcile_pending): we cannot cancel what we cannot find. The wish is written
 *    down and honoured the moment the provider is proven to hold the order.
 *  - With the provider: ask it, once at a time. "Requested" is recorded only when the provider ACKNOWLEDGED the
 *    request, and even then the fulfillment becomes cancelled only when the provider reports it (webhook or poll).
 *    A failed call is repeated with a backoff; a refusal, an unsupported cancel or a call that keeps failing blocks
 *    automatic cancellation and is surfaced to a person, who cancels at the provider and marks it cancelled here.
 *
 * Whatever is decided is decided under the row lock; the provider is called outside it.
 */
class FulfillmentCancellation
{
    public const NOOP = 'noop';
    public const CANCELLED_LOCALLY = 'cancelled_locally';
    public const DEFERRED = 'deferred_until_reconciled';
    public const UNAVAILABLE = 'unavailable';

    /** Retry a provider that could not be reached at all after this long. */
    private const UNAVAILABLE_RETRY_MINUTES = 5;

    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ShipmentTracker $tracker,
        private readonly FulfillmentQueue $queue,
    ) {
    }

    /** @return string one of this class's outcome constants, a CancelLease reason, or a CancellationResult outcome */
    public function cancel(int $fulfillmentId, bool $manual = false): string
    {
        $decision = $this->decide($fulfillmentId, $manual);
        if (is_string($decision)) {
            return $decision;
        }

        return $this->askProvider($decision);
    }

    /**
     * A cancellation wanted while the outcome of the send was unknown can be acted on now that the provider is
     * known to hold the order. Called after an acceptance is recorded, by whichever path recorded it.
     */
    public function honourIntent(int $fulfillmentId): void
    {
        if (ProviderFulfillment::find($fulfillmentId)?->wantsCancel()) {
            $this->queue->dispatchCancel($fulfillmentId);
        }
    }

    /**
     * Decide under the lock. A string is a final answer; a fulfillment is a granted lease to call the provider.
     */
    private function decide(int $fulfillmentId, bool $manual): string|ProviderFulfillment
    {
        return DB::transaction(function () use ($fulfillmentId, $manual): string|ProviderFulfillment {
            $fulfillment = ProviderFulfillment::whereKey($fulfillmentId)->lockForUpdate()->first();
            if ($fulfillment === null || $fulfillment->isTerminal()) {
                return self::NOOP;
            }

            if ($fulfillment->status->isCancellableLocally()) {
                $this->tracker->cancelLocally($fulfillment->id, 'cancel_requested_before_dispatch');

                return self::CANCELLED_LOCALLY;
            }

            if ($fulfillment->status->isSendOutcomeUnknown()) {
                $this->tracker->noteCancelIntent($fulfillment->id);

                return self::DEFERRED;
            }

            $lease = $this->tracker->leaseCancelAttempt($fulfillment->id, $manual);

            return $lease->granted ? $fulfillment->refresh() : (string) $lease->reason;
        });
    }

    private function askProvider(ProviderFulfillment $fulfillment): string
    {
        try {
            $result = $this->registry->adapter($fulfillment->provider)->cancel($fulfillment);
        } catch (ProviderUnavailableException) {
            $this->tracker->releaseCancelLease($fulfillment->id, now()->addMinutes(self::UNAVAILABLE_RETRY_MINUTES));

            return self::UNAVAILABLE;
        } catch (Throwable $exception) {
            Log::error('Provider adapter threw during cancel; treating it as a failed attempt.', [
                'fulfillment_id' => $fulfillment->id,
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);
            $result = CancellationResult::failed($exception::class);
        }

        $this->tracker->settleCancelAttempt($fulfillment->id, $result, $this->retryAt($fulfillment->fresh(), $result));

        return $result->outcome;
    }

    /** When a failed attempt may be repeated, or null when it is not worth repeating (or the budget is spent). */
    private function retryAt(ProviderFulfillment $fulfillment, CancellationResult $result): ?\DateTimeInterface
    {
        $attempts = $fulfillment->cancel_attempts;
        if ($result->outcome !== CancellationResult::FAILED || $attempts >= (int) config('fulfillment.retry.max_attempts', 5)) {
            return null;
        }

        $backoff = array_values((array) config('fulfillment.retry.backoff_seconds', [60, 300, 900]));

        return now()->addSeconds((int) ($backoff[min($attempts - 1, count($backoff) - 1)] ?? 60));
    }
}
