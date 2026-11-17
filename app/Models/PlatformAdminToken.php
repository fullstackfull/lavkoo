<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One session of the platform admin API. Only the hash of its token is kept, and it is hidden from every serialisation.
 */
class PlatformAdminToken extends Model
{
    protected $fillable = [
        'admin_id', 'grant_id', 'token_hash', 'password_fingerprint', 'device_name', 'ip', 'user_agent',
        'last_used_at', 'expires_at', 'revoked_at',
    ];

    protected $casts = [
        'admin_id' => 'integer',
        'grant_id' => 'integer',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected $hidden = ['token_hash', 'password_fingerprint'];

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }
}
