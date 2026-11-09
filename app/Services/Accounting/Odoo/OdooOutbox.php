<?php

namespace App\Services\Accounting\Odoo;

use App\Models\OdooPosting;
use App\Models\OperationalCostEntry;
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
        $runId = self::runId();
        $ids = OdooPosting::due()->orderBy('id')->limit(max(1, $limit))->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        // Only a row still pending is taken, so of two runs that read the same ids exactly one gets each row.
        $taken = OdooPosting::whereIn('id', $ids)->where('state', OdooPosting::PENDING)->update([
            'state' => OdooPosting::CLAIMED, 'claimed_at' => now(), 'claimed_by' => $runId, 'updated_at' => now(),
        ]);

        return $taken === 0
            ? []
            : OdooPosting::whereIn('id', $ids)->where('claimed_by', $runId)->where('state', OdooPosting::CLAIMED)->orderBy('id')->get()->all();
    }

    /** Odoo reports the entry. Terminal, and the move id is what makes it so: a row with one is never created again. */
    public function posted(OdooPosting $row, int $moveId, ?string $moveRef = null, bool $verified = false): void
    {
        $row->forceFill([
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
        ])->save();
    }

    /**
     * Not posted. With `retry` it goes back into the queue after a wait; without it, it waits on a person or on a changed
     * mapping — which is what a fault is, since the same request would be refused again.
     */
    public function failed(OdooPosting $row, string $code, ?string $detail = null, bool $retry = true): void
    {
        $attempts = (int) $row->attempts + 1;
        $ceiling = max(1, (int) config('odoo.outbox.attempts', 5));
        $backoff = max(1, (int) config('odoo.outbox.retry_seconds', 300));

        $row->forceFill([
            'attempts' => $attempts,
            'state' => $retry && $attempts < $ceiling ? OdooPosting::PENDING : ($retry ? OdooPosting::DEAD : OdooPosting::FAILED),
            // Each try waits longer than the last, so an instance refusing everything is not asked every minute.
            'available_at' => $retry && $attempts < $ceiling ? now()->addSeconds($backoff * $attempts) : null,
            'claimed_at' => null,
            'claimed_by' => null,
            'worked_at' => now(),
            'failure_code' => mb_substr($code, 0, 64),
            'failure_detail' => $detail,
        ])->save();
    }

    /**
     * The fact itself cannot be posted and no waiting will change that — an estimate with no policy behind it, a superseded row,
     * a figure nobody could determine. Kept and counted rather than dropped: a cost nobody posted belongs on a screen.
     */
    public function notPostable(OdooPosting $row, string $code): void
    {
        $row->forceFill([
            'state' => OdooPosting::DEAD, 'available_at' => null, 'claimed_at' => null, 'claimed_by' => null,
            'worked_at' => now(), 'failure_code' => mb_substr($code, 0, 64), 'outcome' => 'not_postable',
        ])->save();
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
        $row->forceFill([
            'attempts' => (int) $row->attempts + 1,
            'state' => OdooPosting::NEEDS_REVIEW,
            'available_at' => null,
            'claimed_at' => null,
            'claimed_by' => null,
            'worked_at' => now(),
            'needs_review_since' => $row->needs_review_since ?? now(),
            'failure_code' => mb_substr($code, 0, 64),
            'failure_detail' => $detail,
        ])->save();
    }

    /**
     * The claim goes back untouched: no attempt is counted, because no attempt was made.
     *
     * This is the switch-thrown-mid-run case. Somebody who turns posting off means it to stop now, and a run that counted that
     * as a failed attempt would spend the row's retries on their decision.
     */
    public function release(OdooPosting $row, string $code): void
    {
        $row->forceFill([
            'state' => OdooPosting::PENDING, 'available_at' => null, 'claimed_at' => null, 'claimed_by' => null,
            'failure_code' => mb_substr($code, 0, 64),
        ])->save();
    }

    /** Rows a run that died still holds, made pending again. Returns how many. No attempt is counted: none was finished. */
    public function reclaimAbandoned(): int
    {
        $cutoff = now()->subMinutes(max(1, (int) config('odoo.outbox.claim_minutes', 15)));

        return OdooPosting::where('state', OdooPosting::CLAIMED)->where('claimed_at', '<', $cutoff)->update([
            'state' => OdooPosting::PENDING, 'claimed_at' => null, 'claimed_by' => null, 'updated_at' => now(),
        ]);
    }

    /** What the queue looks like, for the screen a person reads. @return array<string, int> */
    public function counts(): array
    {
        $counts = array_fill_keys([OdooPosting::PENDING, OdooPosting::CLAIMED, OdooPosting::POSTED, OdooPosting::FAILED, OdooPosting::NEEDS_REVIEW, OdooPosting::DEAD], 0);
        foreach (OdooPosting::selectRaw('state, count(*) as total')->groupBy('state')->get() as $row) {
            $counts[(string) $row->state] = (int) $row->total;
        }

        return $counts;
    }

    /** Which run holds a row, so a run that died can be told from one still working. */
    public static function runId(): string
    {
        static $id = null;

        return $id ??= 'odoo-' . Str::uuid()->toString();
    }
}
