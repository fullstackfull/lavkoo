<?php

namespace Tests\Unit\Payments;

use App\Enums\GlobalConstant;
use App\Services\Payments\PaymentGatewayCatalog;
use App\Utils\Helpers;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The three hand-maintained gateway lists agree with each other (D2-04).
 *
 * A gateway is described in three places that nothing compared: the start-route map, the default
 * list that governs both the web checkout and the mobile app's /api/v1/config, and the supported
 * currency map. Drift between them is invisible until a customer picks a method that cannot take
 * their money, or follows a payment link that goes nowhere.
 *
 * Every figure below was measured against the shipped maps, not chosen. When one of these fails,
 * the right response is to read what moved -- not to update the number.
 */
class PaymentGatewayCatalogTest extends TestCase
{
    /** The six markets of the GCC plan, in the order the plan lists them. */
    private const GULF_CURRENCIES = ['SAR', 'AED', 'KWD', 'QAR', 'BHD', 'OMR'];

    public function test_no_gateway_key_is_written_twice_in_the_start_route_map(): void
    {
        // A PHP array literal silently keeps the LAST of two identical keys, so a duplicate cannot be
        // seen from the built array. It has to be counted in the source.
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/Payments/PaymentGatewayCatalog.php');
        preg_match_all("/^\s*'([a-z0-9_]+)' => 'payment\//m", $source, $matches);

        $written = $matches[1];
        $counts = array_count_values($written);
        $repeated = array_keys(array_filter($counts, static fn (int $times): bool => $times > 1));

        $this->assertSame([], $repeated, 'a gateway key is written twice in the start-route map, and the second silently wins');
        $this->assertSame(count($written), count(PaymentGatewayCatalog::startRoutes()), 'the built map has fewer entries than the source writes, which means a key was overwritten');
    }

    public function test_no_two_gateways_share_one_start_route(): void
    {
        $routes = PaymentGatewayCatalog::canonicalStartRoutes();
        $byRoute = [];
        foreach ($routes as $key => $route) {
            $byRoute[$route][] = $key;
        }
        $shared = array_filter($byRoute, static fn (array $keys): bool => count($keys) > 1);

        $this->assertSame(
            [],
            $shared,
            'two gateways start at one route. If one is an older spelling of the other it belongs in PaymentGatewayCatalog::ALIASES, '
            . 'where it is declared rather than discovered; if it is a genuine second gateway on one controller, that is the duplicate '
            . 'this requirement exists to refuse.'
        );
    }

    public function test_every_alias_names_a_gateway_that_exists_and_is_not_itself_offered(): void
    {
        $routes = PaymentGatewayCatalog::startRoutes();
        $default = PaymentGatewayCatalog::defaultKeys();
        $currencies = (new PaymentGatewayCatalog())->declaredCurrencies();

        foreach (PaymentGatewayCatalog::ALIASES as $alias => $gateway) {
            $this->assertArrayHasKey($alias, $routes, "the alias {$alias} has no start route, so nothing can reach it and it should be deleted rather than declared");
            $this->assertArrayHasKey($gateway, $routes, "the alias {$alias} names {$gateway}, which has no start route of its own");
            $this->assertSame($routes[$gateway], $routes[$alias], "the alias {$alias} does not start at the same route as {$gateway}, so it is not an alias");
            $this->assertNotContains($alias, $default, "the alias {$alias} has an entry of its own in the default list, so it is offered as a second gateway");
            $this->assertArrayNotHasKey($alias, $currencies, "the alias {$alias} has a currency entry of its own, which will drift from {$gateway}'s");
        }
    }

    public function test_every_offered_gateway_has_a_start_route_and_declares_its_currencies(): void
    {
        $routes = PaymentGatewayCatalog::startRoutes();
        $currencies = (new PaymentGatewayCatalog())->declaredCurrencies();

        foreach (PaymentGatewayCatalog::defaultKeys() as $key) {
            $this->assertArrayHasKey($key, $routes, "{$key} is offered to customers but has no start route, so picking it goes nowhere");
            $this->assertArrayHasKey($key, $currencies, "{$key} is offered to customers but declares no currency, so nothing can say which markets it serves");
            $this->assertNotSame([], $currencies[$key], "{$key} declares an empty currency list");
        }
    }

