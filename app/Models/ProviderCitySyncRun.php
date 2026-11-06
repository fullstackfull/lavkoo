<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One attempt to refresh a provider's city list, and whether it was complete. */
class ProviderCitySyncRun extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'provider_city_sync_runs';

    protected $guarded = [];

    protected $casts = [
        'request' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
