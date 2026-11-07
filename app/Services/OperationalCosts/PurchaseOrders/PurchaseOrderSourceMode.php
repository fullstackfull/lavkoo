<?php

namespace App\Services\OperationalCosts\PurchaseOrders;

use App\Services\OperationalCosts\Support\OperationalCostsSwitch;

/**
 * Whether the module takes the cost of goods from the platform's purchase orders: `off` (nothing, the default), `shadow` (only reports what it would do and writes nothing
 * anywhere) or `on` (the layers can be made by hand, linked invoice lines make no second layer, the latest order cost is an estimate). Read through config() every time, like
 * OperationalCostsSwitch; only the exact words `shadow` and `on` count, so a typo, `true` or `1` all mean off.
 */
final class PurchaseOrderSourceMode
{
    public const OFF = 'off';
    public const SHADOW = 'shadow';
    public const ON = 'on';

    /** What a command prints, in one line, when the source is off. */
    public const OFF_MESSAGE = 'The purchase-order source is off (OPERATIONAL_COSTS_SOURCE_PURCHASE_ORDERS=off): nothing was read or written. Set it to shadow to see what it would do.';

    public static function current(): string
    {
        $mode = strtolower(trim((string) config('operational_costs.sources.purchase_orders', self::OFF)));

        return in_array($mode, [self::SHADOW, self::ON], true) ? $mode : self::OFF;
    }

    /** True in shadow and in on: the source may be read and its report shown. */
    public static function reports(): bool
    {
        return self::current() !== self::OFF;
    }

    /** True only in on, and only while the cost module itself is switched on: what may write layers, link invoice lines and estimate from an order. */
    public static function active(): bool
    {
        return self::current() === self::ON && OperationalCostsSwitch::enabled();
    }
}
