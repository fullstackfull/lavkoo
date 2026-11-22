<?php

namespace Tests\Feature\Pos;

use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Enums\SessionKey;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
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

    // ---- one transaction -----------------------------------------------------------------------------------------------

    #[DataProvider('tills')]
    public function test_a_failure_after_the_stock_was_taken_leaves_neither_the_stock_taken_nor_a_half_written_order(string $till): void
    {
        $this->shelfProduct(id: 609, stock: 10, till: $till);
        $this->givenCartAtTill($till, [609 => 3]);

        // The order row is written last, after the stock has been taken and the line inserted. Before this change there was no
        // transaction at all, so a failure here left the stock gone, a line written and no order.
        $this->partialMock(OrderRepositoryInterface::class, function ($repository) {
            $repository->shouldReceive('add')->andThrow(new RuntimeException('the till lost the payment terminal'));
        });

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
