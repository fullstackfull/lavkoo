<?php

namespace App\Services\SalesChannels\Amazon;

use App\Enums\SalesChannels\IntegrationCapability as Capability;
use App\Services\SalesChannels\Support\ChannelOperations as Ops;

/**
 * Every call the Amazon connector makes (Selling Partner API). Versions follow the official documentation as read on 2026-11-06:
 * Orders v2026-01-01 (v0 reads are deprecated and removed on 2027-03-27; v0 confirmShipment is still the only write), Finances v2024-06-19,
 * Listings Items 2021-08-01, Catalog Items 2022-04-01, Product Type Definitions 2020-09-01, FBA Inventory v1, Reports 2021-06-30,
 * Feeds 2021-06-30 (JSON_LISTINGS_FEED only), Notifications v1, Sellers v1. See docs/sales-channels/api-reference.md.
 */
final class AmazonOperations
{
    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $read = [Capability::ReadOnlySync, Capability::ProductSync, Capability::OrderImport, Capability::FinancialReconciliation];
        $probe = [Capability::ConnectionTest];
        $orders = [Capability::ReadOnlySync, Capability::OrderImport, Capability::FulfillmentDispatch];
        $listings = [Capability::ReadOnlySync, Capability::ProductSync, Capability::InventoryPublishing, Capability::PricePublishing];
        $publishing = [Capability::InventoryPublishing, Capability::PricePublishing];

        return [
            'amazon.lwa_token' => Ops::auth(['POST']),

            // ---- connection test: harmless reads that show which roles the authorisation carries ----
            'amazon.probe_participations' => Ops::read(capabilities: $probe),
            'amazon.probe_orders' => Ops::read(capabilities: $probe, people: true),
            'amazon.probe_listings' => Ops::read(capabilities: $probe),
            'amazon.probe_fba_inventory' => Ops::read(capabilities: $probe),
            'amazon.probe_finances' => Ops::read(capabilities: $probe),

            // ---- orders (v2026-01-01) ----
            'amazon.orders_search' => Ops::read(capabilities: $orders, people: true),
            'amazon.order_get' => Ops::read(capabilities: $orders, people: true),
            // The buyer's delivery address, only for an order we are about to ship through a provider: needs the buyer-data switch and a master admin's approval on top of the capability.
            // The query is pinned so that this call can never ask for the buyer's own details, tax or payment data.
            'amazon.order_recipient' => Ops::read(capabilities: [Capability::FulfillmentDispatch], buyerData: true, requiredQuery: ['includedData' => 'RECIPIENT,FULFILLMENT']),
            'amazon.order_confirm_shipment' => Ops::write(['POST'], [Capability::FulfillmentDispatch], ['fulfillment_dispatch'], people: true),

            // ---- listings, catalog, product types ----
            'amazon.listings_search' => Ops::read(capabilities: $listings),
            'amazon.listing_get' => Ops::read(capabilities: $listings),
            'amazon.catalog_search' => Ops::read(capabilities: $listings),
            'amazon.product_type_definition' => Ops::read(capabilities: $listings),
            // A PATCH that submits nothing: Amazon checks the patch and answers VALID or INVALID. It is still a PATCH with the real values in it, so every write gate applies to it (a
            // rehearsal logs it and sends nothing; without the switches and the approval it is never sent), and the transport refuses it without mode=VALIDATION_PREVIEW.
            'amazon.listings_validate' => Ops::write(['PATCH'], $publishing, ['price_publishing', 'inventory_publishing'], retrySafe: true, requiredQuery: ['mode' => 'VALIDATION_PREVIEW']),
            'amazon.listings_patch_price' => Ops::write(['PATCH'], [Capability::PricePublishing], ['price_publishing'], retrySafe: true),
            'amazon.listings_patch_quantity' => Ops::write(['PATCH'], [Capability::InventoryPublishing], ['inventory_publishing'], retrySafe: true),
            // Creating or replacing the whole listing of one of our SKUs (putListingsItem). It SETS the listing rather than adding one, and the SKU is ours, so repeating it after
            // a timeout cannot make a second listing at Amazon. It needs the product-sync capability and the product-writes approval, not the price or quantity ones.
            'amazon.listings_put' => Ops::write(['PUT'], [Capability::ProductSync], [Ops::SCOPE_PRODUCT_WRITES], retrySafe: true),
            // Changing the CONTENT of a listing that exists (title, brand, attributes): only the fields given, each replaced, nothing removed. A full PUT on an existing listing
            // would replace it and lose every attribute the draft does not name, so content changes come this way.
            'amazon.listings_patch_content' => Ops::write(['PATCH'], [Capability::ProductSync], [Ops::SCOPE_PRODUCT_WRITES], retrySafe: true),

            // ---- bulk updates (JSON_LISTINGS_FEED) ----
            'amazon.feeds_create_document' => Ops::write(['POST'], $publishing, ['price_publishing', 'inventory_publishing'], retrySafe: true),
            'amazon.feeds_upload' => Ops::write(['PUT'], $publishing, ['price_publishing', 'inventory_publishing'], retrySafe: true),
            'amazon.feeds_create' => Ops::write(['POST'], $publishing, ['price_publishing', 'inventory_publishing']),
            'amazon.feeds_get' => Ops::read(capabilities: $publishing),
            'amazon.feeds_document' => Ops::read(capabilities: $publishing),

            // ---- FBA stock ----
            'amazon.fba_inventory' => Ops::read(capabilities: $read),

            // ---- finances ----
            'amazon.finances_transactions' => Ops::read(capabilities: [Capability::ReadOnlySync, Capability::FinancialReconciliation]),
            'amazon.finances_groups' => Ops::read(capabilities: [Capability::ReadOnlySync, Capability::FinancialReconciliation]),

            // ---- reports (a report request reads; it changes nothing the seller owns) ----
            'amazon.reports_create' => Ops::read(methods: ['POST'], capabilities: $read, retrySafe: false),
            'amazon.reports_get' => Ops::read(capabilities: $read),
            // getReports. **The only way a settlement report is ever found**: Amazon's own page says settlement reports "cannot
            // be requested or scheduled" and are searched for with this operation, so a reader without it has no settlement at
            // all. It is also how a report whose id was lost is found again.
            'amazon.reports_list' => Ops::read(capabilities: $read),
            'amazon.reports_document' => Ops::read(capabilities: $read),
            // The presigned S3 download. Not an SP-API path and it carries NO Amazon credential: the URL is already signed, and
            // adding the access token would send a credential somewhere it was not asked for.
            'amazon.report_download' => Ops::read(capabilities: $read),

            // ---- notifications (delivered to the seller's own AWS queue, never to a URL here) ----
            'amazon.notifications_read' => Ops::read(capabilities: [Capability::ReadOnlySync, Capability::OrderImport]),
            'amazon.notifications_write' => Ops::write(['POST', 'DELETE'], [Capability::OrderImport], [Ops::SCOPE_NOTIFICATION_MANAGEMENT]),
        ];
    }
}
