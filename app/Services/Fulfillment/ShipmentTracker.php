<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\EventOutcome;
use App\Enums\Fulfillment\FulfillmentStatus;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentEvent;
use App\Services\Fulfillment\Data\CancelLease;
use App\Services\Fulfillment\Data\CancellationResult;
use App\Services\Fulfillment\Data\SubmissionResult;
use App\Services\Fulfillment\Support\AppTime;
use App\Services\Fulfillment\Support\PiiRedactor;
use App\Services\Fulfillment\Support\SecretRedactor;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of a fulfillment's status.
 *
 * Every transition — a dispatch result, a webhook, a poll, an operator's manual action — goes through
 * here, under a row lock, so the state machine's stale/terminal rules apply to all of them equally and
 * every change leaves a row in `provider_fulfillment_events`.
 */
class ShipmentTracker
{
    /** How long one cancel attempt may take before another caller is allowed to try (a crashed caller loses it by itself). */
    private const CANCEL_LEASE_SECONDS = 180;

    /** What a dry run kept so a person could read the request before approving it; it describes a request that was never sent. */
    private const RECORDED_REQUEST = ['payload_preview', 'payload_hash'];

    public function __construct(private readonly FulfillmentStateMachine $machine)
    {
    }

    /**
     * Apply a status the PROVIDER reported (webhook, poll, verification).
     *
     * @param array<string, mixed> $extra values to merge into `meta` (postponed date, notes…)
     * @return string an EventOutcome value
     */
    public function applyProviderStatus(
        int $fulfillmentId,
        FulfillmentStatus $incoming,
        ?CarbonInterface $occurredAt,
        string $source,
        ?string $providerStatus,
        ?ProviderFulfillmentEvent $event = null,
        array $extra = [],
        ?string $attentionNote = null,
    ): string {
        $occurredAt = AppTime::local($occurredAt);

        return DB::transaction(function () use ($fulfillmentId, $incoming, $occurredAt, $source, $providerStatus, $event, $extra, $attentionNote): string {
            $fulfillment = ProviderFulfillment::whereKey($fulfillmentId)->lockForUpdate()->first();
            if ($fulfillment === null) {
                return $this->finish($event, EventOutcome::IGNORED_UNMATCHED, 'fulfillment_missing', $source, null, null, $providerStatus, $incoming, $occurredAt);
            }

            $decision = $this->machine->decide($fulfillment->status, $incoming, $fulfillment->last_event_at, $occurredAt);
            $outcome = match ($decision) {
                FulfillmentStateMachine::SAME => EventOutcome::IGNORED_DUPLICATE,
                FulfillmentStateMachine::STALE => EventOutcome::IGNORED_STALE,
                FulfillmentStateMachine::TERMINAL => EventOutcome::IGNORED_TERMINAL,
                default => EventOutcome::APPLIED,
            };

            if ($outcome === EventOutcome::APPLIED) {
                $this->transition($fulfillment, $incoming, $occurredAt, $providerStatus, $extra, $attentionNote);
            } else {
                $fulfillment->forceFill(['last_received_at' => now()])->save();
            }

            return $this->finish($event, $outcome, $decision, $source, $fulfillment->id, $fulfillment->provider, $providerStatus, $incoming, $occurredAt);
        });
    }

    /**
     * A provider reported a status we do not know how to map. It is kept visible (the shipment shows it, and the history
     * says it was not understood) instead of being dropped, once per distinct value so a poll does not repeat itself.
     */
    public function noteUnmappedStatus(int $fulfillmentId, string $providerStatus): void
    {
        DB::transaction(function () use ($fulfillmentId, $providerStatus): void {
            $fulfillment = ProviderFulfillment::whereKey($fulfillmentId)->lockForUpdate()->first();
            if ($fulfillment === null || $fulfillment->isTerminal() || $fulfillment->provider_status === $providerStatus) {
                return;
            }

            $fulfillment->forceFill(['provider_status' => SecretRedactor::truncate($providerStatus, 100), 'last_received_at' => now()])->save();
            $this->record($fulfillment->provider, 'poll', 'status', $fulfillment->id, $fulfillment->provider_reference, $providerStatus, null, null, EventOutcome::IGNORED_UNMAPPED, 'unmapped_provider_status');
        });
    }

