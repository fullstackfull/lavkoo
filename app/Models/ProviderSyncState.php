<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Operational timestamps per provider capability: last received update, last success, last failure,
 * last reconciliation. What the Integration Center shows as "last successful synchronization" and
 * "last API error".
 */
class ProviderSyncState extends Model
{
    protected $table = 'provider_sync_states';

    protected $guarded = [];

    protected $casts = [
        'last_received_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
        'last_reconciled_at' => 'datetime',
        'cursor' => 'array',
        'stats' => 'array',
    ];

    public static function forCapability(string $provider, string $capability): ?self
    {
        return self::where('provider', $provider)->where('capability', $capability)->first();
    }
}
