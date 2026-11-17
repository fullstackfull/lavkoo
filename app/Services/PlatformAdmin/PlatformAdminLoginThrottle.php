<?php

namespace App\Services\PlatformAdmin;

use Illuminate\Cache\RateLimiter;

/**
 * Limits sign-in attempts, counted before the password is looked at: for one e-mail from one address, and for one address as a whole. Every
 * attempt counts, a successful one too. The e-mail is hashed in the key, so the cache never holds it.
 *
 * One e-mail from one address may try the configured number of times a minute. The address as a whole may try three times that, which is what
 * stops a guess at many e-mails from one place, and still leaves room for colleagues behind one office address when one of them mistypes: with
 * the same number for both, the limit of the pair could never be reached before the limit of the address.
 */
final class PlatformAdminLoginThrottle
{
    private const WINDOW_SECONDS = 60;

    private const ADDRESS_ALLOWANCE = 3;

    public function __construct(private readonly PlatformAdminSettings $settings, private readonly RateLimiter $limiter)
    {
    }

    public function exceeded(LoginAttempt $attempt): bool
    {
        $limit = $this->settings->loginAttemptsPerMinute();

        return $this->limiter->tooManyAttempts(key: $this->addressKey(attempt: $attempt), maxAttempts: $limit * self::ADDRESS_ALLOWANCE)
            || $this->limiter->tooManyAttempts(key: $this->pairKey(attempt: $attempt), maxAttempts: $limit);
    }

    public function hit(LoginAttempt $attempt): void
    {
        $this->limiter->hit(key: $this->addressKey(attempt: $attempt), decaySeconds: self::WINDOW_SECONDS);
        $this->limiter->hit(key: $this->pairKey(attempt: $attempt), decaySeconds: self::WINDOW_SECONDS);
    }

    public function secondsUntilAvailable(LoginAttempt $attempt): int
    {
        return max(1, $this->limiter->availableIn(key: $this->addressKey(attempt: $attempt)), $this->limiter->availableIn(key: $this->pairKey(attempt: $attempt)));
    }

    /** A sign-in that succeeded starts the count of that e-mail from that address again. The count of the address does not start again: it is the one that stops a guess at many e-mails. */
    public function forgetPair(LoginAttempt $attempt): void
    {
        $this->limiter->clear(key: $this->pairKey(attempt: $attempt));
    }

    private function addressKey(LoginAttempt $attempt): string
    {
        return "platform-admin:login:address:{$attempt->ip}";
    }

    private function pairKey(LoginAttempt $attempt): string
    {
        return 'platform-admin:login:pair:' . $attempt->pairFingerprint();
    }
}
