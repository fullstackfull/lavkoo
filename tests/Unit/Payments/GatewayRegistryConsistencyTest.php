<?php

namespace Tests\Unit\Payments;

use App\Services\MyFatoorah\GatewayConfigRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\PaymentGatewayRegistryReader;
use Tests\TestCase;

use function App\Utils\payment_gateways;

/**
 * The payment gateway registries have to agree with each other.
 *
 * A gateway key is written down in six places: the route map in Payment::generate_link,
 * Helpers::getDefaultPaymentGateways(), GlobalConstant::DEFAULT_PAYMENT_GATEWAYS, the currency map in
 * PaymentGatewayTrait, the routes the application registers, and app/Traits/Payment.txt — the copy
 * AddonService::deleteAddon writes over Payment.php when the Gateways add-on is deleted. Nothing in the
 * application checks that the six say the same thing, so a gateway added to one of them and forgotten
 * in another reaches a customer as a dead link or a missing payment method.
 *
 * Every list here is READ from its own source file (PaymentGatewayRegistryReader) rather than restated
 * in this test. A test that restated them would pass for ever and prove nothing about drift; read from
 * the source, a seventh gateway added tomorrow to one place and not the others fails a named test.
 *
 * Two disagreements exist today and are PINNED rather than fixed, because changing a payment route or
 * the trait a deleted add-on restores needs the security or financial owner: `liqpay` (its route map
 * path is not the path the application registers) and the four keys app/Traits/Payment.txt never
 * gained. Each is a named constant below with a message saying what to do when it is put right.
 */
class GatewayRegistryConsistencyTest extends TestCase
{
    /**
     * `liqpay` is in both default gateway lists, so an operator can switch it on and a customer can
     * pick it, but Payment::generate_link sends that customer to `payment/liqpay/pay` while
     * routes/web/routes.php registers `payment/liqpay/payment`. The customer gets a 404 instead of a
     * payment page. On main as well as here; recorded for the owner, not fixed.
     */
    private const DEFAULT_GATEWAYS_WHOSE_ROUTE_IS_NOT_REGISTERED = ['liqpay'];

    /**
     * `mercadopago_pix` is in the route map and nowhere else at all — no currency entry, no default-list
     * entry, no controller and no route. It can never be selected, so it costs a customer nothing
     * today; it is pinned so that a key added to the route map alone cannot hide behind it.
     */
    private const ROUTE_MAP_KEYS_THE_CURRENCY_MAP_DOES_NOT_PRICE = ['mercadopago_pix'];

    /**
     * What app/Traits/Payment.txt would lose if the Gateways add-on were deleted on a server where it is
     * installed: four gateway keys, and MyFatoorah rerouted to the alias path. Risk R-002.
     */
    private const KEYS_THE_DISCARDED_TRAIT_COPY_IS_MISSING = ['paymera', 'mtn_cash', 'my_fatoorah', 'mercadopago_pix'];

    private const PATHS_THE_DISCARDED_TRAIT_COPY_SENDS_ELSEWHERE = ['fatoorah' => 'payment/fatoorah/pay'];

    /** D2-04: every key of the route map that has a route is in the default list and the currency map. */
    public function test_every_routed_gateway_key_is_in_both_default_lists_and_in_the_currency_map(): void
    {
        $helpersDefaults = PaymentGatewayRegistryReader::helpersDefaultGatewayKeys();
        $constantDefaults = PaymentGatewayRegistryReader::globalConstantDefaultGatewayKeys();
        $pricedKeys = PaymentGatewayRegistryReader::currencyMapKeys();

        $absentFromTheDefaultLists = [];
        $absentFromTheCurrencyMap = [];

        foreach ($this->gatewayKeysTheApplicationRoutes() as $key) {
            if (!in_array($key, $helpersDefaults, true) || !in_array($key, $constantDefaults, true)) {
                $absentFromTheDefaultLists[] = $key;
            }

            if (!in_array($key, $pricedKeys, true)) {
                $absentFromTheCurrencyMap[] = $key;
            }
        }

        $this->assertSame([], $absentFromTheDefaultLists, 'A gateway the application routes is missing from ' . PaymentGatewayRegistryReader::HELPERS_FILE . ' or ' . PaymentGatewayRegistryReader::GLOBAL_CONSTANT_FILE . ', so it can be paid for but never offered.');
        $this->assertSame([], $absentFromTheCurrencyMap, 'A gateway the application routes has no entry in ' . PaymentGatewayRegistryReader::CURRENCY_MAP_FILE . ', so getPaymentGatewayCurrencyCode() falls back to USD for it.');
    }

