<?php

namespace Tests\Feature\Payments;

use App\Models\PaymentRoutingRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsApiConfigWorld;
use Tests\Support\BuildsStorefrontCatalog;

/**
 * J-09.4 and D2-06: GET /api/v1/config tells the customer app which payment methods the STOREFRONT it asked for may offer, beside the
 * global list and never in place of it.
 *
 * `payment_methods` is the global list and cannot answer this. It applies only routing rules that name neither an amount band nor a
 * country, because the config answer is asked before there is a cart, an amount or a delivery address, and hiding a method on a maybe
 * would hide a method the customer is entitled to. So the app was told about methods a market may not actually offer, and had nothing to
 * filter them with: a gateway an operator had ruled out for Kuwait was still on every Kuwaiti phone, and a gateway that cannot take
 * Kuwaiti dinars at all was offered right up to the moment the payment was refused.
 *
 * `storefront_payment_methods` answers the part a market DOES settle: the app names its storefront, so a hide rule bound to that country
 * can be judged, and the currency is known, so the same question `GatewayCurrencyPolicy` asks at the start of a payment can be asked
 * before the method is ever shown. A rule bound to an amount band still cannot be judged and is still left alone.
 *
 * The first test is the dormancy claim: with nothing configured the two keys are the same list, and `payment_methods` never moves in any
 * test here.
 */
class StorefrontPaymentMethodsOnTheConfigAnswerTest extends ApiAddressTestCase
{
    use BuildsApiConfigWorld;
    use BuildsStorefrontCatalog;

    private const CONFIG = '/api/v1/config';