    /**
     * Record that the provider verifiably holds the order. Safe to call late and more than once: it
     * stores references the first time, and moves the status to `submitted` only from a state in which
     * we had not yet seen acceptance — it never drags an already-progressed shipment backwards.
     */
    public function recordAcceptance(int $fulfillmentId, SubmissionResult $result): void
    {
        try {
            $this->storeAcceptance($fulfillmentId, $result);
        } catch (UniqueConstraintViolationException) {
            // The provider handed back a reference another shipment already carries. The provider DOES hold this order, so it is
            // neither failed nor re-sent; a person has to untangle which reference belongs to which shipment.
            $this->flagForAttention($fulfillmentId, 'duplicate_provider_reference', 'The provider accepted this shipment but returned a reference that another shipment already carries (' . $result->providerReference . '). Check both shipments in the provider dashboard.');
        }
    }

    private function storeAcceptance(int $fulfillmentId, SubmissionResult $result): void
    {
        DB::transaction(function () use ($fulfillmentId, $result): void {
            $fulfillment = ProviderFulfillment::whereKey($fulfillmentId)->lockForUpdate()->first();
            if ($fulfillment === null || !$result->isAccepted()) {
                return;
            }

            $values = [
                'provider_reference' => $fulfillment->provider_reference ?? $result->providerReference,
                'provider_internal_id' => $fulfillment->provider_internal_id ?? $result->providerInternalId,
                'provider_secondary_reference' => $fulfillment->provider_secondary_reference ?? $result->secondaryReference,
                'tracking_number' => $fulfillment->tracking_number ?? $result->trackingNumber,
                'tracking_url' => $fulfillment->tracking_url ?? $result->trackingUrl,
                'provider_status' => $fulfillment->provider_status ?? $result->providerStatus,
                'provider_cost' => $fulfillment->provider_cost ?? $result->providerCost,
                'claim_token' => null,
                'next_attempt_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
                'last_received_at' => now(),
                'submitted_at' => $fulfillment->submitted_at ?? now(),
                'meta' => Arr::except(array_replace($fulfillment->meta ?? [], $result->meta), self::RECORDED_REQUEST),
            ];

            if (in_array($fulfillment->status, [FulfillmentStatus::Dispatching, FulfillmentStatus::ReconcilePending, FulfillmentStatus::RetryWait, FulfillmentStatus::Pending], true)) {
                $values['status'] = FulfillmentStatus::Submitted;
            }

            $fulfillment->forceFill($values)->save();

            $this->record($fulfillment->provider, 'system', 'dispatch', $fulfillment->id, $result->providerReference, $result->providerStatus, $fulfillment->status->value, null, EventOutcome::APPLIED, 'accepted_by_provider');
        });
    }

    /** The shipment needs a person, whatever else it is doing: it keeps its references and moves to needs_attention. */
    private function flagForAttention(int $fulfillmentId, string $code, string $message): void
    {
        $this->simpleTransition($fulfillmentId, FulfillmentStatus::NeedsAttention, [
            'last_error_code' => $code,
            'last_error_message' => PiiRedactor::scrub($message, 1000),
            'claim_token' => null,
            'next_attempt_at' => null,
            'submitted_at' => now(),
        ], 'attention:' . $code);
    }

    /** Idempotent: re-holding for the same reason (a sweep re-checking) writes nothing and logs nothing. */
    public function hold(int $fulfillmentId, string $reason, ?string $detail = null): void
    {
        $current = ProviderFulfillment::whereKey($fulfillmentId)->first(['status', 'hold_reason', 'last_error_message']);
        if ($current !== null && $current->status === FulfillmentStatus::Held
            && $current->hold_reason === $reason && $current->last_error_message === $detail) {
            return;
        }

        $this->simpleTransition($fulfillmentId, FulfillmentStatus::Held, [
            'hold_reason' => $reason,
            'last_error_code' => $reason,
            'last_error_message' => $detail,
            'claim_token' => null,
        ], 'held:' . $reason);
    }

