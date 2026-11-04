<?php

namespace App\Services\Marketplace;

use App\Models\OrderFulfillment;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Schema;

/**
 * Order fulfilment workflow — pick / pack / ship (Phase 3, Stage C).
 *
 * A forward-only state machine over an overlay record. It never writes `orders.order_status`: it
 * enriches the middle of the order flow (the warehouse work) without altering the state machine the
 * storefront and apps depend on. `advance()` refuses to move backward or to repeat a state, and stamps
 * the packed/shipped timestamps as those points are reached.
 */
class FulfillmentService
{
    public function __construct(private readonly ?AuditLogger $audit = null)
    {
    }

    /**
     * Open a fulfilment for an order (per seller). Refuses a second open one for the same order+seller
     * so the workflow can't fork.
     *
     * @return array{ok: bool, reason?: string, fulfillment?: OrderFulfillment}
     */
    public function open(int|string $orderId, int|string|null $sellerId = null, int|string|null $warehouseId = null, int|string|null $by = null): array
    {
        if (!Schema::hasTable('order_fulfillments')) {
            return ['ok' => false, 'reason' => 'fulfilment_unavailable'];
        }

        $existing = OrderFulfillment::where('order_id', $orderId)
            ->where('seller_id', $sellerId)
            ->whereNotIn('status', [OrderFulfillment::STATUS_SHIPPED, OrderFulfillment::STATUS_CANCELED])
            ->exists();
        if ($existing) {
            return ['ok' => false, 'reason' => 'an_open_fulfilment_already_exists_for_this_order'];
        }

        $fulfillment = OrderFulfillment::create([
            'reference' => $this->nextReference(),
            'order_id' => $orderId,
            'seller_id' => $sellerId,
            'warehouse_id' => $warehouseId,
            'status' => OrderFulfillment::STATUS_PENDING,
            'created_by' => $by,
        ]);

        $this->audit?->record(
            action: 'fulfilment.opened',
            subject: ['type' => 'order_fulfillment', 'id' => $fulfillment->id],
            after: ['reference' => $fulfillment->reference, 'order_id' => $orderId],
        );

        return ['ok' => true, 'fulfillment' => $fulfillment];
    }

    /**
     * Move a fulfilment forward to a later status. Forward-only: the target must sit further along the
     * flow than the current status.
     *
     * @return array{ok: bool, reason?: string, fulfillment?: OrderFulfillment}
     */
    public function advance(OrderFulfillment $fulfillment, string $toStatus, array $extra = [], int|string|null $by = null): array
    {
        if (!$fulfillment->isOpen()) {
            return ['ok' => false, 'reason' => 'fulfilment_is_already_closed'];
        }

        $fromIndex = OrderFulfillment::flowIndex($fulfillment->status);
        $toIndex = OrderFulfillment::flowIndex($toStatus);
        if ($toIndex === false || $toIndex <= $fromIndex) {
            return ['ok' => false, 'reason' => 'a_fulfilment_can_only_move_forward'];
        }

        $attributes = ['status' => $toStatus];
        if ($toStatus === OrderFulfillment::STATUS_PACKED) {
            $attributes['packed_at'] = now();
            $attributes['picked_at'] = $fulfillment->picked_at ?? now();
        }
        if ($toStatus === OrderFulfillment::STATUS_SHIPPED) {
            $attributes['shipped_at'] = now();
            $attributes['carrier'] = $extra['carrier'] ?? $fulfillment->carrier;
            $attributes['tracking_number'] = $extra['tracking_number'] ?? $fulfillment->tracking_number;
        }

        $fulfillment->update($attributes);

        $this->audit?->record(
            action: 'fulfilment.advanced',
            subject: ['type' => 'order_fulfillment', 'id' => $fulfillment->id],
            after: ['status' => $toStatus],
        );

        return ['ok' => true, 'fulfillment' => $fulfillment];
    }

    public function assign(OrderFulfillment $fulfillment, int|string|null $userId): array
    {
        if (!$fulfillment->isOpen()) {
            return ['ok' => false, 'reason' => 'fulfilment_is_already_closed'];
        }
        $fulfillment->update(['assigned_to' => $userId]);

        return ['ok' => true, 'fulfillment' => $fulfillment];
    }

    public function cancel(OrderFulfillment $fulfillment): array
    {
        if (!$fulfillment->isOpen()) {
            return ['ok' => false, 'reason' => 'fulfilment_is_already_closed'];
        }
        $fulfillment->update(['status' => OrderFulfillment::STATUS_CANCELED]);

        $this->audit?->record(
            action: 'fulfilment.canceled',
            subject: ['type' => 'order_fulfillment', 'id' => $fulfillment->id],
        );

        return ['ok' => true, 'fulfillment' => $fulfillment];
    }

    private function nextReference(): string
    {
        $prefix = 'FF-' . date('Ym') . '-';
        $last = OrderFulfillment::where('reference', 'like', $prefix . '%')->orderByDesc('id')->value('reference');

        $seq = 1;
        if ($last && preg_match('/(\d+)$/', $last, $m)) {
            $seq = ((int) $m[1]) + 1;
        }

        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
