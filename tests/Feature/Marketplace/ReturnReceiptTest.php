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

    public function test_a_list_of_variants_stays_a_list_with_only_the_returned_variant_changed(): void
    {
        $rma = $this->returnOf(
            lineQty: 4,
            returnQty: 3,
            stock: 9,
            variant: 'Red-L',
            variations: [['type' => 'Blue-M', 'qty' => 5], ['type' => 'Red-L', 'qty' => 4], ['type' => 'Green-S', 'qty' => 0]],
        );

        app(ReturnLogisticsService::class)->receive(rma: $rma, by: 1);

        $this->assertSame(
            '[{"type":"Blue-M","qty":5},{"type":"Red-L","qty":7},{"type":"Green-S","qty":0}]',
            DB::table('products')->where('id', $rma->product_id)->value('variation'),
        );
        $this->assertLanguageFilesUnchanged();
    }

    public function test_variants_keyed_by_name_keep_their_keys_and_stay_an_object(): void
    {
        $rma = $this->returnOf(
            lineQty: 4,
            returnQty: 3,
            stock: 9,
            variant: 'Red-L',
            variations: ['blue' => ['type' => 'Blue-M', 'qty' => 5], 'red' => ['type' => 'Red-L', 'qty' => 4]],
        );

        app(ReturnLogisticsService::class)->receive(rma: $rma, by: 1);

        $this->assertSame(
            '{"blue":{"type":"Blue-M","qty":5},"red":{"type":"Red-L","qty":7}}',
            DB::table('products')->where('id', $rma->product_id)->value('variation'),
        );
        $this->assertLanguageFilesUnchanged();
    }

    public function test_a_returned_variant_the_product_no_longer_lists_restocks_only_the_total(): void
    {
        $rma = $this->returnOf(
            lineQty: 4,
            returnQty: 3,
            stock: 9,
            variant: 'Gold-XL',
            variations: [['type' => 'Red-L', 'qty' => 4], ['type' => 'Blue-M', 'qty' => 5]],
        );

        $result = app(ReturnLogisticsService::class)->receive(rma: $rma, by: 1);

        $product = DB::table('products')->where('id', $rma->product_id)->first();
        $this->assertTrue($result['restocked']);
        $this->assertSame(12, (int) $product->current_stock);
        $this->assertSame('[{"type":"Red-L","qty":4},{"type":"Blue-M","qty":5}]', $product->variation);
        $this->assertLanguageFilesUnchanged();
    }

    public function test_a_variant_return_to_a_product_without_variations_writes_an_empty_list(): void
    {
        foreach ([null, '', '{}'] as $storedVariation) {
            $rma = $this->returnOf(lineQty: 4, returnQty: 3, stock: 9, variant: 'Red-L');
            DB::table('products')->where('id', $rma->product_id)->update(['variation' => $storedVariation]);

            $result = app(ReturnLogisticsService::class)->receive(rma: $rma, by: 1);

            $product = DB::table('products')->where('id', $rma->product_id)->first();
            $this->assertTrue($result['restocked']);
            $this->assertSame(12, (int) $product->current_stock);
            $this->assertSame('[]', $product->variation, 'stored variation ' . var_export($storedVariation, true));
        }
        $this->assertLanguageFilesUnchanged();
    }

    public function test_a_return_of_a_line_without_a_variant_leaves_the_variations_untouched(): void
    {
        $rma = $this->returnOf(lineQty: 4, returnQty: 3, stock: 9, variations: ['red' => ['type' => 'Red-L', 'qty' => 4]]);

        app(ReturnLogisticsService::class)->receive(rma: $rma, by: 1);

        $this->assertSame('{"red":{"type":"Red-L","qty":4}}', DB::table('products')->where('id', $rma->product_id)->value('variation'));
        $this->assertLanguageFilesUnchanged();
    }

    public function test_a_variant_return_received_twice_adds_its_units_to_the_variant_once(): void
    {
        $rma = $this->returnOf(lineQty: 5, returnQty: 2, stock: 9, variant: 'Red-L', variations: [['type' => 'Red-L', 'qty' => '4']]);
        $loadedByTheSecondCaller = ReturnShipment::findOrFail($rma->id);
        $returns = app(ReturnLogisticsService::class);

        $returns->receive(rma: $rma, by: 1);
        $returns->receive(rma: $loadedByTheSecondCaller, by: 1);

        $product = DB::table('products')->where('id', $rma->product_id)->first();
        $this->assertSame(11, (int) $product->current_stock);
        $this->assertSame('[{"type":"Red-L","qty":6}]', $product->variation);
        $this->assertLanguageFilesUnchanged();
    }

    public function test_a_return_authorised_for_zero_units_restocks_one(): void
    {
        $rma = $this->returnOf(lineQty: 5, returnQty: 0, stock: 9, variant: 'Red-L', variations: [['type' => 'Red-L', 'qty' => 4]]);

        app(ReturnLogisticsService::class)->receive(rma: $rma, by: 1);

        $product = DB::table('products')->where('id', $rma->product_id)->first();
        $this->assertSame(1, (int) $rma->qty);
        $this->assertSame(10, (int) $product->current_stock);
        $this->assertSame('[{"type":"Red-L","qty":5}]', $product->variation);
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
