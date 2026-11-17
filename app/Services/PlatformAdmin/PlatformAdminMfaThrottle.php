<?php

namespace App\Services\PlatformAdmin;

use Illuminate\Cache\RateLimiter;

/**
 * Limits the tries at the second step from one address, counted before the challenge is looked up so that nothing about a challenge can be learned from it.
 * An address may make three times the sign-in number of attempts a minute (the same proportion the sign-in itself gives an address): one person mistyping five
 * codes in a challenge is within it, and so is an office behind one address.
 */
final class PlatformAdminMfaThrottle
{
    private const WINDOW_SECONDS = 60;

    private const ADDRESS_ALLOWANCE = 3;

    public function __construct(private readonly PlatformAdminSettings $settings, private readonly RateLimiter $limiter)
    {
    }

    public function exceeded(MfaAttempt $attempt): bool
    {
        return $this->limiter->tooManyAttempts(key: $this->key(attempt: $attempt), maxAttempts: $this->settings->loginAttemptsPerMinute() * self::ADDRESS_ALLOWANCE);
    }

    public function hit(MfaAttempt $attempt): void
    {
        $this->limiter->hit(key: $this->key(attempt: $attempt), decaySeconds: self::WINDOW_SECONDS);
    }

    public function secondsUntilAvailable(MfaAttempt $attempt): int
    {
        return max(1, $this->limiter->availableIn(key: $this->key(attempt: $attempt)));
    }

    private function key(MfaAttempt $attempt): string
    {
        return "platform-admin:mfa:address:{$attempt->ip}";
    }
}
