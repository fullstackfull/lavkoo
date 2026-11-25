<?php

namespace Tests\Support;

use Illuminate\Routing\Route as RoutingRoute;

/**
 * Which routes of the admin panel read or write the GOVERNANCE of the panel itself: the employees, their roles, the countries they work
 * with, the audit trail, and the credentials of the integrations (GCC-14c, G1-09 and G1-13).
 *
 * The third scan beside the products' and the orders': those two ask which market a ROW of the shop belongs to, and this one asks who may
 * change who may see it. The walk and its blind spots are those of StaffRouteScanner.
 */
final class StaffGovernanceRouteScanner extends StaffRouteScanner
{
    /** What in the source of a file or an action means that it reads the governance of the panel. */
    private const SOURCE_MARKERS = [
        'employees' => '/\bAdminRepositoryInterface\b|\badminRepo\b|(?<![A-Za-z])(?:\\\\App\\\\Models\\\\)?Admin::|(?:table|from)\(\s*[\'"]admins[\'"]/',
        'roles' => '/\bAdminRoleRepositoryInterface\b|\badminRoleRepo\b|(?<![A-Za-z])(?:\\\\App\\\\Models\\\\)?AdminRole::|(?:table|from)\(\s*[\'"]admin_roles[\'"]/',
        'the countries an employee works with' => '/\bStaffCountryAssignments\b|[\'"]admin_country_scopes[\'"]/',
        // Reading the trail, not writing to it: `AuditLogger` is in forty controllers that RECORD a line, which is G1-08's business and
        // not a screen that shows one.
        'the audit trail' => '/(?<![A-Za-z])(?:\\\\App\\\\Models\\\\)?AuditLog::|(?:table|from)\(\s*[\'"]audit_logs[\'"]/',
        'integration credentials' => '/\bIntegrationSettingRepositoryInterface\b|\bintegrationSettingRepo\b|[\'"]integration_settings[\'"]|\bshowDemoModeInputValue\b|[\'"]live_values[\'"]/',
    ];

    /** @return array<string, array{file: string, routes: list<string>, governanceRoutes: list<string>, markers: list<string>}> */
    public function controllers(): array
    {
        return $this->scan(readingKey: 'governanceRoutes');
    }

    /** @return list<string> why a route reads the governance of the panel or names a part of it: empty when it does not */
    public function routeReadsGovernance(RoutingRoute $route): array
    {
        return $this->routeReasons(route: $route);
    }

    protected function sourceMarkers(): array
    {
        return self::SOURCE_MARKERS;
    }

    /**
     * A path or a parameter that names an employee or a role. Nothing here keys on an id alone: `update/{id}` is the shape of half the
     * panel, so only the words say what the id is about.
     *
     * @return list<string>
     */
    protected function nameReasons(RoutingRoute $route): array
    {
        $reasons = [];
        if (preg_match('#(^|/)(employee|custom-role|audit-log)[a-z-]*(/|$)#i', $route->uri()) === 1) {
            $reasons[] = 'path';
        }

        return $reasons;
    }
}
