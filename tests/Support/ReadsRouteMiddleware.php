<?php

namespace Tests\Support;

use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * What a route really runs, read from the route table. A route's controller is never built here: a controller's constructor may need tables that a
 * test of the route table does not have.
 */
trait ReadsRouteMiddleware
{
    /** @return list<string> the middleware the route really runs: what is declared on it and its groups, less what it opts out of with withoutMiddleware() */
    private function declaredMiddleware(RoutingRoute $route): array
    {
        $declared = array_map(static fn ($middleware): string => is_string($middleware) ? $middleware : 'closure', (array) $route->middleware());

        return array_values(array_diff($declared, array_map('strval', $route->excludedMiddleware())));
    }

    /** @return list<string> the `module:` middleware the route really runs, as declared: `module:a,b` is one that passes the holder of `a` or `b`, two of them ask for both */
    private function moduleMiddlewareOf(RoutingRoute $route): array
    {
        return array_values(array_filter($this->declaredMiddleware(route: $route), static fn (string $middleware): bool => str_starts_with($middleware, 'module:')));
    }

    /** @return list<string> every permission the `module:` middleware of the route asks for, each name of a list `module:a,b` on its own */
    private function modulePermissionsOf(RoutingRoute $route): array
    {
        $permissions = [];
        foreach ($this->moduleMiddlewareOf(route: $route) as $middleware) {
            array_push($permissions, ...explode(',', substr($middleware, strlen('module:'))));
        }

        return $permissions;
    }

    private function routeFor(string $method, string $uri): RoutingRoute
    {
        return Route::getRoutes()->match(Request::create(uri: $uri, method: $method));
    }
}
