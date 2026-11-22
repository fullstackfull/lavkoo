<?php

namespace Tests\Feature\Inventory;

use App\Models\ReturnShipment;
use App\Models\Seller;
use App\Models\StockMovement;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Fulfillment\AdminPagesTestCase;

/**
 * The shelf can never gain more units than left it — proved on the routes a person actually works on.
 *
 * `POST admin/orders/status` (admin.orders.status) is the panel's order status change, and
 * `POST admin/marketplace/returns/{id}/receive` (admin.marketplace.returns.receive) is the returns queue
 * receiving goods. Both are driven here through the HTTP kernel with the real controllers, the real
 * repository and the real service: the first runs OrderRepository::updateStockOnOrderStatusChange, the
 * second ReturnLogisticsService::receive. Three ways the shelf used to gain units that never left it:
 *
 *  - re-opening a cancelled line drove the variant's quantity in products.variation negative, while
 *    current_stock beside it was floored at zero;
 *  - a partial return received and then the order marked returned put the whole line back on top of the
 *    part already returned (four units back for three that left);
 *  - a return typed for more units than the order line held put the typed figure on the shelf.
 *
 * Each reading below is the three figures that matter together — products.current_stock, the variant's
 * quantity inside products.variation, and the stock_movements rows — because the defects showed up in
 * different ones.
 */
class RestockThroughTheRealRoutesTest extends AdminPagesTestCase
{
    private const PRODUCT = 70;

