<?php

namespace Tests\Feature\Payments;

use App\Enums\GlobalConstant;
use App\Http\Controllers\Customer\PaymentController;
use App\Models\User;
use App\Services\Payments\GatewayCurrencyDecision;
use App\Services\Payments\GatewayCurrencySupport;
use App\Services\Payments\PaymentGatewayCatalog;
use Illuminate\Support\Facades\DB;

/**
 * A gateway payment in a currency the gateway cannot take is REFUSED, not silently turned into a
 * different currency (D2-V1).
 *
 * Three places start a gateway payment, and all three resolved a currency the gateway would accept and
 * then CONVERTED the amount into it. Measured on this repository at the base of this branch
 * (222fe1eb), a customer browsing in Saudi Riyal who owed 375 SAR and chose SSLCommerz -- which takes
 * Bangladeshi Taka and nothing else -- was handed to the gateway for 11,000 BDT; with the Taka row
 * switched off, for 100 USD; and with Paystack, which takes six currencies and no Riyal, again for
 * 100 USD. The figures below are those measurements, not remembered numbers, and the switched-off
 * cases still assert them.
 *
 * Every refusal here is driven through the route the website or the phone really posts to, with the
 * request the middleware leaves, because the one thing this guard must do is run on the real path: the
 * routing guard on this same controller was shipped broken precisely because its tests called the
 * service instead of the route.
 */
class GatewayRefusesACurrencyItCannotTakeTest extends PaymentCurrencyTestCase
{
    /** The payment start, as the phone posts it. */
    private const APP_PAYMENT = '/api/v1/digital-payment';

    /** The payment start, as the website posts it. */
    private const WEB_PAYMENT = '/customer/web-payment-request';

    private const ADD_FUND = '/customer/customer-add-fund-request';

    private const EDIT_DUE_APP = '/api/v1/edit-order/due-payment-by-digital-payment';

    private const EDIT_DUE_WEB = '/customer/customer-order-edit-pay-amount';

    /** Takes Bangladeshi Taka and nothing else: cannot take a Riyal, and has exactly one declared currency. */
    private const ONE_CURRENCY_GATEWAY = 'ssl_commerz';

    /** Takes six currencies, none of them a Riyal: the other side of the count the resolver branches on. */
    private const MANY_CURRENCY_GATEWAY = 'paystack';

