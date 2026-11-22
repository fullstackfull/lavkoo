<?php

namespace Tests\Feature\Pos;

use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Enums\SessionKey;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Marketplace\InventoryService;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * The two panel tills, driven through their own place-order routes: a sale is refused when the shelf cannot cover it, the shelf
 * is left exactly as it was, and the movement row never says something the shelf did not do.
 *
 * Until this test the tills did the opposite. The conditional decrement they already had refused to take stock that was not
 * there, and the code then set `current_stock` to 0 and carried on: the order line was written, the customer was charged for
 * units the shop did not have, and the movement row read "minus 5, balance after 0" when the balance before was 2 — two columns
 * of one row contradicting each other, so the ledger could not be replayed. Measured through these routes on both engines:
 * stock 2 and a line of 5 gave one order, 500.00 charged, `current_stock` 0 and a movement of -5 with balance_after 0.
 *
 * The rule the sale follows now is the web checkout's, not a second one invented for the till: `InventoryService::takeForSale`
 * is the conditional decrement, the variant guard and the movement row the checkout's own path uses, and a line it cannot
 * cover throws `InsufficientStockException` inside the sale's transaction, so the whole sale rolls back.
 */
class PosTillStockTest extends PosTillTestCase
{
    /** @return array<string, array{string}> */
    public static function tills(): array
    {
        return ['admin till' => [self::ADMIN_TILL], 'vendor till' => [self::VENDOR_TILL]];
    }

    // ---- what the shelf can cover is still sold -------------------------------------------------------------------------

    #[DataProvider('tills')]
    public function test_a_sale_the_shelf_can_cover_is_placed_takes_its_stock_and_records_what_it_took(string $till): void
    {
        $this->shelfProduct(id: 601, stock: 10, till: $till);
        $this->givenCartAtTill($till, [601 => 3]);

        $this->placeSaleAtTill($till, amount: 300.0);

        $this->assertSame(['success'], $this->toastTypes());
        $this->assertSame([
            'orders' => 1,
            'order_details' => 1,
            'charged' => 300.0,
            'current_stock' => 7,
            'movements' => [['qty_change' => -3, 'balance_after' => 7]],
        ], $this->readings(601));
    }

    #[DataProvider('tills')]
    public function test_a_variant_sale_the_shelf_can_cover_leaves_the_variants_still_summing_to_the_stock(string $till): void
    {
        $this->shelfProduct(id: 602, stock: 10, till: $till, variation: json_encode([
            ['type' => 'Red', 'price' => 100, 'qty' => 4],
            ['type' => 'Blue', 'price' => 100, 'qty' => 6],
        ]));
        $this->givenCartAtTill($till, [602 => ['quantity' => 3, 'variant' => 'Red', 'variations' => ['color' => 'Red']]]);

        $this->placeSaleAtTill($till, amount: 300.0);

        $this->assertSame(['success'], $this->toastTypes());
        $this->assertSame(['Red' => 1, 'Blue' => 6], $this->variantQuantities(602));
        $this->assertSame(7, (int) Product::query()->whereKey(602)->value('current_stock'));
        $this->assertSame(7, array_sum($this->variantQuantities(602)), 'the variants still add up to the stock');
    }

    // ---- what it cannot cover is refused, and nothing moves -------------------------------------------------------------

    #[DataProvider('tills')]
    public function test_a_line_above_the_stock_is_refused_and_leaves_the_stock_exactly_as_it_was(string $till): void
    {
        $this->shelfProduct(id: 603, stock: 2, till: $till);
        $this->givenCartAtTill($till, [603 => 5]);

        $response = $this->placeSaleAtTill($till, amount: 500.0);

        $response->assertOk()->assertJson(['stockShortfall' => true]);
        $this->assertSame(['error'], $this->toastTypes());
        $this->assertSame([
            'orders' => 0,
            'order_details' => 0,
            'charged' => null,
            'current_stock' => 2,
            'movements' => [],
        ], $this->readings(603), 'no order, nothing charged, the shelf untouched and no movement row');
    }

