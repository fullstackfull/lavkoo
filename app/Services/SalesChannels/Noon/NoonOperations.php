<?php

namespace App\Services\SalesChannels\Noon;

use App\Enums\SalesChannels\IntegrationCapability as Capability;
use App\Services\SalesChannels\Support\ChannelOperations as Ops;

/**
 * Every call the noon connector may make. Paths follow the official partner documentation (noon-docs.noonpartners.dev) as read on 2026-11-08.
 *
 * THE SERVICE SEGMENT IS PART OF THE PATH. `stock`, `pricing`, `content`, `catplat`, `fbpi`, `warehouse-platform`, `event-notifications`, `identity`: noon's reference pages
 * document one full URL on one gateway host, not a path relative to a per-service base. An earlier version of this file registered `/v1/stock-update` and the like, which
 * would have been a 404 against every one of them. `paths()` below is the single place that says what a call is, and a test holds it to this list.
 *
 * Note the two identity prefixes are genuinely different and neither is a typo: the sign-in is `/identity/public/v1/api/login` and the identity read is `/identity/v1/whoami`.
 *
 * NOTHING IS REGISTERED ON A PATH THAT WAS NOT READ. Several operations exist in noon's index whose own reference pages were not opened — OAuth, the single FBPI order read,
 * the buyer's details, cancelling or reading a shipment, invoices, the sandbox order, creating an event destination, returns, FBPO, Offer and the Reports exports. Their
 * paths are NOT guessed from the pattern of their neighbours, because a plausible path is still a wrong one; they are absent until their page is read. See
 * docs/sales-channels/api-reference.md for what is verified and what is not.
 *
 * Rate limits the reference pages state: 1 500 requests per 60 seconds with a burst of 1 500 per 60 seconds for every operation EXCEPT listing warehouses, which is 30 per
 * 60 seconds with a burst of 15 per 30 seconds. They are per PROJECT CODE, and sandbox traffic counts against the same quota.
 */
final class NoonOperations
{
    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $read = [Capability::ReadOnlySync, Capability::ProductSync, Capability::OrderImport, Capability::FinancialReconciliation];
        $probe = [Capability::ConnectionTest];
        $orders = [Capability::ReadOnlySync, Capability::OrderImport, Capability::FulfillmentDispatch];

