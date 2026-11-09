<?php

namespace App\Services\Accounting\Odoo;

use App\Models\OdooPosting;
use App\Models\OperationalCostEntry;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;

/**
 * Works the outbox: for each claimed row, asks Odoo what it already holds and creates a DRAFT entry only if the answer is that it
 * holds none.
 *
 * ## The order of operations is the whole safety of this class
 *
 *  1. **Rebuild the draft.** Not the one that was built at enqueue time: a mapping a person has fixed since then should take
 *     effect, and a figure that has changed since then must NOT be posted under the old version's reference. A row whose fact
 *     has moved on is laid to rest, because the new version has a row of its own.
 *  2. **Ask Odoo, by our reference.** This is the only uniqueness Odoo can be asked for. An entry already there means an earlier
 *     attempt landed and its answer was lost: the row is closed on Odoo's word, and nothing is created.
 *  3. **Create, once.** Never retried by the transport, because `ChannelOperations` marks the write unsafe to repeat.
 *  4. **Read the answer for what it PROVES**, not for whether it succeeded:
 *     - Odoo returned an id → posted.
 *     - Odoo refused (a fault) → not posted, and not retried by itself: the same request would be refused again.
 *     - the request never left this host → nothing happened, so it may be tried again.
 *     - **anything else — a timeout, a reset, a 5xx, a body nobody could read — may have created the entry.** So Odoo is asked
 *       again by the same reference. Found: posted. Authoritatively absent: it did not land, and it may be tried again. And if
 *       that question cannot be answered either, the row goes to `needs_review` and **no run touches it again**: retrying might
 *       post the same cost twice and abandoning it might lose one, and that is a person's decision.
 *
 * `ChannelResponse::isTransient()` is nowhere in this flow on purpose. It answers "might the same request succeed later", which
 * is true of an auth refusal (nothing was written) and of a lost answer (something may have been) alike.
 *
 * ## And a switch thrown off mid-run stops it now
 *
 * The gates are re-read per call, so a person who turns posting off during a run means it to stop at the next row rather than at
 * the end of the batch. The claim goes back with no attempt counted: their decision does not spend the row's retries.
 */
class OdooPostingRun
{
    public function __construct(
        private readonly OdooClient $client,
        private readonly OdooOutbox $outbox,
        private readonly OdooDraftBuilder $builder,
    ) {
    }

    /**
     * Works one batch.
     *
     * @return array{claimed: int, posted: int, found: int, failed: int, needs_review: int, not_postable: int, stopped: string|null}
     */
    public function drain(SalesChannelAccount $connection, OdooPreflight $preflight, int $limit = 50): array
    {
        $stats = ['claimed' => 0, 'posted' => 0, 'found' => 0, 'failed' => 0, 'needs_review' => 0, 'not_postable' => 0, 'stopped' => null];
        $this->outbox->reclaimAbandoned();

        $rows = $this->outbox->claim($limit);
        foreach ($rows as $position => $row) {
            $stats['claimed']++;
            try {
                $this->work($row, $connection, $preflight, $stats);
            } catch (ChannelUnavailableException | OdooNotConfigured $stopped) {
                // A switch, the account or the approval went away mid-run; or this connection turned out not to be set up.
                // Whoever did that meant it to stop NOW, so the rest of the batch is let go as well -- every row this run still
                // holds goes back untouched, with no attempt counted against any of them. Leaving them claimed would park them
                // until the lease expired, which is the same work done later for no reason.
                // Both exceptions carry a stable `reason` code, which is the whole point of their having one.
                $reason = $stopped->reason;
                foreach (array_slice($rows, $position) as $held) {
                    $this->outbox->release($held, $reason);
                }
                $stats['stopped'] = $reason;
                break;
            }
        }

        return $stats;
    }

