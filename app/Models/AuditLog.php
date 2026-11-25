<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One recorded action, anywhere in the system (Phase 3 — spec item 84).
 *
 * Append-only: never edited, never deleted by application code.
 */
class AuditLog extends Model
{
    protected $fillable = [
        'actor_type', 'actor_id', 'actor_name', 'action',
        'subject_type', 'subject_id', 'before', 'after', 'context',
        'ip_address', 'user_agent',
        // Which market and company the line concerns (G1-08). Null for a line written before they were recorded, and for a panel action
        // that belongs to no one market -- an administrator sees every country, so filing it as the default market's would invent a fact.
        'storefront_country', 'legal_entity_id',
    ];

    protected $casts = [
        'actor_id' => 'integer',
        'legal_entity_id' => 'integer',
        'before' => 'array',
        'after' => 'array',
        'context' => 'array',
    ];

    /** Everything recorded against one record, newest first — the record's activity timeline. */
    public function scopeForSubject(Builder $query, string $type, int|string $id): Builder
    {
        return $query->where('subject_type', $type)->where('subject_id', $id)->latest('id');
    }

    /** Filter by module using the action prefix: 'settlement.*', 'payout.*'. */
    public function scopeInModule(Builder $query, string $module): Builder
    {
        return $query->where('action', 'like', $module . '.%');
    }

    /**
     * The human-readable module name, taken from the action prefix.
     *
     * Null-tolerant on purpose: a projection query (`selectRaw('... as module')->pluck('module')`)
     * hydrates rows where `action` was never selected, and this accessor still fires on them. It
     * must return a string in that case rather than fatal.
     */
    public function getModuleAttribute(): string
    {
        $action = (string) ($this->attributes['action'] ?? '');

        return str_contains($action, '.') ? explode('.', $action)[0] : $action;
    }
}
