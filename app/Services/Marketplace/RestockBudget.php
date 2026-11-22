<?php

namespace App\Services\Marketplace;

use App\Models\ReturnShipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How many of an order line's units may still be put back on the shelf.
 *
 * Two writers restock the same line and neither knew about the other: a return receipt
 * (ReturnLogisticsService::receive) worked from the return's quantity, and the order status writer
 * (OrderManager::getStockUpdateOnOrderStatusChange and its twin in OrderRepository, on
 * returned/failed/canceled) worked from the line's. So a partial return followed by marking the order
 * returned put four units back for three that left, and a return typed for more units than the line
 * held put back the typed figure.
 *
 * The line's quantity is the budget both draw on: units already back through a received return are
 * spent, and what is left is all either writer may add. The shelf can then never gain more than left it.
 *
 * Nothing is spent where `return_shipments` does not exist (it comes from the installation dump) or
 * where the line has no returns, so every path that has never seen a return behaves exactly as before.
 */
final class RestockBudget
{
    /** What is left of the line's units for a restock to put back: never negative, never more than the line held. */
    public function unspentUnitsOf(int|string|null $orderDetailsId, int|string|null $lineQuantity): int
    {
        $held = $this->unitsHeldBy($lineQuantity);

        return $held - $this->unitsAlreadyBack($orderDetailsId, $held);
    }

    /**
     * Units of this line already put back by a return that was received and restocked.
     *
     * Capped by what the line held, because the receipt caps itself the same way: a return typed for
     * more units than the line held restocks the line's quantity and not the typed one, so the typed
     * figure read back out of `return_shipments` would overstate what reached the shelf.
     */
    public function unitsAlreadyBack(int|string|null $orderDetailsId, int|string|null $lineQuantity): int
    {
        $held = $this->unitsHeldBy($lineQuantity);

        if (!$orderDetailsId || $held === 0 || !Schema::hasTable('return_shipments')) {
            return 0;
        }

        $restocked = (int) DB::table('return_shipments')
            ->where('order_details_id', $orderDetailsId)
            ->where('status', ReturnShipment::STATUS_RESTOCKED)
            ->sum('qty');

        return min($held, max(0, $restocked));
    }

    private function unitsHeldBy(int|string|null $lineQuantity): int
    {
        return max(0, (int) $lineQuantity);
    }
}
