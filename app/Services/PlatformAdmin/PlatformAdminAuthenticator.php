<?php

namespace App\Services\PlatformAdmin;

use App\Models\Admin;
use App\Models\PlatformAdminGrant;
use App\Models\PlatformAdminMfaFactor;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The first step of signing in as a platform admin: the admin's own e-mail and password, checked against the admins table without starting a session, and
 * an active grant made for that admin row. While the second factor is required the admin must also have a confirmed one, and what a good password earns is
 * a challenge, not a session. Whatever is wrong, the caller learns only that it was not accepted, and the work done is the same: one lookup of the admin,
 * one check of the password (against a dummy hash when there is no such admin), one lookup of the grant and, while the second factor is required, one of the
 * factor.
 */
final class PlatformAdminAuthenticator
{
    private const DUMMY_PASSWORD_LENGTH = 40;

    /** Made once per process, with the same cost as every hash made here, so that an e-mail nobody uses is checked as slowly as one somebody does. */
    private static ?string $dummyHash = null;

    public function __construct(
        private readonly Hasher $hasher,
        private readonly PlatformAdminSettings $settings,
        private readonly PlatformAdminSessionOpener $sessions,
        private readonly PlatformAdminMfaFactors $factors,
        private readonly PlatformAdminMfaChallenges $challenges,
        private readonly PlatformAdminMfaLockout $lockout,
        private readonly PlatformAdminAudit $audit,
        private readonly PlatformAdminLoginFailures $failures,
    ) {
    }

    public function authenticate(LoginAttempt $attempt): SignInOutcome
    {
        $admin = Admin::query()->select(['id', 'name', 'status', 'password', 'created_at'])->where('email', $attempt->email)->first();
        $passwordMatches = $this->hasher->check($attempt->password, $admin === null ? $this->dummyHash() : (string) $admin->getAuthPassword());
        $grant = PlatformAdminGrant::query()->where('admin_id', $admin?->getKey() ?? 0)->first();
        $factor = $this->settings->mfaRequired() ? $this->factors->forAdmin(adminId: (int) ($admin?->getKey() ?? 0)) : null;

        $reason = $this->reasonToRefuse(attempt: $attempt, admin: $admin, passwordMatches: $passwordMatches, grant: $grant, factor: $factor);

        if ($reason !== null) {
            $this->failures->afterTheAnswer(reason: $reason, attempt: $attempt, grant: $grant);

            return SignInOutcome::refused();
        }

        if (!$this->settings->mfaRequired()) {
            return SignInOutcome::opened(DB::transaction(fn (): PlatformAdminSession => $this->sessions->open(admin: $admin, grant: $grant, deviceName: $attempt->deviceName, ip: $attempt->ip, userAgent: $attempt->userAgent)));
        }

        return $this->challengeUnlessLockedOut(attempt: $attempt, admin: $admin, grant: $grant);
    }

    private function reasonToRefuse(LoginAttempt $attempt, ?Admin $admin, bool $passwordMatches, ?PlatformAdminGrant $grant, ?PlatformAdminMfaFactor $factor): ?string
    {
        return match (true) {
            !$attempt->wellFormed => 'malformed_input',
            $admin === null => 'unknown_admin',
            !$passwordMatches => 'wrong_password',
            (int) $admin->getRawOriginal('status') !== 1 => 'inactive_admin',
            $grant === null => 'no_grant',
            !$grant->isMadeFor(admin: $admin) => 'grant_of_another_admin_row',
            !$grant->isActive() => 'revoked_grant',
            $this->settings->mfaRequired() && ($factor === null || !$factor->isActiveFor(admin: $admin)) => 'mfa_not_enrolled',
            default => null,
        };
    }

    /**
     * A good password with a confirmed second factor behind it: a challenge for the second step. An admin who is locked out of the second step is told so, and
     * given none: it is only said to somebody who gave the right password, so it tells a stranger nothing about who exists or who is locked.
     */
    private function challengeUnlessLockedOut(LoginAttempt $attempt, Admin $admin, PlatformAdminGrant $grant): SignInOutcome
    {
        $adminId = (int) $admin->getKey();

        if ($this->lockout->isLocked(adminId: $adminId)) {
            return SignInOutcome::throttled(retryAfterSeconds: $this->lockout->secondsUntilAvailable(adminId: $adminId));
        }

        return SignInOutcome::challenged(DB::transaction(function () use ($attempt, $admin, $grant, $adminId): IssuedChallenge {
            $challenge = $this->challenges->issue(admin: $admin, grant: $grant, deviceName: $attempt->deviceName);
            $this->audit->record(action: 'auth.mfa_challenge', adminId: $adminId, ip: $attempt->ip, userAgent: $attempt->userAgent, context: ['device_name' => $attempt->deviceName]);

            return $challenge;
        }));
    }

    private function dummyHash(): string
    {
        return self::$dummyHash ??= $this->hasher->make(Str::random(self::DUMMY_PASSWORD_LENGTH));
    }
}
