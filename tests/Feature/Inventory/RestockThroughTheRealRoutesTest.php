<?php

namespace Tests\Feature\Inventory;

use App\Models\Order;
use App\Models\ReturnShipment;
use App\Models\Seller;
use App\Services\Marketplace\ReturnLogisticsService;
use App\Models\StockMovement;
use App\Utils\OrderManager;
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

    /** The returns queue authorising a return: POST admin/marketplace/returns/store, exactly the form's own fields. */
    protected function authorizeThroughThePanel(array $fields): ReturnShipment
    {
        $this->post(route('admin.marketplace.returns.store'), $fields + ['qty' => 1, 'restock' => 1])->assertRedirect();

        return ReturnShipment::orderByDesc('id')->firstOrFail();
    }

    protected function authorizedReturn(int $quantity, int $lineId): ReturnShipment
    {
        return ReturnShipment::create([
            'reference' => 'RMA-' . $quantity . '-' . $lineId . '-' . (ReturnShipment::count() + 1),
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

    /** The text of the toasts the last request flashed -- what the operator actually reads. */
    protected function toastsTheOperatorSees(): array
    {
        return array_map(
            static fn (array $toast): string => (string) ($toast['message'] ?? ''),
            array_values((array) session('laravel-toaster-magic::messages', [])),
        );
    }

    /** The shelf and the line's claim on it: [products.current_stock, order_details.is_stock_decreased]. */
    protected function shelfAndClaim(int $lineId): array
    {
        return [
            (int) DB::table('products')->where('id', self::PRODUCT)->value('current_stock'),
            (int) DB::table('order_details')->where('id', $lineId)->value('is_stock_decreased'),
        ];
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

    public function test_going_round_the_cycle_again_puts_nothing_back_but_never_drains_the_shelf(): void
    {
        // A known limitation and a defect that grew out of it, so both are pinned here. RestockBudget reads
        // return_shipments, a record over all time and not of the current cycle: once a line has been
        // re-opened, the units its returns put back have left the shelf again and nothing records that, so a
        // second trip round the cycle restores nothing where it should restore three. That is the limitation,
        // and it is in the safe direction -- the shelf understates what it holds, which cannot oversell.
        //
        // The DEFECT was what that restore of nothing did to the line's claim. is_stock_decreased is the claim
        // on the line's units: 1 means they are off the shelf. The status writer flipped it to 0 before it
        // knew it had nothing to put back, so the record said the units were on the shelf while they were with
        // the customer -- and the NEXT re-open took them off a second time. Round and round, the shelf lost
        // the line's quantity every cycle, without bound: 53, 50, 50, 47, 47, 44, 41. Now the claim is left
        // where it is, so the shelf stops at 50 and the re-open that follows finds the claim already taken and
        // moves nothing.
        //
        // Fixing the limitation itself needs the current cycle recorded, which is the movement log the status
        // writer does not yet write (see the PR body).
        $lineId = $this->soldLine(3);
        $this->receiveThroughThePanel($this->authorizedReturn(3, $lineId));
        $this->assertSame(53, $this->reading()['current_stock']);

        for ($cycle = 1; $cycle <= 4; $cycle++) {
            $this->changeStatusThroughThePanel('processing');
            $this->assertSame([50, 1], $this->shelfAndClaim($lineId), "cycle {$cycle}: the line is live for three units again, so three are off the shelf");

            $this->changeStatusThroughThePanel('returned');
            $this->assertSame(
                [50, 1],
                $this->shelfAndClaim($lineId),
                "cycle {$cycle}: nothing is put back (the limitation), and the claim stays with the line so the "
                . 'next re-open cannot take the same units off the shelf a second time',
            );
        }
    }

    public function test_the_rest_api_copy_of_the_status_writer_never_drains_the_shelf_either(): void
    {
        // OrderManager::getStockUpdateOnOrderStatusChange is the copy the REST API controllers run (customer
        // cancel, v2/v3 seller, delivery man) and it had the same defect. Driven directly, because no HTTP
        // route in this fixture reaches it; the panel's copy is driven through its route above.
        $lineId = $this->soldLine(3, stock: 10);
        $this->receiveThroughThePanel($this->authorizedReturn(3, $lineId));
        $this->assertSame(13, $this->reading()['current_stock']);

        for ($cycle = 1; $cycle <= 3; $cycle++) {
            OrderManager::getStockUpdateOnOrderStatusChange(Order::findOrFail(self::ORDER), 'processing');
            $this->assertSame([10, 1], $this->shelfAndClaim($lineId), "cycle {$cycle}: re-opened");

            OrderManager::getStockUpdateOnOrderStatusChange(Order::findOrFail(self::ORDER), 'returned');
            $this->assertSame([10, 1], $this->shelfAndClaim($lineId), "cycle {$cycle}: returned again");
        }
    }

    // ---- a receipt that can put nothing back must leave the line's claim alone -------------------------------------------

    public function test_a_second_genuine_return_of_a_re_opened_line_leaves_the_line_claimed(): void
    {
        // The goods really are on the receiving desk a second time, and the budget has no room for them
        // (the limitation pinned above). What this must not do is take the line's claim on the way past:
        // the claim said the units were on the shelf while they were on the desk, and the order status
        // change that followed could not restore them either, because the claim was already gone.
        $lineId = $this->soldLine(3);
        $this->receiveThroughThePanel($this->authorizedReturn(3, $lineId));
        $this->changeStatusThroughThePanel('processing');
        $this->assertSame([50, 1], $this->shelfAndClaim($lineId));

        $second = $this->authorizedReturn(3, $lineId);
        $this->receiveThroughThePanel($second);

        $this->assertSame([50, 1], $this->shelfAndClaim($lineId), 'nothing goes back, and the claim stays with the line');
        $this->assertSame(ReturnShipment::STATUS_RECEIVED, $second->fresh()->status, 'a receipt that restocked nothing is received, not restocked');
        $this->assertSame([[StockMovement::TYPE_RETURN, 3, 53, null]], $this->reading()['movements'], 'and writes no movement row for a non-event');

        $this->changeStatusThroughThePanel('returned');
        $this->assertSame([50, 1], $this->shelfAndClaim($lineId));
    }

    public function test_a_receipt_against_a_line_of_no_units_takes_neither_stock_nor_the_claim(): void
    {
        // A line of quantity zero holds no units, so there is nothing to put back. The claim used to be
        // flipped all the same, because "this return completes the line" is true of any return against a
        // line of nothing.
        $lineId = $this->soldLine(0);

        $this->receiveThroughThePanel($rma = $this->authorizedReturn(2, $lineId));

        $this->assertSame([50, 1], $this->shelfAndClaim($lineId));
        $this->assertSame(ReturnShipment::STATUS_RECEIVED, $rma->fresh()->status);
    }

    public function test_a_partial_receipt_after_the_line_went_round_the_cycle_leaves_the_claim_alone(): void
    {
        // Two partial receipts complete the line, the order is re-opened, and a third partial arrives.
        $lineId = $this->soldLine(3);
        $this->receiveThroughThePanel($this->authorizedReturn(1, $lineId));
        $this->receiveThroughThePanel($this->authorizedReturn(2, $lineId));
        $this->assertSame([53, 0], $this->shelfAndClaim($lineId));

        $this->changeStatusThroughThePanel('processing');
        $this->assertSame([50, 1], $this->shelfAndClaim($lineId));

        $this->receiveThroughThePanel($third = $this->authorizedReturn(1, $lineId));

        $this->assertSame([50, 1], $this->shelfAndClaim($lineId));
        $this->assertSame(ReturnShipment::STATUS_RECEIVED, $third->fresh()->status);
        $this->assertCount(2, $this->reading()['movements'], 'the two receipts that put units back, and no third row');
    }

    // ---- the route a person creates a return on --------------------------------------------------------------------------

    public function test_a_return_authorized_in_the_panel_puts_back_what_left_the_shelf_and_no_more(): void
    {
        // The returns queue's form (admin-views/marketplace/returns.blade.php) offers an order and a product
        // and NO line, so every return authorised by hand carried order_details_id = null -- and with no line
        // there is no budget, no cap and no claim. The receipt put its typed quantity on the shelf and the
        // order status writer then put the whole line back on top of it: 50 -> 53 -> 56 for three units that
        // left. The whole mechanism was dormant on the one route that creates returns by hand.
        $lineId = $this->soldLine(3, variant: 'Red', variations: [['type' => 'Red', 'price' => 10, 'sku' => 'R', 'qty' => 20]]);

        $rma = $this->authorizeThroughThePanel(['order_id' => self::ORDER, 'product_id' => self::PRODUCT, 'qty' => 3]);

        $this->assertSame($lineId, (int) $rma->order_details_id, 'the order and the product name exactly one line, so the return is for that line');

        $this->receiveThroughThePanel($rma);
        $this->assertSame(['current_stock' => 53, 'variant_qty' => 23, 'movements' => [[StockMovement::TYPE_RETURN, 3, 53, null]]], $this->reading('Red'));

        $this->changeStatusThroughThePanel('returned');

        $this->assertSame(
            ['current_stock' => 53, 'variant_qty' => 23, 'movements' => [[StockMovement::TYPE_RETURN, 3, 53, null]]],
            $this->reading('Red'),
            'three units left the shelf and three came back -- not six, and the variant moves with current_stock',
        );
        $this->assertSame([53, 0], $this->shelfAndClaim($lineId));
    }

    public function test_a_line_belonging_to_another_order_is_not_the_line_a_return_is_for(): void
    {
        // Nothing validated the line id, so a mistyped one clamped this return against a stranger's line and
        // flipped THAT order's claim -- after which the stranger's own return could restore nothing.
        $lineId = $this->soldLine(3);
        DB::table('orders')->insert([
            'id' => 701, 'customer_id' => 1, 'is_guest' => 1, 'order_status' => 'confirmed', 'payment_status' => 'paid',
            'payment_method' => 'cash_on_delivery', 'seller_is' => 'admin', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $strangersLine = (int) DB::table('order_details')->insertGetId([
            'order_id' => 701, 'product_id' => self::PRODUCT, 'qty' => 1, 'price' => 10, 'is_stock_decreased' => 1,
            'delivery_status' => 'confirmed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $rma = $this->authorizeThroughThePanel([
            'order_id' => self::ORDER, 'order_details_id' => $strangersLine, 'product_id' => self::PRODUCT, 'qty' => 3,
        ]);

        $this->assertSame($lineId, (int) $rma->order_details_id, "a line id from order 701 is not used for a return on order 700");

        $this->receiveThroughThePanel($rma);

        $this->assertSame([53, 0], $this->shelfAndClaim($lineId), 'the three units of this order came back, uncapped by a stranger of quantity one');
        $this->assertSame(1, (int) DB::table('order_details')->where('id', $strangersLine)->value('is_stock_decreased'), "the other order's claim on its own unit is untouched");
    }

    public function test_two_lines_of_one_product_in_one_order_are_left_alone_rather_than_guessed_at(): void
    {
        $this->soldLine(3, variant: 'Red');
        DB::table('order_details')->insert([
            'order_id' => self::ORDER, 'product_id' => self::PRODUCT, 'qty' => 2, 'price' => 10, 'variant' => 'Blue',
            'is_stock_decreased' => 1, 'delivery_status' => 'confirmed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $rma = $this->authorizeThroughThePanel(['order_id' => self::ORDER, 'product_id' => self::PRODUCT, 'qty' => 1]);

        $this->assertNull($rma->order_details_id, 'the order and the product do not name one line, so none is chosen');
    }

    public function test_a_return_naming_only_a_product_reaches_into_no_order(): void
    {
        // A product on its own must not be attached to whatever order happens to hold the only line of it.
        $this->soldLine(3);

        $rma = $this->authorizeThroughThePanel(['product_id' => self::PRODUCT, 'qty' => 1]);

        $this->assertNull($rma->order_details_id);
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


    // ---- what the operator is told when fewer units go back than were asked for -------------------------------------------

    public function test_a_clamp_caused_by_units_already_back_says_that_and_not_something_untrue(): void
    {
        // Two receipts of 2 against a line of 3. The second is clamped to 1 -- not because 2 is more than the
        // line held (it is not) but because 2 of the line's 3 units were already back. The report said "more
        // units were asked for than the order line held", which is the opposite of the truth, and carried no
        // figure an operator could use to see why.
        $lineId = $this->soldLine(3);
        $this->receiveThroughThePanel($this->authorizedReturn(2, $lineId));
        $this->assertSame(52, $this->reading()['current_stock']);

        $this->receiveThroughThePanel($this->authorizedReturn(2, $lineId));

        $this->assertSame(53, $this->reading()['current_stock'], 'one unit of the three was still off the shelf, so one went back');
        $this->assertSame(
            [[StockMovement::TYPE_RETURN, 2, 52, null], [StockMovement::TYPE_RETURN, 1, 53, "asked for 2, 2 of the line's 3 units were already back"]],
            $this->reading()['movements'],
        );
        $this->assertContains(
            translate('some_of_the_lines_units_were_already_back_so_only_the_rest_were_restocked') . ': 2 → 1',
            $this->toastsTheOperatorSees(),
            'the operator is warned, and the warning names the bound that applied',
        );
        $this->assertNotContains(
            translate('more_units_were_asked_for_than_the_line_held_only_the_lines_units_were_restocked') . ': 2 → 1',
            $this->toastsTheOperatorSees(),
        );
    }

    public function test_a_clamp_caused_by_the_lines_size_still_says_so_and_warns_the_operator(): void
    {
        $lineId = $this->soldLine(2);

        $this->receiveThroughThePanel($this->authorizedReturn(5, $lineId));

        $this->assertSame([[StockMovement::TYPE_RETURN, 2, 52, 'asked for 5, the line held 2']], $this->reading()['movements']);
        $this->assertContains(
            translate('more_units_were_asked_for_than_the_line_held_only_the_lines_units_were_restocked') . ': 5 → 2',
            $this->toastsTheOperatorSees(),
        );
    }

    public function test_a_receipt_clamped_all_the_way_to_zero_is_still_reported(): void
    {
        // The clamp's worst case, and the one it used to be silent about: goods are on the receiving desk
        // and not one unit of them can go on the shelf. `clamped` was nulled unless something was restocked,
        // so the operator saw only "Return received" and the audit record carried nothing.
        $lineId = $this->soldLine(2);
        $this->receiveThroughThePanel($this->authorizedReturn(5, $lineId));
        $this->assertSame(52, $this->reading()['current_stock']);

        $second = $this->authorizedReturn(5, $lineId);
        $result = app(ReturnLogisticsService::class)->receive($second);

        $this->assertFalse($result['restocked']);
        $this->assertSame(0, $result['restocked_units']);
        $this->assertSame(
            ['asked' => 5, 'restocked' => 0, 'already_back' => 2, 'line_held' => 2],
            $result['clamped'],
            'already_back is 2 and not the 5 the first return was typed for: what reached the shelf, not what was asked',
        );
        $this->assertSame(52, $this->reading()['current_stock']);
    }

    public function test_the_panel_warns_the_operator_about_a_receipt_clamped_to_nothing(): void
    {
        $lineId = $this->soldLine(2);
        $this->receiveThroughThePanel($this->authorizedReturn(5, $lineId));

        $this->receiveThroughThePanel($this->authorizedReturn(5, $lineId));

        $this->assertContains(
            translate('some_of_the_lines_units_were_already_back_so_only_the_rest_were_restocked') . ': 5 → 0',
            $this->toastsTheOperatorSees(),
            'goods on the desk that the shelf will not take: the operator is the only one who can correct the return',
        );
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

    // ---- the fourth writer of the same shelf: the order edit screen -------------------------------------------------------

    /**
     * The columns the order-edit writer writes that the fulfillment schema does not infer, and a stand-in
     * for the trait so the real method can be driven without one of its eight controllers' request stacks.
     */
    private function orderEditWriter(): object
    {
        Schema::table('order_details', static function (Blueprint $table): void {
            $table->string('digital_file_after_sell')->nullable();
            $table->unsignedBigInteger('shipping_method_id')->nullable();
            $table->string('discount_type')->nullable();
            $table->boolean('refund_request')->default(0);
            $table->timestamp('refund_started_at')->nullable();
        });

        return new class {
            use \App\Traits\OrderEditManager;
        };
    }

    /** One edited line of the product, as the edit screens hand it to the writer. */
    private function editedLine(int $quantity, ?string $variant = null, int $productId = self::PRODUCT): array
    {
        return [[
            'product_id' => $productId, 'variant' => $variant, 'qty' => $quantity, 'price' => 10, 'discount' => 0,
            'seller_id' => null, 'product_details' => null, 'tax_model' => 'exclude',
            'delivery_status' => 'confirmed', 'payment_status' => 'paid', 'variation' => null,
        ]];
    }

    public function test_the_order_edit_writer_does_not_give_back_units_a_return_already_put_on_the_shelf(): void
    {
        // A FOURTH writer of an order line's stock, reached from the order edit screen by eight controllers.
        // It gave every current line's units back unconditionally before rebuilding the lines, so a line whose
        // goods had already come back was given back a second time. With the quantity unchanged the pair
        // netted zero on the shelf while the line's claim went back to 1 -- the same three units counted as
        // both on the shelf and promised to the customer, which is an oversell by the record.
        $writer = $this->orderEditWriter();
        $lineId = $this->soldLine(3, variant: 'Red', variations: [['type' => 'Red', 'price' => 10, 'sku' => 'R', 'qty' => 20]]);
        $this->receiveThroughThePanel($this->authorizedReturn(3, $lineId));
        $this->assertSame([53, 0], $this->shelfAndClaim($lineId), 'three units left the shelf and three came back');

        $writer->addAndUpdateProductsInOrderDetails(Order::with('details')->findOrFail(self::ORDER), $this->editedLine(3, 'Red'), ['applied_tax_cart_list' => []]);

        $this->assertSame(
            ['current_stock' => 50, 'variant_qty' => 20, 'movements' => [[StockMovement::TYPE_RETURN, 3, 53, null]]],
            $this->reading('Red'),
            'the edited line is live for three units, so three come off the shelf -- they are not given back first',
        );
        $this->assertSame(1, (int) DB::table('order_details')->where('order_id', self::ORDER)->value('is_stock_decreased'));
    }

    public function test_an_edit_that_drops_a_returned_line_puts_no_units_on_the_shelf(): void
    {
        $writer = $this->orderEditWriter();
        $lineId = $this->soldLine(3, variant: 'Red', variations: [['type' => 'Red', 'price' => 10, 'sku' => 'R', 'qty' => 20]]);
        $this->receiveThroughThePanel($this->authorizedReturn(3, $lineId));
        DB::table('products')->insert([
            'id' => 71, 'name' => 'Other', 'added_by' => 'admin', 'product_type' => 'physical', 'current_stock' => 5,
            'variation' => json_encode([]), 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $writer->addAndUpdateProductsInOrderDetails(Order::with('details')->findOrFail(self::ORDER), $this->editedLine(1, null, 71), ['applied_tax_cart_list' => []]);

        $this->assertSame(
            ['current_stock' => 53, 'variant_qty' => 23, 'movements' => [[StockMovement::TYPE_RETURN, 3, 53, null]]],
            $this->reading('Red'),
            'the three returned units are already on the shelf: dropping the line must not put them there twice',
        );
        $this->assertSame(4, (int) DB::table('products')->where('id', 71)->value('current_stock'), 'and the product the edit bought instead is taken from');
    }

    public function test_the_order_edit_writer_floors_the_variant_at_zero(): void
    {
        // The same defect as (a), in the third copy of it: current_stock was floored and the variant's
        // quantity inside products.variation was not.
        $writer = $this->orderEditWriter();
        $this->soldLine(3, variant: 'Red', variations: [['type' => 'Red', 'price' => 10, 'sku' => 'R', 'qty' => 20]]);
        DB::table('products')->where('id', self::PRODUCT)->update([
            'current_stock' => 1, 'variation' => json_encode([['type' => 'Red', 'price' => 10, 'sku' => 'R', 'qty' => 1]]),
        ]);

        $writer->addAndUpdateProductsInOrderDetails(Order::with('details')->findOrFail(self::ORDER), $this->editedLine(10, 'Red'), ['applied_tax_cart_list' => []]);

        $this->assertSame(
            ['current_stock' => 0, 'variant_qty' => 0, 'movements' => []],
            $this->reading('Red'),
            'four units on the shelf cannot pay for ten: both figures stop at zero',
        );
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
        $this->assertSame(['asked' => 5, 'restocked' => 2, 'already_back' => 0, 'line_held' => 2], $body['clamped']);
        $this->assertSame(52, $this->reading()['current_stock']);
    }
}
