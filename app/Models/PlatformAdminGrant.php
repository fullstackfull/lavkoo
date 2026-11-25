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

    /**
     * The parts of the API a grant may include, in the order the API reports them. A grant that names none (SQL NULL) has every one of these EXCEPT the ones
     * listed as not given by default: adding a capability to this list would otherwise give it to every such grant, retroactively, the moment the code
     * shipped -- an operator trusted with yesterday's API acquiring tomorrow's without anybody deciding.
     */
    public const CAPABILITIES = ['overview', 'markets', 'alerts', 'audit', 'sessions', self::CAPABILITY_HEALTH, self::CAPABILITY_REPORTS, self::CAPABILITY_EXPORT];

    /** Operational health: payment attempts that never came back, and integrations that have stopped syncing (L-09). */
    public const CAPABILITY_HEALTH = 'health';

    /** A market's own summary over a window: its orders, its returns and what it sold, by the company that sold it and the currency (L-14). */
    public const CAPABILITY_REPORTS = 'reports';

    /** Taking a market's rows out of the platform as a file (L-14). The only capability behind which a fresh second factor is also asked for. */
    public const CAPABILITY_EXPORT = 'export';

    /**
     * Capabilities a grant has only when it NAMES them.
     *
     * The rule for everything above is that naming none means all, which is right for a list that was fixed when the first grant was made and wrong for a
     * list that grows. A capability added later goes in here, so that an existing grant keeps exactly what it was given and an operator who should have
     * the new part is given it on purpose. Health was the first: it reads payment and integration failures, and whether a particular operator should see
     * those is a decision, not an inheritance. Reports and exports are here for a stronger reason -- an export takes a market's rows OFF the platform,
     * and nobody should acquire that because a release shipped.
     */
    public const NOT_GIVEN_BY_DEFAULT = [self::CAPABILITY_HEALTH, self::CAPABILITY_REPORTS, self::CAPABILITY_EXPORT];

    protected $fillable = ['admin_id', 'admin_fingerprint', 'level', 'countries', 'capabilities', 'granted_by', 'granted_at', 'revoked_at', 'expires_at'];

    protected $casts = [
        'admin_id' => 'integer',
        'level' => 'integer',
        'granted_by' => 'integer',
        'countries' => 'array',
        'capabilities' => 'array',
        'granted_at' => 'datetime',
        'revoked_at' => 'datetime',
        'expires_at' => 'datetime',
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

    /** A grant is active until it is revoked, at a level that exists, and until the moment it ends. */
    public function isActive(): bool
    {
        return !$this->isWithdrawn() && !$this->hasExpired();
    }

    /** Whether the operator ended it (revoked it), or it is at a level that does not exist. */
    public function isWithdrawn(): bool
    {
        return $this->revoked_at !== null || $this->level < self::LEVEL_MONITOR;
    }

    /** Whether the moment it was to end has come: at that very moment it is over. A grant with no end has not. */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && !$this->expires_at->isFuture();
    }

    /**
     * The parts of the API this grant includes, in the order of CAPABILITIES. Only a column that is SQL NULL means every one: a value that cannot be read as a
     * list of capability names (a hand edit, damaged JSON) includes none instead of all, and a name that is not a capability is ignored.
     *
     * @return list<string>
     */
    public function capabilityList(): array
    {
        $stored = $this->getAttributes()['capabilities'] ?? null;

        if ($stored === null) {
            return array_values(array_diff(self::CAPABILITIES, self::NOT_GIVEN_BY_DEFAULT));
        }

        $decoded = is_string($stored) ? json_decode($stored, true) : $stored;

        if (!is_array($decoded) || !array_is_list($decoded)) {
            return [];
        }

        return array_values(array_intersect(self::CAPABILITIES, array_filter($decoded, is_string(...))));
    }

    public function allows(string $capability): bool
    {
        return in_array($capability, $this->capabilityList(), true);
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
