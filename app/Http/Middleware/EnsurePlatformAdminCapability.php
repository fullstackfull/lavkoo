<?php

namespace App\Http\Middleware;

use App\Exceptions\PlatformAdminApiException;
use App\Services\PlatformAdmin\PlatformAdminPrincipal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a request through to the part of the platform admin API it is for only when the admin's grant includes that part (`platform_admin_can:alerts`).
 * It runs after the authentication, so there is a principal to ask, and before the controller, so a part the grant does not include reads nothing and
 * judges nothing about what was asked for. The answer is a 403 with its own code, not the 401 of a failure to authenticate: the token is good.
 */
class EnsurePlatformAdminCapability
{
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        if (!PlatformAdminPrincipal::fromRequest(request: $request)->can(capability: $capability)) {
            throw PlatformAdminApiException::capabilityNotAllowed(capability: $capability);
        }

        return $next($request);
    }
}
