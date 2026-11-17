<?php

namespace App\Http\Middleware;

use App\Services\Staff\StaffCountryScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Narrows the product screens of the admin panel to the countries of the signed-in employee (GCC-14c, slice 1). It is registered by the controllers themselves, so it runs
 * after the sign-in check and the module permission of the route.
 *
 * It binds the employee's countries to the request, which narrows every product query of the request (App\Models\Scopes\StaffCountryProductScope). While the switch is off,
 * or for an employee who is not restricted, it binds nothing and does nothing but let the request through.
 */
class ScopeProductsToStaffCountries
{
    public function __construct(private readonly StaffCountryScope $scope)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->scope->bind(request: $request, admin: auth('admin')->user());

        return $next($request);
    }
}
