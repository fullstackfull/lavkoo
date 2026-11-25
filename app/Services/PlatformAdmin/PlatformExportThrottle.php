<?php

namespace App\Services\PlatformAdmin;

use Illuminate\Cache\RateLimiter;

/**
 * How many exports one admin may ask for in an hour (L-14).
 *
 * It is counted per ADMIN and not per address: the limit is on the person taking rows off the platform, and an operator with two phones is still one
 * operator. Counted BEFORE the rows are read, so a refusal costs the database nothing, and the hour is a sliding one, so three at once is also three
 * for the hour.
 *
 * The counters live in the limiter's own store, like every limit of this API, which is why emptying the application cache cannot release one.
 */
final class PlatformExportThrottle
{
    private const SECONDS_PER_HOUR = 3600;

    public function __construct(private readonly PlatformAdminSettings $settings, private readonly RateLimiter $limiter)
    {
    }

    public function exceeded(int $adminId): bool
    {
        return $this->limiter->tooManyAttempts(key: $this->key(adminId: $adminId), maxAttempts: $this->settings->exportsPerHour());
    }

    public function hit(int $adminId): void
    {
        $this->limiter->hit(key: $this->key(adminId: $adminId), decaySeconds: self::SECONDS_PER_HOUR);
    }

    public function secondsUntilAvailable(int $adminId): int
    {
        return max(1, $this->limiter->availableIn(key: $this->key(adminId: $adminId)));
    }

    private function key(string|int $adminId): string
    {
        return "platform-admin:export:{$adminId}";
    }
}
