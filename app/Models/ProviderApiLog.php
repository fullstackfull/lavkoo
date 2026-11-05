<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One outbound provider API call, with secrets removed and bodies truncated. Never holds credentials. */
class ProviderApiLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'provider_api_logs';

    protected $guarded = [];

    protected $casts = [
        'provider_fulfillment_id' => 'integer',
        'request_summary' => 'array',
        'response_status' => 'integer',
        'success' => 'boolean',
        'duration_ms' => 'integer',
        'created_at' => 'datetime',
    ];
}