    /** Give a claim back without consuming the attempt — nothing was sent. */
    public function unclaim(int $fulfillmentId, string $token): void
    {
        DB::transaction(function () use ($fulfillmentId, $token): void {
            $fulfillment = ProviderFulfillment::whereKey($fulfillmentId)->lockForUpdate()->first();
            if ($fulfillment === null || $fulfillment->claim_token !== $token || $fulfillment->status !== FulfillmentStatus::Dispatching) {
                return;
            }

            $fulfillment->forceFill([
                'status' => FulfillmentStatus::Pending,
                'attempts' => max(0, $fulfillment->attempts - 1),
                'claim_token' => null,
                'claimed_at' => null,
            ])->save();
        });
    }

    /**
     * Held, dry-run or failed → pending. `$freshStart` also resets the attempt counters, in the same locked
     * step, so a fulfillment that has since moved on is never touched.
     *
     * @return bool false when the fulfillment was not in a releasable state any more
     */
    public function release(int $fulfillmentId, string $by, bool $freshStart = false): bool
    {
        return $this->simpleTransition($fulfillmentId, FulfillmentStatus::Pending, [
            'hold_reason' => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'next_attempt_at' => null,
            'claim_token' => null,
        ] + ($freshStart ? ['attempts' => 0, 'reconcile_attempts' => 0] : []), 'released:' . $by, [FulfillmentStatus::Held, FulfillmentStatus::DryRun, FulfillmentStatus::Failed], forget: self::RECORDED_REQUEST);
    }

    /**
     * A person checked the provider's own dashboard and found no such order. Applies only while the fulfillment still
     * awaits reconciliation and the provider has never given us a reference: with a reference the provider holds it,
     * and sending again would create a second order.
     *
     * @return bool false when the situation changed under the person's feet
     */
    public function confirmNotCreated(int $fulfillmentId, string $reason): bool
    {
        return DB::transaction(function () use ($fulfillmentId, $reason): bool {
            $fulfillment = ProviderFulfillment::whereKey($fulfillmentId)->lockForUpdate()->first();
            if ($fulfillment === null || $fulfillment->status !== FulfillmentStatus::ReconcilePending
                || $fulfillment->provider_reference !== null || $fulfillment->provider_secondary_reference !== null) {
                return false;
            }

            $fulfillment->forceFill([
                'status' => FulfillmentStatus::Pending,
                'hold_reason' => null,
                'last_error_code' => null,
                'last_error_message' => null,
                'next_attempt_at' => null,
                'claim_token' => null,
                'attempts' => 0,
                'reconcile_attempts' => 0,
            ])->save();
            $this->record($fulfillment->provider, 'manual', 'reconcile', $fulfillment->id, null, null, FulfillmentStatus::Pending->value, null, EventOutcome::APPLIED, 'operator_confirmed_not_created: ' . $reason);

            return true;
        });
    }

    /**
     * A person found the order at the provider and supplies its reference. Applies only while nobody holds the
     * fulfillment (no worker is mid-send) and it has no outcome yet.
     *
     * @return bool false when the fulfillment already has an outcome or a worker holds it
     * @throws \Illuminate\Database\UniqueConstraintViolationException when another shipment already carries this reference
     */
    public function linkProviderReference(int $fulfillmentId, string $reference, ?string $trackingNumber, string $reason): bool
    {
        return DB::transaction(function () use ($fulfillmentId, $reference, $trackingNumber, $reason): bool {
            $fulfillment = ProviderFulfillment::whereKey($fulfillmentId)->lockForUpdate()->first();
            if ($fulfillment === null || !in_array($fulfillment->status, [FulfillmentStatus::ReconcilePending, FulfillmentStatus::Failed, FulfillmentStatus::Held, FulfillmentStatus::Pending], true)) {
                return false;
            }

            $fulfillment->forceFill([
                'provider_reference' => $reference,
                'tracking_number' => $trackingNumber,
                'status' => FulfillmentStatus::Submitted,
                'submitted_at' => now(),
                'hold_reason' => null,
                'last_error_code' => null,
                'last_error_message' => null,
                'next_attempt_at' => null,
                'claim_token' => null,
            ])->save();
            $this->record($fulfillment->provider, 'manual', 'reconcile', $fulfillment->id, $reference, null, FulfillmentStatus::Submitted->value, null, EventOutcome::APPLIED, 'operator_linked_reference: ' . $reason);

            return true;
        });
    }

