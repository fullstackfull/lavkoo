<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\SellerStaffAccessMiddleware;
use App\Services\Marketplace\SellerPermissionService;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as VendorRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * SellerStaffAccessMiddleware decides what a staff member may do from the vendor URL. A value the client puts in a route parameter ({id},
 * {status}, {type}) is not part of the rule: the answer for a URL is the answer for its route, whatever the parameter says. The money rule
 * (withdrawals, payouts, bank and payment information) used to read the whole path, so a word such as `payout` in an id that the handler ignores
 * made the middleware ask for payouts.request instead of the rule of the route, and a role that only requests payouts, or one that holds every
 * permission, passed writes that the map refuses (the owner's password, the shop's details) and the products, orders and refund pages.
 *
 * Every vendor route that sits behind the middleware is asked about twice, with an ordinary value and with each word that names money in every
 * parameter, by a role that only requests payouts and by one that holds every permission: the two answers must be the same.
 */
class VendorStaffRouteParametersTest extends VendorPanelTestCase
{
    private const ONLY_REQUESTS_PAYOUTS = 'only requests payouts';
    private const HOLDS_EVERY_PERMISSION = 'holds every permission';
    private const HOLDS_EVERYTHING_BUT_PAYOUTS = 'holds everything but payouts';

    /** @var array<string, int> the staff member of each kind */
    private array $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStaffTables();
        $everyPermission = app(SellerPermissionService::class)->allKeys();
        $this->staff = [
            self::ONLY_REQUESTS_PAYOUTS => $this->insertStaffMember(name: 'payouts', permissions: ['payouts.request']),
            self::HOLDS_EVERY_PERMISSION => $this->insertStaffMember(name: 'everything', permissions: $everyPermission),
            self::HOLDS_EVERYTHING_BUT_PAYOUTS => $this->insertStaffMember(name: 'almost', permissions: array_values(array_diff($everyPermission, ['payouts.request']))),
        ];
    }

    /** @return array<string, array{string}> the roles that hold payouts.request, the permission a word that names money asks for */
    public static function rolesThatHoldPayouts(): array
    {
        return [
            self::ONLY_REQUESTS_PAYOUTS => [self::ONLY_REQUESTS_PAYOUTS],
            self::HOLDS_EVERY_PERMISSION => [self::HOLDS_EVERY_PERMISSION],
        ];
    }

    /** @return list<string> every word the money rule looks for */
    private static function wordsThatNameMoney(): array
    {
        return ['withdraw', 'payout', 'payment-information', 'bank-info', 'money-withdraw'];
    }

    #[DataProvider('rolesThatHoldPayouts')]
    public function test_no_value_in_a_route_parameter_changes_what_the_staff_middleware_decides_for_any_vendor_route(string $role): void
    {
        $routes = $this->vendorRoutesBehindTheStaffMiddleware();
        $this->assertGreaterThan(100, count($routes), 'the vendor routes were found');

        $refused = 0;
        foreach ($routes as [$method, $route]) {
            $ordinary = $this->decision(staff: $role, method: $method, route: $route, parameterValue: '1');
            $refused += $ordinary === 'refused' ? 1 : 0;

            foreach (self::wordsThatNameMoney() as $word) {
                $this->assertSame($ordinary, $this->decision(staff: $role, method: $method, route: $route, parameterValue: $word), "{$method} {$route->uri()} with {$word} in its parameters");
            }
        }
        $this->assertGreaterThan(0, $refused, 'the middleware refuses something, so the comparison is not between two passes');
    }

    #[DataProvider('rolesThatHoldPayouts')]
    public function test_the_routes_that_change_the_owners_account_and_the_shop_are_refused_whatever_the_id_says(string $role): void
    {
        foreach (['1', 'payout', 'withdraw'] as $value) {
            $this->assertSame('refused', $this->decisionFor(staff: $role, method: 'PATCH', uri: 'vendor/profile/update/{id}', parameterValue: $value), "the password, with {$value}");
            $this->assertSame('refused', $this->decisionFor(staff: $role, method: 'POST', uri: 'vendor/profile/update/{id}', parameterValue: $value), "the profile, with {$value}");
            $this->assertSame(
                $role === self::HOLDS_EVERY_PERMISSION ? 'passed' : 'refused',
                $this->decisionFor(staff: $role, method: 'POST', uri: 'vendor/shop/update/{id}', parameterValue: $value),
                "the shop, with {$value}: it takes shop_settings.manage"
            );
        }
    }

    public function test_the_money_routes_still_ask_for_payouts_by_the_words_of_their_own_address(): void
    {
        $moneyRoutes = [
            ['GET', 'vendor/business-settings/withdraw/index'], ['GET', 'vendor/profile/update-bank-info/{id}'],
            ['POST', 'vendor/shop/payment-information/add'], ['POST', 'vendor/dashboard/withdraw-request-update'],
        ];
        foreach ($moneyRoutes as [$method, $uri]) {
            $this->assertSame('passed', $this->decisionFor(staff: self::ONLY_REQUESTS_PAYOUTS, method: $method, uri: $uri, parameterValue: '1'), "{$method} {$uri} for a role that requests payouts");
            $this->assertSame('refused', $this->decisionFor(staff: self::HOLDS_EVERYTHING_BUT_PAYOUTS, method: $method, uri: $uri, parameterValue: '1'), "{$method} {$uri} for a role without payouts.request");
        }
    }

    /** @return list<array{string, VendorRoute}> */
    private function vendorRoutesBehindTheStaffMiddleware(): array
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            if (!str_starts_with($route->uri(), 'vendor/') || !in_array('seller_staff_access', (array)$route->middleware(), true)) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $routes[] = [$method, $route];
            }
        }

        return $routes;
    }

    private function decisionFor(string $staff, string $method, string $uri, string $parameterValue): string
    {
        foreach ($this->vendorRoutesBehindTheStaffMiddleware() as [$routeMethod, $route]) {
            if ($routeMethod === $method && $route->uri() === $uri) {
                return $this->decision(staff: $staff, method: $method, route: $route, parameterValue: $parameterValue);
            }
        }
        $this->fail("there is no vendor route {$method} {$uri} behind the staff middleware");
    }

    /** What the middleware does with a request for the route from that staff member, with $parameterValue in every parameter: passed or refused. */
    private function decision(string $staff, string $method, VendorRoute $route, string $parameterValue): string
    {
        $this->flushSession();
        $this->signInAsStaff($this->staff[$staff]);

        $request = Request::create('/' . preg_replace('/\{[^}]+\}/', $parameterValue, $route->uri()), $method);
        $request->setLaravelSession($this->app['session.store']);
        $request->setRouteResolver(static fn (): VendorRoute => $route);

        try {
            (new SellerStaffAccessMiddleware(app(SellerPermissionService::class)))->handle($request, static fn (): string => 'next');
        } catch (HttpException) {
            return 'refused';
        }

        return 'passed';
    }
}
