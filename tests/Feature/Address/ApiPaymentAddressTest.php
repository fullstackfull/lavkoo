<?php

namespace Tests\Feature\Address;

use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\User;
use App\Services\Address\AddressSchema;
use Illuminate\Support\Facades\DB;

/**
 * Starting an online payment: the address it is for is checked BEFORE the shopper is handed to the gateway, because after the
 * money is taken an order can no longer be refused. Both the app and the website start payments through the same controller.
 */
class ApiPaymentAddressTest extends ApiAddressTestCase
{
    private const APP_PAYMENT = '/api/v1/digital-payment';

    private const WEB_PAYMENT = '/customer/web-payment-request';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPipelineTables();
        $this->useTaxIncludedPrices();
    }

    private function startsOrderReadyCart(int $callerId, bool $isGuest = false): void
    {
        $this->putOneProductInTheCart($callerId, $isGuest);
        DB::table('cart_shippings')->insert(['cart_group_id' => 'group-' . $callerId . ($isGuest ? 'g' : 'c'), 'shipping_method_id' => 1, 'shipping_cost' => 0]);
    }

    /** @return array<string, mixed> */
    private function appPayment(array $overrides = []): array
    {
        return $overrides + ['payment_method' => 'ssl_commerz', 'payment_platform' => 'app', 'payment_request_from' => 'app'];
    }

    private function paymentRequests(): int
    {
        return DB::table('payment_requests')->count();
    }

    /** @return array<string, mixed> */
    private function storedPayment(): array
    {
        return json_decode((string) DB::table('payment_requests')->value('additional_data'), true);
    }

    public function test_the_app_is_not_handed_to_the_gateway_with_an_address_that_is_not_the_payers(): void
    {
        $customer = $this->makeCustomer();
        $mine = $this->makeLegacyAddress($customer->id);
        $theirs = $this->makeLegacyAddress($this->makeCustomer()->id);
        $this->startsOrderReadyCart($customer->id);

        foreach ([['address_id' => $theirs->id], ['address_id' => $mine->id, 'billing_address_id' => $theirs->id]] as $addresses) {
            $response = $this->asCustomer($customer)->postJson(self::APP_PAYMENT, $this->appPayment($addresses + ['customer_id' => $customer->id, 'is_guest' => 0]));

            $response->assertStatus(403)->assertJsonPath('errors.0.code', 'address')->assertJsonPath('message', translate('address_not_found'));
        }
        $this->assertSame(0, $this->paymentRequests());
    }

    public function test_a_payer_cannot_borrow_another_customers_identity_to_reach_that_customers_address(): void
    {
        $customer = $this->makeCustomer();
        $victim = $this->makeCustomer();
        $victimAddress = $this->makeLegacyAddress($victim->id);
        $this->startsOrderReadyCart($customer->id);

        $this->asCustomer($customer)->postJson(self::APP_PAYMENT, $this->appPayment(['customer_id' => $victim->id, 'is_guest' => 0, 'address_id' => $victimAddress->id]))->assertStatus(403);
        $this->assertSame(0, $this->paymentRequests());
    }

    public function test_a_guest_cannot_pay_with_the_address_of_another_guest_or_of_a_customer_with_the_same_number(): void
    {
        $this->startsOrderReadyCart(7001, isGuest: true);
        $otherGuest = $this->makeLegacyAddress(7002, isGuest: true);
        $customerWithTheSameNumber = $this->makeLegacyAddress(7001);

        foreach ([$otherGuest->id, $customerWithTheSameNumber->id] as $addressId) {
            $this->postJson(self::APP_PAYMENT, $this->appPayment(['guest_id' => 7001, 'customer_id' => 7001, 'is_guest' => 1, 'address_id' => $addressId]))->assertStatus(403);
        }
        $this->assertSame(0, $this->paymentRequests());
    }

    public function test_the_payment_that_starts_is_stored_for_the_authenticated_payer_whatever_the_app_claims(): void
    {
        $customer = $this->makeCustomer();
        $other = $this->makeCustomer();
        $mine = $this->makeLegacyAddress($customer->id);
        $this->startsOrderReadyCart($customer->id);

        $this->asCustomer($customer)->postJson(self::APP_PAYMENT, $this->appPayment(['customer_id' => $other->id, 'is_guest' => 1, 'address_id' => $mine->id]))
            ->assertOk()->assertJsonStructure(['redirect_link']);

        $stored = $this->storedPayment();
        $this->assertSame([$customer->id, 0, $mine->id], [$stored['customer_id'], (int) $stored['is_guest'], $stored['address_id']]);
    }

    public function test_a_guests_payment_is_stored_under_the_guest_id_whatever_customer_id_the_app_sends(): void
    {
        $this->startsOrderReadyCart(7001, isGuest: true);
        $address = $this->makeLegacyAddress(7001, isGuest: true, overrides: ['email' => 'guest@example.test']);

        $this->postJson(self::APP_PAYMENT, $this->appPayment(['guest_id' => 7001, 'customer_id' => 7002, 'is_guest' => 1, 'address_id' => $address->id]))->assertOk();

        $stored = $this->storedPayment();
        $this->assertSame([7001, 1], [(int) $stored['customer_id'], (int) $stored['is_guest']]);
    }

    public function test_the_app_may_pay_with_an_old_saudi_address_in_soft_mode_but_not_in_strict_mode(): void
    {
        $customer = $this->makeCustomer();
        $old = $this->makeLegacyAddress($customer->id);
        $this->startsOrderReadyCart($customer->id);

        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $this->asCustomer($customer)->postJson(self::APP_PAYMENT, $this->appPayment(['customer_id' => $customer->id, 'is_guest' => 0, 'address_id' => $old->id]))->assertOk();

        $this->setAddressMode(AddressSchema::MODE_STRICT);
        $response = $this->asCustomer($customer)->postJson(self::APP_PAYMENT, $this->appPayment(['customer_id' => $customer->id, 'is_guest' => 0, 'address_id' => $old->id]));
        $response->assertStatus(403)->assertJsonPath('message', translate('address_complete_required'));
        $this->assertSame(1, $this->paymentRequests(), 'only the soft-mode payment was started');
    }

    public function test_a_payment_without_any_address_starts_as_before(): void
    {
        $this->setAddressMode(AddressSchema::MODE_STRICT);
        $customer = $this->makeCustomer();
        $this->startsOrderReadyCart($customer->id);

        $this->asCustomer($customer)->postJson(self::APP_PAYMENT, $this->appPayment(['customer_id' => $customer->id, 'is_guest' => 0]))->assertOk();
    }

    private function onTheWebsite(): void
    {
        $this->withoutMiddleware([Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class, RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class]);
    }

    public function test_the_website_sends_the_shopper_back_to_the_address_step_instead_of_to_the_gateway_with_an_address_that_is_not_theirs(): void
    {
        $this->onTheWebsite();
        $customer = $this->makeCustomer();
        $theirs = $this->makeLegacyAddress($this->makeCustomer()->id);
        $this->startsOrderReadyCart($customer->id);
        $this->actingAs($customer, 'customer');

        $response = $this->withSession(['address_id' => $theirs->id])->post(self::WEB_PAYMENT, ['payment_method' => 'ssl_commerz', 'payment_platform' => 'web']);

        $response->assertRedirect(route('checkout-details'));
        $this->assertSame(0, $this->paymentRequests());
    }

    public function test_the_website_asks_for_an_old_saudi_address_to_be_completed_in_soft_mode_and_accepts_a_structured_one(): void
    {
        $this->onTheWebsite();
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->makeCustomer();
        $old = $this->makeLegacyAddress($customer->id);
        $structured = $this->makeStructuredAddress($this->ids, $customer->id);
        $this->startsOrderReadyCart($customer->id);
        $this->actingAs($customer, 'customer');

        $this->withSession(['address_id' => $old->id])->post(self::WEB_PAYMENT, ['payment_method' => 'ssl_commerz', 'payment_platform' => 'web'])
            ->assertRedirect(route('checkout-details'));
        $this->assertSame(0, $this->paymentRequests());

        $this->withSession(['address_id' => $structured->id, 'billing_address_id' => $structured->id])->post(self::WEB_PAYMENT, ['payment_method' => 'ssl_commerz', 'payment_platform' => 'web'])
            ->assertRedirectContains('payment/sslcommerz/pay');
        $this->assertSame($structured->id, $this->storedPayment()['address_id']);
    }

    public function test_the_website_may_pay_with_an_address_typed_at_checkout_and_never_saved(): void
    {
        $this->onTheWebsite();
        $customer = $this->makeCustomer();
        $unsaved = $this->makeLegacyAddress(0);
        $this->startsOrderReadyCart($customer->id);
        $this->actingAs($customer, 'customer');

        $this->withSession(['address_id' => $unsaved->id])->post(self::WEB_PAYMENT, ['payment_method' => 'ssl_commerz', 'payment_platform' => 'web'])
            ->assertRedirectContains('payment/sslcommerz/pay');
        $this->assertNotNull(User::find($customer->id));
    }
}
