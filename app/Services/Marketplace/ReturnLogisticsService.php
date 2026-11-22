<?php

namespace App\Services\Marketplace;

use App\Models\OrderDetail;
use App\Models\RefundRequest;
use App\Models\ReturnShipment;
use App\Models\StockMovement;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Returns logistics — the physical side of a return (Phase 3, Stage C).
 *
 * A return walks authorized → in_transit → received, and on receipt either restocks (goods sellable
 * again) or is rejected (damaged/unsellable). The one behaviour that closes the loop the platform was
 * missing: **receiving a restockable return puts the unit back into `products.current_stock`** — under
 * a row lock, and recorded as a `return` movement in the same inventory log a purchase receipt writes
 * to. The financial side (commission reversal) already lives in the refund path; this links to the
 * refund_request rather than duplicating it.
 */
class ReturnLogisticsService
{
    public function __construct(
        private readonly ?InventoryService $inventory = null,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /**
     * Authorise a return. Starts the RMA in `authorized` — the goods have not moved yet.
     *
     * @param array{order_id?: int, order_details_id?: int, refund_request_id?: int, product_id?: int, seller_id?: int, qty?: int, reason?: string, restock?: bool, note?: string} $data
     */
    public function authorize(array $data, int|string|null $by = null, string $byType = 'admin'): ReturnShipment
    {
        $rma = ReturnShipment::create([
            'reference' => $this->nextReference(),
            'order_id' => $data['order_id'] ?? null,
            'order_details_id' => $data['order_details_id'] ?? null,
            'refund_request_id' => $data['refund_request_id'] ?? null,
            'product_id' => $data['product_id'] ?? null,
            'seller_id' => $data['seller_id'] ?? null,
            'qty' => max(1, (int) ($data['qty'] ?? 1)),
            'reason' => $data['reason'] ?? null,
            'status' => ReturnShipment::STATUS_AUTHORIZED,
            'restock' => array_key_exists('restock', $data) ? (bool) $data['restock'] : true,
            'note' => $data['note'] ?? null,
            'created_by' => $by,
            'created_by_type' => $byType,
        ]);

        $this->audit?->record(
            action: 'returns.authorized',
            subject: ['type' => 'return_shipment', 'id' => $rma->id],
            after: ['reference' => $rma->reference, 'product_id' => $rma->product_id, 'qty' => $rma->qty],
        );

        return $rma;
    }

    public function markInTransit(ReturnShipment $rma, ?string $carrier = null, ?string $tracking = null): array
    {
        if ($rma->status !== ReturnShipment::STATUS_AUTHORIZED) {
            return ['ok' => false, 'reason' => 'only_an_authorized_return_can_be_marked_in_transit'];
        }

        $rma->update([
            'status' => ReturnShipment::STATUS_IN_TRANSIT,
            'carrier' => $carrier ?: $rma->carrier,
            'tracking_number' => $tracking ?: $rma->tracking_number,
        ]);

        return ['ok' => true, 'return' => $rma];
    }

    /**
     * Receive the goods. If the return is restockable and points at a product, restock it (locked)
     * and record a `return` movement; otherwise the return is simply marked received.
     *
     * Never more units than the order line held, and never units another writer has already put back:
     * the line's quantity is a budget shared with the order status writer (RestockBudget). A receipt
     * asking for more than is left restocks what is left and says so in `clamped` — the operator is
     * told rather than quietly corrected.
     *
     * @return array{ok: bool, reason?: string, return?: ReturnShipment, restocked?: bool, balance_after?: int|null, restocked_units?: int, clamped?: array{asked: int, restocked: int, line_held: int}|null}
     */
    public function receive(ReturnShipment $rma, int|string|null $by = null, string $byType = 'admin'): array
    {
        if (!$rma->isOpen()) {
            return ['ok' => false, 'reason' => 'return_is_not_in_a_receivable_state'];
        }

        return DB::transaction(function () use ($rma, $by, $byType) {
            // Callers load the return before this transaction, so two receipts of one return can both pass the
            // check above. Re-reading it under a row lock lets only the first one restock; the rest wait, then see it closed.
            $rma = ReturnShipment::whereKey($rma->getKey())->lockForUpdate()->first();
            if (!$rma || !$rma->isOpen()) {
                return ['ok' => false, 'reason' => 'return_is_not_in_a_receivable_state'];
            }

            $restocked = false;
            $balanceAfter = null;

            $restockedUnits = 0;
            $clamped = null;

            if ($rma->restock && $rma->product_id && Schema::hasTable('products')) {
                // Coordinate with the legacy order-status restock (getStockUpdateOnOrderStatusChange) so a
                // line is never restocked twice — once here and once when the order is marked returned, and
                // never for more units than the line held. The line's quantity is a budget both writers draw
                // on (RestockBudget): this receipt may put back only what is unspent, and the order status
                // writer puts back only what receipts left. The line row is LOCKED for as long as it takes to
                // read the budget and spend it, so two receipts against one line cannot both spend the same
                // units, and the status writer (which locks the line with its own marker flip) cannot either.
                //
                // The marker claim is on the CUMULATIVE returned quantity, not on this one event: whichever
                // receipt completes the line claims the restore by flipping is_stock_decreased 1->0, and a
                // zero-row flip means the status writer already restored the line, so there is nothing left
                // to put back. A receipt that does not complete the line leaves the marker for the status
                // writer, and may restock only while the line's units are still off the shelf.
                $mayRestock = true;
                $line = null;
                $restockedUnits = (int) $rma->qty;

                if ($rma->order_details_id && Schema::hasTable('order_details')) {
                    $line = DB::table('order_details')->where('id', $rma->order_details_id)->lockForUpdate()->first();
                }

                if ($line) {
                    $budget = app(RestockBudget::class);
                    $alreadyBack = $budget->unitsAlreadyBack($rma->order_details_id, $line->qty);
                    $restockedUnits = min((int) $rma->qty, $budget->unspentUnitsOf($rma->order_details_id, $line->qty));

                    // A receipt that will put NOTHING back must not take the line's claim. The claim was
                    // taken before this was known, so a receipt of goods the budget had no room for flipped
                    // is_stock_decreased to 0 and restocked nothing: the record then said the units were on
                    // the shelf while they were on the receiving desk, and the order status change that
                    // followed could not restore them either because the claim was gone.
                    $mayRestock = false;
                    if ($restockedUnits > 0) {
                        $completesTheLine = $alreadyBack + (int) $rma->qty >= max(0, (int) $line->qty);
                        $mayRestock = $completesTheLine
                            ? $this->claimTheLinesRestore($rma->order_details_id)
                            : (int) $line->is_stock_decreased === 1;
                    }

                    if ($restockedUnits < (int) $rma->qty) {
                        // The operator asked for more units than the line can give back. Never silent: the
                        // caller is told, the audit trail keeps both figures, and so does the movement row.
                        $clamped = ['asked' => (int) $rma->qty, 'restocked' => $restockedUnits, 'line_held' => (int) $line->qty];
                    }
                }

                $locked = $mayRestock ? DB::table('products')->where('id', $rma->product_id)->lockForUpdate()->first() : null;
                if ($locked) {
                    $balanceAfter = (int) $locked->current_stock + $restockedUnits;
                    $restock = ['current_stock' => $balanceAfter, 'updated_at' => now()];
                    $variant = (string) ($line->variant ?? '');
                    if ($variant !== '') {
                        $restock['variation'] = $this->variationWithReturnedUnits(
                            variation: $locked->variation,
                            variant: $variant,
                            qty: $restockedUnits,
                        );
                    }
                    DB::table('products')->where('id', $rma->product_id)->update($restock);

                    ($this->inventory ?? app(InventoryService::class))->record(
                        productId: $rma->product_id,
                        type: StockMovement::TYPE_RETURN,
                        qtyChange: $restockedUnits,
                        balanceAfter: $balanceAfter,
                        reason: $rma->reason,
                        referenceType: 'return_shipment',
                        referenceId: $rma->id,
                        note: $clamped === null
                            ? null
                            : 'asked for ' . $clamped['asked'] . ', the line held ' . $clamped['line_held'],
                        sellerId: $rma->seller_id,
                        createdBy: $by,
                        // Who actually received the goods. Stamping every receipt 'admin' made the
                        // stock log say the marketplace handled a return the seller handled.
                        createdByType: $byType,
                    );
                    $restocked = true;
                }
            }

            $rma->update([
                'status' => $restocked ? ReturnShipment::STATUS_RESTOCKED : ReturnShipment::STATUS_RECEIVED,
                'received_at' => now(),
            ]);

            $this->audit?->record(
                action: 'returns.received',
                subject: ['type' => 'return_shipment', 'id' => $rma->id],
                after: ['restocked' => $restocked, 'balance_after' => $balanceAfter, 'restocked_units' => $restocked ? $restockedUnits : 0, 'clamped' => $restocked ? $clamped : null],
            );

            return [
                'ok' => true,
                'return' => $rma->fresh(),
                'restocked' => $restocked,
                'balance_after' => $balanceAfter,
                'restocked_units' => $restocked ? $restockedUnits : 0,
                'clamped' => $restocked ? $clamped : null,
            ];
        });
    }

    public function reject(ReturnShipment $rma, ?string $reason = null): array
    {
        if (!$rma->isOpen()) {
            return ['ok' => false, 'reason' => 'return_is_not_open'];
        }

        $rma->update([
            'status' => ReturnShipment::STATUS_REJECTED,
            'note' => $reason ?: $rma->note,
        ]);

        $this->audit?->record(
            action: 'returns.rejected',
            subject: ['type' => 'return_shipment', 'id' => $rma->id],
            after: ['reason' => $reason],
        );

        return ['ok' => true, 'return' => $rma];
    }

    /**
     * Open the return that an approved refund implies, once.
     *
     * A refund approved on a physical product means goods are coming back, and until now nothing
     * recorded that: the money moved and the units did not exist anywhere. Without an RMA the return
     * never restocks, so a seller who refunds a customer quietly loses the stock as well as the sale.
     *
     * Idempotent on the refund request, so approving twice — or an admin having opened one already —
     * does not create a second return for the same goods.
     */
    public function authorizeForRefund(
        int|string $refundRequestId,
        array $data,
        int|string|null $by = null,
        string $byType = 'seller',
    ): ?ReturnShipment {
        if (!Schema::hasTable('return_shipments')) {
            return null;
        }

        $existing = ReturnShipment::where('refund_request_id', $refundRequestId)->first();

        if ($existing) {
            return $existing;
        }

        return $this->authorize(
            data: $data + ['refund_request_id' => $refundRequestId],
            by: $by,
            byType: $byType,
        );
    }

    /**
     * Open the return an approved refund implies, from whichever surface approved it.
     *
     * The decision to refund is the same decision whether it was taken in the panel or in the app,
     * so its consequences have to be the same too. Written here rather than in either controller
     * because it was in one of them and not the other, which meant a refund approved at a desk lost
     * the goods and the same refund approved on a phone did not.
     *
     * Only for a physical product: a digital item has nothing to send back, and an RMA for one would
     * be a return that can never be received. Never allowed to fail the refund it follows — the
     * customer's money moving matters more than the paperwork about the goods.
     */
    public function openForApprovedRefund(
        ?RefundRequest $refund,
        ?OrderDetail $orderDetails,
        int|string $sellerId,
        string $byType = 'seller',
    ): ?ReturnShipment {
        // Both are nullable because both callers hold them as `?Model`. A refund that is not there
        // has no goods to ask about, and this must never be the thing that fails a refund.
        if (!$refund || !$orderDetails) {
            return null;
        }

        $product = json_decode($orderDetails->product_details ?? '', true);

        if (($product['product_type'] ?? 'physical') !== 'physical') {
            return null;
        }

        try {
            return $this->authorizeForRefund(
                refundRequestId: $refund->id,
                data: [
                    'order_id' => $refund->order_id,
                    'order_details_id' => $refund->order_details_id,
                    'product_id' => $refund->product_id ?? $orderDetails->product_id,
                    'seller_id' => $sellerId,
                    'qty' => max(1, (int) $orderDetails->qty),
                    'reason' => $refund->refund_reason,
                    // Whether the goods can be sold again is a decision for whoever opens the box,
                    // not for the moment the refund was approved.
                    'restock' => true,
                ],
                by: $sellerId,
                byType: $byType,
            );
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Take the line's restore for this receipt, atomically.
     *
     * A zero-row flip means the marker was already 0 — the order status writer restored the line — so
     * the units are on the shelf already and this receipt must add none.
     */
    private function claimTheLinesRestore(int|string $orderDetailsId): bool
    {
        return DB::table('order_details')
            ->where('id', $orderDetailsId)
            ->where('is_stock_decreased', 1)
            ->update(['is_stock_decreased' => 0]) > 0;
    }

    /** The product's variations with the returned units added back to the one the order line bought, as the order-status restock does. */
    private function variationWithReturnedUnits(?string $variation, string $variant, int $qty): string
    {
        return json_encode(array_map(
            static fn (array $option): array => ($option['type'] ?? null) == $variant
                ? array_merge($option, ['qty' => (int) ($option['qty'] ?? 0) + $qty])
                : $option,
            json_decode((string) $variation, true) ?: [],
        ));
    }

    private function nextReference(): string
    {
        $prefix = 'RMA-' . date('Ym') . '-';
        $last = ReturnShipment::where('reference', 'like', $prefix . '%')->orderByDesc('id')->value('reference');

        $seq = 1;
        if ($last && preg_match('/(\d+)$/', $last, $m)) {
            $seq = ((int) $m[1]) + 1;
        }

        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
