<?php

namespace App\Http\Middleware;

use App\Services\SalesChannels\Support\SalesChannelSchema;
use App\Services\SalesChannels\Support\SalesChannelSetup;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The sales-channel and cost screens can be reached as soon as the code is deployed, which may be before their migrations have run. Until they have, every page would fail on
 * a missing table: the page explains that instead, and changes nothing.
 */
class EnsureSalesChannelsMigrated
{
    public const MIGRATIONS = [
        '2026_11_05_100000_create_provider_fulfillment_tables',
        '2026_11_07_100000_create_operational_cost_tables',
        '2026_11_07_100100_create_sales_channel_tables',
        '2026_11_07_100200_create_inventory_and_routing_tables',
        '2026_11_08_100000_add_state_before_dispute_to_operational_cost_entries',
        '2026_11_09_100000_add_refund_columns_to_external_order_lines',
        '2026_11_09_100100_add_charge_matching_to_sales_channel_events',
        '2026_11_09_100200_create_operational_cost_return_decisions_table',
        '2026_11_09_100300_add_basis_to_operational_cost_reconciliations',
        '2026_11_09_200000_create_channel_order_dispatches_table',
        '2026_11_10_100000_add_stock_allocation',
        '2026_11_11_100000_add_purchase_order_sources_to_cost_tables',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (SalesChannelSchema::ready()) {
            SalesChannelSetup::prepare();

            return $next($request);
        }

        return response()->view('admin-views.sales-channels.not-migrated', ['migrations' => self::MIGRATIONS], 503);
    }
}
