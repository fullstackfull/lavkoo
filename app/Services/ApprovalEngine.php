<?php

namespace App\Services;

use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One reusable maker-checker engine for every workflow that needs approval (Phase 3 — spec item 82).
 *
 * The specification asks for this by name — "Do not implement separate approval logic for every
 * module" — and the codebase proves the need: settlement-approve, settlement-pay, payout-approve and
 * payout-pay were each written by hand. New workflows use this instead, and old ones can adopt it
 * gradually.
 *
 * The two rules that make dual-control real rather than cosmetic, both enforced here:
 *
 *   1. **The maker cannot be a checker.** The person who requested an action cannot approve it.
 *   2. **One approver counts once.** A single person cannot supply two of the two approvals a
 *      large refund needs — enforced by a unique key, not just a check, so a race cannot beat it.
 *
 * The engine records who approved what through AuditLogger, so an approval is on the unified trail
 * automatically. It does not itself perform the approved action — the caller decides what happens
 * when `open()` returns a request that has reached `approved`, using the stored payload. Keeping the
 * decision and the effect separate is what lets one engine serve refunds, payouts and price changes
 * alike.
 */
class ApprovalEngine
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Open an approval request. Returns null only if the table is absent.
     */
    public function open(
        string $workflow,
        array|object|null $subject = null,
        ?float $amount = null,
        int $requiredApprovals = 1,
        int|string|null $requestedBy = null,
        ?string $requestedByType = null,
        ?array $payload = null,
        ?string $note = null,
        ?string $currency = null,
    ): ?ApprovalRequest {
        if (!Schema::hasTable('approval_requests')) {
            return null;
        }

        [$subjectType, $subjectId] = $this->resolveSubject($subject);

        $request = ApprovalRequest::create([
            'reference' => $this->nextReference(),
            'workflow' => $workflow,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'status' => ApprovalRequest::STATUS_PENDING,
            'amount' => $amount,
            'currency' => $currency,
            'required_approvals' => max(1, $requiredApprovals),
            'requested_by' => $requestedBy,
            'requested_by_type' => $requestedByType,
            'payload' => $payload,
            'request_note' => $note,
        ]);

        $this->audit->record(
            action: $workflow . '.approval_requested',
            subject: $request,
            context: ['reference' => $request->reference, 'amount' => $amount, 'required_approvals' => $request->required_approvals],
        );

        return $request;
    }

    /**
     * Record an approval. Returns the fresh request; check its status for whether it is now approved.
     *
     * @return array{ok: bool, reason?: string, request?: ApprovalRequest}
     */
    public function approve(ApprovalRequest $request, int|string $actorId, string $actorType, ?string $actorName = null, ?string $note = null): array
    {
        if (!$request->isPending()) {
            return ['ok' => false, 'reason' => 'not_pending'];
        }

        // Rule 1: the maker cannot be a checker.
        if ((string) $request->requested_by === (string) $actorId && $request->requested_by_type === $actorType) {
            return ['ok' => false, 'reason' => 'the_person_who_requested_cannot_approve'];
        }

        return DB::transaction(function () use ($request, $actorId, $actorType, $actorName, $note) {
            try {
                // Rule 2: one approver counts once. The unique key turns a duplicate into an
                // exception rather than a second counted approval, so a double-submit or a race
                // cannot inflate the count.
                ApprovalDecision::create([
                    'approval_request_id' => $request->id,
                    'decision' => ApprovalDecision::APPROVE,
                    'actor_type' => $actorType,
                    'actor_id' => $actorId,
                    'actor_name' => $actorName,
                    'note' => $note,
                ]);
            } catch (QueryException) {
                return ['ok' => false, 'reason' => 'already_decided_by_this_actor'];
            }

            $locked = ApprovalRequest::where('id', $request->id)->lockForUpdate()->first();
            $count = $locked->approvals_count + 1;
            $reached = $count >= $locked->required_approvals;

            $locked->forceFill([
                'approvals_count' => $count,
                'status' => $reached ? ApprovalRequest::STATUS_APPROVED : ApprovalRequest::STATUS_PENDING,
                'decided_at' => $reached ? now() : null,
            ])->save();

            $this->audit->record(
                action: $locked->workflow . '.' . ($reached ? 'approved' : 'approval_added'),
                subject: $locked,
                context: ['reference' => $locked->reference, 'approvals' => $count . '/' . $locked->required_approvals],
            );

            return ['ok' => true, 'request' => $locked->fresh()];
        }, attempts: 3);
    }

    /**
     * Reject a request outright. A single rejection ends it, regardless of how many approvals it
     * would otherwise need — a checker who sees a problem stops the action, they do not out-vote it.
     */
    public function reject(ApprovalRequest $request, int|string $actorId, string $actorType, ?string $actorName = null, ?string $note = null): array
    {
        if (!$request->isPending()) {
            return ['ok' => false, 'reason' => 'not_pending'];
        }

        return DB::transaction(function () use ($request, $actorId, $actorType, $actorName, $note) {
            ApprovalDecision::updateOrCreate(
                ['approval_request_id' => $request->id, 'actor_type' => $actorType, 'actor_id' => $actorId],
                ['decision' => ApprovalDecision::REJECT, 'actor_name' => $actorName, 'note' => $note]
            );

            $request->forceFill([
                'status' => ApprovalRequest::STATUS_REJECTED,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();

            $this->audit->record(
                action: $request->workflow . '.rejected',
                subject: $request,
                context: ['reference' => $request->reference],
            );

            return ['ok' => true, 'request' => $request->fresh()];
        });
    }

    /**
     * @return array{0: ?string, 1: int|string|null}
     */
    private function resolveSubject(array|object|null $subject): array
    {
        if ($subject === null) {
            return [null, null];
        }
        if (is_array($subject)) {
            return [$subject['type'] ?? null, $subject['id'] ?? null];
        }

        return [get_class($subject), $subject->getKey() ?? ($subject->id ?? null)];
    }

    private function nextReference(): string
    {
        do {
            $reference = 'APR-' . now()->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        } while (ApprovalRequest::where('reference', $reference)->exists());

        return $reference;
    }
}
