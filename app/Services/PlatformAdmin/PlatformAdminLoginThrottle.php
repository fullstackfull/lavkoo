<?php

namespace App\Services\PlatformAdmin;

use Illuminate\Cache\RateLimiter;

/**
 * Limits sign-in attempts, counted before the password is looked at: for one address, and for one address together with one e-mail. Every
 * attempt counts, a successful one too. The e-mail is hashed in the key, so the cache never holds it.
 */
final class PlatformAdminLoginThrottle
{
    private const WINDOW_SECONDS = 60;

    public function __construct(private readonly PlatformAdminSettings $settings, private readonly RateLimiter $limiter)
    {
    }

    public function exceeded(LoginAttempt $attempt): bool
    {
        $limit = $this->settings->loginAttemptsPerMinute();

        return $this->limiter->tooManyAttempts($this->addressKey($attempt), $limit) || $this->limiter->tooManyAttempts($this->pairKey($attempt), $limit);
    }

    public function hit(LoginAttempt $attempt): void
    {
        $this->limiter->hit($this->addressKey($attempt), self::WINDOW_SECONDS);
        $this->limiter->hit($this->pairKey($attempt), self::WINDOW_SECONDS);
    }

    public function secondsUntilAvailable(LoginAttempt $attempt): int
    {
        return max(1, $this->limiter->availableIn($this->addressKey($attempt)), $this->limiter->availableIn($this->pairKey($attempt)));
    }

    /** A sign-in that succeeded starts the count of that e-mail from that address again. The count of the address does not start again: it is the one that stops a guess at many e-mails. */
    public function forgetPair(LoginAttempt $attempt): void
    {
        $this->limiter->clear($this->pairKey($attempt));
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
