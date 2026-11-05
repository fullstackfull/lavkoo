<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\HoldReason;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentItem;
use App\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * What a human may do to a fulfillment, each guarded and each audited.
 *
 * These are the manual-recovery controls the dispatcher's own safety rules leave open on purpose: an
 * ambiguous submission is never re-sent automatically, so an operator who has CHECKED the provider's own
 * dashboard says so here, and the statement is recorded with who made it and why.
 *
 * Every change is made by the tracker under the row lock and only from the state the person was looking at: if a
 * worker, a webhook or a reconciliation moved the fulfillment in the meantime, the action is refused instead of
 * overwriting a fact that arrived later.
 */
class OperatorActions
{
    /** The cancellation was handed to a queue worker; its result will show on the shipment. */
    public const CANCEL_QUEUED = 'queued';

    /** Holds that clear when their cause is fixed; a person pressing "release" would only send what the rule stopped. */
    private const HOLDS_RELEASED_BY_THEIR_CAUSE = [
        HoldReason::MIXED_COD_BLOCKED,
        HoldReason::CURRENCY_UNCONVERTIBLE,
        HoldReason::ALREADY_WITH_PROVIDER,
    ];

    public function __construct(
        private readonly ShipmentTracker $tracker,
        private readonly FulfillmentQueue $queue,
        private readonly FulfillmentCancellation $cancellation,
        private readonly AuditLogger $audit,
    ) {
    }

    /** Held, dry-run or failed → pending, and sent now, bypassing the go-live cut-off. Never for a money hold. */
    public function release(int $fulfillmentId): void
    {
        $fulfillment = $this->find($fulfillmentId);
        if ($fulfillment->status === FulfillmentStatus::Held && in_array($fulfillment->hold_reason, self::HOLDS_RELEASED_BY_THEIR_CAUSE, true)) {
            throw new InvalidArgumentException('This hold is released by fixing its cause, not by an operator.');
        }
        if (!$this->tracker->release($fulfillmentId, 'operator', freshStart: true)) {
            throw new InvalidArgumentException('Only a held, dry-run or failed fulfillment can be released.');
        }

        $this->recordAudit('fulfillment.released', $fulfillment);
        $this->queue->dispatchFulfillment($fulfillmentId, 0, true);
    }

    /** The operator confirmed in the provider's own dashboard that the order does NOT exist there. */
    public function confirmNotCreated(int $fulfillmentId, string $reason): void
    {
        $fulfillment = $this->find($fulfillmentId);
        if (!$this->tracker->confirmNotCreated($fulfillmentId, $reason)) {
            throw new InvalidArgumentException('Only a fulfillment still awaiting reconciliation, with no provider reference, can be confirmed as not created.');
        }

        $this->recordAudit('fulfillment.confirmed_not_created', $fulfillment, ['reason' => $reason]);
        $this->queue->dispatchFulfillment($fulfillmentId, 0, true);
    }

    /** The operator found the order at the provider and supplies its reference. */
    public function linkReference(int $fulfillmentId, string $reference, ?string $trackingNumber, string $reason): void
    {
        $fulfillment = $this->find($fulfillmentId);
        $reference = trim($reference);
        if ($reference === '') {
            throw new InvalidArgumentException('A provider reference is required.');
        }

        try {
            $linked = $this->tracker->linkProviderReference($fulfillmentId, $reference, $this->nullIfBlank($trackingNumber), $reason);
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException('Another shipment already carries this provider reference.');
        }
        if (!$linked) {
            throw new InvalidArgumentException('This fulfillment already has an outcome.');
        }

        $this->recordAudit('fulfillment.reference_linked', $fulfillment, ['reference' => $reference, 'reason' => $reason]);
        $this->cancellation->honourIntent($fulfillmentId);
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

    /**
     * Ask for the shipment to be cancelled. Run on the spot when there is no queue worker (the `sync` driver), so the
     * person sees the answer instead of a request that nothing will pick up.
     *
     * @return string a FulfillmentCancellation / CancelLease / CancellationResult outcome, or self::CANCEL_QUEUED
     */
    public function requestCancel(int $fulfillmentId): string
    {
        $fulfillment = $this->find($fulfillmentId);
        $this->recordAudit('fulfillment.cancel_requested', $fulfillment);

        if ($this->queue->runsInline()) {
            return $this->cancellation->cancel(fulfillmentId: $fulfillmentId, manual: true);
        }

        $this->queue->dispatchCancel($fulfillmentId, manual: true);

        return self::CANCEL_QUEUED;
    }

    /**
     * A cancelled fulfillment's items go back to unrouted, so routing creates generation + 1.
     *
     * The original is "cancelled" in OUR records; at the provider it may still be live (it is cancelled by hand when
     * the provider cannot do it). Sending a replacement while the original still ships delivers the goods twice, so
     * the person must say they checked.
     */
    public function redispatchAsNewGeneration(int $fulfillmentId, string $reason): void
    {
        $fulfillment = $this->find($fulfillmentId);

        $released = DB::transaction(function () use ($fulfillmentId): bool {
            $locked = ProviderFulfillment::whereKey($fulfillmentId)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== FulfillmentStatus::Cancelled) {
                return false;
            }

            ProviderFulfillmentItem::where('provider_fulfillment_id', $fulfillmentId)->update([
                'provider_fulfillment_id' => null,
                'status' => ProviderFulfillmentItem::STATUS_UNROUTED,
            ]);

            return true;
        });
        if (!$released) {
            throw new InvalidArgumentException('Only a cancelled fulfillment can be re-dispatched as a new generation.');
        }

        $this->recordAudit('fulfillment.redispatched_new_generation', $fulfillment, ['reason' => $reason]);
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

    private function nullIfBlank(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? trim($value) : null;
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
