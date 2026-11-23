<?php

namespace Tests\Feature\Pos;

use App\Http\Controllers\RestAPI\v3\seller\POSController;
use App\Models\Order;
use App\Models\Seller;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The THIRD till: the one in the seller app, behind `POST /api/v3/seller/pos/place-order`.
 *
 * The two panel tills are covered by {@see PosTillStockTest}, which drives their own HTTP routes. This one is driven through
 * the controller action with a `Request` built exactly as the seller API middleware leaves it — `$request['seller']` set to
 * the signed-in seller, the body as JSON — which is the pattern the repository's own v3 seller tests already use
 * (`tests/Feature/Address/ApiSellerAddressTest::placeOrder`). It therefore says NOTHING about who may reach the route: the
 * `seller_can:orders.manage` middleware is not in the path. What it does say is what the sale itself does to the money, the
 * shelf and the order rows, which is what this endpoint was getting wrong.
 *
 * What it was getting wrong, measured on the unmodified endpoint before any of this (see the pull request body for the
 * figures):
 *  - the wallet was debited BEFORE the lines were checked, and outside any transaction, so a line the shelf could not cover
 *    answered `product_not_available` with the customer's money already gone;
 *  - a request carrying no `modified_cart` skipped the whole line block and still wrote an order row: no lines,
 *    `order_amount` 0, `paid_amount` whatever was posted, HTTP 200;
 *  - a shortfall was floored at zero and sold anyway, and the variant was floored with `max(0, ...)`, so the variant
 *    quantities stopped summing to `current_stock`;
 *  - nothing was in a transaction, so a failure after the stock was taken left stock gone with no order to show for it.
 */
class SellerAppTillStockTest extends PosTillTestCase
{
    private const CUSTOMER_ID = 40;

    /** A sale posted at the seller app's till, as the app posts it. */
    private function placeSaleAtSellerAppTill(array $lines, array $overrides = []): JsonResponse
    {
        $body = array_merge([
            'customer_id' => 0,
            'cart' => [],
            'payment_method' => 'cash',
            'paid_amount' => 0,
            'fulfillment' => 'instant',
            'total_tax_amount' => 0,
            'modified_cart' => $lines,
        ], $overrides);

        $request = Request::create(
            '/api/v3/seller/pos/place-order',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($body)
        );
        $request['seller'] = (new Seller())->forceFill(['id' => self::SELLER_ID, 'f_name' => 'Vendor']);

        return app(POSController::class)->place_order($request);
    }

    /** One line of the app's `modified_cart`, with every key the endpoint reads. */
    private function line(int $productId, int $quantity, float $price = 100.0, ?string $variant = null): array
    {
        return [
            'id' => $productId,
            'quantity' => $quantity,
            'price' => $price,
            'discounted_price' => $price,
            'discount' => 0,
            'applied_tax_amount' => 0,
            'coupon_discount' => 0,
            'extra_discount' => 0,
            'variant' => $variant,
            'variation' => [],
        ];
    }

