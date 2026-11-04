<?php

namespace App\Services\Monitoring;

/**
 * Who may see what inside Monitoring.
 *
 * Monitoring shows more about a business than almost any other screen: revenue as it happens,
 * customer traffic, error messages that quote real data, server credentials' effects, and a log
 * viewer. "Every admin sees everything" is the wrong default for that, so the area is split into
 * capabilities that can be granted separately.
 *
 * It layers on the panel's existing RBAC exactly the way ThemePermissionService does, rather than
 * inventing a parallel system: the coarse `system_settings` module grant is still enforced by the
 * route middleware, and these finer capabilities are read from the same `module_access` JSON on
 * the admin's role. A role that predates this feature and simply holds the module keeps the
 * ordinary read-only view — but logs, security and settings have to be granted deliberately,
 * because those are the three that expose data and change behaviour.
 *
 * Keys follow the project's existing convention (`theme_view`, `theme_publish`), and the dotted
 * spellings are accepted as aliases so either form works in a stored role.
 */
class MonitoringPermissionService
{
    /** See the dashboard: status, charts, performance, business flows. */
    public const VIEW = 'monitoring_view';

    /** Read the log centre and individual request/trace payloads. */
    public const LOGS = 'monitoring_logs';

    /** See the security page: failed logins, admin activity, suspicious sources. */
    public const SECURITY = 'monitoring_security';

    /** See server internals: processes, hardware, energy, deployments. */
    public const INFRASTRUCTURE = 'monitoring_infrastructure';

    /**
     * Read grouped exceptions with their stack traces, resolve or ignore them, and read the
     * per-route failure detail underneath them: which endpoint is failing, how often, how slow.
     */
    public const ERRORS = 'monitoring_errors';

    /** Change thresholds, retention, alert rules and privacy settings. */
    public const SETTINGS = 'monitoring_settings';

    /** The coarse module that already gates the monitoring routes. */
    private const MODULE = 'system_settings';

    /**
     * Capabilities an existing role holding the module keeps without an explicit grant.
     *
     * View and errors are the working set — an operator who could already reach the monitoring
     * page can still do their job. Logs, security, infrastructure and settings are not here on
     * purpose: they read customer data, expose the server, or change how the system behaves.
     *
     * @var array<int, string>
     */
    private const IMPLIED_BY_MODULE = [self::VIEW, self::ERRORS];

    /** @var array<string, string> dotted spelling => canonical key */
    private const ALIASES = [
        'monitoring.view' => self::VIEW,
        'monitoring.logs' => self::LOGS,
        'monitoring.security' => self::SECURITY,
        'monitoring.infrastructure' => self::INFRASTRUCTURE,
        'monitoring.errors' => self::ERRORS,
        'monitoring.settings' => self::SETTINGS,
    ];

    public function can(string $capability): bool
    {
        $admin = auth('admin')->user();
        if (!$admin) {
            return false;
        }

        // Master admin (role 1) keeps full access, consistent with the rest of the panel.
        if ((int) ($admin->admin_role_id ?? 0) === 1) {
            return true;
        }

        $granted = $this->grantedKeys($admin);

        if (in_array($capability, $granted, true)) {
            return true;
        }

        if (in_array(self::MODULE, $granted, true)) {
            return in_array($capability, self::IMPLIED_BY_MODULE, true);
        }

        return false;
    }

    public function canView(): bool
    {
        return $this->can(self::VIEW);
    }

    public function canReadLogs(): bool
    {
        return $this->can(self::LOGS);
    }

    public function canSeeSecurity(): bool
    {
        return $this->can(self::SECURITY);
    }

    public function canSeeInfrastructure(): bool
    {
        return $this->can(self::INFRASTRUCTURE);
    }

    public function canSeeErrors(): bool
    {
        return $this->can(self::ERRORS);
    }

    public function canEditSettings(): bool
    {
        return $this->can(self::SETTINGS);
    }

    /**
     * The capability a given monitoring tab requires.
     *
     * One map rather than a check scattered through thirty controller methods, so a new tab cannot
     * be added without deciding who is allowed to see it.
     */
    public function capabilityForTab(string $tab): string
    {
        return match ($tab) {
            'logs' => self::LOGS,
            // 'apis' and 'live' reach monitoring_request_buckets through the same SeriesReader
            // calls as 'requests' and publish the same per-route hits, errors, error_rate and
            // percentiles, so leaving either on the default made monitoring_errors optional for
            // the table it exists to gate. 'live' costs a view-only operator the traffic page,
            // which is the cheaper loss: the alternative was handing them the failing endpoints.
            'errors', 'traces', 'requests', 'apis', 'live' => self::ERRORS,
            'security' => self::SECURITY,
            // 'webserver' was the one member of this group that was missing, so it fell through to
            // the default and was readable by anyone holding monitoring_view — a page naming the
            // nginx and PHP-FPM pools, their worker counts and their configuration.
            'server', 'network', 'storage', 'energy', 'webserver', 'deployments', 'backups', 'redis', 'database', 'queues', 'scheduler' => self::INFRASTRUCTURE,
            'settings', 'alerts' => self::SETTINGS,
            default => self::VIEW,
        };
    }

    /**
     * Every capability, for the role editor.
     *
     * @return array<string, string> key => translation key for its label
     */
    public static function all(): array
    {
        return [
            self::VIEW => 'monitoring_view_dashboards',
            self::ERRORS => 'monitoring_view_errors_and_traces',
            self::LOGS => 'monitoring_read_logs',
            self::SECURITY => 'monitoring_view_security',
            self::INFRASTRUCTURE => 'monitoring_view_infrastructure',
            self::SETTINGS => 'monitoring_change_settings',
        ];
    }

    /** @return array<int, string> */
    private function grantedKeys(object $admin): array
    {
        $access = $admin->role->module_access ?? null;
        if (is_string($access)) {
            $decoded = json_decode($access, true);
            $access = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($access)) {
            return [];
        }

        $keys = [];
        foreach ($access as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            $keys[] = self::ALIASES[$entry] ?? $entry;
        }

        return $keys;
    }
}
