<?php

namespace Tests\Unit\Payments;

use App\Services\MyFatoorah\GatewayConfigRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\PaymentGatewayRegistryReader;
use Tests\Support\RefusesToTouchALiveDatabase;
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
 * the source, a seventh gateway added tomorrow to one of the six places and not the others fails a named
 * test. Each pairing is asserted in BOTH directions, because a one-way check passes for whichever list
 * the new key was added to. The one gap left is the shape of a route: the registered-routes side can
 * only recognise the conventional `payment/<gateway>/pay` entry path, so a gateway registered under some
 * other shape — `payment/bkash/make-payment` is the existing example — is not seen there.
 *
 * Four disagreements exist today and are PINNED rather than fixed, because changing a payment route, the
 * trait a deleted add-on restores or a currency table needs the security or financial owner: `liqpay`
 * (its route-map path is not the path the application registers), `mercadopago_pix` (routed, priced
 * nowhere), `cinetpay` (priced, routed nowhere, and in no other file in the repository) and the four keys
 * app/Traits/Payment.txt never gained. Each is a named constant below with a message saying what to do
 * when it is put right.
 */
class GatewayRegistryConsistencyTest extends TestCase
{
    use RefusesToTouchALiveDatabase;

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

    /**
     * `cinetpay` is priced by the currency map and appears NOWHERE else in the repository: no route-map
     * entry, no default-list entry, no controller, no route. Nothing can select it, so it costs a customer
     * nothing today; it is pinned so that a gateway priced and then forgotten cannot hide behind it.
     */
    private const CURRENCY_MAP_KEYS_THE_ROUTE_MAP_DOES_NOT_ROUTE = ['cinetpay'];

    /**
     * `payment/fatoorah/pay` is registered and reaches the MyFatoorah controller, but the route map sends
     * new links to the canonical `payment/myfatoorah/pay`; the alias path stays registered so that an app
     * build or a bookmark still holding it resolves (GatewayConfigRepository::ALIASES). Registered on
     * purpose and unused by the route map on purpose — pinned so that an entry path registered for a
     * gateway no registry knows about cannot hide behind it.
     */
    private const REGISTERED_ENTRY_PATHS_THE_ROUTE_MAP_DOES_NOT_USE = ['payment/fatoorah/pay'];

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