    /** The same requirement one step wider: a key added to the route map alone is caught before it is ever routed. */
    public function test_every_gateway_key_in_the_route_map_is_priced_by_the_currency_map(): void
    {
        $pricedKeys = PaymentGatewayRegistryReader::currencyMapKeys();
        $unpriced = [];

        foreach (PaymentGatewayRegistryReader::liveRouteMapKeys() as $key) {
            if (!in_array($key, $pricedKeys, true) && !$this->isAliasKey($key)) {
                $unpriced[] = $key;
            }
        }

        $this->assertSame(
            self::ROUTE_MAP_KEYS_THE_CURRENCY_MAP_DOES_NOT_PRICE,
            $unpriced,
            'The route map in ' . PaymentGatewayRegistryReader::LIVE_TRAIT_FILE . ' and the currency map in ' . PaymentGatewayRegistryReader::CURRENCY_MAP_FILE . ' disagree. Add the gateway to both, or, if a pinned exception has been put right, remove it from ROUTE_MAP_KEYS_THE_CURRENCY_MAP_DOES_NOT_PRICE.'
        );
    }

    public function test_an_alias_key_routes_where_the_gateway_it_aliases_routes(): void
    {
        $routeMap = PaymentGatewayRegistryReader::liveRouteMap();
        $canonicalPath = $routeMap[GatewayConfigRepository::KEY] ?? null;

        $this->assertNotNull($canonicalPath, GatewayConfigRepository::KEY . ' has no entry in the route map.');

        foreach (GatewayConfigRepository::ALIASES as $alias) {
            if (!array_key_exists($alias, $routeMap)) {
                continue;
            }

            $this->assertSame($canonicalPath, $routeMap[$alias], "The alias '{$alias}' and '" . GatewayConfigRepository::KEY . "' are the same gateway but route to different paths.");
        }
    }

    public function test_the_two_default_gateway_lists_hold_the_same_keys_in_the_same_order(): void
    {
        $this->assertSame(
            PaymentGatewayRegistryReader::helpersDefaultGatewayKeys(),
            PaymentGatewayRegistryReader::globalConstantDefaultGatewayKeys(),
            'Helpers::getDefaultPaymentGateways() and GlobalConstant::DEFAULT_PAYMENT_GATEWAYS are the same list kept in two files; a gateway added to one and not the other is offered at checkout but hidden in the admin panel, or the reverse.'
        );
    }

    public function test_every_default_gateway_is_in_the_route_map_and_the_currency_map(): void
    {
        $routedKeys = array_keys(PaymentGatewayRegistryReader::liveRouteMap());
        $pricedKeys = PaymentGatewayRegistryReader::currencyMapKeys();
        $defaults = PaymentGatewayRegistryReader::helpersDefaultGatewayKeys();

        $this->assertSame([], array_values(array_diff($defaults, $routedKeys)), 'A gateway offered by default has no entry in the route map, so Payment::generate_link returns false and the customer is sent nowhere.');
        $this->assertSame([], array_values(array_diff($defaults, $pricedKeys)), 'A gateway offered by default has no entry in the currency map.');
    }

