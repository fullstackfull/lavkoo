<?php

namespace Tests\Feature\Payments;

use App\Models\PaymentRoutingRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A routing rule that hides a gateway REFUSES the payment, through the routes a phone and a browser
 * really post to (D2-06).
 *
 * The guard itself was already on the controller. What had never been driven was the controller: every
 * assertion about it called `PaymentRoutingService::hides()` directly, and the lesson this repository
 * has already paid for twice is that a test of a helper is not a test of the caller -- the sibling
 * currency guard on this same controller was shipped broken for exactly that reason, and its test file
 * says so. So nothing here calls the service. Each case posts to `/api/v1/digital-payment` or
 * `/customer/web-payment-request` and reads `payment_requests`, because the one thing this guard must do
 * is stop a payment row being written for a gateway the rules have ruled out.
 *
 * The amount and the destination are the point of enforcing it HERE rather than only in the lists: the
 * global config answer cannot judge an amount band or a country, because there is no cart and no
 * delivery address when it is asked. By the payment start there are both, and the cases below prove the
 * guard is judged with the order's own figures -- a band the order falls inside refuses it, the same band
 * with the order outside does not, and a country rule follows the address the order is being delivered
 * to rather than anything the request said.
 */
class ARoutingRuleRefusesThePaymentStartTest extends PaymentCurrencyTestCase
{
    /** The payment start, as the phone posts it. */
    private const APP_PAYMENT = '/api/v1/digital-payment';

    /** The payment start, as the website posts it. */
    private const WEB_PAYMENT = '/customer/web-payment-request';

    /** In the default gateway list, switched on below, and carrying no readiness check of its own. */
    private const GATEWAY = 'paytabs';

    /** The other switched-on gateway, so a rule naming one is seen to leave the other alone. */
    private const OTHER_GATEWAY = 'stripe';

    protected function setUp(): void
    {
        parent::setUp();

        $this->theGatewaysThePlatformOffers();
        $this->theRoutingRulesTable();
        // Single currency, so the amount the guard is handed is the order's own total and no conversion
        // stands between the rule's band and the figure it is compared with.
        $this->aSingleCurrencyStore();
    }

