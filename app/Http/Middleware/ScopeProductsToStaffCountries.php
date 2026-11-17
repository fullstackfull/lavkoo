<?php

namespace App\Http\Middleware;

use App\Services\Staff\StaffCountryScope;
use App\Services\Staff\StaffProductRoutes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Narrows the product screens of the admin panel to the countries of the signed-in employee (GCC-14c, slice 1). It is registered by the controllers themselves, so it runs
 * after the sign-in check and the module permission of the route: an employee who may not use a screen is told so before this ever looks at a product.
 *
 * It binds the employee's countries to the request, which narrows every product query of the request (App\Models\Scopes\StaffCountryProductScope), and answers a
 * route that names a product out of those countries, or no product at all, like a product that does not exist. While the switch is off, or for an employee who is not
 * restricted, it does nothing but let the request through.
 */
class ScopeProductsToStaffCountries
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
        $rule = StaffProductRoutes::ruleOf(controller: (string) $route->getControllerClass(), method: $route->getActionMethod());
        $productIds = $rule === null ? null : StaffProductRoutes::productIdsOf(request: $request, rule: $rule);

        abort_if($productIds === null || !$this->scope->coversEveryProduct(productIds: $productIds), 404);

        return $next($request);
    }
}
