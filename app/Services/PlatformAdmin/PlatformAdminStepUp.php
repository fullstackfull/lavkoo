<?php

namespace App\Services\PlatformAdmin;

use App\Models\PlatformAdminMfaFactor;
use Illuminate\Support\Facades\DB;

/**
 * Proving the second factor again inside a session that already holds one (L-15).
 *
 * Signing in proves it once, for a session that may last eight hours. An action that takes something out of the platform asks for it again within
 * minutes, so that an unlocked phone on a desk is not the same thing as the operator. Nothing here opens a session, changes a grant or touches
 * business data: it stamps the moment on the session's own row, and a middleware reads that stamp.
 *
 * The trail is not written here. Every request of a signed-in admin is already one line of it, written by the authentication middleware with the
 * route's own name -- `auth.step_up` -- so a line of its own would be the same event twice. The controller leaves how the factor was proved on the
 * request for that line to carry.
 *
 * The single-use rule of a code is the sign-in's and is not loosened: the factor's row is held for the duration, the verifier is called exactly once,
 * and a code that was already accepted is refused as a replay. A failure counts towards the same lockout a failed sign-in counts towards -- guessing at
 * a step-up is guessing at the factor -- and the caller is told only that it was not accepted.
 */
final class PlatformAdminStepUp
{
    public function __construct(
        private readonly PlatformAdminMfaFactors $factors,
        private readonly PlatformAdminMfaVerifier $verifier,
        private readonly PlatformAdminMfaLockout $lockout,
        private readonly PlatformAdminSettings $settings,
    ) {
    }

    /**
     * Whether a step-up is asked of this session at all. It is not while the platform's second factor is switched off, because then there is no factor
     * to ask for: that mode is the one-step sign-in of a staging server, and refusing every export there would mean the feature could not be used.
     * With the factor required, which is what ships, every session must prove it again.
     */
    public function isRequired(): bool
    {
        return $this->settings->mfaRequired();
    }

    public function isFresh(PlatformAdminPrincipal $principal): bool
    {
        return !$this->isRequired() || $principal->token->isSteppedUp(minutes: $this->settings->stepUpMinutes());
    }

    /** The outcome of one try: accepted, refused, or refused because the admin is locked out and the code was not looked at. */
    public function confirm(PlatformAdminPrincipal $principal, MfaAttempt $attempt): StepUpOutcome
    {
        $adminId = $principal->adminId();

        if ($this->lockout->isLocked(adminId: $adminId)) {
            return StepUpOutcome::throttled(retryAfterSeconds: $this->lockout->secondsUntilAvailable(adminId: $adminId));
        }

        $verdict = $this->checkAndStamp(principal: $principal, attempt: $attempt);

        if (!$verdict->isAccepted()) {
            $this->lockout->recordFailure(adminId: $adminId);

            return StepUpOutcome::refused();
        }

        return StepUpOutcome::accepted(
            freshUntil: $principal->token->stepped_up_at?->copy()->addMinutes($this->settings->stepUpMinutes()),
            method: $verdict->method,
        );
    }

    /** Checks the code with the factor's row held and, when it is right, stamps the session; all or nothing. */
    private function checkAndStamp(PlatformAdminPrincipal $principal, MfaAttempt $attempt): MfaVerdict
    {
        return DB::transaction(function () use ($principal, $attempt): MfaVerdict {
            $factor = $this->factors->forAdmin(adminId: $principal->adminId(), lock: true);
            $verdict = $this->verifier->useCodeOf(attempt: $attempt, factor: $this->usable(factor: $factor, principal: $principal));

            if ($verdict->isAccepted()) {
                $principal->token->forceFill(['stepped_up_at' => now()])->save();
            }

            return $verdict;
        }, attempts: PlatformAdminAudit::DEADLOCK_ATTEMPTS);
    }

    /** The factor to check against, or null when there is nothing to check against, which the verifier spends the same work refusing. */
    private function usable(?PlatformAdminMfaFactor $factor, PlatformAdminPrincipal $principal): ?PlatformAdminMfaFactor
    {
        return $factor !== null && $factor->isActiveFor(admin: $principal->admin) ? $factor : null;
    }
}
