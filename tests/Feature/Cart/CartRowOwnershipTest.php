<?php

namespace Tests\Feature\Cart;

use App\Http\Controllers\Web\CartController as WebCartController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Tests\Feature\Address\ApiAddressTestCase;

/**
 * A cart row can only be changed by the customer it belongs to, and only into a variation of its own product. The variation update used to
 * load the row by the id in the request and take the price from whichever product the request named, so a customer could give the row of
 * an expensive product the price of a cheap one, or change another customer's row. The select action took any row ids.
 */
class CartRowOwnershipTest extends ApiAddressTestCase
{
    private const EXPENSIVE_PRODUCT = 1;

    private const CHEAP_PRODUCT = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPipelineTables();
        $this->useTaxIncludedPrices();
        if (!defined('VIEW_FILE_NAMES')) {
            define('VIEW_FILE_NAMES', include resource_path('themes/default/file_names.php'));
        }
        View::getFinder()->prependLocation(base_path('tests/Support/views/storefront'));

        if (!Schema::hasColumn('carts', 'color')) {
            Schema::table('carts', function ($table) {
                $table->string('color')->nullable();
                $table->text('choices')->nullable();
                $table->text('variations')->nullable();
            });
        }
        if (!Schema::hasColumn('products', 'choice_options')) {
            Schema::table('products', fn ($table) => $table->text('choice_options')->nullable());
        }
        DB::table('products')->insert([
            ['id' => self::EXPENSIVE_PRODUCT, 'name' => 'Expensive', 'added_by' => 'admin', 'user_id' => 1, 'unit_price' => 100, 'current_stock' => 10, 'variation' => '[]', 'choice_options' => '[]', 'discount' => 0, 'discount_type' => 'flat'],
            ['id' => self::CHEAP_PRODUCT, 'name' => 'Cheap', 'added_by' => 'admin', 'user_id' => 1, 'unit_price' => 1, 'current_stock' => 10, 'discount' => 0, 'discount_type' => 'flat',
                'choice_options' => json_encode([['name' => 'choice_1', 'title' => 'Colour', 'options' => ['Red']]]),
                'variation' => json_encode([['type' => 'Red', 'price' => 1, 'sku' => 'c-red', 'qty' => 10]])],
        ]);
    }

    private function cartRowOf(User $customer, int $productId, float $price = 100): int
    {
        return DB::table('carts')->insertGetId([
            'customer_id' => $customer->id, 'is_guest' => 0, 'cart_group_id' => 'group-' . $customer->id, 'product_id' => $productId, 'quantity' => 1, 'price' => $price,
            'seller_id' => 1, 'seller_is' => 'admin', 'is_checked' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function updateVariation(User $customer, int $rowId, int $productId): array
    {
        $this->actingAs($customer, 'customer');

        return app(WebCartController::class)->update_variation(Request::create('/cart/update-variation', 'POST', [
            'id' => $rowId, 'product_id' => $productId, 'quantity' => 1, 'choice_1' => 'Red',
        ]));
    }

    public function test_a_customer_cannot_change_the_cart_row_of_another_customer(): void
    {
        $owner = $this->makeCustomer();
        $stranger = $this->makeCustomer();
        $row = $this->cartRowOf($owner, self::CHEAP_PRODUCT, price: 5);

        $result = $this->updateVariation($stranger, $row, self::CHEAP_PRODUCT);

        $this->assertSame(0, $result['status']);
        $this->assertEquals(5, DB::table('carts')->where('id', $row)->value('price'));
        $this->assertNull(DB::table('carts')->where('id', $row)->value('variant'));
    }

    public function test_a_cart_row_cannot_take_the_price_of_a_different_product(): void
    {
        $customer = $this->makeCustomer();
        $row = $this->cartRowOf($customer, self::EXPENSIVE_PRODUCT);

        $result = $this->updateVariation($customer, $row, self::CHEAP_PRODUCT);

        $this->assertSame(0, $result['status']);
        $this->assertEquals(100, DB::table('carts')->where('id', $row)->value('price'));
        $this->assertSame(self::EXPENSIVE_PRODUCT, (int) DB::table('carts')->where('id', $row)->value('product_id'));
    }

    public function test_a_row_that_no_longer_exists_is_refused_instead_of_failing(): void
    {
        $customer = $this->makeCustomer();

        $this->assertSame(0, $this->updateVariation($customer, 9999, self::CHEAP_PRODUCT)['status']);
    }

    public function test_a_customer_can_still_change_the_variation_of_their_own_row(): void
    {
        $customer = $this->makeCustomer();
        $row = $this->cartRowOf($customer, self::CHEAP_PRODUCT, price: 1);

        $result = $this->updateVariation($customer, $row, self::CHEAP_PRODUCT);

        $this->assertSame(1, $result['status']);
        $this->assertSame('Red', DB::table('carts')->where('id', $row)->value('variant'));
    }

    public function test_the_api_select_action_only_changes_the_callers_own_rows(): void
    {
        $owner = $this->makeCustomer();
        $caller = $this->makeCustomer();
        $ownersRow = $this->cartRowOf($owner, self::EXPENSIVE_PRODUCT);
        $callersRow = $this->cartRowOf($caller, self::EXPENSIVE_PRODUCT);

        $this->asCustomer($caller)->postJson('/api/v1/cart/select-cart-items', ['action' => 'unchecked', 'ids' => [$ownersRow, $callersRow]])->assertOk();

        $this->assertSame(1, (int) DB::table('carts')->where('id', $ownersRow)->value('is_checked'), 'the other customer\'s row is untouched');
        $this->assertSame(0, (int) DB::table('carts')->where('id', $callersRow)->value('is_checked'), 'the caller\'s own row is unticked');
    }
}
