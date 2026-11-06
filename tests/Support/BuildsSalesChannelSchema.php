<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * The inferred core schema, the fulfillment tables, and this feature's own tables - all built from the REAL migrations - for tests of
 * the sales-channel, routing and operational-cost modules. Two extra roles exist for the permission tests: admin 4 holds only
 * `sales_channels`, admin 5 only `operational_costs`.
 */
trait BuildsSalesChannelSchema
{
    use BuildsFulfillmentSchema;

    protected function buildSalesChannelSchema(): void
    {
        $this->buildCoreSchema();
        $this->runFulfillmentMigrations();
        $this->runSalesChannelMigrations();

        DB::table('admin_roles')->insert([
            ['id' => 4, 'name' => 'Sales channels', 'module_access' => json_encode(['sales_channels']), 'status' => 1],
            ['id' => 5, 'name' => 'Operational costs', 'module_access' => json_encode(['operational_costs']), 'status' => 1],
        ]);
        DB::table('admins')->insert([
            ['id' => 4, 'name' => 'Channels admin', 'email' => 'channels@example.test', 'admin_role_id' => 4],
            ['id' => 5, 'name' => 'Costs admin', 'email' => 'costs@example.test', 'admin_role_id' => 5],
        ]);
    }

    /** Run the REAL migrations of the operational-cost, sales-channel and inventory/routing tables. */
    protected function runSalesChannelMigrations(): void
    {
        foreach ([
            '2026_11_07_100000_create_operational_cost_tables.php',
            '2026_11_07_100100_create_sales_channel_tables.php',
            '2026_11_07_100200_create_inventory_and_routing_tables.php',
            '2026_11_08_100000_add_state_before_dispute_to_operational_cost_entries.php',
        ] as $file) {
            (require database_path('migrations/' . $file))->up();
        }
    }
}
