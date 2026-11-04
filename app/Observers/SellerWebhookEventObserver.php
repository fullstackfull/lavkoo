<?php

namespace App\Observers;

use App\Models\Order;
use App\Models\Product;
use App\Models\RefundRequest;
use App\Models\VendorPayoutRequest;
use App\Services\Marketplace\InventoryService;
use App\Services\Marketplace\SellerWebhookDispatcher;

/**
 * Where a seller's webhooks are actually raised from.
 *
 * Observers rather than call sites, and for the same reason the price history is an observer: an
 * order's status is written from the admin panel, the vendor panel, three API versions, the
 * delivery-partner webhook and a queued job, and the eighth writer will be added by somebody who
 * never heard of this file. Hanging the event on the model means it cannot be forgotten.
 *
 * Every method is best-effort. A checkout must not fail because a seller's own server is down, and
 * the dispatcher already swallows its own failures — this only decides what is worth sending.
 */
class SellerWebhookEventObserver
{
    public function __construct(
        private readonly SellerWebhookDispatcher $dispatcher,
        private readonly InventoryService $inventory,
    ) {
    }

    public function created(mixed $model): void
    {
        if ($model instanceof Order && $this->belongsToSeller($model)) {
            $this->dispatcher->dispatch($model->seller_id, 'order.placed', [
                'order_id' => $model->id,
                'order_status' => $model->order_status,
                'payment_status' => $model->payment_status,
                'order_amount' => (float) $model->order_amount,
            ]);

            return;
        }

        if ($model instanceof RefundRequest) {
            $sellerId = $this->sellerBehindRefund($model);

            if ($sellerId !== null) {
                $this->dispatcher->dispatch($sellerId, 'order.refund_requested', [
                    'refund_request_id' => $model->id,
                    'order_id' => $model->order_id,
                    'order_details_id' => $model->order_details_id,
                    'amount' => (float) $model->amount,
                    'status' => $model->status,
                ]);
            }
        }
    }

    public function updated(mixed $model): void
    {
        if ($model instanceof Order) {
            if ($model->wasChanged('order_status') && $this->belongsToSeller($model)) {
                $this->dispatcher->dispatch($model->seller_id, 'order.status_changed', [
                    'order_id' => $model->id,
                    'order_status' => $model->order_status,
                    'previous_status' => $model->getOriginal('order_status'),
                ]);
            }

            return;
        }

        if ($model instanceof VendorPayoutRequest) {
            if ($model->wasChanged('status')) {
                $this->dispatcher->dispatch($model->seller_id, 'payout.status_changed', [
                    'payout_request_id' => $model->id,
                    'status' => $model->status,
                    'previous_status' => $model->getOriginal('status'),
                    'amount' => (float) $model->amount,
                ]);
            }

            return;
        }

        if ($model instanceof Product) {
            $this->stockCrossedTheLine($model);
        }
    }

    /**
     * Sent once, on the way down.
     *
     * A product sitting at two units does not deserve a message every time anything about it is
     * saved; a product that has just fallen from six to two does. Reading both sides of the change
     * is what makes the difference, and is why this is an observer rather than a scheduled scan.
     */
    private function stockCrossedTheLine(Product $product): void
    {
        if (!$product->wasChanged('current_stock') || $product->added_by !== 'seller') {
            return;
        }

        // The seller's own threshold, from the same place the low-stock detector reads it.
        $threshold = $this->inventory->stockLimitFor($product->user_id);

        if ($threshold <= 0) {
            return;
        }

        $before = (int) $product->getOriginal('current_stock');
        $after = (int) $product->current_stock;

        if ($before > $threshold && $after <= $threshold) {
            $this->dispatcher->dispatch($product->user_id, 'product.stock_low', [
                'product_id' => $product->id,
                'current_stock' => $after,
                'previous_stock' => $before,
                'threshold' => $threshold,
            ]);
        }
    }

    /** An order placed with the marketplace itself has no seller to tell. */
    private function belongsToSeller(Order $order): bool
    {
        return $order->seller_is === 'seller' && $order->seller_id !== null;
    }

    private function sellerBehindRefund(RefundRequest $refund): int|string|null
    {
        $order = Order::find($refund->order_id);

        return $order && $this->belongsToSeller($order) ? $order->seller_id : null;
    }
}