    public function markFailed(int $fulfillmentId, string $code, string $message): void
    {
        $this->simpleTransition($fulfillmentId, FulfillmentStatus::Failed, [
            'failed_at' => now(),
            'last_error_code' => $code,
            'last_error_message' => PiiRedactor::scrub($message, 1000),
            'claim_token' => null,
            'next_attempt_at' => null,
        ], 'failed:' . $code);
    }

    public function scheduleRetry(int $fulfillmentId, string $code, string $message, \DateTimeInterface $at): void
    {
        $this->simpleTransition($fulfillmentId, FulfillmentStatus::RetryWait, [
            'last_error_code' => $code,
            'last_error_message' => PiiRedactor::scrub($message, 1000),
            'next_attempt_at' => $at,
            'claim_token' => null,
        ], 'retry:' . $code);
    }

    public function awaitReconciliation(int $fulfillmentId, string $code, string $message, \DateTimeInterface $nextCheck): void
    {
        $this->simpleTransition($fulfillmentId, FulfillmentStatus::ReconcilePending, [
            'last_error_code' => $code,
            'last_error_message' => PiiRedactor::scrub($message, 1000),
            'next_attempt_at' => $nextCheck,
            'reconcile_attempts' => 0,
            'claim_token' => null,
        ], 'ambiguous:' . $code);
    }

    public function markDryRun(int $fulfillmentId, array $meta): void
    {
        $this->simpleTransition($fulfillmentId, FulfillmentStatus::DryRun, [
            'claim_token' => null,
            'next_attempt_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
        ], 'dry_run', null, $meta);
    }

    /** The parent order was closed before this fulfillment reached the provider: nothing to cancel remotely. */
    public function cancelLocally(int $fulfillmentId, string $reason): void
    {
        $this->simpleTransition($fulfillmentId, FulfillmentStatus::Cancelled, [
            'cancelled_at' => now(),
            'claim_token' => null,
            'next_attempt_at' => null,
            'hold_reason' => null,
        ], 'cancelled_locally:' . $reason, array_values(array_filter(FulfillmentStatus::cases(), static fn (FulfillmentStatus $status): bool => $status->isCancellableLocally())));
    }

    /**
     * Someone wants this shipment cancelled, but the provider cannot be asked yet because the outcome of the
     * send is unknown. Written down once; honoured the moment the provider is known to hold the order.
     */
    public function noteCancelIntent(int $fulfillmentId): void
    {
        DB::transaction(function () use ($fulfillmentId): void {
            $fulfillment = ProviderFulfillment::whereKey($fulfillmentId)->lockForUpdate()->first();
            if ($fulfillment === null || $fulfillment->isTerminal() || $fulfillment->cancel_intent_at !== null) {
                return;
            }

            $fulfillment->forceFill(['cancel_intent_at' => now()])->save();
            $this->record($fulfillment->provider, 'system', 'cancel', $fulfillment->id, $fulfillment->provider_reference, null, null, null, EventOutcome::APPLIED, 'cancel_wanted_before_acceptance');
        });
    }

    /**
     * Take the right to ask the provider to cancel. Exactly one caller holds it at a time, so two workers (or a
     * worker and an operator's click) can never both call the provider; a caller that crashes loses it by itself
     * when the lease runs out. `$manual` is a person re-asking: it lifts a block and restarts the retry budget, but
     * never overrides a request the provider already acknowledged or an attempt still in progress.
     */
    public function leaseCancelAttempt(int $fulfillmentId, bool $manual): CancelLease
    {
        return DB::transaction(function () use ($fulfillmentId, $manual): CancelLease {
            $fulfillment = ProviderFulfillment::whereKey($fulfillmentId)->lockForUpdate()->first();

            $refusal = match (true) {
                $fulfillment === null, $fulfillment->isTerminal(), !$fulfillment->status->isAcceptedByProvider() => CancelLease::NOT_APPLICABLE,
                $fulfillment->cancel_requested_at !== null => CancelLease::ALREADY_REQUESTED,
                $fulfillment->cancel_blocked_reason !== null && !$manual => CancelLease::BLOCKED,
                $fulfillment->cancel_next_at?->isFuture() === true => CancelLease::WAITING,
                default => null,
            };
            if ($refusal !== null) {
                return CancelLease::refused($refusal);
            }

            $attempts = $manual ? 1 : $fulfillment->cancel_attempts + 1;
            $fulfillment->forceFill([
                'cancel_intent_at' => $fulfillment->cancel_intent_at ?? now(),
                'cancel_attempts' => $attempts,
                'cancel_next_at' => now()->addSeconds(self::CANCEL_LEASE_SECONDS),
                'cancel_blocked_reason' => null,
            ])->save();

            return CancelLease::granted($attempts);
        });
    }

