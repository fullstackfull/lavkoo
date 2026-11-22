<?php

namespace Tests\Feature\Concurrency;

use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsPosSchema;
use Tests\Support\Concurrency\ConcurrentRunner;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Two point-of-sale tills posting a sale to their own place-order route in two PHP processes at the same instant, against a
 * real MySQL or MariaDB: the last unit is sold once, the other till is refused, and the shelf never goes below zero.
 *
 * This is the race the till's own comment said it existed to handle — "two POS terminals selling the last unit" — and the way
 * it handled it was to floor the stock at zero and charge both customers. Needs a real engine: SQLite in memory is private to
 * one process, so there is no race to run.
 */
class PosStockConcurrencyTest extends TestCase
{
    use BuildsPosSchema;
    use RestoresLanguageFiles;

    private const PRODUCT = 701;

    protected function setUp(): void
    {
        parent::setUp();

        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process.');
        }

        $this->rememberLanguageFiles();
        Http::preventStrayRequests();
        $this->createPosStoreTables();
        DB::table('sellers')->insert(['id' => 7, 'f_name' => 'Vendor', 'status' => 'approved']);
        DB::table('shops')->insert(['id' => 2, 'seller_id' => 7, 'author_type' => 'vendor', 'name' => 'Vendor shop']);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function shelfProduct(int $stock, string $till = 'admin'): void
    {
        DB::table('products')->insert([
            'id' => self::PRODUCT,
            'name' => 'Last unit',
            'slug' => 'last-unit',
            'code' => 'LAST',
            'added_by' => $till === 'admin' ? 'admin' : 'seller',
            'user_id' => $till === 'admin' ? 0 : 7,
            'seller_id' => $till === 'admin' ? 1 : 2,
            'seller_is' => $till === 'admin' ? 'admin' : 'seller',
            'product_type' => 'physical',
            'current_stock' => $stock,
            'unit_price' => 100,
            'variation' => json_encode([]),
            'choice_options' => json_encode([]),
            'colors' => json_encode([]),
            'images' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param list<array<string, mixed>> $reports */
    private function assertEveryWorkerAnswered(array $reports): void
    {
        foreach ($reports as $index => $report) {
            $this->assertTrue($report['ok'], "worker {$index} failed: " . ($report['error'] ?? '') . ' ' . ($report['message'] ?? ''));
        }
        $this->assertTrue(ConcurrentRunner::overlapped($reports), 'the two sales ran at the same time');
    }

    public function test_two_tills_selling_the_last_unit_at_once_sell_it_once_and_refuse_the_other(): void
    {
        $this->shelfProduct(stock: 1);

        $reports = ConcurrentRunner::run('pos.place_sale', array_fill(0, 2, [
            'till' => 'admin', 'product' => self::PRODUCT, 'quantity' => 1, 'amount' => 100.0, 'seller' => 0,
        ]));

        $this->assertEveryWorkerAnswered($reports);
        $this->assertSame(1, Order::query()->count(), 'the last unit was sold once');
        $this->assertSame(1, DB::table('order_details')->count());
        $this->assertSame(0, (int) Product::query()->whereKey(self::PRODUCT)->value('current_stock'), 'the shelf is empty, not negative');
        $this->assertSame(1, StockMovement::query()->where('product_id', self::PRODUCT)->count(), 'one sale, one movement row');

        $movement = StockMovement::query()->where('product_id', self::PRODUCT)->firstOrFail();
        $this->assertSame(-1, (int) $movement->qty_change);
        $this->assertSame(0, (int) $movement->balance_after, 'the balance follows from the change: 1 - 1 = 0');

        // One of the two says the sale went through and the other says the shelf was short. Which process won is the race's to
        // decide; that exactly one won is not.
        $refusals = array_filter($reports, static fn (array $report): bool => str_contains((string) ($report['result']['body'] ?? ''), 'stockShortfall'));
        $this->assertCount(1, $refusals, 'exactly one of the two tills was refused');
    }

    public function test_six_tills_selling_the_last_three_units_at_once_sell_three_and_refuse_three(): void
    {
        $this->shelfProduct(stock: 3);

        $reports = ConcurrentRunner::run('pos.place_sale', array_fill(0, 6, [
            'till' => 'admin', 'product' => self::PRODUCT, 'quantity' => 1, 'amount' => 100.0, 'seller' => 0,
        ]));

        $this->assertEveryWorkerAnswered($reports);
        $this->assertSame(3, Order::query()->count(), 'three units, three orders');
        $this->assertSame(0, (int) Product::query()->whereKey(self::PRODUCT)->value('current_stock'));
        $this->assertSame(3, StockMovement::query()->where('product_id', self::PRODUCT)->count());

        $refusals = array_filter($reports, static fn (array $report): bool => str_contains((string) ($report['result']['body'] ?? ''), 'stockShortfall'));
        $this->assertCount(3, $refusals, 'the other three were told the shelf was short');

        // Replay the ledger from the reading before the race and it must land on the shelf.
        $balance = 3;
        foreach (StockMovement::query()->where('product_id', self::PRODUCT)->orderBy('id')->get() as $movement) {
            $balance += (int) $movement->qty_change;
            $this->assertSame($balance, (int) $movement->balance_after, 'every row of a race still replays');
        }
        $this->assertSame(0, $balance);
    }

    /**
     * Two sales that BOTH fit must both go through. The till chose its order id by counting the rows and adding one, so two
     * overlapping sales picked the same number and the second died on the primary key: measured here before the change as one
     * HTTP 500 in three runs out of three, a lawful sale lost to a crash. The wallet shape is the worse one, because
     * `createWalletTransaction`'s unlocked read of the customer is the first statement of the transaction and pins the
     * snapshot the id is then read from.
     */
    public function test_two_wallet_sales_for_one_customer_at_once_both_go_through_and_debit_once_each(): void
    {
        $this->shelfProduct(stock: 50);
        DB::table('users')->insert(['id' => 42, 'f_name' => 'Sara', 'l_name' => 'Alharbi', 'email' => 'sara@example.test',
            'phone' => '0551112222', 'wallet_balance' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);

        $reports = ConcurrentRunner::run('pos.place_sale', array_fill(0, 2, [
            'till' => 'admin', 'product' => self::PRODUCT, 'quantity' => 3, 'amount' => 300.0, 'seller' => 0,
            'type' => 'wallet', 'customer' => 42,
        ]));

        $this->assertEveryWorkerAnswered($reports);
        foreach ($reports as $index => $report) {
            $this->assertSame(200, (int) $report['result']['status'], "sale {$index} was lost to a server error");
        }
        $this->assertSame(2, Order::query()->count(), 'two sales the shelf and the wallet could both cover are two sales');
        $this->assertSame(2, DB::table('order_details')->count());
        $this->assertSame(2, DB::table('wallet_transactions')->count(), 'one debit each, not one lost');
        $this->assertSame(400.0, (float) DB::table('users')->where('id', 42)->value('wallet_balance'), '1000 less 300 twice');
        $this->assertSame(44, (int) Product::query()->whereKey(self::PRODUCT)->value('current_stock'));
        $this->assertSame(2, StockMovement::query()->where('product_id', self::PRODUCT)->count());
    }

    /** The same lost sale with no wallet and no shared product: two cash sales over different shelves still shared an id. */
    public function test_two_cash_sales_over_different_products_at_once_both_go_through(): void
    {
        $this->shelfProduct(stock: 50);
        DB::table('products')->insert(['id' => 702, 'name' => 'Other shelf', 'slug' => 'other-shelf', 'code' => 'OTHER',
            'added_by' => 'admin', 'user_id' => 0, 'seller_id' => 1, 'seller_is' => 'admin', 'product_type' => 'physical',
            'current_stock' => 50, 'unit_price' => 100, 'variation' => json_encode([]), 'choice_options' => json_encode([]),
            'colors' => json_encode([]), 'images' => json_encode([]), 'created_at' => now(), 'updated_at' => now()]);

        $reports = ConcurrentRunner::run('pos.place_sale', [
            ['till' => 'admin', 'product' => self::PRODUCT, 'quantity' => 2, 'amount' => 200.0, 'seller' => 0],
            ['till' => 'admin', 'product' => 702, 'quantity' => 2, 'amount' => 200.0, 'seller' => 0],
        ]);

        $this->assertEveryWorkerAnswered($reports);
        foreach ($reports as $index => $report) {
            $this->assertSame(200, (int) $report['result']['status'], "sale {$index} was lost to a server error");
        }
        $this->assertSame(2, Order::query()->count());
        $this->assertSame(2, DB::table('order_details')->count());
        $this->assertSame([100001, 100002], Order::query()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all(), 'two sales, two ids');
        $this->assertSame(48, (int) Product::query()->whereKey(self::PRODUCT)->value('current_stock'));
        $this->assertSame(48, (int) Product::query()->whereKey(702)->value('current_stock'));
    }

    public function test_a_till_and_a_vendor_till_racing_over_the_same_product_never_oversell_it(): void
    {
        $this->shelfProduct(stock: 2, till: 'seller');

        $reports = ConcurrentRunner::run('pos.place_sale', [
            ['till' => 'vendor', 'product' => self::PRODUCT, 'quantity' => 2, 'amount' => 200.0, 'seller' => 7],
            ['till' => 'admin', 'product' => self::PRODUCT, 'quantity' => 2, 'amount' => 200.0, 'seller' => 0],
        ]);

        $this->assertEveryWorkerAnswered($reports);
        $this->assertSame(1, Order::query()->count(), 'two units cannot answer two lines of two');
        $this->assertSame(0, (int) Product::query()->whereKey(self::PRODUCT)->value('current_stock'));
        $this->assertSame(1, StockMovement::query()->where('product_id', self::PRODUCT)->count());
    }
}
