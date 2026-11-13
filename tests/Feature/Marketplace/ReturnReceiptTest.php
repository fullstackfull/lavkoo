<?php

namespace Tests\Feature\Marketplace;

use App\Models\ReturnShipment;
use App\Models\StockMovement;
use App\Services\Marketplace\ReturnLogisticsService;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsReturnReceiptSchema;
use Tests\TestCase;

/**
 * Receiving a return puts its units back on the shelf once: a second receipt of the same return, from a caller still holding the
 * return it loaded before the first one, restocks nothing and writes no movement, and a variant's own quantity rises with the total.
 */
class ReturnReceiptTest extends TestCase
{
    use BuildsReturnReceiptSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildReturnReceiptSchema();
    }

    protected function tearDown(): void
    {
        $this->dropReturnReceiptSchema();
        parent::tearDown();
    }

    public function test_a_partial_return_received_twice_restocks_once(): void
    {
        $rma = $this->returnOf(lineQty: 5, returnQty: 2, stock: 10);
        $loadedByTheSecondCaller = ReturnShipment::findOrFail($rma->id);
        $returns = app(ReturnLogisticsService::class);

        $first = $returns->receive(rma: $rma, by: 1);
        $second = $returns->receive(rma: $loadedByTheSecondCaller, by: 1);

        $this->assertTrue($first['ok']);
        $this->assertTrue($first['restocked']);
        $this->assertSame(['ok' => false, 'reason' => 'return_is_not_in_a_receivable_state'], $second);
        $this->assertSame(12, (int) DB::table('products')->where('id', $rma->product_id)->value('current_stock'));
        $this->assertSame(1, StockMovement::where('type', StockMovement::TYPE_RETURN)->where('reference_id', $rma->id)->count());
        $this->assertSame(ReturnShipment::STATUS_RESTOCKED, $rma->fresh()->status);
        $this->assertSame(1, (int) DB::table('order_details')->where('id', $rma->order_details_id)->value('is_stock_decreased'));
        $this->assertLanguageFilesUnchanged();
    }

    public function test_a_variant_return_restocks_the_variant_and_the_total(): void
    {
        $rma = $this->returnOf(
            lineQty: 4,
            returnQty: 3,
            stock: 9,
            variant: 'Red-L',
            variations: [['type' => 'Red-L', 'price' => 10, 'sku' => 'R-L', 'qty' => 4], ['type' => 'Blue-M', 'price' => 10, 'sku' => 'B-M', 'qty' => 5]],
        );

        $result = app(ReturnLogisticsService::class)->receive(rma: $rma, by: 1);

        $product = DB::table('products')->where('id', $rma->product_id)->first();
        $this->assertTrue($result['restocked']);
        $this->assertSame(12, (int) $product->current_stock);
        $this->assertSame(
            [['type' => 'Red-L', 'price' => 10, 'sku' => 'R-L', 'qty' => 7], ['type' => 'Blue-M', 'price' => 10, 'sku' => 'B-M', 'qty' => 5]],
            json_decode($product->variation, true),
        );
        $this->assertLanguageFilesUnchanged();
    }

    public function test_a_full_line_return_still_claims_the_lines_stock_marker(): void
    {
        $rma = $this->returnOf(lineQty: 2, returnQty: 2, stock: 3);

        $result = app(ReturnLogisticsService::class)->receive(rma: $rma, by: 1);

        $this->assertTrue($result['restocked']);
        $this->assertSame(5, (int) DB::table('products')->where('id', $rma->product_id)->value('current_stock'));
        $this->assertSame(0, (int) DB::table('order_details')->where('id', $rma->order_details_id)->value('is_stock_decreased'));
        $this->assertSame(1, StockMovement::where('type', StockMovement::TYPE_RETURN)->count());
        $this->assertLanguageFilesUnchanged();
    }

    public function test_a_full_line_return_after_the_legacy_restore_does_not_restock_again(): void
    {
        $rma = $this->returnOf(lineQty: 2, returnQty: 2, stock: 3);
        DB::table('order_details')->where('id', $rma->order_details_id)->update(['is_stock_decreased' => 0]);

        $result = app(ReturnLogisticsService::class)->receive(rma: $rma, by: 1);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['restocked']);
        $this->assertSame(ReturnShipment::STATUS_RECEIVED, $rma->fresh()->status);
        $this->assertSame(3, (int) DB::table('products')->where('id', $rma->product_id)->value('current_stock'));
        $this->assertSame(0, StockMovement::count());
        $this->assertLanguageFilesUnchanged();
    }
}
