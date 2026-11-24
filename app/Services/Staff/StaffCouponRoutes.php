<?php

namespace App\Services\Staff;

use App\Http\Controllers\Admin\Promotion\CouponController;
use Illuminate\Http\Request;

/**
 * The coupon screens of the admin panel and what each of their actions names (GCC-14c; G-11's bulk-route finding).
 *
 * A coupon belongs to a market -- it is priced in that market's money (GCC-05) -- so switching one off, editing it or deleting it is an act
 * in that market. Nothing narrowed these actions: a restricted employee could switch off the coupon of a country they do not work with, and
 * `bulk-status` took a whole list of ids. `money.market:coupon` on the route group answers which MONEY a coupon is read in, which is a
 * different question from whose coupon it is.
 *
 * Every action of the controller has a rule, and ScopeCouponsToStaffCountries reads it for an employee who is restricted. A route without a
 * rule is refused to that employee rather than let through, and StaffCouponRouteInventoryTest walks the real route table and fails for an
 * action that has none. What a rule can say:
 *
 *   route:{name}        one coupon id in the path
 *   input:{name}        one coupon id in the request (`name?` when it may be missing)
 *   inputs:{name}       a list of coupon ids in the request: all of them must be in scope, or none is touched
 *   none                it names no existing coupon (the vendor picker)
 *   list_not_narrowed   it returns coupon ROWS and they are NOT yet narrowed to the employee's countries
 *   new_not_checked     it creates a coupon and the country it is created in is NOT yet checked against the employee's
 *
 * The last two are named rather than called `list` and `new`, because coupons have no query scope of their own: a restricted employee still
 * sees every market's coupons on the list and can still create one for a market they do not work with. Calling those rules `list` would say
 * the opposite. They are this requirement's remaining work, and naming them is what keeps the table from lying about what is enforced.
 *
 * A coupon out of scope is answered like a coupon that does not exist, as a product out of scope is, so belonging to a country is not
 * something an employee can find out by asking.
 */
final class StaffCouponRoutes
{
    /** The rules that the middleware enforces by reading ids; everything else names no coupon to check. */
    private const ENFORCED = ['route', 'input', 'inputs'];

    private const RULES = [
        CouponController::class => [
            'getAddListView' => 'list_not_narrowed',
            'add' => 'new_not_checked',
            'exportList' => 'list_not_narrowed',
            'quickView' => 'input:id',
            'getUpdateView' => 'route:id',
            'update' => 'route:id',
            'updateStatus' => 'route:id',
            'bulkStatus' => 'inputs:ids',
            'getVendorList' => 'none',
            'delete' => 'route:id',
        ],
    ];

    /** @return array<string, string> the rule of every action of this controller, empty when the controller is not a coupon screen */
    public static function rulesOf(string $controller): array
    {
        return self::RULES[ltrim($controller, '\\')] ?? [];
    }

    public static function ruleOf(string $controller, string $method): ?string
    {
        return self::rulesOf(controller: $controller)[$method] ?? null;
    }

    /** Every controller this table covers, for the test that walks the route table. */
    public static function controllers(): array
    {
        return array_keys(self::RULES);
    }

    /**
     * The coupon ids a request names under a rule.
     *
     * @return list<int>|null null when what the rule asks for is missing or cannot be a coupon id, which is answered like an unknown coupon
     */
    public static function couponIdsOf(Request $request, string $rule): ?array
    {
        [$kind, $name] = array_pad(explode(':', $rule, 2), 2, '');
        $optional = str_ends_with($name, '?');
        $name = rtrim($name, '?');

        return match ($kind) {
            'none', 'list_not_narrowed', 'new_not_checked' => [],
            'route' => StaffRouteIds::one(value: $request->route($name)),
            'input' => $optional && StaffRouteIds::blank(value: $request->input($name)) ? [] : StaffRouteIds::one(value: $request->input($name)),
            'inputs' => StaffRouteIds::many(value: $request->input($name)),
            default => null,
        };
    }

    /** Whether this rule is one the middleware really checks, which is what tells an enforced action from a named gap. */
    public static function isEnforced(string $rule): bool
    {
        return in_array(explode(':', $rule, 2)[0], self::ENFORCED, true);
    }
}
