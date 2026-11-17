<?php

namespace App\Services\PlatformAdmin;

use App\Enums\PlatformAdmin\MfaMethod;
use App\Models\Admin;
use App\Models\PlatformAdminGrant;
use App\Models\PlatformAdminMfaChallenge;
use App\Models\PlatformAdminMfaFactor;
use Illuminate\Support\Facades\DB;

/**
 * The second step of signing in: a challenge and a code, or a challenge and a recovery code, for a session.
 *
 * Whatever is wrong, the caller learns only that it was not accepted, and the work done is the same: the four lookups, and one check of a code, against the
 * factor when there is one to check against and against nothing when there is not (a challenge nobody was given or that is over, an admin who may not sign
 * in), so that a refusal costs as much whatever the reason. The one exception is a locked-out admin whose challenge is still good, who is told so (429) and
 * whose code is not checked or counted: it is a thing only somebody who holds a good challenge can see.
 *
 * A try is counted on the challenge before the code is looked at, in one statement that holds only while the challenge is still good. The check of the code and
 * the use of what was right run in one transaction that holds the row of the factor, so that two requests with the same code cannot both succeed.
 */
final class PlatformAdminSecondStep
{
    public function __construct(
        private readonly PlatformAdminMfaChallenges $challenges,
        private readonly PlatformAdminMfaFactors $factors,
        private readonly PlatformAdminMfaVerifier $verifier,
        private readonly PlatformAdminMfaLockout $lockout,
        private readonly PlatformAdminSessionOpener $sessions,
        private readonly PlatformAdminAudit $audit,
        private readonly PlatformAdminLoginFailures $failures,
    ) {
    }

    public function verify(MfaAttempt $attempt): SignInOutcome
    {
        $challenge = $this->challenges->find(candidate: $attempt->challenge);
        $adminId = (int) ($challenge?->admin_id ?? 0);
        $admin = Admin::query()->select(['id', 'name', 'status', 'password', 'created_at'])->find($adminId);
        $grant = PlatformAdminGrant::query()->find((int) ($challenge?->grant_id ?? 0));
        $factor = $this->factors->forAdmin(adminId: $adminId);

        $reason = $this->challenges->whyNoTryCanBeMade(challenge: $challenge);
        $triesMade = null;

        if ($reason === null) {
            if ($this->lockout->isLocked(adminId: $adminId)) {
                return SignInOutcome::throttled(retryAfterSeconds: $this->lockout->secondsUntilAvailable(adminId: $adminId));
            }

            $triesMade = $this->challenges->countTry(challenge: $challenge);
            $reason = $triesMade === null ? PlatformAdminMfaChallenges::CONSUMED : $this->whyNotCompletable(challenge: $challenge, admin: $admin, grant: $grant, factor: $factor);
        }

        if ($reason === null) {
            [$verdict, $session] = $this->checkAndOpen(attempt: $attempt, challenge: $challenge, admin: $admin, grant: $grant);

            return $session !== null
                ? SignInOutcome::opened(session: $session, verdict: $verdict)
                : $this->refuse(reason: (string) $verdict->reason, attempt: $attempt, challenge: $challenge, grant: $grant, triesMade: $triesMade, wasAGuess: $verdict->wasAGuess());
        }

        $this->verifier->useCodeOf(attempt: $attempt, factor: null);

        return $this->refuse(reason: $reason, attempt: $attempt, challenge: $challenge, grant: $grant, triesMade: $triesMade, wasAGuess: false);
    }

    /** What is the matter with a sign-in whose challenge is still good, or null when nothing is. The order is the sign-in's. */
    private function whyNotCompletable(PlatformAdminMfaChallenge $challenge, ?Admin $admin, ?PlatformAdminGrant $grant, ?PlatformAdminMfaFactor $factor): ?string
    {
        return match (true) {
            $admin === null || (int) $admin->getRawOriginal('status') !== 1 => 'inactive_admin',
            $grant === null => 'no_grant',
            $grant->admin_id !== $challenge->admin_id || !$grant->isMadeFor(admin: $admin) => 'grant_of_another_admin_row',
            $grant->isWithdrawn() => 'revoked_grant',
            $grant->hasExpired() => 'expired_grant',
            !hash_equals($challenge->password_fingerprint, PlatformAdminTokenService::fingerprintOf(passwordHash: (string) $admin->getAuthPassword())) => 'password_changed',
            $factor === null || !$factor->isActiveFor(admin: $admin) => MfaVerdict::UNUSABLE_FACTOR,
            default => null,
        };
    }

    /**
     * Checks the code against the factor with its row held, and when it is right uses it up, opens the session and consumes the challenge, all or nothing. The
     * order of what is held is the factor, then the grant (opening a session holds it), then the challenge: the order the console commands take them in too, so
     * that an operator ending the sessions of an admin while they sign in cannot deadlock with them.
     *
     * @return array{0: MfaVerdict, 1: ?PlatformAdminSession}
     */
    private function checkAndOpen(MfaAttempt $attempt, PlatformAdminMfaChallenge $challenge, Admin $admin, PlatformAdminGrant $grant): array
    {
        return DB::transaction(function () use ($attempt, $challenge, $admin, $grant): array {
            $factor = $this->factors->forAdmin(adminId: (int) $admin->getKey(), lock: true);
            $stillOpen = PlatformAdminMfaChallenge::query()->whereKey($challenge->getKey())->whereNull('consumed_at')->exists();
            $verdict = $this->verifier->useCodeOf(attempt: $attempt, factor: $stillOpen ? $factor : null);

            if (!$verdict->isAccepted()) {
                return [$verdict, null];
            }

            $session = $this->sessions->open(admin: $admin, grant: $grant, deviceName: $challenge->device_name, ip: $attempt->ip, userAgent: $attempt->userAgent, mfaMethod: $verdict->method);
            $this->challenges->consume(challenge: $challenge);

            if ($verdict->method === MfaMethod::RecoveryCode) {
                $this->audit->record(
                    action: 'mfa.recovery_code_used',
                    adminId: $session->principal->adminId(),
                    tokenId: (int) $session->principal->token->getKey(),
                    ip: $attempt->ip,
                    userAgent: $attempt->userAgent,
                    context: ['recovery_codes_left' => $verdict->recoveryCodesLeft],
                );
            }

            return [$verdict, $session];
        }, attempts: PlatformAdminAudit::DEADLOCK_ATTEMPTS);
    }

    /** What a refused try leaves: a guess counts against the admin, the last try consumes the challenge, and the trail and the log are written after the answer. */
    private function refuse(string $reason, MfaAttempt $attempt, ?PlatformAdminMfaChallenge $challenge, ?PlatformAdminGrant $grant, ?int $triesMade, bool $wasAGuess): SignInOutcome
    {
        $lockedNow = $wasAGuess && $challenge !== null && $this->lockout->recordFailure(adminId: (int) $challenge->admin_id);

        if ($challenge !== null && $triesMade !== null && $this->challenges->allowsNoMoreTries(triesMade: $triesMade)) {
            $this->challenges->consume(challenge: $challenge);
        }

        $this->failures->afterTheAnswerOfTheSecondStep(reason: $reason, attempt: $attempt, grant: $grant, lockedOut: $lockedNow);

        return SignInOutcome::refused();
    }
}
