<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\Contracts\ReconcilesSubmissions;
use App\Services\Fulfillment\Exceptions\ProviderUnavailableException;
use App\Services\Fulfillment\Support\ProviderSyncRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolves a submission whose outcome we do not know, by asking the provider — never by sending again.
 *
 * It can only ever prove the provider HOLDS the order (then the acceptance is recorded) or fail to find
 * it. Failing to find it is not proof it does not exist — a list may be paged, a record may be late — so
 * "not found" keeps the fulfillment in reconcile_pending, backing off, until a human decides.
 */
class ReconciliationService
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ShipmentTracker $tracker,
        private readonly FulfillmentQueue $queue,
        private readonly ProviderSyncRecorder $sync,
    ) {
    }

    public function reconcile(int $fulfillmentId): string
    {
        $fulfillment = ProviderFulfillment::with('items')->find($fulfillmentId);
        if ($fulfillment === null || $fulfillment->status !== FulfillmentStatus::ReconcilePending) {
            return 'noop';
        }

        $adapter = $this->registry->adapter($fulfillment->provider);

        try {
            $result = $adapter instanceof ReconcilesSubmissions
                ? $adapter->reconcileSubmission($fulfillment)
                : null;
        } catch (ProviderUnavailableException) {
            return 'unavailable';
        } catch (Throwable $exception) {
            Log::warning('Reconciliation attempt failed.', ['fulfillment_id' => $fulfillmentId, 'error' => $exception->getMessage()]);
            $result = null;
        }

        $this->sync->reconciled($fulfillment->provider);

        if ($result !== null && $result->isAccepted()) {
            $this->tracker->recordAcceptance($fulfillment->id, $result);
            if (ProviderFulfillment::find($fulfillment->id)?->isCancelRequested()) {
                $this->queue->dispatchCancel($fulfillment->id);
            }

            return 'accepted';
        }

        $this->backOff($fulfillment);

        return 'still_unknown';
    }

    private function backOff(ProviderFulfillment $fulfillment): void
    {
        $attempts = $fulfillment->reconcile_attempts + 1;
        $exhausted = $attempts >= (int) config('fulfillment.retry.max_reconcile_attempts', 6);

        DB::table('provider_fulfillments')->where('id', $fulfillment->id)->update([
            'reconcile_attempts' => $attempts,
            'next_attempt_at' => $exhausted ? null : now()->addMinutes(5 * $attempts),
            'last_error_code' => $exhausted ? 'manual_reconciliation_required' : $fulfillment->last_error_code,
            'updated_at' => now(),
        ]);

        if (!$exhausted) {
            $this->queue->dispatchReconcile($fulfillment->id, 300 * $attempts);
        }
    }
}
