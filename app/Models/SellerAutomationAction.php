<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One thing a rule did, or declined to do, to one record.
 *
 * `before` and `after` are the whole point. A seller who disagrees with an automated change needs
 * to be able to see exactly what it was and put it back, and a marketplace investigating a dispute
 * needs the same. A row with no `before` is an action nobody can undo.
 */
class SellerAutomationAction extends Model
{
    public const STATUS_APPLIED = 'applied';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    public const SUBJECT_PRODUCT = 'product';
    public const SUBJECT_ORDER = 'order';

    protected $fillable = [
        'run_id',
        'rule_id',
        'seller_id',
        'subject_type',
        'subject_id',
        'subject_label',
        'action',
        'status',
        'reason',
        'before',
        'after',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'reverted_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    public function isRevertible(): bool
    {
        return $this->status === self::STATUS_APPLIED
            && $this->reverted_at === null
            // Somebody has changed this since. Putting back what the rule replaced would overwrite
            // their decision with one taken before it.
            && $this->superseded_at === null
            && !empty($this->before);
    }
}
