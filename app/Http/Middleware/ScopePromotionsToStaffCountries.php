<?php

namespace App\Http\Middleware;

use App\Services\Staff\StaffCountryScope;
use App\Services\Staff\StaffPromotionRoutes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Narrows the promotion screens of the admin panel -- the flash deals, the deal of the day, the clearance sale, the most-demanded entries,
 * the banners and the in-house shop page -- to the countries of the signed-in employee (GCC-14c, slice 4).
 *
 * It is registered by the controllers themselves, so it runs after the sign-in check and the module permission of the route: an employee who
 * may not use a screen is told so before this ever looks at a product.
 *
 * It binds the employee's countries to the request, which narrows every product query of the request (`StaffCountryProductScope`) and every
 * query of the rows that belong to a product -- a deal's products, the deal of the day, the most-demanded entries, the clearance offers
 * (`StaffCountryProductRowScope`). For the rule of the action it then answers a path or a request that names a product, or a promotion row
 * of a product, outside those countries -- or nothing that can be checked -- like something that does not exist, and it narrows the other
 * subjects the rule names: the in-house shop page counts orders, which are reached through their lines' products and not through a column.
 *
 * While the switch is off, or for an employee who is not restricted, it does nothing but let the request through.
 */
class ScopePromotionsToStaffCountries
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
        $rule = StaffPromotionRoutes::ruleOf(controller: (string) $route->getControllerClass(), method: $route->getActionMethod());

        abort_if($rule === null || !StaffPromotionRoutes::isCovered(request: $request, rule: $rule, scope: $this->scope), 404);

        $this->scope->narrowTo(request: $request, subjects: StaffPromotionRoutes::subjectsOf(rule: $rule));

        return $next($request);
    }
}
