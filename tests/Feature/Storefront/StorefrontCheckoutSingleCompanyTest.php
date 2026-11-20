<?php

namespace Tests\Feature\Storefront;

use App\Exceptions\OrderMixesSellingCompaniesException;
use App\Models\Cart;
use App\Models\Product;
use App\Models\User;
use App\Services\Storefront\OneSellingCompanyPerOrder;
use App\Services\Storefront\PaymentStorefront;
use App\Services\Storefront\StorefrontCatalog;
use App\Utils\OrderManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;

/**
 * An order names one country and one selling company (D1-02). The cart keeps a storefront's rows apart, but only for a request a
 * storefront was resolved for and only while storefronts are on, so the rule is asserted again where the order is written: on the
 * checkout page, so the customer is refused cleanly, and inside generateOrder() before the transaction, for every other caller.
 *
 * Saudi Arabia never meets it: every product there carries one country, so every order's rows name one selling scope.
 */
class StorefrontCheckoutSingleCompanyTest extends ApiAddressTestCase
{
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;

    private const COD = '/api/v1/customer/order/place';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontCartWorld();
        $this->seedTwoCountries();
    }

    /** Both countries' rows checked in one cart group, with one shipping choice: what an older client or a script leaves behind. */
    private function customerWithOneMixedGroup(): User
    {
        $customer = $this->makeCustomer();
        $this->cartRow($customer->id, 1, 100);
        $this->cartRow($customer->id, 2, 250);
        DB::table('cart_shippings')->insert(['cart_group_id' => 'group-' . $customer->id . 'c', 'shipping_method_id' => 1, 'shipping_cost' => 25]);

        return $customer;
    }

    /** @return array<int, list<int>> order id => the product ids its rows name */
    private function ordersWithTheirRows(): array
    {
        $rows = [];
        foreach (DB::table('order_details')->orderBy('id')->get() as $detail) {
            $rows[(int) $detail->order_id][] = (int) $detail->product_id;
        }

        return $rows;
    }

    /** Places the order the way the hook of a payment that recorded no storefront does: nothing bound, nothing narrowed. */
    private function placeAnUnboundOrder(User $customer): void
    {
        auth('customer')->setUser($customer);

        PaymentStorefront::within(additionalData: [], callback: fn () => OrderManager::generateOrder(data: [
            'is_guest' => 0,
            'customer_id' => $customer->id,
            'order_status' => 'confirmed',
            'payment_method' => 'digital_payment',
            'payment_status' => 'paid',
        ]));
    }

    private function addTheSellingCompanyColumn(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->unsignedBigInteger(StorefrontCatalog::ENTITY_COLUMN)->nullable());
        StorefrontCatalog::flush();
    }

    public function test_the_switch_being_off_no_longer_lets_one_order_carry_two_countries(): void
    {
        $customer = $this->customerWithOneMixedGroup();

        $this->asCustomer($customer)->getJson(self::COD)
            ->assertStatus(422)
            ->assertJsonPath('message', translate('Product_is_unavailable'));

        $this->assertSame([], $this->ordersWithTheirRows(), 'nothing was written');
        $this->assertSame([1, 2], $this->productsInTheCart(), 'and the cart is left as it was');
    }

    public function test_an_unbound_request_no_longer_lets_one_order_carry_two_countries(): void
    {
        $customer = $this->customerWithOneMixedGroup();
        $this->switchStorefrontsOn();

        $this->expectException(OrderMixesSellingCompaniesException::class);

        try {
            $this->placeAnUnboundOrder($customer);
        } finally {
            $this->assertSame([], $this->ordersWithTheirRows());
            $this->assertSame([1, 2], $this->productsInTheCart());
        }
    }

    public function test_a_checkout_paying_for_two_countries_in_two_cart_groups_is_refused(): void
    {
        $customer = $this->makeCustomer();
        $this->cartRow($customer->id, 1, 100, group: 'group-sa');
        $this->cartRow($customer->id, 2, 250, group: 'group-ae');
        foreach (['group-sa', 'group-ae'] as $group) {
            DB::table('cart_shippings')->insert(['cart_group_id' => $group, 'shipping_method_id' => 1, 'shipping_cost' => 25]);
        }

        $this->asCustomer($customer)->getJson(self::COD)->assertStatus(422);

        $this->assertSame([], $this->ordersWithTheirRows());
    }

    public function test_the_checkout_page_refuses_a_cart_of_two_countries_with_the_message_used_for_an_unavailable_product(): void
    {
        $customer = $this->customerWithOneMixedGroup();
        auth('customer')->setUser($customer);

        $response = OrderManager::checkValidationForCheckoutPages(request());

        $this->assertSame(0, $response['status']);
        $this->assertContains(translate('Product_is_unavailable'), $response['message'], 'the same message the cart uses when it refuses a product it may not sell');
        $this->assertSame(route('shop-cart'), $response['redirect']);
    }

    /** Criterion [3], already MET: a storefront request orders its own rows out of a legacy mixed group and leaves the other country's. */
    public function test_a_bound_storefront_still_checks_out_a_legacy_mixed_group_per_storefront(): void
    {
        $customer = $this->customerWithOneMixedGroup();
        $this->switchStorefrontsOn();

        $this->asCustomer($customer)->withHeader('X-Storefront', 'AE')->getJson(self::COD)->assertOk();

        $this->assertSame([[2]], array_values($this->ordersWithTheirRows()));
        $this->assertSame([1], $this->productsInTheCart());
    }

    /** The customer who buys each country separately is never refused: two orders, one country each, out of the one legacy group. */
    public function test_each_country_of_a_legacy_mixed_group_can_be_checked_out_in_turn(): void
    {
        $customer = $this->customerWithOneMixedGroup();
        $this->switchStorefrontsOn();

        $this->asCustomer($customer)->withHeader('X-Storefront', 'AE')->getJson(self::COD)->assertOk();
        DB::table('cart_shippings')->insert(['cart_group_id' => 'group-' . $customer->id . 'c', 'shipping_method_id' => 1, 'shipping_cost' => 25]);
        $this->asCustomer($customer)->withHeader('X-Storefront', 'SA')->getJson(self::COD)->assertOk();

        $this->assertSame([[2], [1]], array_values($this->ordersWithTheirRows()));
        $this->assertSame([], $this->productsInTheCart());
    }

    public function test_an_order_of_one_countrys_rows_is_written_exactly_as_before(): void
    {
        $customer = $this->makeCustomer();
        DB::table('products')->where('id', 2)->update(['storefront_country' => 'SA']);
        $this->cartRow($customer->id, 1, 100);
        $this->cartRow($customer->id, 2, 250);
        DB::table('cart_shippings')->insert(['cart_group_id' => 'group-' . $customer->id . 'c', 'shipping_method_id' => 1, 'shipping_cost' => 25]);

        $this->asCustomer($customer)->getJson(self::COD)->assertOk();

        $this->assertSame([[1, 2]], array_values($this->ordersWithTheirRows()));
    }

    public function test_the_checkout_page_says_yes_to_one_countrys_rows(): void
    {
        $customer = $this->customerWithOneMixedGroup();
        DB::table('products')->where('id', 2)->update(['storefront_country' => 'SA']);
        auth('customer')->setUser($customer);

        $this->assertSame(1, OrderManager::checkValidationForCheckoutPages(request())['status']);
    }

    /** The database the platform runs on today: no country column, so no row says who sells it and no order is refused. */
    public function test_before_the_country_migration_nothing_is_refused(): void
    {
        $customer = $this->customerWithOneMixedGroup();
        $this->removeStorefrontCountryColumn();
        StorefrontCatalog::flush();

        $this->asCustomer($customer)->getJson(self::COD)->assertOk();

        $this->assertSame([[1, 2]], array_values($this->ordersWithTheirRows()));
    }

    /** The rule is read off the products the checkout has already loaded, so it adds nothing to the Saudi checkout's query count. */
    public function test_judging_the_checked_rows_asks_the_database_nothing(): void
    {
        $this->customerWithOneMixedGroup();
        $rows = Cart::with('product')->get();

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->assertTrue(OneSellingCompanyPerOrder::spansMoreThanOne(cartRows: $rows));
        $this->assertSame(0, $queries);
    }

    public function test_two_companies_of_one_country_are_refused_once_products_carry_a_company(): void
    {
        $customer = $this->customerWithOneMixedGroup();
        DB::table('products')->update(['storefront_country' => 'SA']);
        $this->addTheSellingCompanyColumn();
        DB::table('products')->where('id', 1)->update([StorefrontCatalog::ENTITY_COLUMN => 7]);
        DB::table('products')->where('id', 2)->update([StorefrontCatalog::ENTITY_COLUMN => 8]);

        $this->asCustomer($customer)->getJson(self::COD)->assertStatus(422);

        $this->assertSame([], $this->ordersWithTheirRows());
    }

    public function test_one_company_of_one_country_is_not_refused_once_products_carry_a_company(): void
    {
        $customer = $this->customerWithOneMixedGroup();
        DB::table('products')->update(['storefront_country' => 'SA']);
        $this->addTheSellingCompanyColumn();
        DB::table('products')->update([StorefrontCatalog::ENTITY_COLUMN => 7]);

        $this->asCustomer($customer)->getJson(self::COD)->assertOk();

        $this->assertSame([[1, 2]], array_values($this->ordersWithTheirRows()));
    }

    public function test_a_selling_scope_names_the_company_only_once_products_carry_one(): void
    {
        $this->assertSame('storefront_country=SA', StorefrontCatalog::sellingScopeOf(product: Product::find(1)));

        $this->addTheSellingCompanyColumn();
        DB::table('products')->where('id', 1)->update([StorefrontCatalog::ENTITY_COLUMN => 7]);

        $this->assertSame('storefront_country=SA/legal_entity_id=7', StorefrontCatalog::sellingScopeOf(product: Product::find(1)));
        $this->assertNull(StorefrontCatalog::sellingScopeOf(product: null), 'a row whose product is gone says nothing');

        $this->removeStorefrontCountryColumn();
        $this->assertSame('legal_entity_id=7', StorefrontCatalog::sellingScopeOf(product: Product::find(1)));
    }
}
