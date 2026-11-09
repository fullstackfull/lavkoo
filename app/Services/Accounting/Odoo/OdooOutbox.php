<?php

namespace App\Services\Accounting\Odoo;

use App\Models\OdooPosting;
use App\Models\OperationalCostEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * The queue of facts waiting to become journal entries, and the mechanics of taking one without taking it twice.
 *
 * Three things keep a cost out of the customer's books twice over, and all three are needed because Odoo offers none of them:
 *
 *  1. **A unique key here.** `(source_type, source_id, source_version)`. A second enqueue of the same fact at the same version
 *     collides and returns the row that already exists, rather than adding a second one.
 *  2. **A claim.** A row is moved from `pending` to `claimed` by an update that only matches a pending row, so of two runs
 *     reaching the same row at the same instant exactly one proceeds.
 *  3. **A question put to Odoo before any create**, by the reference this row carries. That is `OdooPostingRun`'s job; what this
 *     class guarantees is that the reference is stable and that only one run is working the row while it asks.
 *
 * A version that changes when the fact changes is what makes (1) correct rather than merely tidy: a corrected figure is a new
 * version and a new row (settled by a reversal, per the accounting policy), never a second posting of the same version and never
 * an edit of an entry already in the books.
 */
class OdooOutbox
{
    /**
     * This run's identity, minted once per object.
     *
     * Deliberately NOT a static memo. Two runs inside one process would have shared it, which makes the ownership guard
     * untestable and, worse, lets a worker that resolves this service twice write over its own earlier claim as though it were
     * somebody else's. One drain, one object, one id.
     */
    private readonly string $runId;

    public function __construct()
    {
        $this->runId = 'odoo-' . Str::uuid()->toString();
    }