    public function test_the_keys_that_are_in_one_map_and_not_another_are_exactly_the_ones_already_known(): void
    {
        $routeKeys = array_keys(PaymentGatewayCatalog::canonicalStartRoutes());
        $currencyKeys = array_keys((new PaymentGatewayCatalog())->declaredCurrencies());

        $routedWithNoCurrency = array_values(array_diff($routeKeys, $currencyKeys));
        $currencyWithNoRoute = array_values(array_diff($currencyKeys, $routeKeys));

        // Measured on the shipped maps. Neither is in the default list, so neither is offered today,
        // and neither serves a GCC market -- but a THIRD one appearing is drift that wants reading.
        $this->assertSame(['mercadopago_pix'], $routedWithNoCurrency, 'a gateway that can be reached declares no currency');
        $this->assertSame(['cinetpay'], $currencyWithNoRoute, 'a gateway declares currencies but cannot be reached');
    }

    /**
     * The spellings of a gateway are declared in ONE place.
     *
     * `GatewayCurrencyPolicy` kept its own copy of the same pair, which is how the currency check and the start-route map could have
     * come to disagree about which gateway a key names -- the drift this class exists to prevent, inside the very check that refuses a
     * payment. Read from the source rather than from the classes, because what is asserted is that no second copy exists, and a second
     * copy cannot be asked whether it exists.
     */
    public function test_only_one_file_declares_what_a_gateway_is_called(): void
    {
        // This test has no application behind it, so the root is found from this file rather than from base_path().
        $root = dirname(__DIR__, 3);
        $declaring = [];

        foreach (['app', 'Modules'] as $tree) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $tree)) as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                if (preg_match('/[\'"]my_fatoorah[\'"]\s*=>\s*[\'"]fatoorah[\'"]/', (string) file_get_contents($file->getPathname())) === 1) {
                    $declaring[] = str_replace($root . '/', '', $file->getPathname());
                }
            }
        }

        sort($declaring);

        $this->assertSame(
            ['app/Services/Payments/PaymentGatewayCatalog.php'],
            $declaring,
            'the alias map has one home, and every comparison goes through PaymentGatewayCatalog::canonicalKey()',
        );
    }

    public function test_which_offered_gateways_declare_each_of_the_six_gulf_currencies(): void
    {
        $catalog = new PaymentGatewayCatalog();

        $declaredBy = [];
        foreach (self::GULF_CURRENCIES as $currency) {
            foreach ($catalog->defaultKeys() as $key) {
                if ($catalog->declaresCurrency($key, $currency)) {
                    $declaredBy[$currency][] = $key;
                }
            }
            $declaredBy[$currency] = $declaredBy[$currency] ?? [];
        }

        // Measured, not chosen. This is the six-country plan's payment coverage as the code declares
        // it today: PayTabs is the only offered gateway that declares all six, the two gateways this
        // project actually integrates declare two each, and QAR, BHD and OMR are served by PayTabs
        // alone. A change here means a gateway's declared reach moved and the plan wants re-reading.
        $this->assertSame(['stripe', 'razor_pay', 'paymob_accept', 'paytabs', 'fatoorah'], $declaredBy['SAR']);
        $this->assertSame(['stripe', 'razor_pay', 'paymob_accept', 'paytabs'], $declaredBy['AED']);
        $this->assertSame(['paytabs', 'fatoorah'], $declaredBy['KWD']);
        $this->assertSame(['stripe', 'razor_pay', 'paytabs'], $declaredBy['QAR']);
        $this->assertSame(['paytabs'], $declaredBy['BHD']);
        $this->assertSame(['paytabs'], $declaredBy['OMR']);
    }

    public function test_an_older_spelling_resolves_to_the_gateway_it_names(): void
    {
        $this->assertSame('fatoorah', PaymentGatewayCatalog::canonicalKey('my_fatoorah'));
        $this->assertSame('fatoorah', PaymentGatewayCatalog::canonicalKey('fatoorah'));
        $this->assertSame('paymob_accept', PaymentGatewayCatalog::canonicalKey('paymob_accept'));
        $this->assertTrue(PaymentGatewayCatalog::isAlias('my_fatoorah'));
        $this->assertFalse(PaymentGatewayCatalog::isAlias('fatoorah'));

        // The alias answers the currency question as the gateway it is, rather than as nothing.
        $catalog = new PaymentGatewayCatalog();
        $this->assertTrue($catalog->declaresCurrency('my_fatoorah', 'KWD'));
        $this->assertTrue($catalog->declaresCurrency('my_fatoorah', 'sar'), 'the currency code is compared case-insensitively');
        $this->assertFalse($catalog->declaresCurrency('my_fatoorah', 'BHD'));
    }

    public function test_the_start_route_of_every_key_is_unchanged_from_what_the_link_builder_used(): void
    {
        $routes = PaymentGatewayCatalog::startRoutes();

        // A payment link already in a customer's inbox names one of these paths. Spot-checked against
        // the map as it stood inside Payment::generate_link() before it was lifted out.
        $this->assertSame('payment/paymob/pay', $routes['paymob_accept']);
        $this->assertSame('payment/myfatoorah/pay', $routes['fatoorah']);
        $this->assertSame('payment/myfatoorah/pay', $routes['my_fatoorah']);
        $this->assertSame('payment/sslcommerz/pay', $routes['ssl_commerz']);
        $this->assertSame('payment/bkash/make-payment', $routes['bkash']);
        $this->assertSame('payment/razor-pay/pay', $routes['razor_pay']);
        $this->assertSame('payment/mercadopago_pix/pay', $routes['mercadopago_pix']);
        $this->assertCount(40, $routes, 'the start-route map gained or lost a key');
    }
    public function test_the_two_accessors_of_the_offered_list_cannot_disagree(): void
    {
        // The allowlist used to be written out twice: GlobalConstant::DEFAULT_PAYMENT_GATEWAYS, read by
        // AppServiceProvider and by every admin gateway and currency screen, and a literal copy inside
        // Helpers::getDefaultPaymentGateways(), read by payment_gateways() -- which governs the web
        // checkout AND the mobile app's /api/v1/config. The copies agreed, but nothing made them, so a
        // gateway added to one would have been offered on one surface and hidden on the other.
        $this->assertSame(
            GlobalConstant::DEFAULT_PAYMENT_GATEWAYS,
            Helpers::getDefaultPaymentGateways(),
            'the offered gateway list is written out twice again, and the two copies can now drift'
        );
        $this->assertSame(GlobalConstant::DEFAULT_PAYMENT_GATEWAYS, PaymentGatewayCatalog::defaultKeys());
        $this->assertCount(16, PaymentGatewayCatalog::defaultKeys());
    }

    public function test_the_gateway_form_lists_are_not_mistaken_for_capability_maps(): void
    {
        /*
         * GATEWAYS_CURRENCIES, GATEWAYS_COUNTRIES and GATEWAYS_LANGUAGES are named as though they said
         * which currencies, countries and languages each GATEWAY supports. They do not: each is a flat
         * list that fills a dropdown on the gateway admin form. Reading one as a capability map gives a
         * confident wrong answer about which markets a gateway serves -- which is exactly the question
         * the six-country plan asks. This test states the shape so the mistake cannot be made twice.
         */
        foreach (['GATEWAYS_CURRENCIES' => GlobalConstant::GATEWAYS_CURRENCIES,
                  'GATEWAYS_COUNTRIES' => GlobalConstant::GATEWAYS_COUNTRIES,
                  'GATEWAYS_LANGUAGES' => GlobalConstant::GATEWAYS_LANGUAGES] as $name => $list) {
            $this->assertSame(range(0, count($list) - 1), array_keys($list), "{$name} is keyed by position, so it is a list and not a map of gateway => capability");
            foreach (PaymentGatewayCatalog::defaultKeys() as $gateway) {
                $this->assertArrayNotHasKey($gateway, $list, "{$name} has an entry keyed by the gateway {$gateway}, so it may now be a capability map and this test wants rewriting");
            }
        }

        // And so the gap is stated rather than implied: no map anywhere says which COUNTRIES a gateway
        // is confirmed for. The currency map is the whole of the capability data this codebase holds.
        $this->assertCount(243, GlobalConstant::GATEWAYS_COUNTRIES, 'the country list is the ISO country list, not a gateway map');
        $this->assertCount(243, GlobalConstant::COUNTRIES);
    }
}
