<?php

namespace App\Services\SalesChannels\Attention;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * The only things a person may do to a row that is waiting for them — and the refusal of everything else.
 *
 * ## The rule this class exists to make true
 *
 * **Nothing here sends anything.** Not a refund, not a return decision, not a package split, not a waybill, not a journal
 * entry, not a payment. Every state this screen shows is one where the outcome is unknown and the channel either offers no
 * way to ask or charges real money for a wrong guess. A "retry" button on such a row is not a convenience; it is the
 * double-refund, the second parcel and the duplicated ledger entry, one click away.
 *
 * So there are two actions:
 *
 *  - **acknowledge** — a person went to the channel, found out what happened, and writes it down. The row is settled as a
 *    HUMAN judgement: who, when, and in their own words. No request leaves this host.
 *  - **put back** — the work is queued again, and **only for a source that declared itself idempotent by construction**. One
 *    source qualifies: the stock outbox, whose publisher recomputes a quantity and compares it with what it last wrote.
 *    `assertRetryable()` refuses it for anything else, so adding a source with the wrong flag fails loudly rather than
 *    quietly offering to refund somebody twice.
 *
 * Every action is audited with the person who took it and the words they gave, because settling an unknown outcome by hand is
 * a judgement somebody may have to answer for later.
 */
class AttentionActions
{
    public function __construct(
        private readonly AttentionRegistry $registry,
        private readonly AttentionCollector $collector,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Records what a person found out at the channel, and settles the row on that basis.
     *
     * @return array{ok: bool, refusal: string|null}
     */
    public function acknowledge(string $sourceKey, int $id, int $adminId, string $note): array
    {
        $source = $this->registry->find($sourceKey);
        if ($source === null || !$source->allows(AttentionSource::ACKNOWLEDGE)) {
            return ['ok' => false, 'refusal' => 'attention_action_not_allowed_for_this_source'];
        }
        if (trim($note) === '') {
            // The note IS the record. Settling an unknown outcome with no words would leave the next person reading a state
            // change nobody can account for.
            return ['ok' => false, 'refusal' => 'attention_acknowledgement_needs_words'];
        }
        $row = $this->row($source, $id);
        if ($row === null) {
            return ['ok' => false, 'refusal' => 'attention_row_is_no_longer_waiting'];
        }

        $changes = [
            'reviewed_by' => $adminId,
            'reviewed_at' => now(),
            'review_note' => mb_substr(trim($note), 0, 1000),
        ];
        if ($source->settledState !== null) {
            $changes[$source->stateColumn] = $source->settledState;
        }

        $this->write($source, $row, $changes);
        $this->audit->record(action: 'sales_channel.attention_acknowledged', subject: $row, context: [
            'source' => $source->key, 'row' => $id, 'from_state' => (string) $row->{$source->stateColumn},
            'to_state' => $source->settledState, 'by' => $adminId,
        ]);

        return ['ok' => true, 'refusal' => null];
    }

    /**
     * Queues the work again. Refused unless the source is idempotent by construction.
     *
     * @return array{ok: bool, refusal: string|null}
     */
    public function putBack(string $sourceKey, int $id, int $adminId, string $note): array
    {
        $source = $this->registry->find($sourceKey);
        if ($source === null || !$source->allows(AttentionSource::PUT_BACK)) {
            return ['ok' => false, 'refusal' => 'attention_action_not_allowed_for_this_source'];
        }
        if (!$source->retryable || $source->pendingState === null) {
            // **Belt and braces, and deliberately so.** A source could be declared with PUT_BACK among its actions and
            // `retryable: false` by mistake; this is the check that makes that mistake an error instead of a second refund.
            return ['ok' => false, 'refusal' => 'attention_this_work_may_already_have_happened_and_is_never_sent_again'];
        }
        $row = $this->row($source, $id);
        if ($row === null) {
            return ['ok' => false, 'refusal' => 'attention_row_is_no_longer_waiting'];
        }

        $this->write($source, $row, [
            $source->stateColumn => $source->pendingState,
            'attempts' => 0,
            'available_at' => null,
            'failure_code' => null,
            'reviewed_by' => $adminId,
            'reviewed_at' => now(),
            'review_note' => mb_substr(trim($note), 0, 1000),
        ]);
        $this->audit->record(action: 'sales_channel.attention_put_back', subject: $row, context: [
            'source' => $source->key, 'row' => $id, 'by' => $adminId,
        ]);

        return ['ok' => true, 'refusal' => null];
    }

    private function row(AttentionSource $source, int $id): ?Model
    {
        /** @var Model $model */
        $model = new $source->model();

        // The state is part of the condition: a row somebody else settled while this screen was open is no longer waiting,
        // and acting on it would overwrite their judgement with this one.
        return $model->newQuery()->whereKey($id)->whereIn($source->stateColumn, $source->states)->first();
    }

    /**
     * Writes only the columns the table really has.
     *
     * These nine tables were built by different pieces of work and do not share a review vocabulary: some have
     * `reviewed_by`, some do not, and a screen that insisted on one shape would mean a migration across all of them for a
     * note. What a table cannot hold is left out and the audit log carries it instead, which is where it has to be anyway.
     *
     * @param array<string, mixed> $changes
     */
    private function write(AttentionSource $source, Model $row, array $changes): void
    {
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing($source->table());
        $keep = array_intersect_key($changes, array_flip($columns));
        if ($keep !== []) {
            $row->newQuery()->whereKey($row->getKey())->update($keep + ['updated_at' => now()]);
        }
    }
}
