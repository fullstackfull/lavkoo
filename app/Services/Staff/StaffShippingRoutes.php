<?php

namespace App\Services\Staff;

use App\Http\Controllers\Admin\CategoryShippingCostController;
use App\Http\Controllers\Admin\Marketplace\ShippingZoneController;
use App\Http\Controllers\Admin\Shipping\ShippingMethodController;
use App\Http\Controllers\Admin\Shipping\ShippingTypeController;
use Illuminate\Http\Request;

/**
 * The shipping screens of the admin panel and what each of their actions names (GCC-14c slice 6; H1-14).
 *
 * A shipping method and a per-category shipping cost belong to a market -- the cost is a number typed in that market's money (H1-14) -- so
 * editing one, switching it off or deleting it is an act in that market. Nothing narrowed these actions: an employee restricted to Kuwait
 * could change the Saudi store's delivery price, and the category-cost form took a whole list of ids with no check at all.
 *
 * Every action of every shipping controller has a rule, and ScopeShippingToStaffCountries reads it for an employee who is restricted. A route
 * without a rule is refused to that employee rather than let through, and StaffShippingRoutesTest walks the real route table and fails for an
 * action that has none. What a rule can say:
 *
 *   route:{name}            one method id in the path
 *   input:{name}            one method id in the request (`name?` when it may be missing)
 *   inputs:{name}           a list of method ids in the request: all of them must be in scope, or none is touched
 *   costs:{name}            a list of per-category cost ids in the request, judged the same way
 *   platform_setting        it writes a setting of the whole platform, not a row of a market
 *   market_not_recorded     the rows it acts on carry no market yet, so no scope can judge them
 *   list_not_narrowed       it returns shipping ROWS and they are NOT yet narrowed to the employee's countries
 *   new_not_checked         it creates a row and the market it is created in is NOT yet checked against the employee's
 *
 * The last four are named rather than enforced, and each is named for its own reason rather than lumped together:
 *
 *   - `platform_setting` is the truth about `shipping-type` and `update-shipping-responsibility`: one row for the whole platform says
 *     whether shipping is charged per order, per product or per category, and who is responsible for it. Narrowing that to one employee's
 *     countries would be meaningless -- there is nothing per market to narrow to -- so what it needs is the owner's decision on whether the
 *     shipping TYPE becomes a per-market setting, which is Q-24, not a scope.
 *   - `market_not_recorded` is `shipping_zones`, and it is this requirement's own open question: the table already carries the countries a
 *     zone SERVES, which is not the same as the market that OWNS it, and giving it an owner before that is settled would either break the
 *     destination matching or add a column nothing reads. Until a zone has a market, no scope can say whose it is, and claiming otherwise
 *     would be the lie this table exists to prevent.
 *   - `list_not_narrowed` and `new_not_checked` are the real remaining gaps on the method screen: a restricted employee still SEES every
 *     market's methods and costs on the index, and can still create a method with no market, which makes it the default storefront's.
 *
 * A row out of scope is answered like a row that does not exist, as a product and a coupon out of scope are, so belonging to a market is not
 * something an employee can find out by asking.
 */
final class StaffShippingRoutes
{
    /** The rules that the middleware enforces by reading ids; everything else names no row to check. */
    private const ENFORCED = ['route', 'input', 'inputs', 'costs'];

    /** The rules whose ids are per-category costs rather than shipping methods. */
    private const COST_RULES = ['costs'];

    private const RULES = [
        ShippingMethodController::class => [
            'index' => 'list_not_narrowed',
            'add' => 'new_not_checked',
            'getUpdateView' => 'route:id',
            'update' => 'route:id',
            'updateStatus' => 'input:id',
            'delete' => 'input:id',
            'updateShippingResponsibility' => 'platform_setting',
        ],
        CategoryShippingCostController::class => [
            'add' => 'costs:ids',
        ],
        ShippingTypeController::class => [
            'addOrUpdate' => 'platform_setting',
        ],
        ShippingZoneController::class => [
            'index' => 'market_not_recorded',
            'store' => 'market_not_recorded',
            'toggle' => 'market_not_recorded',
            'update' => 'market_not_recorded',
            'destroy' => 'market_not_recorded',
        ],
    ];

    /** @return array<string, string> the rule of every action of this controller, empty when the controller is not a shipping screen */
    public static function rulesOf(string $controller): array
    {
        return self::RULES[ltrim($controller, '\\')] ?? [];
    }

    public static function ruleOf(string $controller, string $method): ?string
    {
        return self::rulesOf(controller: $controller)[$method] ?? null;
    }

    /**
     * Every controller this table covers, for the test that walks the route table.
     *
     * @return list<string>
     */
    public static function controllers(): array
    {
        return array_keys(self::RULES);
    }

    /**
     * The ids a request names under a rule.
     *
     * @return list<int>|null null when what the rule asks for is missing or cannot be an id, which is answered like an unknown row
     */
    public static function idsOf(Request $request, string $rule): ?array
    {
        [$kind, $name] = array_pad(explode(':', $rule, 2), 2, '');
        $optional = str_ends_with($name, '?');
        $name = rtrim($name, '?');

        return match ($kind) {
            'platform_setting', 'market_not_recorded', 'list_not_narrowed', 'new_not_checked' => [],
            'route' => StaffRouteIds::one(value: $request->route($name)),
            'input' => $optional && StaffRouteIds::blank(value: $request->input($name)) ? [] : StaffRouteIds::one(value: $request->input($name)),
            'inputs', 'costs' => StaffRouteIds::many(value: $request->input($name)),
            default => null,
        };
    }

    /** Whether the ids of this rule are per-category costs rather than shipping methods: the two live in different tables. */
    public static function namesCosts(string $rule): bool
    {
        return in_array(explode(':', $rule, 2)[0], self::COST_RULES, true);
    }

    /** Whether this rule is one the middleware really checks, which is what tells an enforced action from a named gap. */
    public static function isEnforced(string $rule): bool
    {
        return in_array(explode(':', $rule, 2)[0], self::ENFORCED, true);
    }
}
