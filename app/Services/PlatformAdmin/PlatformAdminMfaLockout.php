<?php

namespace App\Services\PlatformAdmin;

use Illuminate\Cache\RateLimiter;

/**
 * The lockout of an admin's second step: so many wrong or replayed codes, from whatever challenges and addresses, within the window, and the second
 * step is closed to that admin until the window ends. It is the limit nothing else bounds: a person who knows the password can ask for as many challenges
 * as the sign-in allows, and each of them allows its own tries.
 *
 * The counters live in the application cache, like every limit of this API.
 */
final class PlatformAdminMfaLockout
{
    private const SECONDS_PER_MINUTE = 60;

    public function __construct(private readonly PlatformAdminSettings $settings, private readonly RateLimiter $limiter)
    {
    }

    public function isLocked(int $adminId): bool
    {
        return $this->limiter->tooManyAttempts(key: $this->key(adminId: $adminId), maxAttempts: $this->settings->mfaLockoutFailures());
    }

    /** Counts a wrong or replayed code, and says whether it was the one that locked the admin. */
    public function recordFailure(int $adminId): bool
    {
        $failures = $this->limiter->hit(key: $this->key(adminId: $adminId), decaySeconds: $this->settings->mfaLockoutMinutes() * self::SECONDS_PER_MINUTE);

        return $failures === $this->settings->mfaLockoutFailures();
    }

    public function secondsUntilAvailable(int $adminId): int
    {
        return max(1, $this->limiter->availableIn(key: $this->key(adminId: $adminId)));
    }

    private function key(int $adminId): string
    {
        return "platform-admin:mfa:lockout:{$adminId}";
    }
}
