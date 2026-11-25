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

    /** The real migration, never a shape this test invented. */
    private function theRoutingRulesTable(): void
    {
        Schema::dropIfExists('payment_routing_rules');
        (require database_path('migrations/2026_12_02_100000_create_payment_routing_rules_table.php'))->up();
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

    // ---------------------------------------------------------------- before the migration has run

    public function test_nothing_is_refused_and_nothing_throws_before_the_table_exists(): void
    {
        Schema::drop('payment_routing_rules');

        $this->startsAppPayment()->assertOk();

        $this->assertSame(1, $this->paymentRequests());
    }
}
