<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One evaluation of one rule.
 *
 * Kept even when nothing matched, because "this rule has found nothing for a week" and "this rule
 * has not run for a week" are different problems with different answers, and a seller cannot tell
 * them apart from the rule row alone.
 */
class SellerAutomationRun extends Model
{
    public const OUTCOME_APPLIED = 'applied';
    public const OUTCOME_NO_MATCH = 'no_match';
    public const OUTCOME_CAPPED = 'capped';
    public const OUTCOME_FAILED = 'failed';

    protected $fillable = [
        'rule_id',
        'seller_id',
        'outcome',
        'matched_count',
        'applied_count',
        'skipped_count',
        'failed_count',
        'dry_run',
        'message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'matched_count' => 'integer',
        'applied_count' => 'integer',
        'skipped_count' => 'integer',
        'failed_count' => 'integer',
        'dry_run' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function actions(): HasMany
    {
        return $this->hasMany(SellerAutomationAction::class, 'run_id');
    }
}