    /** @var list<string> */
    private const GATEWAYS = ['ssl_commerz', 'paytabs', 'stripe', 'paymob_accept'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildApiConfigWorld();
        Schema::dropIfExists('payment_routing_rules');
        (require database_path('migrations/2026_12_02_100000_create_payment_routing_rules_table.php'))->up();

        // Four gateways of the platform's own list, chosen for what their currency lists hold (App\Traits\PaymentGatewayTrait):
        // ssl_commerz holds no Gulf currency at all, paytabs holds the dinar of Kuwait and the dirham, stripe and paymob_accept hold the
        // dirham and not the dinar. Inserted in this order, which is the order the gateway list answers in.
        foreach (self::GATEWAYS as $gateway) {
            $this->switchAGatewayOn($gateway);
        }

        foreach (['AED' => 3.6725, 'KWD' => 0.307] as $code => $rate) {
            DB::table('currencies')->insert(['name' => $code, 'symbol' => $code, 'code' => $code, 'exchange_rate' => $rate, 'status' => 1]);
        }

        // The three ways to pay, which the controller reads before it reaches the gateway list.
        foreach (['cash_on_delivery', 'digital_payment', 'offline_payment'] as $method) {
            DB::table('business_settings')->updateOrInsert(['type' => $method], ['value' => '{"status":"1"}']);
        }

        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.countries.AE.active' => true,
            'gcc.storefronts.countries.KW.active' => true,
            'gcc.storefronts.currency.verified_three_decimal_gateways' => [],
        ]);
        clearWebConfigCacheKeys();

        // StorefrontCatalog resolves the storefront of an UNBOUND request -- which is what this route has, since it binds no storefront
        // context -- and it refuses to do so while the application thinks it is running in the console. A PHPUnit process thinks exactly
        // that, so without this the whole test would read as the default market and every case below would pass for the wrong reason.
        $this->serveAsHttpRequest(self::CONFIG);
    }

    /** @return array<string, mixed> the whole answer, as the app reads it */
    private function configOf(?string $country): array
    {
        $response = $country === null ? $this->getJson(self::CONFIG) : $this->getJson(self::CONFIG, ['X-Storefront' => $country]);

        return json_decode($response->assertOk()->getContent(), true);
    }

    /** @return list<string> the gateways of one key of the answer */
    private function methodsOf(?string $country, string $key = 'storefront_payment_methods'): array
    {
        return array_values(array_map(static fn (array $method): string => (string) $method['key_name'], $this->configOf($country)[$key] ?? []));
    }

    private function aHideRuleFor(string $gateway, ?string $country): void
    {
        PaymentRoutingRule::create([
            'name' => 'no ' . $gateway . ' in ' . ($country ?? 'any market'),
            'gateway_code' => $gateway,
            'action' => PaymentRoutingRule::ACTION_HIDE,
            'country' => $country,
            'priority' => 10,
            'status' => PaymentRoutingRule::STATUS_ACTIVE,
        ]);
    }

    public function test_with_nothing_configured_the_new_key_is_the_same_list_as_the_old_one(): void
    {
        $answer = $this->configOf(null);

        $this->assertArrayHasKey('payment_methods', $answer);
        $this->assertArrayHasKey('storefront_payment_methods', $answer, 'the app has nothing to read');
        $this->assertSame(self::GATEWAYS, $this->methodsOf(null, 'payment_methods'));
        $this->assertSame($answer['payment_methods'], $answer['storefront_payment_methods'], 'with no rule and no storefront currency the two lists are one list');
    }

    public function test_a_rule_that_names_kuwait_takes_the_gateway_off_the_kuwaiti_list_and_leaves_saudi_alone(): void
    {
        $this->aHideRuleFor(gateway: 'paytabs', country: 'KW');

        $this->assertSame(['ssl_commerz', 'stripe', 'paymob_accept'], $this->methodsOf('KW'), 'the Kuwaiti storefront is not offered a gateway its own rule hides');
        $this->assertSame(self::GATEWAYS, $this->methodsOf('SA'), 'and Saudi still lists it');
        $this->assertSame(self::GATEWAYS, $this->methodsOf('KW', 'payment_methods'), 'the global key is what it always was, for every market');
    }

    public function test_a_rule_that_names_no_country_takes_the_gateway_off_both_lists_as_it_always_did(): void
    {
        $this->aHideRuleFor(gateway: 'paytabs', country: null);

        $this->assertSame(['ssl_commerz', 'stripe', 'paymob_accept'], $this->methodsOf('KW'));
        $this->assertSame(['ssl_commerz', 'stripe', 'paymob_accept'], $this->methodsOf('KW', 'payment_methods'), 'an everywhere rule was always applied to the global list');
    }

    public function test_a_rule_bound_to_an_amount_band_is_left_alone_because_there_is_no_cart_yet(): void
    {
        PaymentRoutingRule::create([
            'name' => 'no paytabs over 500 in Kuwait', 'gateway_code' => 'paytabs', 'action' => PaymentRoutingRule::ACTION_HIDE,
            'country' => 'KW', 'min_amount' => 500, 'priority' => 10, 'status' => PaymentRoutingRule::STATUS_ACTIVE,
        ]);

        $this->assertSame(
            self::GATEWAYS,
            $this->methodsOf('KW'),
            'the amount of the order is not known here, so hiding the method would hide one the customer may well be entitled to',
        );
    }

    public function test_a_market_trading_in_its_own_money_is_offered_only_gateways_that_can_take_it(): void
    {
        config(['gcc.storefronts.currency.enabled' => true]);

        $this->assertSame(
            [],
            $this->methodsOf('KW'),
            'the dinar has three decimals and no gateway has been verified for it, so the Kuwaiti storefront can offer none of these',
        );
        $this->assertSame(self::GATEWAYS, $this->methodsOf('KW', 'payment_methods'), 'and the global key still answers everything, for a client that reads only it');
        $this->assertSame(self::GATEWAYS, $this->methodsOf('SA'), 'the Saudi storefront trades in the platform currency and is judged by nothing new');
    }

    public function test_a_verified_gateway_is_the_one_a_three_decimal_market_is_offered(): void
    {
        config([
            'gcc.storefronts.currency.enabled' => true,
            'gcc.storefronts.currency.verified_three_decimal_gateways' => ['paytabs'],
        ]);

        $this->assertSame(['paytabs'], $this->methodsOf('KW'), 'the one gateway that both lists the dinar and has been verified for a third decimal');
    }

    public function test_a_two_decimal_market_is_offered_the_gateways_whose_list_holds_its_currency(): void
    {
        config(['gcc.storefronts.currency.enabled' => true]);

        $this->assertSame(
            ['paytabs', 'stripe', 'paymob_accept'],
            $this->methodsOf('AE'),
            'the dirham has two decimals, so what decides is whether the gateway lists it at all -- and ssl_commerz does not',
        );
    }
}
