<?php

namespace App\Services\SalesChannels\Support;

use Illuminate\Support\Facades\Schema;

/**
 * The sales-channel code can be deployed before its migrations have run, and until they have it must leave the platform exactly as it was. Scheduled work asks first.
 */
final class SalesChannelSchema
{
    /** The modules' own tables, and the Torod Point / M5AZN fulfilment tables they read (shipments, their items, the provider stock figures): without those, parts of the modules would fail. */
    private const REQUIRED = [
        'sales_channel_accounts', 'external_order_mappings', 'sales_channel_sync_states', 'inventory_locations', 'operational_cost_entries', 'operational_cost_return_decisions',
        'provider_fulfillments', 'provider_fulfillment_items', 'provider_product_mappings',
    ];

    /**
     * The columns later migrations added to tables the first ones created: the ledger writes the first on every entry, the statement importer the next two on every event, the
     * refund reader the next two on every line and the invoice reconciliation the last on every comparison, so without them every one of those writes would fail.
     *
     * @var array<string, list<string>>
     */
    private const REQUIRED_COLUMNS = [
        'operational_cost_entries' => ['state_before_dispute'],
        'sales_channel_events' => ['charge_key', 'duplicate_of_id'],
        'external_order_lines' => ['refunded_total', 'refunded_tax'],
        'operational_cost_reconciliations' => ['basis'],
    ];

    public static function ready(): bool
    {
        foreach (self::REQUIRED as $table) {
            if (!Schema::hasTable($table)) {
                return false;
            }
        }
        foreach (self::REQUIRED_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if (!Schema::hasColumn($table, $column)) {
                    return false;
                }
            }
        }

        return true;
    }
}
