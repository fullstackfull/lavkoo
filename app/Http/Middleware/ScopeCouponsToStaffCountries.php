<?php

namespace App\Http\Middleware;

use App\Services\Staff\StaffCountryScope;
use App\Services\Staff\StaffCouponRoutes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Narrows the coupon screens of the admin panel to the countries of the signed-in employee (GCC-14c; G-11).
 *
 * It is registered by the controller itself, so it runs after the sign-in check and the module permission of the route: an employee who may
 * not use the screen is told so before this ever looks at a coupon.
 *
 * It answers a route that names a coupon out of those countries, or no coupon at all, like a coupon that does not exist. For `bulk-status`
 * it reads the whole list, so one id the employee may not touch refuses the request before the action runs and nothing is half-applied --
 * which is the rule the product and order bulk actions already follow. While the switch is off, or for an employee who is not restricted, it
 * does nothing but let the request through.
 */
class ScopeCouponsToStaffCountries
{
    public function __construct(private readonly StaffCountryScope $scope)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->scope->bind(request: $request, admin: auth('admin')->user()) === null) {
            return $next($request);
        }

        $route = $request->route();
        $rule = StaffCouponRoutes::ruleOf(controller: (string) $route->getControllerClass(), method: $route->getActionMethod());
        $couponIds = $rule === null ? null : StaffCouponRoutes::couponIdsOf(request: $request, rule: $rule);

        abort_if($couponIds === null || !$this->scope->coversEveryCoupon(couponIds: $couponIds), 404);

        return $next($request);
    }
}
