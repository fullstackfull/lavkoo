<?php

namespace App\Services\DeveloperPortal;

/**
 * Reading the API documentation and firing requests at the platform are not the same permission.
 *
 * Analytics and monitoring were each split into capabilities because they expose more than an
 * ordinary screen. The Developer Portal was not: holding the coarse `system_settings` module opened
 * all of it, including the console — which issues real, authenticated requests against this
 * installation. Anyone who could read the docs could also call the endpoints they describe.
 *
 * So three capabilities, in order of what they let somebody do:
 *
 *   VIEW      read the endpoint catalogue, the schemas and the exports. Harmless, and implied by
 *             the module so every role that has the portal today keeps the pages it reads.
 *   CONSOLE   execute a request from the page. Never implied — it is the one capability that acts
 *             on the platform rather than describing it, and the whole reason for this split.
 *   SNAPSHOT  capture an API surface snapshot, which writes a stored baseline that later runs are
 *             compared against. A write, so it is granted rather than inherited.
 *
 * The narrowing is deliberate and it is the point: a role that could use the console yesterday and
 * holds only the module needs the console capability granted to keep it. Master admin is unchanged.
 */
class DeveloperPortalPermissionService
{
    public const VIEW     = 'developer_view';
    public const CONSOLE  = 'developer_console';
    public const SNAPSHOT = 'developer_snapshot';

    /** The coarse module that already gates the developer routes. */
    private const MODULE = 'system_settings';

    /**
     * What the module grant carries on its own.
     *
     * Reading only. Both of the others do something, and something is what has to be asked for.
     *
     * @var array<int, string>
     */
    private const IMPLIED_BY_MODULE = [self::VIEW];

    /** @var array<string, string> dotted spelling => canonical key */
    private const ALIASES = [
        'developer.view' => self::VIEW,
        'developer.console' => self::CONSOLE,
        'developer.snapshot' => self::SNAPSHOT,
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

    public function canUseConsole(): bool
    {
        return $this->can(self::CONSOLE);
    }

    public function canSnapshot(): bool
    {
        return $this->can(self::SNAPSHOT);
    }

    /**
     * Every capability, for the role editor.
     *
     * @return array<string, string> key => translation key for its label
     */
    public static function all(): array
    {
        return [
            self::VIEW => 'developer_read_the_api_documentation',
            self::CONSOLE => 'developer_send_requests_from_the_api_console',
            self::SNAPSHOT => 'developer_capture_an_api_surface_snapshot',
        ];
    }

    /**
     * @return array<int, string>
     */
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