        return [
            // ---- signing in -------------------------------------------------------------------------------------
            // A JWT signed RS256 with the key file's private key is exchanged for session cookies. Body: `token` and `default_project_code`.
            // It ALWAYS goes to the production host, even when every other call of a sandbox account goes to the sandbox.
            'noon.login' => Ops::auth(['POST']),
            // Who the session belongs to: `user_code` and `username`. The connection test.
            'noon.whoami' => Ops::read(capabilities: $probe),

            // ---- the seller's warehouses ------------------------------------------------------------------------
            // Stock is addressed by a (warehouse_code, partner_sku) PAIR, so the warehouses have to be known before a stock figure means anything.
            'noon.warehouses_list' => Ops::read(['POST'], capabilities: $read),

            // ---- stock ------------------------------------------------------------------------------------------
            // `qty` is ABSOLUTE: it becomes the new available quantity and is not added to what noon holds.
            'noon.stock_list' => Ops::read(['POST'], capabilities: $read),
            'noon.stock_update' => Ops::write(['POST'], [Capability::InventoryPublishing], ['inventory_publishing'], retrySafe: true),

            // ---- price ------------------------------------------------------------------------------------------
            // Per (partner_sku, country_code). Omitting a field, or sending null, leaves the stored value as it is — null does NOT clear it.
            'noon.pricing_get' => Ops::read(['POST'], capabilities: $read),
            'noon.pricing_upsert' => Ops::write(['POST'], [Capability::PricePublishing], ['price_publishing'], retrySafe: true),

            // ---- the catalogue ----------------------------------------------------------------------------------
            // The category codes, each a complete `family-product_type-product_subtype` path, and the attributes of one category with `is_mandatory` on each.
            'noon.categories_list' => Ops::read(['POST'], capabilities: $read),
            'noon.category_attributes_list' => Ops::read(['POST'], capabilities: $read),
            // Creates or amends a catalogue item, noon deciding which by our own `partner_sku`, and answers `sku_parent` plus a variant per size.
            'noon.product_upsert' => Ops::write(['POST'], [Capability::ProductSync], [Ops::SCOPE_PRODUCT_WRITES], retrySafe: true),
            // What noon holds for a `sku_parent`: its completeness, the attributes it is still missing or rejects, and its quality-review state.
            'noon.product_content_get' => Ops::read(['POST'], capabilities: $read),
            'noon.product_group_upsert' => Ops::write(['POST'], [Capability::ProductSync], [Ops::SCOPE_PRODUCT_WRITES], retrySafe: true),
            // Ties one of our `partner_sku` values to a barcode, creating the barcode if noon does not hold it yet.
            'noon.barcode_map' => Ops::write(['POST'], [Capability::ProductSync], [Ops::SCOPE_PRODUCT_WRITES], retrySafe: true),

            // ---- orders fulfilled from OUR warehouse (FBPI) -----------------------------------------------------
            // Pages of at most 50 for one warehouse, continued with `next_token` as a QUERY parameter, filtered by `created_after` / `created_before` in UTC.
            'noon.fbpi_orders_list' => Ops::read(['POST'], capabilities: $orders, people: true),
            'noon.shipment_create' => Ops::write(['POST'], [Capability::FulfillmentDispatch], ['fulfillment_dispatch']),

            // ---- where noon sends its events --------------------------------------------------------------------
            // Reading the destinations only. Creating one is a different operation whose page was not read, so it is not registered.
            'noon.webhook_destinations_list' => Ops::read(capabilities: [Capability::ReadOnlySync, Capability::OrderImport]),
        ];
    }

    /**
     * The documented FULL path of each operation, service segment and all. One place says what every call is.
     *
     * @return array<string, string>
     */
    public static function paths(): array
    {
        return [
            'noon.login' => '/identity/public/v1/api/login',
            'noon.whoami' => '/identity/v1/whoami',
            'noon.warehouses_list' => '/warehouse-platform/v1/warehouses/list',
            'noon.stock_list' => '/stock/v1/stock-list',
            'noon.stock_update' => '/stock/v1/stock-update',
            'noon.pricing_get' => '/pricing/v1/pricing/get',
            'noon.pricing_upsert' => '/pricing/v1/pricing/upsert',
            'noon.categories_list' => '/content/v1/categories/list',
            'noon.category_attributes_list' => '/content/v1/categories/attributes/list',
            'noon.product_upsert' => '/content/v1/product/upsert',
            'noon.product_content_get' => '/content/v1/product/content/get',
            'noon.product_group_upsert' => '/content/v1/product/group/upsert',
            'noon.barcode_map' => '/catplat/v1/barcode/map',
            'noon.fbpi_orders_list' => '/fbpi/v1/fbpi-orders/list',
            'noon.shipment_create' => '/fbpi/v1/shipment/create',
            'noon.webhook_destinations_list' => '/event-notifications/v1/destination/list',
        ];
    }

    /**
     * Operations noon's index names but whose own reference page has not been read, so no path is known for them.
     *
     * They are listed here rather than left unsaid, so that "not registered" reads as a gap somebody can close by reading one page, and never as "noon has no such call".
     * Guessing their paths from their neighbours is exactly what produced the 404s this file was corrected for.
     *
     * @return list<string>
     */
    public static function documentedButUnread(): array
    {
        return [
            'oauth.create_token', 'oauth.exchange_token', 'api_user.create_credential', 'api_user.remove_credentials',
            'fbpi.get_order', 'fbpi.get_order_customer_data', 'fbpi.update_order', 'fbpi.cancel_shipment', 'fbpi.get_shipment',
            'fbpi.add_shipment_courier_awbs', 'fbpi.get_noon_logistics_awbs', 'fbpi.upload_order_invoice', 'fbpi.upload_shipment_invoice',
            'fbpi.create_sandbox_order',
            'catalog.sku_generate', 'catalog.batch_sku_map', 'catalog.sku_rename', 'catalog.sku_delete', 'catalog.barcode_import',
            'content.get_product_group', 'content.delete_product_group',
            'event_notifications.create_destination', 'event_notifications.get_destination', 'event_notifications.update_destination', 'event_notifications.list_event_types',
            'returns.list_return_references', 'fbpo.get_purchase_order', 'offer.get_product_offers',
            'reports.create_export', 'reports.get_export_status', 'reports.get_export_category_list',
        ];
    }

    /**
     * Noon's own success marker inside a 200. Every batch call answers one `items[]` entry per item submitted, each carrying its own `status`, and the pricing guide is
     * explicit that "a 200 response does not mean every price was set".
     */
    public const ITEM_OK_CODE = 'OK';

    public const ITEM_OK_ID = 0;
}
