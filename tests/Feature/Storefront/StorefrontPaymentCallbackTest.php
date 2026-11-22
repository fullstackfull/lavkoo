<?php

namespace Tests\Feature\Storefront;

use App\Models\User;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\ActivatesStorefront;
use Tests\Support\ApprovesStorefrontTax;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\KeepsOrderDebugLogClean;

/**
 * A payment is finished in the storefront it was started in. The gateway reports back in a request of its own, with no storefront prefix,
 * header or query, so that request is served as the default storefront. A customer who paid in another country would then have an empty
 * cart and no order, with the money already taken. The storefront is recorded when the payment starts and bound again before the order is
 * built. While storefronts are off nothing is recorded and nothing is bound.
 */
class StorefrontPaymentCallbackTest extends ApiAddressTestCase
{
    use ActivatesStorefront;
    use ApprovesStorefrontTax;
    use BuildsStorefrontCatalog;
    use KeepsOrderDebugLogClean;

    private const APP_PAYMENT = '/api/v1/digital-payment';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPipelineTables();
        $this->useTaxIncludedPrices();
        $this->runStorefrontCountryMigration();
        $this->rememberOrderDebugLog();
    }

    protected function tearDown(): void
    {
        $this->restoreOrderDebugLog();

        parent::tearDown();
    }

    public static function callbackRequests(): array
    {
        return [
            'a request the storefront middleware bound to the default storefront' => ['bindTheDefaultStorefront'],
            'a request no middleware has bound yet' => ['serveAsAnUnboundWebRequest'],
        ];
    }

    private function switchStorefrontsOn(bool $kuwaitIsActive = true): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.countries.KW.active' => $kuwaitIsActive,
        ]);
    }

    /** What the storefront middleware does for a request that has no prefix, header or query. */
    private function bindTheDefaultStorefront(): void
    {
        app()->instance(StorefrontContext::class, StorefrontResolver::fromConfig()->context());
    }

    private function serveAsAnUnboundWebRequest(): void
    {
        $this->serveAsHttpRequest('/payment/stripe/callback');
    }

    private function payerWithAKuwaitCart(): User
    {
        $payer = $this->makeCustomer();
        $this->putOneProductInTheCart($payer->id);
        DB::table('products')->where('id', 1)->update(['storefront_country' => 'KW']);

        return $payer;
    }

    /**
     * @param array<string, mixed> $stored what was stored when the payment started
     * @return list<int|string> the ids of the orders the callback created
     */
    private function gatewayReportsBack(User $payer, array $stored): array
    {
        request()->replace([]);
        digital_payment_success([
            'is_paid' => 1, 'payment_method' => 'ssl_commerz', 'transaction_id' => 'TXN-1',
            'additional_data' => json_encode($stored + [
                'customer_id' => $payer->id, 'is_guest' => 0, 'is_guest_in_order' => 0, 'order_note' => null, 'coupon_code' => null,
                'payment_mode' => 'web', 'payment_request_from' => 'web', 'new_customer_id' => 0,
            ]),
        ]);

        return DB::table('orders')->orderBy('id')->pluck('id')->all();
    }

    /** @return array<string, mixed> */
    private function storedPayment(): array
    {
        return json_decode((string) DB::table('payment_requests')->value('additional_data'), true);
    }

    #[DataProvider('callbackRequests')]
    public function test_a_paid_kuwait_cart_becomes_an_order_although_the_callback_names_no_storefront(string $serveTheCallback): void
    {
        $this->switchStorefrontsOn();
        $payer = $this->payerWithAKuwaitCart();
        $this->$serveTheCallback();

        $orders = $this->gatewayReportsBack($payer, ['storefront' => 'KW']);

        $this->assertCount(1, $orders, 'the customer paid, so the order exists');
        $this->assertSame(1, (int) DB::table('order_details')->value('product_id'));
    }

    #[DataProvider('callbackRequests')]
    public function test_a_payment_started_in_a_storefront_that_was_switched_off_since_is_still_finished(string $serveTheCallback): void
    {
        $this->switchStorefrontsOn(kuwaitIsActive: false);
        $payer = $this->payerWithAKuwaitCart();
        $this->$serveTheCallback();

        $this->assertCount(1, $this->gatewayReportsBack($payer, ['storefront' => 'KW']));
    }

    public function test_the_request_gets_its_own_storefront_back_once_the_order_is_built(): void
    {
        $this->switchStorefrontsOn();
        $payer = $this->payerWithAKuwaitCart();
        $this->bindTheDefaultStorefront();

        $this->assertCount(1, $this->gatewayReportsBack($payer, ['storefront' => 'KW']));

        $this->assertSame('SA', StorefrontContext::current()->country);
    }

    public function test_a_request_that_had_no_storefront_bound_has_none_bound_once_the_order_is_built(): void
    {
        $this->switchStorefrontsOn();
        $payer = $this->payerWithAKuwaitCart();
        $this->serveAsAnUnboundWebRequest();

        $this->assertCount(1, $this->gatewayReportsBack($payer, ['storefront' => 'KW']));

        $this->assertFalse(app()->bound(StorefrontContext::class));
    }

    #[DataProvider('callbackRequests')]
    public function test_a_payment_with_no_recorded_storefront_is_finished_as_before(string $serveTheCallback): void
    {
        $this->switchStorefrontsOn();
        $payer = $this->makeCustomer();
        $this->putOneProductInTheCart($payer->id);
        $this->$serveTheCallback();

        $this->assertCount(1, $this->gatewayReportsBack($payer, []));
    }

    #[DataProvider('callbackRequests')]
    public function test_a_payment_started_in_a_country_that_was_removed_from_the_configuration_since_is_still_finished(string $serveTheCallback): void
    {
        $this->switchStorefrontsOn();
        $payer = $this->payerWithAKuwaitCart();
        $countries = config('gcc.storefronts.countries');
        unset($countries['KW']);
        config(['gcc.storefronts.countries' => $countries]);
        $this->$serveTheCallback();

        $orders = $this->gatewayReportsBack($payer, ['storefront' => 'KW']);

        $this->assertCount(1, $orders, 'the customer paid in Kuwait, and has no Saudi cart to fall back on');
        $this->assertSame(1, (int) DB::table('order_details')->value('product_id'));
        $this->assertSame(0, DB::table('carts')->count());
    }

    public function test_a_payment_started_in_a_removed_country_does_not_take_the_customers_saudi_cart_instead(): void
    {
        $this->switchStorefrontsOn();
        $payer = $this->payerWithAKuwaitCart();
        DB::table('products')->insert(['id' => 2, 'name' => 'Saudi serum', 'added_by' => 'admin', 'user_id' => 1, 'unit_price' => 40, 'current_stock' => 5, 'storefront_country' => 'SA']);
        DB::table('carts')->insert([
            'customer_id' => $payer->id, 'is_guest' => 0, 'cart_group_id' => 'group-' . $payer->id . 's', 'product_id' => 2, 'quantity' => 1, 'price' => 40,
            'seller_id' => 1, 'seller_is' => 'admin', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $countries = config('gcc.storefronts.countries');
        unset($countries['KW']);
        config(['gcc.storefronts.countries' => $countries]);
        $this->serveAsAnUnboundWebRequest();

        $this->gatewayReportsBack($payer, ['storefront' => 'KW']);

        $this->assertSame([1], DB::table('order_details')->pluck('product_id')->map(fn ($id) => (int) $id)->all(), 'the order is the cart that was paid for');
        $this->assertSame([2], DB::table('carts')->pluck('product_id')->map(fn ($id) => (int) $id)->all(), 'and the Saudi row is still in the cart');
    }

    public function test_while_storefronts_are_off_the_recorded_storefront_changes_nothing(): void
    {
        $payer = $this->payerWithAKuwaitCart();
        $this->bindTheDefaultStorefront();

        $orders = $this->gatewayReportsBack($payer, ['storefront' => 'KW']);

        $this->assertCount(1, $orders);
        $this->assertSame('SA', StorefrontContext::current()->country);
    }

    public function test_a_payment_started_in_a_storefront_records_it(): void
    {
        $this->switchStorefrontsOn();
        config(['gcc.storefronts.legal.grandfathered' => ['SA', 'KW']]);
        $this->approveNoTaxFor('KW');
        $this->activateStorefrontFor('KW');
        $customer = $this->makeCustomer();
        $this->putOneProductInTheCart($customer->id);
        DB::table('products')->where('id', 1)->update(['storefront_country' => 'KW']);
        DB::table('cart_shippings')->insert(['cart_group_id' => 'group-' . $customer->id . 'c', 'shipping_method_id' => 1, 'shipping_cost' => 0]);

        $this->asCustomer($customer)->withHeader('X-Storefront', 'KW')
            ->postJson(self::APP_PAYMENT, ['payment_method' => 'ssl_commerz', 'payment_platform' => 'app', 'payment_request_from' => 'app', 'customer_id' => $customer->id, 'is_guest' => 0])
            ->assertOk();

        $this->assertSame('KW', $this->storedPayment()['storefront']);
    }

    public function test_a_payment_started_while_storefronts_are_off_records_no_storefront(): void
    {
        $customer = $this->makeCustomer();
        $this->putOneProductInTheCart($customer->id);
        DB::table('cart_shippings')->insert(['cart_group_id' => 'group-' . $customer->id . 'c', 'shipping_method_id' => 1, 'shipping_cost' => 0]);

        $this->asCustomer($customer)->withHeader('X-Storefront', 'KW')
            ->postJson(self::APP_PAYMENT, ['payment_method' => 'ssl_commerz', 'payment_platform' => 'app', 'payment_request_from' => 'app', 'customer_id' => $customer->id, 'is_guest' => 0])
            ->assertOk();

        $this->assertArrayNotHasKey('storefront', $this->storedPayment());
    }
}
