<?php

namespace Tests\Support;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use ReflectionMethod;

/**
 * Which routes of the admin panel read a kind of data a staff country scope has to narrow, read from the real route table and the source of the controllers (GCC-14c). It is a
 * pattern check over source text, so it has blind spots: a controller that reaches the data only through a service or a trait that the markers do not name is not seen, and
 * the guards that use it keep a hand table for the ones found by hand.
 *
 * Two readings, both over the routes whose middleware holds `admin`:
 *  - a route reads the data when the source of its own action, its path or its parameters say so;
 *  - a controller reads it when its file does, in any of its actions or helpers, so that a route that delegates to a helper of its controller is not missed.
 */
abstract class StaffRouteScanner
{
    /** @return array<string, string> what in the source of a file or an action means that it reads the data, by name: a regular expression each */
    abstract protected function sourceMarkers(): array;

    /** @return list<string> why the path or the parameters of a route say that it names the data */
    abstract protected function nameReasons(RoutingRoute $route): array;

    /**
     * @param string $readingKey the key under which the routes that read the data are listed for each controller
     * @return array<string, array<string, mixed>> the admin controllers that read the data, by class: `file`, `routes` (all of them), the reading routes, the markers of the file
     */
    protected function scan(string $readingKey): array
    {
        $found = [];
        foreach ($this->adminRoutes() as $route) {
            $class = ltrim((string) $route->getControllerClass(), '\\');
            if ($class === '' || !class_exists($class)) {
                continue;
            }

            $found[$class]['routes'][] = $this->keyOf(route: $route);
            if ($this->routeReasons(route: $route) !== []) {
                $found[$class][$readingKey][] = $this->keyOf(route: $route);
            }
        }

        $controllers = [];
        foreach ($found as $class => $routes) {
            $file = (string) (new ReflectionClass($class))->getFileName();
            $markers = array_keys(array_filter($this->sourceMarkers(), static fn (string $pattern): bool => preg_match($pattern, (string) file_get_contents($file)) === 1));
            if ($markers !== [] || ($routes[$readingKey] ?? []) !== []) {
                $controllers[$class] = ['file' => $file, 'routes' => $routes['routes'], $readingKey => $routes[$readingKey] ?? [], 'markers' => $markers];
            }
        }
        ksort($controllers);

        return $controllers;
    }

    /** @return list<RoutingRoute> */
    public function adminRoutes(): array
    {
        return array_values(array_filter(Route::getRoutes()->getRoutes(), fn (RoutingRoute $route): bool => in_array('admin', $this->declaredMiddleware(route: $route), true)));
    }

    /** @return list<string> why a route reads the data or names it: empty when it does not */
    protected function routeReasons(RoutingRoute $route): array
    {
        $reasons = [];
        foreach ($this->sourceMarkers() as $name => $pattern) {
            if (preg_match($pattern, $this->actionSource(route: $route)) === 1) {
                $reasons[] = $name;
            }
        }

        return [...$reasons, ...$this->nameReasons(route: $route)];
    }

    public function keyOf(RoutingRoute $route): string
    {
        return implode('|', array_diff($route->methods(), ['HEAD'])) . ' ' . $route->uri();
    }

    /** @return list<string> the middleware the route really runs: what is declared on it and its groups, less what it opts out of with withoutMiddleware() */
    private function declaredMiddleware(RoutingRoute $route): array
    {
        $declared = array_map(static fn ($middleware): string => is_string($middleware) ? $middleware : 'closure', (array) $route->middleware());

        return array_values(array_diff($declared, array_map('strval', $route->excludedMiddleware())));
    }

    private function actionSource(RoutingRoute $route): string
    {
        $action = $route->getActionName();
        if (!str_contains($action, '@')) {
            return '';
        }

        [$class, $method] = explode('@', $action, 2);
        if (!class_exists($class) || !method_exists($class, $method)) {
            return '';
        }

        $reflection = new ReflectionMethod($class, $method);
        $lines = (array) file((string) $reflection->getFileName());

        return implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
    }
}