    private const ORDER = 700;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildTheTablesTheseRoutesTouch();
    }

    /**
     * The platform's own tables these two routes read, which the fulfillment schema does not infer.
     *
     * `return_shipments` and `stock_movements` have no migration in this repository (they come from the
     * installation dump), so their columns are what ReturnShipment::$fillable and InventoryService::record()
     * write. The rest are the tables the status-change controller reads on its way past the stock writer,
     * with only the columns it asks for.
     */
    private function buildTheTablesTheseRoutesTouch(): void
    {
        require_once database_path('migrations/2021_04_17_134848_add_column_to_order_details_stock.php');
        (new \AddColumnToOrderDetailsStock())->up();

        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('email')->nullable();
            $table->decimal('wallet_balance', 40, 20)->default(0);
            $table->timestamps();
        });
        Schema::create('order_details_rewards', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('reward_type')->nullable();
            $table->decimal('reward_amount', 40, 20)->default(0);
            $table->boolean('reward_delivered')->default(false);
            $table->timestamps();
        });
        Schema::create('order_status_histories', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_type')->nullable();
            $table->string('status')->nullable();
            $table->string('cause')->nullable();
            $table->timestamps();
        });
        Schema::create('order_transactions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
        Schema::create('order_edit_histories', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->decimal('order_due_amount', 40, 20)->default(0);
            $table->decimal('order_return_amount', 40, 20)->default(0);
            $table->string('order_due_payment_status')->nullable();
            $table->string('order_due_payment_method')->nullable();
            $table->string('order_return_payment_status')->nullable();
            $table->timestamps();
        });
        Schema::create('referral_customers', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->boolean('delivered_notify')->default(false);
            $table->timestamps();
        });
        Schema::create('return_shipments', static function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('order_details_id')->nullable();
            $table->unsignedBigInteger('refund_request_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->integer('qty')->default(1);
            $table->string('reason')->nullable();
            $table->string('status', 20)->default(ReturnShipment::STATUS_AUTHORIZED);
            $table->boolean('restock')->default(1);
            $table->string('carrier')->nullable();
            $table->string('tracking_number')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_type', 24)->nullable();
            $table->timestamps();
        });
        Schema::create('stock_movements', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('type', 24);
            $table->integer('qty_change');
            $table->integer('balance_after')->nullable();
            $table->string('reason', 40)->nullable();
            $table->string('reference_type', 40)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_type', 24)->nullable();
            $table->timestamps();
        });
    }

    /** One paid order of `$quantity` units that have already left the shelf (is_stock_decreased = 1). */
    protected function soldLine(int $quantity, int $stock = 50, ?string $variant = null, ?array $variations = null): int
    {
        DB::table('products')->insert([
            'id' => self::PRODUCT, 'name' => 'Product', 'added_by' => 'admin', 'product_type' => 'physical',
            'current_stock' => $stock, 'variation' => json_encode($variations ?? []), 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('orders')->insert([
            'id' => self::ORDER, 'customer_id' => 1, 'is_guest' => 1, 'order_status' => 'confirmed',
            'payment_status' => 'paid', 'payment_method' => 'cash_on_delivery', 'seller_is' => 'admin',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('order_details')->insertGetId([
            'order_id' => self::ORDER, 'product_id' => self::PRODUCT, 'qty' => $quantity, 'price' => 10,
            'variant' => $variant, 'is_stock_decreased' => 1, 'delivery_status' => 'confirmed',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The panel's order status change: POST admin/orders/status. */
    protected function changeStatusThroughThePanel(string $status): void
    {
        $this->post(route('admin.orders.status'), ['id' => self::ORDER, 'order_status' => $status])
            ->assertOk()
            ->assertJson(['status' => 1]);
    }

    /** The returns queue receiving goods: POST admin/marketplace/returns/{id}/receive. */
    protected function receiveThroughThePanel(ReturnShipment $rma): void
    {
        $this->post(route('admin.marketplace.returns.receive', $rma->id))->assertRedirect();
    }

    protected function authorizedReturn(int $quantity, int $lineId): ReturnShipment
    {
        return ReturnShipment::create([
            'reference' => 'RMA-' . $quantity . '-' . $lineId,
            'order_id' => self::ORDER,
            'order_details_id' => $lineId,
            'product_id' => self::PRODUCT,
            'seller_id' => 7,
            'qty' => $quantity,
            'reason' => 'damaged_box',
            'status' => ReturnShipment::STATUS_AUTHORIZED,
            'restock' => true,
        ]);
    }

    /** The three figures that matter: current_stock, the variant's quantity, and the return movements as [qty_change, balance_after, note]. */
    protected function reading(?string $variant = null): array
    {
        $product = DB::table('products')->where('id', self::PRODUCT)->first();
        $variations = json_decode((string) $product->variation, true) ?: [];

        return [
            'current_stock' => (int) $product->current_stock,
            'variant_qty' => $variant === null ? null : (int) (collect($variations)->firstWhere('type', $variant)['qty'] ?? 0),
            'movements' => StockMovement::orderBy('id')
                ->get()
                ->map(static fn (StockMovement $m): array => [$m->type, (int) $m->qty_change, (int) $m->balance_after, $m->note])
                ->all(),
        ];
    }

    // ---- (a) re-opening a cancelled line ---------------------------------------------------------------------------------

    public function test_re_opening_a_cancelled_line_through_the_panel_never_drives_the_variant_below_zero(): void
    {
        $this->soldLine(3, variant: 'Red', variations: [['type' => 'Red', 'price' => 10, 'sku' => 'R', 'qty' => 20]]);

        $this->changeStatusThroughThePanel('canceled');
        $this->assertSame(['current_stock' => 53, 'variant_qty' => 23, 'movements' => []], $this->reading('Red'));

        // Out of band, in the product form, the shelf is emptied while the order sits cancelled.
        DB::table('products')->where('id', self::PRODUCT)->update([
            'current_stock' => 1, 'variation' => json_encode([['type' => 'Red', 'price' => 10, 'sku' => 'R', 'qty' => 1]]),
        ]);

        $this->changeStatusThroughThePanel('processing');

        $this->assertSame(
            ['current_stock' => 0, 'variant_qty' => 0, 'movements' => []],
            $this->reading('Red'),
            'one unit on the shelf cannot pay for three: both figures stop at zero, neither goes negative',
        );
    }

    // ---- (b) a partial return, then the order marked returned ------------------------------------------------------------

    public function test_a_partial_return_then_the_order_marked_returned_puts_back_exactly_what_left_the_shelf(): void
    {
        $lineId = $this->soldLine(3);

        $this->receiveThroughThePanel($this->authorizedReturn(1, $lineId));
        $this->assertSame([
            'current_stock' => 51,
            'variant_qty' => null,
            'movements' => [[StockMovement::TYPE_RETURN, 1, 51, null]],
        ], $this->reading());
        $this->assertSame(1, (int) DB::table('order_details')->where('id', $lineId)->value('is_stock_decreased'), 'a partial receipt leaves the marker for the status writer');

        $this->changeStatusThroughThePanel('returned');

        $this->assertSame([
            'current_stock' => 53,
            'variant_qty' => null,
            'movements' => [[StockMovement::TYPE_RETURN, 1, 51, null]],
        ], $this->reading(), 'three units left the shelf and three came back: 1 by the receipt and the remaining 2 by the status writer');
        $this->assertSame(0, (int) DB::table('order_details')->where('id', $lineId)->value('is_stock_decreased'));
    }

    public function test_a_partial_return_received_after_the_order_was_marked_returned_puts_nothing_back(): void
    {
        $lineId = $this->soldLine(3);
        $this->changeStatusThroughThePanel('returned');
        $this->assertSame(53, $this->reading()['current_stock']);

        $this->receiveThroughThePanel($rma = $this->authorizedReturn(1, $lineId));

        $this->assertSame(53, $this->reading()['current_stock'], 'the status writer put the whole line back: a part of it cannot come back again');
        $this->assertSame([], $this->reading()['movements']);
        $this->assertSame(ReturnShipment::STATUS_RECEIVED, $rma->fresh()->status);
    }

    public function test_two_partial_returns_that_together_complete_the_line_leave_its_marker_claimed(): void
    {
        $lineId = $this->soldLine(3);

        $this->receiveThroughThePanel($this->authorizedReturn(1, $lineId));
        $this->assertSame(1, (int) DB::table('order_details')->where('id', $lineId)->value('is_stock_decreased'), 'one of three units back: the line is still a line whose stock is taken');

        $this->receiveThroughThePanel($this->authorizedReturn(2, $lineId));

        $this->assertSame(53, $this->reading()['current_stock'], 'three units left the shelf and three came back, in two receipts');
        $this->assertSame(
            0,
            (int) DB::table('order_details')->where('id', $lineId)->value('is_stock_decreased'),
            'the claim is on the cumulative returned quantity: the receipt that completes the line takes the marker, '
            . 'so the flag does not go on saying the units are off the shelf while they are on it',
        );

        // And the status writer, finding the marker already taken, adds nothing.
        $this->changeStatusThroughThePanel('returned');
        $this->assertSame(53, $this->reading()['current_stock']);
    }

    // ---- re-opening a line whose units have partly or wholly come back ------------------------------------------------------

    public function test_re_opening_a_line_after_a_return_takes_all_of_its_units_off_the_shelf_again(): void
    {
        $lineId = $this->soldLine(3);
        $this->receiveThroughThePanel($this->authorizedReturn(1, $lineId));
        $this->changeStatusThroughThePanel('returned');
        $this->assertSame(53, $this->reading()['current_stock']);

        $this->changeStatusThroughThePanel('processing');

        $this->assertSame(
            50,
            $this->reading()['current_stock'],
            'the order is live for three units again, so three come off the shelf -- bounding this direction by what '
            . 'returns had put back would leave the shelf offering units an open order has already promised',
        );
        $this->assertSame(1, (int) DB::table('order_details')->where('id', $lineId)->value('is_stock_decreased'));
    }

    public function test_re_opening_a_line_whose_whole_quantity_came_back_takes_all_of_it_off_the_shelf_again(): void
    {
        $lineId = $this->soldLine(3);
        $this->receiveThroughThePanel($this->authorizedReturn(3, $lineId));
        $this->assertSame(53, $this->reading()['current_stock']);

        $this->changeStatusThroughThePanel('processing');

        $this->assertSame(50, $this->reading()['current_stock']);
    }

    public function test_marking_a_re_opened_line_returned_again_currently_puts_nothing_back(): void
    {
        // A known limitation, pinned so it is visible rather than surprising. RestockBudget reads
        // return_shipments, which is a record over all time and not of the current cycle: once a line has
        // been re-opened, the units those returns put back have left the shelf again, and nothing records
        // that. So a second trip round the cycle restores nothing where it should restore three.
        //
        // The error is in the safe direction -- the shelf understates what it holds, which cannot oversell,
        // where the old code overstated it (3 out, 6 back). Fixing it properly needs the current cycle to be
        // recorded, which is the movement log the status writer does not yet write (see the PR body).
        $lineId = $this->soldLine(3);
        $this->receiveThroughThePanel($this->authorizedReturn(3, $lineId));
        $this->changeStatusThroughThePanel('processing');
        $this->assertSame(50, $this->reading()['current_stock']);

        $this->changeStatusThroughThePanel('returned');

        $this->assertSame(50, $this->reading()['current_stock'], 'should be 53: the three units are with the customer again and are coming back a second time');
        $this->assertSame(0, (int) DB::table('order_details')->where('id', $lineId)->value('is_stock_decreased'), 'the marker is right even though the units are not');
    }

    // ---- (c) a return for more units than the line held --------------------------------------------------------------------

    public function test_a_return_for_more_units_than_the_line_held_restocks_the_lines_quantity_and_says_so(): void
    {
        $lineId = $this->soldLine(2);

        $this->receiveThroughThePanel($rma = $this->authorizedReturn(5, $lineId));

        $this->assertSame([
            'current_stock' => 52,
            'variant_qty' => null,
            'movements' => [[StockMovement::TYPE_RETURN, 2, 52, 'asked for 5, the line held 2']],
        ], $this->reading(), 'two units left the shelf, so two came back — and the movement row records the figure asked for');
        $this->assertSame(ReturnShipment::STATUS_RESTOCKED, $rma->fresh()->status);
        $this->assertSame(5, (int) $rma->fresh()->qty, 'what the operator typed is kept on the return itself');
    }

    public function test_a_return_that_points_at_no_order_line_still_restocks_its_whole_quantity(): void
    {
        // The returns queue lets a return name a product and no line (ReturnLogisticsController::store makes
        // order_details_id optional). There is no line, so there is no budget to bound it and nothing to clamp.
        $this->soldLine(2);
        $rma = ReturnShipment::create([
            'reference' => 'RMA-no-line', 'product_id' => self::PRODUCT, 'qty' => 4,
            'status' => ReturnShipment::STATUS_AUTHORIZED, 'restock' => true,
        ]);

        $this->receiveThroughThePanel($rma);

        $this->assertSame([
            'current_stock' => 54,
            'variant_qty' => null,
            'movements' => [[StockMovement::TYPE_RETURN, 4, 54, null]],
        ], $this->reading(), 'a return with no line behaves exactly as it did before: its own quantity, no cap, no note');
        $this->assertSame(ReturnShipment::STATUS_RESTOCKED, $rma->fresh()->status);
    }

    public function test_the_seller_app_is_told_the_same_thing_the_panel_is(): void
    {
        $lineId = $this->soldLine(2);
        $rma = $this->authorizedReturn(5, $lineId);
        $rma->update(['seller_id' => 5]);

        // POST api/v3/seller/returns/{id}/receive, as SellerApiAuthMiddleware hands it to the controller.
        $request = Request::create('/api/v3/seller/returns/' . $rma->id . '/receive', 'POST');
        $request['seller'] = (new Seller())->forceFill(['id' => 5]);
        $answer = app(\App\Http\Controllers\RestAPI\v3\seller\SellerReturnController::class)->receive($request, $rma->id);

        $this->assertSame(200, $answer->status());
        $body = $answer->getData(true);
        $this->assertSame(2, $body['restocked_units']);
        $this->assertSame(['asked' => 5, 'restocked' => 2, 'line_held' => 2], $body['clamped']);
        $this->assertSame(52, $this->reading()['current_stock']);
    }
}
