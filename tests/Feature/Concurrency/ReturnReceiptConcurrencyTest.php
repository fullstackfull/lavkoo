<?php

namespace Tests\Feature\Concurrency;

use App\Models\ReturnShipment;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsReturnReceiptSchema;
use Tests\Support\Concurrency\ConcurrentRunner;
use Tests\TestCase;

/**
 * Two processes receiving one partial return at the same instant, against a real engine: a double click, or the panel and the seller
 * app together. A partial return never touches the order line's stock marker, so nothing but the return row itself stands between
 * the two receipts and a double restock. Needs a real MySQL or MariaDB: SQLite in memory is private to one process.
 */
class ReturnReceiptConcurrencyTest extends TestCase
{
    use BuildsReturnReceiptSchema;

    protected function setUp(): void
    {
        parent::setUp();
        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process.');
        }
        $this->buildReturnReceiptSchema();
    }

    protected function tearDown(): void
    {
        if (ConcurrentRunner::available()) {
            $this->dropReturnReceiptSchema();
        }
        parent::tearDown();
    }

    public function test_a_partial_return_received_by_two_processes_at_once_restocks_once(): void
    {
        $rma = $this->returnOf(lineQty: 5, returnQty: 2, stock: 10);

        $reports = ConcurrentRunner::run('returns.receive', array_fill(0, 2, ['rma' => $rma->id, 'hold_microseconds' => 300000]));

        foreach ($reports as $index => $report) {
            $this->assertTrue($report['ok'], "worker {$index}: " . ($report['error'] ?? '') . ' ' . ($report['message'] ?? ''));
        }
        $results = array_column($reports, 'result');
        $this->assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['ok'] && $result['restocked'])), 'one receipt restocked');
        $this->assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['reason'] === 'return_is_not_in_a_receivable_state')), 'the other was told it was already received');
        $this->assertSame(12, (int) DB::table('products')->where('id', $rma->product_id)->value('current_stock'));
        $this->assertSame(1, StockMovement::where('type', StockMovement::TYPE_RETURN)->where('reference_id', $rma->id)->count());
        $this->assertSame(ReturnShipment::STATUS_RESTOCKED, $rma->fresh()->status);
        $this->assertTrue(ConcurrentRunner::overlapped($reports), 'the processes really ran at the same time');
        $this->assertLanguageFilesUnchanged();
    }
}