    #[DataProvider('tills')]
    public function test_a_variant_shortfall_is_not_masked_by_the_stock_of_a_sibling_variant(string $till): void
    {
        // The product total is 10 and the line is 4, so a total-only check passes. Only one of the four is Red.
        $this->shelfProduct(id: 604, stock: 10, till: $till, variation: json_encode([
            ['type' => 'Red', 'price' => 100, 'qty' => 1],
            ['type' => 'Blue', 'price' => 100, 'qty' => 9],
        ]));
        $this->givenCartAtTill($till, [604 => ['quantity' => 4, 'variant' => 'Red', 'variations' => ['color' => 'Red']]]);

        $response = $this->placeSaleAtTill($till, amount: 400.0);

        $response->assertJson(['stockShortfall' => true]);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(['Red' => 1, 'Blue' => 9], $this->variantQuantities(604), 'Red was not floored to nothing');
        $this->assertSame(10, (int) Product::query()->whereKey(604)->value('current_stock'));
        $this->assertSame([], $this->readings(604)['movements']);
    }

    #[DataProvider('tills')]
    public function test_one_short_line_refuses_the_whole_sale_including_the_line_the_shelf_could_cover(string $till): void
    {
        $this->shelfProduct(id: 605, stock: 50, till: $till);
        $this->shelfProduct(id: 606, stock: 1, till: $till);
        $this->givenCartAtTill($till, [605 => 2, 606 => 5]);

        $response = $this->placeSaleAtTill($till, amount: 700.0);

        $response->assertJson(['stockShortfall' => true]);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, DB::table('order_details')->count());
        $this->assertSame(50, (int) Product::query()->whereKey(605)->value('current_stock'), 'the line that could be met was rolled back too');
        $this->assertSame(1, (int) Product::query()->whereKey(606)->value('current_stock'));
        $this->assertSame(0, StockMovement::query()->count());
    }

    // ---- a line that is not a sale at all ------------------------------------------------------------------------------

    /**
     * A negative quantity walked straight through the conditional decrement, because `current_stock >= -4` is true of every
     * row: the shelf GAINED four units, the customer was charged, and the movement row recorded the addition as a sale of
     * +4. It is reachable with no crafting — the till's own add-to-cart refuses a quantity of zero or less only on the branch
     * that updates an existing line, so the first add of a negative quantity is accepted — which is why this test posts it
     * through that route rather than writing the cart itself.
     */
    #[DataProvider('tills')]
    public function test_a_line_of_fewer_than_one_unit_is_refused_and_never_adds_stock(string $till): void
    {
        $this->shelfProduct(id: 636, stock: 5, till: $till);
        $added = $this->addToCartAtTill($till, ['id' => 636, 'quantity' => -4]);

        $this->assertSame(200, $added['http']);
        $lines = array_values(array_filter((array) $added['cart'], 'is_array'));
        $this->assertSame(-4, $lines[0]['quantity'], 'the till accepted the line, which is the point');

        $response = $this->placeSaleAtTill($till, amount: 100.0);

        $response->assertJson(['stockShortfall' => true]);
        $this->assertSame([
            'orders' => 0,
            'order_details' => 0,
            'charged' => null,
            'current_stock' => 5,
            'movements' => [],
        ], $this->readings(636), 'nothing sold, nothing charged, and above all nothing added to the shelf');
    }

    // ---- a variant the product does not have ---------------------------------------------------------------------------

    /**
     * The variant guard only ever looked at a variant it MATCHED. A line naming one the product no longer has matched
     * nothing, so the guard did not fire and the units came off the product total instead: four Red sold from a product
     * holding no Red at all, and the variant quantities left summing to 10 against a shelf of 7.
     *
     * The way in is ordinary: a seller edits a product's colours while a line sits in a cashier's held basket. The cart is
     * written directly here because the add-to-cart route cannot produce it — it checks the variant against the product as it
     * is at the time — so the rename has to happen after the line is in the basket.
     */
    #[DataProvider('tills')]
    public function test_a_variant_the_product_no_longer_has_is_refused_and_not_taken_off_the_total(string $till): void
    {
        $this->shelfProduct(id: 637, stock: 10, till: $till, variation: json_encode([
            ['type' => 'Red', 'price' => 100, 'qty' => 4],
            ['type' => 'Blue', 'price' => 100, 'qty' => 6],
        ]));
        $this->givenCartAtTill($till, [637 => ['quantity' => 3, 'variant' => 'Red', 'variations' => ['color' => 'Red']]]);
        DB::table('products')->where('id', 637)->update(['variation' => json_encode([
            ['type' => 'Crimson', 'price' => 100, 'qty' => 4],
            ['type' => 'Blue', 'price' => 100, 'qty' => 6],
        ])]);

        $response = $this->placeSaleAtTill($till, amount: 300.0);

        $response->assertJson(['stockShortfall' => true]);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(10, (int) Product::query()->whereKey(637)->value('current_stock'), 'the total is the sum of the variants and no variant was sold');
        $this->assertSame(['Crimson' => 4, 'Blue' => 6], $this->variantQuantities(637));
        $this->assertSame(10, array_sum($this->variantQuantities(637)), 'the variants still sum to the stock');
        $this->assertSame(0, StockMovement::query()->count());
    }

    /**
     * The same hole from the other side: a product that HAS variants, on a line that names none. `makeVariation` returns the
     * empty string when no colour or choice is posted, and the add-to-cart stock check reads `'' != null` as false in PHP and
     * falls through to its total-only branch, so the line is accepted — and the sale then took eight units off a product
     * whose largest variant held six, leaving the variants summing to 10 against a shelf of 2.
     */
    #[DataProvider('tills')]
    public function test_a_product_with_variants_is_not_sold_on_a_line_that_names_none(string $till): void
    {
        $this->shelfProduct(id: 638, stock: 10, till: $till, variation: json_encode([
            ['type' => 'Red', 'price' => 100, 'qty' => 4],
            ['type' => 'Blue', 'price' => 100, 'qty' => 6],
        ]));
        $added = $this->addToCartAtTill($till, ['id' => 638, 'quantity' => 8]);

        $this->assertSame(200, $added['http']);
        $lines = array_values(array_filter((array) $added['cart'], 'is_array'));
        $this->assertSame('', $lines[0]['variant'], 'the till accepted a varianted line naming no variant');

        $response = $this->placeSaleAtTill($till, amount: 800.0);

        $response->assertJson(['stockShortfall' => true]);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(10, (int) Product::query()->whereKey(638)->value('current_stock'));
        $this->assertSame(10, array_sum($this->variantQuantities(638)), 'the variants still sum to the stock');
    }

    /**
     * A variant whose `type` arrived as a number, which a data import or a hand edit leaves. The first round of this slice
     * tightened the comparison to `===`, which silently stopped matching it: the units came off the total and the variant was
     * left alone, which is the hole above, introduced by this branch's own change. The two writers the rule was taken from
     * (`generateOrder` and the status-change restock) both compare loosely, so the comparison is made on the string.
     *
     * This test is the LAWFUL sale, deliberately, and the first version of it was not. Asserting the refusal of a shortfall
     * proves nothing about the comparison: with `===` restored the variant simply matches nothing, the refusal for an
     * unmatched variant fires instead, and the test stays green for the wrong reason — which it did. Only a sale the variant
     * CAN cover tells the two apart, because a comparison that does not match refuses it.
     */
    #[DataProvider('tills')]
    public function test_a_variant_whose_type_is_a_number_is_matched_and_taken_from_its_own_stock(string $till): void
    {
        $this->shelfProduct(id: 639, stock: 10, till: $till, variation: '[{"type":32,"price":100,"qty":5},{"type":"34","price":100,"qty":5}]');
        $this->givenCartAtTill($till, [639 => ['quantity' => 3, 'variant' => '32']]);

        $this->placeSaleAtTill($till, amount: 300.0);

        $this->assertSame(['success'], $this->toastTypes(), 'five units of variant 32 can answer a line of three');
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(['32' => 2, '34' => 5], $this->variantQuantities(639), 'taken from its own variant, not from the total alone');
        $this->assertSame(7, (int) Product::query()->whereKey(639)->value('current_stock'));
        $this->assertSame(7, array_sum($this->variantQuantities(639)), 'the variants still sum to the stock');
    }

    /** And the same variant is guarded like any other when the line is above what it holds. */
    #[DataProvider('tills')]
    public function test_a_variant_whose_type_is_a_number_is_guarded_like_any_other(string $till): void
    {
        $this->shelfProduct(id: 643, stock: 10, till: $till, variation: '[{"type":32,"price":100,"qty":1},{"type":"34","price":100,"qty":9}]');
        $this->givenCartAtTill($till, [643 => ['quantity' => 4, 'variant' => '32']]);

        $response = $this->placeSaleAtTill($till, amount: 400.0);

        $response->assertJson(['stockShortfall' => true], 'one unit of variant 32 cannot answer a line of four');
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(10, (int) Product::query()->whereKey(643)->value('current_stock'));
        $this->assertSame(['32' => 1, '34' => 9], $this->variantQuantities(643));
    }

    // ---- the ledger describes what happened ----------------------------------------------------------------------------

    #[DataProvider('tills')]
    public function test_every_movement_the_till_writes_has_a_balance_that_follows_from_its_own_change(string $till): void
    {
        $this->shelfProduct(id: 607, stock: 9, till: $till);
        $this->givenCartAtTill($till, [607 => 4]);
        $this->placeSaleAtTill($till, amount: 400.0);

        $movements = StockMovement::query()->where('product_id', 607)->orderBy('id')->get();
        $this->assertCount(1, $movements);

        // The reading before this sale was 9, and each later row starts from the one before it. A row whose balance does not
        // follow from its change is a row that cannot be replayed, whatever the stock policy.
        $balance = 9;
        foreach ($movements as $movement) {
            $balance += (int) $movement->qty_change;
            $this->assertSame($balance, (int) $movement->balance_after, "movement {$movement->id} says balance {$movement->balance_after} after a change of {$movement->qty_change}");
        }
        $this->assertSame($balance, (int) Product::query()->whereKey(607)->value('current_stock'), 'the ledger replays to the shelf');
    }

    #[DataProvider('tills')]
    public function test_a_refused_sale_writes_no_movement_row_at_all(string $till): void
    {
        $this->shelfProduct(id: 608, stock: 3, till: $till);
        $this->givenCartAtTill($till, [608 => 4]);

        $this->placeSaleAtTill($till, amount: 400.0);

        $this->assertSame(0, StockMovement::query()->count(), 'a sale that did not happen leaves no history saying it did');
    }

    /**
     * Whose history the sale lands in. The movement was keyed on `products.seller_id`, which this repository writes nowhere
     * and which holds the SHOP id in the rows that have one, while the seller's own movement log — `InventoryService::recent`
     * behind the v3 seller endpoint and the seller centre's inventory list — filters on the SELLER's id. Keyed on the shop,
     * a seller's own till sales never appeared in the seller's own log: measured as one adjustment and no sale.
     */
    public function test_the_movement_of_a_vendor_till_sale_reaches_the_seller_who_made_it(): void
    {
        $this->shelfProduct(id: 640, stock: 10, till: self::VENDOR_TILL);
        $this->givenCartAtTill(self::VENDOR_TILL, [640 => 3]);

        $this->placeSaleAtTill(self::VENDOR_TILL, amount: 300.0);

        $product = DB::table('products')->where('id', 640)->first();
        $this->assertSame(self::SELLER_ID, (int) $product->user_id);
        $this->assertNotSame((int) $product->seller_id, (int) $product->user_id, 'the two columns differ, which is why the key matters');

        $movement = StockMovement::query()->where('product_id', 640)->sole();
        $this->assertSame(self::SELLER_ID, (int) $movement->seller_id, 'the sale is the seller\'s, as the order line already says');
        $this->assertSame(self::SELLER_ID, (int) DB::table('order_details')->value('seller_id'), 'and the two rows for one event agree');

        $this->assertSame(1, app(InventoryService::class)->recent(productId: 640, sellerId: self::SELLER_ID)->total(), 'the seller can see their own sale');
    }

    /** A platform product's counter sale belongs to no seller, as the web checkout records it, and not to the shop with id 1. */
    public function test_the_movement_of_a_platform_sale_is_not_attributed_to_a_seller(): void
    {
        $this->shelfProduct(id: 641, stock: 10, till: self::ADMIN_TILL);
        $this->givenCartAtTill(self::ADMIN_TILL, [641 => 2]);

        $this->placeSaleAtTill(self::ADMIN_TILL, amount: 200.0);

        $movement = StockMovement::query()->where('product_id', 641)->sole();
        $this->assertNull($movement->seller_id, 'a platform sale is not some seller\'s');
        $this->assertSame(0, app(InventoryService::class)->recent(productId: 641, sellerId: 1)->total(), 'and shop 1 is not handed it');
    }

    // ---- one transaction -----------------------------------------------------------------------------------------------

    #[DataProvider('tills')]
    public function test_a_failure_after_the_stock_was_taken_leaves_neither_the_stock_taken_nor_a_half_written_order(string $till): void
    {
        $this->shelfProduct(id: 609, stock: 10, till: $till);
        $this->givenCartAtTill($till, [609 => 3]);

        // The order row is written last, after the stock has been taken and the line inserted. Before this change there was no
        // transaction at all, so a failure here left the stock gone, a line written and no order.
        //
        // The mock wraps the REAL repository rather than standing in for the interface. `partialMock` on an interface has no
        // parent to fall back to, so every method it is not told about returns null — `$this->orderRepo->getList()` then
        // returned null, the request died on `null->count()` before a single unit of stock was taken, and this test passed
        // whether the sale was one transaction or seven. Removing the transaction left it green, which is how the hole in it
        // was found. A proxied partial passes everything but `add` through to the real repository, so the sale really does
        // reach the shelf and really does fail afterwards.
        $failingOrderRepo = Mockery::mock($this->app->make(OrderRepositoryInterface::class));
        $failingOrderRepo->shouldReceive('add')->andThrow(new RuntimeException('the till lost the payment terminal'));
        $this->app->instance(OrderRepositoryInterface::class, $failingOrderRepo);

        try {
            $this->placeSaleAtTill($till, amount: 300.0);
        } catch (RuntimeException) {
            // whether the panel's handler turns this into a 500 or it reaches the test, the database must be untouched
        }

        $this->assertSame(10, (int) Product::query()->whereKey(609)->value('current_stock'), 'the stock was not taken');
        $this->assertSame(0, DB::table('order_details')->count(), 'no order line survived');
        $this->assertSame(0, StockMovement::query()->count(), 'no movement survived');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_refused_wallet_sale_leaves_the_balance_alone_and_writes_no_wallet_row(): void
    {
        $this->walletCustomer(id: 42, balance: 900);
        $this->shelfProduct(id: 610, stock: 2, till: self::ADMIN_TILL);
        $this->givenWalletCartAtTill(self::ADMIN_TILL, customerId: 42, productId: 610, quantity: 5);

        $response = $this->actingAtTill(self::ADMIN_TILL)->post($this->placeOrderUrl(self::ADMIN_TILL), [
            'amount' => 500.0, 'type' => 'wallet', 'fulfillment' => 'instant',
        ]);

        $response->assertJson(['stockShortfall' => true]);
        $this->assertSame(900.0, (float) DB::table('users')->where('id', 42)->value('wallet_balance'), 'a sale the till refused did not take the money');
        $this->assertSame(0, DB::table('wallet_transactions')->count());
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(2, (int) Product::query()->whereKey(610)->value('current_stock'));
    }

    public function test_a_wallet_sale_the_shelf_can_cover_still_debits_once(): void
    {
        $this->walletCustomer(id: 43, balance: 900);
        $this->shelfProduct(id: 611, stock: 10, till: self::ADMIN_TILL);
        $this->givenWalletCartAtTill(self::ADMIN_TILL, customerId: 43, productId: 611, quantity: 3);

        $this->actingAtTill(self::ADMIN_TILL)->post($this->placeOrderUrl(self::ADMIN_TILL), [
            'amount' => 300.0, 'type' => 'wallet', 'fulfillment' => 'instant',
        ]);

        $this->assertSame(['success'], $this->toastTypes());
        $this->assertSame(600.0, (float) DB::table('users')->where('id', 43)->value('wallet_balance'));
        $this->assertSame(1, DB::table('wallet_transactions')->count());
        $this->assertSame(7, (int) Product::query()->whereKey(611)->value('current_stock'));
    }

    // ---- the lock the sale takes ---------------------------------------------------------------------------------------

    #[DataProvider('tills')]
    public function test_the_products_of_a_sale_are_locked_together_in_ascending_id_order(string $till): void
    {
        // Ids given to the cart in descending order: the lock must not follow the cart's order, or two sales holding the same
        // two products in opposite orders would deadlock. generateOrder locks in this same order for this same reason.
        $this->shelfProduct(id: 621, stock: 50, till: $till);
        $this->shelfProduct(id: 612, stock: 50, till: $till);
        $this->shelfProduct(id: 633, stock: 50, till: $till);
        $this->givenCartAtTill($till, [633 => 1, 621 => 1, 612 => 1]);

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = ['sql' => str_replace('`', '"', $query->sql), 'bindings' => $query->bindings];
        });

        $this->placeSaleAtTill($till, amount: 300.0);

        $ordered = array_values(array_filter($statements, static fn (array $statement): bool => str_contains($statement['sql'], 'from "products"')
            && str_contains($statement['sql'], 'order by "id" asc')));
        $this->assertNotEmpty($ordered, 'the sale selects its products in id order to lock them');

        $first = $ordered[0];
        $ids = array_map('intval', array_values(array_filter($first['bindings'], static fn ($binding): bool => is_int($binding) || ctype_digit((string) $binding))));
        $this->assertSame([612, 621, 633], $ids, 'every product of the sale, ascending, in one statement');

        // Only a real engine takes the lock: Laravel's SQLite grammar compiles lockForUpdate to nothing, so the clause can be
        // asserted where it exists and the ordering above is what holds on both.
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->assertStringContainsStringIgnoringCase('for update', $first['sql'], 'the statement that reads them in id order is the one that locks them');
        }
    }

    // ---- helpers -------------------------------------------------------------------------------------------------------

    private function walletCustomer(int $id, float $balance): void
    {
        DB::table('users')->insert([
            'id' => $id, 'f_name' => 'Sara', 'l_name' => 'Alharbi', 'email' => "customer{$id}@example.test",
            'phone' => '0551234567', 'wallet_balance' => $balance, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function givenWalletCartAtTill(string $till, int $customerId, int $productId, int $quantity): void
    {
        $cartId = 'saved-customer-' . $customerId;
        $this->withSession([
            SessionKey::CURRENT_USER => $cartId,
            SessionKey::CART_NAME => [$cartId],
            $cartId => [[
                'id' => $productId, 'name' => 'Product ' . $productId, 'quantity' => $quantity, 'price' => 100.0,
                'discount' => 0, 'tax' => 0, 'variant' => null, 'variations' => [], 'productType' => 'physical',
                'customerId' => $customerId, 'thumbnail' => null, 'maximum_order_quantity' => 1000, 'add_to_cart_time' => now(),
            ]],
        ]);
    }
}