    /** @param array<string, mixed> $stats */
    private function work(OdooPosting $row, SalesChannelAccount $connection, OdooPreflight $preflight, array &$stats): void
    {
        $entry = OperationalCostEntry::find($row->source_id);
        if ($entry === null || $row->source_type !== OdooPostingKey::SOURCE_COST_ENTRY) {
            $this->outbox->notPostable($row, 'source_row_is_gone');
            $stats['not_postable']++;

            return;
        }

        $result = $this->builder->forCostEntry($entry, $preflight, (int) $row->seller_id);
        if (!$result->isReady()) {
            $this->settleRefusal($row, (string) $result->firstRefusal(), $result->refusals, $stats);

            return;
        }
        if ($result->key->sourceVersion !== $row->source_version) {
            // The fact changed after this row was queued. Posting it now would put the old figure in the books under a reference
            // that no longer describes it; the new version has its own row.
            $this->outbox->notPostable($row, 'source_version_superseded');
            $stats['not_postable']++;

            return;
        }

        // THE QUESTION THAT MAKES A RETRY SAFE, asked before anything is written.
        $already = $this->client->entriesByReference($connection, $row->odoo_ref);
        if ($already->ok()) {
            $found = $already->records()[0] ?? null;
            if ($found !== null) {
                $this->outbox->posted($row, (int) ($found['id'] ?? 0), isset($found['name']) ? (string) $found['name'] : null, verified: true);
                $stats['found']++;

                return;
            }
        } else {
            // Nothing has been written by this row, so there is nothing at risk: it simply could not be asked, and waits.
            $this->outbox->failed($row, 'odoo_could_not_be_asked_before_creating', $already->problem);
            $stats['failed']++;

            return;
        }

        $created = $this->client->createDraft($connection, 'account.move', $result->draft->toValues($preflight));
        if ($created->ok()) {
            $ids = $created->ids();
            if ($ids !== []) {
                $this->outbox->posted($row, $ids[0], $result->draft->reference);
                $stats['posted']++;

                return;
            }

            // A success Odoo gave no id for. The entry may well exist, so this is the ambiguous case, not a failure.
            $this->afterAnAnswerNobodyCouldRead($row, $connection, 'odoo_created_without_an_id', $stats);

            return;
        }
        if ($created->isRefusal()) {
            // Odoo arrived at an answer. Repeating it unchanged would be refused again, so it waits for a person or for a
            // mapping change rather than burning retries.
            $this->outbox->failed($row, 'odoo_refused:' . (int) $created->fault->faultCode, $created->fault->faultString, retry: false);
            $stats['failed']++;

            return;
        }
        if ($created->certainlyDidNotAct()) {
            $this->outbox->failed($row, 'odoo_create_never_left_this_host', $created->problem);
            $stats['failed']++;

            return;
        }

        $this->afterAnAnswerNobodyCouldRead($row, $connection, (string) $created->problem, $stats);
    }

    /**
     * A create may have landed. Ask Odoo, and only a person decides if that question cannot be answered either.
     *
     * @param array<string, mixed> $stats
     */
    private function afterAnAnswerNobodyCouldRead(OdooPosting $row, SalesChannelAccount $connection, string $problem, array &$stats): void
    {
        $check = $this->client->entriesByReference($connection, $row->odoo_ref);
        if (!$check->ok()) {
            // Neither confirmed nor denied. NO automatic retry, ever: this is the one state in this module that waits for a
            // human being.
            $this->outbox->needsReview($row, 'odoo_create_outcome_unknown', $problem . '; verification: ' . (string) $check->problem);
            $stats['needs_review']++;

            return;
        }

        $found = $check->records()[0] ?? null;
        if ($found !== null) {
            $this->outbox->posted($row, (int) ($found['id'] ?? 0), isset($found['name']) ? (string) $found['name'] : null, verified: true);
            $stats['found']++;

            return;
        }

        // Odoo answered, and it holds no entry with this reference: the create did not land. Safe to try again, and the attempt
        // is counted so a persistent problem does not loop for ever.
        $this->outbox->failed($row, 'odoo_create_did_not_land', $problem);
        $stats['failed']++;
    }

    /**
     * A draft the builder refused.
     *
     * The distinction is whether waiting could ever help. A missing mapping, a field this instance lacks, a company nobody read
     * — a person can fix those, so the row waits. A refusal about the FACT itself — an estimate with no policy behind it, a
     * superseded row, a figure nobody could determine — will never change by waiting, so the row is laid to rest and counted.
     *
     * @param list<string> $all
     * @param array<string, mixed> $stats
     */
    private function settleRefusal(OdooPosting $row, string $first, array $all, array &$stats): void
    {
        if (str_starts_with($first, 'cost_')) {
            $this->outbox->notPostable($row, $first);
            $stats['not_postable']++;

            return;
        }

        $this->outbox->failed($row, $first, implode(', ', $all));
        $stats['failed']++;
    }
}
