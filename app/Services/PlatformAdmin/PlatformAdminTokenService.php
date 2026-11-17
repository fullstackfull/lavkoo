<?php

namespace App\Services\PlatformAdmin;

use App\Models\Admin;
use App\Models\PlatformAdminGrant;
use App\Models\PlatformAdminToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The sessions of the platform admin API: opened at sign-in, checked on every request, and ended here.
 *
 * A token is 64 random characters behind a prefix that says what it is, so that one found in a log or a leaked file can be revoked on sight.
 * Only its SHA-256 hash is stored. The token is random, not chosen by a person, so a fast hash is enough and the lookup can be one indexed read.
 */
final class PlatformAdminTokenService
{
    private const PREFIX = 'lkpa_';

    private const SECRET_LENGTH = 64;

    private const SHAPE = '/^' . self::PREFIX . '[A-Za-z0-9]{' . self::SECRET_LENGTH . '}$/';

    private const USER_AGENT_LIMIT = 255;

    /** A session that ended longer ago than this is deleted at the next sign-in of its admin, so the table stays the size of what is in use. */
    private const RETENTION_DAYS = 30;

    /** The time a session was last used is written at most this often: a screen that polls would otherwise write on every request. */
    private const USE_RECORDING_INTERVAL_SECONDS = 60;

    private const LISTING_LIMIT = 100;

    public function __construct(private readonly PlatformAdminSettings $settings, private readonly PlatformMarkets $markets)
    {
    }

    public function issue(Admin $admin, PlatformAdminGrant $grant, string $deviceName, ?string $ip, ?string $userAgent): PlatformAdminSession
    {
        $plain = self::PREFIX . Str::random(self::SECRET_LENGTH);

        $token = PlatformAdminToken::create([
            'admin_id' => $admin->getKey(),
            'grant_id' => $grant->getKey(),
            'token_hash' => hash('sha256', $plain),
            'password_fingerprint' => self::fingerprintOf(passwordHash: (string) $admin->getAuthPassword()),
            'device_name' => $deviceName,
            'ip' => $ip,
            'user_agent' => $userAgent === null ? null : PlatformAdminText::clipped(text: $userAgent, limit: self::USER_AGENT_LIMIT),
            'expires_at' => now()->addMinutes($this->settings->tokenTtlMinutes()),
        ]);

        $this->deleteLongEndedSessionsOf(adminId: (int) $admin->getKey());

        return new PlatformAdminSession(principal: $this->principalOf(admin: $admin, grant: $grant, token: $token), plainToken: $plain);
    }

    /**
     * Who holds this token, if anyone does. Every check is made on every request, and the answer to each is the same nothing:
     * a token that is unknown, ended, expired, of an admin who is no longer active or whose password has changed since, or whose grant is gone
     * or was made for another admin row (the row of the admin was deleted and another came to carry its id).
     */
    public function principalFor(string $candidate): ?PlatformAdminPrincipal
    {
        if (preg_match(self::SHAPE, $candidate) !== 1) {
            return null;
        }

        $token = PlatformAdminToken::query()->where('token_hash', hash('sha256', $candidate))->first();

        if ($token === null || !$token->isActive()) {
            return null;
        }

        $admin = Admin::query()->select(['id', 'name', 'status', 'password', 'created_at'])->find($token->admin_id);

        if ($admin === null || (int) $admin->getRawOriginal('status') !== 1) {
            return null;
        }

        $grant = PlatformAdminGrant::query()->find($token->grant_id);

        if ($grant === null || !$grant->isActive() || $grant->admin_id !== $token->admin_id || !$grant->isMadeFor(admin: $admin)) {
            return null;
        }

        if (!hash_equals($token->password_fingerprint, self::fingerprintOf(passwordHash: (string) $admin->getAuthPassword()))) {
            return null;
        }

        return $this->principalOf(admin: $admin, grant: $grant, token: $token);
    }

    public function recordUse(PlatformAdminToken $token): void
    {
        $token->newQuery()
            ->whereKey($token->getKey())
            ->where(static fn (Builder $query) => $query->whereNull('last_used_at')->orWhere('last_used_at', '<=', now()->subSeconds(self::USE_RECORDING_INTERVAL_SECONDS)))
            ->toBase()
            ->update(['last_used_at' => now()]);
    }

    public function revoke(PlatformAdminToken $token): void
    {
        $token->forceFill(['revoked_at' => now()])->save();
    }

    /** Ends every session of an admin that is not already ended, and returns how many it ended. Rows revoked earlier keep their own time. */
    public function revokeAllFor(int $adminId): int
    {
        return PlatformAdminToken::query()->where('admin_id', $adminId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    /** @return Collection<int, PlatformAdminToken> the sessions of an admin that are still open, newest first */
    public function openSessionsOf(int $adminId): Collection
    {
        return PlatformAdminToken::query()->active()->where('admin_id', $adminId)->orderByDesc('id')->limit(self::LISTING_LIMIT)->get();
    }

    /** One of the open sessions of this admin, or null when there is none with that id: another admin's session is not found either. */
    public function openSessionOf(int $adminId, int $tokenId): ?PlatformAdminToken
    {
        return PlatformAdminToken::query()->active()->where('admin_id', $adminId)->whereKey($tokenId)->first();
    }

    /** The SHA-256 of a password hash: what a session remembers of the password it was opened with, so any change of it ends every session. */
    public static function fingerprintOf(string $passwordHash): string
    {
        return hash('sha256', $passwordHash);
    }

    private function principalOf(Admin $admin, PlatformAdminGrant $grant, PlatformAdminToken $token): PlatformAdminPrincipal
    {
        return new PlatformAdminPrincipal(admin: $admin, grant: $grant, token: $token, markets: $this->markets->allowedBy(grant: $grant));
    }

    private function deleteLongEndedSessionsOf(int $adminId): void
    {
        PlatformAdminToken::query()->where('admin_id', $adminId)->where('expires_at', '<', now()->subDays(self::RETENTION_DAYS))->delete();
    }
}
