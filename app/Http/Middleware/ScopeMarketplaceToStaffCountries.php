<?php

namespace App\Http\Middleware;

use App\Services\Staff\StaffCountryScope;
use App\Services\Staff\StaffMarketplaceRoutes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Narrows the screens of slice 5 to the countries of the signed-in employee (GCC-14c; G1-11, G-09): the marketplace stock screens -- the
 * warehouses, the inventory adjustments, the batches and the purchase orders -- and the rest of the slice, which is made of orders: the
 * reviews, the returns, the fulfilments, the seller scorecard, the settlements, the brand registry and the commission rules.
 *
 * It is registered by the controllers themselves, so it runs after the sign-in check and the module permission of the route.
 *
 * It binds the employee's countries to the request, which narrows every product query of it and every row that belongs to a product -- a
 * stock movement, a batch, a shelf balance, a purchase-order line, a review, a product commission rule (`StaffCountryProductRowScope`). It
 * then narrows the rows a listing screen asked for through the ORDER they belong to -- a return, a fulfilment (`StaffCountryOrderScope`) --
 * and only those, so that an action which CHANGES one still runs on the real data. For the rule of the action it answers a request that
 * names a product, a batch, a purchase order, an order, a review, a return, a fulfilment or a commission rule outside those countries, or
 * nothing that can be checked, like something that does not exist. The stock screens MOVE stock, so there the refusal matters more than the
 * listing: a balance another market sells against must not be changed by an employee who does not work with it.
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

        $this->scope->narrowTo(request: $request, subjects: StaffMarketplaceRoutes::subjectsOf(rule: $rule));

        return $next($request);
    }
}
