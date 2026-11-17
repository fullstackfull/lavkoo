<?php

namespace App\Models;

use App\Services\PlatformAdmin\Base32;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;

/**
 * The second factor of one platform admin: the TOTP secret, encrypted, and the recovery codes, as HMACs. Made and removed only by the console commands.
 *
 * It is pending until it is confirmed, and counts only once it is. Neither the secret nor the codes are ever serialised: they are hidden from
 * every array and JSON form of the model, and nothing that answers a request reads them except the check of a code.
 *
 * `recovery_codes` is a list of `{"hash": <HMAC-SHA-256 hex>, "used_at": <ISO 8601 or null>}`.
 */
class PlatformAdminMfaFactor extends Model
{
    protected $fillable = ['admin_id', 'grant_id', 'admin_fingerprint', 'secret', 'confirmed_at', 'last_used_step', 'recovery_codes'];

    protected $casts = [
        'admin_id' => 'integer',
        'grant_id' => 'integer',
        'secret' => 'encrypted',
        'confirmed_at' => 'datetime',
        'last_used_step' => 'integer',
        'recovery_codes' => 'array',
    ];

    protected $hidden = ['secret', 'recovery_codes', 'admin_fingerprint'];

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /** Whether this factor is one the admin may sign in with: confirmed, and made for exactly this admin row. */
    public function isActiveFor(Admin $admin): bool
    {
        return $this->isConfirmed() && $this->isMadeFor(admin: $admin);
    }

    /** Whether this factor was enrolled for exactly this admin row: the grant's own test, so that a row that merely carries the same id is not that admin. */
    public function isMadeFor(Admin $admin): bool
    {
        return hash_equals((string) $this->admin_fingerprint, PlatformAdminGrant::fingerprintOf(admin: $admin));
    }

    /**
     * The secret as the bytes the code is computed from, or null when it cannot be read: it was encrypted with another application key, or it is damaged.
     * The one place that lets go of it, and the one that tells the check of a code (which then fails closed) from a factor that works.
     */
    public function secretBytes(): ?string
    {
        try {
            $bytes = Base32::decode(text: (string) $this->secret);
        } catch (DecryptException) {
            return null;
        }

        return $bytes === null || $bytes === '' ? null : $bytes;
    }

    /** How many recovery codes have not been used. */
    public function recoveryCodesLeft(): int
    {
        return count(array_filter((array) $this->recovery_codes, static fn (array $code): bool => ($code['used_at'] ?? null) === null));
    }
}