    /** The same pairing the other way round: a gateway priced but never routed is caught too. */
    public function test_every_gateway_key_the_currency_map_prices_is_in_the_route_map(): void
    {
        $routedKeys = array_keys(PaymentGatewayRegistryReader::liveRouteMap());

        $this->assertSame(
            self::CURRENCY_MAP_KEYS_THE_ROUTE_MAP_DOES_NOT_ROUTE,
            array_values(array_diff(PaymentGatewayRegistryReader::currencyMapKeys(), $routedKeys)),
            'The currency map in ' . PaymentGatewayRegistryReader::CURRENCY_MAP_FILE . ' prices a gateway the route map in ' . PaymentGatewayRegistryReader::LIVE_TRAIT_FILE . ' cannot send anyone to, so Payment::generate_link returns false for it. Add the gateway to both, or, if a pinned exception has been put right, remove it from CURRENCY_MAP_KEYS_THE_ROUTE_MAP_DOES_NOT_ROUTE.'
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
     * The same pairing the other way round: a gateway whose payment page is registered and whose keys
     * were never written down anywhere is caught too.
     *
     * Only the conventional entry path is recognised — `payment/<gateway>/pay`, the shape all but one of
     * today's gateways use. A gateway registered under another shape (`payment/bkash/make-payment`) is
     * invisible here, which is a limit of this check, not a licence.
     */
    public function test_every_gateway_entry_path_the_application_registers_is_in_the_route_map(): void
    {
        $entryPaths = array_filter(
            $this->registeredPaths(),
            static fn (string $path): bool => str_starts_with($path, 'payment/') && str_ends_with($path, '/pay')
        );

        $this->assertSame(
            self::REGISTERED_ENTRY_PATHS_THE_ROUTE_MAP_DOES_NOT_USE,
            array_values(array_diff($entryPaths, array_values(PaymentGatewayRegistryReader::liveRouteMap()))),
            'A payment page is registered that no gateway key in ' . PaymentGatewayRegistryReader::LIVE_TRAIT_FILE . ' leads to, so nothing can ever send a customer there. Add the gateway to the route map and the other registries, or, if a pinned exception has been put right, remove it from REGISTERED_ENTRY_PATHS_THE_ROUTE_MAP_DOES_NOT_USE.'
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
     * The behaviour of the default-list filter: a switched-on gateway outside the list is not offered.
     *
     * This asks the running application rather than the source files — a stub gateway row is switched on
     * in the settings table and payment_gateways(), the one function behind both the web checkout and the
     * `payment_methods` array /api/v1/config hands the mobile app, is asked what it offers.
     *
     * What it proves is exactly that filter, and no more. payment_gateways() never looks at routes, so a
     * routeless gateway that IS in the default list is still offered; that case belongs to the sibling
     * below, which follows each offered key to its route.
     */
    public function test_a_gateway_key_absent_from_the_default_list_is_not_offered_at_checkout(): void
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

        $this->assertNotContains($stub, $offered, "'{$stub}' is outside the default list, so the filter should have dropped it.");
        $this->assertContains($offerable, $offered, "'{$offerable}' is routed and in the default list, so the filter has not simply emptied the list.");
    }

    /**
     * Every gateway a customer is actually offered reaches a payment page this application registers.
     *
     * The structural sibling reads the default list out of its source file; this one switches every
     * default gateway on, asks payment_gateways() what it would offer a customer, and follows each
     * answer through the route map to the registered routes. A stub with no route and no controller,
     * added to both default lists so that it appears in the admin panel, fails here — which is the
     * regression the checkout filter alone cannot see, because payment_gateways() never reads routes.
     */
    public function test_every_gateway_offered_at_checkout_reaches_a_route_the_application_registers(): void
    {
        $this->buildGatewaySettingsTable();

        foreach (PaymentGatewayRegistryReader::helpersDefaultGatewayKeys() as $key) {
            $this->switchOnGateway(key: $key);
        }

        config(['get_payment_publish_status' => 0]);

        $routeMap = PaymentGatewayRegistryReader::liveRouteMap();
        $registeredPaths = $this->registeredPaths();
        $offered = payment_gateways()->pluck('key_name')->all();

        $unreachable = [];
        foreach ($offered as $key) {
            if (!in_array($routeMap[$key] ?? '', $registeredPaths, true)) {
                $unreachable[] = $key;
            }
        }

        $this->assertNotSame([], $offered, 'Every default gateway was switched on, so payment_gateways() offering nothing means this test is measuring the wrong thing.');
        $this->assertSame(
            self::DEFAULT_GATEWAYS_WHOSE_ROUTE_IS_NOT_REGISTERED,
            $unreachable,
            'A gateway offered at checkout is sent by Payment::generate_link to a path routes/web/routes.php does not register, so the customer gets a 404 and an orphan payment_requests row is left behind. Register the path or correct the route map; if a pinned exception has been put right, remove it from DEFAULT_GATEWAYS_WHOSE_ROUTE_IS_NOT_REGISTERED.'
        );
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

    /**
     * The gateway settings table, with the columns the Setting model reads. It lives in the installed SQL
     * dump, not in a migration, so it is built here.
     *
     * Dropped first because on a real engine the table outlives the test and would otherwise carry
     * another suite's columns and rows — and guarded first because on a real engine the database is
     * whatever DB_DATABASE points at. addon_settings holds every gateway's encrypted credentials
     * (GatewayConfigRepository), so this refuses to run at all outside a throwaway test database.
     */
    private function buildGatewaySettingsTable(): void
    {
        $this->refuseUnlessTheDatabaseIsThrowaway();

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
