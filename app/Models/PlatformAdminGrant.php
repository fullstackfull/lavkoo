<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The right of one admin to use the platform admin API (GCC-20). Created and revoked only on the server; no request can change it.
 */
class PlatformAdminGrant extends Model
{
    /** Read-only monitoring. The only level that exists; a later level includes it. */
    public const LEVEL_MONITOR = 1;

    protected $fillable = ['admin_id', 'admin_fingerprint', 'level', 'countries', 'granted_by', 'granted_at', 'revoked_at'];

    protected $casts = [
        'admin_id' => 'integer',
        'level' => 'integer',
        'granted_by' => 'integer',
        'countries' => 'array',
        'granted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /**
     * What a grant remembers of the admin row it was made for: the id and the moment the row was created, hashed. The id alone would let a row
     * that comes to carry it later inherit the grant (a deleted admin whose id is reused, a restored dump); the creation time of a row does not
     * change when the admin edits it. A row without a creation time is told apart by its id alone. The admin must have been read with `created_at`.
     */
    public static function fingerprintOf(Admin $admin): string
    {
        return hash('sha256', $admin->getKey() . '|' . $admin->getRawOriginal('created_at'));
    }

    /** Whether this grant was made for exactly this admin row: a different row that merely has the same id is not the admin it was made for. */
    public function isMadeFor(Admin $admin): bool
    {
        return hash_equals((string) $this->admin_fingerprint, self::fingerprintOf(admin: $admin));
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->level >= self::LEVEL_MONITOR;
    }

    /**
     * The markets this grant is limited to, or null for every market. Only a column that is SQL NULL means every market: a value that cannot
     * be read as a list of country codes (a hand edit, a damaged JSON text) grants no market at all instead of all of them.
     *
     * @return list<string>|null
     */
    public function countryList(): ?array
    {
        $stored = $this->getAttributes()['countries'] ?? null;

        if ($stored === null) {
            return null;
        }

        $decoded = is_string($stored) ? json_decode($stored, true) : $stored;

        if (!is_array($decoded) || !array_is_list($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, is_string(...)));
    }
}
