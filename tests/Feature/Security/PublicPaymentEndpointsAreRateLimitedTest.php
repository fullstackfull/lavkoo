<?php

namespace Tests\Feature\Security;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every public payment endpoint carries a rate limit (D2-17).
 *
 * The gateway return and callback routes are public by necessity: a gateway, not a browser holding a
 * session, is what calls them, and twelve of them stand the CSRF check down for exactly that reason.
 * Not one of them carried a limit, so each was reachable by anyone at any rate -- and some of them do
 * real work: read a payment, ask a provider, settle an order.
 *
 * This reads the ROUTE TABLE the application actually boots rather than the source of a route file, so
 * a gateway added outside the throttled group is caught instead of being assumed to be inside it.
 */
class PublicPaymentEndpointsAreRateLimitedTest extends TestCase
{
    private const LIMITER = 'throttle:public-payment-endpoints';

    /** @return array<string, \Illuminate\Routing\Route> uri => route, for everything under /payment */
    private function paymentRoutes(): array
    {
        $found = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (str_starts_with($uri, 'payment/')) {
                $found[$uri . '|' . implode(',', $route->methods())] = $route;
            }
        }

        return $found;
    }

    public function test_every_gateway_return_and_callback_route_carries_the_limit(): void
    {
        $routes = $this->paymentRoutes();

        $this->assertGreaterThanOrEqual(40, count($routes), 'the gateway routes are not in the route table at all, so this test is measuring nothing');

        foreach ($routes as $key => $route) {
            $this->assertContains(
                self::LIMITER,
                $route->middleware(),
                "{$key} is a public payment endpoint with no rate limit. A gateway added outside the throttled group has to be throttled too."
            );
        }
    }

    public function test_the_routes_that_stand_the_csrf_check_down_are_limited_too(): void
    {
        // These are the ones a gateway POSTs to with no session and no token, so they are the ones a
        // stranger can reach most easily.
        $postRoutes = array_filter(
            $this->paymentRoutes(),
            static fn ($route) => in_array('POST', $route->methods(), true)
        );

        $this->assertNotEmpty($postRoutes, 'no POST payment route is in the route table');

        foreach ($postRoutes as $key => $route) {
            $this->assertContains(self::LIMITER, $route->middleware(), "{$key} is a public POST payment endpoint with no rate limit");
        }
    }

    /**
     * The one case the two tests above cannot see: a gateway route registered OUTSIDE the `payment/` prefix.
     *
     * Both of them find their routes by that prefix, so a gateway whose routes are declared somewhere else -- another route
     * file, another prefix, a module -- is simply not in their scope and they would stay green while it went unlimited. This
     * one finds the routes by the controller NAMESPACE every gateway's controller lives in, which a new gateway's controller
     * joins the day it is written, and it reads the middleware the router RESOLVES so a limit declared through a group or by
     * class name counts as one.
     *
     * Public here means what it says: a route behind an authentication gate is bounded by that gate and by the `global`
     * limiter, and is not what this is about.
     */
    public function test_a_gateway_endpoint_declared_outside_the_payment_prefix_is_limited_too(): void
    {
        $authentication = ['Illuminate\\Auth\\Middleware\\Authenticate', 'App\\Http\\Middleware\\AdminMiddleware', 'App\\Http\\Middleware\\SellerMiddleware'];
        $unlimited = [];
        $found = 0;

        foreach (Route::getRoutes() as $route) {
            if (!str_starts_with((string) $route->getActionName(), 'App\\Http\\Controllers\\Payment_Methods\\')) {
                continue;
            }

            $resolved = array_filter(Route::resolveMiddleware($route->middleware(), $route->excludedMiddleware()), 'is_string');
            if (array_intersect($authentication, array_map(static fn (string $middleware): string => strtok($middleware, ':'), $resolved)) !== []) {
                continue;
            }

            $found++;
            $throttled = array_filter($resolved, static fn (string $middleware): bool => str_starts_with($middleware, 'Illuminate\\Routing\\Middleware\\ThrottleRequests'));
            if ($throttled === []) {
                $unlimited[] = implode(',', $route->methods()) . ' /' . $route->uri();
            }
        }

        $this->assertGreaterThanOrEqual(40, $found, 'the scan found ' . $found . ' gateway controller routes, which is too few for it to be reaching the gateway controllers at all');
        $this->assertSame([], $unlimited, 'these gateway endpoints are public and unbounded');
    }

    public function test_the_limiter_is_registered_and_counts_per_source_address_only(): void
    {
        $limiter = RateLimiter::limiter('public-payment-endpoints');

        $this->assertNotNull($limiter, 'the route table names a limiter that is not registered, so every request through it would fail');

        $request = Request::create('/payment/paymob/pay', 'GET', ['payment_id' => 'anything']);
        $request->server->set('REMOTE_ADDR', '203.0.113.7');

        $limit = $limiter($request);
        $this->assertInstanceOf(Limit::class, $limit);
        $this->assertSame(600, $limit->maxAttempts, 'the ceiling moved; it is a posture number and wants saying out loud');
        $this->assertSame('203.0.113.7', $limit->key, 'the limit is keyed by something other than the source address');
    }

    public function test_the_key_cannot_be_varied_by_anything_the_caller_chooses(): void
    {
        // A key taken from the path or the body -- the gateway's name, a payment id -- would let one
        // prober vary it and buy an unlimited number of requests from one address.
        $limiter = RateLimiter::limiter('public-payment-endpoints');

        $keys = [];
        foreach ([
            ['/payment/paymob/pay', 'GET', ['payment_id' => 'one']],
            ['/payment/stripe/success', 'GET', ['payment_id' => 'two']],
            ['/payment/sslcommerz/success', 'POST', ['tran_id' => 'three']],
        ] as [$uri, $method, $input]) {
            $request = Request::create($uri, $method, $input);
            $request->server->set('REMOTE_ADDR', '203.0.113.7');
            $keys[] = $limiter($request)->key;
        }

        $this->assertCount(1, array_unique($keys), 'two requests from one address got different limiter keys, so the limit can be stepped around');
    }

    public function test_a_different_source_address_has_its_own_allowance(): void
    {
        $limiter = RateLimiter::limiter('public-payment-endpoints');

        $first = Request::create('/payment/paymob/pay', 'GET');
        $first->server->set('REMOTE_ADDR', '203.0.113.7');
        $second = Request::create('/payment/paymob/pay', 'GET');
        $second->server->set('REMOTE_ADDR', '198.51.100.9');

        $this->assertNotSame($limiter($first)->key, $limiter($second)->key, 'one customer exhausting the limit would lock out everyone else');
    }

    public function test_the_two_limiters_that_already_existed_are_untouched(): void
    {
        foreach (['myfatoorah-webhooks' => 600, 'myfatoorah-status' => 90] as $name => $expected) {
            $limiter = RateLimiter::limiter($name);
            $this->assertNotNull($limiter, "the {$name} limiter is gone");

            $request = Request::create('/api/payment/myfatoorah/status', 'GET');
            $request->server->set('REMOTE_ADDR', '203.0.113.7');

            $this->assertSame($expected, $limiter($request)->maxAttempts, "the {$name} ceiling moved");
        }
    }

    public function test_the_limit_acts_on_a_real_request_and_not_only_in_the_route_table(): void
    {
        // The throttle middleware is the only thing that sets these headers, so their presence on a
        // real response is what proves it ran -- rather than that it is configured somewhere.
        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.70'])
            ->get('/payment/paymob/pay?payment_id=' . str_repeat('0', 8));

        $this->assertSame('600', $response->headers->get('X-RateLimit-Limit'), 'the throttle did not run on this route');
        $this->assertSame('599', $response->headers->get('X-RateLimit-Remaining'), 'the request was not counted against the allowance');
        $this->assertNotSame(429, $response->getStatusCode(), 'the very first request was refused, so the ceiling is far too low');
    }

    public function test_a_caller_past_the_ceiling_is_refused(): void
    {
        /*
         * The bucket is filled directly rather than by making 600 requests. The key is the one
         * Illuminate\Routing\Middleware\ThrottleRequests builds at its line 134 --
         * md5($limiterName . $limit->key) -- so if the framework ever changes that, this test fails
         * loudly instead of quietly proving nothing.
         *
         * The refusal is read off the EXCEPTION rather than off a rendered page, deliberately:
         * resources/views/errors/429.blade.php reads business_settings, so rendering it needs a
         * database. What is being proved here is that the middleware refuses, and that must not
         * depend on whether an error page can be drawn. (That the 429 page queries the database at
         * all is a finding of its own -- a flood would make a database read per refused request --
         * and it is recorded on the pull request rather than changed here.)
         */
        $address = '203.0.113.77';
        $key = md5('public-payment-endpoints' . $address);
        RateLimiter::clear($key);

        for ($i = 0; $i < 600; $i++) {
            RateLimiter::hit($key, 60);
        }

        $this->withoutExceptionHandling();
        $this->expectException(ThrottleRequestsException::class);

        $this->withServerVariables(['REMOTE_ADDR' => $address])
            ->get('/payment/paymob/pay?payment_id=' . str_repeat('0', 8));
    }

    public function test_one_address_flooding_does_not_shut_the_gateway_returns_for_everybody(): void
    {
        $flooded = '203.0.113.78';
        RateLimiter::clear(md5('public-payment-endpoints' . $flooded));
        for ($i = 0; $i < 600; $i++) {
            RateLimiter::hit(md5('public-payment-endpoints' . $flooded), 60);
        }

        $other = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.90'])
            ->get('/payment/paymob/pay?payment_id=' . str_repeat('0', 8));

        $this->assertNotSame(429, $other->getStatusCode(), 'one address exhausting its allowance refused another address too');
        $this->assertSame('599', $other->headers->get('X-RateLimit-Remaining'), 'the other address did not start with its own full allowance');
    }
}
