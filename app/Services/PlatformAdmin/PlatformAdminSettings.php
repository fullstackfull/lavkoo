<?php

namespace App\Services\PlatformAdmin;

/**
 * The switch and the numbers of the platform admin API, read from config/gcc.php at the moment they are needed. A value that makes no sense
 * (zero, a negative number, text) means the default, and the lifetime of a token has a ceiling nothing configured can lift.
 */
final class PlatformAdminSettings
{
    public const MAX_TOKEN_TTL_MINUTES = 1440;

    private const DEFAULT_TOKEN_TTL_MINUTES = 480;

    private const DEFAULT_LOGIN_ATTEMPTS_PER_MINUTE = 5;

    private const DEFAULT_REQUESTS_PER_MINUTE = 120;

    private const DEFAULT_FAILED_LOGIN_AUDITS_PER_HOUR = 10;

    private const DEFAULT_WINDOW_DAYS = 30;

    private const DEFAULT_MAX_WINDOW_DAYS = 92;

    /** Only a real boolean true switches the API on: a string or a number that merely looks true does not. */
    public function enabled(): bool
    {
        return config('gcc.platform_admin.enabled') === true;
    }

    public function tokenTtlMinutes(): int
    {
        $configured = $this->positiveInteger(key: 'token_ttl_minutes', default: self::DEFAULT_TOKEN_TTL_MINUTES);

        return min($configured, self::MAX_TOKEN_TTL_MINUTES);
    }

    public function loginAttemptsPerMinute(): int
    {
        return $this->positiveInteger(key: 'login_attempts_per_minute', default: self::DEFAULT_LOGIN_ATTEMPTS_PER_MINUTE);
    }

    public function requestsPerMinute(): int
    {
        return $this->positiveInteger(key: 'requests_per_minute', default: self::DEFAULT_REQUESTS_PER_MINUTE);
    }

    public function failedLoginAuditsPerHour(): int
    {
        return $this->positiveInteger(key: 'failed_login_audit_per_hour', default: self::DEFAULT_FAILED_LOGIN_AUDITS_PER_HOUR);
    }

    /** The longest span of days any query of the API may read. */
    public function windowDaysMax(): int
    {
        return $this->positiveInteger(key: 'window_days_max', default: self::DEFAULT_MAX_WINDOW_DAYS);
    }

    /** The length of the window of an overview that does not ask for one, never longer than the longest. */
    public function windowDaysDefault(): int
    {
        return min($this->positiveInteger(key: 'window_days_default', default: self::DEFAULT_WINDOW_DAYS), $this->windowDaysMax());
    }

    private function positiveInteger(string $key, int $default): int
    {
        $configured = (int) config("gcc.platform_admin.{$key}");

        return $configured < 1 ? $default : $configured;
    }
}
