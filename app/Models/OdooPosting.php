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

    public const CLAIMED = 'claimed';

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

    /** Rows a run may take: pending, and due. A `needs_review` row is deliberately not among them. */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('state', self::PENDING)
            ->where(fn (Builder $inner): Builder => $inner->whereNull('available_at')->orWhere('available_at', '<=', now()));
    }

    /** True once Odoo has reported an entry for this row: it is never created again, by anybody. */
    public function isPosted(): bool
    {
        return $this->state === self::POSTED && $this->odoo_move_id !== null;
    }

    public function claimIsLive(): bool
    {
        if ($this->state !== self::CLAIMED || $this->claimed_at === null) {
            return false;
        }

        return $this->claimed_at->gt(now()->subMinutes(max(1, (int) config('odoo.outbox.claim_minutes', 15))));
    }
}
