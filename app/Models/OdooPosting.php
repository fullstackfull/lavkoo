<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One fact waiting to become one journal entry in the customer's Odoo — and the row that makes a retry safe.
 *
 * `(source_type, source_id, source_version)` is unique, so the same fact at the same version cannot be queued twice; `odoo_ref`
 * is the same key written into the entry at Odoo, so "did my last attempt land?" is a question Odoo can answer. Odoo itself
 * offers neither: no external-reference field, no constraint, no upsert.
 *
 * @property int $id
 * @property string $source_type
 * @property int $source_id
 * @property string $source_version
 * @property string $odoo_ref
 * @property string $state
 * @property int $attempts
 * @property int|null $odoo_move_id
 * @property string|null $failure_code
 */
class OdooPosting extends Model
{
    public const PENDING = 'pending';

    /**
     * **A create may be at Odoo — written BEFORE the request leaves, never after it returns.**
     *
     * The same hole the noon shipments had, and it was here too: ownership was a state (`claimed`), so a row being worked read
     * as a row nothing had been posted for. A run that died between `account.move.create` leaving this host and PHP recording
     * the answer left exactly that; `reclaimAbandoned()` then made it `pending` again, the next run asked Odoo by the reference,
     * read "no such entry" -- which a lagging read can say as easily as an absent entry -- and posted the cost a second time
     * into somebody's books, where a duplicate is corrected only by a reversal somebody has to explain.
     *
     * So ownership lives in `claimed_by` and `claimed_at` ALONE, and this state is only ever about the entry. A row carrying it
     * may be ASKED about for ever; it may never be created again by a machine.
     */
    public const MAY_HAVE_POSTED = 'may_have_posted';

    /** Odoo reports the entry. A DRAFT: the final posting is a separate, approved step this version does not take. */
    public const POSTED = 'posted';

    /**
     * Not posted, and why is recorded.
     *
     * With `available_at` set it will be tried again; without one it waits on a person or on a changed mapping. A fault is the
     * second kind: Odoo arrived at an answer and the same request would be refused again.
     */
    public const FAILED = 'failed';

    /**
     * **THE STATE THAT NEVER RETRIES ITSELF.** A create went out and Odoo can neither confirm nor deny the entry. Retrying might
     * post the same cost twice; abandoning it might lose it. Neither is a machine's choice, so it waits for a person.
     */
    public const NEEDS_REVIEW = 'needs_review';

    /** Nothing more will be tried: the attempts ran out, or the fact itself is not postable. Kept and counted, never dropped. */
    public const DEAD = 'dead';

    protected $table = 'odoo_posting_queue';

    protected $guarded = [];

    protected $casts = [
        'seller_id' => 'integer',
        'odoo_company_id' => 'integer',
        'source_id' => 'integer',
        'attempts' => 'integer',
        'odoo_move_id' => 'integer',
        'reviewed_by' => 'integer',
        'available_at' => 'datetime',
        'claimed_at' => 'datetime',
        'worked_at' => 'datetime',
        'posted_at' => 'datetime',
        'verified_at' => 'datetime',
        'needs_review_since' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    /**
     * Rows a run may take: due, not owned by a live run, and in a state that still has something to do.
     *
     * `may_have_posted` IS among them, because such a row still has a question to put to Odoo -- and it is the state itself,
     * not the claim, that stops the run from creating anything. `needs_review` is deliberately not: that one waits for a person
     * and no amount of time makes it due.
     *
     * Ownership is checked here rather than inferred from the state, which is the whole of the fix: a lease expiring frees the
     * row without changing what is known about the entry.
     */
    public function scopeDue(Builder $query): Builder
    {
        $cutoff = now()->subMinutes(max(1, (int) config('odoo.outbox.claim_minutes', 15)));

        return $query->whereIn('state', [self::PENDING, self::MAY_HAVE_POSTED])
            ->where(fn (Builder $inner): Builder => $inner->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->where(fn (Builder $inner): Builder => $inner->whereNull('claimed_by')->orWhere('claimed_at', '<', $cutoff));
    }

    /** True once Odoo has reported an entry for this row: it is never created again, by anybody. */
    public function isPosted(): bool
    {
        return $this->state === self::POSTED && $this->odoo_move_id !== null;
    }

    /**
     * True while a run owns this row.
     *
     * It reads the claim and NOTHING ELSE. Tying it to the state is what let a row being worked look like a row with no create
     * behind it.
     */
    public function claimIsLive(): bool
    {
        if ($this->claimed_by === null || $this->claimed_at === null) {
            return false;
        }

        return $this->claimed_at->gt(now()->subMinutes(max(1, (int) config('odoo.outbox.claim_minutes', 15))));
    }

    /**
     * True once a create may have reached Odoo: from here a run may ASK about the entry and may never create it again.
     *
     * It survives an expired lease, a crash and a takeover, because it is a fact about the books rather than about a process.
     */
    public function mayHavePosted(): bool
    {
        return $this->state === self::MAY_HAVE_POSTED;
    }
}
