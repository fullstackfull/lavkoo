<?php

namespace App\Services\DeliverySyria;

/**
 * The Delivery Syria courier status vocabulary and its safe mapping onto the platform's order_status.
 *
 * The courier reports 7 numeric states. The platform's order_status is a different, financially-loaded
 * lifecycle: moving an order to `delivered` matures vendor earnings/commission, marks payment paid and
 * pays the deliveryman; moving to `canceled`/`returned`/`failed` restocks inventory. Those transitions
 * are orchestrated by the order controllers (stock guard + wallet + history + events) and must never be
 * reproduced by a bare save() — least of all from an external webhook call.
 *
 * So this map draws a hard line: the four "intermediate" platform states (pending, confirmed,
 * processing, out_for_delivery) carry no side effects and are safe to reflect straight from a courier
 * report; the two "terminal" ones (delivered, canceled) are financial and are only recorded by the
 * webhook, never auto-applied. `isFinancialTransition()` is that line.
 */
class DeliverySyriaStatus
{
    public const PENDING = 0;
    public const DELIVERED = 1;
    public const CANCELED = 2;
    public const IN_TRANSIT = 3;
    public const CONFIRMED = 4;
    public const PICKED_UP = 5;
    public const SHIPPED = 6;

    /** Courier code => human label (English; the courier expects an English status_label echoed back). */
    private const LABELS = [
        self::PENDING => 'Pending',
        self::DELIVERED => 'Delivered',
        self::CANCELED => 'Canceled',
        self::IN_TRANSIT => 'In Transit',
        self::CONFIRMED => 'Confirmed',
        self::PICKED_UP => 'Picked Up',
        self::SHIPPED => 'Shipped',
    ];

    /**
     * Courier code => platform order_status. The intermediate courier states map onto the platform's
     * side-effect-free statuses; the courier has no "shipped to hub" analog, so both its shipped/
     * in-transit map to the platform's single in-flight status, out_for_delivery.
     */
    private const ORDER_STATUS = [
        self::PENDING => 'pending',
        self::CONFIRMED => 'confirmed',
        self::PICKED_UP => 'processing',
        self::SHIPPED => 'out_for_delivery',
        self::IN_TRANSIT => 'out_for_delivery',
        self::DELIVERED => 'delivered',
        self::CANCELED => 'canceled',
    ];

    /** Platform order_status values whose transition triggers stock/earnings side effects. */
    private const FINANCIAL_ORDER_STATUSES = ['delivered', 'canceled', 'returned', 'failed'];

    public static function isValid(int $code): bool
    {
        return array_key_exists($code, self::LABELS);
    }

    public static function label(int $code): string
    {
        return self::LABELS[$code] ?? 'Unknown';
    }

    /** The platform order_status this courier code corresponds to, or null if there is no mapping. */
    public static function toOrderStatus(int $code): ?string
    {
        return self::ORDER_STATUS[$code] ?? null;
    }

    /**
     * Would reflecting this courier code into order_status cross a financial/stock boundary? When true
     * the webhook records the courier status but does NOT touch order_status — finalizing an order
     * (and moving marketplace money) stays with the platform's own order flow, by design.
     */
    public static function isFinancialTransition(int $code): bool
    {
        $orderStatus = self::toOrderStatus($code);

        return $orderStatus === null || in_array($orderStatus, self::FINANCIAL_ORDER_STATUSES, true);
    }
}
