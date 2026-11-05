<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\EventOutcome;
use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\HoldReason;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\Order;
use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\Contracts\ProviderAdapter;
use App\Services\Fulfillment\Data\SubmissionResult;
use App\Services\Fulfillment\Exceptions\ProviderUnavailableException;
use App\Services\Fulfillment\Support\PiiRedactor;
use App\Services\Fulfillment\Support\ProviderConfig;
use App\Services\Fulfillment\Support\ProviderSyncRecorder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends one provider fulfillment to its provider, exactly once as far as the provider can tell.
 *
 * Three phases, deliberately separated:
 *
 *  1. CLAIM    — under a row lock, re-check everything (is the order still valid, is the provider on and
 *                configured, does the payload pass preflight) and flip pending → dispatching with a fresh
 *                claim token. Only one worker can win this.
 *  2. SEND     — the HTTP call, outside any database transaction.
 *  3. SETTLE   — under the lock again, record the honest outcome.
 *
 * The rule that prevents duplicates: a result that is not a definite "provider did not take it" is never
 * answered with a second send. A timeout, a dropped connection, a 5xx or a crashed worker all land in
 * `reconcile_pending`, and only reconciliation — which asks the provider — can move it on.
 */
class ProviderOrderDispatcher
{
    /** Holds that clear themselves once the underlying setting or data is fixed; the sweep retries them. */
    public const AUTO_RELEASABLE = [
        HoldReason::PROVIDER_DISABLED,
        HoldReason::EXTERNAL_WRITES_DISABLED,
        HoldReason::PROVIDER_NOT_READY,
        HoldReason::ACKNOWLEDGEMENT_REQUIRED,
        HoldReason::MAPPING_MISSING,
        HoldReason::MAPPING_NOT_APPROVED,
        HoldReason::STOCK_UNAVAILABLE,
        HoldReason::CITY_UNMAPPED,
        HoldReason::ADDRESS_INCOMPLETE,
        HoldReason::PHONE_INVALID,
    ];

