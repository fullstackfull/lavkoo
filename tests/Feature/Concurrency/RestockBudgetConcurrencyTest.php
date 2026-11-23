<?php

namespace Tests\Feature\Concurrency;

use App\Models\StockMovement;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsReturnReceiptSchema;
use Tests\Support\Concurrency\ConcurrentRunner;
use Tests\TestCase;

/**
 * The two writers of an order line's stock, racing, against a real engine.
 *
 * A line's units are a budget (App\Services\Marketplace\RestockBudget): the return receipt spends what it
 * receives and the order status writer spends the remainder. Both read that budget before they spend it, so
 * without a lock two processes can read the same unspent units and both put them back. The line row is what
 * serialises them — the receipt locks it, and the status writer's conditional marker flip locks it too.
 *
 * Needs a real MySQL or MariaDB: SQLite in memory is private to one process, so the race cannot happen there.
 */
class RestockBudgetConcurrencyTest extends TestCase
{
    use BuildsReturnReceiptSchema;

    protected function setUp(): void
    {
        parent::setUp();
        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process.');
        }
        $this->buildReturnReceiptSchema();
        $this->buildOrdersTable();
    }

    protected function tearDown(): void
    {
        if (ConcurrentRunner::available()) {
            Schema::dropIfExists('orders');
            $this->dropReturnReceiptSchema();
        }
        parent::tearDown();
    }

    /**
     * `orders` as the status writer reads it: an id, a payment method (the channel-order marker looks at it) and its lines.
     *
     * `Order::details()` orders its lines by `seller_id`, which the receipt schema does not carry, so the column is added
     * here rather than in the shared trait the return-receipt tests use. `products.status` likewise: the PANEL copy of the
     * writer loads `details.product`, and the Product relation is scoped to `status = 1` -- which only a real engine
     * reports, because SQLite skips every test in this file.
     */
    private function buildOrdersTable(): void
    {
        Schema::table('order_details', static fn (Blueprint $table) => $table->unsignedBigInteger('seller_id')->nullable());
        Schema::table('products', static fn (Blueprint $table) => $table->boolean('status')->default(1));

        Schema::dropIfExists('orders');
        Schema::create('orders', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('order_status')->default('confirmed');
            $table->string('payment_status')->default('paid');
            $table->string('payment_method')->nullable();
            $table->timestamps();
        });
        DB::table('orders')->insert(['id' => 1, 'payment_method' => 'cash_on_delivery', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function stock(int $productId): int
    {
        return (int) DB::table('products')->where('id', $productId)->value('current_stock');
    }

    private function assertEveryWorkerSucceeded(array $reports): void
    {
        foreach ($reports as $index => $report) {
            $this->assertTrue($report['ok'], "worker {$index}: " . ($report['error'] ?? '') . ' ' . ($report['message'] ?? ''));
        }
        $this->assertTrue(ConcurrentRunner::ranAtTheSameTime($reports), 'the processes really ran at the same time');
    }

    public function test_two_processes_marking_the_same_order_returned_restock_the_line_once(): void
    {
        // Three units left the shelf; one has already come back through a received return, so two are unspent.
        $rma = $this->returnOf(lineQty: 3, returnQty: 1, stock: 10);
        app(\App\Services\Marketplace\ReturnLogisticsService::class)->receive($rma);
        $this->assertSame(11, $this->stock($rma->product_id));

        $reports = ConcurrentRunner::run('returns.change_order_status', array_fill(0, 2, ['order' => 1, 'hold_microseconds' => 300000]));

        $this->assertEveryWorkerSucceeded($reports);
        $this->assertSame(13, $this->stock($rma->product_id), 'three units left the shelf and three came back, however many processes marked the order returned');
        $this->assertSame(0, (int) DB::table('order_details')->where('id', $rma->order_details_id)->value('is_stock_decreased'));
        $this->assertLanguageFilesUnchanged();
    }

    public function test_a_partial_receipt_racing_the_order_being_marked_returned_puts_back_exactly_what_left_the_shelf(): void
    {
        // Three units left the shelf. One comes back as a received return at the same instant as the whole
        // order is marked returned: between them they may put back three units and not one more.
        $rma = $this->returnOf(lineQty: 3, returnQty: 1, stock: 10);

        $reports = ConcurrentRunner::run('returns.receive', [
            ['rma' => $rma->id, 'hold_microseconds' => 300000],
            ['op' => 'returns.change_order_status', 'order' => 1, 'hold_microseconds' => 300000],
        ]);

        $this->assertEveryWorkerSucceeded($reports);
        $this->assertSame(13, $this->stock($rma->product_id), 'whichever of the two got the line first, the shelf gained exactly the three units that left it');
        $this->assertSame(0, (int) DB::table('order_details')->where('id', $rma->order_details_id)->value('is_stock_decreased'));
        $this->assertLessThanOrEqual(
            1,
            StockMovement::where('type', StockMovement::TYPE_RETURN)->where('reference_id', $rma->id)->count(),
            'the receipt wrote at most one movement row for the return',
        );
        $this->assertLanguageFilesUnchanged();
    }

    public function test_the_panel_copy_of_the_status_writer_restocks_the_line_once_when_raced_too(): void
    {
        // OrderRepository::updateStockOnOrderStatusChange is a second, near-identical copy of the same sixty
        // lines and it is the one the admin and vendor panels run. Only OrderManager was ever raced, so the
        // panel copy's lock discipline was unproven on a real engine -- and the two copies really do diverge
        // in coverage, which is what the first round's mutations M9 and M10 showed.
        $rma = $this->returnOf(lineQty: 3, returnQty: 1, stock: 10);
        app(\App\Services\Marketplace\ReturnLogisticsService::class)->receive($rma);
        $this->assertSame(11, $this->stock($rma->product_id));

        $reports = ConcurrentRunner::run('returns.change_order_status_panel_copy', array_fill(0, 2, ['order' => 1, 'hold_microseconds' => 300000]));

        $this->assertEveryWorkerSucceeded($reports);
        $this->assertSame(13, $this->stock($rma->product_id), 'three units left the shelf and three came back, however many panels marked the order returned');
        $this->assertSame(0, (int) DB::table('order_details')->where('id', $rma->order_details_id)->value('is_stock_decreased'));
        $this->assertLanguageFilesUnchanged();
    }

    public function test_a_partial_receipt_racing_the_panel_copy_puts_back_exactly_what_left_the_shelf(): void
    {
        $rma = $this->returnOf(lineQty: 3, returnQty: 1, stock: 10);

        $reports = ConcurrentRunner::run('returns.receive', [
            ['rma' => $rma->id, 'hold_microseconds' => 300000],
            ['op' => 'returns.change_order_status_panel_copy', 'order' => 1, 'hold_microseconds' => 300000],
        ]);

        $this->assertEveryWorkerSucceeded($reports);
        $this->assertSame(13, $this->stock($rma->product_id), 'whichever of the two got the line first, the shelf gained exactly the three units that left it');
        $this->assertSame(0, (int) DB::table('order_details')->where('id', $rma->order_details_id)->value('is_stock_decreased'));
        $this->assertLanguageFilesUnchanged();
    }
}
