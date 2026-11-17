<?php

namespace App\Models;

use App\Models\Builders\PlatformAdminAuditLogBuilder;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of what the platform admin API did. Written once by the audit writer, which chains it to the line before, never updated or deleted through the
 * application, and never given a secret. Every way the model has of changing or removing a line (update, save, delete, destroy, increment) runs a query, and the
 * query this model builds refuses (see PlatformAdminAuditLogBuilder); the prune command works on the table itself.
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

    /**
     * @param \Illuminate\Database\Query\Builder $query
     *
     * @return PlatformAdminAuditLogBuilder<static>
     */
    public function newEloquentBuilder($query): PlatformAdminAuditLogBuilder
    {
        return new PlatformAdminAuditLogBuilder($query);
    }
}
