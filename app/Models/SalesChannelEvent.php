<?php

namespace App\Models;

use App\Models\Concerns\StoresLocalTime;
use Illuminate\Database\Eloquent\Model;

/** An inbound webhook or notification (or an internal event worth auditing), stored once however often it is delivered. */
class SalesChannelEvent extends Model
{
    use StoresLocalTime;

    protected $table = 'sales_channel_events';

    protected $guarded = [];

    protected $casts = [
        'account_id' => 'integer',
        'payload' => 'array',
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
