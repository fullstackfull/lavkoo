<?php

namespace App\Services\SalesChannels\Support;

use Illuminate\Support\Facades\Schema;

/**
 * The sales-channel code can be deployed before its migrations have run, and until they have it must leave the platform exactly as it was. Scheduled work asks first.
 */
final class SalesChannelSchema
{
    private const REQUIRED = ['sales_channel_accounts', 'external_order_mappings', 'sales_channel_sync_states', 'inventory_locations', 'operational_cost_entries'];

    public static function ready(): bool
    {
        foreach (self::REQUIRED as $table) {
            if (!Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }
}
