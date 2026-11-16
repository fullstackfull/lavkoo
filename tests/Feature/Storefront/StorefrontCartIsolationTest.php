<?php

namespace Tests\Feature\Storefront;

use App\Models\Cart;
use App\Models\Scopes\StorefrontCartScope;
use App\Models\User;
use App\Services\Fulfillment\CheckoutCodGuard;
use App\Services\Fulfillment\FulfillmentSchema;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;

/**
 * A cart only holds, shows and changes the rows of the storefront it is used from. A customer who shops two countries has two carts, and
 * an action in one (adding, ticking, removing, checking out) never touches the other. While storefronts are off, or outside a storefront
 * request, the cart is exactly what it was.
 */
class StorefrontCartIsolationTest extends ApiAddressTestCase
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

    private function serveRequestFrom(string $country): void
    {
        app()->instance(StorefrontContext::class, StorefrontResolver::fromConfig()->context(explicitChoice: $country));
    }

    private function customerWithACartInEachCountry(): User
    {
        $customer = $this->makeCustomer();
        $this->switchStorefrontsOn();

        $this->asCustomer($customer)->withHeader('X-Storefront', 'SA')->postJson('/api/v1/cart/add', ['id' => 1, 'quantity' => 1])->assertOk()->assertJsonPath('status', 1);
        $this->asCustomer($customer)->withHeader('X-Storefront', 'AE')->postJson('/api/v1/cart/add', ['id' => 2, 'quantity' => 1])->assertOk()->assertJsonPath('status', 1);
        foreach (DB::table('carts')->pluck('cart_group_id') as $group) {
            DB::table('cart_shippings')->insert(['cart_group_id' => $group, 'shipping_method_id' => 1, 'shipping_cost' => 25]);
        }

        return $customer;
    }

    /** What an older client or a script leaves behind: both countries' rows in one group, with one shipping choice. */
    private function customerWithOneMixedGroup(): User
    {
        $customer = $this->makeCustomer();
        $this->switchStorefrontsOn();
        $this->cartRow($customer->id, 1, 100);
        $this->cartRow($customer->id, 2, 250);
        DB::table('cart_shippings')->insert(['cart_group_id' => 'group-' . $customer->id . 'c', 'shipping_method_id' => 1, 'shipping_cost' => 25]);

        return $customer;
    }

    public function test_adding_from_two_storefronts_makes_one_cart_group_for_each(): void
    {
        $this->customerWithACartInEachCountry();

        $groups = DB::table('carts')->orderBy('product_id')->pluck('cart_group_id', 'product_id')->all();

        $this->assertCount(2, $groups);
        $this->assertNotSame($groups[1], $groups[2], 'the two countries must not share a cart group');
    }

    public function test_a_second_product_of_the_same_country_joins_the_cart_group_of_that_country(): void
    {
        $customer = $this->makeCustomer();
        $this->switchStorefrontsOn();
        DB::table('products')->insert(DB::table('products')->where('id', 1)->get()->map(fn ($row) => array_merge((array) $row, ['id' => 3, 'slug' => 'second-sa']))->all());

        foreach ([1, 3] as $productId) {
            $this->asCustomer($customer)->withHeader('X-Storefront', 'SA')->postJson('/api/v1/cart/add', ['id' => $productId, 'quantity' => 1])->assertJsonPath('status', 1);
        }

        $this->assertSame(1, DB::table('carts')->distinct()->count('cart_group_id'));
    }

    public function test_a_cart_shows_only_the_rows_of_the_storefront_it_is_used_from(): void
    {
        $this->customerWithOneMixedGroup();

        $this->serveRequestFrom('SA');
        $this->assertSame([1], Cart::query()->orderBy('product_id')->pluck('product_id')->map(fn ($id) => (int) $id)->all());

        $this->serveRequestFrom('AE');
        $this->assertSame([2], Cart::query()->orderBy('product_id')->pluck('product_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_a_request_that_is_not_a_storefront_request_sees_every_row(): void
    {
        $this->customerWithOneMixedGroup();

        $this->assertSame([1, 2], Cart::query()->orderBy('product_id')->pluck('product_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_while_storefronts_are_off_every_row_is_seen_and_the_query_is_unchanged(): void
    {
        $this->customerWithOneMixedGroup();
        config(['gcc.storefronts.enabled' => false]);
        $this->serveRequestFrom('SA');

        $this->assertSame([1, 2], Cart::query()->orderBy('product_id')->pluck('product_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(Cart::query()->withoutGlobalScope(StorefrontCartScope::class)->toSql(), Cart::query()->toSql());
    }

    public function test_checking_out_in_one_storefront_leaves_the_cart_of_the_other_untouched(): void
    {
        $customer = $this->customerWithACartInEachCountry();

        $this->asCustomer($customer)->withHeader('X-Storefront', 'SA')->getJson(self::COD)->assertOk();

        $this->assertSame([1], DB::table('order_details')->pluck('product_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([2], $this->productsInTheCart(), 'the Emirati row is still in the cart');
        $this->assertSame(1, DB::table('cart_shippings')->count(), 'and its shipping choice with it');
    }

    public function test_checking_out_a_legacy_mixed_group_orders_and_deletes_only_the_storefronts_rows(): void
    {
        $customer = $this->customerWithOneMixedGroup();

        $this->asCustomer($customer)->withHeader('X-Storefront', 'AE')->getJson(self::COD)->assertOk();

        $this->assertSame([2], DB::table('order_details')->pluck('product_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([1], $this->productsInTheCart());
    }

    public function test_removing_everything_in_one_storefront_keeps_the_other_storefronts_rows(): void
    {
        $customer = $this->customerWithACartInEachCountry();

        $this->asCustomer($customer)->withHeader('X-Storefront', 'SA')->deleteJson('/api/v1/cart/remove-all', ['key' => 1])->assertOk();

        $this->assertSame([2], $this->productsInTheCart());
    }

    public function test_ticking_cart_items_in_one_storefront_cannot_reach_a_row_of_the_other(): void
    {
        $customer = $this->customerWithACartInEachCountry();
        $emiratiRow = (int) DB::table('carts')->where('product_id', 2)->value('id');

        $this->asCustomer($customer)->withHeader('X-Storefront', 'SA')->postJson('/api/v1/cart/select-cart-items', ['action' => 'unchecked', 'ids' => [$emiratiRow]]);

        $this->assertSame(1, (int) DB::table('carts')->where('id', $emiratiRow)->value('is_checked'));
    }

    public function test_buying_now_in_one_storefront_does_not_untick_the_other_storefronts_rows(): void
    {
        $customer = $this->makeCustomer();
        $this->switchStorefrontsOn();
        $this->cartRow($customer->id, 2, 250, group: 'group-ae');

        $this->asCustomer($customer)->withHeader('X-Storefront', 'SA')->postJson('/api/v1/cart/add', ['id' => 1, 'quantity' => 1, 'buy_now' => 1, 'product_variation_code' => ''])->assertOk();

        $this->assertSame(1, (int) DB::table('carts')->where('product_id', 2)->value('is_checked'));
    }

    public function test_the_cash_on_delivery_guard_judges_only_the_storefronts_rows(): void
    {
        $this->customerWithOneMixedGroup();
        $this->makeTheFulfillmentSchemaReady();
        DB::table('products')->where('id', 2)->update(['fulfillment_provider' => 'm5azn']);
        $group = (string) DB::table('carts')->value('cart_group_id');

        $this->serveRequestFrom('SA');
        $this->assertNull(app(CheckoutCodGuard::class)->blockedReason([$group]), 'the Saudi basket is the shop\'s own product only');

        $this->serveRequestFrom('AE');
        $this->assertNull(app(CheckoutCodGuard::class)->blockedReason([$group]), 'and the Emirati basket one provider only');
    }

    public function test_the_cash_on_delivery_guard_judges_a_whole_group_when_the_request_is_not_a_storefront_request(): void
    {
        $this->customerWithOneMixedGroup();
        $this->makeTheFulfillmentSchemaReady();
        DB::table('products')->where('id', 2)->update(['fulfillment_provider' => 'm5azn']);
        $group = (string) DB::table('carts')->value('cart_group_id');

        $this->assertNotNull(app(CheckoutCodGuard::class)->blockedReason([$group]));
    }

    public function test_the_price_and_stock_of_another_storefronts_product_are_not_answered_by_the_cart(): void
    {
        $this->switchStorefrontsOn();

        $this->withHeader('X-Storefront', 'SA')->postJson('/cart/variant_price', ['id' => 2, 'quantity' => 1, 'product_variation_code' => ''])->assertNotFound();
    }

    private function makeTheFulfillmentSchemaReady(): void
    {
        foreach (['provider_fulfillments', 'provider_fulfillment_items'] as $table) {
            Schema::dropIfExists($table);
            Schema::create($table, fn (Blueprint $blueprint) => $blueprint->id());
        }
        Schema::table('products', fn (Blueprint $blueprint) => $blueprint->string('fulfillment_provider')->nullable());
        FulfillmentSchema::flush();
    }
}
