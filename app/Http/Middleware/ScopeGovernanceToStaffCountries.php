<?php

namespace App\Http\Middleware;

use App\Services\Staff\StaffCountryScope;
use App\Services\Staff\StaffGovernanceRoutes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the countries of the signed-in employee to the requests of the panel's own governance screens (GCC-14c; G1-08) -- today the audit
 * centre -- so that what those screens already narrow is actually narrowed.
 *
 * It is registered by the controller itself, so it runs after the sign-in check and the module permission of the route. A route of a covered
 * controller with no rule is refused rather than let through, as on every other slice.
 *
 * Why a middleware at all, when the screen does its own narrowing: the narrowing reads
 * `StaffCountryScope::boundCountries()`, and nothing on that route bound anything. The shared admin layout binds a request that nothing else
 * did, but it does that in a view composer -- which runs after the controller has already read and paginated its rows. So the audit centre
 * narrowed nothing in practice and showed a restricted employee every market's trail. Binding is the fix; the rule in the registry is what
 * keeps a new action of the screen from being added unconsidered.
 *
 * While the switch is off, or for an employee who is not restricted, it does nothing but let the request through.
 */
class ScopeGovernanceToStaffCountries
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
        $rule = StaffGovernanceRoutes::ruleOf(controller: (string) $route->getControllerClass(), method: $route->getActionMethod());

        abort_if($rule === null || !StaffGovernanceRoutes::isKnown($rule), 404);

        return $next($request);
    }
}
