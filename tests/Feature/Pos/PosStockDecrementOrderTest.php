<?php

namespace Tests\Feature\Pos;

use App\Enums\SessionKey;
use App\Http\Controllers\Admin\POS\POSOrderController as AdminPOSOrderController;
use App\Http\Controllers\RestAPI\v3\seller\POSController as SellerApiPOSController;
use App\Http\Controllers\Vendor\POS\POSOrderController as VendorPOSOrderController;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\KeepsTranslateOffLanguageFiles;

/**
 * A point-of-sale sale takes stock from its products in ascending product id order, whatever order the cart lists them in, the same
 * rule the storefront order applies to its product locks. Two sales holding the same products in opposite cart orders then wait for
 * each other instead of each holding a row the other needs.
 */
class PosStockDecrementOrderTest extends ApiAddressTestCase
{
    use KeepsTranslateOffLanguageFiles;

    private const SELLER_ID = 5;
    private const STOCK = 10;

    /** Cart order, highest product id first; each product with its own quantity. */
    private const CART_LINES = [3 => 3, 2 => 2, 1 => 1];

    private User $customer;

    /** @var list<int> product ids in the order their stock was decremented */
    private array $decrementedProductIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPipelineTables();
        $this->useTaxIncludedPrices();
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Paracetamol', 'added_by' => 'seller', 'user_id' => self::SELLER_ID, 'unit_price' => 30, 'current_stock' => self::STOCK],
            ['id' => 2, 'name' => 'Vitamin C', 'added_by' => 'seller', 'user_id' => self::SELLER_ID, 'unit_price' => 20, 'current_stock' => self::STOCK],
            ['id' => 3, 'name' => 'Zinc', 'added_by' => 'seller', 'user_id' => self::SELLER_ID, 'unit_price' => 10, 'current_stock' => self::STOCK],
        ]);
        $this->customer = $this->makeCustomer();
        $this->keepTranslateOffLanguageFiles();
        DB::listen(function (QueryExecuted $query): void {
            $this->recordStockDecrement(query: $query);
        });
    }

    protected function tearDown(): void
    {
        $this->assertLanguageFilesUntouched();

        parent::tearDown();
    }

    private function recordStockDecrement(QueryExecuted $query): void
    {
        $sql = $query->sql;
        if (!str_starts_with($sql, 'update "products"') || !str_contains($sql, '"current_stock" = "current_stock" -')) {
            return;
        }
        $placeholdersBeforeProductId = substr_count(substr($sql, 0, (int) strpos($sql, '"id" = ?')), '?');
        $this->decrementedProductIds[] = (int) $query->bindings[$placeholdersBeforeProductId];
    }

    private function cartAmount(): float
    {
        $unitPrices = DB::table('products')->pluck('unit_price', 'id');

        return (float) collect(self::CART_LINES)->map(fn (int $quantity, int $productId): float => $unitPrices[$productId] * $quantity)->sum();
    }

    private function assertStockWasTakenInAscendingProductIdOrder(): void
    {
        $this->assertSame([1, 2, 3], $this->decrementedProductIds, 'stock is decremented in ascending product id order');
        $this->assertSame(
            [1 => self::STOCK - 1, 2 => self::STOCK - 2, 3 => self::STOCK - 3],
            DB::table('products')->orderBy('id')->pluck('current_stock', 'id')->map(fn ($stock): int => (int) $stock)->all(),
            'each product loses exactly its own line quantity',
        );
        $this->assertSame(
            [1 => 1, 2 => 2, 3 => 3],
            DB::table('order_details')->orderBy('product_id')->pluck('qty', 'product_id')->map(fn ($quantity): int => (int) $quantity)->all(),
            'every cart line is written once with its quantity',
        );
        $this->assertSame(1, DB::table('orders')->count());
    }

    public function test_a_seller_app_sale_decrements_stock_in_ascending_product_id_order(): void
    {
        $lines = collect(self::CART_LINES)->map(fn (int $quantity, int $productId): array => [
            'id' => $productId, 'quantity' => $quantity, 'price' => 0, 'discounted_price' => 0, 'discount' => 0, 'applied_tax_amount' => 0,
            'variant' => null, 'variation' => [], 'coupon_discount' => 0, 'extra_discount' => 0,
        ])->values()->all();
        $input = [
            'customer_id' => $this->customer->id, 'cart' => [], 'modified_cart' => $lines, 'fulfillment' => 'instant',
            'payment_method' => 'cash', 'paid_amount' => $this->cartAmount(),
        ];
        $request = Request::create('/api/v3/seller/pos/place-order', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($input));
        $request['seller'] = (new Seller())->forceFill(['id' => self::SELLER_ID]);

        $response = app(SellerApiPOSController::class)->place_order($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStockWasTakenInAscendingProductIdOrder();
    }

    /** @return array<string, array{0: class-string}> */
    public static function panels(): array
    {
        return [
            'admin panel' => [AdminPOSOrderController::class],
            'vendor panel' => [VendorPOSOrderController::class],
        ];
    }

    #[DataProvider('panels')]
    public function test_a_panel_sale_decrements_stock_in_ascending_product_id_order(string $controller): void
    {
        $cartId = 'saved-customer-' . $this->customer->id;
        $unitPrices = DB::table('products')->pluck('unit_price', 'id');
        $lines = collect(self::CART_LINES)->map(fn (int $quantity, int $productId): array => [
            'id' => $productId, 'customerId' => $this->customer->id, 'customerOnHold' => false, 'quantity' => $quantity,
            'price' => (float) $unitPrices[$productId], 'name' => 'Product ' . $productId, 'productType' => 'physical', 'image' => null,
            'discount' => 0, 'variant' => '', 'variations' => [],
        ])->values()->all();
        session([SessionKey::CURRENT_USER => $cartId, SessionKey::CART_NAME => [$cartId], $cartId => $lines]);
        $amount = $this->cartAmount();
        $request = Request::create('/pos/place-order', 'POST', ['amount' => $amount, 'paid_amount' => $amount, 'type' => 'cash', 'fulfillment' => 'instant']);

        app($controller)->placeOrder($request);

        $this->assertStockWasTakenInAscendingProductIdOrder();
    }
}
