<?php

namespace App\Services\PlatformAdmin;

/**
 * How a step of the sign-in ended: a session was opened (and, at the second step, how the second factor was passed), a challenge was given, the caller was
 * throttled, or it was refused, which is everything else and tells the caller nothing more.
 */
final readonly class SignInOutcome
{
    private function __construct(
        public ?PlatformAdminSession $session = null,
        public ?MfaVerdict $verdict = null,
        public ?IssuedChallenge $challenge = null,
        public ?int $retryAfterSeconds = null,
    ) {
    }

    public static function opened(PlatformAdminSession $session, ?MfaVerdict $verdict = null): self
    {
        return new self(session: $session, verdict: $verdict);
    }

    public static function challenged(IssuedChallenge $challenge): self
    {
        return new self(challenge: $challenge);
    }

    public static function throttled(int $retryAfterSeconds): self
    {
        return new self(retryAfterSeconds: $retryAfterSeconds);
    }

    public static function refused(): self
    {
        return new self();
    }
}
