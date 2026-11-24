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
    private function gatewayReportsBack(User $payer, array $stored, string $transactionId = 'TXN-1'): array
    {
        request()->replace([]);
        digital_payment_success([
            'is_paid' => 1, 'payment_method' => 'ssl_commerz', 'transaction_id' => $transactionId,
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

    /** A payer holding one row of each market, so that what the order takes says which market it was built in. */
    private function payerHoldingBothMarkets(string $email, string $phone): User
    {
        $payer = $this->makeCustomer(['email' => $email, 'phone' => $phone]);

        foreach ([1 => 'kw', 2 => 'ae'] as $productId => $group) {
            DB::table('carts')->insert([
                'customer_id' => $payer->id, 'is_guest' => 0, 'cart_group_id' => 'group-' . $payer->id . $group, 'product_id' => $productId,
                'quantity' => 1, 'price' => 100, 'seller_id' => 1, 'seller_is' => 'admin', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $payer;
    }

    /**
     * The two halves of the resolution that joined the security set and the GCC chain in `module-helper.php` (M1-03): a callback is settled
     * in the storefront the payment was started in, and the settlement scope keeps one payment to one order.
     *
     * Two markets settled one after the other in ONE process is the case neither set could test alone, and it is the same leak class as a
     * queue worker's (A1-14): the first callback binds a storefront, and a storefront left bound would build the second market's order in
     * the first market -- from the wrong cart rows, invoiced by the wrong company, in the wrong money. Each payer here holds one row of each
     * market, so what the order takes is what says which market it was built in.
     */
    public function test_two_payments_of_two_markets_settled_one_after_the_other_each_build_their_own(): void
    {
        $this->switchStorefrontsOn();
        config(['gcc.storefronts.countries.AE.active' => true]);
        $this->theTwoMarketsCatalogue();
        $kuwaiti = $this->payerHoldingBothMarkets('kuwaiti@example.test', '+96550000000');
        $emirati = $this->payerHoldingBothMarkets('emirati@example.test', '+971500000000');
        $this->bindTheDefaultStorefront();

        $this->gatewayReportsBack($kuwaiti, ['storefront' => 'KW']);
        $this->gatewayReportsBack($emirati, ['storefront' => 'AE'], transactionId: 'TXN-2');

        $this->assertSame(
            [1, 2],
            DB::table('order_details')->orderBy('order_id')->pluck('product_id')->map(fn ($id) => (int) $id)->all(),
            'the Kuwaiti payment took the Kuwaiti row and the Emirati payment the Emirati one: neither callback took the other market with it',
        );
        $this->assertSame(
            [2, 1],
            DB::table('carts')->orderBy('customer_id')->pluck('product_id')->map(fn ($id) => (int) $id)->all(),
            'and each payer keeps the row of the market they did not pay in',
        );
        $this->assertSame('SA', StorefrontContext::current()->country, 'the request keeps the storefront it was serving');
    }

    /**
     * One payment is one order, however often the gateway reports it: the settlement half of the same resolution.
     *
     * The cart is cleared when the order is written, so a plain second report finds nothing to build and would look harmless with no guard at
     * all. What the guard is for is the state where the cart is NOT empty -- a clearing that failed, a recovery sweep, a customer who put the
     * same thing back -- so the cart is refilled here before the gateway reports again. Without the guard that builds a second order for one
     * payment; with it the payment is recognised and nothing is built.
     */
    public function test_a_second_report_of_one_payment_builds_no_second_order_even_with_a_cart_to_build_from(): void
    {
        $this->switchStorefrontsOn();
        $payer = $this->payerWithAKuwaitCart();
        $this->bindTheDefaultStorefront();

        $this->assertCount(1, $this->gatewayReportsBack($payer, ['storefront' => 'KW']));

        $this->putOneProductInTheCart($payer->id);

        $this->assertCount(1, $this->gatewayReportsBack($payer, ['storefront' => 'KW']), 'the payment is recognised, so nothing is built twice');
        $this->assertSame(1, DB::table('carts')->where('customer_id', $payer->id)->count(), 'and the cart it did not take is left alone');
    }

    /** The product of each market, with the Kuwaiti one being the row the cart helper already made. */
    private function theTwoMarketsCatalogue(): void
    {
        if (!DB::table('products')->where('id', 1)->exists()) {
            DB::table('products')->insert(['id' => 1, 'name' => 'Paracetamol', 'added_by' => 'admin', 'user_id' => 1, 'unit_price' => 100, 'current_stock' => 10]);
        }

        DB::table('products')->where('id', 1)->update(['storefront_country' => 'KW']);
        DB::table('products')->insert([
            'id' => 2, 'name' => 'Ibuprofen', 'added_by' => 'admin', 'user_id' => 1, 'unit_price' => 100, 'current_stock' => 10, 'storefront_country' => 'AE',
        ]);
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
