<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One line of what the platform admin API did. Written once, never updated, and never given a secret.
 */
class PlatformAdminAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['admin_id', 'action', 'market', 'token_id', 'ip', 'user_agent', 'context'];

    protected $casts = [
        'admin_id' => 'integer',
        'token_id' => 'integer',
        'context' => 'array',
        'created_at' => 'datetime',
    ];
}
