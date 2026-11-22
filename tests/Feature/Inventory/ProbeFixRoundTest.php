<?php

namespace Tests\Feature\Inventory;

use App\Models\ReturnShipment;
use App\Models\StockMovement;
use App\Repositories\OrderRepository;
use App\Services\Marketplace\RestockBudget;
use App\Utils\OrderManager;
use Illuminate\Support\Facades\DB;

/** THROWAWAY PROBE for the fix round. Reproduces each reviewer finding. Never committed. */
class ProbeFixRoundTest extends RestockThroughTheRealRoutesTest
{
    /** The columns the order-edit writer writes that the fulfillment schema does not infer. */
    private function widenOrderDetailsForTheEditWriter(): void
    {
        \Illuminate\Support\Facades\Schema::table('order_details', static function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->string('digital_file_after_sell')->nullable();
            $table->unsignedBigInteger('shipping_method_id')->nullable();
            $table->string('discount_type')->nullable();
            $table->boolean('refund_request')->default(0);
            $table->timestamp('refund_started_at')->nullable();
        });
    }

    private function marker(int $lineId): int
    {
        return (int) DB::table('order_details')->where('id', $lineId)->value('is_stock_decreased');
    }

    private function stock(): int
    {
        return (int) DB::table('products')->where('id', 70)->value('current_stock');
    }

    // ---- A-F1 / B-F1 : the re-open cycle drains the shelf -----------------------------------------
    public function test_probe_f1_drain_cycle_through_the_panel_repository(): void
    {
        $lineId = $this->soldLine(3);
        $this->receiveThroughThePanel($this->authorizedReturn(3, $lineId));
        $trace = ['afterReturn' => [$this->stock(), $this->marker($lineId)]];

        foreach ([1, 2, 3, 4] as $cycle) {
            $this->changeStatusThroughThePanel('processing');
            $trace["c{$cycle}.reopen"] = [$this->stock(), $this->marker($lineId)];
            $this->changeStatusThroughThePanel('returned');
            $trace["c{$cycle}.returned"] = [$this->stock(), $this->marker($lineId)];
        }

        fwrite(STDERR, "\nPROBE F1 repository: " . json_encode($trace) . "\n");
        $this->assertTrue(true);
    }

    public function test_probe_f1_drain_cycle_through_order_manager(): void
    {
        $lineId = $this->soldLine(3, stock: 10);
        $this->receiveThroughThePanel($this->authorizedReturn(3, $lineId));
        $order = \App\Models\Order::find(700);
        $trace = ['afterReturn' => [$this->stock(), $this->marker($lineId)]];

        foreach ([1, 2, 3] as $cycle) {
            OrderManager::getStockUpdateOnOrderStatusChange($order->fresh(), 'processing');
            $trace["c{$cycle}.reopen"] = [$this->stock(), $this->marker($lineId)];
            OrderManager::getStockUpdateOnOrderStatusChange($order->fresh(), 'returned');
            $trace["c{$cycle}.returned"] = [$this->stock(), $this->marker($lineId)];
        }

        fwrite(STDERR, "\nPROBE F1 OrderManager: " . json_encode($trace) . "\n");
        $this->assertTrue(true);
    }

    // ---- A-F2 / B-F6 : a genuine second return restocks nothing and eats the claim ----------------
    public function test_probe_f2_second_genuine_return_of_a_reopened_line(): void
    {
        $lineId = $this->soldLine(3);
        $this->receiveThroughThePanel($this->authorizedReturn(3, $lineId));
        $this->changeStatusThroughThePanel('processing');
        $before = [$this->stock(), $this->marker($lineId)];

        $rmaB = $this->authorizedReturn(3, $lineId);
        $rmaB->update(['reference' => 'RMA-B']);
        $result = app(\App\Services\Marketplace\ReturnLogisticsService::class)->receive($rmaB->fresh());

        fwrite(STDERR, "\nPROBE F2: before=" . json_encode($before)
            . " after=" . json_encode([$this->stock(), $this->marker($lineId)])
            . " rmaB=" . $rmaB->fresh()->status
            . " result=" . json_encode($result + ['return' => null])
            . " movements=" . json_encode($this->reading()['movements']) . "\n");

        $this->changeStatusThroughThePanel('returned');
        fwrite(STDERR, "PROBE F2 then returned: " . json_encode([$this->stock(), $this->marker($lineId)]) . "\n");
        $this->assertTrue(true);
    }

    // ---- A-F3 / B-F4 : the clamp names the wrong bound -------------------------------------------
    public function test_probe_f3_clamp_reason_when_the_bound_is_units_already_back(): void
    {
        $lineId = $this->soldLine(3);
        $a = $this->authorizedReturn(2, $lineId);
        $this->receiveThroughThePanel($a);
        $b = $this->authorizedReturn(2, $lineId);
        $b->update(['reference' => 'RMA-B']);
        $result = app(\App\Services\Marketplace\ReturnLogisticsService::class)->receive($b->fresh());

        fwrite(STDERR, "\nPROBE F3: stock=" . $this->stock()
            . " result=" . json_encode(['restocked' => $result['restocked'], 'restocked_units' => $result['restocked_units'], 'clamped' => $result['clamped']])
            . " movements=" . json_encode($this->reading()['movements']) . "\n");
        $this->assertTrue(true);
    }

    // ---- A-F4 / B-F5 : the clamp is silent when total --------------------------------------------
    public function test_probe_f4_clamp_is_silent_when_it_clamps_to_zero(): void
    {
        $lineId = $this->soldLine(2);
        $this->receiveThroughThePanel($this->authorizedReturn(5, $lineId));
        $b = $this->authorizedReturn(5, $lineId);
        $b->update(['reference' => 'RMA-B']);
        $result = app(\App\Services\Marketplace\ReturnLogisticsService::class)->receive($b->fresh());

        fwrite(STDERR, "\nPROBE F4: stock=" . $this->stock()
            . " result=" . json_encode(['ok' => $result['ok'], 'restocked' => $result['restocked'], 'balance_after' => $result['balance_after'], 'restocked_units' => $result['restocked_units'], 'clamped' => $result['clamped']])
            . " rmaB=" . $b->fresh()->status
            . " sum_restocked_qty=" . DB::table('return_shipments')->where('status', 'restocked')->sum('qty')
            . " alreadyBack=" . app(RestockBudget::class)->unitsAlreadyBack($lineId, 2)
            . "\n");
        $this->assertTrue(true);
    }

    // ---- B-F2 / B-F3 / B-F8 : the panel's own create route --------------------------------------
    public function test_probe_bf2_panel_create_then_receive_then_returned(): void
    {
        $lineId = $this->soldLine(3, variant: 'Red', variations: [['type' => 'Red', 'price' => 10, 'sku' => 'R', 'qty' => 20]]);

        $this->post(route('admin.marketplace.returns.store'), [
            'order_id' => 700, 'product_id' => 70, 'qty' => 3, 'reason' => 'damaged_box', 'restock' => 1,
        ])->assertRedirect();

        $rma = ReturnShipment::firstOrFail();
        fwrite(STDERR, "\nPROBE B-F2 created: order_details_id=" . var_export($rma->order_details_id, true) . " qty=" . $rma->qty . "\n");

        $this->receiveThroughThePanel($rma);
        fwrite(STDERR, "PROBE B-F2 after receive: " . json_encode($this->reading('Red')) . " marker=" . $this->marker($lineId) . " rma=" . $rma->fresh()->status . "\n");

        $this->changeStatusThroughThePanel('returned');
        fwrite(STDERR, "PROBE B-F2 after returned: " . json_encode($this->reading('Red')) . " marker=" . $this->marker($lineId) . "\n");
        fwrite(STDERR, "PROBE B-F9 lang dirty: " . trim(shell_exec('cd ' . escapeshellarg(base_path()) . ' && git status --porcelain -- resources/lang/ 2>&1') ?: '(clean)') . "\n");
        $this->assertTrue(true);
    }

    // ---- B-F7 : a line id belonging to another order --------------------------------------------
    public function test_probe_bf7_cross_order_line_id(): void
    {
        $lineA = $this->soldLine(3);
        DB::table('orders')->insert([
            'id' => 701, 'customer_id' => 1, 'is_guest' => 1, 'order_status' => 'confirmed',
            'payment_status' => 'paid', 'payment_method' => 'cash_on_delivery', 'seller_is' => 'admin',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $lineB = (int) DB::table('order_details')->insertGetId([
            'order_id' => 701, 'product_id' => 70, 'qty' => 1, 'price' => 10, 'variant' => null,
            'is_stock_decreased' => 1, 'delivery_status' => 'confirmed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Through the panel's own create route, as the controller builds it.
        $this->post(route('admin.marketplace.returns.store'), [
            'order_id' => 700, 'order_details_id' => $lineB, 'product_id' => 70, 'qty' => 3, 'restock' => 1,
        ])->assertRedirect();
        $rma = ReturnShipment::firstOrFail();
        fwrite(STDERR, "\nPROBE B-F7 created with order_details_id=" . var_export($rma->order_details_id, true) . " (lineA=" . $lineA . ", lineB=" . $lineB . ")\n");
        $this->receiveThroughThePanel($rma);

        fwrite(STDERR, "\nPROBE B-F7: stock=" . $this->stock() . " markerA=" . $this->marker($lineA) . " markerB=" . $this->marker($lineB)
            . " movements=" . json_encode($this->reading()['movements']) . "\n");
        $this->assertTrue(true);
    }

    // ---- A-F11 : a line of quantity zero ---------------------------------------------------------
    public function test_probe_af11_line_of_quantity_zero(): void
    {
        $lineId = $this->soldLine(0);
        $rma = $this->authorizedReturn(2, $lineId);
        $result = app(\App\Services\Marketplace\ReturnLogisticsService::class)->receive($rma);

        fwrite(STDERR, "\nPROBE A-F11: stock=" . $this->stock() . " marker=" . $this->marker($lineId)
            . " restocked=" . var_export($result['restocked'], true) . " units=" . $result['restocked_units'] . "\n");
        $this->assertTrue(true);
    }

    // ---- A-F12 : a return marked not restockable -------------------------------------------------
    public function test_probe_af12_non_restockable_return(): void
    {
        $lineId = $this->soldLine(3);
        $rma = $this->authorizedReturn(3, $lineId);
        $rma->update(['restock' => false]);
        $this->receiveThroughThePanel($rma->fresh());
        $afterReceive = [$this->stock(), $this->marker($lineId), $rma->fresh()->status];
        $this->changeStatusThroughThePanel('returned');

        fwrite(STDERR, "\nPROBE A-F12: afterReceive=" . json_encode($afterReceive) . " afterReturned=" . json_encode([$this->stock(), $this->marker($lineId)]) . "\n");
        $this->assertTrue(true);
    }

    // ---- A-F9 : the variation column's shape -----------------------------------------------------
    public function test_probe_af9_variation_keys(): void
    {
        $this->soldLine(3, variant: 'Red-L');
        DB::table('products')->where('id', 70)->update([
            'variation' => json_encode(['blue' => ['type' => 'Blue-M', 'qty' => 5], 'red' => ['type' => 'Red-L', 'qty' => 4]]),
        ]);
        $this->changeStatusThroughThePanel('canceled');

        fwrite(STDERR, "\nPROBE A-F9: " . DB::table('products')->where('id', 70)->value('variation') . "\n");
        $this->assertTrue(true);
    }

    // ---- A-F10 : the line's quantity grows -------------------------------------------------------
    public function test_probe_af10_line_quantity_grows(): void
    {
        $lineId = $this->soldLine(3);
        $this->receiveThroughThePanel($this->authorizedReturn(2, $lineId));
        $b = $this->authorizedReturn(2, $lineId);
        $b->update(['reference' => 'RMA-B']);
        $this->receiveThroughThePanel($b->fresh());
        $budget = app(RestockBudget::class);
        $at3 = [$budget->unitsAlreadyBack($lineId, 3), $budget->unspentUnitsOf($lineId, 3)];

        DB::table('order_details')->where('id', $lineId)->update(['qty' => 5]);
        $at5 = [$budget->unitsAlreadyBack($lineId, 5), $budget->unspentUnitsOf($lineId, 5)];

        fwrite(STDERR, "\nPROBE A-F10: stock=" . $this->stock() . " at_qty_3=" . json_encode($at3) . " at_qty_5=" . json_encode($at5)
            . " typed_sum=" . DB::table('return_shipments')->where('status', 'restocked')->sum('qty') . "\n");
        $this->assertTrue(true);
    }

    // ---- B-MB3 : the budget is per line ----------------------------------------------------------
    public function test_probe_mb3_two_lines_of_one_product(): void
    {
        $lineA = $this->soldLine(3);
        $lineB = (int) DB::table('order_details')->insertGetId([
            'order_id' => 700, 'product_id' => 70, 'qty' => 2, 'price' => 10, 'variant' => 'B',
            'is_stock_decreased' => 1, 'delivery_status' => 'confirmed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->receiveThroughThePanel($this->authorizedReturn(3, $lineA));
        $afterA = $this->stock();
        $this->changeStatusThroughThePanel('returned');

        fwrite(STDERR, "\nPROBE MB3: afterA=" . $afterA . " afterReturned=" . $this->stock()
            . " markers=" . json_encode([$this->marker($lineA), $this->marker($lineB)]) . "\n");
        $this->assertTrue(true);
    }

    // ---- A-F5 : the fourth writer ----------------------------------------------------------------
    public function test_probe_af5_order_edit_writer(): void
    {
        $lineId = $this->soldLine(3, variant: 'Red', variations: [['type' => 'Red', 'price' => 10, 'sku' => 'R', 'qty' => 20]]);
        $this->receiveThroughThePanel($this->authorizedReturn(3, $lineId));
        $afterReturn = $this->reading('Red');

        $trait = new class { use \App\Traits\OrderEditManager; };
        $call = (new \ReflectionClass($trait))->getMethod('adjustProductStock');
        $call->setAccessible(true);

        $call->invoke($trait, 70, 'Red', 3, 'increment');
        $afterIncrement = $this->reading('Red');
        $call->invoke($trait, 70, 'Red', 3, 'decrement');
        $afterPair = $this->reading('Red');

        DB::table('products')->where('id', 70)->update(['current_stock' => 1, 'variation' => json_encode([['type' => 'Red', 'price' => 10, 'sku' => 'R', 'qty' => 1]])]);
        $call->invoke($trait, 70, 'Red', 3, 'decrement');
        $floor = $this->reading('Red');

        fwrite(STDERR, "\nPROBE A-F5: afterReturn=" . json_encode($afterReturn)
            . "\n  afterIncrement(alone)=" . json_encode($afterIncrement)
            . "\n  afterIncrementThenDecrement(the real pair)=" . json_encode($afterPair)
            . "\n  decrement_with_one_on_the_shelf=" . json_encode($floor) . "\n");
        $this->assertTrue(true);
    }

    // ---- B-F10 : OrderRepository is never raced; prove it runs the same budget --------------------
    public function test_probe_bf10_repository_copy_reachable(): void
    {
        $lineId = $this->soldLine(3);
        $this->receiveThroughThePanel($this->authorizedReturn(1, $lineId));
        app(OrderRepository::class)->updateStockOnOrderStatusChange(700, 'returned');
        fwrite(STDERR, "\nPROBE B-F10: stock=" . $this->stock() . " marker=" . $this->marker($lineId) . "\n");
        $this->assertTrue(true);
    }

    // ---- A-F5 through the real trait method the eight order-edit controllers call ------------------
    public function test_probe_af5_order_edit_through_the_real_writer(): void
    {
        $this->widenOrderDetailsForTheEditWriter();
        $lineId = $this->soldLine(3, variant: 'Red', variations: [['type' => 'Red', 'price' => 10, 'sku' => 'R', 'qty' => 20]]);
        $this->receiveThroughThePanel($this->authorizedReturn(3, $lineId));
        $afterReturn = [$this->reading('Red'), $this->marker($lineId)];

        $editor = new class { use \App\Traits\OrderEditManager; };
        $order = \App\Models\Order::with('details')->find(700);
        $edited = [[
            'product_id' => 70, 'variant' => 'Red', 'qty' => 3, 'price' => 10, 'discount' => 0,
            'seller_id' => null, 'product_details' => null, 'tax_model' => 'exclude',
            'delivery_status' => 'confirmed', 'payment_status' => 'paid', 'variation' => null,
        ]];

        $editor->addAndUpdateProductsInOrderDetails($order, $edited, ['applied_tax_cart_list' => []]);
        $sameQty = [$this->reading('Red'), $this->marker($lineId), (int) DB::table('order_details')->where('order_id', 700)->count()];

        fwrite(STDERR, "\nPROBE A-F5 real writer: afterReturn=" . json_encode($afterReturn)
            . "\n  after an edit that keeps the line at 3=" . json_encode($sameQty) . "\n");
        $this->assertTrue(true);
    }

    public function test_probe_af5_order_edit_removes_the_returned_line(): void
    {
        $this->widenOrderDetailsForTheEditWriter();
        $lineId = $this->soldLine(3, variant: 'Red', variations: [['type' => 'Red', 'price' => 10, 'sku' => 'R', 'qty' => 20]]);
        $this->receiveThroughThePanel($this->authorizedReturn(3, $lineId));
        $afterReturn = [$this->reading('Red'), $this->marker($lineId)];

        $editor = new class { use \App\Traits\OrderEditManager; };
        $order = \App\Models\Order::with('details')->find(700);
        // The edit drops the returned line and buys a different product instead.
        DB::table('products')->insert([
            'id' => 71, 'name' => 'Other', 'added_by' => 'admin', 'product_type' => 'physical',
            'current_stock' => 5, 'variation' => json_encode([]), 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $edited = [[
            'product_id' => 71, 'variant' => null, 'qty' => 1, 'price' => 10, 'discount' => 0,
            'seller_id' => null, 'product_details' => null, 'tax_model' => 'exclude',
            'delivery_status' => 'confirmed', 'payment_status' => 'paid', 'variation' => null,
        ]];

        $editor->addAndUpdateProductsInOrderDetails($order, $edited, ['applied_tax_cart_list' => []]);

        fwrite(STDERR, "\nPROBE A-F5 line removed: afterReturn=" . json_encode($afterReturn)
            . "\n  after the edit: product70=" . json_encode($this->reading('Red'))
            . " product71_stock=" . DB::table('products')->where('id', 71)->value('current_stock') . "\n");
        $this->assertTrue(true);
    }
}