    /**
     * Record what the provider answered to a cancel request. Only an acknowledgement ("requested") marks the
     * request as made; a failed call is repeated at `$retryAt` (null = give up and block); a refusal or an
     * unsupported cancel blocks automatic cancellation, which then needs a person at the provider.
     */
    public function settleCancelAttempt(int $fulfillmentId, CancellationResult $result, ?\DateTimeInterface $retryAt): void
    {
        DB::transaction(function () use ($fulfillmentId, $result, $retryAt): void {
            $fulfillment = ProviderFulfillment::whereKey($fulfillmentId)->lockForUpdate()->first();
            if ($fulfillment === null) {
                return;
            }

            $summary = PiiRedactor::scrub($result->outcome . ($result->message ? ': ' . $result->message : ''), 500);
            $fulfillment->forceFill(match (true) {
                $result->outcome === CancellationResult::REQUESTED => ['cancel_requested_at' => now(), 'cancel_next_at' => null, 'cancel_blocked_reason' => null],
                $result->outcome === CancellationResult::FAILED && $retryAt !== null => ['cancel_next_at' => $retryAt],
                default => ['cancel_next_at' => null, 'cancel_blocked_reason' => $summary],
            })->save();

            $this->record(
                provider: $fulfillment->provider,
                source: 'system',
                eventType: 'cancel',
                fulfillmentId: $fulfillment->id,
                externalReference: $fulfillment->provider_reference,
                providerStatus: null,
                internalStatus: null,
                occurredAt: null,
                outcome: $result->outcome === CancellationResult::REQUESTED ? EventOutcome::APPLIED : EventOutcome::FAILED,
                note: $summary,
            );
        });
    }

    /** The provider could not be reached at all, so nothing was asked: give the attempt back and try again later. */
    public function releaseCancelLease(int $fulfillmentId, \DateTimeInterface $retryAt): void
    {
        DB::transaction(function () use ($fulfillmentId, $retryAt): void {
            $fulfillment = ProviderFulfillment::whereKey($fulfillmentId)->lockForUpdate()->first();
            if ($fulfillment === null) {
                return;
            }

            $fulfillment->forceFill([
                'cancel_attempts' => max(0, $fulfillment->cancel_attempts - 1),
                'cancel_next_at' => $retryAt,
            ])->save();
        });
    }

