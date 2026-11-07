<?php

namespace App\Services\SalesChannels\Trendyol;

use App\Enums\SalesChannels\IntegrationCapability as Capability;
use App\Services\SalesChannels\Support\ChannelOperations as Ops;

/**
 * Every call the Trendyol connector makes (Marketplace Integration API: Product V2, Order V2, claims, finance, webhooks).
 * Sources: developers.trendyol.com, v3.0 International (Gulf storefronts) and v1.0/v2.0 (Turkey); see docs/sales-channels/api-reference.md.
 */
final class TrendyolOperations
{
    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $lookup = [Capability::ConnectionTest, Capability::ReadOnlySync, Capability::ProductSync];
        $catalog = [Capability::ReadOnlySync, Capability::ProductSync, Capability::InventoryPublishing, Capability::PricePublishing];
        $orders = [Capability::ReadOnlySync, Capability::OrderImport, Capability::FulfillmentDispatch];
        $finance = [Capability::ReadOnlySync, Capability::FinancialReconciliation];

        return [
            // ---- lookups ----
            'trendyol.brands' => Ops::read(capabilities: $lookup),
            'trendyol.categories' => Ops::read(capabilities: $lookup),
            'trendyol.category_attributes' => Ops::read(capabilities: $lookup),
            'trendyol.category_attribute_values' => Ops::read(capabilities: $lookup),
            'trendyol.cargo_providers' => Ops::read(capabilities: $lookup),
            'trendyol.addresses' => Ops::read(capabilities: $lookup, people: true),

            // ---- products (V2) ----
            'trendyol.products_approved' => Ops::read(capabilities: $lookup),
            'trendyol.products_unapproved' => Ops::read(capabilities: $lookup),
            'trendyol.products_inventory_price' => Ops::read(capabilities: $lookup),
            'trendyol.product_by_barcode' => Ops::read(capabilities: $lookup),
            'trendyol.batch_result' => Ops::read(capabilities: $catalog),
            'trendyol.products_create' => Ops::write(['POST'], [Capability::ProductSync], [Ops::SCOPE_PRODUCT_WRITES]),
            'trendyol.products_update' => Ops::write(['POST'], [Capability::ProductSync], [Ops::SCOPE_PRODUCT_WRITES]),
            'trendyol.products_archive' => Ops::write(['PUT'], [Capability::ProductSync], [Ops::SCOPE_PRODUCT_WRITES], retrySafe: true),
            'trendyol.products_unlock' => Ops::write(['PUT'], [Capability::ProductSync], [Ops::SCOPE_PRODUCT_WRITES], retrySafe: true),
            // Sets absolute quantities and prices, so repeating it after a timeout is harmless (Trendyol itself refuses an identical body within 15 minutes).
            'trendyol.price_inventory_update' => Ops::write(['POST'], [Capability::InventoryPublishing, Capability::PricePublishing], ['inventory_publishing', 'price_publishing'], retrySafe: true),

            // ---- orders (V2) ----
            'trendyol.orders_list' => Ops::read(capabilities: $orders, people: true),
            'trendyol.orders_stream' => Ops::read(capabilities: $orders, people: true),
            // The package's shipping address, only for an order we are about to ship through a provider (see the buyer-data gate in ChannelOperations).
            'trendyol.order_recipient' => Ops::read(capabilities: [Capability::FulfillmentDispatch], buyerData: true),
            'trendyol.probe_orders' => Ops::read(capabilities: [Capability::ConnectionTest], people: true),
            'trendyol.orders_picking' => Ops::write(['PUT'], [Capability::FulfillmentDispatch], ['fulfillment_dispatch']),
            'trendyol.orders_tracking' => Ops::write(['PUT'], [Capability::FulfillmentDispatch], ['fulfillment_dispatch']),
            'trendyol.orders_invoice_link' => Ops::write(['POST'], [Capability::FulfillmentDispatch], ['fulfillment_dispatch']),
            'trendyol.orders_unsupplied' => Ops::write(['PUT'], [Capability::FulfillmentDispatch], ['fulfillment_dispatch']),

            // ---- returns ----
            'trendyol.claims_list' => Ops::read(capabilities: $orders, people: true),

            // ---- finance (current account statement) ----
            'trendyol.probe_finance' => Ops::read(capabilities: [Capability::ConnectionTest]),
            'trendyol.finance_settlements' => Ops::read(capabilities: $finance),
            'trendyol.finance_other' => Ops::read(capabilities: $finance),
            'trendyol.finance_payment_orders' => Ops::read(capabilities: $finance),

            // ---- webhooks ----
            'trendyol.webhooks_list' => Ops::read(capabilities: [Capability::ReadOnlySync, Capability::OrderImport]),
            'trendyol.webhooks_write' => Ops::write(['POST', 'PUT', 'DELETE'], [Capability::OrderImport], [Ops::SCOPE_WEBHOOK_MANAGEMENT]),
        ];
    }
}
