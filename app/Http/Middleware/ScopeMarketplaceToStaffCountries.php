<?php

namespace App\Http\Middleware;

use App\Services\Staff\StaffCountryScope;
use App\Services\Staff\StaffMarketplaceRoutes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Narrows the marketplace STOCK screens of the admin panel -- the warehouses, the inventory adjustments, the batches and the purchase
 * orders -- to the countries of the signed-in employee (GCC-14c, slice 5; G1-11).
 *
 * It is registered by the controllers themselves, so it runs after the sign-in check and the module permission of the route.
 *
 * It binds the employee's countries to the request, which narrows every product query of it and every row that belongs to a product -- a
 * stock movement, a batch, a shelf balance, a purchase-order line (`StaffCountryProductRowScope`). For the rule of the action it then
 * answers a request that names a product, a batch or a purchase order outside those countries, or nothing that can be checked, like
 * something that does not exist. These four screens MOVE stock, so the refusal matters more than the listing: a balance another market
 * sells against must not be changed by an employee who does not work with it.
 *
 * While the switch is off, or for an employee who is not restricted, it does nothing but let the request through.
 */
class ScopeMarketplaceToStaffCountries
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
        $rule = StaffMarketplaceRoutes::ruleOf(controller: (string) $route->getControllerClass(), method: $route->getActionMethod());

        abort_if($rule === null || !StaffMarketplaceRoutes::isCovered(request: $request, rule: $rule, scope: $this->scope), 404);

        return $next($request);
    }
}