    public function test_no_gateway_key_is_declared_twice_in_any_registry(): void
    {
        $registries = [
            PaymentGatewayRegistryReader::LIVE_TRAIT_FILE => PaymentGatewayRegistryReader::liveRouteMapKeys(),
            PaymentGatewayRegistryReader::DISCARDED_TRAIT_FILE => PaymentGatewayRegistryReader::discardedTraitRouteMapKeys(),
            PaymentGatewayRegistryReader::CURRENCY_MAP_FILE => PaymentGatewayRegistryReader::currencyMapKeys(),
            PaymentGatewayRegistryReader::HELPERS_FILE => PaymentGatewayRegistryReader::helpersDefaultGatewayKeys(),
            PaymentGatewayRegistryReader::GLOBAL_CONSTANT_FILE => PaymentGatewayRegistryReader::globalConstantDefaultGatewayKeys(),
        ];

        $repeated = [];
        foreach ($registries as $file => $keys) {
            $twice = array_keys(array_filter(array_count_values($keys), static fn (int $times): bool => $times > 1));
            if ($twice !== []) {
                $repeated[$file] = $twice;
            }
        }

        $this->assertSame([], $repeated, 'A key written twice in an array literal is invisible once PHP has evaluated it: the later entry silently wins.');
    }

    /**
     * Every gateway a customer can be offered by default reaches a path this application registers.
     *
     * The paths of the add-on gateways are deliberately not asserted: they are registered by
     * Modules/Gateways when that add-on is installed, and none of them is in the default list, so none
     * of them can be offered while it is absent.
     */
    public function test_every_default_gateway_reaches_a_route_the_application_registers(): void
    {
        $routeMap = PaymentGatewayRegistryReader::liveRouteMap();
        $registeredPaths = $this->registeredPaths();

        $unreachable = [];
        foreach (PaymentGatewayRegistryReader::helpersDefaultGatewayKeys() as $key) {
            if (isset($routeMap[$key]) && !in_array($routeMap[$key], $registeredPaths, true)) {
                $unreachable[] = $key;
            }
        }

        $this->assertSame(
            self::DEFAULT_GATEWAYS_WHOSE_ROUTE_IS_NOT_REGISTERED,
            $unreachable,
            'Payment::generate_link sends a customer to a path routes/web/routes.php does not register, so the payment page 404s. Register the path or correct the route map; if a pinned exception has been put right, remove it from DEFAULT_GATEWAYS_WHOSE_ROUTE_IS_NOT_REGISTERED.'
        );
    }

    /**
     * app/Traits/Payment.txt is not dead code: AddonService::deleteAddon copies it over
     * app/Traits/Payment.php when the Gateways add-on is deleted. Whatever it is missing is what the
     * platform would lose at that moment, silently.
     */
    public function test_the_discarded_trait_copy_drifts_from_the_live_one_only_where_it_already_does(): void
    {
        $live = PaymentGatewayRegistryReader::liveRouteMap();
        $discarded = PaymentGatewayRegistryReader::discardedTraitRouteMap();

        $sentElsewhere = [];
        foreach ($discarded as $key => $path) {
            if (isset($live[$key]) && $live[$key] !== $path) {
                $sentElsewhere[$key] = $path;
            }
        }

        $advice = ' ' . PaymentGatewayRegistryReader::DISCARDED_TRAIT_FILE . ' replaces ' . PaymentGatewayRegistryReader::LIVE_TRAIT_FILE . ' when the Gateways add-on is deleted (AddonService::deleteAddon), so this drift is what the platform would lose at that moment. Carry the change into both files, or, once a pinned difference has been put right, remove it from the constant naming it.';

        $this->assertSame(
            self::KEYS_THE_DISCARDED_TRAIT_COPY_IS_MISSING,
            array_values(array_diff(array_keys($live), array_keys($discarded))),
            'Gateway keys the discarded trait copy does not have.' . $advice
        );
        $this->assertSame(
            self::PATHS_THE_DISCARDED_TRAIT_COPY_SENDS_ELSEWHERE,
            $sentElsewhere,
            'Gateway keys the discarded trait copy routes to a different path.' . $advice
        );
        $this->assertSame(
            [],
            array_values(array_diff(array_keys($discarded), array_keys($live))),
            'Gateway keys only the discarded trait copy has.' . $advice
        );
    }

