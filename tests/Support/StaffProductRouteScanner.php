<?php

namespace Tests\Support;

use Illuminate\Routing\Route as RoutingRoute;

/**
 * Which routes of the admin panel read products or name one (GCC-14c): the repository, the model, the product manager, the products table and a product id in a route or a
 * request. The walk and its blind spots are those of StaffRouteScanner.
 */
final class StaffProductRouteScanner extends StaffRouteScanner
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
        return $this->scan(readingKey: 'productRoutes');
    }

    /** @return list<string> why a route reads products or names one: empty when it does not */
    public function routeReadsProducts(RoutingRoute $route): array
    {
        return $this->routeReasons(route: $route);
    }

    protected function sourceMarkers(): array
    {
        return self::SOURCE_MARKERS;
    }

    protected function nameReasons(RoutingRoute $route): array
    {
        $reasons = [];
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
}
