<?php

/*
|--------------------------------------------------------------------------
| Multi-provider fulfillment (M5AZN + Torod Point / LogesTechs)
|--------------------------------------------------------------------------
|
| Everything here is a deployment-level default. Credentials are NEVER read from this file: they are
| entered in the admin panel (Integration Center) and stored encrypted in `integration_settings`.
|
| Outbound traffic is OFF until two independent switches are both on:
|   1. this file's `enabled` (an environment-level kill switch, FULFILLMENT_INTEGRATIONS_ENABLED), and
|   2. the provider's own `dispatch_enabled` setting in the admin panel.
*/

return [

    /*
    | Global kill switch. When false, no outbound provider call of any kind is made — not dispatch,
    | not sync, not even a connection test — and queued work waits instead of failing.
    */
    'enabled' => (bool) env('FULFILLMENT_INTEGRATIONS_ENABLED', false),

    /*
    | Second, independent environment switch: may anything be WRITTEN to a provider (an order, a product, a
    | cancellation)? Off by default, and it is enforced by the transport itself, below every other switch: with it
    | off the integration is READ-ONLY (logins and reads only) however the admin panel is configured. A staging copy of
    | production must keep this off. Turn it on only for a deliberate go-live. See docs/integrations/operations.md.
    */
    'external_writes_enabled' => (bool) env('FULFILLMENT_EXTERNAL_WRITES_ENABLED', false),

    /*
    | Parent-order currency the providers are paid/collect in. The store is KSA-only; amounts sent to a
    | provider are converted from the platform's stored base currency to this code.
    */
    'currency' => env('FULFILLMENT_CURRENCY', 'SAR'),

    'queue' => [
        'connection' => env('FULFILLMENT_QUEUE_CONNECTION'),
        'name' => env('FULFILLMENT_QUEUE', 'fulfillment'),
    ],

    'http' => [
        'connect_timeout' => 5,
        'timeout' => 20,
        // Reads only. A write is never retried inside the HTTP client: whether it may be repeated is
        // decided by the fulfillment state machine, which knows whether the first attempt could have
        // been accepted.
        'read_retries' => 2,
        'read_retry_delay_ms' => 400,
    ],

    'tracking' => [
        // An open shipment that has heard nothing from its provider for this long is reported by the readiness check.
        'stale_hours' => 48,
    ],

    'retry' => [
        'max_attempts' => 5,
        // Seconds to wait before attempt N+1 after a failure that is KNOWN not to have been accepted.
        'backoff_seconds' => [60, 300, 900, 3600, 21600],
        // A fulfillment stuck "dispatching" this long lost its worker mid-request. Its outcome is
        // unknown, so it moves to reconcile_pending — it is never simply re-sent.
        'stuck_dispatching_minutes' => 10,
        'max_reconcile_attempts' => 6,
    ],

    'dispatch' => [
        // Parent order statuses that make an order eligible for routing (in addition to the payment
        // rule: paid, or cash on delivery). Providers may narrow this in their own settings.
        'order_statuses' => ['confirmed', 'processing'],
    ],

    'cod' => [
        // A cash-on-delivery order whose collection would be split across more than one collector
        // (two providers, or a provider plus in-house delivery) is held unless this is true AND every
        // provider involved has `cod_verified` set in its settings. See docs/integrations/cod.md.
        'allow_mixed' => (bool) env('FULFILLMENT_ALLOW_MIXED_COD', false),
    ],

    'logs' => [
        'retention_days' => 60,
        'max_body_bytes' => 8192,
    ],

    'webhooks' => [
        // Provider timestamps further in the future than this are treated as clock-skewed and clamped.
        'max_future_skew_seconds' => 300,
    ],

    'providers' => [

        'm5azn' => [
            'label' => 'M5AZN',
            'label_ar' => 'M5AZN',
            'role' => 'dropshipping_supplier',
            // Official docs list three regional servers; this store trades in KSA only.
            'default_base_url' => 'https://m5azn.com',
            'default_country' => 'SA',
            'adapter' => \App\Services\Fulfillment\Providers\M5azn\M5aznAdapter::class,
            // M5AZN's official docs (m5azn-api.stoplight.io, retrieved 2026-11-05) document nine
            // operations. These flags state what that documentation actually establishes — they are
            // not aspirations. `false` means "not documented", not "not wanted".
            'documented' => [
                'login' => true,
                'catalog_list' => true,
                'catalog_details' => true,
                'stock_change_feed' => true,
                'create_order' => true,
                'order_list' => true,
                'order_details' => true,
                'product_push_webhook' => false,
                'order_status_webhook' => false,
                'cancel_order' => false,
                'return_order' => false,
                'margin_read' => false,
                'margin_write' => false,
                'response_schemas' => false,
            ],
        ],

        'torod_point' => [
            'label' => 'Torod Point',
            'label_ar' => 'تورود بوينت',
            'role' => 'fulfillment_warehouse',
            // Fulfillment API v5 — warehouse products and warehouse (fulfillment) orders.
            'default_base_url' => 'https://apisv5.logestechs.com/api',
            // Lastmile API v2 — used ONLY for cities, package cancel, AWB and package status. Its
            // "create shipment" call is deliberately never made: a warehouse order produces its own
            // last-mile package, so calling both would ship the parcel twice.
            'default_lastmile_base_url' => 'https://apisv2.logestechs.com/api',
            // Our LogesTechs company id (supplied by the business). It is pre-filled in the admin form
            // and must be saved there; it is not a secret. Never take this from an API example.
            'default_company_id' => 714,
            'adapter' => \App\Services\Fulfillment\Providers\TorodPoint\TorodPointAdapter::class,
            // What the three LogesTechs documents (Lastmile API v2, Fulfillment API v5, webhook status
            // updates) actually establish. `false` = "not documented", never "not wanted".
            'documented' => [
                'fulfillment_product_bulk_upsert' => true,
                'fulfillment_product_list' => true,
                'fulfillment_product_category_list' => true,
                'fulfillment_order_create' => true,
                'fulfillment_order_list' => true,
                'villages_search' => true,
                'lastmile_cities' => true,
                'lastmile_cancel_package' => true,
                'lastmile_awb' => true,
                'lastmile_package_status' => true,
                'lastmile_create_shipment' => true,
                'lastmile_webhook' => true,
                'fulfillment_webhook' => true,
                'webhook_signature' => false,
                'product_list_response_schema' => false,
                'product_list_stock_quantity' => false,
                'warehouse_inventory_endpoint' => false,
                'warehouse_order_create_response_schema' => false,
                'warehouse_order_list_response_schema' => false,
                'warehouse_order_cancel' => false,
                'warehouse_order_status_by_id' => false,
                'cod_collection_method_values' => false,
                'error_response_format' => false,
            ],
        ],
    ],
];
