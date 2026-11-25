<?php

namespace Tests\Feature\Storefront;

use App\Http\Middleware\FileSessionPerStorefront;
use App\Services\Storefront\StorefrontSession;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A coupon, a currency or an address chosen in one storefront does not apply in another, and each market's choice is kept (A1-12).
 *
 * One browser session serves six markets over one cookie at path `/`. Until now one set of session keys held the applied coupon, the
 * displayed currency, the chosen address and the cart being checked out, so a Kuwaiti coupon was still in the session when the visitor
 * walked into the Saudi storefront, and the Saudi checkout read it.
 */
class StorefrontSessionIsolationTest extends TestCase
{
    private const SAUDI_COUPON = ['coupon_code' => 'SA-WELCOME', 'coupon_discount' => 30, 'coupon_type' => 'first_order', 'coupon_bearer' => 'inhouse', 'coupon_seller_id' => 0];

    private const KUWAITI_COUPON = ['coupon_code' => 'KW-WELCOME', 'coupon_discount' => 3, 'coupon_type' => 'free_delivery', 'coupon_bearer' => 'vendor', 'coupon_seller_id' => 7];

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerProbeRoute();
    }

    /** With storefronts off, the session is exactly what it is today: nothing is filed and nothing is forgotten. */
    public function test_with_storefronts_switched_off_a_coupon_survives_every_request(): void
    {
        config(['gcc.storefronts.enabled' => false]);

        $this->withSession(self::SAUDI_COUPON)->get('/__session-probe')->assertOk();
        $this->assertSame('SA-WELCOME', session('coupon_code'));
        $this->assertFalse(session()->has(StorefrontSession::FILED_UNDER), 'nothing is written while the switch is off');
        $this->assertFalse(session()->has(StorefrontSession::FILE));
    }

    public function test_a_coupon_taken_in_kuwait_is_not_in_the_session_in_saudi_arabia(): void
    {
        $this->switchOn();

        $this->probe('KW', self::KUWAITI_COUPON)->assertOk();
        $this->assertSame('KW-WELCOME', session('coupon_code'), 'its own market still has it');

        $this->probe('SA')->assertOk();

        foreach (array_keys(self::KUWAITI_COUPON) as $key) {
            $this->assertFalse(session()->has($key), "{$key} crossed into another market");
        }
    }

    /** Filing, not forgetting: the market the visitor goes back to has what they left there. */
    public function test_each_market_keeps_its_own_coupon_across_a_round_trip(): void
    {
        $this->switchOn();

        $this->probe('SA', self::SAUDI_COUPON);
        $this->probe('KW');
        session(self::KUWAITI_COUPON);

        $this->probe('SA');
        $this->assertSame(self::SAUDI_COUPON, array_intersect_key(session()->all(), self::SAUDI_COUPON));

        $this->probe('KW');
        $this->assertSame(self::KUWAITI_COUPON, array_intersect_key(session()->all(), self::KUWAITI_COUPON));
    }

    public function test_a_currency_chosen_in_one_storefront_does_not_apply_in_another(): void
    {
        $this->switchOn();

        $this->probe('KW', ['currency_code' => 'KWD', 'currency_symbol' => 'KD', 'currency_exchange_rate' => 1]);

        $this->probe('SA');
        $this->assertFalse(session()->has('currency_code'), 'the Saudi storefront would have been priced in dinars');
        $this->assertFalse(session()->has('currency_symbol'));
        $this->assertFalse(session()->has('currency_exchange_rate'));
    }

    public function test_the_chosen_address_and_the_cart_being_checked_out_stay_in_their_market(): void
    {
        $this->switchOn();

        $this->probe('AE', ['address_id' => 41, 'billing_address_id' => 42, 'cart_group_id' => 'ae-group', 'shipping_method_id' => 9, 'order_note' => 'leave at reception']);

        $this->probe('SA');
        foreach (['address_id', 'billing_address_id', 'cart_group_id', 'shipping_method_id', 'order_note'] as $key) {
            $this->assertFalse(session()->has($key), "{$key} crossed into another market");
        }
    }

    /** Staying in one market costs no write and loses nothing: the common request. */
    public function test_two_requests_in_the_same_storefront_keep_everything(): void
    {
        $this->switchOn();

        $this->probe('KW', self::KUWAITI_COUPON + ['currency_code' => 'KWD']);
        $this->probe('KW');

        $this->assertSame('KW-WELCOME', session('coupon_code'));
        $this->assertSame('KWD', session('currency_code'));
        $this->assertSame([], app(StorefrontSession::class)->filedFor('KW'), 'a market being served has nothing put away');
    }

    /**
     * The guest id is deliberately NOT filed per market, and this is the test that says so: GCC-13 gives a visitor one identity, and
     * the guest cart of two countries merges into each country's cart at login (StorefrontCartEdgesTest) only because one guest id
     * spans both. Filing it would mint a guest per market and take that behaviour away.
     */
    public function test_the_guest_id_is_one_identity_across_every_market(): void
    {
        $this->switchOn();

        $this->probe('KW', ['guest_id' => 5150]);
        $this->probe('SA');

        $this->assertSame(5150, session('guest_id'));
        $this->assertArrayHasKey('guest_id', StorefrontSession::PLATFORM_WIDE, 'the reason it is left alone is recorded beside it');
    }

    /** Filing a visitor's own state must never be done with a cookie of its own: one session cookie, as today. */
    public function test_filing_writes_no_cookie(): void
    {
        $this->switchOn();

        $response = $this->probe('KW', self::KUWAITI_COUPON);

        $this->assertSame([], array_values(array_filter(
            $response->headers->getCookies(),
            static fn ($cookie): bool => !in_array($cookie->getName(), [config('session.cookie'), 'XSRF-TOKEN'], true),
        )));
    }

    /** The website is where a session lives, so the website is where the filing is bound -- and nowhere else. */
    public function test_the_alias_is_bound_to_the_storefront_website_and_is_in_no_named_group(): void
    {
        $this->assertSame(FileSessionPerStorefront::class, app(Router::class)->getMiddleware()['storefront.session'] ?? null);

        foreach (['web', 'api'] as $group) {
            $this->assertNotContains('storefront.session', app(Router::class)->getMiddlewareGroups()[$group] ?? []);
        }

        // The REAL website routes, by name, not this test's own probe: unbinding the web group has to fail here. It did not, the first
        // time this test was written against "any route that carries the alias" -- the probe satisfied it on its own.
        foreach (['home', 'shop-cart', 'checkout-details', 'checkout-payment', 'checkout-complete'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "the website route {$name} is gone");
            $this->assertContains('storefront.session', $route->middleware(), "the website route {$name} does not file its session per market");
        }

        // And nothing stateless takes it: the token APIs carry no session to file.
        $filed = [];
        foreach (Route::getRoutes() as $route) {
            if (in_array('storefront.session', $route->middleware(), true)) {
                $filed[] = $route->uri();
            }
        }

        $this->assertSame([], array_values(array_filter($filed, static fn (string $uri): bool => str_starts_with($uri, 'api/'))));
    }

    /** No key is claimed by both lists: a key is one market's or the platform's, never argued over. */
    public function test_no_key_is_both_per_storefront_and_platform_wide(): void
    {
        $this->assertSame([], array_values(array_intersect(StorefrontSession::keys(), array_keys(StorefrontSession::PLATFORM_WIDE))));
        $this->assertSame(StorefrontSession::keys(), array_values(array_unique(StorefrontSession::keys())));
    }

    private function switchOn(): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.countries.KW.active' => true,
            'gcc.storefronts.countries.AE.active' => true,
        ]);
    }

    /** @param array<string, mixed> $chosenThere what the visitor chooses while in that market */
    private function probe(string $country, array $chosenThere = []): TestResponse
    {
        $response = $this->get('/__session-probe', ['X-Storefront' => $country]);

        if ($chosenThere !== []) {
            session($chosenThere);
        }

        return $response;
    }

    private function registerProbeRoute(): void
    {
        Route::middleware(['web', 'storefront', 'storefront.session'])
            ->get('/__session-probe', fn () => response()->json(['filed_under' => session(StorefrontSession::FILED_UNDER)]));
    }
}
