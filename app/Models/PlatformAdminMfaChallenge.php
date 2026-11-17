<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A sign-in with a good password that is waiting for the second factor. Only the hash of the challenge is kept, and it is hidden from every serialisation.
 */
class PlatformAdminMfaChallenge extends Model
{
    protected $fillable = ['token_hash', 'admin_id', 'grant_id', 'password_fingerprint', 'device_name', 'attempts', 'expires_at', 'consumed_at'];

    protected $casts = [
        'admin_id' => 'integer',
        'grant_id' => 'integer',
        'attempts' => 'integer',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    protected $hidden = ['token_hash', 'password_fingerprint'];

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isExpired(): bool
    {
        return !$this->expires_at->isFuture();
    }
}
