<?php


use Illuminate\Support\Str;

if (!function_exists('getOrderSummary')) {
    function getOrderSummary(object $order): array
    {
        $sub_total = 0;
        $total_tax = $order['total_tax_amount'];
        $total_discount_on_product = 0;
        foreach ($order->details as $detail) {
            $sub_total += $detail->price * $detail->qty;
            $total_discount_on_product += $detail->discount;
        }
        $total_shipping_cost = $order['shipping_cost'];
        return [
            'subtotal' => $sub_total,
            'total_tax' => $total_tax,
            'total_discount_on_product' => $total_discount_on_product,
            'total_shipping_cost' => $total_shipping_cost,
        ];
    }
}

if (!function_exists('getUniqueId')) {
    function getUniqueId(): string
    {
        return rand(1000, 9999) . '-' . Str::random(5) . '-' . time();
    }
}


if (!function_exists('getOrderStatusList')) {
    function getOrderStatusList(): array
    {
        return [
            'pending',
            'confirmed',
            'processing',
            'out_for_delivery',
            'delivered',
            'returned',
            'failed',
            'canceled',
        ];
    }
}