    public const SKIPPED = 'skipped';
    public const UNAVAILABLE = 'unavailable';

    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ProviderConnectionManager $connections,
        private readonly OrderEligibility $eligibility,
        private readonly ShipmentTracker $tracker,
        private readonly FulfillmentQueue $queue,
        private readonly ProviderSyncRecorder $sync,
        private readonly FulfillmentCancellation $cancellation,
        private readonly FulfillmentMoney $money,
    ) {
    }

    /** @return string SubmissionResult::* outcome, or self::SKIPPED / self::UNAVAILABLE */
    public function dispatch(int $fulfillmentId, bool $manual = false): string
    {
        $claim = $this->claim($fulfillmentId, $manual);
        if ($claim === null) {
            return self::SKIPPED;
        }

        [$id, $token] = $claim;
        $fulfillment = ProviderFulfillment::with('items')->findOrFail($id);
        $adapter = $this->registry->adapter($fulfillment->provider);

        $this->sync->attempted($fulfillment->provider, ProviderSyncRecorder::ORDERS);

        try {
            $result = $adapter->submit($fulfillment);
        } catch (ProviderUnavailableException $exception) {
            $this->tracker->unclaim($id, $token);

            return self::UNAVAILABLE;
        } catch (Throwable $exception) {
            // We cannot know whether the request left before this threw, so it is NOT safe to resend.
            Log::error('Provider adapter threw during submit; treating the outcome as unknown.', [
                'fulfillment_id' => $id,
                'exception' => $exception::class,
                'error' => PiiRedactor::scrub($exception->getMessage()),
            ]);
            $result = SubmissionResult::ambiguous('adapter_exception', $exception::class);
        }

        $this->settle($id, $token, $fulfillment->provider, $result);

        return $result->outcome;
    }

    /** @return array{0:int,1:string}|null */
    private function claim(int $fulfillmentId, bool $manual): ?array
    {
        return DB::transaction(function () use ($fulfillmentId, $manual): ?array {
            $fulfillment = ProviderFulfillment::whereKey($fulfillmentId)->lockForUpdate()->first();
            if ($fulfillment === null || !$this->isDispatchable($fulfillment, $manual)) {
                return null;
            }

            $provider = $fulfillment->providerCode();
            if ($provider === null) {
                return null;
            }

            if ($fulfillment->provider_reference !== null || $fulfillment->provider_secondary_reference !== null) {
                $this->tracker->hold($fulfillment->id, HoldReason::ALREADY_WITH_PROVIDER, 'The provider already gave this shipment a reference; it is linked, never sent again.');

                return null;
            }

            $config = $this->connections->config($provider);
            $order = Order::find($fulfillment->order_id);

            if ($this->eligibility->isClosed($order)) {
                $this->tracker->cancelLocally($fulfillment->id, 'parent_order_closed');

                return null;
            }
            if (!$this->eligibility->isEligible($order, $config)) {
                return null;
            }

            $adapter = $this->registry->adapter($provider);
            $hold = $this->providerHold($fulfillment, $order, $config, $adapter, $manual) ?? $this->reassessMoney($fulfillment, $order, $provider);
            if ($hold === null) {
                $preflight = $adapter->preflight($fulfillment->loadMissing('items'));
                $hold = $preflight->ok ? null : [$preflight->holdReason, $preflight->detail];
            }
            if ($hold !== null) {
                $this->tracker->hold($fulfillment->id, $hold[0], $hold[1]);

                return null;
            }

            $token = (string) Str::uuid();
            $fulfillment->forceFill([
                'status' => FulfillmentStatus::Dispatching,
                'attempts' => $fulfillment->attempts + 1,
                'claim_token' => $token,
                'claimed_at' => now(),
                'last_attempt_at' => now(),
                'next_attempt_at' => null,
                'hold_reason' => null,
            ])->save();

            return [$fulfillment->id, $token];
        });
    }

    private function isDispatchable(ProviderFulfillment $fulfillment, bool $manual): bool
    {
        return match ($fulfillment->status) {
            FulfillmentStatus::Pending => true,
            FulfillmentStatus::RetryWait => $fulfillment->next_attempt_at === null || $fulfillment->next_attempt_at->lte(now()),
            FulfillmentStatus::Held => in_array($fulfillment->hold_reason, self::AUTO_RELEASABLE, true),
            FulfillmentStatus::DryRun => $manual,
            default => false,
        };
    }

    /** @return array{0:string,1:?string}|null [reason, detail] when the provider itself is not ready to receive orders */
    private function providerHold(ProviderFulfillment $fulfillment, ?Order $order, ProviderConfig $config, ProviderAdapter $adapter, bool $manual): ?array
    {
        if (!$this->connections->isGloballyEnabled() || !$config->enabled || !$config->bool('dispatch_enabled')) {
            return [HoldReason::PROVIDER_DISABLED, null];
        }

        // A dry run is computed locally and sends nothing, so it is allowed on a host that may not write. A real send is not:
        // it is held (not failed, not retried in a loop) until the environment allows writes.
        if (!$config->bool('dry_run', true) && !$this->connections->externalWritesEnabled()) {
            return [HoldReason::EXTERNAL_WRITES_DISABLED, null];
        }

        // "Send orders" is not "send them by themselves". Until an operator has verified the provider and switched automatic
        // sending on, an order that is due waits here for a person to release it, which sends that one order at once. A dry
        // run needs no approval: nothing leaves the host.
        if (!$manual && !$config->bool('dry_run', true) && !$config->bool('auto_dispatch', false)) {
            return [HoldReason::AWAITING_APPROVAL, null];
        }

        $readiness = $adapter->readiness();
        if (!$readiness->canUse('dispatch')) {
            return [HoldReason::PROVIDER_NOT_READY, implode(', ', $readiness->missing) ?: $readiness->capability('dispatch')?->reason];
        }

        $goLive = $config->get('go_live_at');
        if (!$manual && !$config->bool('dry_run', true) && $goLive && $order?->created_at !== null
            && $order->created_at->lt(Carbon::parse($goLive))) {
            return [HoldReason::BEFORE_GO_LIVE, null];
        }

        return null;
    }

    /**
     * The order may have been paid, part-paid, or had its cash-collection rules changed since the shipment was routed.
     * What is sent must be what is true now: a provider told to collect cash the customer has already paid collects it twice.
     *
     * @return array{0:string,1:?string}|null [reason, detail] when the money can no longer be assigned safely
     */
    private function reassessMoney(ProviderFulfillment $fulfillment, Order $order, ProviderCode $provider): ?array
    {
        $money = $this->money->assess($order, $provider, $fulfillment->loadMissing('items')->items, $this->money->allItems($order->id));
        if ($money->blockedReason !== null) {
            return [$money->blockedReason, null];
        }

        if ($money->differsFrom($fulfillment)) {
            $changes = $money->changesTo($fulfillment);
            $fulfillment->forceFill($money->columns())->save();
            $this->tracker->record($fulfillment->provider, 'system', 'money', $fulfillment->id, null, null, null, null, EventOutcome::APPLIED, 'money_reassessed_before_send', $changes);
        }

        return null;
    }

    private function settle(int $id, string $token, string $provider, SubmissionResult $result): void
    {
        // Acceptance is evidence the provider holds the order. It is recorded even if this worker lost
        // its claim in the meantime (recovery may have moved the row to reconcile_pending), because
        // dropping it would invite a duplicate send.
        if ($result->isAccepted()) {
            $this->tracker->recordAcceptance($id, $result);
            $this->sync->succeeded($provider, ProviderSyncRecorder::ORDERS);
            $this->cancellation->honourIntent($id);

            return;
        }

        $delay = null;
        $reconcile = false;

        DB::transaction(function () use ($id, $token, $result, &$delay, &$reconcile): void {
            $fulfillment = ProviderFulfillment::whereKey($id)->lockForUpdate()->first();
            if ($fulfillment === null || $fulfillment->claim_token !== $token || $fulfillment->status !== FulfillmentStatus::Dispatching) {
                Log::notice('Provider fulfillment result ignored: the claim was lost.', ['fulfillment_id' => $id, 'outcome' => $result->outcome]);

                return;
            }

            $message = (string) $result->message;
            match ($result->outcome) {
                SubmissionResult::REJECTED => $this->tracker->markFailed($id, (string) $result->errorCode, $message),
                SubmissionResult::DRY_RUN => $this->tracker->markDryRun($id, $result->meta),
                SubmissionResult::AMBIGUOUS => $this->awaitReconciliation($id, $result, $reconcile),
                default => $delay = $this->retryOrFail($fulfillment, $result),
            };
        });

        if ($result->outcome !== SubmissionResult::DRY_RUN) {
            $this->sync->failed($provider, ProviderSyncRecorder::ORDERS, "{$result->outcome}: {$result->errorCode} {$result->message}");
        }

        if ($delay !== null) {
            $this->queue->dispatchFulfillment($id, $delay);
        }
        if ($reconcile) {
            $this->queue->dispatchReconcile($id, 120);
        }
    }

    private function awaitReconciliation(int $id, SubmissionResult $result, bool &$reconcile): void
    {
        $this->tracker->awaitReconciliation($id, (string) $result->errorCode, (string) $result->message, now()->addMinutes(2));
        $reconcile = true;
    }

    /** @return int|null seconds until the next attempt, or null when attempts are exhausted */
    private function retryOrFail(ProviderFulfillment $fulfillment, SubmissionResult $result): ?int
    {
        if ($fulfillment->attempts >= (int) config('fulfillment.retry.max_attempts', 5)) {
            $this->tracker->markFailed($fulfillment->id, 'attempts_exhausted', "{$result->errorCode}: {$result->message}");

            return null;
        }

        $backoff = array_values((array) config('fulfillment.retry.backoff_seconds', [60, 300, 900]));
        $seconds = (int) ($backoff[min($fulfillment->attempts - 1, count($backoff) - 1)] ?? 60);
        $this->tracker->scheduleRetry($fulfillment->id, (string) $result->errorCode, (string) $result->message, now()->addSeconds($seconds));

        return $seconds;
    }
}