    /** Declares Kuwaiti Dinar and Saudi Riyal. */
    private const GATEWAY_THAT_TAKES_RIYAL = 'fatoorah';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderEditTables();
    }

    // ---------------------------------------------------------------- the payment start

    /** @return \Illuminate\Testing\TestResponse */
    private function startsAppPayment(string $gateway, string $browsing, array $headers = [])
    {
        $customer = $this->makeCustomer();
        $address = $this->makeLegacyAddress($customer->id);
        $this->aCartReadyToPay($customer->id);

        return $this->asCustomer($customer)->withHeaders($headers)->postJson(self::APP_PAYMENT, [
            'payment_method' => $gateway, 'payment_platform' => 'app', 'payment_request_from' => 'app',
            'customer_id' => $customer->id, 'is_guest' => 0, 'address_id' => $address->id,
            'current_currency_code' => $browsing,
        ]);
    }

    public function test_the_payment_start_refuses_the_phone_and_names_the_currency_it_cannot_take(): void
    {
        $this->aMultiCurrencyStore();
        $this->switchTheRefusalOn();

        $response = $this->startsAppPayment(self::ONE_CURRENCY_GATEWAY, 'SAR');

        $response->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'payment-currency')
            ->assertJsonPath('message', translate('this_payment_method_cannot_take_payments_in_your_currency') . ' (SAR)');

        $this->assertNothingWasWritten();
        $this->assertSame(
            GatewayCurrencyDecision::NOT_DECLARED,
            app(GatewayCurrencySupport::class)->decide(gateway: self::ONE_CURRENCY_GATEWAY, currencyCode: 'SAR')->reason,
            'the refusal is recorded under the wrong reason'
        );
    }

    public function test_the_payment_start_refuses_the_website_with_a_message_and_the_payment_page(): void
    {
        $this->aMultiCurrencyStore();
        $this->switchTheRefusalOn();
        $customer = $this->makeCustomer();
        $address = $this->makeLegacyAddress($customer->id);
        $this->aCartReadyToPay($customer->id);

        $response = $this->actingAs($customer, 'customer')->post(self::WEB_PAYMENT, [
            'payment_method' => self::ONE_CURRENCY_GATEWAY, 'payment_platform' => 'web',
            'address_id' => $address->id, 'current_currency_code' => 'SAR',
        ]);

        $response->assertRedirect(route('checkout-payment'));
        $this->assertTheCustomerWasTold();
        $this->assertNothingWasWritten();
    }

    /**
     * The `lang` header is what decides, and that is what is measured.
     *
     * The first round set the store's default language to Arabic AND sent `lang: sa`, so the Arabic answer
     * it asserted was produced by the default alone and the test would have passed with the header
     * ignored entirely (found by Reviewer B, who measured exactly that). Here the store's default is
     * ENGLISH and Arabic is merely an enabled language, so only the header can produce Arabic -- and the
     * same request without the header must come back in English, or nothing has been shown.
     */
    public function test_the_refusal_reaches_the_phone_in_the_language_it_asked_for(): void
    {
        $this->aMultiCurrencyStore();
        $this->setSetting('language', [
            ['id' => 1, 'name' => 'English', 'code' => 'en', 'default' => 1, 'status' => 1],
            ['id' => 2, 'name' => 'Arabic', 'code' => 'sa', 'default' => 0, 'status' => 1],
        ]);
        $this->switchTheRefusalOn();

        $this->startsAppPayment(self::ONE_CURRENCY_GATEWAY, 'SAR', headers: ['lang' => 'sa'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'وسيلة الدفع هذه لا تقبل الدفع بعملتك (SAR)');
    }

    /**
     * The control for the test above, in a method of its own because the application's locale is set for
     * the process once a request has asked for one -- two requests in one test method cannot show this.
     * The same store, the same gateway, no `lang` header: English, so the header above is what did it.
     */
    public function test_the_same_refusal_without_the_header_comes_back_in_the_stores_own_language(): void
    {
        $this->aMultiCurrencyStore();
        $this->setSetting('language', [
            ['id' => 1, 'name' => 'English', 'code' => 'en', 'default' => 1, 'status' => 1],
            ['id' => 2, 'name' => 'Arabic', 'code' => 'sa', 'default' => 0, 'status' => 1],
        ]);
        $this->switchTheRefusalOn();

        $this->startsAppPayment(self::ONE_CURRENCY_GATEWAY, 'SAR')
            ->assertStatus(403)
            ->assertJsonPath('message', 'This payment method cannot take payments in your currency (SAR)');
    }

    /**
     * Every locale directory the repository tracks has the refusal's text, so no installation shows a
     * customer a humanised key -- and, just as important, no installation's first refusal makes
     * translate() REWRITE a tracked language file on the server to put the placeholder in (found by
     * Reviewer B, who measured 530 rewritten lines in resources/lang/bd/new-messages.php).
     */
    public function test_the_refusal_has_a_text_in_every_locale_the_repository_ships(): void
    {
        $this->aMultiCurrencyStore();
        $this->switchTheRefusalOn();

        foreach (['ae', 'bd', 'en', 'es', 'in', 'ks', 'sa'] as $locale) {
            $messages = require base_path("resources/lang/{$locale}/new-messages.php");
            $this->assertArrayHasKey(
                'this_payment_method_cannot_take_payments_in_your_currency',
                $messages,
                "{$locale} has no text for the refusal, so translate() will write one into the tracked file"
            );
        }

        $before = file_get_contents(base_path('resources/lang/bd/new-messages.php'));
        $this->startsAppPayment(self::ONE_CURRENCY_GATEWAY, 'SAR', headers: ['lang' => 'bd'])->assertStatus(403);
        $this->assertSame($before, file_get_contents(base_path('resources/lang/bd/new-messages.php')), 'a refusal rewrote a tracked language file');
    }

    public function test_a_gateway_that_can_take_the_customers_currency_goes_through_and_the_amount_is_the_amount(): void
    {
        $this->aMultiCurrencyStore();
        $this->switchTheRefusalOn();

        $this->startsAppPayment(self::GATEWAY_THAT_TAKES_RIYAL, 'SAR')->assertOk()->assertJsonStructure(['redirect_link']);

        $this->assertSame(['currency' => 'SAR', 'amount' => 375.0], $this->theCharge());
    }

    public function test_a_gateway_with_several_declared_currencies_and_none_of_them_the_customers_is_refused_too(): void
    {
        $this->aMultiCurrencyStore();
        $this->switchTheRefusalOn();

        $this->startsAppPayment(self::MANY_CURRENCY_GATEWAY, 'SAR')->assertStatus(403);

        $this->assertNothingWasWritten();
    }

    // ---------------------------------------------------------------- the wallet top-up

    /** @return \Illuminate\Testing\TestResponse */
    private function topsUpTheWallet(string $gateway, string $browsing, ?User $customer = null)
    {
        $this->walletTopUpIsOpen();
        $customer ??= $this->makeCustomer();

        return $this->asCustomer($customer)->postJson(self::ADD_FUND, [
            'amount' => 100, 'payment_method' => $gateway, 'payment_platform' => 'app',
            'payment_request_from' => 'app', 'current_currency_code' => $browsing,
        ]);
    }

    public function test_the_wallet_top_up_refuses_a_gateway_that_cannot_take_the_customers_currency(): void
    {
        $this->aMultiCurrencyStore();
        $this->switchTheRefusalOn();
        $customer = $this->makeCustomer();

        $this->topsUpTheWallet(self::ONE_CURRENCY_GATEWAY, 'SAR', customer: $customer)
            ->assertStatus(403)
            ->assertJsonPath('message', translate('this_payment_method_cannot_take_payments_in_your_currency') . ' (SAR)');

        $this->assertNothingWasWritten();
        $this->assertSame(0.0, (float) $customer->fresh()->wallet_balance, 'the refused top-up moved the wallet');
    }

    public function test_the_websites_wallet_top_up_is_refused_with_a_message_and_the_page_it_came_from(): void
    {
        $this->aMultiCurrencyStore();
        $this->walletTopUpIsOpen();
        $this->switchTheRefusalOn();
        $customer = $this->makeCustomer();

        $response = $this->actingAs($customer, 'customer')->from('/')->post(self::ADD_FUND, [
            'amount' => 100, 'payment_method' => self::ONE_CURRENCY_GATEWAY, 'payment_platform' => 'web',
            'current_currency_code' => 'SAR',
        ]);

        $response->assertRedirect('/');
        $this->assertTheCustomerWasTold();
        $this->assertNothingWasWritten();
        $this->assertSame(0.0, (float) $customer->fresh()->wallet_balance);
    }

    public function test_the_wallet_top_up_still_goes_through_for_a_gateway_that_can(): void
    {
        $this->aMultiCurrencyStore();
        $this->switchTheRefusalOn();

        $this->topsUpTheWallet(self::GATEWAY_THAT_TAKES_RIYAL, 'SAR')->assertOk()->assertJsonStructure(['redirect_link']);

        $this->assertSame(['currency' => 'SAR', 'amount' => 375.0], $this->theCharge());
    }

    // ---------------------------------------------------------------- an order edit's due amount

    /** @return \Illuminate\Testing\TestResponse */
    private function paysTheDueAmount(string $gateway, string $browsing)
    {
        $customer = $this->makeCustomer();
        $order = $this->anOrderWithADueAmount($customer->id);

        return $this->asCustomer($customer)->postJson(self::EDIT_DUE_APP, [
            'order_id' => $order->id, 'payment_method' => $gateway, 'payment_platform' => 'app',
            'payment_request_from' => 'app', 'current_currency_code' => $browsing,
        ]);
    }

    public function test_an_order_edits_due_payment_refuses_a_gateway_that_cannot_take_the_customers_currency(): void
    {
        $this->aMultiCurrencyStore();
        $this->switchTheRefusalOn();

        // 403, NOT the 401 this route gives a dead session: the customer app clears the login token on any
        // 401, so the first round's refusal signed the customer out mid-payment (found by both reviewers).
        $this->paysTheDueAmount(self::ONE_CURRENCY_GATEWAY, 'SAR')
            ->assertStatus(403)
            ->assertJsonPath('message', translate('this_payment_method_cannot_take_payments_in_your_currency') . ' (SAR)');

        $this->assertNothingWasWritten(ordersTheTestMade: 1);
        $this->assertNull(
            DB::table('order_edit_histories')->value('order_due_payment_method'),
            'the refused due payment was written onto the edit history anyway'
        );
    }

    public function test_an_order_edits_due_payment_still_goes_through_for_a_gateway_that_can(): void
    {
        $this->aMultiCurrencyStore();
        $this->switchTheRefusalOn();

        $this->paysTheDueAmount(self::GATEWAY_THAT_TAKES_RIYAL, 'SAR')->assertOk()->assertJsonStructure(['redirect_link']);

        $this->assertSame(['currency' => 'SAR', 'amount' => 150.0], $this->theCharge());
    }

    /**
     * The website's own due-payment form. Its answer is a message and the page the customer came from --
     * no exception, no error page -- and that is what is checked: the controller reads `redirect_link`
     * off the answer without guarding it, so a refusal that left the key out would end in a 500.
     */
    public function test_the_websites_due_payment_form_is_refused_without_an_error_page(): void
    {
        $this->aMultiCurrencyStore();
        $this->switchTheRefusalOn();
        $customer = $this->makeCustomer();
        $order = $this->anOrderWithADueAmount($customer->id);

        $response = $this->actingAs($customer, 'customer')->from('/')->post(self::EDIT_DUE_WEB, [
            'order_id' => $order->id, 'payment_method' => self::ONE_CURRENCY_GATEWAY,
            'payment_platform' => 'web', 'current_currency_code' => 'SAR',
        ]);

        $response->assertRedirect('/');
        $this->assertTheCustomerWasTold();
        $this->assertNothingWasWritten(ordersTheTestMade: 1);
    }

    // ---------------------------------------------------------------- the switch off: today, unchanged

    /**
     * Every figure here was measured on a worktree at this branch's base, 222fe1eb, through these same
     * routes. With the switch in its shipped state the three callers must still produce them -- the
     * substitution into another currency and the fall-through to USD included.
     */
    public function test_with_the_switch_off_all_three_callers_convert_exactly_as_they_do_today(): void
    {
        $this->aMultiCurrencyStore();
        $this->assertFalse(app(GatewayCurrencySupport::class)->enabled(), 'the refusal is not off in the shipped state');

        $this->startsAppPayment(self::ONE_CURRENCY_GATEWAY, 'SAR')->assertOk();
        $this->assertSame(['currency' => 'BDT', 'amount' => 11000.0], $this->theCharge(), 'the payment start no longer converts as it did');

        DB::table('payment_requests')->delete();
        $this->topsUpTheWallet(self::ONE_CURRENCY_GATEWAY, 'SAR')->assertOk();
        $this->assertSame(['currency' => 'BDT', 'amount' => 11000.0], $this->theCharge(), 'the wallet top-up no longer converts as it did');

        DB::table('payment_requests')->delete();
        $this->paysTheDueAmount(self::ONE_CURRENCY_GATEWAY, 'SAR')->assertOk();
        $this->assertSame(['currency' => 'BDT', 'amount' => 4400.0], $this->theCharge(), "an order edit's due payment no longer converts as it did");
    }

    public function test_with_the_switch_off_the_fall_through_to_usd_is_still_exactly_what_it_was(): void
    {
        // The Taka row switched off, so nothing the gateway declares can be priced: today's answer is USD.
        $this->aMultiCurrencyStore(activeCodes: ['SAR', 'USD']);

        $this->startsAppPayment(self::ONE_CURRENCY_GATEWAY, 'SAR')->assertOk();
        $this->assertSame(['currency' => 'USD', 'amount' => 100.0], $this->theCharge());

        DB::table('payment_requests')->delete();
        $this->startsAppPayment(self::MANY_CURRENCY_GATEWAY, 'SAR')->assertOk();
        $this->assertSame(['currency' => 'USD', 'amount' => 100.0], $this->theCharge());
    }

    public function test_the_switch_is_off_until_an_operator_sets_it_and_the_migration_leaves_it_off(): void
    {
        $this->aMultiCurrencyStore();
        $support = app(GatewayCurrencySupport::class);

        DB::table('business_settings')->where('type', GatewayCurrencySupport::SETTING)->delete();
        $this->setSetting('unrelated_setting', 1);
        $this->assertFalse($support->enabled(), 'with no row at all the refusal is on');

        (require database_path('migrations/2026_12_02_110000_register_gateway_currency_refusal_setting.php'))->up();
        $this->setSetting('unrelated_setting', 2);
        $this->assertSame('0', (string) DB::table('business_settings')->where('type', GatewayCurrencySupport::SETTING)->value('value'));
        $this->assertFalse($support->enabled(), 'the migration switched the refusal on');

        $this->switchTheRefusalOn();
        $this->assertTrue($support->enabled());
    }

    /**
     * A settings row holding something that is not a flag at all. Every other setting on this table is
     * read as a string or an array depending on what somebody saved, so a switch that cast whatever it
     * found would take the payment start down with a type error on every request rather than stay off.
     */
    public function test_a_switch_whose_value_is_not_a_flag_leaves_the_refusal_off(): void
    {
        $this->aMultiCurrencyStore();

        foreach ([['on' => true], '', 'yes', 0, null, [1]] as $nonsense) {
            $this->setSetting(GatewayCurrencySupport::SETTING, $nonsense);
            $this->assertFalse(app(GatewayCurrencySupport::class)->enabled(), 'a value that is not the flag switched the refusal on');
        }

        $this->startsAppPayment(self::ONE_CURRENCY_GATEWAY, 'SAR')->assertOk();
        $this->assertSame(['currency' => 'BDT', 'amount' => 11000.0], $this->theCharge());
    }

    public function test_the_migration_never_overwrites_a_switch_an_operator_has_already_set(): void
    {
        $this->aMultiCurrencyStore();
        $this->switchTheRefusalOn();

        (require database_path('migrations/2026_12_02_110000_register_gateway_currency_refusal_setting.php'))->up();
        $this->setSetting('unrelated_setting', 3);

        $this->assertTrue(app(GatewayCurrencySupport::class)->enabled(), 'the migration turned an operator\'s switch back off');
        $this->assertSame(1, DB::table('business_settings')->where('type', GatewayCurrencySupport::SETTING)->count());
    }

    // ---------------------------------------------------------------- a single-currency installation

    /**
     * A store that prices in one currency converts nothing, so there is no substitution to refuse. With
     * the switch ON it must still charge the system default currency, at the amount it charges today.
     */
    public function test_a_single_currency_installation_is_untouched_even_with_the_switch_on(): void
    {
        $this->aSingleCurrencyStore();
        $this->switchTheRefusalOn();

        $this->startsAppPayment(self::ONE_CURRENCY_GATEWAY, 'SAR')->assertOk();
        $this->assertSame(['currency' => 'SAR', 'amount' => 100.0], $this->theCharge());

        DB::table('payment_requests')->delete();
        $this->topsUpTheWallet(self::ONE_CURRENCY_GATEWAY, 'SAR')->assertOk();
        $this->assertSame(['currency' => 'SAR', 'amount' => 100.0], $this->theCharge());

        DB::table('payment_requests')->delete();
        $this->paysTheDueAmount(self::ONE_CURRENCY_GATEWAY, 'SAR')->assertOk();
        $this->assertSame(['currency' => 'SAR', 'amount' => 40.0], $this->theCharge());
    }

    // ---------------------------------------------------------------- the boundary the fall-through hid

    /**
     * A currency the gateway DOES declare, whose currency row the store has switched off.
     *
     * Today that is the quietest case of all: the resolver's first branch wants the row active, the
     * remaining branches want an active row too, so nothing matches and the amount goes out labelled USD
     * -- computed from a rate the store had deliberately retired. It is refused here, for the same reason
     * as a currency the gateway never declared: there is no currency in which this charge can lawfully
     * go out, and an amount in one the customer did not choose is exactly what must not happen.
     */
    public function test_a_currency_the_gateway_declares_but_the_store_has_retired_is_refused(): void
    {
        $this->aMultiCurrencyStore(activeCodes: ['USD']);
        $this->switchTheRefusalOn();

        $decision = app(GatewayCurrencySupport::class)->decide(gateway: self::GATEWAY_THAT_TAKES_RIYAL, currencyCode: 'SAR');

        $this->assertTrue($decision->refused());
        $this->assertSame(GatewayCurrencyDecision::CURRENCY_INACTIVE, $decision->reason);

        $this->startsAppPayment(self::GATEWAY_THAT_TAKES_RIYAL, 'SAR')->assertStatus(403);
        $this->assertNothingWasWritten();
    }

    public function test_the_same_case_with_the_switch_off_still_becomes_usd_as_it_does_today(): void
    {
        $this->aMultiCurrencyStore(activeCodes: ['USD']);

        $this->startsAppPayment(self::GATEWAY_THAT_TAKES_RIYAL, 'SAR')->assertOk();

        $this->assertSame(['currency' => 'USD', 'amount' => 100.0], $this->theCharge());
    }

    // ---------------------------------------------------------------- one question, one answer

    /**
     * The question is asked in ONE place, and the proof is mechanical: for every gateway the platform
     * offers and every currency the store holds, the new answer allows a payment exactly when the legacy
     * resolver was going to charge in the customer's OWN currency -- with one family of exceptions, which
     * this names, counts and explains rather than averaging away.
     *
     * Every exception is a gateway that does not declare the US Dollar, asked about a customer browsing
     * in dollars, where the resolver ran out of branches: its `else if` chain tries the customer's own
     * currency, then a lone declared currency, then Paystack's pair, then any active declared one -- and
     * if the branch it entered fails inside, the chain ENDS and the closing `return 'USD'` names the
     * customer's own currency by accident. Handing dollars to a gateway that does not take dollars is
     * the substitution this refuses, so a disagreement there is the feature working. Anywhere else one
     * would mean two different rules about the same question, which is what must not exist.
     *
     * In this store that is four of the seven dollarless gateways; the other three reach a declared
     * currency that IS active (Taka, Riyal) and so never reach the fall-through, which is why the list
     * is asserted exactly as well as by its shape.
     *
     * SCOPE, corrected after review: this sweeps the sixteen gateways in the DEFAULT list, not "every
     * offered gateway". With the paid gateway add-on published, Helpers::payment_gateways() drops that
     * filter and offers every active payment_config row, so up to 40 keys are startable -- including
     * `mercadopago_pix`, which declares no currency at all. That key is covered by its own test below
     * rather than by this sweep (found by Reviewer B).
     */
    public function test_the_one_question_agrees_with_the_legacy_resolver_on_every_gateway_in_the_default_list(): void
    {
        $this->aMultiCurrencyStore(activeCodes: ['SAR', 'USD', 'BDT']);
        $this->switchTheRefusalOn();
        $support = app(GatewayCurrencySupport::class);
        $controller = app(PaymentController::class);
        $disagreements = [];
        $pairs = 0;

        foreach (GlobalConstant::DEFAULT_PAYMENT_GATEWAYS as $gateway) {
            foreach (['SAR', 'USD', 'BDT', 'KWD'] as $code) {
                $resolverChargesInTheirOwnCurrency = $controller->getPaymentGatewayCurrencyCode(key: $gateway, currentCurrency: $code) === $code;
                $allowed = !$support->decide(gateway: $gateway, currencyCode: $code)->refused();
                if ($resolverChargesInTheirOwnCurrency !== $allowed) {
                    $disagreements[] = $gateway . '/' . $code;
                }
                $pairs++;
            }
        }

        $this->assertSame(64, $pairs, 'the sweep stopped covering the offered gateways');
        $this->assertSame(
            ['paystack/USD', 'senang_pay/USD', 'paytm/USD', 'mtn_cash/USD'],
            $disagreements,
            'the refusal and the legacy resolver differ somewhere other than the dollar fall-through'
        );

        // And the shape of it, so the claim still holds if the store's currency list changes.
        $catalog = app(PaymentGatewayCatalog::class);
        foreach ($disagreements as $pair) {
            [$gateway, $code] = explode('/', $pair);
            $this->assertSame('USD', $code, "{$pair} is a disagreement that is not about the dollar fall-through");
            $this->assertFalse($catalog->declaresCurrency(gateway: $gateway, currencyCode: 'USD'), "{$gateway} declares the dollar, so the fall-through does not explain {$pair}");
        }
    }

    public function test_with_the_switch_off_the_question_refuses_nothing_at_all(): void
    {
        $this->aMultiCurrencyStore(activeCodes: ['SAR', 'USD', 'BDT']);
        $support = app(GatewayCurrencySupport::class);

        foreach (GlobalConstant::DEFAULT_PAYMENT_GATEWAYS as $gateway) {
            foreach (['SAR', 'USD', 'BDT', 'KWD', ''] as $code) {
                $this->assertFalse($support->decide(gateway: $gateway, currencyCode: $code)->refused(), "{$gateway}/{$code} was refused with the switch off");
            }
        }
    }

    /**
     * decide() asked about no currency at all still has no question to answer, and that is the layer the
     * lower-level API keeps: a caller that genuinely has no code gets "allowed".
     *
     * What CHANGED in this round is the layer above it. decideForRequest no longer accepts "no currency
     * named" as "no claim to test", because the claim was being made anyway: the shipped customer app's
     * gateway checkout sends no `current_currency_code` at all and the resolver went on to charge BDT
     * 11,000 for a cart worth SAR 375 (measured; found by Reviewer A). A request that names none is now
     * judged against the currency the store prices in by default -- the same currency the single-currency
     * branch of all three callers charges -- which is pinned by the three tests below this one.
     */
    public function test_the_question_asked_about_no_currency_at_all_still_has_no_answer(): void
    {
        $this->aMultiCurrencyStore();
        $this->switchTheRefusalOn();
        $support = app(GatewayCurrencySupport::class);

        $this->assertFalse($support->decide(gateway: self::ONE_CURRENCY_GATEWAY, currencyCode: null)->refused());
        $this->assertFalse($support->decide(gateway: self::ONE_CURRENCY_GATEWAY, currencyCode: '  ')->refused());
        $this->assertFalse($support->decide(gateway: '', currencyCode: 'SAR')->refused(), 'a request naming no gateway was judged');

        // A posted field can be an array. That must never be the thing that turns a payment start into a
        // 500 -- and with the switch ON it no longer can be, because an unusable field falls back to the
        // store's default code and the legacy resolver is handed a string rather than the array it would
        // have passed to array_key_exists(). Switched off it is passed through exactly as today.
        $this->assertFalse($support->decideForRequest(['payment_method' => ['x'], 'current_currency_code' => 'SAR'])->refused());

        // The store prices in TAKA here, and the unusable field holds 'SAR', so only the store's default
        // can produce the answer: an assertion against a default that happened to equal the field's own
        // contents would not have shown which of the two was read.
        $this->setSetting('system_default_currency', 3);
        $this->assertSame('BDT', $support->chargedCurrencyCode(['payment_method' => self::ONE_CURRENCY_GATEWAY, 'current_currency_code' => ['SAR']]));
        $this->assertSame('BDT', $support->chargedCurrencyCode(['payment_method' => self::ONE_CURRENCY_GATEWAY]));
    }

    public function test_the_currency_is_read_off_the_request_in_one_place_and_a_lower_case_code_is_the_same_code(): void
    {
        $this->aMultiCurrencyStore();
        $this->switchTheRefusalOn();
        $support = app(GatewayCurrencySupport::class);

        $this->assertTrue($support->decideForRequest(['payment_method' => self::ONE_CURRENCY_GATEWAY, 'current_currency_code' => 'sar'])->refused());
        $this->assertFalse($support->decideForRequest(['payment_method' => self::GATEWAY_THAT_TAKES_RIYAL, 'current_currency_code' => ' sar '])->refused());
    }

    // ------------------------------------------- the currency the request does not name (Reviewer A, F1)

    /**
     * THE SHIPPED APP'S OWN CHECKOUT, with the payload the Flutter repository really sends.
     *
     * lavkooapp's digitalPaymentPlaceOrder (lib/features/checkout/domain/repositories/checkout_repository.dart)
     * posts order_note, customer_id, address_id, billing_address_id, coupon_code, coupon_discount,
     * payment_platform, payment_method, callback, payment_request_from, guest_id, is_guest,
     * is_check_create_account and password -- and NO current_currency_code. There is no interceptor that
     * adds one, and an API request has no session either, so the first round's guard never fired on the
     * product's highest-volume gateway path: measured at 200 and a payment row of BDT 11,000 for a cart
     * worth SAR 375, with the refusal switched ON. The currency the store prices in by default is now
     * what such a request is judged against, so it is refused.
     */
    public function test_the_apps_own_checkout_names_no_currency_and_is_refused_all_the_same(): void
    {
        $this->aMultiCurrencyStore();
        $this->switchTheRefusalOn();
        $customer = $this->makeCustomer();
        $address = $this->makeLegacyAddress($customer->id);
        $this->aCartReadyToPay($customer->id);

        $response = $this->asCustomer($customer)->postJson(self::APP_PAYMENT, [
            'order_note' => null, 'customer_id' => (string) $customer->id, 'address_id' => (string) $address->id,
            'billing_address_id' => null, 'coupon_code' => null, 'coupon_discount' => null,
            'payment_platform' => 'app', 'payment_method' => self::ONE_CURRENCY_GATEWAY, 'callback' => null,
            'payment_request_from' => 'app', 'guest_id' => null, 'is_guest' => false,
            'is_check_create_account' => '0', 'password' => null,
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'payment-currency')
            ->assertJsonPath('message', translate('this_payment_method_cannot_take_payments_in_your_currency') . ' (SAR)');

        $this->assertNothingWasWritten();
    }

    /** The same request with an EMPTY code: the global middleware nulls it, and it is judged the same way. */
    public function test_a_currency_field_that_arrives_empty_is_judged_and_not_waved_through(): void
    {
        $this->aMultiCurrencyStore();
        $this->switchTheRefusalOn();

        $this->startsAppPayment(self::ONE_CURRENCY_GATEWAY, '')->assertStatus(403);
        $this->assertNothingWasWritten();
    }

    /** The other side of it: a gateway that CAN take the store's default currency still goes through. */
    public function test_a_request_that_names_no_currency_still_pays_a_gateway_that_can_take_the_stores_own(): void
    {
        $this->aMultiCurrencyStore();
        $this->switchTheRefusalOn();

        $this->startsAppPayment(self::GATEWAY_THAT_TAKES_RIYAL, '')->assertOk()->assertJsonStructure(['redirect_link']);

        $this->assertSame(['currency' => 'SAR', 'amount' => 375.0], $this->theCharge());
    }

    /** And with the switch in its shipped state it is still converted exactly as it is today. */
    public function test_with_the_switch_off_a_request_naming_no_currency_still_converts_as_it_does_today(): void
    {
        // Measured on a worktree at 222fe1eb through this same route with no current_currency_code.
        $this->aMultiCurrencyStore();

        $this->startsAppPayment(self::ONE_CURRENCY_GATEWAY, '')->assertOk();
        $this->assertSame(['currency' => 'BDT', 'amount' => 11000.0], $this->theCharge());
    }

    // ------------------------------- the code judged is the code charged (Reviewer A, F2)

    /**
     * A lower-case currency code, driven through the real route and MEASURED AT THE CHARGE.
     *
     * The guard upper-cases; the legacy resolver's own first branch is a case-sensitive
     * array_key_exists. So the first round judged `sar` takeable as SAR, the resolver missed its first
     * branch, fell through to "the first active declared currency" -- Currency::whereIn(...)->first(),
     * no ORDER BY, so whichever row the engine returns first -- and charged KWD 31 for a cart worth SAR
     * 375, with the refusal switched ON (measured by Reviewer A; reproduced here at 200/KWD 31 before
     * the fix). The fixture is theirs: KWD active and its row sorting first, which is what exposes it.
     *
     * One code is now read, normalised and handed BOTH to the question and to the conversion, so the
     * thing judged and the thing charged are the same string.
     */
    public function test_a_lower_case_currency_code_is_charged_in_that_currency_and_not_another(): void
    {
        $this->aStoreWhereTheFallThroughLandsOnKuwait();
        $this->switchTheRefusalOn();

        $this->startsAppPayment(self::GATEWAY_THAT_TAKES_RIYAL, 'sar')->assertOk();
        $this->assertSame(['currency' => 'SAR', 'amount' => 375.0], $this->theCharge(), 'a lower-case code was charged in another currency');

        DB::table('payment_requests')->delete();
        $this->startsAppPayment(self::GATEWAY_THAT_TAKES_RIYAL, ' sar ')->assertOk();
        $this->assertSame(['currency' => 'SAR', 'amount' => 375.0], $this->theCharge(), 'a padded code was charged in another currency');

        DB::table('payment_requests')->delete();
        $this->startsAppPayment(self::GATEWAY_THAT_TAKES_RIYAL, 'SAR')->assertOk();
        $this->assertSame(['currency' => 'SAR', 'amount' => 375.0], $this->theCharge(), 'the control case moved');
    }

    /** Switched off, the same request still produces today's figure -- the substitution included. */
    public function test_with_the_switch_off_a_lower_case_code_is_still_charged_the_way_it_is_today(): void
    {
        // Measured on a worktree at 222fe1eb with this fixture: KWD 31 for a cart worth SAR 375.
        $this->aStoreWhereTheFallThroughLandsOnKuwait();

        $this->startsAppPayment(self::GATEWAY_THAT_TAKES_RIYAL, 'sar')->assertOk();
        $this->assertSame(['currency' => 'KWD', 'amount' => 31.0], $this->theCharge());
    }

    /**
     * Four active currencies with Kuwait's row sorting FIRST, so the resolver's unordered `first()`
     * lands on it when its case-sensitive branch misses the customer's own code.
     */
    private function aStoreWhereTheFallThroughLandsOnKuwait(): void
    {
        $this->aMultiCurrencyStore();
        DB::table('currencies')->truncate();
        $id = 0;
        foreach ([['KWD', 'Kuwaiti Dinar', 0.31], ['SAR', 'Saudi Riyal', 3.75], ['USD', 'United States Dollar', 1], ['BDT', 'Bangladeshi Taka', 110]] as [$code, $name, $rate]) {
            DB::table('currencies')->insert([
                'id' => ++$id, 'name' => $name, 'code' => $code, 'symbol' => $code, 'exchange_rate' => $rate, 'status' => 1,
            ]);
        }

        // Riyal is still what the store prices in by default; only the row order changed.
        $this->setSetting('system_default_currency', 2);
    }

    // ------------------- a gateway that declares nothing is not judged (both reviewers)

    /**
     * `mercadopago_pix` has a start route and NO entry in the declared-currency map, so the first round's
     * guard refused it in every currency there is: an operator who had configured it and then switched
     * the refusal on lost every payment through it, told that it "cannot take payments in your currency"
     * (measured by both reviewers; reproduced here at 403 before the fix). It is reachable: payment()
     * does not restrict payment_method to the default list, and Helpers::payment_gateways() drops that
     * filter entirely once the gateway add-on is published.
     *
     * A gateway that declares nothing makes no claim this can contradict -- the same situation as a
     * question asked about no currency -- so the answer is the same: allowed, and the catalogue hole is
     * recorded rather than charged to the customer. The base branch's own catalogue test already pinned
     * the hole; this asserts it from the same maps so the two cannot drift apart.
     */
    public function test_a_gateway_that_declares_no_currency_at_all_is_not_refused(): void
    {
        $this->aMultiCurrencyStore();
        $this->switchTheRefusalOn();
        $catalog = app(PaymentGatewayCatalog::class);

        $declarationless = array_values(array_diff(
            array_keys(PaymentGatewayCatalog::canonicalStartRoutes()),
            array_keys($catalog->declaredCurrencies())
        ));
        $this->assertSame(['mercadopago_pix'], $declarationless, 'the set of gateways that declare no currency has changed');
        $this->assertTrue($catalog->declaresNoCurrency(gateway: 'mercadopago_pix'));

        $this->assertFalse(
            app(GatewayCurrencySupport::class)->decide(gateway: 'mercadopago_pix', currencyCode: 'SAR')->refused(),
            'a gateway that declares no currency was refused a currency'
        );

        // And through the real route it pays, at the figure the base branch produces.
        $this->startsAppPayment('mercadopago_pix', 'SAR')->assertOk();
        $this->assertSame(['currency' => 'USD', 'amount' => 100.0], $this->theCharge());
    }

    /**
     * An older spelling of a gateway is judged as the gateway it is -- AND charged as it.
     *
     * My own second reading of the fix found the same trap as Reviewer A's F2, in the other argument.
     * The question canonicalises `my_fatoorah` to `fatoorah` and so judges a Riyal takeable; the legacy
     * resolver does not canonicalise, looks `my_fatoorah` up in the declared-currency map, finds nothing,
     * and falls through to USD. Measured through this route with the switch ON before the fix: allowed,
     * and charged USD 100 on a cart worth SAR 375 -- a judgement about one currency and a charge in
     * another, which is the thing this requirement forbids.
     *
     * Only the currency lookup is canonicalised. The payment row still records the method the customer
     * posted, which is asserted, because an older payment link names that spelling.
     */
    public function test_an_older_spelling_of_a_gateway_is_judged_and_charged_as_the_gateway_it_is(): void
    {
        $this->aMultiCurrencyStore();
        $this->switchTheRefusalOn();
        $catalog = app(PaymentGatewayCatalog::class);

        $this->assertArrayHasKey('my_fatoorah', PaymentGatewayCatalog::ALIASES);
        $this->assertFalse($catalog->declaresNoCurrency(gateway: 'my_fatoorah'), 'an alias looks like a gateway that declares nothing');
        $this->assertFalse(app(GatewayCurrencySupport::class)->decide(gateway: 'my_fatoorah', currencyCode: 'SAR')->refused());
        $this->assertTrue(app(GatewayCurrencySupport::class)->decide(gateway: 'my_fatoorah', currencyCode: 'BDT')->refused());

        $this->startsAppPayment('my_fatoorah', 'SAR')->assertOk();
        $this->assertSame(['currency' => 'SAR', 'amount' => 375.0], $this->theCharge(), 'an older spelling was charged in a currency nobody chose');
        $this->assertSame('my_fatoorah', DB::table('payment_requests')->value('payment_method'), 'the payment row stopped recording the spelling the customer posted');
    }

    /** Switched off, that same request still falls through to the dollar exactly as it does today. */
    public function test_with_the_switch_off_an_older_spelling_still_falls_through_to_the_dollar(): void
    {
        // Measured on a worktree at 222fe1eb through this route: USD 100 for a cart worth SAR 375.
        $this->aMultiCurrencyStore();

        $this->startsAppPayment('my_fatoorah', 'SAR')->assertOk();
        $this->assertSame(['currency' => 'USD', 'amount' => 100.0], $this->theCharge());
    }

    // --------------------------- the refusal must not sign the customer out (both reviewers)

    /**
     * The order-edit route answers 401 for a dead session, and lavkooapp clears the login token on ANY
     * 401: api_error_handler.dart `case 401` calls AuthController::clearSharedData(), which removes
     * userLoginToken and guestId from shared preferences -- the same call the logout sheet makes. The
     * `case 403` arm reads the same message fields and touches nothing. So an ordinary, operator-expected
     * refusal answered 401 showed the message and signed the customer out mid-payment.
     *
     * This refusal is answered 403. The cases that already used 401 keep it, which is asserted here too,
     * because changing a status an existing client already parses is not what this lane is for.
     */
    public function test_the_order_edits_refusal_does_not_sign_the_customer_out_and_the_old_401s_remain(): void
    {
        $this->aMultiCurrencyStore();
        $this->switchTheRefusalOn();

        $this->paysTheDueAmount(self::ONE_CURRENCY_GATEWAY, 'SAR')->assertStatus(403);

        // An order that does not exist: still 401, the status this route has always answered.
        $customer = $this->makeCustomer();
        $this->asCustomer($customer)->postJson(self::EDIT_DUE_APP, [
            'order_id' => 999999, 'payment_method' => self::GATEWAY_THAT_TAKES_RIYAL, 'current_currency_code' => 'SAR',
        ])->assertStatus(401)->assertJsonPath('message', translate('Order_not_found'));
    }

    /**
     * A failure that comes out of the SAME method by the other route keeps its 401: a due amount of zero
     * makes generate_link throw, and that return carries no refusal name. Without this the mapping could
     * answer 403 for every failure and nothing would notice.
     *
     * Its own test method, not an extra request in the one above: `orders.id` is not auto-increment in
     * the real-engine test schema, so a second order in one method collides on MariaDB with
     * "Duplicate entry '0' for key 'PRIMARY'" (measured -- SQLite tolerated it and MariaDB did not).
     */
    public function test_a_due_payment_that_fails_for_another_reason_keeps_the_401_it_always_had(): void
    {
        $this->aMultiCurrencyStore();
        $this->switchTheRefusalOn();
        $customer = $this->makeCustomer();
        $order = $this->anOrderWithADueAmount($customer->id, due: 0);

        $this->asCustomer($customer)->postJson(self::EDIT_DUE_APP, [
            'order_id' => $order->id, 'payment_method' => self::GATEWAY_THAT_TAKES_RIYAL, 'current_currency_code' => 'SAR',
        ])->assertStatus(401);

        $this->assertSame(0, $this->paymentRequests());
    }

    // ------------------------------------- the session half of the one read (mutation S1)

    /**
     * The WEB routes judge the session whenever the form does not carry the field, and nothing pinned
     * that: deleting the session half of the one read left the whole suite green (Reviewer A's surviving
     * mutation S1). Laravel's ConvertEmptyStringsToNull makes an empty posted field null, and `??` on a
     * Request tests the fetched value, so the session really is what decides here.
     */
    public function test_the_websites_payment_start_is_refused_on_the_currency_in_the_session(): void
    {
        $this->aMultiCurrencyStore();
        $this->switchTheRefusalOn();
        $customer = $this->makeCustomer();
        $address = $this->makeLegacyAddress($customer->id);
        $this->aCartReadyToPay($customer->id);

        // TAKA in the session, RIYAL as the store's default, and a gateway that declares the Riyal and
        // not the Taka. So only the session can produce this refusal: judged against the store default
        // instead, this very request would be ALLOWED. The first attempt at this test put the same code
        // in both places and the mutation that deletes the session read still passed.
        $response = $this->actingAs($customer, 'customer')->withSession(['currency_code' => 'BDT'])
            ->post(self::WEB_PAYMENT, [
                'payment_method' => self::GATEWAY_THAT_TAKES_RIYAL, 'payment_platform' => 'web', 'address_id' => $address->id,
            ]);

        $response->assertRedirect(route('checkout-payment'));
        $this->assertTheCustomerWasTold(currency: 'BDT');
        $this->assertNothingWasWritten();
    }

    /** And the session is what the conversion is given too, so an allowed one is charged in it. */
    public function test_the_websites_payment_start_is_charged_in_the_currency_in_the_session(): void
    {
        $this->aMultiCurrencyStore();
        $this->switchTheRefusalOn();
        $customer = $this->makeCustomer();
        $address = $this->makeLegacyAddress($customer->id);
        $this->aCartReadyToPay($customer->id);

        $this->actingAs($customer, 'customer')->withSession(['currency_code' => 'SAR'])
            ->post(self::WEB_PAYMENT, [
                'payment_method' => self::GATEWAY_THAT_TAKES_RIYAL, 'payment_platform' => 'web', 'address_id' => $address->id,
            ])->assertRedirectContains('payment/myfatoorah/pay');

        $this->assertSame(['currency' => 'SAR', 'amount' => 375.0], $this->theCharge());
    }

    // ------------------------------- where in the path the guard sits (mutation S2)

    /**
     * The guard's POSITION, which nothing pinned: it is hoisted to the entry point, above the cart read,
     * the stock check and the minimum-order check, and only the last step of that was measured -- moving
     * it one statement further was red, moving it four was green (Reviewer A's surviving mutation S2).
     *
     * The gate chosen for it is the app-only shipping-method check inside the same method, below the cart
     * read: with `shipping_type` = order_wise, a physical product and NO cart_shippings row, the route
     * answers `errors.code = shipping-method`. That gate is reached only once the cart has been read, so
     * a guard still above it answers the CURRENCY message on exactly the same request -- and the test
     * below this one measures the same request with the switch off and sees the shipping gate answer, so
     * the discriminator is proved live rather than assumed.
     *
     * (Two gates that look like they would serve do NOT, measured: an out-of-stock cart and a cart under
     * the minimum order amount are both answered by OrderManager::checkValidationForCheckoutPages, which
     * runs ABOVE the guard, so neither refusal says anything about where the guard sits.)
     */
    public function test_the_refusal_comes_before_the_cart_is_read(): void
    {
        $this->aMultiCurrencyStore();
        $this->switchTheRefusalOn();
        $this->aStoreThatChecksShippingAfterTheGuard();
        $customer = $this->makeCustomer();
        $address = $this->makeLegacyAddress($customer->id);
        $this->putOneProductInTheCart($customer->id, false);

        $this->asCustomer($customer)->postJson(self::APP_PAYMENT, [
            'payment_method' => self::ONE_CURRENCY_GATEWAY, 'payment_platform' => 'app', 'payment_request_from' => 'app',
            'customer_id' => $customer->id, 'is_guest' => 0, 'address_id' => $address->id, 'current_currency_code' => 'SAR',
        ])
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'payment-currency');

        $this->assertSame(0, $this->paymentRequests());
    }

    /** The same request, the refusal in its shipped state: the gate below the guard really does answer. */
    public function test_the_gate_below_the_guard_is_live_on_the_same_request(): void
    {
        $this->aMultiCurrencyStore();
        $this->aStoreThatChecksShippingAfterTheGuard();
        $customer = $this->makeCustomer();
        $address = $this->makeLegacyAddress($customer->id);
        $this->putOneProductInTheCart($customer->id, false);

        $this->asCustomer($customer)->postJson(self::APP_PAYMENT, [
            'payment_method' => self::ONE_CURRENCY_GATEWAY, 'payment_platform' => 'app', 'payment_request_from' => 'app',
            'customer_id' => $customer->id, 'is_guest' => 0, 'address_id' => $address->id, 'current_currency_code' => 'SAR',
        ])
            ->assertStatus(403)
            ->assertJsonPath('errors.code', 'shipping-method');

        $this->assertSame(0, $this->paymentRequests());
    }

    private function aStoreThatChecksShippingAfterTheGuard(): void
    {
        $this->setSetting('shipping_method', 'inhouse_shipping');
        $this->setSetting('shipping_type', 'order_wise');
    }

    // ------------------------------- the app's own wallet route (Reviewer B)

    /**
     * POST /api/v1/add-to-fund, which is what lavkooapp's wallet screen calls
     * (lib/features/wallet/domain/repositories/wallet_repository.dart, AppConstants.addFundUri). The
     * first round drove the WEB top-up route with payment_request_from=app and called that the app's
     * route; this is the one the phone really posts to (found by Reviewer B).
     */
    public function test_the_apps_own_wallet_route_is_refused_and_still_pays_a_gateway_that_can(): void
    {
        $this->aMultiCurrencyStore();
        $this->walletTopUpIsOpen();
        $this->switchTheRefusalOn();
        $customer = $this->makeCustomer();

        $this->asCustomer($customer)->postJson('/api/v1/add-to-fund', [
            'amount' => 100, 'payment_method' => self::ONE_CURRENCY_GATEWAY, 'payment_platform' => 'app',
            'payment_request_from' => 'app', 'current_currency_code' => 'SAR',
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', translate('this_payment_method_cannot_take_payments_in_your_currency') . ' (SAR)');

        $this->assertNothingWasWritten();
        $this->assertSame(0.0, (float) $customer->fresh()->wallet_balance);

        $this->asCustomer($customer)->postJson('/api/v1/add-to-fund', [
            'amount' => 100, 'payment_method' => self::GATEWAY_THAT_TAKES_RIYAL, 'payment_platform' => 'app',
            'payment_request_from' => 'app', 'current_currency_code' => 'SAR',
        ])->assertOk();

        $this->assertSame(['currency' => 'SAR', 'amount' => 375.0], $this->theCharge());
    }

    // ------------------------------- which refusal answers first (Reviewer B, F9)

    /**
     * A gateway that is BOTH hidden by a D2-06 routing rule and unable to take the currency. The currency
     * guard sits at the entry point and the routing guard deep inside getCustomerPaymentRequest, so the
     * currency answers first. Deliberate -- the currency refusal is the one that runs before anything is
     * read -- and recorded here rather than left to be discovered (found by Reviewer B).
     */
    public function test_an_unusable_currency_answers_before_a_routing_rule_hides_the_method(): void
    {
        $this->aMultiCurrencyStore();
        $this->switchTheRefusalOn();

        $this->startsAppPayment(self::ONE_CURRENCY_GATEWAY, 'SAR')
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'payment-currency');

        // And on the wallet top-up it answers before the minimum/maximum band.
        $this->walletTopUpIsOpen();
        $this->setSetting('minimum_add_fund_amount', 5000);
        $customer = $this->makeCustomer();

        $this->asCustomer($customer)->postJson(self::ADD_FUND, [
            'amount' => 1, 'payment_method' => self::ONE_CURRENCY_GATEWAY, 'payment_platform' => 'app',
            'payment_request_from' => 'app', 'current_currency_code' => 'SAR',
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', translate('this_payment_method_cannot_take_payments_in_your_currency') . ' (SAR)');
    }

    // ---------------------------------------------------------------- nothing half-written

    /**
     * The website's refusals say why. A redirect on its own is indistinguishable from a page reload, so
     * the flashed message is read out of the session the request left behind.
     */
    private function assertTheCustomerWasTold(string $currency = 'SAR'): void
    {
        $told = array_column(session('toastr::messages') ?? [], 'message');

        $this->assertContains(
            translate('this_payment_method_cannot_take_payments_in_your_currency') . ' (' . $currency . ')',
            $told,
            'the customer was sent back without being told why'
        );
    }

    /**
     * No payment row, no new order, no order line, no money movement, no stock move and no account.
     *
     * `$ordersTheTestMade` is the orders the fixture itself created -- an order edit's due payment needs
     * one to be due on -- so a refused payment adding an order of its own is still caught.
     *
     * WHICH OF THESE ACTUALLY DISCRIMINATE, measured rather than assumed (found by Reviewer B): at a
     * payment START only `payment_requests` moves on the allowed path. The order, its lines, the
     * transaction, the stock move and the history row are all written on the gateway's callback, so those
     * assertions are equally true of the unguarded code and are a trap for a future change rather than
     * evidence about this guard. `payment_requests` and `users` are the two that bite here, and the
     * position of the guard is pinned separately, by the test that refuses before the cart is read.
     */
    private function assertNothingWasWritten(int $ordersTheTestMade = 0, int $usersTheTestMade = 1): void
    {
        $this->assertSame(0, $this->paymentRequests(), 'a payment row was written for a refused payment');
        $this->assertSame($usersTheTestMade, DB::table('users')->count(), 'an account was created for a refused payment');
        $this->assertSame($ordersTheTestMade, DB::table('orders')->count(), 'an order was written for a refused payment');
        $this->assertSame(0, DB::table('order_details')->count(), 'an order line was written for a refused payment');
        $this->assertSame(0, DB::table('order_transactions')->count(), 'a money movement was recorded for a refused payment');

        // Every product these tests create starts at ten, so anything else is a stock move.
        $this->assertSame([], DB::table('products')->where('current_stock', '<>', 10)->pluck('id')->all(), 'the stock moved for a refused payment');

        $this->assertSame(0.0, (float) DB::table('users')->sum('wallet_balance'), 'a wallet moved for a refused payment');
        $this->assertSame(0, DB::table('order_status_histories')->count());
    }
}
