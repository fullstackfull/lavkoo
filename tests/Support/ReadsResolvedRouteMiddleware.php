<?php

namespace Tests\Support;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * The middleware a route is declared with, as the router resolves it: an alias, a group, a class name and a name with parameters all become the class that runs, so a gate is found
 * whatever its spelling. Not gatherMiddleware(): that builds the controller of every route, and some controllers need a service a test has no credentials for.
 */
trait ReadsResolvedRouteMiddleware
{
    /** @return list<RoutingRoute> every route that carries the gate, whether it names it by its alias, by its class, with a parameter or through a group */
    protected function routesDeclaredWith(string $gate): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => in_array($gate, $this->middlewareClassesOf(route: $route), true))
            ->values()
            ->all();
    }

    /** @return list<string> the classes the route is declared to run, without their parameters, less what it opts out of with withoutMiddleware() */
    protected function middlewareClassesOf(RoutingRoute $route): array
    {
        return collect(Route::resolveMiddleware(middleware: $route->middleware(), excluded: $route->excludedMiddleware()))
            ->filter(fn (mixed $middleware) => is_string($middleware))
            ->map(fn (string $middleware) => strtok($middleware, ':'))
            ->values()
            ->all();
    }
}
