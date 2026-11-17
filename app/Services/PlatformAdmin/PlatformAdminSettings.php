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

    private const DEFAULT_ZERO_STOCK_WARNING_COUNT = 10;

    private const DEFAULT_ZERO_STOCK_CRITICAL_COUNT = 50;

    private const DEFAULT_PENDING_AGE_HOURS = 24;

    private const DEFAULT_PENDING_WARNING_COUNT = 5;

    private const DEFAULT_PENDING_CRITICAL_COUNT = 20;

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

    public function zeroStockWarningCount(): int
    {
        return $this->positiveInteger(key: 'alerts.zero_stock_warning_count', default: self::DEFAULT_ZERO_STOCK_WARNING_COUNT);
    }

    /** Never below the warning count, so that the levels of one alert stay in order whatever is configured. */
    public function zeroStockCriticalCount(): int
    {
        return max($this->positiveInteger(key: 'alerts.zero_stock_critical_count', default: self::DEFAULT_ZERO_STOCK_CRITICAL_COUNT), $this->zeroStockWarningCount());
    }

    public function pendingAgeHours(): int
    {
        return $this->positiveInteger(key: 'alerts.pending_age_hours', default: self::DEFAULT_PENDING_AGE_HOURS);
    }

    public function pendingWarningCount(): int
    {
        return $this->positiveInteger(key: 'alerts.pending_warning_count', default: self::DEFAULT_PENDING_WARNING_COUNT);
    }

    /** Never below the warning count. */
    public function pendingCriticalCount(): int
    {
        return max($this->positiveInteger(key: 'alerts.pending_critical_count', default: self::DEFAULT_PENDING_CRITICAL_COUNT), $this->pendingWarningCount());
    }

    private function positiveInteger(string $key, int $default): int
    {
        $configured = (int) config("gcc.platform_admin.{$key}");

        return $configured < 1 ? $default : $configured;
    }
}
