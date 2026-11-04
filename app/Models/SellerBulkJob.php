<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The receipt for one bulk operation.
 *
 * The point of the row is honesty about a partial result. A bulk change over hundreds of products
 * will not all land — one product was deleted while the seller was choosing, another is a variant
 * product whose stock lives per variant, a third would be driven below zero. The operation must
 * still do the rest, and must be able to say afterwards exactly which rows it did not do and why.
 *
 * `partial` is therefore a first-class outcome, not an error: it means the job ran to the end and
 * some rows were refused. `failed` means nothing landed.
 */
class SellerBulkJob extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';

    /** A job still moving. The app polls these; the finished ones it can cache. */
    public const OPEN_STATUSES = [self::STATUS_QUEUED, self::STATUS_PROCESSING];

    protected $fillable = [
        'seller_id',
        'created_by_staff_id',
        'created_by_api_key_id',
        'type',
        'status',
        'total',
        'processed',
        'succeeded',
        'failed',
        'failures',
        'input',
        'error',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'seller_id' => 'integer',
        'created_by_staff_id' => 'integer',
        'total' => 'integer',
        'processed' => 'integer',
        'succeeded' => 'integer',
        'failed' => 'integer',
        'failures' => 'array',
        'input' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class, 'seller_id');
    }

    public function scopeForSeller($query, int|string $sellerId)
    {
        return $query->where('seller_id', $sellerId);
    }

    public function isFinished(): bool
    {
        return !in_array($this->status, self::OPEN_STATUSES, true);
    }

    /**
     * How far along, for a progress bar that does not lie.
     *
     * A job with nothing to do is finished, not zero percent — otherwise an empty selection would
     * sit at 0% forever.
     */
    public function progress(): int
    {
        if ($this->total < 1) {
            return $this->isFinished() ? 100 : 0;
        }

        return (int) min(100, round($this->processed / $this->total * 100));
    }
}
