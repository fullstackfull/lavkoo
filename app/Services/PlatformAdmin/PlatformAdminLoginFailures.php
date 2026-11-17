<?php

namespace App\Services\PlatformAdmin;

use App\Models\PlatformAdminGrant;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;

/**
 * What a refused sign-in, or a refused second step, leaves behind. It is written after the answer has gone out, so that a refusal costs the caller the same
 * whoever they claim to be; and it is bounded, because anyone on the internet can cause it.
 *
 * Every refusal reaches the log with its reason and the caller's address, and a hash of what identifies the attempt (the e-mail, or the challenge), never
 * the e-mail, the password, the challenge, the code or anything else that was sent. Only a refusal for an admin who holds a grant is also written to the
 * audit trail, and at most a few times an hour per grant, separately for the sign-in and for the second step. A lockout is the one line that is not limited
 * that way: it begins once, and is what an owner must see.
 */
final class PlatformAdminLoginFailures
{
    private const AUDIT_WINDOW_SECONDS = 3600;

    public function __construct(
        private readonly Application $application,
        private readonly PlatformAdminSettings $settings,
        private readonly RateLimiter $limiter,
        private readonly PlatformAdminAudit $audit,
    ) {
    }

    public function afterTheAnswer(string $reason, LoginAttempt $attempt, ?PlatformAdminGrant $grant): void
    {
        $this->leaveBehind(
            logMessage: 'Platform admin sign-in refused.',
            logContext: ['reason' => $reason, 'ip' => $attempt->ip, 'email_hash' => $attempt->emailFingerprint()],
            audit: $grant === null ? null : fn () => $this->auditWithinTheHourlyLimit(limiter: 'failed-login', action: 'auth.login_failed', reason: $reason, grant: $grant, ip: $attempt->ip, userAgent: $attempt->userAgent),
        );
    }

    public function afterTheAnswerOfTheSecondStep(string $reason, MfaAttempt $attempt, ?PlatformAdminGrant $grant, bool $lockedOut): void
    {
        $this->leaveBehind(
            logMessage: 'Platform admin second factor refused.',
            logContext: ['reason' => $reason, 'ip' => $attempt->ip, 'challenge_hash' => $attempt->challengeFingerprint()],
            audit: $grant === null ? null : function () use ($reason, $grant, $attempt, $lockedOut): void {
                $this->auditWithinTheHourlyLimit(limiter: 'failed-mfa', action: 'auth.mfa_failed', reason: $reason, grant: $grant, ip: $attempt->ip, userAgent: $attempt->userAgent);

                if ($lockedOut) {
                    $this->audit->record(
                        action: 'auth.mfa_locked',
                        adminId: $grant->admin_id,
                        ip: $attempt->ip,
                        userAgent: $attempt->userAgent,
                        context: ['failures' => $this->settings->mfaLockoutFailures(), 'minutes' => $this->settings->mfaLockoutMinutes()],
                    );
                }
            },
        );
    }

    /** @param array<string, mixed> $logContext */
    private function leaveBehind(string $logMessage, array $logContext, ?Closure $audit): void
    {
        $written = false;

        // A callback registered on the application runs at the end of every request the same application serves afterwards, so it runs once.
        $this->application->terminating(function () use ($logMessage, $logContext, $audit, &$written): void {
            if ($written) {
                return;
            }
            $written = true;

            Log::info($logMessage, $logContext);

            if ($audit !== null) {
                $audit();
            }
        });
    }

    /** @param string $limiter the name of the count, one for the sign-in and one for the second step, so that the one cannot use up the other's lines */
    private function auditWithinTheHourlyLimit(string $limiter, string $action, string $reason, PlatformAdminGrant $grant, string $ip, ?string $userAgent): void
    {
        $this->limiter->attempt(
            key: "platform-admin:audit:{$limiter}:{$grant->getKey()}",
            maxAttempts: $this->settings->failedLoginAuditsPerHour(),
            callback: fn () => $this->audit->record(action: $action, adminId: $grant->admin_id, ip: $ip, userAgent: $userAgent, context: ['reason' => $reason]),
            decaySeconds: self::AUDIT_WINDOW_SECONDS,
        );
    }
}
