<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One outbound call to a sales channel, with everything secret or personal removed before it was stored. */
class SalesChannelApiLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'sales_channel_api_logs';

    protected $guarded = [];

    protected $casts = [
        'account_id' => 'integer',
        'request_summary' => 'array',
        'success' => 'boolean',
        'dry_run' => 'boolean',
    ];
}
