<?php

namespace Tests\Feature\Storefront;

use App\Http\Controllers\Customer\SystemController;
use App\Http\Controllers\Web\CartController as WebCartController;
use App\Models\User;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use App\Utils\CartManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;

/**
 * The places where a cart crosses storefronts or loses its product: logging in with a guest cart that holds two countries, a row whose
 * product is gone, the shipping choice of a cart group, and the restock request. Each country's rows stay in that country's cart, nothing
 * is left that cannot be removed, and the behaviour while storefronts are off is the one it always was.
 */
class StorefrontCartEdgesTest extends ApiAddressTestCase
{
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;

    private const GUEST_ID = 777;

    private const MISSING_PRODUCT = 99;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontCartWorld();
        $this->seedTwoCountries();
        $this->insertCopyOfProduct(id: 3, of: 1, slug: 'second-sa');
        $this->insertCopyOfProduct(id: 4, of: 2, slug: 'second-ae');
        DB::table('shipping_methods')->insert(['id' => 1, 'title' => 'Standard', 'cost' => 25, 'status' => 1]);
    }

    private function insertCopyOfProduct(int $id, int $of, string $slug): void
    {
        DB::table('products')->insert(DB::table('products')->where('id', $of)->get()->map(fn ($row) => array_merge((array) $row, ['id' => $id, 'slug' => $slug]))->all());
    }

    /** The shop's own shipping with one cost per order: the only mode in which a shipping choice is kept for a cart group. */
    private function useOrderWiseShipping(): void
    {
        DB::table('business_settings')->insert(['type' => 'shipping_method', 'value' => 'inhouse_shipping']);
        DB::table('shipping_types')->insert(['seller_id' => 0, 'shipping_type' => 'order_wise']);
        Cache::forget(CACHE_BUSINESS_SETTINGS_TABLE);
    }

    private function serveRequestFrom(string $country): void
    {
        app()->instance(StorefrontContext::class, StorefrontResolver::fromConfig()->context(explicitChoice: $country));
    }

    private function guestAdds(int $productId, string $storefront): void
    {
        $this->withHeader('X-Storefront', $storefront)->postJson('/api/v1/cart/add', ['id' => $productId, 'quantity' => 1, 'guest_id' => self::GUEST_ID])->assertOk()->assertJsonPath('status', 1);
    }

    /** A customer who already shops both countries, and a guest who added a product from each. */
    private function customerAndAGuestWhoShopBothCountries(): User
    {
        $customer = $this->makeCustomer();
        $this->switchStorefrontsOn();
        $this->cartRow($customer->id, 3, 100, group: 'sa-group');
        $this->cartRow($customer->id, 4, 250, group: 'ae-group');
        $this->guestAdds(productId: 1, storefront: 'SA');
        $this->guestAdds(productId: 2, storefront: 'AE');

        return $customer;
    }

    /** @return array<int, array{customer: int, guest: int, group: string}> by product id */
    private function rowsOfTheTwoProducts(): array
    {
        return DB::table('carts')->whereIn('product_id', [1, 2])->get()->mapWithKeys(fn ($row) => [(int) $row->product_id => ['customer' => (int) $row->customer_id, 'guest' => (int) $row->is_guest, 'group' => $row->cart_group_id]])->all();
    }

    public function test_logging_in_through_the_api_moves_the_guests_rows_of_both_countries_into_the_cart_of_each_country(): void
    {
        $customer = $this->customerAndAGuestWhoShopBothCountries();

        $this->asCustomer($customer)->withHeader('X-Storefront', 'AE')->postJson('/api/v1/cart/get-merge-guest-cart', ['cart_guest_id' => self::GUEST_ID])->assertOk();

        $this->assertSame([
            1 => ['customer' => $customer->id, 'guest' => 0, 'group' => 'sa-group'],
            2 => ['customer' => $customer->id, 'guest' => 0, 'group' => 'ae-group'],
        ], $this->rowsOfTheTwoProducts(), 'the login was served from the UAE, and the Saudi row still reached the Saudi cart');
    }

    public function test_logging_in_on_the_website_moves_the_guests_rows_of_both_countries_into_the_cart_of_each_country(): void
    {
        $customer = $this->customerAndAGuestWhoShopBothCountries();
        $this->actingAs($customer, 'customer');
        session()->put('guest_id', self::GUEST_ID);
        $this->serveRequestFrom('SA');

        CartManager::cartListSessionToDatabase();

        $this->assertSame([
            1 => ['customer' => $customer->id, 'guest' => 0, 'group' => 'sa-group'],
            2 => ['customer' => $customer->id, 'guest' => 0, 'group' => 'ae-group'],
        ], $this->rowsOfTheTwoProducts());
        $this->assertSame(['SA'], [StorefrontContext::current()->country], 'and the storefront of the request is the one it was');
    }

    public function test_a_guests_row_of_a_country_the_customer_has_no_cart_in_makes_a_cart_group_of_its_own(): void
    {
        $customer = $this->makeCustomer();
        $this->switchStorefrontsOn();
        $this->cartRow($customer->id, 3, 100, group: 'sa-group');
        $this->guestAdds(productId: 2, storefront: 'AE');

        $this->asCustomer($customer)->withHeader('X-Storefront', 'SA')->postJson('/api/v1/cart/get-merge-guest-cart', ['cart_guest_id' => self::GUEST_ID])->assertOk();

        $merged = $this->rowsOfTheTwoProducts()[2];
        $this->assertSame([$customer->id, 0], [$merged['customer'], $merged['guest']]);
        $this->assertNotSame('sa-group', $merged['group'], 'a Saudi cart group does not take an Emirati row');
    }

    public function test_while_storefronts_are_off_a_login_merges_the_whole_guest_cart_into_the_first_group_as_before(): void
    {
        $customer = $this->customerAndAGuestWhoShopBothCountries();
        config(['gcc.storefronts.enabled' => false]);

        $this->asCustomer($customer)->postJson('/api/v1/cart/get-merge-guest-cart', ['cart_guest_id' => self::GUEST_ID])->assertOk();

        $this->assertSame(['sa-group', 'sa-group'], array_column($this->rowsOfTheTwoProducts(), 'group'));
    }

    public function test_a_row_whose_product_is_gone_can_still_be_removed(): void
    {
        $customer = $this->makeCustomer();
        $this->switchStorefrontsOn();
        $this->cartRow($customer->id, 1, 100, group: 'group-a');
        $orphan = $this->cartRow($customer->id, self::MISSING_PRODUCT, 5, group: 'group-a');

        $this->asCustomer($customer)->withHeader('X-Storefront', 'SA')->deleteJson('/api/v1/cart/remove', ['key' => $orphan])->assertOk();

        $this->assertSame([1], $this->productsInTheCart());
    }

    public function test_removing_everything_also_removes_a_row_whose_product_is_gone(): void
    {
        $customer = $this->makeCustomer();
        $this->switchStorefrontsOn();
        $this->cartRow($customer->id, 1, 100, group: 'group-a');
        $this->cartRow($customer->id, self::MISSING_PRODUCT, 5, group: 'group-a');

        $this->asCustomer($customer)->withHeader('X-Storefront', 'SA')->deleteJson('/api/v1/cart/remove-all', ['key' => 1])->assertOk();

        $this->assertSame([], $this->productsInTheCart());
    }

    public function test_checking_out_also_cleans_up_a_row_whose_product_is_gone(): void
    {
        $customer = $this->makeCustomer();
        $this->switchStorefrontsOn();
        $this->cartRow($customer->id, 1, 100, group: 'group-a');
        $this->cartRow($customer->id, self::MISSING_PRODUCT, 5, group: 'group-a');

        $this->asCustomer($customer)->withHeader('X-Storefront', 'SA')->getJson('/api/v1/customer/order/place')->assertOk();

        $this->assertSame([], $this->productsInTheCart());
    }

    public function test_a_row_whose_product_is_gone_does_not_make_the_other_storefronts_rows_reachable(): void
    {
        $customer = $this->makeCustomer();
        $this->switchStorefrontsOn();
        $this->cartRow($customer->id, 2, 250, group: 'group-ae');
        $this->cartRow($customer->id, self::MISSING_PRODUCT, 5, group: 'group-ae');

        $this->asCustomer($customer)->withHeader('X-Storefront', 'SA')->deleteJson('/api/v1/cart/remove-all', ['key' => 1])->assertOk();

        $this->assertSame([2], $this->productsInTheCart(), 'the Emirati row is not the Saudi storefront\'s to remove, only the orphan is');
    }

    public function test_a_shipping_method_cannot_be_chosen_for_the_cart_group_of_another_storefront(): void
    {
        $this->serveAsHttpRequest('/api/v1/shipping-method/choose-for-order');
        $customer = $this->makeCustomer();
        $this->switchStorefrontsOn();
        $this->cartRow($customer->id, 1, 100, group: 'group-sa');
        $this->cartRow($customer->id, 2, 250, group: 'group-ae');

        $this->asCustomer($customer)->withHeader('X-Storefront', 'SA')->postJson('/api/v1/shipping-method/choose-for-order', ['cart_group_id' => 'group-ae', 'id' => 1])->assertForbidden();

        $this->assertSame(0, DB::table('cart_shippings')->count());
    }

    public function test_a_shipping_method_cannot_be_chosen_for_the_cart_group_of_another_customer(): void
    {
        $this->serveAsHttpRequest('/api/v1/shipping-method/choose-for-order');
        $customer = $this->makeCustomer();
        $stranger = $this->makeCustomer();
        $this->switchStorefrontsOn();
        $this->cartRow($customer->id, 1, 100, group: 'group-own');
        $this->cartRow($stranger->id, 1, 100, group: 'group-stranger');

        $this->asCustomer($customer)->withHeader('X-Storefront', 'SA')->postJson('/api/v1/shipping-method/choose-for-order', ['cart_group_id' => 'group-stranger', 'id' => 1])->assertForbidden();

        $this->assertSame(0, DB::table('cart_shippings')->count());
    }

    public function test_a_shipping_method_can_still_be_chosen_for_one_group_and_for_all_groups_of_the_storefront(): void
    {
        $this->serveAsHttpRequest('/api/v1/shipping-method/choose-for-order');
        $customer = $this->makeCustomer();
        $this->switchStorefrontsOn();
        $this->cartRow($customer->id, 1, 100, group: 'group-sa');
        $this->cartRow($customer->id, 3, 100, group: 'group-sa-two');
        $this->cartRow($customer->id, 2, 250, group: 'group-ae');

        $this->asCustomer($customer)->withHeader('X-Storefront', 'SA')->postJson('/api/v1/shipping-method/choose-for-order', ['cart_group_id' => 'group-sa', 'id' => 1])->assertOk();
        $this->assertSame(['group-sa'], DB::table('cart_shippings')->pluck('cart_group_id')->all());

        $this->asCustomer($customer)->withHeader('X-Storefront', 'SA')->postJson('/api/v1/shipping-method/choose-for-order', ['cart_group_id' => 'all_cart_group', 'id' => 1])->assertOk();
        $this->assertEqualsCanonicalizing(['group-sa', 'group-sa-two'], DB::table('cart_shippings')->pluck('cart_group_id')->all(), 'every group of the storefront, and none of the other');
    }

    public function test_the_website_does_not_set_a_shipping_method_for_the_cart_group_of_another_storefront_either(): void
    {
        $customer = $this->makeCustomer();
        $this->switchStorefrontsOn();
        $this->cartRow($customer->id, 2, 250, group: 'group-ae');

        $this->actingAs($customer, 'customer');
        $this->serveRequestFrom('SA');

        $response = app(SystemController::class)->setShippingMethod(Request::create('/set-shipping-method', 'GET', ['cart_group_id' => 'group-ae', 'id' => 1]));

        $this->assertSame(0, $response->getData(true)['status']);
        $this->assertSame(0, DB::table('cart_shippings')->count());
    }

    public function test_checking_out_one_storefront_of_a_legacy_mixed_group_keeps_the_shipping_choice_of_the_rows_left_behind(): void
    {
        $this->useOrderWiseShipping();
        $customer = $this->makeCustomer();
        $this->switchStorefrontsOn();
        $this->cartRow($customer->id, 1, 100);
        $this->cartRow($customer->id, 2, 250);
        DB::table('cart_shippings')->insert(['cart_group_id' => 'group-' . $customer->id . 'c', 'shipping_method_id' => 1, 'shipping_cost' => 25]);

        $this->asCustomer($customer)->withHeader('X-Storefront', 'AE')->getJson('/api/v1/customer/order/place')->assertOk();

        $this->assertSame([1], $this->productsInTheCart());
        $this->assertSame(1, DB::table('cart_shippings')->count(), 'the Saudi row is still in that group, with the shipping it was given');
    }

    public function test_checking_out_a_group_that_holds_one_countrys_rows_still_removes_its_shipping_choice(): void
    {
        $this->useOrderWiseShipping();
        $customer = $this->makeCustomer();
        $this->switchStorefrontsOn();
        $this->cartRow($customer->id, 1, 100, group: 'group-sa');
        $this->cartRow($customer->id, 2, 250, group: 'group-ae');
        DB::table('cart_shippings')->insert([['cart_group_id' => 'group-sa', 'shipping_method_id' => 1, 'shipping_cost' => 25], ['cart_group_id' => 'group-ae', 'shipping_method_id' => 1, 'shipping_cost' => 25]]);

        $this->asCustomer($customer)->withHeader('X-Storefront', 'SA')->getJson('/api/v1/customer/order/place')->assertOk();

        $this->assertSame(['group-ae'], DB::table('cart_shippings')->pluck('cart_group_id')->all());
    }

    public function test_a_restock_request_for_a_product_only_another_storefront_sells_is_refused_and_creates_nothing(): void
    {
        $this->makeTheRestockTables();
        $customer = $this->makeCustomer();
        $this->switchStorefrontsOn();
        $this->actingAs($customer, 'customer');
        $this->serveRequestFrom('SA');

        $response = $this->restockRequestFor(productId: 2);

        $this->assertSame('error', $response->getData(true)['status']);
        $this->assertSame(0, DB::table('restock_products')->count() + DB::table('restock_product_customers')->count());
    }

    public function test_while_storefronts_are_off_a_restock_request_for_a_product_that_is_not_active_is_taken_as_it_always_was(): void
    {
        $this->makeTheRestockTables();
        $customer = $this->makeCustomer();
        DB::table('products')->where('id', 1)->update(['status' => 0]);
        $this->actingAs($customer, 'customer');

        $response = $this->restockRequestFor(productId: 1);

        $this->assertSame('success', $response->getData(true)['status']);
        $this->assertSame(1, DB::table('restock_product_customers')->count());
    }

    public function test_a_restock_request_for_a_product_the_storefront_sells_is_taken(): void
    {
        $this->makeTheRestockTables();
        $customer = $this->makeCustomer();
        $this->switchStorefrontsOn();
        $this->actingAs($customer, 'customer');
        $this->serveRequestFrom('AE');

        $this->assertSame('success', $this->restockRequestFor(productId: 2)->getData(true)['status']);
    }

    private function restockRequestFor(int $productId): \Illuminate\Http\JsonResponse
    {
        return app(WebCartController::class)->addProductRestockRequest(Request::create('/cart/product-restock-request', 'POST', ['id' => $productId, 'product_variation_code' => '']));
    }

    private function makeTheRestockTables(): void
    {
        foreach (['restock_products', 'restock_product_customers', 'product_compares', 'wishlists'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('restock_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('variant')->nullable();
            $table->timestamps();
        });
        Schema::create('restock_product_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('restock_product_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('variant')->nullable();
            $table->timestamps();
        });
        Schema::create('product_compares', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->timestamps();
        });
        Schema::create('wishlists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->timestamps();
        });
    }
}
