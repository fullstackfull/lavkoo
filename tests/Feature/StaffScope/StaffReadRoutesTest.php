<?php

namespace Tests\Feature\StaffScope;

use App\Http\Middleware\ScopeOrdersToStaffCountries;
use App\Services\Staff\StaffOrderRoutes;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\BuildsStaffReportWorld;
use Tests\TestCase;

/**
 * Every route of the screens of slice 3 that names an order by its number answers a restricted employee like an unknown one (a 404, the same body) when the order is outside their
 * countries, and a value that is not an id, whatever the database would make of it (GCC-14c, slice 3). The routes come from the real route table: for each one the order number is put
 * where its rule looks for it, in the path or in the input. Order 102 holds a Kuwaiti product and is in scope, 101 holds a Saudi one and is not, 999999 does not exist.
 */
class StaffReadRoutesTest extends TestCase
{
    use BuildsStaffReportWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStaffReportTables();
        $this->insertStaffProduct(id: 1, country: 'SA');
        $this->insertStaffProduct(id: 2, country: 'KW');
        $this->insertStaffOrder(id: 101, products: [1]);
        $this->insertStaffOrder(id: 102, products: [2]);
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 9, countries: ['KW']);
        $this->signInAsStaff(adminId: 9);
    }

    /** @return list<array{route: RoutingRoute, method: string, parts: list<string>}> the routes of slice 3 that name an order by its number */
    private function routesThatNameAnOrder(): array
    {
        $routes = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $controller = ltrim((string) $route->getControllerClass(), '\\');
            $rule = in_array($controller, StaffOrderRoutes::readScreenControllers(), true) ? StaffOrderRoutes::ruleOf(controller: $controller, method: $route->getActionMethod()) : null;
            $parts = array_values(array_filter($rule ?? [], static fn (string $part): bool => preg_match('/^order\.(route|input):/', $part) === 1));
            if ($parts !== []) {
                $routes[] = ['route' => $route, 'method' => array_values(array_diff($route->methods(), ['HEAD']))[0], 'parts' => $parts];
            }
        }

        return $routes;
    }

    private function lets(array $covered, string $number): bool
    {
        $path = [];
        $input = [];
        foreach ($covered['parts'] as $part) {
            [$what, $name] = explode(':', $part, 2);
            if ($what === 'order.route') {
                $path[$name] = $number;
            } else {
                $input[$name] = $number;
            }
        }
        $uri = preg_replace_callback('/\{(\w+)\??\}/', static fn (array $match): string => rawurlencode($path[$match[1]] ?? '5'), '/' . $covered['route']->uri());
        $request = Request::create($uri, $covered['method'], $input);
        try {
            $route = Route::getRoutes()->match($request);
        } catch (NotFoundHttpException) {
            return false;
        }
        $request->setRouteResolver(static fn () => $route);
        $route->bind($request);
        $this->app->instance('request', $request);

        try {
            app(ScopeOrdersToStaffCountries::class)->handle($request, static fn () => response('reached'));
        } catch (NotFoundHttpException) {
            return false;
        }

        return true;
    }

    public function test_the_walk_finds_the_four_routes_that_read_an_order_by_its_number(): void
    {
        $found = array_map(fn (array $covered): string => $covered['method'] . ' ' . $covered['route']->uri(), $this->routesThatNameAnOrder());
        sort($found);

        $this->assertSame([
            'GET admin/delivery-man/ajax-order-status-history/{order}', 'GET admin/transaction/pdf-order-wise-expense-transaction',
            'GET admin/transaction/pdf-order-wise-transaction', 'GET admin/vendors/order-details/{order_id}/{vendor_id}',
        ], $found);
    }

    public function test_an_order_of_the_employees_countries_passes_and_every_other_number_is_refused_on_every_such_route(): void
    {
        foreach ($this->routesThatNameAnOrder() as $covered) {
            $label = $covered['method'] . ' ' . $covered['route']->uri();

            $this->assertTrue($this->lets($covered, '102'), $label . ' with an order of their country');
            foreach (['101', '999999', '102abc', '0102', '1e2', '-102', '102.0', '0', ''] as $other) {
                $this->assertFalse($this->lets($covered, $other), $label . ' with ' . json_encode($other));
            }
        }
    }
}
