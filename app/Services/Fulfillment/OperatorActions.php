<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\EventOutcome;
use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\HoldReason;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentItem;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * What a human may do to a fulfillment, each guarded and each audited.
 *
 * These are the manual-recovery controls the dispatcher's own safety rules leave open on purpose: an
 * ambiguous submission is never re-sent automatically, so an operator who has CHECKED the provider's own
 * dashboard says so here, and the statement is recorded with who made it and why.
 */
class OperatorActions
{
    public function __construct(
        private readonly ShipmentTracker $tracker,
        private readonly FulfillmentQueue $queue,
        private readonly AuditLogger $audit,
    ) {
    }

    /** Held, dry-run or failed → pending, and sent now, bypassing the go-live cut-off. Never for a money hold. */
    public function release(int $fulfillmentId): void
    {
        $fulfillment = $this->find($fulfillmentId);
        if ($fulfillment->status === FulfillmentStatus::Held && in_array($fulfillment->hold_reason, [HoldReason::MIXED_COD_BLOCKED, HoldReason::CURRENCY_UNCONVERTIBLE], true)) {
            throw new InvalidArgumentException('A money hold is released by fixing its cause, not by an operator.');
        }
        if (!in_array($fulfillment->status, [FulfillmentStatus::Held, FulfillmentStatus::DryRun, FulfillmentStatus::Failed], true)) {
            throw new InvalidArgumentException('Only a held, dry-run or failed fulfillment can be released.');
        }

        $this->tracker->release($fulfillmentId, 'operator');
        DB::table('provider_fulfillments')->where('id', $fulfillmentId)->update(['attempts' => 0, 'reconcile_attempts' => 0]);
        $this->recordAudit('fulfillment.released', $fulfillment);
        $this->queue->dispatchFulfillment($fulfillmentId, 0, true);
    }

    /** The operator confirmed in the provider's own dashboard that the order does NOT exist there. */
    public function confirmNotCreated(int $fulfillmentId, string $reason): void
    {
        $fulfillment = $this->find($fulfillmentId);
        if ($fulfillment->status !== FulfillmentStatus::ReconcilePending) {
            throw new InvalidArgumentException('Only a fulfillment awaiting reconciliation can be confirmed as not created.');
        }

        DB::table('provider_fulfillments')->where('id', $fulfillmentId)->update([
            'status' => FulfillmentStatus::Pending->value,
            'hold_reason' => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'next_attempt_at' => null,
            'claim_token' => null,
            'attempts' => 0,
            'reconcile_attempts' => 0,
            'updated_at' => now(),
        ]);
        $this->tracker->record($fulfillment->provider, 'manual', 'reconcile', $fulfillmentId, null, null, FulfillmentStatus::Pending->value, null, EventOutcome::APPLIED, 'operator_confirmed_not_created: ' . $reason);
        $this->recordAudit('fulfillment.confirmed_not_created', $fulfillment, ['reason' => $reason]);
        $this->queue->dispatchFulfillment($fulfillmentId, 0, true);
    }

    /** The operator found the order at the provider and supplies its reference. */
    public function linkReference(int $fulfillmentId, string $reference, ?string $trackingNumber, string $reason): void
    {
        $fulfillment = $this->find($fulfillmentId);
        if (!in_array($fulfillment->status, [FulfillmentStatus::ReconcilePending, FulfillmentStatus::Failed, FulfillmentStatus::Held, FulfillmentStatus::Pending], true)) {
            throw new InvalidArgumentException('This fulfillment already has an outcome.');
        }
        if (trim($reference) === '') {
            throw new InvalidArgumentException('A provider reference is required.');
        }

        DB::transaction(function () use ($fulfillmentId, $reference, $trackingNumber): void {
            DB::table('provider_fulfillments')->where('id', $fulfillmentId)->update([
                'provider_reference' => trim($reference),
                'tracking_number' => $trackingNumber !== null && trim($trackingNumber) !== '' ? trim($trackingNumber) : null,
                'status' => FulfillmentStatus::Submitted->value,
                'submitted_at' => now(),
                'hold_reason' => null,
                'last_error_code' => null,
                'last_error_message' => null,
                'next_attempt_at' => null,
                'claim_token' => null,
                'updated_at' => now(),
            ]);
        });
        $this->tracker->record($fulfillment->provider, 'manual', 'reconcile', $fulfillmentId, $reference, null, FulfillmentStatus::Submitted->value, null, EventOutcome::APPLIED, 'operator_linked_reference: ' . $reason);
        $this->recordAudit('fulfillment.reference_linked', $fulfillment, ['reference' => $reference, 'reason' => $reason]);
    }

    /** Needed when a provider cannot report delivery itself (M5AZN documents no status webhook). */
    public function markDelivered(int $fulfillmentId, string $reason): void
    {
        $this->applyManual($fulfillmentId, FulfillmentStatus::Delivered, $reason, 'fulfillment.marked_delivered');
    }

    public function markCancelled(int $fulfillmentId, string $reason): void
    {
        $this->applyManual($fulfillmentId, FulfillmentStatus::Cancelled, $reason, 'fulfillment.marked_cancelled');
    }

    public function requestCancel(int $fulfillmentId): void
    {
        $fulfillment = $this->find($fulfillmentId);
        $this->recordAudit('fulfillment.cancel_requested', $fulfillment);
        $this->queue->dispatchCancel($fulfillmentId);
    }

    /** A cancelled fulfillment's items go back to unrouted, so routing creates generation + 1. */
    public function redispatchAsNewGeneration(int $fulfillmentId): void
    {
        $fulfillment = $this->find($fulfillmentId);
        if ($fulfillment->status !== FulfillmentStatus::Cancelled) {
            throw new InvalidArgumentException('Only a cancelled fulfillment can be re-dispatched as a new generation.');
        }

        ProviderFulfillmentItem::where('provider_fulfillment_id', $fulfillmentId)->update([
            'provider_fulfillment_id' => null,
            'status' => ProviderFulfillmentItem::STATUS_UNROUTED,
        ]);
        $this->recordAudit('fulfillment.redispatched_new_generation', $fulfillment);
        $this->queue->dispatchRoute($fulfillment->order_id);
    }

    private function applyManual(int $fulfillmentId, FulfillmentStatus $to, string $reason, string $action): void
    {
        $fulfillment = $this->find($fulfillmentId);
        if (!$fulfillment->status->isAcceptedByProvider() || $fulfillment->isTerminal()) {
            throw new InvalidArgumentException('Only a fulfillment the provider holds, and that is not finished, can be closed by hand.');
        }

        $this->tracker->applyProviderStatus($fulfillmentId, $to, now(), 'manual', 'manual:' . $to->value, null, ['manual_reason' => $reason]);
        $this->recordAudit($action, $fulfillment, ['reason' => $reason]);
    }

    private function find(int $fulfillmentId): ProviderFulfillment
    {
        return ProviderFulfillment::findOrFail($fulfillmentId);
    }

    /** @param array<string, mixed> $context */
    private function recordAudit(string $action, ProviderFulfillment $fulfillment, array $context = []): void
    {
        $this->audit->record(
            action: $action,
            subject: ['type' => 'provider_fulfillment', 'id' => $fulfillment->id],
            before: ['status' => $fulfillment->status->value],
            context: ['reference' => $fulfillment->reference, 'provider' => $fulfillment->provider] + $context,
        );
    }
}
