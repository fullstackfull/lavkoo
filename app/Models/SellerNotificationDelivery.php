<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One thing the platform told a seller, and whether it got there. */
class SellerNotificationDelivery extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SUPPRESSED = 'suppressed';

    protected $fillable = [
        'seller_id', 'topic', 'severity', 'title', 'body', 'subject_count',
        'action_key', 'action_params', 'digest_key', 'status', 'channels', 'error', 'sent_at', 'read_at',
    ];

    protected $casts = [
        'seller_id' => 'integer',
        'subject_count' => 'integer',
        'action_params' => 'array',
        'channels' => 'array',
        'sent_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    public function scopeForSeller($query, int|string $sellerId)
    {
        return $query->where('seller_id', $sellerId);
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }
}
