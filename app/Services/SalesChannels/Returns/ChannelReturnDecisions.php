<?php

namespace App\Services\SalesChannels\Returns;

use App\Models\ChannelReturnDecision;
use App\Models\SalesChannelAccount;
use App\Services\AuditLogger;
use App\Services\SalesChannels\Data\ChannelResponse;
use App\Services\SalesChannels\Exceptions\ChannelPermissionDenied;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Trendyol\TrendyolConnector;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A person's decision about one customer's return, recorded before it is sent and sent at most once.
 *
 * ## Why this is not simply two API calls
 *
 * **Approving a return refunds a customer and rejecting it refuses them**, and Trendyol documents no idempotency for either:
 * no client-supplied key, no rejection of a repeated request, and nothing about what a second identical call does. So what a
 * second approval does to a refund cannot be known from its pages — which means the only safe design is one where the question
 * never arises.
 *
 * Three things together make that true:
 *
 *  1. **One decision per claim item, ever.** `(account_id, claim_id, claim_item_id)` is unique, so an item cannot be approved
 *     twice and cannot be approved and also rejected. The second matters more than the first: the two decisions go to
 *     different endpoints and nothing at Trendyol would connect them.
 *  2. **The row says "may have been sent" BEFORE the request leaves.** A run that dies between the socket write and the reply
 *     leaves a row saying the customer may already have been refunded, and no machine decides again from there.
 *  3. **A fenced row is never retried, and never asked about either.** This is the one place in this module where a fenced row
 *     has nothing to ask: Trendyol publishes no way to query what became of one claim-item decision. Elsewhere a reference can
 *     be searched for; here there is nothing, so the row waits for a person. That is a real cost and it is the honest one.
 *
 * ## And a rejection's reason comes from Trendyol
 *
 * `claimIssueReasonId` is an id from `GET /order/claim-issue-reasons`, whose example list Trendyol does not describe as
 * complete. So a reason is checked against what the instance answered: an id from memory would be refused, or — worse —
 * accepted as a different reason than the person chose.
 */
class ChannelReturnDecisions
{
    /** This run's identity, minted once per object, so one run cannot write over its own earlier claim as if it were another's. */
    private readonly string $runId;

    public function __construct(
        private readonly TrendyolConnector $trendyol,
        private readonly AuditLogger $audit,
    ) {
        $this->runId = 'returns-' . Str::uuid()->toString();
    }

    /** The decisions table ships before its migration has to have run; until it has, this says so rather than failing. */
    public static function ready(): bool
    {
        return Schema::hasTable('channel_return_decisions');
    }