    /**
     * Records one fact to be posted, or hands back the row that already covers it.
     *
     * The insert is tried and the collision caught rather than checked for first: a check followed by an insert is two
     * statements with a gap between them, and two runs enqueueing the same cost would both pass the check.
     */
    public function enqueue(OperationalCostEntry $entry, ?int $companyId = null, int $sellerId = 0): OdooPosting
    {
        $key = OdooPostingKey::forCostEntry($entry);

        try {
            return OdooPosting::create([
                'seller_id' => $sellerId,
                'odoo_company_id' => $companyId,
                'source_type' => $key->sourceType,
                'source_id' => $key->sourceId,
                'source_version' => $key->sourceVersion,
                'sales_channel' => $entry->sales_channel,
                'odoo_ref' => $key->reference,
                'state' => OdooPosting::PENDING,
                'attempts' => 0,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already queued, perhaps already posted. Either way this is the row that speaks for this fact.
            return OdooPosting::where('source_type', $key->sourceType)
                ->where('source_id', $key->sourceId)
                ->where('source_version', $key->sourceVersion)
                ->firstOrFail();
        }
    }

    /**
     * Takes up to `$limit` due rows for this run.
     *
     * @return list<OdooPosting>
     */
    public function claim(int $limit = 50): array
    {
        $runId = $this->runId;
        $ids = OdooPosting::due()->orderBy('id')->limit(max(1, $limit))->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        // One conditional UPDATE, which is the whole mutual exclusion: a row is taken only if nobody owns it or the owner's lease
        // has run out, so of two runs that read the same ids exactly one gets each row. **THE STATE IS NOT TOUCHED.** Taking
        // ownership is not news about the books, and a claim that wrote the state is how a dead run's create came to be
        // forgotten. One statement needs no transaction around it: the WHERE is the guard.
        $cutoff = now()->subMinutes(max(1, (int) config('odoo.outbox.claim_minutes', 15)));
        $taken = OdooPosting::whereIn('id', $ids)
            ->where(fn (Builder $inner): Builder => $inner->whereNull('claimed_by')->orWhere('claimed_at', '<', $cutoff))
            ->update(['claimed_at' => now(), 'claimed_by' => $runId, 'updated_at' => now()]);

        return $taken === 0
            ? []
            : OdooPosting::whereIn('id', $ids)->where('claimed_by', $runId)->orderBy('id')->get()->all();
    }

    /**
     * **CALLED BEFORE `create` LEAVES, never after it returns.**
     *
     * From the instant it commits, the row says a journal entry may be in the customer's books -- so a run that dies at ANY
     * later point, including between the socket write and the next statement, leaves a row that no machine will create from
     * again. Marking it afterwards left a window of exactly that shape, and the cost of it is a duplicate in somebody's ledger.
     *
     * It is deliberately pessimistic. Everything that can PROVE Odoo did not process the request lifts it; being wrong in this
     * direction costs an accountant a look, and being wrong in the other costs them a reversal to explain.
     *
     * The claim is KEPT: the attempt is not over, and the verification still has to happen.
     */
    public function aboutToCreate(OdooPosting $row, string $reason = 'odoo_create_in_flight'): bool
    {
        // **A COMPARE-AND-SET THAT FAILS CLOSED, and the return value is not optional to check.**
        //
        // It affects nothing when this run no longer owns the row: another run took it over, or this run's own lease lapsed
        // while it was paused -- the reference search before it is a network call, and a run can lose minutes in one. A run in
        // that position cannot fence anything, so it must not create anything: its `account.move.create` would leave this host
        // with no durable record that it had, which is the hole the fence closes.
        //
        // The lease is part of the condition, not just the owner, because a run whose claim has run out cannot promise
        // exclusivity for the length of a network call even when nobody has taken the row yet. And the lease is RENEWED here,
        // so the fence covers the call that follows it rather than expiring halfway through.
        $lease = now()->subMinutes(max(1, (int) config('odoo.outbox.claim_minutes', 15)));
        $landed = OdooPosting::whereKey($row->id)
            ->where('claimed_by', $this->runId)
            ->where('claimed_at', '>', $lease)
            ->update([
                'state' => OdooPosting::MAY_HAVE_POSTED,
                'failure_code' => $reason,
                'worked_at' => now(),
                'claimed_at' => now(),
                'updated_at' => now(),
            ]) === 1;

        if ($landed) {
            $row->forceFill(['state' => OdooPosting::MAY_HAVE_POSTED, 'failure_code' => $reason, 'claimed_at' => now()])->syncOriginal();
        }

        return $landed;
    }

    /** Odoo reports the entry. Terminal, and the move id is what makes it so: a row with one is never created again. */
    public function posted(OdooPosting $row, int $moveId, ?string $moveRef = null, bool $verified = false): void
    {
        $this->write($row, [
            'state' => OdooPosting::POSTED,
            'odoo_move_id' => $moveId,
            'odoo_move_ref' => $moveRef,
            'posted_at' => $row->posted_at ?? now(),
            // Set when Odoo was ASKED whether it holds the entry, as opposed to having answered a create directly: the two are
            // different kinds of knowledge and a person reading the row should be able to tell them apart.
            'verified_at' => $verified ? now() : $row->verified_at,
            'worked_at' => now(),
            'claimed_at' => null,
            'claimed_by' => null,
            'failure_code' => null,
            'outcome' => $verified ? 'found_in_odoo' : 'created',
        ]);
    }

    /**
     * Not posted. With `retry` it goes back into the queue after a wait; without it, it waits on a person or on a changed
     * mapping — which is what a fault is, since the same request would be refused again.
     */
    public function failed(OdooPosting $row, string $code, ?string $detail = null, bool $retry = true, bool $keepFence = true): void
    {
        $attempts = (int) $row->attempts + 1;
        $ceiling = max(1, (int) config('odoo.outbox.attempts', 5));
        $backoff = max(1, (int) config('odoo.outbox.retry_seconds', 300));

        // **A ROW WHOSE FENCE IS UP KEEPS IT**, whatever else this attempt concluded: the create may still be in the customer's
        // books, and "try again later" must never quietly mean "create again". Only a caller that can PROVE Odoo did not
        // process the request passes `keepFence: false`.
        $fenced = $keepFence && $row->mayHavePosted();
        $this->write($row, [
            'attempts' => $attempts,
            'state' => match (true) {
                $fenced => OdooPosting::MAY_HAVE_POSTED,
                $retry && $attempts < $ceiling => OdooPosting::PENDING,
                $retry => OdooPosting::DEAD,
                default => OdooPosting::FAILED,
            },
            // Each try waits longer than the last, so an instance refusing everything is not asked every minute.
            'available_at' => $retry && $attempts < $ceiling ? now()->addSeconds($backoff * $attempts) : null,
            'claimed_at' => null,
            'claimed_by' => null,
            'worked_at' => now(),
            'failure_code' => mb_substr($code, 0, 64),
            'failure_detail' => $detail,
        ]);
    }

    /**
     * **WAITING ON SOMETHING ELSE, which is not a failed attempt.**
     *
     * One row waits for another: a reversal or a return whose original entry is queued and not yet in the books. Nothing about
     * this row is wrong, so it spends none of its attempts -- the same reasoning as `release()`, where a person throwing a
     * switch must not use up a row's tries. Counting these as failures would lay a perfectly good reversal to rest after five
     * passes while its original was still being worked.
     *
     * The fence is kept if one is up. It cannot be, at the only point this is reached, and the invariant holds anyway: nothing
     * in this class may quietly turn "try later" into "create again".
     */
    public function waiting(OdooPosting $row, string $code): void
    {
        $this->write($row, [
            'state' => $row->mayHavePosted() ? OdooPosting::MAY_HAVE_POSTED : OdooPosting::PENDING,
            'available_at' => now()->addSeconds(max(1, (int) config('odoo.outbox.wait_seconds', 600))),
            'claimed_at' => null,
            'claimed_by' => null,
            'worked_at' => now(),
            'failure_code' => mb_substr($code, 0, 64),
        ]);
    }

    /**
     * The fact itself cannot be posted and no waiting will change that — an estimate with no policy behind it, a superseded row,
     * a figure nobody could determine. Kept and counted rather than dropped: a cost nobody posted belongs on a screen.
     */
    public function notPostable(OdooPosting $row, string $code): void
    {
        $this->write($row, [
            'state' => OdooPosting::DEAD, 'available_at' => null, 'claimed_at' => null, 'claimed_by' => null,
            'worked_at' => now(), 'failure_code' => mb_substr($code, 0, 64), 'outcome' => 'not_postable',
        ]);
    }

    /**
     * **A create went out and nobody can say whether Odoo holds the entry.**
     *
     * No `available_at`, so no run picks it up again: retrying might post the same cost twice and abandoning it might lose one,
     * and choosing between those is not something a machine does with somebody's books. `needs_review_since` is when the person
     * started being owed the decision.
     */
    public function needsReview(OdooPosting $row, string $code, ?string $detail = null): void
    {
        $this->write($row, [
            'attempts' => (int) $row->attempts + 1,
            'state' => OdooPosting::NEEDS_REVIEW,
            'available_at' => null,
            'claimed_at' => null,
            'claimed_by' => null,
            'worked_at' => now(),
            'needs_review_since' => $row->needs_review_since ?? now(),
            'failure_code' => mb_substr($code, 0, 64),
            'failure_detail' => $detail,
        ]);
    }

    /**
     * The claim goes back untouched: no attempt is counted, because no attempt was made.
     *
     * This is the switch-thrown-mid-run case. Somebody who turns posting off means it to stop now, and a run that counted that
     * as a failed attempt would spend the row's retries on their decision.
     */
    public function release(OdooPosting $row, string $code, bool $keepFence = true): void
    {
        // The state is NOT reset by default: a row whose fence is up keeps it, because a switch being thrown says nothing about
        // whether an earlier create reached Odoo. `keepFence: false` is for the one caller that knows the throw happened before
        // the request went.
        $this->write($row, [
            'state' => $keepFence && $row->mayHavePosted() ? OdooPosting::MAY_HAVE_POSTED : OdooPosting::PENDING,
            'available_at' => null, 'claimed_at' => null, 'claimed_by' => null,
            'failure_code' => mb_substr($code, 0, 64),
        ]);
    }

    /**
     * Rows a run that died still holds, released for another run. Returns how many.
     *
     * **IT CLEARS THE CLAIM AND NOTHING ELSE.** It used to set the state back to `pending`, which is precisely how a dead run's
     * unrecorded create was forgotten: the row that had a journal entry somewhere in Odoo came back looking brand new. No
     * attempt is counted either, because none was finished.
     */
    public function reclaimAbandoned(): int
    {
        $cutoff = now()->subMinutes(max(1, (int) config('odoo.outbox.claim_minutes', 15)));

        return OdooPosting::whereNotNull('claimed_by')->where('claimed_at', '<', $cutoff)->update([
            'claimed_at' => null, 'claimed_by' => null, 'updated_at' => now(),
        ]);
    }

    /** What the queue looks like, for the screen a person reads. @return array<string, int> */
    public function counts(): array
    {
        $counts = array_fill_keys([OdooPosting::PENDING, OdooPosting::MAY_HAVE_POSTED, OdooPosting::POSTED, OdooPosting::FAILED, OdooPosting::NEEDS_REVIEW, OdooPosting::DEAD], 0);
        foreach (OdooPosting::selectRaw('state, count(*) as total')->groupBy('state')->get() as $row) {
            $counts[(string) $row->state] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * One settle, and it only lands if THIS run still owns the row.
     *
     * A run whose lease expired -- a slow call that was overtaken, a process that came back from the dead -- cannot write over
     * the row its successor holds: its late word is about a world it stopped observing, and the only safe outcome is that it
     * changes nothing. The condition is in the WHERE, so the statement is its own transaction.
     *
     * @param array<string, mixed> $values
     */
    private function write(OdooPosting $row, array $values): void
    {
        $landed = OdooPosting::whereKey($row->id)->where('claimed_by', $this->runId)->update($values + ['updated_at' => now()]);
        if ($landed === 0) {
            return;
        }

        // THE OBJECT IS BROUGHT INTO STEP WITH THE ROW, and this is not tidiness. The run reads `$row->mayHavePosted()` after
        // the fence goes up, to decide whether a later settle may clear it; an object still holding the state it was claimed
        // with would answer "no fence" and silently drop it -- which is the very hole this whole change exists to close. It
        // nearly got through here.
        $row->forceFill($values)->syncOriginal();
    }

    /** Which run this is, so a run that died can be told from one still working. */
    public function runId(): string
    {
        return $this->runId;
    }
}