    /** @return array<string, mixed> the decoded answer the app receives */
    private function answer(JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true) ?: [];
    }

    private function givenWalletCustomer(float $balance): void
    {
        DB::table('users')->insert([
            'id' => self::CUSTOMER_ID,
            'f_name' => 'Walk',
            'l_name' => 'In',
            'email' => 'walkin@example.test',
            'phone' => '0551112222',
            'wallet_balance' => $balance,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('business_settings')->updateOrInsert(['type' => 'wallet_status'], ['value' => 1]);
    }

    private function walletBalance(): float
    {
        return (float) User::query()->whereKey(self::CUSTOMER_ID)->value('wallet_balance');
    }

    public function test_a_lawful_sale_goes_through_and_takes_its_stock_from_the_shelf(): void
    {
        $this->shelfProduct(id: 701, stock: 10, till: self::VENDOR_TILL);

        $response = $this->placeSaleAtSellerAppTill([$this->line(701, 3)], ['paid_amount' => 300]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['order_id'], array_keys($this->answer($response)));
        $this->assertSame(
            ['orders' => 1, 'order_details' => 1, 'charged' => 300.0, 'current_stock' => 7, 'movements' => [['qty_change' => -3, 'balance_after' => 7]]],
            $this->readings(701)
        );
    }

    public function test_a_line_the_shelf_cannot_cover_is_refused_and_nothing_is_charged_or_taken(): void
    {
        $this->shelfProduct(id: 701, stock: 2, till: self::VENDOR_TILL);

        $response = $this->placeSaleAtSellerAppTill([$this->line(701, 5)], ['paid_amount' => 500]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($this->answer($response)['product_not_available']);
        $this->assertSame(
            'Product 701 ' . translate('not_available_for_order_place'),
            $this->answer($response)['message'],
            'the app reads the product name out of this message, so the shape it already parses must not change'
        );
        $this->assertSame(
            ['orders' => 0, 'order_details' => 0, 'charged' => null, 'current_stock' => 2, 'movements' => []],
            $this->readings(701),
            'the shelf, the order rows and the money are all exactly as they were'
        );
    }

    public function test_a_sale_with_no_lines_writes_no_order_instead_of_an_empty_one_charged_for(): void
    {
        $this->shelfProduct(id: 701, stock: 10, till: self::VENDOR_TILL);

        $response = $this->placeSaleAtSellerAppTill([], ['paid_amount' => 100]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($this->answer($response)['product_not_available']);
        $this->assertSame(0, Order::query()->count(), 'the till used to write an order row with no lines at all');
        $this->assertSame(0, DB::table('order_details')->count());
        $this->assertSame(10, (int) DB::table('products')->where('id', 701)->value('current_stock'));
    }

    public function test_a_modified_cart_the_app_did_not_send_at_all_writes_no_order_either(): void
    {
        $this->shelfProduct(id: 701, stock: 10, till: self::VENDOR_TILL);

        $response = $this->placeSaleAtSellerAppTill([], ['modified_cart' => null, 'paid_amount' => 100]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($this->answer($response)['product_not_available']);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, DB::table('order_details')->count());
    }

    public function test_a_refused_sale_leaves_the_wallet_balance_where_it_was_and_writes_no_wallet_row(): void
    {
        $this->givenWalletCustomer(balance: 900.0);
        $this->shelfProduct(id: 701, stock: 2, till: self::VENDOR_TILL);

        $response = $this->placeSaleAtSellerAppTill(
            [$this->line(701, 5)],
            ['customer_id' => self::CUSTOMER_ID, 'payment_method' => 'wallet', 'paid_amount' => 500]
        );

        $this->assertTrue($this->answer($response)['product_not_available']);
        $this->assertSame(900.0, $this->walletBalance(), 'the debit used to happen before the line was ever checked');
        $this->assertSame(0, DB::table('wallet_transactions')->count());
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(2, (int) DB::table('products')->where('id', 701)->value('current_stock'));
    }

    public function test_an_accepted_wallet_sale_debits_the_balance_once(): void
    {
        $this->givenWalletCustomer(balance: 900.0);
        $this->shelfProduct(id: 701, stock: 10, till: self::VENDOR_TILL);

        $response = $this->placeSaleAtSellerAppTill(
            [$this->line(701, 3)],
            ['customer_id' => self::CUSTOMER_ID, 'payment_method' => 'wallet', 'paid_amount' => 300]
        );

        $this->assertArrayHasKey('order_id', $this->answer($response));
        $this->assertSame(600.0, $this->walletBalance());
        $this->assertSame(1, DB::table('wallet_transactions')->count());
        $this->assertSame(300.0, (float) DB::table('wallet_transactions')->value('debit'));
        $this->assertSame(1, Order::query()->count());
    }

    public function test_a_wallet_sale_the_balance_cannot_cover_is_refused_before_the_shelf_is_touched(): void
    {
        $this->givenWalletCustomer(balance: 100.0);
        $this->shelfProduct(id: 701, stock: 10, till: self::VENDOR_TILL);

        $response = $this->placeSaleAtSellerAppTill(
            [$this->line(701, 3)],
            ['customer_id' => self::CUSTOMER_ID, 'payment_method' => 'wallet', 'paid_amount' => 300]
        );

        $this->assertTrue($this->answer($response)['need_balance']);
        $this->assertSame(100.0, $this->walletBalance());
        $this->assertSame(0, DB::table('wallet_transactions')->count());
        $this->assertSame(
            ['orders' => 0, 'order_details' => 0, 'charged' => null, 'current_stock' => 10, 'movements' => []],
            $this->readings(701)
        );
    }

    public function test_a_wallet_sale_whose_debit_does_not_happen_is_refused_rather_than_stored_as_paid(): void
    {
        $this->givenWalletCustomer(balance: 900.0);
        DB::table('business_settings')->updateOrInsert(['type' => 'wallet_status'], ['value' => 0]);
        $this->shelfProduct(id: 701, stock: 10, till: self::VENDOR_TILL);

        $response = $this->placeSaleAtSellerAppTill(
            [$this->line(701, 3)],
            ['customer_id' => self::CUSTOMER_ID, 'payment_method' => 'wallet', 'paid_amount' => 300]
        );

        $this->assertTrue($this->answer($response)['need_balance'], 'the wallet writer refuses while the add-on is off');
        $this->assertSame(900.0, $this->walletBalance());
        $this->assertSame(0, DB::table('wallet_transactions')->count());
        $this->assertSame(0, Order::query()->count(), 'an order stored as paid with nothing taken is the defect this closes');
        $this->assertSame(10, (int) DB::table('products')->where('id', 701)->value('current_stock'));
    }

    public function test_a_line_naming_a_product_that_is_not_there_refuses_the_whole_sale(): void
    {
        $this->shelfProduct(id: 701, stock: 10, till: self::VENDOR_TILL);

        $response = $this->placeSaleAtSellerAppTill([$this->line(701, 2), $this->line(999, 1)], ['paid_amount' => 300]);

        $this->assertTrue($this->answer($response)['product_not_available']);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, DB::table('order_details')->count(), 'the missing line used to be skipped and the rest sold');
        $this->assertSame(10, (int) DB::table('products')->where('id', 701)->value('current_stock'), 'the coverable line is given back too');
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_one_short_line_among_coverable_ones_refuses_the_whole_sale(): void
    {
        $this->shelfProduct(id: 701, stock: 50, till: self::VENDOR_TILL);
        $this->shelfProduct(id: 702, stock: 1, till: self::VENDOR_TILL);

        $response = $this->placeSaleAtSellerAppTill([$this->line(701, 2), $this->line(702, 5)], ['paid_amount' => 700]);

        $this->assertTrue($this->answer($response)['product_not_available']);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(50, (int) DB::table('products')->where('id', 701)->value('current_stock'));
        $this->assertSame(1, (int) DB::table('products')->where('id', 702)->value('current_stock'));
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_a_negative_quantity_is_refused_instead_of_adding_stock_and_logging_it_as_a_sale(): void
    {
        $this->shelfProduct(id: 701, stock: 5, till: self::VENDOR_TILL);

        $response = $this->placeSaleAtSellerAppTill([$this->line(701, -4)], ['paid_amount' => 100]);

        $this->assertTrue($this->answer($response)['product_not_available']);
        $this->assertSame(
            ['orders' => 0, 'order_details' => 0, 'charged' => null, 'current_stock' => 5, 'movements' => []],
            $this->readings(701),
            'a negative line used to walk through the conditional decrement, ADD stock and log the addition as a sale'
        );
    }

    public function test_a_variant_short_of_the_line_refuses_and_the_variants_still_sum_to_the_stock(): void
    {
        $this->shelfProduct(
            id: 701,
            stock: 10,
            till: self::VENDOR_TILL,
            variation: json_encode([
                ['type' => 'Red', 'price' => 100, 'qty' => 1, 'sku' => 'R'],
                ['type' => 'Blue', 'price' => 100, 'qty' => 9, 'sku' => 'B'],
            ])
        );

        $response = $this->placeSaleAtSellerAppTill([$this->line(701, 4, 100.0, 'Red')], ['paid_amount' => 400]);

        $this->assertTrue($this->answer($response)['product_not_available']);
        $this->assertSame(['Red' => 1, 'Blue' => 9], $this->variantQuantities(701));
        $this->assertSame(10, (int) DB::table('products')->where('id', 701)->value('current_stock'));
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_a_lawful_variant_sale_takes_the_variant_and_the_total_together(): void
    {
        $this->shelfProduct(
            id: 701,
            stock: 10,
            till: self::VENDOR_TILL,
            variation: json_encode([
                ['type' => 'Red', 'price' => 100, 'qty' => 4, 'sku' => 'R'],
                ['type' => 'Blue', 'price' => 100, 'qty' => 6, 'sku' => 'B'],
            ])
        );

        $response = $this->placeSaleAtSellerAppTill([$this->line(701, 3, 100.0, 'Red')], ['paid_amount' => 300]);

        $this->assertArrayHasKey('order_id', $this->answer($response));
        $this->assertSame(['Red' => 1, 'Blue' => 6], $this->variantQuantities(701));
        $this->assertSame(7, (int) DB::table('products')->where('id', 701)->value('current_stock'));
        $this->assertSame(7, array_sum($this->variantQuantities(701)), 'the variants sum to the stock, which the floored write broke');
    }

    public function test_a_wallet_sale_for_exactly_the_balance_goes_through_and_lands_on_nothing(): void
    {
        $this->givenWalletCustomer(balance: 300.0);
        $this->shelfProduct(id: 701, stock: 10, till: self::VENDOR_TILL);

        $response = $this->placeSaleAtSellerAppTill(
            [$this->line(701, 3)],
            ['customer_id' => self::CUSTOMER_ID, 'payment_method' => 'wallet', 'paid_amount' => 300]
        );

        $this->assertArrayHasKey('order_id', $this->answer($response), 'spending the whole balance is a lawful sale, not a shortfall');
        $this->assertSame(0.0, $this->walletBalance());
        $this->assertSame(1, DB::table('wallet_transactions')->count());
    }

    public function test_a_sale_of_exactly_the_stock_on_hand_goes_through_and_empties_the_shelf(): void
    {
        $this->shelfProduct(id: 701, stock: 3, till: self::VENDOR_TILL);

        $response = $this->placeSaleAtSellerAppTill([$this->line(701, 3)], ['paid_amount' => 300]);

        $this->assertArrayHasKey('order_id', $this->answer($response));
        $this->assertSame(
            ['orders' => 1, 'order_details' => 1, 'charged' => 300.0, 'current_stock' => 0, 'movements' => [['qty_change' => -3, 'balance_after' => 0]]],
            $this->readings(701)
        );
    }

    public function test_the_sale_lands_in_the_stock_history_of_the_seller_who_made_it(): void
    {
        $this->shelfProduct(id: 701, stock: 10, till: self::VENDOR_TILL);

        $this->placeSaleAtSellerAppTill([$this->line(701, 2)], ['paid_amount' => 200]);

        $movement = StockMovement::query()->where('product_id', 701)->firstOrFail();
        $this->assertSame((string) self::SELLER_ID, (string) $movement->seller_id);
        $this->assertSame('pos_order', $movement->reference_type);
        $this->assertSame((int) Order::query()->value('id'), (int) $movement->reference_id);
    }
}
