<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\EventOutcome;
use App\Enums\Fulfillment\FulfillmentStatus;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentEvent;
use App\Services\Fulfillment\Data\SubmissionResult;
use App\Services\Fulfillment\Support\AppTime;
use App\Services\Fulfillment\Support\SecretRedactor;
use Carbon\CarbonInterface;
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
                return $this->finish($event, EventOutcome::IGNORED_UNMATCHED, 'fulfillment_missing', $source, null, $providerStatus, $incoming, $occurredAt);
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

            return $this->finish($event, $outcome, $decision, $source, $fulfillment->id, $providerStatus, $incoming, $occurredAt);
        });
    }

    /**
     * Record that the provider verifiably holds the order. Safe to call late and more than once: it
     * stores references the first time, and moves the status to `submitted` only from a state in which
     * we had not yet seen acceptance — it never drags an already-progressed shipment backwards.
     */
    public function recordAcceptance(int $fulfillmentId, SubmissionResult $result): void
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
                'meta' => array_replace($fulfillment->meta ?? [], $result->meta),
            ];

            if (in_array($fulfillment->status, [FulfillmentStatus::Dispatching, FulfillmentStatus::ReconcilePending, FulfillmentStatus::RetryWait, FulfillmentStatus::Pending], true)) {
                $values['status'] = FulfillmentStatus::Submitted;
            }

            $fulfillment->forceFill($values)->save();

            $this->record($fulfillment->provider, 'system', 'dispatch', $fulfillment->id, $result->providerReference, $result->providerStatus, $fulfillment->status->value, null, EventOutcome::APPLIED, 'accepted_by_provider');
        });
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

    public function release(int $fulfillmentId, string $by): void
    {
        $this->simpleTransition($fulfillmentId, FulfillmentStatus::Pending, [
            'hold_reason' => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'next_attempt_at' => null,
            'claim_token' => null,
        ], 'released:' . $by, [FulfillmentStatus::Held, FulfillmentStatus::DryRun, FulfillmentStatus::Failed]);
    }

    public function markFailed(int $fulfillmentId, string $code, string $message): void
    {
        $this->simpleTransition($fulfillmentId, FulfillmentStatus::Failed, [
            'failed_at' => now(),
            'last_error_code' => $code,
            'last_error_message' => SecretRedactor::truncate(SecretRedactor::redactString($message), 1000),
            'claim_token' => null,
            'next_attempt_at' => null,
        ], 'failed:' . $code);
    }

    public function scheduleRetry(int $fulfillmentId, string $code, string $message, \DateTimeInterface $at): void
    {
        $this->simpleTransition($fulfillmentId, FulfillmentStatus::RetryWait, [
            'last_error_code' => $code,
            'last_error_message' => SecretRedactor::truncate(SecretRedactor::redactString($message), 1000),
            'next_attempt_at' => $at,
            'claim_token' => null,
        ], 'retry:' . $code);
    }

    public function awaitReconciliation(int $fulfillmentId, string $code, string $message, \DateTimeInterface $nextCheck): void
    {
        $this->simpleTransition($fulfillmentId, FulfillmentStatus::ReconcilePending, [
            'last_error_code' => $code,
            'last_error_message' => SecretRedactor::truncate(SecretRedactor::redactString($message), 1000),
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
        ], 'cancelled_locally:' . $reason, [
            FulfillmentStatus::Pending, FulfillmentStatus::Held, FulfillmentStatus::RetryWait,
            FulfillmentStatus::DryRun, FulfillmentStatus::Failed,
        ]);
    }

    public function noteCancelRequested(int $fulfillmentId): void
    {
        DB::transaction(function () use ($fulfillmentId): void {
            $fulfillment = ProviderFulfillment::whereKey($fulfillmentId)->lockForUpdate()->first();
            if ($fulfillment === null || $fulfillment->isTerminal()) {
                return;
            }
            $fulfillment->forceFill(['meta' => array_replace($fulfillment->meta ?? [], ['cancel_requested_at' => now()->toIso8601String()])])->save();
            $this->record($fulfillment->provider, 'system', 'cancel', $fulfillment->id, $fulfillment->provider_reference, null, null, null, EventOutcome::APPLIED, 'cancel_requested');
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
            $values['last_error_message'] = SecretRedactor::truncate($attentionNote ?? $providerStatus, 500);
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
     * @param array<string, mixed> $meta
     */
    private function simpleTransition(int $fulfillmentId, FulfillmentStatus $to, array $values, string $note, ?array $onlyFrom = null, array $meta = []): void
    {
        DB::transaction(function () use ($fulfillmentId, $to, $values, $note, $onlyFrom, $meta): void {
            $fulfillment = ProviderFulfillment::whereKey($fulfillmentId)->lockForUpdate()->first();
            if ($fulfillment === null) {
                return;
            }
            if ($onlyFrom !== null && !in_array($fulfillment->status, $onlyFrom, true)) {
                return;
            }
            if ($fulfillment->status->isTerminal()) {
                return;
            }

            $values['status'] = $to;
            if ($meta !== []) {
                $values['meta'] = array_replace($fulfillment->meta ?? [], $meta);
            }
            $fulfillment->forceFill($values)->save();

            $this->record($fulfillment->provider, 'system', 'state', $fulfillment->id, $fulfillment->provider_reference, null, $to->value, null, EventOutcome::APPLIED, $note);
        });
    }

    private function finish(
        ?ProviderFulfillmentEvent $event,
        string $outcome,
        string $note,
        string $source,
        ?int $fulfillmentId,
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
            $this->record('', $source, 'status', $fulfillmentId, null, $providerStatus, $incoming->value, $occurredAt, $outcome, $note);
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
