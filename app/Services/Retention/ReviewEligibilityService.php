<?php

namespace App\Services\Retention;

use App\Models\Order;
use App\Models\OrderDetail;
use Illuminate\Support\Facades\Schema;

/**
 * May this customer review this product against this order? (Phase 2.4)
 *
 * Neither review endpoint asked. `product_id` and `order_id` arrived from the client and were
 * written straight to the row, so any signed-in customer could post a review for any product,
 * attributed to any order id — including orders that were never theirs. On a pharmacy catalogue
 * that is not a cosmetic problem: ratings are what customers use to choose between medicines.
 *
 * The rule is deliberately the narrow one: the order must belong to the customer, and the product
 * must be a line on it. It does **not** require a particular delivery status, because when a store
 * opens reviews is a policy decision this code has no business inventing — and adding a status rule
 * could silently stop legitimate customers reviewing.
 */
class ReviewEligibilityService
{
    public function customerMayReview(int|string|null $customerId, int|string|null $orderId, int|string|null $productId): bool
    {
        if (!$customerId || !$orderId || !$productId) {
            return false;
        }

        if (!Schema::hasTable('orders') || !Schema::hasTable('order_details')) {
            return false;
        }

        $ownsOrder = Order::where('id', $orderId)
            ->where('customer_id', $customerId)
            ->where('is_guest', 0)
            ->exists();

        if (!$ownsOrder) {
            return false;
        }

        return OrderDetail::where('order_id', $orderId)
            ->where('product_id', $productId)
            ->exists();
    }
}
