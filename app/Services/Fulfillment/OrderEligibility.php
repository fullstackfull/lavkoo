<?php

namespace App\Services\Fulfillment;

use App\Models\Order;
use App\Services\Fulfillment\Support\ProviderConfig;

/**
 * Is a parent order allowed to be handed to a provider yet?
 *
 * Re-evaluated at routing AND again at dispatch, because an order can be cancelled, paused or edited
 * in the gap between the two.
 */
class OrderEligibility
{
    public const ELIGIBLE = 'eligible';
    public const ORDER_MISSING = 'order_missing';
    public const POS_ORDER = 'pos_order';
    public const PAUSED = 'order_paused';
    public const CLOSED = 'order_closed';
    public const STATUS_NOT_READY = 'order_status_not_ready';
    public const PAYMENT_PENDING = 'payment_pending';

    /** Order statuses that end the order: nothing may be dispatched for them, and pending work is cancelled. */
    public const CLOSED_STATUSES = ['canceled', 'failed', 'returned', 'refunded'];

    public function check(?Order $order, ProviderConfig $provider): string
    {
        if ($order === null) {
            return self::ORDER_MISSING;
        }

        if ($order->order_type === 'POS') {
            return self::POS_ORDER;
        }

        if (in_array($order->order_status, self::CLOSED_STATUSES, true)) {
            return self::CLOSED;
        }

        if ((bool) $order->is_pause) {
            return self::PAUSED;
        }

        $allowedStatuses = $provider->list('dispatch_order_statuses') ?: (array) config('fulfillment.dispatch.order_statuses', ['confirmed', 'processing']);
        if (!in_array($order->order_status, $allowedStatuses, true)) {
            return self::STATUS_NOT_READY;
        }

        if ($order->payment_method !== 'cash_on_delivery' && $order->payment_status !== 'paid') {
            return self::PAYMENT_PENDING;
        }

        return self::ELIGIBLE;
    }

    public function isEligible(?Order $order, ProviderConfig $provider): bool
    {
        return $this->check($order, $provider) === self::ELIGIBLE;
    }

    public function isClosed(?Order $order): bool
    {
        return $order !== null && in_array($order->order_status, self::CLOSED_STATUSES, true);
    }
}
