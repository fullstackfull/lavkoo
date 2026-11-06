<?php

namespace App\Services\SalesChannels\Support;

use Illuminate\Support\Facades\Schema;

/**
 * The sales-channel code can be deployed before its migrations have run, and until they have it must leave the platform exactly as it was. Scheduled work asks first.
 */
final class SalesChannelSchema
{
    private const REQUIRED = ['sales_channel_accounts', 'external_order_mappings', 'sales_channel_sync_states', 'inventory_locations', 'operational_cost_entries'];

    /** A column a later migration added to a table the first ones created: the ledger writes it on every entry, so without it every write would fail. */
    private const REQUIRED_COLUMNS = ['operational_cost_entries' => 'state_before_dispute'];

    public static function ready(): bool
    {
        foreach (self::REQUIRED as $table) {
            if (!Schema::hasTable($table)) {
                return false;
            }
        }
        foreach (self::REQUIRED_COLUMNS as $table => $column) {
            if (!Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }
}
