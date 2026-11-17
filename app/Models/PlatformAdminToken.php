<?php

namespace App\Models;

use App\Enums\PlatformAdmin\MfaMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One session of the platform admin API. Only the hash of its token is kept, and it is hidden from every serialisation.
 */
class PlatformAdminToken extends Model
{
    protected $fillable = [
        'admin_id', 'grant_id', 'token_hash', 'password_fingerprint', 'device_name', 'ip', 'user_agent', 'mfa_method',
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

    /**
     * How this session passed the second factor, or null when it did not. A value that is none of the methods is read as none, so that a damaged row
     * is a session that did not pass it (refused while the second factor is required) and not a server error.
     */
    public function mfaMethod(): ?MfaMethod
    {
        return MfaMethod::tryFrom((string) ($this->getAttributes()['mfa_method'] ?? ''));
    }

    /**
     * Whether it has gone unused for the idle time: counted from the last use, or from the moment it was opened when it was never used, and over at the very
     * moment the time is up, as a session is at its expiry. A row with neither moment is idle: nothing says it was ever used.
     */
    public function isIdle(int $idleMinutes): bool
    {
        $lastActivity = $this->last_used_at ?? $this->created_at;

        return $lastActivity === null || !$lastActivity->copy()->addMinutes($idleMinutes)->isFuture();
    }

    /** Open sessions: not revoked, not expired and, when the idle time is given, used or opened within it. */
    public function scopeActive(Builder $query, ?int $idleMinutes = null): void
    {
        $query->whereNull('revoked_at')->where('expires_at', '>', now());

        if ($idleMinutes !== null) {
            $query->whereRaw('coalesce(last_used_at, created_at) > ?', [now()->subMinutes($idleMinutes)]);
        }
    }
}
