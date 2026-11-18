<?php

namespace Tests\Feature\Inventory;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\HoldReason;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentEvent;
use App\Models\ReturnShipment;
use App\Repositories\OrderRepository;
use App\Services\Fulfillment\FulfillmentSchema;
use App\Services\Fulfillment\ShipmentTracker;
use App\Services\Marketplace\ReturnLogisticsService;
use App\Services\SalesChannels\Dispatch\ChannelOrderMarker;
use App\Services\SalesChannels\Inventory\ReservationService;
use App\Utils\OrderManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\BuildsStockPools;
use Tests\TestCase;

/**
 * Characterization of the store's stock and of the holds placed on it, as the code behaves today: the order status writer's
 * `is_stock_decreased` guard, the return receipt's claim on that same flag, a provider fulfillment held and released, and a
 * channel reservation released. Tests named `..._currently_...` pin behaviour that looks wrong; they are findings, not fixes.
 */
class StockHoldsCharacterizationTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use BuildsStockPools;

    private const PRODUCT = 70;

    private const ORDER = 700;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        FulfillmentSchema::flush();
        $this->buildSalesChannelSchema();
        $this->addTheStockFlagTheRealMigrationAdds();
        $this->buildReturnShipmentsTable();
        ChannelOrderMarker::forget();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        ChannelOrderMarker::forget();

        parent::tearDown();
    }

    /** The platform's own migration: `order_details.is_stock_decreased` boolean, default 1. */
    private function addTheStockFlagTheRealMigrationAdds(): void
    {
        require_once database_path('migrations/2021_04_17_134848_add_column_to_order_details_stock.php');
        (new \AddColumnToOrderDetailsStock())->up();
    }

    /** `return_shipments` comes from the installation dump (no migration here): its columns are the model's `$fillable` and nothing more. */
    private function buildReturnShipmentsTable(): void
    {
        Schema::create('return_shipments', static function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('order_details_id')->nullable();
            $table->unsignedBigInteger('refund_request_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->integer('qty')->default(1);
            $table->string('reason')->nullable();
            $table->string('status', 24);
            $table->boolean('restock')->default(true);
            $table->string('carrier')->nullable();
            $table->string('tracking_number')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_type', 24)->nullable();
            $table->timestamps();
        });
    }

    private function changeStatus(string $status): void
    {
        OrderManager::getStockUpdateOnOrderStatusChange(Order::with('details')->findOrFail(self::ORDER), $status);
    }

    private function stock(): int
    {
        return (int) DB::table('products')->where('id', self::PRODUCT)->value('current_stock');
    }

    private function stockFlag(): int
    {
        return (int) DB::table('order_details')->where('order_id', self::ORDER)->value('is_stock_decreased');
    }

    private function lineId(): int
    {
        return (int) DB::table('order_details')->where('order_id', self::ORDER)->value('id');
    }

    private function variantQuantity(string $type): int
    {
        $variations = json_decode((string) DB::table('products')->where('id', self::PRODUCT)->value('variation'), true);

        return (int) collect($variations)->firstWhere('type', $type)['qty'];
    }

    private function returnFor(int $quantity, ?int $lineId): ReturnShipment
    {
        return app(ReturnLogisticsService::class)->authorize([
            'order_id' => self::ORDER,
            'order_details_id' => $lineId,
            'product_id' => self::PRODUCT,
            'qty' => $quantity,
            'reason' => 'damaged_box',
        ]);
    }

    private function heldFulfillment(): ProviderFulfillment
    {
        $this->storeProduct(self::PRODUCT, stock: 50);
        $this->storeOrder(self::ORDER, self::PRODUCT, 2, acceptedAs: FulfillmentStatus::Pending);
        $fulfillment = ProviderFulfillment::where('order_id', self::ORDER)->firstOrFail();
        app(ShipmentTracker::class)->hold($fulfillment->id, HoldReason::CITY_UNMAPPED);

        return $fulfillment->fresh();
    }

    private function eventsOf(ProviderFulfillment $fulfillment): int
    {
        return ProviderFulfillmentEvent::where('provider_fulfillment_id', $fulfillment->id)->count();
    }

    // ---- order status writer (app/Utils/OrderManager.php:95-154) ---------------------------------------------------------------

    public function test_a_cancel_restores_the_line_and_its_variant_once_and_a_repeated_cancel_restores_nothing(): void
    {
        $this->storeProduct(self::PRODUCT, stock: 50, variation: json_encode([['type' => 'Red', 'price' => 10, 'sku' => 'R', 'qty' => 20]]));
        $this->storeOrder(self::ORDER, self::PRODUCT, 3, variantKey: 'Red');

        $this->changeStatus('canceled');
        $this->assertSame([53, 23, 0], [$this->stock(), $this->variantQuantity('Red'), $this->stockFlag()]);

        $this->changeStatus('canceled');
        $this->assertSame([53, 23, 0], [$this->stock(), $this->variantQuantity('Red'), $this->stockFlag()], 'the flag was already 0: the conditional flip matched no row');
    }

    public function test_moving_between_restoring_statuses_restores_nothing_a_second_time_whichever_writer_runs(): void
    {
        $this->storeProduct(self::PRODUCT, stock: 50);
        $this->storeOrder(self::ORDER, self::PRODUCT, 2);

        $this->changeStatus('failed');
        $this->assertSame(52, $this->stock());

        app(OrderRepository::class)->updateStockOnOrderStatusChange(self::ORDER, 'returned');
        $this->changeStatus('canceled');

        $this->assertSame([52, 0], [$this->stock(), $this->stockFlag()], 'failed, returned and canceled all restore from flag 1 only');
        $this->assertSame('failed', DB::table('order_details')->where('order_id', self::ORDER)->value('delivery_status'), 'a change that flips nothing writes no line status either');
    }

    public function test_re_opening_a_cancelled_line_takes_the_units_again_once(): void
    {
        $this->storeProduct(self::PRODUCT, stock: 50);
        $this->storeOrder(self::ORDER, self::PRODUCT, 2);
        $this->changeStatus('canceled');

        $this->changeStatus('processing');
        $this->changeStatus('processing');
        app(OrderRepository::class)->updateStockOnOrderStatusChange(self::ORDER, 'delivered');

        $this->assertSame([50, 1], [$this->stock(), $this->stockFlag()]);
    }

    public function test_re_opening_a_cancelled_line_floors_current_stock_at_zero_but_currently_drives_the_variant_negative(): void
    {
        $this->storeProduct(self::PRODUCT, stock: 50, variation: json_encode([['type' => 'Red', 'price' => 10, 'sku' => 'R', 'qty' => 20]]));
        $this->storeOrder(self::ORDER, self::PRODUCT, 3, variantKey: 'Red');
        $this->changeStatus('canceled');
        // Out of band (a stock edit in the panel) the shelf is emptied while the order sits cancelled.
        DB::table('products')->where('id', self::PRODUCT)->update(['current_stock' => 1, 'variation' => json_encode([['type' => 'Red', 'price' => 10, 'sku' => 'R', 'qty' => 1]])]);

        $this->changeStatus('processing');

        $this->assertSame(0, $this->stock(), 'the conditional decrement refused, so current_stock was set to 0 instead of 1 - 3');
        $this->assertSame(-2, $this->variantQuantity('Red'), 'the variant JSON is decremented without a floor');
        $this->assertSame(1, $this->stockFlag());
    }

    // ---- return receipt (app/Services/Marketplace/ReturnLogisticsService.php:82-148) --------------------------------------------

    public function test_a_whole_line_return_received_after_the_order_was_marked_returned_does_not_restock_again(): void
    {
        $this->storeProduct(self::PRODUCT, stock: 50);
        $this->storeOrder(self::ORDER, self::PRODUCT, 2);
        $this->changeStatus('returned');
        $this->assertSame(52, $this->stock());

        $result = app(ReturnLogisticsService::class)->receive($this->returnFor(2, $this->lineId()));

        $this->assertFalse($result['restocked']);
        $this->assertSame(ReturnShipment::STATUS_RECEIVED, $result['return']->status);
        $this->assertSame(52, $this->stock());
    }

    public function test_a_whole_line_return_received_first_claims_the_flag_so_marking_the_order_returned_restocks_nothing_more(): void
    {
        $this->storeProduct(self::PRODUCT, stock: 50);
        $this->storeOrder(self::ORDER, self::PRODUCT, 2);

        $result = app(ReturnLogisticsService::class)->receive($this->returnFor(2, $this->lineId()));
        $this->assertTrue($result['restocked']);
        $this->assertSame([52, 0], [$this->stock(), $this->stockFlag()]);

        $this->changeStatus('returned');

        $this->assertSame(52, $this->stock());
    }

    public function test_a_return_received_twice_restocks_once(): void
    {
        $this->storeProduct(self::PRODUCT, stock: 50);
        $this->storeOrder(self::ORDER, self::PRODUCT, 2);
        $return = $this->returnFor(2, $this->lineId());

        app(ReturnLogisticsService::class)->receive($return);
        $second = app(ReturnLogisticsService::class)->receive($return->fresh());

        $this->assertSame(['ok' => false, 'reason' => 'return_is_not_in_a_receivable_state'], $second);
        $this->assertSame(52, $this->stock());
    }

    public function test_a_partial_return_then_marking_the_order_returned_currently_restocks_more_units_than_the_line_held(): void
    {
        $this->storeProduct(self::PRODUCT, stock: 50);
        $this->storeOrder(self::ORDER, self::PRODUCT, 3);

        $result = app(ReturnLogisticsService::class)->receive($this->returnFor(1, $this->lineId()));
        $this->assertTrue($result['restocked']);
        $this->assertSame([51, 1], [$this->stock(), $this->stockFlag()], 'a partial return never touches the flag');

        $this->changeStatus('returned');

        $this->assertSame(54, $this->stock(), 'three units left the shelf and four came back: 1 by the receipt and 3 by the status writer');
    }

    public function test_a_whole_line_return_for_more_units_than_the_line_currently_restocks_the_returns_quantity(): void
    {
        $this->storeProduct(self::PRODUCT, stock: 50);
        $this->storeOrder(self::ORDER, self::PRODUCT, 2);

        app(ReturnLogisticsService::class)->receive($this->returnFor(5, $this->lineId()));

        $this->assertSame([55, 0], [$this->stock(), $this->stockFlag()], 'the claim checks line qty <= return qty, then adds the return qty, not the line qty');
    }

    public function test_a_restocked_return_of_a_variant_line_currently_leaves_the_variant_quantity_unchanged(): void
    {
        $this->storeProduct(self::PRODUCT, stock: 50, variation: json_encode([['type' => 'Red', 'price' => 10, 'sku' => 'R', 'qty' => 20]]));
        $this->storeOrder(self::ORDER, self::PRODUCT, 2, variantKey: 'Red');

        app(ReturnLogisticsService::class)->receive($this->returnFor(2, $this->lineId()));

        $this->assertSame([52, 20], [$this->stock(), $this->variantQuantity('Red')], 'current_stock rises, the variant JSON is never written');
    }

    // ---- provider fulfillment hold (app/Services/Fulfillment/ShipmentTracker.php:164-213) ----------------------------------------

    public function test_holding_a_fulfillment_changes_no_stock_and_places_no_reservation(): void
    {
        $fulfillment = $this->heldFulfillment();

        $this->assertSame([FulfillmentStatus::Held, HoldReason::CITY_UNMAPPED], [$fulfillment->status, $fulfillment->hold_reason]);
        $this->assertSame([50, 1, 0], [$this->stock(), $this->stockFlag(), InventoryReservation::count()]);
    }

    public function test_holding_again_for_the_same_reason_writes_and_logs_nothing(): void
    {
        $fulfillment = $this->heldFulfillment();
        $events = $this->eventsOf($fulfillment);

        app(ShipmentTracker::class)->hold($fulfillment->id, HoldReason::CITY_UNMAPPED);

        $this->assertSame($events, $this->eventsOf($fulfillment));
        $this->assertSame(FulfillmentStatus::Held, $fulfillment->fresh()->status);
    }

    public function test_releasing_an_already_released_fulfillment_changes_nothing_the_second_time(): void
    {
        $fulfillment = $this->heldFulfillment();
        $tracker = app(ShipmentTracker::class);

        $this->assertTrue($tracker->release($fulfillment->id, 'operator'));
        $events = $this->eventsOf($fulfillment);

        $this->assertFalse($tracker->release($fulfillment->id, 'operator'), 'pending is not a releasable state');
        $this->assertSame($events, $this->eventsOf($fulfillment));
        $fresh = $fulfillment->fresh();
        $this->assertSame([FulfillmentStatus::Pending, null], [$fresh->status, $fresh->hold_reason]);
        $this->assertSame(50, $this->stock());
    }

    public function test_a_terminal_fulfillment_cannot_be_held(): void
    {
        $this->storeProduct(self::PRODUCT, stock: 50);
        $this->storeOrder(self::ORDER, self::PRODUCT, 2, acceptedAs: FulfillmentStatus::Cancelled);
        $fulfillment = ProviderFulfillment::where('order_id', self::ORDER)->firstOrFail();

        app(ShipmentTracker::class)->hold($fulfillment->id, HoldReason::MANUAL);

        $this->assertSame([FulfillmentStatus::Cancelled, null], [$fulfillment->fresh()->status, $fulfillment->fresh()->hold_reason]);
    }

    // ---- channel reservation (app/Services/SalesChannels/Inventory/ReservationService.php:23-120) --------------------------------

    public function test_a_reservation_by_itself_does_not_change_current_stock(): void
    {
        $this->storeProduct(self::PRODUCT, stock: 50);

        app(ReservationService::class)->reserve('channel-order:1:1', $this->torodLocation()->id, self::PRODUCT, '', 4);

        $this->assertSame([50, 4], [$this->stock(), (int) InventoryReservation::sum('quantity')]);
    }

    public function test_releasing_an_already_released_reservation_changes_nothing_the_second_time(): void
    {
        $reservations = app(ReservationService::class);
        $reservations->reserve('channel-order:1:1', $this->torodLocation()->id, self::PRODUCT, '', 4);
        $reservations->release('channel-order:1:1', 'order_cancelled');
        $first = (array) DB::table('inventory_reservations')->first();

        $reservations->release('channel-order:1:1', 'order_returned');

        $this->assertSame($first, (array) DB::table('inventory_reservations')->first(), 'the stored row is untouched: same reason, same released_at');
        $this->assertSame(InventoryReservation::RELEASED, $first['state']);
    }

    public function test_a_reservation_released_for_a_cancelled_order_stays_released_on_reopen_and_reserve(): void
    {
        $reservations = app(ReservationService::class);
        $location = $this->torodLocation()->id;
        $reservations->reserve('channel-order:1:1', $location, self::PRODUCT, '', 4);
        $reservations->release('channel-order:1:1', 'order_cancelled');

        $reservations->reopen('channel-order:1:1');
        $again = $reservations->reserve('channel-order:1:1', $location, self::PRODUCT, '', 6);

        $this->assertSame([InventoryReservation::RELEASED, 4], [$again->state, (int) $again->quantity]);
        $this->assertSame(0, $reservations->inFlight($location, self::PRODUCT, '', null));
    }
}
