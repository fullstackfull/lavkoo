<?php

namespace Tests\Feature\Storefront;

use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Library\Payer;
use App\Library\Payment as PaymentInfo;
use App\Library\Receiver;
use App\Models\User;
use App\Exceptions\StorefrontPaymentMethodNotOfferedException;
use App\Services\Storefront\StorefrontPaymentMethods;
use App\Traits\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsSixStorefrontCheckoutWorld;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\BuildsStorefrontMoneyWorld;

/**
 * Requirement D2-V2, the control: an order or a payment that asks for a way to pay its storefront has switched off is refused before anything is written, through the
 * real routes of the apps and of the website, and the same request is taken as before in a storefront that did not switch it off, by a storefront for the ways it kept,
 * and everywhere while the switch is off. Hiding the choice on a page is not the control: these routes are.
 */
class StorefrontPaymentMethodCheckoutTest extends ApiAddressTestCase
{
    use BuildsSixStorefrontCheckoutWorld;
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;
    use BuildsStorefrontMoneyWorld;

    private const MESSAGE = 'This payment method is not available in this storefront.';

    private const PRODUCT_OF = ['SA' => 1, 'AE' => 2, 'KW' => 3];

    private const THE_HANDLER_ANSWERS = 'the handler answers';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSixStorefrontCheckoutWorld();
        config(['gcc.storefronts.currency.enabled' => false, 'gcc.storefronts.payment_methods.enabled' => true]);
        foreach (StorefrontPaymentMethods::METHODS as $method) {
            $this->setSetting($method, '{"status":1}');
        }
        foreach (['add_funds_to_wallet' => '1', 'minimum_add_fund_amount' => '1', 'maximum_add_fund_amount' => '1000', 'company_name' => 'Lavkoo', 'company_web_logo' => '{"image_name":"logo.png","storage":"public"}'] as $type => $value) {
            $this->setSetting($type, $value);
        }
    }

    /** @param array<string, list<string>> $byCountry */
    private function switchOff(array $byCountry): void
    {
        config(['gcc.storefronts.payment_methods.switched_off' => $byCountry]);
    }

    private function aCustomerWithACartIn(string $country): User
    {
        $customer = $this->makeCustomer();
        $this->fillTheCartFrom(customer: $customer, country: $country, productId: self::PRODUCT_OF[$country]);

        return $customer;
    }

    // ---- the requests of an app that ask for one of the three ways to pay --------------------------------------------------------

    private function placeByCashOnDelivery(User $customer, string $country): TestResponse
    {
        return $this->asCustomer($customer)->withHeader('X-Storefront', $country)->getJson('/api/v1/customer/order/place');
    }

    private function placeByOfflinePayment(User $customer, string $country): TestResponse
    {
        return $this->asCustomer($customer)->withHeader('X-Storefront', $country)->postJson('/api/v1/customer/order/place-by-offline-payment', [
            'method_id' => 1, 'method_informations' => base64_encode('{}'), 'payment_note' => 'paid at the bank',
        ]);
    }

    private function startDigitalPayment(User $customer, string $country): TestResponse
    {
        return $this->asCustomer($customer)->withHeader('X-Storefront', $country)->postJson('/api/v1/digital-payment', [
            'payment_method' => 'ssl_commerz', 'payment_platform' => 'app', 'payment_request_from' => 'app', 'customer_id' => $customer->id, 'is_guest' => 0,
        ]);
    }

    private function startWalletTopUp(User $customer, string $country): TestResponse
    {
        return $this->asCustomer($customer)->withHeader('X-Storefront', $country)->postJson('/api/v1/add-to-fund', [
            'amount' => 50, 'payment_method' => 'ssl_commerz', 'payment_platform' => 'app', 'payment_request_from' => 'app',
        ]);
    }

    /** @return array<string, array{string, string, string}> the way to pay, the request of an app that asks for it, and the table a taken request writes to */
    public static function requestsOfAnApp(): array
    {
        return [
            'an order by cash on delivery' => [StorefrontPaymentMethods::CASH_ON_DELIVERY, 'placeByCashOnDelivery', 'orders'],
            'an order by offline payment' => [StorefrontPaymentMethods::OFFLINE_PAYMENT, 'placeByOfflinePayment', 'orders'],
            'a payment through a gateway' => [StorefrontPaymentMethods::DIGITAL_PAYMENT, 'startDigitalPayment', 'payment_requests'],
            'a wallet top-up through a gateway' => [StorefrontPaymentMethods::DIGITAL_PAYMENT, 'startWalletTopUp', 'payment_requests'],
        ];
    }

    private function assertNothingWasWritten(): void
    {
        foreach (['orders', 'order_details', 'payment_requests', 'offline_payments'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "nothing is written to {$table}");
        }
        $this->assertSame(1, DB::table('carts')->count(), 'and the cart is as it was');
    }

    private function assertRefused(TestResponse $response): void
    {
        $response->assertStatus(422)->assertExactJson(['code' => 'storefront_payment_method_not_offered', 'message' => self::MESSAGE]);
        $this->assertNothingWasWritten();
    }

    private function assertTaken(TestResponse $response, string $method, string $table): void
    {
        $response->assertOk();
        $this->assertSame(1, DB::table($table)->count(), "one row in {$table}");
        if ($table === 'orders') {
            $this->assertSame($method, DB::table('orders')->value('payment_method'));
        }
    }

    #[DataProvider('requestsOfAnApp')]
    public function test_a_storefront_that_switched_the_method_off_refuses_the_request_before_anything_is_written(string $method, string $request): void
    {
        $this->switchOff(['KW' => [$method]]);
        $customer = $this->aCustomerWithACartIn('KW');

        $this->assertRefused($this->{$request}($customer, 'KW'));
    }

    #[DataProvider('requestsOfAnApp')]
    public function test_the_same_request_is_taken_as_before_in_a_storefront_that_did_not_switch_the_method_off(string $method, string $request, string $table): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        $this->switchOff(['KW' => [$method]]);
        $customer = $this->aCustomerWithACartIn('SA');

        $this->assertTaken($this->{$request}($customer, 'SA'), $method, $table);
    }

    #[DataProvider('requestsOfAnApp')]
    public function test_a_storefront_takes_the_methods_it_did_not_switch_off(string $method, string $request, string $table): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        $this->switchOff(['KW' => array_values(array_diff(StorefrontPaymentMethods::METHODS, [$method]))]);
        $customer = $this->aCustomerWithACartIn('KW');

        $this->assertTaken($this->{$request}($customer, 'KW'), $method, $table);
    }

    #[DataProvider('requestsOfAnApp')]
    public function test_nothing_is_refused_while_the_switch_is_off(string $method, string $request, string $table): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        config(['gcc.storefronts.payment_methods.enabled' => false]);
        $this->switchOff(['KW' => StorefrontPaymentMethods::METHODS, 'SA' => StorefrontPaymentMethods::METHODS]);
        $customer = $this->aCustomerWithACartIn('KW');

        $this->assertTaken($this->{$request}($customer, 'KW'), $method, $table);
    }

    /**
     * Characterisation, not a wish: the routes that take an order or start a payment have never looked at the platform's own switch (only the pages and the apps do), and
     * a storefront that lists nothing answers exactly as before. Closing that is a change of the Saudi storefront's behaviour, which is not this switch's to make.
     */
    #[DataProvider('requestsOfAnApp')]
    public function test_a_method_the_platform_has_off_is_still_taken_where_no_storefront_switched_it_off_as_it_always_was(string $method, string $request, string $table): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        $this->setSetting($method, '{"status":0}');
        $this->switchOff(['AE' => [$method]]);
        $customer = $this->aCustomerWithACartIn('KW');

        $this->assertTaken($this->{$request}($customer, 'KW'), $method, $table);
    }

    public function test_the_default_storefront_can_refuse_a_method_another_storefront_takes(): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        $this->switchOff(['SA' => [StorefrontPaymentMethods::CASH_ON_DELIVERY]]);

        $this->assertRefused($this->placeByCashOnDelivery($this->aCustomerWithACartIn('SA'), 'SA'));

        DB::table('carts')->delete();
        $this->assertTaken($this->placeByCashOnDelivery($this->aCustomerWithACartIn('KW'), 'KW'), StorefrontPaymentMethods::CASH_ON_DELIVERY, 'orders');
    }

    public function test_with_the_currency_of_the_storefront_in_force_too_the_method_is_refused_and_the_default_storefront_still_sells(): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        config(['gcc.storefronts.currency.enabled' => true]);
        $this->switchOff(['KW' => [StorefrontPaymentMethods::CASH_ON_DELIVERY]]);

        $this->assertRefused($this->placeByCashOnDelivery($this->aCustomerWithACartIn('KW'), 'KW'));

        DB::table('carts')->delete();
        $this->assertTaken($this->placeByCashOnDelivery($this->aCustomerWithACartIn('SA'), 'SA'), StorefrontPaymentMethods::CASH_ON_DELIVERY, 'orders');
    }

    public function test_the_refusal_is_in_the_language_the_app_asks_for(): void
    {
        $this->setSetting('language', json_encode([
            ['id' => 1, 'name' => 'English', 'code' => 'en', 'direction' => 'ltr', 'status' => 1, 'default' => true],
            ['id' => 2, 'name' => 'Arabic', 'code' => 'sa', 'direction' => 'rtl', 'status' => 1, 'default' => false],
        ]));
        $this->switchOff(['KW' => [StorefrontPaymentMethods::CASH_ON_DELIVERY]]);
        $customer = $this->aCustomerWithACartIn('KW');

        $this->withHeader('lang', 'ar')->placeByCashOnDelivery($customer, 'KW')
            ->assertStatus(422)
            ->assertExactJson(['code' => 'storefront_payment_method_not_offered', 'message' => 'طريقة الدفع هذه غير متاحة في هذا المتجر.']);
    }

    // ---- every payment that starts through a gateway asks first, whatever route it came by -------------------------------------

    private function startAPaymentThroughAGateway(): mixed
    {
        $paymentInfo = new PaymentInfo(
            success_hook: 'digital_payment_success',
            failure_hook: 'digital_payment_fail',
            currency_code: 'SAR',
            payment_method: 'ssl_commerz',
            payment_platform: 'web',
            payer_id: '1',
            receiver_id: '100',
            additional_data: [],
            payment_amount: 125.5,
            attribute: 'order',
            attribute_id: '1',
        );

        return Payment::generate_link(new Payer('A Customer', 'customer@example.invalid', '', ''), $paymentInfo, new Receiver('receiver_name', 'example.png'));
    }

    public function test_a_payment_through_a_gateway_never_gets_a_row_in_a_storefront_that_switched_digital_payment_off(): void
    {
        $this->switchOff(['KW' => [StorefrontPaymentMethods::DIGITAL_PAYMENT]]);

        try {
            $this->inStorefront('KW', fn () => $this->startAPaymentThroughAGateway());
            $this->fail('Kuwait switched digital payment off.');
        } catch (StorefrontPaymentMethodNotOfferedException) {
            $this->assertSame(0, DB::table('payment_requests')->count());
        }

        $this->assertIsString($this->inStorefront('SA', fn () => $this->startAPaymentThroughAGateway()));
        $this->assertIsString($this->startAPaymentThroughAGateway(), 'and outside a storefront, as before');
        $this->assertSame(2, DB::table('payment_requests')->count());
    }

    public function test_cash_on_delivery_or_offline_payment_switched_off_does_not_stop_a_payment_through_a_gateway(): void
    {
        $this->switchOff(['KW' => [StorefrontPaymentMethods::CASH_ON_DELIVERY, StorefrontPaymentMethods::OFFLINE_PAYMENT]]);

        $this->assertIsString($this->inStorefront('KW', fn () => $this->startAPaymentThroughAGateway()));
    }

    // ---- the due of an edited order ----------------------------------------------------------------------------------------------

    /** @return array<string, array{string, string}> */
    public static function dueRoutesOfAnApp(): array
    {
        return [
            'by cash on delivery' => [StorefrontPaymentMethods::CASH_ON_DELIVERY, '/api/v1/edit-order/due-payment-by-cod'],
            'by offline payment' => [StorefrontPaymentMethods::OFFLINE_PAYMENT, '/api/v1/edit-order/due-payment-by-offline-payment'],
            'through a gateway' => [StorefrontPaymentMethods::DIGITAL_PAYMENT, '/api/v1/edit-order/due-payment-by-digital-payment'],
        ];
    }

    private function payTheDue(string $address, string $country): TestResponse
    {
        return $this->withHeader('X-Storefront', $country)->postJson($address, ['order_id' => 999, 'payment_method' => 'any', 'method_id' => 1, 'current_currency_code' => 'SAR', 'guest_id' => 1]);
    }

    #[DataProvider('dueRoutesOfAnApp')]
    public function test_the_due_of_an_edited_order_cannot_be_paid_by_a_method_the_storefront_of_the_request_switched_off(string $method, string $address): void
    {
        $this->switchOff(['KW' => [$method]]);
        $this->serveAsHttpRequest($address);

        $this->payTheDue($address, 'KW')->assertStatus(422)->assertExactJson(['code' => 'storefront_payment_method_not_offered', 'message' => self::MESSAGE]);
        $this->payTheDue($address, 'SA')->assertStatus(401)->assertJsonPath('message', translate('Order_not_found'));
    }

    #[DataProvider('dueRoutesOfAnApp')]
    public function test_the_due_routes_answer_as_before_while_the_switch_is_off(string $method, string $address): void
    {
        config(['gcc.storefronts.payment_methods.enabled' => false]);
        $this->switchOff(['KW' => [$method]]);
        $this->serveAsHttpRequest($address);

        $this->payTheDue($address, 'KW')->assertStatus(401)->assertJsonPath('message', translate('Order_not_found'));
    }

    // ---- the website -------------------------------------------------------------------------------------------------------------

    private function onTheWebsite(User $customer, string $country): static
    {
        $this->withoutMiddleware([Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class, RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class]);
        $this->actingAs($customer, 'customer');
        $address = $this->makeLegacyAddress($customer->id);

        return $this->withHeader('X-Storefront', $country)->withSession(['address_id' => $address->id, 'billing_address_id' => $address->id])->from('/checkout-payment');
    }

    /** @return array<string, array{string, string, array<string, mixed>, string}> the way to pay, the address the website posts to, what it posts, and what a taken request leaves */
    public static function requestsOfTheWebsite(): array
    {
        $due = '/customer/customer-order-edit-pay-amount';

        return [
            'an order by cash on delivery' => [StorefrontPaymentMethods::CASH_ON_DELIVERY, '/checkout-complete', ['payment_method' => 'cash_on_delivery'], 'orders'],
            'an order by offline payment' => [StorefrontPaymentMethods::OFFLINE_PAYMENT, '/offline-payment-checkout-complete', ['payment_method' => 'offline_payment', 'method_id' => 1], 'orders'],
            'a payment through a gateway' => [StorefrontPaymentMethods::DIGITAL_PAYMENT, '/customer/web-payment-request', ['payment_method' => 'ssl_commerz', 'payment_platform' => 'web'], 'payment_requests'],
            'a wallet top-up through a gateway' => [StorefrontPaymentMethods::DIGITAL_PAYMENT, '/customer/customer-add-fund-request', ['amount' => 50, 'payment_method' => 'ssl_commerz', 'payment_platform' => 'web'], 'payment_requests'],
            'the due of an edited order by offline payment' => [StorefrontPaymentMethods::OFFLINE_PAYMENT, $due, ['order_id' => 999, 'payment_method' => 'offline_payment', 'payment_platform' => 'web', 'method_id' => 1], self::THE_HANDLER_ANSWERS],
            'the due of an edited order by cash on delivery' => [StorefrontPaymentMethods::CASH_ON_DELIVERY, $due, ['order_id' => 999, 'payment_method' => 'cash_on_delivery', 'payment_platform' => 'web'], self::THE_HANDLER_ANSWERS],
            'the due of an edited order through a gateway' => [StorefrontPaymentMethods::DIGITAL_PAYMENT, $due, ['order_id' => 999, 'payment_method' => 'ssl_commerz', 'payment_platform' => 'web'], self::THE_HANDLER_ANSWERS],
        ];
    }

    /** The website took the request: an order or a payment was written, or, for the due of an order that does not exist, the handler itself answered. */
    private function assertTakenByTheWebsite(TestResponse $response, string $proof): void
    {
        $response->assertRedirect()->assertSessionMissing('error');
        $this->assertStringNotContainsString(self::MESSAGE, json_encode(session('toastr::messages')));

        if ($proof === self::THE_HANDLER_ANSWERS) {
            $this->assertStringContainsString(translate('Order_not_found'), json_encode(session('toastr::messages')));

            return;
        }

        $this->assertSame(1, DB::table($proof)->count(), "one row in {$proof}");
    }

    #[DataProvider('requestsOfTheWebsite')]
    public function test_the_website_sends_the_shopper_back_with_the_message_and_writes_nothing(string $method, string $address, array $form): void
    {
        $this->switchOff(['KW' => [$method]]);
        $customer = $this->aCustomerWithACartIn('KW');

        $response = $this->onTheWebsite($customer, 'KW')->post($address, $form);

        $response->assertRedirect('/checkout-payment')->assertSessionHas('error', self::MESSAGE);
        $this->assertStringContainsString(self::MESSAGE, json_encode(session('toastr::messages')), 'the page shows it');
        $this->assertNothingWasWritten();
    }

    #[DataProvider('requestsOfTheWebsite')]
    public function test_the_website_takes_the_same_request_as_before_in_a_storefront_that_did_not_switch_the_method_off(string $method, string $address, array $form, string $proof): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        $this->switchOff(['KW' => [$method]]);
        $customer = $this->aCustomerWithACartIn('SA');

        $this->assertTakenByTheWebsite($this->onTheWebsite($customer, 'SA')->post($address, $form), $proof);
    }

    #[DataProvider('requestsOfTheWebsite')]
    public function test_the_website_takes_in_a_storefront_the_methods_that_storefront_did_not_switch_off(string $method, string $address, array $form, string $proof): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        $this->switchOff(['KW' => array_values(array_diff(StorefrontPaymentMethods::METHODS, [$method]))]);
        $customer = $this->aCustomerWithACartIn('KW');

        $this->assertTakenByTheWebsite($this->onTheWebsite($customer, 'KW')->post($address, $form), $proof);
    }

    #[DataProvider('requestsOfTheWebsite')]
    public function test_the_website_refuses_nothing_while_the_switch_is_off(string $method, string $address, array $form, string $proof): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        config(['gcc.storefronts.payment_methods.enabled' => false]);
        $this->switchOff(['KW' => StorefrontPaymentMethods::METHODS, 'SA' => StorefrontPaymentMethods::METHODS]);
        $customer = $this->aCustomerWithACartIn('KW');

        $this->assertTakenByTheWebsite($this->onTheWebsite($customer, 'KW')->post($address, $form), $proof);
    }

    public function test_a_script_of_the_website_is_answered_like_an_app(): void
    {
        $this->switchOff(['KW' => [StorefrontPaymentMethods::CASH_ON_DELIVERY]]);
        $customer = $this->aCustomerWithACartIn('KW');

        $this->onTheWebsite($customer, 'KW')->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => '*/*'])->post('/checkout-complete', ['payment_method' => 'cash_on_delivery'])
            ->assertStatus(422)
            ->assertExactJson(['code' => 'storefront_payment_method_not_offered', 'message' => self::MESSAGE]);
        $this->assertNothingWasWritten();
    }
}
