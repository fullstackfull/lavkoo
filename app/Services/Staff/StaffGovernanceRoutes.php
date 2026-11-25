<?php

namespace App\Services\Staff;

use App\Http\Controllers\Admin\Marketplace\AuditLogController;

/**
 * The screens of the panel's own governance that a staff country scope covers (GCC-14c; G1-08), and what each of their actions reads.
 *
 * One controller so far, and it is here rather than in the product or order registry because what it reads is neither: an audit line names
 * the market it concerns in a column of its own. The employee and role screens are governance too, and they need no rule -- they are behind
 * `EnsureMasterAdmin`, so no restricted employee reaches them; `tests/Support/StaffGovernanceInventory.php` records that and the guard
 * asserts the middleware is still on every one of their routes.
 *
 * Every action of a covered controller has a rule, and a route without one is REFUSED to a restricted employee rather than let through.
 * What a rule can say:
 *
 *   none         the action reads nothing that belongs to a market
 *   list:audit   it lists audit lines: the request is bound, and the screen narrows them by the market each line names
 *
 * `list:audit` exists because BINDING is the whole job here. `AuditLogController` already narrowed both of its reads by
 * `StaffCountryScope::boundCountries()` -- and nothing bound the request on its route, so the narrowing was dead and a restricted employee
 * read every market's trail. The shared admin layout binds a request that nothing else did, but it does so in a VIEW COMPOSER, which runs
 * after the controller has already paginated. A screen that reads a market-owned table needs a middleware of its own, and that is this.
 */
final class StaffGovernanceRoutes
{
    /** @var array<class-string, array<string, string>> */
    private const RULES = [
        AuditLogController::class => [
            'index' => 'list:audit',
        ],
    ];

    /** @return list<class-string> the controllers whose actions a staff country scope covers */
    public static function controllers(): array
    {
        return array_keys(self::RULES);
    }

    /** @return array<string, string> the rule of every action of a covered controller, by the name of the action */
    public static function rulesOf(string $controller): array
    {
        return self::RULES[ltrim($controller, '\\')] ?? [];
    }

    public static function ruleOf(string $controller, string $method): ?string
    {
        return self::rulesOf(controller: $controller)[$method] ?? null;
    }

    public static function isKnown(string $rule): bool
    {
        return in_array($rule, ['none', 'list:audit'], true);
    }
}
