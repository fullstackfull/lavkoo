<?php

namespace App\Services\SalesChannels\Noon;

use App\Enums\SalesChannels\IntegrationCapability as Capability;
use App\Services\SalesChannels\Support\ChannelOperations as Ops;

/**
 * Every call the noon connector may make. Versions and paths follow the official partner documentation (noon-docs.noonpartners.dev) as read on 2026-11-08.
 *
 * WHAT IS VERIFIED AND WHAT IS NOT, because it decides what this module is allowed to do:
 *
 *  - The HTTP METHOD and the exact PATH of every operation below are quoted from the documentation's own reference pages. Nothing here is inferred from an operation's name
 *    or from a documentation URL slug.
 *  - The REQUEST AND RESPONSE SHAPES ARE NOT PUBLISHED in a form that can be read: on every reference page the Request and Responses sections are rendered in the browser
 *    and come back empty. So the FIELD NAMES of every call but the sign-in are unverified.
 *
 * That is why a path being registered here does NOT mean the connector calls it. Registering it only means the transport would not refuse it; the connector implements a call
 * only once its body is known from the documentation or from a real account. This file is therefore the allow-list AND the honest record of how far the documentation goes,
 * in the same way `AmazonOperations` and `TrendyolOperations` are for theirs. See docs/sales-channels/api-reference.md.
 *
 * Rate limits the reference pages state: 1 500 requests per 60 seconds with a burst of 1 500 per 60 seconds for every operation, except listing warehouses (30 per 60
 * seconds, burst 15 per 30 seconds). They are per PROJECT CODE, and sandbox traffic counts against the same quota.
 */
final class NoonOperations
{
    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $read = [Capability::ReadOnlySync, Capability::ProductSync, Capability::OrderImport, Capability::FinancialReconciliation];
        $probe = [Capability::ConnectionTest];
        $orders = [Capability::ReadOnlySync, Capability::OrderImport, Capability::FulfillmentDispatch];
        $publishing = [Capability::InventoryPublishing, Capability::PricePublishing];

        return [
            // ---- signing in -------------------------------------------------------------------------------------
            // A JWT signed RS256 with the service account's private key is exchanged for a session cookie that lasts 30 days. This is the one call whose body IS documented:
            // `token` (the JWT) and `default_project_code`. It ALWAYS goes to the production host, even when every other call goes to the sandbox.
            'noon.login' => Ops::auth(['POST']),
            // Who the session belongs to. The connection test, and the only call this version makes besides signing in.
            'noon.whoami' => Ops::read(capabilities: $probe),

            // ---- OAuth, for an integrator acting on a seller's behalf --------------------------------------------
            // Registered because the flow is documented, not because it is built: this application holds one seller's own credentials, not an integrator's.
            'noon.oauth_token_create' => Ops::write(['POST'], [Capability::ConnectionTest], [Ops::SCOPE_PRODUCT_WRITES]),
            'noon.oauth_token_exchange' => Ops::write(['POST'], [Capability::ConnectionTest], [Ops::SCOPE_PRODUCT_WRITES]),

            // ---- the seller's warehouses ------------------------------------------------------------------------
            // Stock is addressed by a (warehouse, partner_sku) PAIR, so the warehouses have to be known before any stock call means anything.
            'noon.warehouses_list' => Ops::read(['POST'], capabilities: $read),

            // ---- stock ------------------------------------------------------------------------------------------
            // A read and a write, both POST. The read's description names the identifier: "a list of warehouse and partner_sku pairs".
            'noon.stock_list' => Ops::read(['POST'], capabilities: $read),
            'noon.stock_update' => Ops::write(['POST'], [Capability::InventoryPublishing], ['inventory_publishing'], retrySafe: true),

            // ---- price ------------------------------------------------------------------------------------------
            'noon.pricing_get' => Ops::read(['POST'], capabilities: $read),
            'noon.pricing_upsert' => Ops::write(['POST'], [Capability::PricePublishing], ['price_publishing'], retrySafe: true),

            // ---- the catalogue ----------------------------------------------------------------------------------
            // Creates or updates a product; noon decides create from update by our own `partner_sku`. A product cannot enter its quality review until it is 100% complete.
            'noon.product_upsert' => Ops::write(['POST'], [Capability::ProductSync], [Ops::SCOPE_PRODUCT_WRITES], retrySafe: true),
            // Ties one of our `partner_sku`s to a barcode, creating the barcode if noon does not hold it yet.
            'noon.barcode_map' => Ops::write(['POST'], [Capability::ProductSync], [Ops::SCOPE_PRODUCT_WRITES], retrySafe: true),

            // ---- orders fulfilled from OUR warehouse (FBPI) -----------------------------------------------------
            // noon sends a webhook when an order is created and the order itself is then fetched by its number. The buyer's details are a separate call and are gated as such.
            'noon.fbpi_order_get' => Ops::read(capabilities: $orders, people: true),
            'noon.fbpi_orders_list' => Ops::read(['POST'], capabilities: $orders, people: true),
            // Pages of at most 50, continued with the `next_token` the previous answer gave, filtered by `created_after` / `created_before` in UTC.
            'noon.shipment_create' => Ops::write(['POST'], [Capability::FulfillmentDispatch], ['fulfillment_dispatch']),

            // ---- where noon sends its events --------------------------------------------------------------------
            'noon.webhook_destination_create' => Ops::write(['POST'], [Capability::OrderImport], [Ops::SCOPE_WEBHOOK_MANAGEMENT]),

            // ---- the sandbox's own call -------------------------------------------------------------------------
            // Makes a test order in the static sandbox and triggers the order webhook. It persists nothing and calls nothing downstream.
            'noon.sandbox_order_create' => Ops::write(['POST'], [Capability::ConnectionTest], [Ops::SCOPE_PRODUCT_WRITES]),
        ];
    }

    /**
     * The documented path of each operation, so that one place says what every call is and a test can hold this file to the documentation.
     *
     * `:fbpi_order_nr` is replaced by the order's number. Paths are relative to the host; the sign-in path is relative to the PRODUCTION host always.
     *
     * @return array<string, string>
     */
    public static function paths(): array
    {
        return [
            'noon.login' => '/identity/public/v1/api/login',
            'noon.whoami' => '/identity/v1/whoami',
            'noon.oauth_token_create' => '/identity/oauth/v1/token/create',
            'noon.oauth_token_exchange' => '/identity/oauth/v1/token/exchange',
            'noon.warehouses_list' => '/v1/warehouses/list',
            'noon.stock_list' => '/v1/stock-list',
            'noon.stock_update' => '/v1/stock-update',
            'noon.pricing_get' => '/v1/pricing/get',
            'noon.pricing_upsert' => '/v1/pricing/upsert',
            'noon.product_upsert' => '/v1/product/upsert',
            'noon.barcode_map' => '/v1/barcode/map',
            'noon.fbpi_order_get' => '/v1/fbpi-order/:fbpi_order_nr/get',
            'noon.fbpi_orders_list' => '/v1/fbpi-orders/list',
            'noon.shipment_create' => '/v1/shipment/create',
            'noon.webhook_destination_create' => '/v1/destination/https-destination/create',
            'noon.sandbox_order_create' => '/v1/sandbox-order/create',
        ];
    }

    /**
     * The operations this version really CALLS. Everything else above is registered and unused, because its request body is not documented.
     *
     * @return list<string>
     */
    public static function built(): array
    {
        return ['noon.login', 'noon.whoami'];
    }
}
