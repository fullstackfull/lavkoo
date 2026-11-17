<?php

namespace App\Services\PlatformAdmin;

use App\Enums\PlatformAdmin\MfaMethod;
use App\Models\Admin;
use App\Models\PlatformAdminGrant;
use App\Models\PlatformAdminToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

    public function __construct(private readonly PlatformAdminSettings $settings, private readonly PlatformMarkets $markets, private readonly PlatformAdminAudit $audit)
    {
    }

    /**
     * Opens a session. The admin's other open sessions are counted first, in a transaction that holds their grant so that two sign-ins of one admin cannot both
     * see room, and the oldest beyond what the limit leaves room for are ended: each of them is audited, naming the one that made room.
     */
    public function issue(Admin $admin, PlatformAdminGrant $grant, string $deviceName, ?string $ip, ?string $userAgent, ?MfaMethod $mfaMethod = null): PlatformAdminSession
    {
        return DB::transaction(function () use ($admin, $grant, $deviceName, $ip, $userAgent, $mfaMethod): PlatformAdminSession {
            PlatformAdminGrant::query()->whereKey($grant->getKey())->lockForUpdate()->first();

            $plain = self::PREFIX . Str::random(self::SECRET_LENGTH);
            $evicted = $this->endOldestBeyond(adminId: (int) $admin->getKey(), keep: $this->settings->sessionMaxConcurrent() - 1);

            $token = PlatformAdminToken::create([
                'admin_id' => $admin->getKey(),
                'grant_id' => $grant->getKey(),
                'token_hash' => hash('sha256', $plain),
                'password_fingerprint' => self::fingerprintOf(passwordHash: (string) $admin->getAuthPassword()),
                'device_name' => $deviceName,
                'ip' => $ip,
                'user_agent' => $userAgent === null ? null : PlatformAdminText::clipped(text: $userAgent, limit: self::USER_AGENT_LIMIT),
                'mfa_method' => $mfaMethod?->value,
                'expires_at' => now()->addMinutes($this->settings->tokenTtlMinutes()),
            ]);

            foreach ($evicted as $evictedId) {
                $this->audit->record(
                    action: 'session.evicted',
                    adminId: (int) $admin->getKey(),
                    tokenId: $evictedId,
                    ip: $ip,
                    userAgent: $userAgent,
                    context: ['by_token_id' => (int) $token->getKey(), 'limit' => $this->settings->sessionMaxConcurrent()],
                );
            }

            $this->deleteLongEndedSessionsOf(adminId: (int) $admin->getKey());

            return new PlatformAdminSession(principal: $this->principalOf(admin: $admin, grant: $grant, token: $token), plainToken: $plain);
        });
    }

    /**
     * Who holds this token, if anyone does. Every check is made on every request, and the answer to each is the same nothing:
     * a token that is unknown, ended, expired, of an admin who is no longer active or whose password has changed since, or whose grant is gone
     * or was made for another admin row (the row of the admin was deleted and another came to carry its id); and, while the second factor is required,
     * one that did not pass it, however it was opened.
     */
    public function principalFor(string $candidate, ?string $ip = null, ?string $userAgent = null): ?PlatformAdminPrincipal
    {
        if (preg_match(self::SHAPE, $candidate) !== 1) {
            return null;
        }

        $token = PlatformAdminToken::query()->where('token_hash', hash('sha256', $candidate))->first();

        if ($token === null || !$token->isActive() || ($this->settings->mfaRequired() && $token->mfaMethod() === null)) {
            return null;
        }

        if ($token->isIdle(idleMinutes: $this->settings->sessionIdleMinutes())) {
            $this->endIdle(token: $token, ip: $ip, userAgent: $userAgent);

            return null;
        }

        $admin = Admin::query()->select(['id', 'name', 'status', 'password', 'created_at'])->find($token->admin_id);

        if ($admin === null || (int) $admin->getRawOriginal('status') !== 1) {
            return null;
        }

        $grant = PlatformAdminGrant::query()->find($token->grant_id);

        if ($grant === null || $grant->admin_id !== $token->admin_id || !$grant->isMadeFor(admin: $admin)) {
            return null;
        }

        if ($grant->hasExpired() && !$grant->isWithdrawn()) {
            $this->endSessionsOfAnExpiredGrant(token: $token, grant: $grant, ip: $ip, userAgent: $userAgent);

            return null;
        }

        if (!$grant->isActive()) {
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

    /**
     * Ends every open session of the admin but one, and returns how many it ended. A session that was over already (ended, expired, idle) is not one of them,
     * and is left as it is.
     */
    public function endAllExcept(int $adminId, int $keptTokenId): int
    {
        return $this->openSessionsQuery(adminId: $adminId)->where('id', '!=', $keptTokenId)->update(['revoked_at' => now()]);
    }

    /** @return Collection<int, PlatformAdminToken> the sessions of an admin that are still open, newest first */
    public function openSessionsOf(int $adminId): Collection
    {
        return $this->openSessionsQuery(adminId: $adminId)->orderByDesc('id')->limit(self::LISTING_LIMIT)->get();
    }

    /** One of the open sessions of this admin, or null when there is none with that id: another admin's session is not found either. */
    public function openSessionOf(int $adminId, int $tokenId): ?PlatformAdminToken
    {
        return $this->openSessionsQuery(adminId: $adminId)->whereKey($tokenId)->first();
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

    /** @return Builder<PlatformAdminToken> the sessions of an admin that are open: not ended, not expired and not idle */
    private function openSessionsQuery(int $adminId): Builder
    {
        return PlatformAdminToken::query()->active($this->settings->sessionIdleMinutes())->where('admin_id', $adminId);
    }

    /**
     * Ends every open session of an admin whose grant has ended, on the first request that meets it, and says so in the trail once: the sessions that are ended
     * already are refused like any other and write nothing. Nothing is written when there was no open session to end.
     */
    private function endSessionsOfAnExpiredGrant(PlatformAdminToken $token, PlatformAdminGrant $grant, ?string $ip, ?string $userAgent): void
    {
        $ended = $this->openSessionsQuery(adminId: (int) $token->admin_id)->update(['revoked_at' => now()]);

        if ($ended > 0) {
            $this->audit->record(
                action: 'session.grant_expired',
                adminId: (int) $token->admin_id,
                tokenId: (int) $token->getKey(),
                ip: $ip,
                userAgent: $userAgent,
                context: ['expired_at' => PlatformAdminPresenter::timestamp(moment: $grant->expires_at), 'sessions_ended' => $ended],
            );
        }
    }

    /**
     * Ends a session that has been idle too long, on the first request made with it after, and says so in the trail. Only the request that ends it says so: a
     * session that is ended already is refused like any other and writes nothing.
     */
    private function endIdle(PlatformAdminToken $token, ?string $ip, ?string $userAgent): void
    {
        $ended = PlatformAdminToken::query()->whereKey($token->getKey())->whereNull('revoked_at')->update(['revoked_at' => now()]);

        if ($ended === 1) {
            $this->audit->record(
                action: 'session.idle_ended',
                adminId: (int) $token->admin_id,
                tokenId: (int) $token->getKey(),
                ip: $ip,
                userAgent: $userAgent,
                context: ['idle_minutes' => $this->settings->sessionIdleMinutes()],
            );
        }
    }

    /**
     * Ends the open sessions of an admin that are not among the newest `$keep`.
     *
     * @return list<int> the ids of the sessions it ended, oldest first
     */
    private function endOldestBeyond(int $adminId, int $keep): array
    {
        $surplus = $this->openSessionsQuery(adminId: $adminId)->orderByDesc('id')->pluck('id')
            ->slice($keep)
            ->map(static fn ($id): int => (int) $id)
            ->reverse()
            ->values()
            ->all();

        if ($surplus !== []) {
            PlatformAdminToken::query()->whereIn('id', $surplus)->update(['revoked_at' => now()]);
        }

        return $surplus;
    }

    private function deleteLongEndedSessionsOf(int $adminId): void
    {
        PlatformAdminToken::query()->where('admin_id', $adminId)->where('expires_at', '<', now()->subDays(self::RETENTION_DAYS))->delete();
    }
}
