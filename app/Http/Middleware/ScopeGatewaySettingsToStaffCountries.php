<?php

namespace App\Http\Middleware;

use App\Services\Staff\StaffCountryScope;
use App\Services\Staff\StaffGatewayRoutes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps an employee scoped to one country out of the payment-gateway settings screens (D2-08).
 *
 * They held `module:3rd_party_setup` and nothing else, so an employee given one market and that module could read and rewrite the
 * credentials every market takes card payments with. Why the answer for a restricted employee is the whole screen rather than a subset of
 * its rows is {@see StaffGatewayRoutes}: there is one gateway configuration for the installation, and a write to it is a write to every
 * market's merchant account at once.
 *
 * It is registered by the controllers themselves, as the other scope middlewares are, and on the administrative actions alone -- so a
 * customer-facing or point-of-sale action that shares a controller with a settings action is not touched by it.
 *
 * A route with NO rule is refused as well. A new action on one of these screens is unreachable to a restricted employee until somebody has
 * decided what it reads; the alternative is a new credential screen that is open by default.
 *
 * While `gcc.staff.gateway_settings.scoped` is off, while the country scopes are off, and for an employee the scopes do not restrict, it
 * does nothing but let the request through.
 */
class ScopeGatewaySettingsToStaffCountries
{
    public function __construct(private readonly StaffCountryScope $scope)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->scope->bind(request: $request, admin: auth('admin')->user()) === null) {
            return $next($request);
        }

        $rule = StaffGatewayRoutes::ruleOf(
            controller: (string) $request->route()->getControllerClass(),
            method: (string) $request->route()->getActionMethod(),
        );

        abort_if($rule === null || $rule === StaffGatewayRoutes::UNAVAILABLE, 404);

        return $next($request);
    }
}
