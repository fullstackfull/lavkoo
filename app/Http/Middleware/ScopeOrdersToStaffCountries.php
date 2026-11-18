<?php

namespace App\Http\Middleware;

use App\Services\Staff\StaffCountryScope;
use App\Services\Staff\StaffOrderRoutes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Narrows the order, POS and refund screens of the admin panel (slice 2), and the screens that read orders without managing them, such as the people, the dashboard and the
 * reports (slice 3), to the countries of the signed-in employee (GCC-14c). It is registered by the controllers themselves, so it runs after the sign-in check and the module
 * permission of the route: an employee who may not use a screen is told so before this ever looks at an order.
 *
 * It binds the employee's countries to the request, which narrows every product query of the request as it does on the product screens, and for the rule of the action it
 * does two things: it answers a route that names an order, a line, a refund request or a product out of those countries, or nothing that can be checked, like something that does
 * not exist, and it narrows the orders or the refund requests the action lists. An action that only changes an order is not narrowed, so that it runs on the real data.
 * While the switch is off, or for an employee who is not restricted, it does nothing but let the request through.
 */
class ScopeOrdersToStaffCountries
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
        $rule = StaffOrderRoutes::ruleOf(controller: (string) $route->getControllerClass(), method: $route->getActionMethod());

        abort_if($rule === null || !StaffOrderRoutes::isCovered(request: $request, rule: $rule, scope: $this->scope), 404);

        $this->scope->narrowTo(request: $request, subjects: StaffOrderRoutes::subjectsOf(rule: $rule));

        return $next($request);
    }
}
