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

    private const DEFAULT_MFA_CHALLENGE_TTL_MINUTES = 5;

    private const MAX_MFA_CHALLENGE_TTL_MINUTES = 15;

    private const DEFAULT_MFA_MAX_CHALLENGE_ATTEMPTS = 5;

    private const MAX_MFA_CHALLENGE_ATTEMPTS = 10;

    private const DEFAULT_MFA_LOCKOUT_FAILURES = 10;

    private const MAX_MFA_LOCKOUT_FAILURES = 20;

    private const DEFAULT_MFA_LOCKOUT_MINUTES = 15;

    private const MAX_MFA_LOCKOUT_MINUTES = 60;

    private const DEFAULT_SESSION_IDLE_MINUTES = 60;

    private const MAX_SESSION_IDLE_MINUTES = 1440;

    private const DEFAULT_SESSION_MAX_CONCURRENT = 5;

    private const MAX_SESSION_MAX_CONCURRENT = 20;

    /** What an environment value of the second-factor switch may say to turn it off. Everything else, a typo or a blank included, leaves it on. */
    private const SECOND_FACTOR_OFF_VALUES = ['0', 'false', 'off', 'no'];

    private const DEFAULT_WINDOW_DAYS = 30;

    private const DEFAULT_MAX_WINDOW_DAYS = 92;

    private const DEFAULT_ZERO_STOCK_WARNING_COUNT = 10;

    private const DEFAULT_ZERO_STOCK_CRITICAL_COUNT = 50;

    private const DEFAULT_PENDING_AGE_HOURS = 24;

    private const DEFAULT_PENDING_WARNING_COUNT = 5;

    private const DEFAULT_PENDING_CRITICAL_COUNT = 20;

    /** A health signal of this many rows is a warning. One row is information; the owner sets the real numbers per signal (L-09's open decision). */
    private const DEFAULT_HEALTH_WARNING_COUNT = 5;

    private const DEFAULT_HEALTH_CRITICAL_COUNT = 20;

    /** Only a real boolean true switches the API on: a string or a number that merely looks true does not. */
    public function enabled(): bool
    {
        return config('gcc.platform_admin.enabled') === true;
    }

    /**
     * Whether a sign-in takes the second factor. It fails closed: only a real boolean false, or the text of one that an environment file can hold
     * (`false`, `0`, `off`, `no`, in any case), switches it off; a typo, a blank value or a missing entry is on.
     */
    public function mfaRequired(): bool
    {
        $configured = config('gcc.platform_admin.mfa.required');

        if (is_bool($configured)) {
            return $configured;
        }

        return !is_scalar($configured) || !in_array(strtolower(trim((string) $configured)), self::SECOND_FACTOR_OFF_VALUES, true);
    }

    public function mfaChallengeTtlMinutes(): int
    {
        return min($this->positiveInteger(key: 'mfa.challenge_ttl_minutes', default: self::DEFAULT_MFA_CHALLENGE_TTL_MINUTES), self::MAX_MFA_CHALLENGE_TTL_MINUTES);
    }

    public function mfaMaxChallengeAttempts(): int
    {
        return min($this->positiveInteger(key: 'mfa.max_challenge_attempts', default: self::DEFAULT_MFA_MAX_CHALLENGE_ATTEMPTS), self::MAX_MFA_CHALLENGE_ATTEMPTS);
    }

    public function mfaLockoutFailures(): int
    {
        return min($this->positiveInteger(key: 'mfa.lockout_failures', default: self::DEFAULT_MFA_LOCKOUT_FAILURES), self::MAX_MFA_LOCKOUT_FAILURES);
    }

    public function mfaLockoutMinutes(): int
    {
        return min($this->positiveInteger(key: 'mfa.lockout_minutes', default: self::DEFAULT_MFA_LOCKOUT_MINUTES), self::MAX_MFA_LOCKOUT_MINUTES);
    }

    /** How long a session may go unused before it is over. */
    public function sessionIdleMinutes(): int
    {
        return min($this->positiveInteger(key: 'session.idle_minutes', default: self::DEFAULT_SESSION_IDLE_MINUTES), self::MAX_SESSION_IDLE_MINUTES);
    }

    /** How many sessions of one admin may be open at once. */
    public function sessionMaxConcurrent(): int
    {
        return min($this->positiveInteger(key: 'session.max_concurrent', default: self::DEFAULT_SESSION_MAX_CONCURRENT), self::MAX_SESSION_MAX_CONCURRENT);
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

    /**
     * The count at which one health signal becomes a warning. One signal, one pair of numbers, so that a ruling on any of them changes
     * configuration rather than code (L-09's open decision names the thresholds as the owner's).
     */
    public function healthAlertWarningCount(string $signal): int
    {
        return $this->positiveInteger(key: "alerts.health.{$signal}_warning_count", default: self::DEFAULT_HEALTH_WARNING_COUNT);
    }

    /** Never below the warning count of the same signal, so its levels stay in order whatever is configured. */
    public function healthAlertCriticalCount(string $signal): int
    {
        return max(
            $this->positiveInteger(key: "alerts.health.{$signal}_critical_count", default: self::DEFAULT_HEALTH_CRITICAL_COUNT),
            $this->healthAlertWarningCount(signal: $signal),
        );
    }

    private function positiveInteger(string $key, int $default): int
    {
        $configured = (int) config("gcc.platform_admin.{$key}");

        return $configured < 1 ? $default : $configured;
    }
}