    /**
     * A key the application does not route is never offered at checkout.
     *
     * This asserts the behaviour, not only the shape of the lists: a stub gateway row is switched on in
     * the settings table and payment_gateways() — the one function behind both the web checkout and the
     * `payment_methods` array /api/v1/config hands the mobile app — is asked what it offers.
     */
    public function test_a_gateway_key_the_application_does_not_route_is_not_offered_at_checkout(): void
    {
        $this->buildGatewaySettingsTable();

        $stub = $this->gatewayKeyWithNeitherRouteNorDefaultListing();
        $offerable = $this->defaultGatewayTheApplicationRoutes();

        $this->switchOnGateway(key: $stub);
        $this->switchOnGateway(key: $offerable);

        // The Gateways add-on is not installed in this checkout, which is the state payment_gateways()
        // filters by the default list in; the add-on's own routes would otherwise register the stubs.
        config(['get_payment_publish_status' => 0]);

        $offered = payment_gateways()->pluck('key_name')->all();

        $this->assertNotContains($stub, $offered, "'{$stub}' has no route and no default-list entry, so a customer picking it could only ever reach a dead link.");
        $this->assertContains($offerable, $offered, "'{$offerable}' is routed and in the default list, so the filter has not simply emptied the list.");
    }

    /** @return list<string> the gateway keys whose route-map path this application actually registers, aliases aside */
    private function gatewayKeysTheApplicationRoutes(): array
    {
        $registeredPaths = $this->registeredPaths();

        $keys = [];
        foreach (PaymentGatewayRegistryReader::liveRouteMap() as $key => $path) {
            if (!$this->isAliasKey($key) && in_array($path, $registeredPaths, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    private function gatewayKeyWithNeitherRouteNorDefaultListing(): string
    {
        $registeredPaths = $this->registeredPaths();
        $defaults = PaymentGatewayRegistryReader::helpersDefaultGatewayKeys();

        foreach (PaymentGatewayRegistryReader::liveRouteMap() as $key => $path) {
            if (!in_array($key, $defaults, true) && !in_array($path, $registeredPaths, true)) {
                return $key;
            }
        }

        $this->fail('Every key in the route map is routed and listed, so this test has nothing to prove and should be reconsidered.');
    }

    private function defaultGatewayTheApplicationRoutes(): string
    {
        $routeMap = PaymentGatewayRegistryReader::liveRouteMap();
        $registeredPaths = $this->registeredPaths();

        foreach (PaymentGatewayRegistryReader::helpersDefaultGatewayKeys() as $key) {
            // MyFatoorah aside: payment_gateways() drops it unless it holds credentials, which is its
            // own tested behaviour and would make this test measure the wrong thing.
            if ($key === GatewayConfigRepository::KEY) {
                continue;
            }

            if (isset($routeMap[$key]) && in_array($routeMap[$key], $registeredPaths, true)) {
                return $key;
            }
        }

        $this->fail('No default gateway reaches a registered route.');
    }

    private function isAliasKey(string $key): bool
    {
        return $key !== GatewayConfigRepository::KEY && in_array($key, GatewayConfigRepository::ALIASES, true);
    }

    /** @return list<string> */
    private function registeredPaths(): array
    {
        $paths = [];
        foreach (Route::getRoutes() as $route) {
            $paths[] = $route->uri();
        }

        return array_values(array_unique($paths));
    }

    private function switchOnGateway(string $key): void
    {
        DB::table('addon_settings')->insert([
            'id' => (string) Str::uuid(),
            'key_name' => $key,
            'settings_type' => 'payment_config',
            'mode' => 'live',
            'is_active' => 1,
            'live_values' => json_encode(['status' => 1, 'mode' => 'live']),
            'test_values' => json_encode(['status' => 1, 'mode' => 'live']),
            'additional_data' => json_encode(['gateway_title' => $key]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** The gateway settings table, with the columns the Setting model reads. It lives in the installed SQL dump, not in a migration. */
    private function buildGatewaySettingsTable(): void
    {
        Schema::dropIfExists('addon_settings');
        Schema::create('addon_settings', static function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->string('key_name')->nullable();
            $table->text('live_values')->nullable();
            $table->text('test_values')->nullable();
            $table->string('settings_type')->nullable();
            $table->string('mode')->default('test');
            $table->integer('is_active')->default(0);
            $table->text('additional_data')->nullable();
            $table->timestamps();
        });
    }
}
