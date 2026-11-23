<?php

namespace App\Services\PlatformAdmin;

use App\Models\Admin;
use App\Models\PlatformAdminGrant;
use App\Models\PlatformAdminMfaChallenge;
use Illuminate\Support\Str;

/**
 * The sign-ins that have a good password and wait for the second factor: given at the first step, counted at every try of the second, consumed by the
 * first success or by the last try.
 *
 * A challenge is 48 random characters behind a prefix that says what it is. Only its SHA-256 is stored, as with a token: it is random, so a fast hash is
 * enough and the lookup is one indexed read.
 */
final class PlatformAdminMfaChallenges
{
    public const NOT_GIVEN = 'unknown_challenge';

    public const CONSUMED = 'consumed_challenge';

    public const EXPIRED = 'expired_challenge';

    public const USED_UP = 'attempts_exhausted';

    private const PREFIX = 'lkpc_';

    private const SECRET_LENGTH = 48;

    /** A challenge that ended longer ago than this is deleted at the next sign-in of its admin, so the table stays the size of what is in use. */
    private const RETENTION_DAYS = 1;

    public function __construct(private readonly PlatformAdminSettings $settings)
    {
    }

    public function issue(Admin $admin, PlatformAdminGrant $grant, string $deviceName): IssuedChallenge
    {
        $plain = self::PREFIX . Str::random(self::SECRET_LENGTH);

        $challenge = PlatformAdminMfaChallenge::create([
            'token_hash' => hash('sha256', $plain),
            'admin_id' => $admin->getKey(),
            'grant_id' => $grant->getKey(),
            'password_fingerprint' => PlatformAdminTokenService::fingerprintOf(passwordHash: (string) $admin->getAuthPassword()),
            'device_name' => $deviceName,
            'expires_at' => now()->addMinutes($this->settings->mfaChallengeTtlMinutes()),
        ]);

        $this->deleteLongEndedChallengesOf(adminId: (int) $admin->getKey());

        return new IssuedChallenge(plain: $plain, expiresAt: $challenge->expires_at);
    }

    /** The challenge a caller sent, if it is one that was given. Whatever was sent is looked up by its hash, so a value of the wrong shape finds nothing at the same cost. */
    public function find(string $candidate): ?PlatformAdminMfaChallenge
    {
        return PlatformAdminMfaChallenge::query()->where('token_hash', hash('sha256', $candidate))->first();
    }

    /** Why a try on this challenge cannot be made, or null when it can: there is no such challenge, it is consumed, it is over, or its tries are used up. */
    public function whyNoTryCanBeMade(?PlatformAdminMfaChallenge $challenge): ?string
    {
        return match (true) {
            $challenge === null => self::NOT_GIVEN,
            $challenge->isConsumed() => self::CONSUMED,
            $challenge->isExpired() => self::EXPIRED,
            $challenge->attempts >= $this->settings->mfaMaxChallengeAttempts() => self::USED_UP,
            default => null,
        };
    }

    /**
     * Counts a try, in one statement that holds only while the challenge is still good, so that two requests cannot both take the last try. Returns how many
     * tries the challenge has had, this one included, or null when it could not be counted because it was used up or consumed in the meantime.
     */
    public function countTry(PlatformAdminMfaChallenge $challenge): ?int
    {
        $counted = PlatformAdminMfaChallenge::query()
            ->whereKey($challenge->getKey())
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', $this->settings->mfaMaxChallengeAttempts())
            ->increment('attempts');

        return $counted === 1 ? (int) PlatformAdminMfaChallenge::query()->whereKey($challenge->getKey())->value('attempts') : null;
    }

    /** Whether it is used up: the tries it has had are all it allows. */
    public function allowsNoMoreTries(int $triesMade): bool
    {
        return $triesMade >= $this->settings->mfaMaxChallengeAttempts();
    }

    /** Consumes the challenge, if it is not consumed already. Returns whether it was this call that did. */
    public function consume(PlatformAdminMfaChallenge $challenge): bool
    {
        return PlatformAdminMfaChallenge::query()->whereKey($challenge->getKey())->whereNull('consumed_at')->update(['consumed_at' => now()]) === 1;
    }

    /** Consumes every challenge of the admin that is not consumed yet, and returns how many it ended. A challenge consumed earlier keeps its own time. */
    public function endAllFor(int $adminId): int
    {
        return PlatformAdminMfaChallenge::query()->where('admin_id', $adminId)->whereNull('consumed_at')->update(['consumed_at' => now()]);
    }

    private function deleteLongEndedChallengesOf(int $adminId): void
    {
        PlatformAdminMfaChallenge::query()->where('admin_id', $adminId)->where('expires_at', '<', now()->subDays(self::RETENTION_DAYS))->delete();
    }
}
