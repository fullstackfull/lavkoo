<?php

namespace App\Http\Middleware;

use App\Services\Staff\StaffCountryScope;
use App\Services\Staff\StaffShippingRoutes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Narrows the shipping screens of the admin panel to the countries of the signed-in employee (GCC-14c slice 6; H1-14).
 *
 * It is registered by the controllers themselves, so it runs after the sign-in check and the module permission of the route: an employee who
 * may not use the screen is told so before this ever looks at a shipping method.
 *
 * It answers a route that names a method or a cost outside those countries, or no row at all, like a row that does not exist. The
 * category-cost form posts a whole list of ids, so one id the employee may not touch refuses the request before the action runs and nothing
 * is half-applied -- the rule the product, order and coupon bulk actions already follow. While the switch is off, or for an employee who is
 * not restricted, it does nothing but let the request through.
 */
class ScopeShippingToStaffCountries
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
        $rule = StaffShippingRoutes::ruleOf(controller: (string) $route->getControllerClass(), method: $route->getActionMethod());
        $ids = $rule === null ? null : StaffShippingRoutes::idsOf(request: $request, rule: $rule);

        abort_if($ids === null, 404);

        $covered = $rule !== null && StaffShippingRoutes::namesCosts($rule)
            ? $this->scope->coversEveryCategoryShippingCost(costIds: $ids)
            : $this->scope->coversEveryShippingMethod(methodIds: $ids);

        abort_if(!$covered, 404);

        return $next($request);
    }
}
