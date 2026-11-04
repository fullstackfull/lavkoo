<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One seller SLA breach — open while out of policy, cleared when back inside it (Phase 3, Stage E).
 */
class SellerSlaBreach extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLEARED = 'cleared';

    protected $fillable = [
        'seller_id', 'metric', 'threshold', 'actual_value', 'status', 'cleared_at',
    ];

    protected $casts = [
        'seller_id' => 'integer',
        'threshold' => 'decimal:4',
        'actual_value' => 'decimal:4',
        'cleared_at' => 'datetime',
    ];

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
