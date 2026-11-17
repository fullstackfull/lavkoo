<?php

namespace Tests\Support;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use ReflectionMethod;

/**
 * Which routes of the admin panel read products or name one, read from the real route table and the source of the controllers (GCC-14c). It is a pattern check over source
 * text, so it has blind spots: a controller that reaches products only through a service or a trait that it does not name here is not seen. It does see the repository, the model,
 * the product manager, the products table and a product id in a route or a request.
 *
 * Two readings, both over the routes whose middleware holds `admin`:
 *  - a route reads products when the source of its own action, its path or its parameters say so;
 *  - a controller reads products when its file does, in any of its actions or helpers, so that a route that delegates to a helper of its controller is not missed.
 */
final class StaffProductRouteScanner
{
    /** What in the source of a file or an action means that it reads products. */
    private const SOURCE_MARKERS = [
        'repository' => '/\b(?:productRepo|ProductRepo|productRepository|ProductRepositoryInterface)\b/',
        'model' => '/(?<![A-Za-z])(?:\\\\App\\\\Models\\\\)?Product::/',
        'manager' => '/\bProductManager::/',
        'table' => '/(?:table|from)\(\s*[\'"]products[\'"]/',
        'product id in the request' => '/[\'"](?:product_id|productId|product_ids|productIds)[\'"]/',
    ];

    /** @return array<string, array{file: string, routes: list<string>, productRoutes: list<string>, markers: list<string>}> the admin controllers that read products, by class */
    public function controllers(): array
    {
        $found = [];
        foreach ($this->adminRoutes() as $route) {
            $class = ltrim((string) $route->getControllerClass(), '\\');
            if ($class === '' || !class_exists($class)) {
                continue;
            }

            $found[$class]['routes'][] = $this->keyOf(route: $route);
            if ($this->routeReadsProducts(route: $route) !== []) {
                $found[$class]['productRoutes'][] = $this->keyOf(route: $route);
            }
        }

        $controllers = [];
        foreach ($found as $class => $routes) {
            $file = (string) (new ReflectionClass($class))->getFileName();
            $markers = array_keys(array_filter(self::SOURCE_MARKERS, static fn (string $pattern): bool => preg_match($pattern, (string) file_get_contents($file)) === 1));
            if ($markers !== [] || ($routes['productRoutes'] ?? []) !== []) {
                $controllers[$class] = ['file' => $file, 'routes' => $routes['routes'], 'productRoutes' => $routes['productRoutes'] ?? [], 'markers' => $markers];
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

    /** @return list<string> why a route reads products or names one: empty when it does not */
    public function routeReadsProducts(RoutingRoute $route): array
    {
        $reasons = [];
        foreach (self::SOURCE_MARKERS as $name => $pattern) {
            if (preg_match($pattern, $this->actionSource(route: $route)) === 1) {
                $reasons[] = $name;
            }
        }
        foreach ($route->parameterNames() as $parameter) {
            if (preg_match('/^product/i', $parameter) === 1) {
                $reasons[] = 'parameter ' . $parameter;
            }
        }
        if (preg_match('#(^|/)[a-z-]*product[a-z-]*(/|$)#i', $route->uri()) === 1) {
            $reasons[] = 'path';
        }

        return $reasons;
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
