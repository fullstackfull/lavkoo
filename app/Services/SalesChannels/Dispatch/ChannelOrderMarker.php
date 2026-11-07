<?php

namespace App\Services\SalesChannels\Dispatch;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tells the internal orders this module makes for channel orders from the store's own. They take no stock from the store (their goods are at the provider and their lines say so), so the
 * platform's stock code on a status change must leave them alone: its rule "any status but returned/failed/canceled has taken the stock" would otherwise take units off `products.current_stock`
 * the first time somebody moves one of them forward by hand.
 */
final class ChannelOrderMarker
{
    private static ?bool $mappingsExist = null;

    /** @param object|array<string, mixed> $order */
    public static function holdsNoStock(object|array $order): bool
    {
        $paidHow = data_get($order, 'payment_method');
        if ($paidHow !== null && $paidHow === (string) config('sales_channels.dispatch.payment_method', 'marketplace_prepaid')) {
            return true;
        }

        $id = data_get($order, 'id');
        self::$mappingsExist ??= Schema::hasTable('external_order_mappings');

        return $id !== null && self::$mappingsExist && DB::table('external_order_mappings')->where('order_id', $id)->exists();
    }

    /** Forgets what was learned about the schema (tests build and drop tables between cases). */
    public static function forget(): void
    {
        self::$mappingsExist = null;
    }
}
