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
     * @return array{claimed: int, posted: int, found: int, failed: int, needs_review: int, not_postable: int, lost_the_row: int, waiting: int, stopped: string|null}
     */
    public function drain(SalesChannelAccount $connection, OdooPreflight $preflight, int $limit = 50): array
    {
        // `lost_the_row` is a run that found the row taken from under it between claiming and fencing: nothing was sent and
        // nothing was written, which is a different outcome from a failure and is counted as its own.
        // `waiting` is a row that is not postable YET because another row is not: a reversal or a return whose original entry
        // is queued and not in Odoo. It spends no attempt, so it is counted apart from a failure.
        $stats = ['claimed' => 0, 'posted' => 0, 'found' => 0, 'failed' => 0, 'needs_review' => 0, 'not_postable' => 0, 'lost_the_row' => 0, 'waiting' => 0, 'stopped' => null];
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
        // **THE FENCE IS READ FIRST, BEFORE ANY JUDGEMENT ABOUT THE SOURCE.**
        //
        // It used to be read after. That meant a fenced row whose ledger entry had since been deleted was marked `dead` with
        // `source_row_is_gone`, and a fenced row whose figure had been corrected was marked `dead` with
        // `source_version_superseded` -- in both cases burying an `account.move.create` that may be sitting in the customer's
        // books. A row in the `dead` pile is a row nobody is looking for, and the entry it hid would be found by an accountant
        // rather than by us.
        //
        // What our own ledger now says about the fact cannot change what Odoo may already hold. So a fenced row is ASKED about,
        // always, whatever has happened to its source -- and once Odoo's answer settles it, the source can be judged.
        if ($row->mayHavePosted()) {
            $this->verifyOnly($row, $connection, 'an earlier create for this reference is unaccounted for', $stats);

            return;
        }

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

        // **THE REFERENCE THE ROW WILL BE SEARCHED BY AND THE ONE THE ENTRY WOULD CARRY MUST BE THE SAME STRING.**
        //
        // AFTER the version check, and that order is the whole of its meaning. The reference carries the version, so a row whose
        // fact has moved on fails this comparison too -- and that is a superseded row, which has its own name and its own
        // outcome. What is left once the versions agree is the case this guards: the two strings are built by the same function
        // from the same entry, so they agree today, and if they ever stopped agreeing every protection in this class would still
        // be in place and would all be looking at the wrong value. The search before the create would ask for the row's
        // reference, the create would write the draft's, and a retry would search for the first, not find it, and post a second
        // entry -- a duplicate in somebody's books arrived at by code that reads as careful. So the two are compared rather than
        // assumed.
        if ($result->draft->reference !== $row->odoo_ref) {
            $this->outbox->notPostable($row, 'odoo_reference_no_longer_matches_the_row');
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

        // THE FENCE, AND IT GOES BEFORE THE REQUEST. From here the row durably says an entry may be in the customer's books, so
        // a run that dies at any later instant leaves a row no machine will create from again.
        //
        // **AND IT CAN FAIL, WHICH ABORTS THE CREATE.** It affects nothing when this run no longer owns the row -- taken over,
        // or its own lease lapsed during the reference search above, which is a network call. A run that cannot fence must not
        // create: its request would leave this host with nothing recording that it had.
        if (!$this->outbox->aboutToCreate($row)) {
            $stats['lost_the_row']++;

            return;
        }

        try {
            $created = $this->client->createDraft($connection, 'account.move', $result->draft->toValues($preflight));
        } catch (ChannelUnavailableException | OdooNotConfigured $gate) {
            // A gate refuses BEFORE anything leaves this host -- a switch, a capability, an approval, a connection not set up --
            // so nothing was sent and the fence comes down. This catch is deliberately narrow, around the create alone: the same
            // exception raised by a LATER call (the verification, say, after a capability is withdrawn mid-run) must not lift a
            // fence that is standing for a create that did go out. `drain()` releases those without touching the state.
            $this->outbox->release($row, $gate->reason, keepFence: false);

            throw $gate;
        }

        if ($created->ok()) {
            $ids = $created->ids();
            if ($ids !== []) {
                $this->outbox->posted($row, $ids[0], $result->draft->reference);
                $stats['posted']++;

                return;
            }

            // A success Odoo gave no id for. The entry may well exist, so this is the ambiguous case, not a failure.
            $this->verifyOnly($row, $connection, 'odoo_created_without_an_id', $stats);

            return;
        }
        // THE ONLY ROAD BACK FROM THE FENCE, and it takes proof rather than an absence of bad news: Odoo arrived at an answer,
        // or the request never left this host. Either way nothing was written. A refusal is still not retried by itself, because
        // repeating it unchanged would be refused again.
        if ($created->isRefusal()) {
            $this->outbox->failed($row, 'odoo_refused:' . (int) $created->fault->faultCode, $created->fault->faultString, retry: false, keepFence: false);
            $stats['failed']++;

            return;
        }
        if ($created->certainlyDidNotAct()) {
            $this->outbox->failed($row, 'odoo_create_never_left_this_host', $created->problem, keepFence: false);
            $stats['failed']++;

            return;
        }

        $this->verifyOnly($row, $connection, (string) $created->problem, $stats);
    }

    /**
     * A create may have landed: **ASK, and never create.**
     *
     * One method for both ways a row gets here -- this run's own create came back unreadable, or an earlier run's create is
     * unaccounted for and this run inherited the fence -- because the question and the rules are identical.
     *
     * Odoo holding the entry resolves it. **Anything else waits for a person**, including Odoo answering that it holds no such
     * entry. That last one is the strict reading, and it is deliberate: a read can be behind its own write, Odoo documents
     * neither way, and "no entry with this reference" is therefore not conclusive proof that the create was never executed.
     * The owner's instruction is that only conclusive proof lifts the fence.
     *
     * The cost is real and worth stating: any 5xx or lost answer on a create now needs somebody to look, rather than being
     * retried. The alternative is a second entry in a customer's ledger, which is corrected only by a reversal they have to
     * explain. An unanswerable question is the one case that stays askable, so a passing outage heals itself.
     *
     * @param array<string, mixed> $stats
     */
    private function verifyOnly(OdooPosting $row, SalesChannelAccount $connection, string $problem, array &$stats): void
    {
        $check = $this->client->entriesByReference($connection, $row->odoo_ref);
        $found = $check->ok() ? ($check->records()[0] ?? null) : null;
        if ($found !== null) {
            $this->outbox->posted($row, (int) ($found['id'] ?? 0), isset($found['name']) ? (string) $found['name'] : null, verified: true);
            $stats['found']++;

            return;
        }

        // EVERYTHING ELSE WAITS FOR A PERSON, and the two cases are named separately in the detail so whoever reads the row can
        // tell them apart: Odoo could not be asked, or Odoo answered that it holds no such entry. Neither is conclusive proof
        // that the create was never executed -- a read can be behind its own write -- and the rule here is that only conclusive
        // proof lifts the fence.
        $this->outbox->needsReview($row, 'odoo_create_outcome_unknown', $problem . '; ' . ($check->ok()
            ? 'Odoo answered that it holds no entry with this reference, which a lagging read says as readily as an absent entry.'
            : 'Odoo could not be asked: ' . (string) $check->problem));
        $stats['needs_review']++;
    }

    /**
     * A draft the builder refused.
     *
     * The distinction is whether waiting could ever help. A missing mapping, a field this instance lacks, a company nobody read
     * — a person can fix those, so the row waits. A refusal about the FACT itself — an estimate with no policy behind it, a
     * superseded row, a figure nobody could determine — will never change by waiting, so the row is laid to rest and counted.
     *
     * Two refusals are about ANOTHER row rather than this one, and they are routed on their own:
     *
     *  - the entry this one takes back is not in Odoo yet → wait, and spend no attempt. Nothing about this row is wrong, and
     *    counting it as a failure would lay a good reversal to rest after five passes while its original was still being worked.
     *  - the entry this one takes back will never be in Odoo → there is nothing to take back, and no waiting changes that.
     *
     * @param list<string> $all
     * @param array<string, mixed> $stats
     */
    private function settleRefusal(OdooPosting $row, string $first, array $all, array &$stats): void
    {
        if (str_starts_with($first, 'odoo_original_entry_is_not_posted_yet')) {
            $this->outbox->waiting($row, $first);
            $stats['waiting']++;

            return;
        }
        if ($first === 'odoo_original_entry_will_never_be_posted') {
            $this->outbox->notPostable($row, $first);
            $stats['not_postable']++;

            return;
        }

        if (str_starts_with($first, 'cost_')) {
            $this->outbox->notPostable($row, $first);
            $stats['not_postable']++;

            return;
        }

        $this->outbox->failed($row, $first, implode(', ', $all));
        $stats['failed']++;
    }
}
