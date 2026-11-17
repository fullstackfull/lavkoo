<?php

namespace App\Services\PlatformAdmin;

use App\Models\PlatformAdminGrant;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;

/**
 * What a refused sign-in leaves behind. It is written after the answer has gone out, so that a refusal costs the caller the same whoever
 * they claim to be; and it is bounded, because anyone on the internet can cause it.
 *
 * Every refusal reaches the log with its reason, an address hash and the caller's address, never the e-mail, the password or anything else
 * that was sent. Only a refusal for an admin who holds a grant is also written to the audit trail, and at most a few times an hour per grant.
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
        $written = false;

        // A callback registered on the application runs at the end of every request the same application serves afterwards, so it runs once.
        $this->application->terminating(function () use ($reason, $attempt, $grant, &$written): void {
            if ($written) {
                return;
            }
            $written = true;

            Log::info('Platform admin sign-in refused.', ['reason' => $reason, 'ip' => $attempt->ip, 'email_hash' => $attempt->emailFingerprint()]);

            if ($grant !== null) {
                $this->auditWithinTheHourlyLimit(reason: $reason, attempt: $attempt, grant: $grant);
            }
        });
    }

    private function auditWithinTheHourlyLimit(string $reason, LoginAttempt $attempt, PlatformAdminGrant $grant): void
    {
        $this->limiter->attempt(
            key: "platform-admin:audit:failed-login:{$grant->getKey()}",
            maxAttempts: $this->settings->failedLoginAuditsPerHour(),
            callback: fn () => $this->audit->record(action: 'auth.login_failed', adminId: $grant->admin_id, ip: $attempt->ip, userAgent: $attempt->userAgent, context: ['reason' => $reason]),
            decaySeconds: self::AUDIT_WINDOW_SECONDS,
        );
    }
}