    /**
     * `addon_settings` is in the installed SQL dump rather than in a migration, so every suite that needs
     * it builds it -- the same shape the sibling routing test and the MyFatoorah support trait build.
     */
    private function theGatewaysThePlatformOffers(): void
    {
        Schema::dropIfExists('addon_settings');
        Schema::create('addon_settings', function ($table): void {
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

        foreach ([self::GATEWAY, self::OTHER_GATEWAY] as $key) {
            DB::table('addon_settings')->insert([
                'id' => (string) Str::uuid(),
                'key_name' => $key,
                'settings_type' => 'payment_config',
                'mode' => 'test',
                'is_active' => 1,
                'live_values' => json_encode(['status' => 1, 'gateway' => $key, 'mode' => 'live']),
                'test_values' => json_encode(['status' => 1, 'gateway' => $key, 'mode' => 'test']),
                'additional_data' => json_encode(['gateway_title' => $key]),
            ]);
        }
    }

    /** The real migrations, never a shape this test invented: the table, then the column that says which shop a rule governs. */
    private function theRoutingRulesTable(): void
    {
        Schema::dropIfExists('payment_routing_rules');
        (require database_path('migrations/2026_12_02_100000_create_payment_routing_rules_table.php'))->up();
        (require database_path('migrations/2026_12_31_100000_add_storefront_to_payment_routing_rules.php'))->up();
    }

    /** Six markets served, with the default one answering a request that names none. */
    private function storefrontsAreOn(): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.countries.SA.active' => true,
            'gcc.storefronts.countries.KW.active' => true,
        ]);
    }

    private function aRule(array $overrides = []): PaymentRoutingRule
    {
        // $overrides FIRST: PHP's array union keeps the left operand's value for a duplicate key, so
        // writing the defaults first would silently drop every override that names one of them.
        return PaymentRoutingRule::create($overrides + [
            'name' => 'test rule',
            'gateway_code' => self::GATEWAY,
            'action' => PaymentRoutingRule::ACTION_HIDE,
            'status' => PaymentRoutingRule::STATUS_ACTIVE,
            'priority' => 10,
        ]);
    }

    /** @return \Illuminate\Testing\TestResponse */
    private function startsAppPayment(string $gateway = self::GATEWAY, ?string $addressCountry = null)
    {
        $customer = $this->makeCustomer();
        $address = $this->makeLegacyAddress($customer->id, overrides: $addressCountry === null ? [] : ['country' => $addressCountry]);
        $this->aCartReadyToPay($customer->id);

        return $this->asCustomer($customer)->postJson(self::APP_PAYMENT, [
            'payment_method' => $gateway, 'payment_platform' => 'app', 'payment_request_from' => 'app',
            'customer_id' => $customer->id, 'is_guest' => 0, 'address_id' => $address->id,
        ]);
    }

    /** @return \Illuminate\Testing\TestResponse */
    private function startsWebPayment(string $gateway = self::GATEWAY)
    {
        $customer = $this->makeCustomer();
        $address = $this->makeLegacyAddress($customer->id);
        $this->aCartReadyToPay($customer->id);

        return $this->actingAs($customer, 'customer')->from('/')->post(self::WEB_PAYMENT, [
            'payment_method' => $gateway, 'payment_platform' => 'web', 'address_id' => $address->id,
        ]);
    }

    private function assertNoPaymentWasStarted(): void
    {
        $this->assertSame(0, $this->paymentRequests(), 'a payment row was written for a gateway the rules hide');
    }

    /** The browser's half of the refusal: the message Toastr leaves in the session, as the sibling guard reads it. */
    private function assertTheCustomerWasTold(): void
    {
        $this->assertContains(
            translate('this_payment_method_is_not_available_for_this_order'),
            array_column(session('toastr::messages') ?? [], 'message'),
            'the customer was sent back without being told why'
        );
    }

    // ---------------------------------------------------------------- the control

    public function test_with_no_rule_the_phone_starts_the_payment_exactly_as_before(): void
    {
        $this->startsAppPayment()->assertOk()->assertJsonStructure(['redirect_link']);

        // 100: a single-currency store charges the stored total in its own currency and converts nothing,
        // which is also the figure the amount bands below are compared with.
        $this->assertSame(['currency' => 'SAR', 'amount' => 100.0], $this->theCharge());
    }

    public function test_with_no_rule_the_website_starts_the_payment_exactly_as_before(): void
    {
        $this->startsWebPayment();

        $this->assertSame(1, $this->paymentRequests(), 'the payment start stopped writing its row');
    }

    // ---------------------------------------------------------------- the refusal

    /**
     * The phone is TOLD NO, in the vocabulary its sibling refusals already use. Before this it was handed
     * `{"redirect_link": "http://.../checkout-payment"}` with a 200: the refusal was decided inside
     * `getCustomerPaymentRequest()`, whose return value is the URL to hand the shopper to, so the only
     * way it could say no was to answer the URL of the website's checkout page -- which the app branch
     * cannot tell from a gateway's. The money was not taken, which is what this guard is for, but the app
     * was told the payment had started and would have opened the website in a webview.
     */
    public function test_a_rule_hiding_the_gateway_refuses_the_phone_with_a_reason_and_no_payment_row(): void
    {
        $this->aRule();

        $this->startsAppPayment()
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'payment-method')
            ->assertJsonPath('message', translate('this_payment_method_is_not_available_for_this_order'));

        $this->assertNoPaymentWasStarted();
    }

    public function test_a_rule_hiding_the_gateway_sends_the_browser_back_with_a_message_and_no_payment_row(): void
    {
        $this->aRule();

        $this->startsWebPayment()->assertRedirect(route('checkout-payment'));

        $this->assertTheCustomerWasTold();
        $this->assertNoPaymentWasStarted();
    }

    public function test_a_rule_for_one_gateway_leaves_the_other_one_payable(): void
    {
        $this->aRule();

        $this->startsAppPayment(self::OTHER_GATEWAY)->assertOk();

        $this->assertSame(1, $this->paymentRequests(), 'a rule naming one gateway stopped a payment through another');
    }

    public function test_an_inactive_rule_refuses_nothing_at_the_payment_start(): void
    {
        $this->aRule(['status' => 'inactive']);

        $this->startsAppPayment()->assertOk();

        $this->assertSame(1, $this->paymentRequests());
    }

    public function test_a_prefer_rule_never_refuses_a_payment(): void
    {
        $this->aRule(['action' => PaymentRoutingRule::ACTION_PREFER]);

        $this->startsAppPayment()->assertOk();

        $this->assertSame(1, $this->paymentRequests());
    }

    // ---------------------------------------------------------------- judged with the order in hand

    /**
     * The order is charged as 100, so a band ending at 500 covers it and a band starting at 500 does not.
     * This is the half of the feature the config answer cannot do: it is asked before there is a cart, so
     * it leaves every amount-bound rule alone.
     */
    public function test_an_amount_bound_rule_the_order_falls_inside_refuses_the_payment(): void
    {
        $this->aRule(['max_amount' => 500]);

        $this->startsAppPayment();

        $this->assertNoPaymentWasStarted();
    }

    public function test_an_amount_bound_rule_the_order_falls_outside_lets_the_payment_through(): void
    {
        $this->aRule(['min_amount' => 500]);

        $this->startsAppPayment()->assertOk();

        $this->assertSame(1, $this->paymentRequests(), 'a rule whose band the order is outside refused the payment');
    }

    /**
     * The destination is read from the address the order is being DELIVERED to, which is the only place
     * it can be read from: nothing the request sent says where the parcel goes.
     */
    public function test_a_country_bound_rule_follows_the_orders_delivery_address(): void
    {
        $this->aRule(['country' => 'Kuwait']);

        $this->startsAppPayment(addressCountry: 'KW');

        $this->assertNoPaymentWasStarted();
    }

    public function test_a_country_bound_rule_leaves_another_markets_order_alone(): void
    {
        $this->aRule(['country' => 'Kuwait']);

        $this->startsAppPayment(addressCountry: 'Saudi Arabia')->assertOk();

        $this->assertSame(1, $this->paymentRequests(), 'a Kuwaiti rule refused a Saudi order');
    }

    // ---------------------------------------------------------------- which storefront the rule governs

    /**
     * `country` is where the parcel goes; `storefront` is which shop the rule governs. On one market the
     * two always agreed; on six they are different rules, and until the column existed an operator could
     * only write the second one.
     *
     * Driven on the DEFAULT market, which is the Saudi baseline this platform must not move: a rule
     * naming the default storefront refuses a payment started there, and the same rule naming another
     * market does not.
     */
    public function test_a_rule_bound_to_this_storefront_refuses_a_payment_started_in_it(): void
    {
        $this->storefrontsAreOn();
        $this->aRule(['storefront' => 'SA']);

        $this->startsAppPayment();

        $this->assertNoPaymentWasStarted();
    }

    public function test_a_rule_bound_to_another_storefront_leaves_this_ones_payment_alone(): void
    {
        $this->storefrontsAreOn();
        $this->aRule(['storefront' => 'KW']);

        $this->startsAppPayment()->assertOk();

        $this->assertSame(1, $this->paymentRequests(), "a rule bound to another market refused this market's payment");
    }

    /**
     * A storefront nobody can recognise acts NOWHERE, which is the opposite of what the destination column
     * does with the same input -- and deliberately so. A destination is free text an address book wrote,
     * so an unrecognisable one keeps the text comparison it has always had: deciding it matches nothing
     * would switch off a rule somebody wrote. A storefront is chosen from the platform's own list of
     * markets (the admin form validates it with `Rule::in`), so a value that is not one of them is not a
     * market at all, and a rule for no market must not act in every market.
     */
    public function test_a_rule_naming_a_storefront_nobody_can_recognise_acts_nowhere(): void
    {
        $this->storefrontsAreOn();
        $this->aRule(['storefront' => 'Narnia']);

        $this->startsAppPayment()->assertOk();

        $this->assertSame(1, $this->paymentRequests(), 'a rule naming no real market refused a payment');
    }

    /**
     * While storefronts are off -- which is every installation until that decision is taken -- no shop is
     * being served, so a rule bound to one acts nowhere. A rule bound to nothing still acts everywhere,
     * which is the whole of the dormancy claim: nothing an operator has already written changes.
     */
    public function test_a_storefront_bound_rule_acts_nowhere_while_storefronts_are_off(): void
    {
        $this->aRule(['storefront' => 'SA']);

        $this->startsAppPayment()->assertOk();

        $this->assertSame(1, $this->paymentRequests(), 'a storefront-bound rule acted with storefronts switched off');
    }

    public function test_a_rule_naming_no_storefront_still_refuses_in_every_market(): void
    {
        $this->storefrontsAreOn();
        $this->aRule();

        $this->startsAppPayment();

        $this->assertNoPaymentWasStarted();
    }

    // ---------------------------------------------------------------- before the migration has run

    public function test_nothing_is_refused_and_nothing_throws_before_the_table_exists(): void
    {
        Schema::drop('payment_routing_rules');

        $this->startsAppPayment()->assertOk();

        $this->assertSame(1, $this->paymentRequests());
    }
}