    /**
     * Records a person's decision. It does NOT send it.
     *
     * Deciding and sending are separate on purpose: the record is what makes the send safe, so it has to exist first and has to
     * survive the send failing. A second decision about the same item comes back as the FIRST one — the unique key decides,
     * not a check followed by an insert, because two admins pressing at once would both pass a check.
     *
     * @param list<string> $claimItemIds
     * @return array{rows: list<ChannelReturnDecision>, already: list<ChannelReturnDecision>, refusal: string|null}
     */
    public function decide(
        SalesChannelAccount $account,
        string $claimId,
        array $claimItemIds,
        string $decision,
        ?int $adminId,
        ?int $reasonId = null,
        string $description = '',
        ?string $externalOrderId = null,
    ): array {
        if (!in_array($decision, [ChannelReturnDecision::APPROVE, ChannelReturnDecision::REJECT], true)) {
            return ['rows' => [], 'already' => [], 'refusal' => 'return_decision_not_understood'];
        }
        $items = array_values(array_unique(array_filter(array_map('trim', $claimItemIds))));
        if (trim($claimId) === '' || $items === []) {
            return ['rows' => [], 'already' => [], 'refusal' => 'return_decision_names_no_item'];
        }

        $reasonName = null;
        if ($decision === ChannelReturnDecision::REJECT) {
            // Trendyol requires both, and a description it caps at 500 characters. Checked here rather than at the transport
            // so a person is told what is missing instead of reading a 400 from Trendyol.
            if (trim($description) === '') {
                return ['rows' => [], 'already' => [], 'refusal' => 'return_rejection_needs_a_description'];
            }
            $reasons = $this->trendyol->returnRejectReasons($account);
            if ($reasonId === null || !isset($reasons[$reasonId])) {
                // The id is checked against the list the INSTANCE answered: Trendyol's documented example list is not described
                // as complete, so neither a memorised id nor a hard-coded table may stand in for it.
                return ['rows' => [], 'already' => [], 'refusal' => 'return_rejection_reason_is_not_one_trendyol_offers'];
            }
            $reasonName = $reasons[$reasonId];
        }

        $rows = [];
        $already = [];
        foreach ($items as $itemId) {
            $existing = ChannelReturnDecision::where('account_id', $account->id)
                ->where('claim_id', $claimId)
                ->where('claim_item_id', $itemId)
                ->first();
            if ($existing !== null) {
                $already[] = $existing;

                continue;
            }
            try {
                $rows[] = ChannelReturnDecision::create([
                    'seller_id' => (int) ($account->seller_id ?? 0),
                    'account_id' => (int) $account->id,
                    'channel' => (string) $account->channel,
                    'claim_id' => $claimId,
                    'claim_item_id' => $itemId,
                    'external_order_id' => $externalOrderId,
                    'decision' => $decision,
                    'reason_id' => $reasonId,
                    'reason_name' => $reasonName,
                    'description' => $decision === ChannelReturnDecision::REJECT ? mb_substr(trim($description), 0, 500) : null,
                    'state' => ChannelReturnDecision::PENDING,
                    'decided_by' => $adminId,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Two admins at once. The row that exists is the decision; this one was never made.
                $already[] = ChannelReturnDecision::where('account_id', $account->id)->where('claim_id', $claimId)->where('claim_item_id', $itemId)->firstOrFail();
            }
        }

        if ($rows !== []) {
            $this->audit->record(action: 'sales_channel.return_decided', subject: $account, context: [
                'claim' => $claimId, 'decision' => $decision, 'items' => count($rows), 'reason_id' => $reasonId, 'by' => $adminId,
            ]);
        }

        return ['rows' => $rows, 'already' => $already, 'refusal' => null];
    }

    /**
     * Sends the decisions that are due, one claim at a time.
     *
     * Grouped by claim and decision because Trendyol's endpoints take a LIST of claim items: three items of one return approved
     * together are one call, which matters when the limit is five calls a minute.
     *
     * @return array{sent: int, done: int, needs_review: int, failed: int, stopped: string|null}
     */
    public function send(SalesChannelAccount $account, int $limit = 20): array
    {
        $stats = ['sent' => 0, 'done' => 0, 'needs_review' => 0, 'failed' => 0, 'stopped' => null];
        if (!self::ready()) {
            $stats['stopped'] = 'returns_table_not_migrated';

            return $stats;
        }

        foreach ($this->claimed($account, $limit) as $group) {
            try {
                $this->sendOne($account, $group, $stats);
            } catch (ChannelUnavailableException | ChannelPermissionDenied $gate) {
                // A switch, a capability or the approval went away. Everything still held goes back untouched, with no attempt
                // counted: somebody's decision not to allow this must not spend a row's tries.
                foreach ($group as $row) {
                    $this->release($row, $gate->reason ?? 'refused');
                }
                $stats['stopped'] = $gate->reason ?? 'refused';
                break;
            }
        }

        return $stats;
    }

    /**
     * One claim's worth of one decision.
     *
     * @param list<ChannelReturnDecision> $group
     * @param array<string, mixed> $stats
     * @throws ChannelUnavailableException|ChannelPermissionDenied
     */
    private function sendOne(SalesChannelAccount $account, array $group, array &$stats): void
    {
        $first = $group[0];
        $items = array_map(static fn (ChannelReturnDecision $row): string => (string) $row->claim_item_id, $group);

        // **THE FENCE, AND IT GOES BEFORE THE REQUEST.** From here every row in this group durably says a customer may already
        // have been refunded or refused. It is a compare-and-set on the claim: a run that no longer owns these rows must not
        // send, because its request would leave this host with nothing recording that it had.
        if (!$this->aboutToSend($group)) {
            return;
        }
        $stats['sent']++;

        try {
            $response = $first->decision === ChannelReturnDecision::APPROVE
                ? $this->trendyol->approveReturnItems($account, (string) $first->claim_id, $items)
                : $this->trendyol->rejectReturnItems($account, (string) $first->claim_id, $items, (int) $first->reason_id, (string) $first->description);
        } catch (ChannelUnavailableException | ChannelPermissionDenied $gate) {
            // A gate refuses BEFORE anything leaves this host, so the fence comes down and the rows go back.
            foreach ($group as $row) {
                $this->release($row, $gate->reason ?? 'refused', liftTheFence: true);
            }

            throw $gate;
        }

        if ($response->ok()) {
            $this->settleAll($group, ChannelReturnDecision::DONE, null, null);
            $stats['done']++;

            return;
        }
        if ($response->isDryRun()) {
            $this->settleAll($group, ChannelReturnDecision::PENDING, 'rehearsal_sent_nothing', null, liftTheFence: true);

            return;
        }
        if ($response->certainlyNotProcessed()) {
            // Nothing left this host. The rows may be decided again, which is the only road back from the fence.
            $this->settleAll($group, ChannelReturnDecision::PENDING, 'request_never_left_this_host', $response->message(), liftTheFence: true);
            $stats['failed']++;

            return;
        }
        if ($response->kind === ChannelResponse::REJECTED || $response->kind === ChannelResponse::AUTH_ERROR) {
            // Trendyol arrived at an answer and refused. Repeating it unchanged would be refused again, so the row rests with
            // the reason -- and the fence comes down, because a refusal is proof the decision was not taken.
            $this->settleAll($group, ChannelReturnDecision::FAILED, 'trendyol_refused_' . (int) $response->status, $response->message(), liftTheFence: true);
            $stats['failed']++;

            return;
        }

        // A 5xx, a timeout, a body nobody could read. **The decision may have been taken**, and Trendyol publishes no way to
        // ask: there is no endpoint that reports what became of one claim-item decision. So this waits for a person, and no run
        // ever sends it again.
        $this->settleAll($group, ChannelReturnDecision::NEEDS_REVIEW, 'outcome_unknown_and_trendyol_offers_no_way_to_ask', $response->message());
        $stats['needs_review']++;
    }

    /**
     * Takes the due rows for this run and groups them by claim and decision.
     *
     * @return list<list<ChannelReturnDecision>>
     */
    private function claimed(SalesChannelAccount $account, int $limit): array
    {
        $ids = ChannelReturnDecision::sendable()->where('account_id', $account->id)->orderBy('id')->limit(max(1, $limit))->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        $cutoff = now()->subMinutes(max(1, (int) config('sales_channels.returns.claim_minutes', 10)));
        ChannelReturnDecision::whereIn('id', $ids)
            ->where(fn ($query) => $query->whereNull('claimed_by')->orWhere('claimed_at', '<', $cutoff))
            ->update(['claimed_at' => now(), 'claimed_by' => $this->runId, 'updated_at' => now()]);

        $groups = [];
        foreach (ChannelReturnDecision::whereIn('id', $ids)->where('claimed_by', $this->runId)->orderBy('id')->get() as $row) {
            $groups[$row->claim_id . '|' . $row->decision . '|' . (string) $row->reason_id][] = $row;
        }

        return array_values($groups);
    }

    /**
     * **Written before the request: a compare-and-set whose answer is not optional to check.**
     *
     * It affects nothing when this run no longer owns the rows -- taken over, or its own claim lapsed while it was paused. A run
     * that cannot fence must not send: its request would leave this host with no durable record that it had, which is the whole
     * hole the fence closes.
     *
     * @param list<ChannelReturnDecision> $group
     */
    private function aboutToSend(array $group): bool
    {
        $lease = now()->subMinutes(max(1, (int) config('sales_channels.returns.claim_minutes', 10)));
        $ids = array_map(static fn (ChannelReturnDecision $row): int => (int) $row->id, $group);
        $landed = ChannelReturnDecision::whereIn('id', $ids)
            ->where('claimed_by', $this->runId)
            ->where('claimed_at', '>', $lease)
            ->update([
                'state' => ChannelReturnDecision::MAY_HAVE_SENT,
                'attempts' => DB::raw('attempts + 1'),
                'sent_at' => now(),
                'claimed_at' => now(),
                'updated_at' => now(),
            ]);
        if ($landed !== count($ids)) {
            return false;
        }
        foreach ($group as $row) {
            $row->forceFill(['state' => ChannelReturnDecision::MAY_HAVE_SENT, 'sent_at' => now()])->syncOriginal();
        }

        return true;
    }

    /**
     * @param list<ChannelReturnDecision> $group
     */
    private function settleAll(array $group, string $state, ?string $code, ?string $detail, bool $liftTheFence = false): void
    {
        foreach ($group as $row) {
            // **A ROW WHOSE FENCE IS UP KEEPS IT** unless the caller can prove the request was not processed. "Try again later"
            // must never quietly mean "refund again".
            $keep = !$liftTheFence && $row->mayHaveReachedTheChannel() && $state === ChannelReturnDecision::PENDING;
            ChannelReturnDecision::whereKey($row->id)->where('claimed_by', $this->runId)->update([
                'state' => $keep ? ChannelReturnDecision::MAY_HAVE_SENT : $state,
                'failure_code' => $code === null ? null : mb_substr($code, 0, 120),
                'failure_detail' => $detail,
                'settled_at' => in_array($state, [ChannelReturnDecision::DONE, ChannelReturnDecision::FAILED], true) ? now() : null,
                'claimed_at' => null,
                'claimed_by' => null,
                'updated_at' => now(),
            ]);
        }
    }

    private function release(ChannelReturnDecision $row, string $code, bool $liftTheFence = false): void
    {
        ChannelReturnDecision::whereKey($row->id)->where('claimed_by', $this->runId)->update([
            'state' => $liftTheFence || !$row->mayHaveReachedTheChannel() ? ChannelReturnDecision::PENDING : ChannelReturnDecision::MAY_HAVE_SENT,
            'failure_code' => mb_substr($code, 0, 120),
            'claimed_at' => null,
            'claimed_by' => null,
            'updated_at' => now(),
        ]);
    }

    /** Which run this is, so a run that died can be told from one still working. */
    public function runId(): string
    {
        return $this->runId;
    }
}
