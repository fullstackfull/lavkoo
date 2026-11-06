<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Where an account's incremental synchronisation of one resource has got to: the watermark, the cursor, the last outcome, the
 * back-off after repeated failures and the lease that keeps two runs from working on the same account and resource at once.
 */
class SalesChannelSyncState extends Model
{
    protected $table = 'sales_channel_sync_states';

    protected $guarded = [];

    protected $casts = [
        'account_id' => 'integer',
        'cursor' => 'array',
        'watermark' => 'datetime',
        'last_attempt_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
        'backoff_until' => 'datetime',
        'lease_expires_at' => 'datetime',
        'consecutive_failures' => 'integer',
        'backfill' => 'array',
        'stats' => 'array',
    ];
}
