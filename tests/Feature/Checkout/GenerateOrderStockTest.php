<?php

namespace Tests\Feature\Checkout;

use App\Exceptions\InsufficientStockException;
use App\Models\User;
use App\Utils\OrderManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Address\ApiAddressTestCase;

/**
 * The storefront order writer never takes stock that is not there: a product-total or a single-variant shortfall refuses the whole
 * order and leaves stock as it was, the post-payment path (allow_oversell) still takes it, and every product of a cart is locked in id
 * order so two carts cannot lock the same rows in opposite orders.
 */
class GenerateOrderStockTest extends ApiAddressTestCase
{
    private const PRODUCT = 1;

    private const SECOND_PRODUCT = 2;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPipelineTables();
        $this->useTaxIncludedPrices();
        if (!Schema::hasColumn('carts', 'variations')) {
            Schema::table('carts', static function (Blueprint $table): void {
                $table->longText('variations')->nullable();
            });
        }
        $this->customer = $this->makeCustomer();
    }

    private function addProduct(int $productId, int $stock, ?array $variants = null): void
    {
        DB::table('products')->insert([
            'id' => $productId, 'name' => 'Product ' . $productId, 'added_by' => 'admin', 'user_id' => 1, 'unit_price' => 100,
            'current_stock' => $stock, 'variation' => json_encode($variants ?? []),
        ]);
    }

    private function addCartLine(int $productId, int $quantity, ?string $variant = null): void
    {
        DB::table('carts')->insert([
            'customer_id' => $this->customer->id, 'is_guest' => false, 'cart_group_id' => 'group-' . $this->customer->id . 'c', 'product_id' => $productId,
            'variant' => $variant, 'variations' => json_encode($variant === null ? [] : ['color' => $variant]), 'quantity' => $quantity, 'price' => 100,
            'seller_id' => 1, 'seller_is' => 'admin', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function placeOrder(array $data = []): array
    {
        request()->merge(['user' => $this->customer]);
        $address = $this->makeLegacyAddress($this->customer->id);

        return OrderManager::generateOrder(data: $data + [
            'is_guest' => 0, 'customer_id' => $this->customer->id, 'order_status' => 'pending', 'payment_method' => 'cash_on_delivery',
            'payment_status' => 'unpaid', 'transaction_ref' => '', 'requestObj' => request(), 'address_id' => $address->id,
        ]);
    }

    private function stockOf(int $productId): int
    {
        return (int) DB::table('products')->where('id', $productId)->value('current_stock');
    }

    private function variantsOf(int $productId): array
    {
        return json_decode((string) DB::table('products')->where('id', $productId)->value('variation'), true);
    }

    private function assertNothingWasWritten(): void
    {
        $this->assertSame(0, DB::table('orders')->count(), 'no order row');
        $this->assertSame(0, DB::table('order_details')->count(), 'no order line');
        $this->assertSame(0, DB::table('order_status_histories')->count(), 'no status history');
    }

    /** One vendor cart of one line, in the shape processOrderGenerateData() hands addOrderDetailsData(). */
    private function vendorCartFor(int $productId, int $quantity, ?string $variant = null): array
    {
        return [
            'stock_policy' => 'reject',
            'applied_tax_cart_list' => [],
            'cart_list' => [[
                'id' => 1, 'product_id' => $productId, 'seller_id' => 1, 'seller_is' => 'admin', 'quantity' => $quantity, 'price' => 100,
                'variant' => $variant, 'variations' => json_encode($variant === null ? [] : ['color' => $variant]),
            ]],
        ];
    }

    public function test_the_line_writer_throws_insufficient_stock_when_more_than_current_stock_is_ordered(): void
    {
        $this->addProduct(productId: self::PRODUCT, stock: 2);

        try {
            OrderManager::addOrderDetailsData(orderId: 100001, vendorCart: $this->vendorCartFor(productId: self::PRODUCT, quantity: 3));
            $this->fail('Ordering 3 of a product with 2 in stock must throw.');
        } catch (InsufficientStockException $exception) {
            $this->assertSame(self::PRODUCT, (int) $exception->productId);
            $this->assertSame(3, $exception->requested);
        }

        $this->assertSame(2, $this->stockOf(self::PRODUCT));
        $this->assertSame(0, DB::table('order_details')->count());
    }

    public function test_an_order_for_more_than_current_stock_is_refused_and_leaves_stock_unchanged(): void
    {
        $this->addProduct(productId: self::PRODUCT, stock: 2);
        $this->addCartLine(productId: self::PRODUCT, quantity: 3);

        $this->assertSame([], $this->placeOrder(), 'refused: no order ids');

        $this->assertSame(2, $this->stockOf(self::PRODUCT));
        $this->assertNothingWasWritten();
    }

    public function test_an_order_of_exactly_current_stock_is_accepted_and_takes_all_of_it(): void
    {
        $this->addProduct(productId: self::PRODUCT, stock: 3);
        $this->addCartLine(productId: self::PRODUCT, quantity: 3);

        $this->assertCount(1, $this->placeOrder());

        $this->assertSame(0, $this->stockOf(self::PRODUCT));
    }

    public function test_the_line_writer_refuses_a_variant_shortfall_that_the_product_total_would_mask(): void
    {
        $this->addProduct(productId: self::PRODUCT, stock: 10, variants: [['type' => 'Red', 'qty' => 1, 'price' => 100], ['type' => 'Blue', 'qty' => 9, 'price' => 100]]);

        $this->expectException(InsufficientStockException::class);

        OrderManager::addOrderDetailsData(orderId: 100001, vendorCart: $this->vendorCartFor(productId: self::PRODUCT, quantity: 2, variant: 'Red'));
    }

    public function test_an_order_for_more_of_one_variant_than_it_holds_is_refused_and_no_variant_moves(): void
    {
        $variants = [['type' => 'Red', 'qty' => 1, 'price' => 100], ['type' => 'Blue', 'qty' => 9, 'price' => 100]];
        $this->addProduct(productId: self::PRODUCT, stock: 10, variants: $variants);
        $this->addCartLine(productId: self::PRODUCT, quantity: 2, variant: 'Red');

        $this->assertSame([], $this->placeOrder());

        $this->assertSame(10, $this->stockOf(self::PRODUCT));
        $this->assertSame($variants, $this->variantsOf(self::PRODUCT));
        $this->assertNothingWasWritten();
    }

    public function test_the_post_payment_path_allow_oversell_takes_the_stock_even_when_it_is_not_there(): void
    {
        $this->addProduct(productId: self::PRODUCT, stock: 1, variants: [['type' => 'Red', 'qty' => 1, 'price' => 100]]);
        $this->addCartLine(productId: self::PRODUCT, quantity: 3, variant: 'Red');

        $orderIds = $this->placeOrder(data: ['stock_policy' => 'allow_oversell', 'payment_status' => 'paid', 'order_status' => 'confirmed']);

        $this->assertCount(1, $orderIds, 'the customer has paid: the order is written');
        $this->assertSame(-2, $this->stockOf(self::PRODUCT), 'the full quantity is decremented, unconditionally');
        $this->assertSame(-2, $this->variantsOf(self::PRODUCT)[0]['qty']);
        $this->assertSame(3, (int) DB::table('order_details')->where('order_id', $orderIds[0])->value('qty'));
    }

    public function test_an_unknown_stock_policy_is_treated_as_reject(): void
    {
        $this->addProduct(productId: self::PRODUCT, stock: 1);
        $this->addCartLine(productId: self::PRODUCT, quantity: 2);

        $this->assertSame([], $this->placeOrder(data: ['stock_policy' => 'anything-else']));

        $this->assertSame(1, $this->stockOf(self::PRODUCT));
    }

    public function test_one_short_line_refuses_the_whole_order_and_the_other_lines_keep_their_stock(): void
    {
        $this->addProduct(productId: self::PRODUCT, stock: 5);
        $this->addProduct(productId: self::SECOND_PRODUCT, stock: 1);
        $this->addCartLine(productId: self::PRODUCT, quantity: 2);
        $this->addCartLine(productId: self::SECOND_PRODUCT, quantity: 2);

        $this->assertSame([], $this->placeOrder());

        $this->assertSame([5, 1], [$this->stockOf(self::PRODUCT), $this->stockOf(self::SECOND_PRODUCT)]);
        $this->assertNothingWasWritten();
    }

    public function test_every_product_of_the_cart_is_locked_up_front_in_ascending_id_order(): void
    {
        $this->addProduct(productId: self::SECOND_PRODUCT, stock: 5);
        $this->addProduct(productId: self::PRODUCT, stock: 5);
        $this->addCartLine(productId: self::SECOND_PRODUCT, quantity: 1);
        $this->addCartLine(productId: self::PRODUCT, quantity: 1);

        DB::enableQueryLog();
        $this->assertCount(1, $this->placeOrder());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $lockQueries = array_values(array_filter($queries, static fn (array $query): bool => preg_match('/from "products" where "id" in \(\?, \?\) order by "id" asc/', $query['query']) === 1));
        $this->assertCount(1, $lockQueries, 'one up-front lock over the whole cart');
        $this->assertSame([self::PRODUCT, self::SECOND_PRODUCT], array_map('intval', $lockQueries[0]['bindings']), 'ascending ids, whatever the cart order');
        $this->assertSame([4, 4], [$this->stockOf(self::PRODUCT), $this->stockOf(self::SECOND_PRODUCT)]);
    }
}
