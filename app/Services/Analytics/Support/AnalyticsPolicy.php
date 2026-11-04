<?php

namespace App\Services\Analytics\Support;

use App\Services\Platform\Policy;
use App\Services\Platform\PolicyRegistry;

/**
 * The analytics decisions an administrator is allowed to make.
 *
 * The Analytics settings page opened with "Read-only for now, and honest about it" and printed
 * config() values with no form, which made it the clearest case in the whole control-surface audit:
 * every privacy decision about live customer traffic — whether Do Not Track is honoured, whether
 * consent is required before a visitor is measured, whether an IP is masked, how long anything is
 * kept — was an environment variable and a deploy.
 *
 * Precedence is stored, then environment, then the shipped default. An install that sets these in
 * .env keeps behaving exactly as it does; an operator who has never opened the screen sees no
 * change; and turning a privacy control ON is now something a person can do in a minute, which is
 * the only reason those controls are worth having.
 */
class AnalyticsPolicy
{
    /** policy key => the config path it falls back to. */
    private const CONFIG_PATHS = [
        'analytics_enabled' => 'analytics.enabled',
        'analytics_exclude_bots' => 'analytics.exclude_bots',
        'analytics_exclude_internal' => 'analytics.exclude_internal',
        'analytics_session_gap_minutes' => 'analytics.session_gap_minutes',
        'analytics_engaged_after_seconds' => 'analytics.engaged_after_seconds',
        'analytics_mask_ip' => 'analytics.privacy.mask_ip',
        'analytics_store_country' => 'analytics.privacy.store_country',
        'analytics_respect_do_not_track' => 'analytics.privacy.respect_do_not_track',
        'analytics_require_consent' => 'analytics.privacy.require_consent',
        'analytics_retention_event_days' => 'analytics.retention.event_days',
        'analytics_retention_session_days' => 'analytics.retention.session_days',
        'analytics_retention_daily_days' => 'analytics.retention.daily_days',
    ];

    public function __construct(private readonly Policy $policy)
    {
    }

    public function enabled(): bool
    {
        return (bool) $this->value('analytics_enabled');
    }

    public function excludeBots(): bool
    {
        return (bool) $this->value('analytics_exclude_bots');
    }

    public function excludeInternal(): bool
    {
        return (bool) $this->value('analytics_exclude_internal');
    }

    public function sessionGapMinutes(): int
    {
        return (int) $this->value('analytics_session_gap_minutes');
    }

    public function engagedAfterSeconds(): int
    {
        return (int) $this->value('analytics_engaged_after_seconds');
    }

    public function maskIp(): bool
    {
        return (bool) $this->value('analytics_mask_ip');
    }

    public function storeCountry(): bool
    {
        return (bool) $this->value('analytics_store_country');
    }

    public function respectDoNotTrack(): bool
    {
        return (bool) $this->value('analytics_respect_do_not_track');
    }

    public function requireConsent(): bool
    {
        return (bool) $this->value('analytics_require_consent');
    }

    public function retentionDays(string $kind): int
    {
        return (int) $this->value('analytics_retention_' . $kind);
    }

    /** Everything, for the screen that edits it. @return array<string, mixed> */
    public function all(): array
    {
        $values = [];

        foreach (array_keys(self::CONFIG_PATHS) as $key) {
            $values[$key] = $this->value($key);
        }

        return $values;
    }

    /**
     * Stored, then environment, then the shipped default.
     *
     * The environment step is what makes this safe to introduce on a live platform: a shop that has
     * ANALYTICS_REQUIRE_CONSENT=true set must not have consent quietly stopped being required
     * because a default in a registry said otherwise.
     */
    private function value(string $key): mixed
    {
        try {
            if ($this->policy->isSet($key)) {
                return $this->policy->get($key);
            }
        } catch (\Throwable) {
            // No settings table yet — fall through to configuration.
        }

        return config(self::CONFIG_PATHS[$key], PolicyRegistry::definition($key)['default'] ?? null);
    }
}