    /** Record an event row that did not come with a webhook receipt (a poll, an operator action). */
    public function record(
        string $provider,
        string $source,
        string $eventType,
        ?int $fulfillmentId,
        ?string $externalReference,
        ?string $providerStatus,
        ?string $internalStatus,
        ?CarbonInterface $occurredAt,
        string $outcome,
        ?string $note = null,
        ?array $payload = null,
    ): ProviderFulfillmentEvent {
        return ProviderFulfillmentEvent::create([
            'provider' => $provider,
            'source' => $source,
            'event_type' => $eventType,
            'provider_fulfillment_id' => $fulfillmentId,
            'external_reference' => $externalReference,
            'provider_status' => $providerStatus,
            'internal_status' => $internalStatus,
            'occurred_at' => AppTime::local($occurredAt),
            'received_at' => now(),
            'processed_at' => now(),
            'outcome' => $outcome,
            'outcome_note' => $note === null ? null : mb_substr($note, 0, 255),
            'payload' => $payload === null ? null : SecretRedactor::redact($payload),
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function transition(
        ProviderFulfillment $fulfillment,
        FulfillmentStatus $incoming,
        ?CarbonInterface $occurredAt,
        ?string $providerStatus,
        array $extra,
        ?string $attentionNote,
    ): void {
        $values = [
            'status' => $incoming,
            'provider_status' => $providerStatus ?? $fulfillment->provider_status,
            'last_received_at' => now(),
            'last_event_at' => $this->newest($fulfillment->last_event_at, $occurredAt),
            'last_error_code' => null,
            'last_error_message' => null,
            'hold_reason' => null,
            'next_attempt_at' => null,
            'claim_token' => null,
        ];

        if ($incoming === FulfillmentStatus::NeedsAttention) {
            $values['last_error_code'] = 'provider_attention';
            $values['last_error_message'] = PiiRedactor::scrub((string) ($attentionNote ?? $providerStatus), 500);
        }

        $stamp = $occurredAt ?? now();
        match ($incoming) {
            FulfillmentStatus::Submitted => $values['submitted_at'] = $fulfillment->submitted_at ?? $stamp,
            FulfillmentStatus::InTransit, FulfillmentStatus::OutForDelivery => $values['shipped_at'] = $fulfillment->shipped_at ?? $stamp,
            FulfillmentStatus::Delivered => $values['delivered_at'] = $stamp,
            FulfillmentStatus::Cancelled => $values['cancelled_at'] = $stamp,
            FulfillmentStatus::Returned => $values['returned_at'] = $stamp,
            default => null,
        };

        if ($extra !== []) {
            $values['meta'] = array_replace($fulfillment->meta ?? [], $extra);
        }

        $fulfillment->forceFill($values)->save();
    }

    /**
     * @param list<FulfillmentStatus>|null $onlyFrom
     * @param array<string, mixed> $values
     * @param array<string, mixed> $meta merged into the shipment's meta
     * @param list<string> $forget meta keys removed in the same locked step
     */
    private function simpleTransition(int $fulfillmentId, FulfillmentStatus $to, array $values, string $note, ?array $onlyFrom = null, array $meta = [], array $forget = []): bool
    {
        return DB::transaction(function () use ($fulfillmentId, $to, $values, $note, $onlyFrom, $meta, $forget): bool {
            $fulfillment = ProviderFulfillment::whereKey($fulfillmentId)->lockForUpdate()->first();
            if ($fulfillment === null
                || ($onlyFrom !== null && !in_array($fulfillment->status, $onlyFrom, true))
                || $fulfillment->status->isTerminal()) {
                return false;
            }

            $values['status'] = $to;
            if ($meta !== [] || $forget !== []) {
                $values['meta'] = Arr::except(array_replace($fulfillment->meta ?? [], $meta), $forget);
            }
            $fulfillment->forceFill($values)->save();

            $this->record($fulfillment->provider, 'system', 'state', $fulfillment->id, $fulfillment->provider_reference, null, $to->value, null, EventOutcome::APPLIED, $note);

            return true;
        });
    }

    private function finish(
        ?ProviderFulfillmentEvent $event,
        string $outcome,
        string $note,
        string $source,
        ?int $fulfillmentId,
        ?string $provider,
        ?string $providerStatus,
        FulfillmentStatus $incoming,
        ?CarbonInterface $occurredAt,
    ): string {
        if ($event !== null) {
            $event->forceFill([
                'provider_fulfillment_id' => $fulfillmentId ?? $event->provider_fulfillment_id,
                'internal_status' => $incoming->value,
                'outcome' => $outcome,
                'outcome_note' => $note,
                'processed_at' => now(),
            ])->save();
        } elseif ($fulfillmentId !== null) {
            $this->record((string) $provider, $source, 'status', $fulfillmentId, null, $providerStatus, $incoming->value, $occurredAt, $outcome, $note);
        }

        return $outcome;
    }

    private function newest(?CarbonInterface $current, ?CarbonInterface $candidate): ?CarbonInterface
    {
        if ($candidate === null) {
            return $current;
        }

        return $current === null || $candidate->greaterThan($current) ? $candidate : $current;
    }
}
