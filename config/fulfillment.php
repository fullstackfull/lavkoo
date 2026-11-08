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
    |
    | The three switches in this file are read with FILTER_VALIDATE_BOOLEAN, not a (bool) cast: the cast turns the usual ways of
    | writing "off" (off, no, n, disabled, 0.0) into TRUE, so a person typing one of them in a hurry would switch the thing ON.
    | Here only true, 1, on and yes mean on; anything else, a typo included, means off.
    */
    'enabled' => filter_var(env('FULFILLMENT_INTEGRATIONS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    /*
    | Second, independent environment switch: may anything be WRITTEN to a provider (an order, a product, a
    | cancellation)? Off by default, and it is enforced by the transport itself, below every other switch: with it
    | off the integration is READ-ONLY (logins and reads only) however the admin panel is configured. A staging copy of
    | production must keep this off. Turn it on only for a deliberate go-live. See docs/integrations/operations.md.
    */
    'external_writes_enabled' => filter_var(env('FULFILLMENT_EXTERNAL_WRITES_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    /*
    | Parent-order currency the providers are paid/collect in. The store is KSA-only; amounts sent to a
    | provider are converted from the platform's stored base currency to this code.
    */
    /*
    | Changing a product's fulfilment provider is a decision about what comes NEXT: an order already placed carries the provider it was placed with
    | (the line's own snapshot, which the router reads instead of the product), so a shipment in flight never moves and a line not routed yet keeps
    | its provider too. The change records how many such lines stayed behind.
    |
    | true restores the older, stricter rule: refuse the change until every order for the product has finished with its current provider. Nothing in
    | the code needs it; it is here for an owner who prefers the product and its open orders never to disagree.
    */
    'lock_provider_while_orders_are_in_flight' => filter_var(env('FULFILLMENT_LOCK_PROVIDER_WHILE_ORDERS_IN_FLIGHT', false), FILTER_VALIDATE_BOOLEAN),

    'currency' => env('FULFILLMENT_CURRENCY', 'SAR'),

    /*
    | Codes the store's own currency table may use for a currency that providers know by its ISO code. This store's riyal is
    | stored as "KSA" (the country, not the currency): it is the same money as SAR, so an amount in it needs no conversion, and a
    | provider is always sent the ISO code. The map is read in one direction only (store spelling => ISO code); it never changes a
    | row of the currency table.
    */
    'currency_aliases' => [
        'KSA' => 'SAR',
    ],

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
        'allow_mixed' => filter_var(env('FULFILLMENT_ALLOW_MIXED_COD', false), FILTER_VALIDATE_BOOLEAN),
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
            /*
            | The city-list refresh (Lastmile `addresses/cities`), which the page walk reads within these limits. The
            | cached copy is 20,858 cities at 100 a page — about 209 pages — so the first version's 100-page ceiling
            | could not reach the end of the list; 250 leaves room for it to grow. These are the deployment defaults:
            | the sync needs no hand-edited setting on a server, and an environment variable only moves them.
            |
            | Every value is clamped by `TorodCityDirectory` (max_pages to MAX_PAGES_CEILING = 500 pages = 50,000
            | cities, max_seconds to 1800), so no setting here can make the walk unbounded. The walk also stops by
            | itself the moment a page repeats what it has seen, comes back empty, or the provider's own total is
            | reached — the ceilings are the last resort, not the normal end.
            |
            | A walk that runs out of pages or time is reported `partial`: it still adds and updates every city it
            | read, and a partial reading never deletes a city, never marks one missing and never changes a mapping a
            | person approved. So raising a limit can only read more, and lowering one can only read less.
            */
            'cities' => [
                'max_pages' => (int) env('TOROD_CITY_SYNC_MAX_PAGES', 250),
                // The surface the sync runs on: a console run (the `fulfillment:sync` command, the queue worker, the
                // schedule) walks the whole list; the admin's "sync now" answers an HTTP request and stays short.
                'max_seconds_console' => (int) env('TOROD_CITY_SYNC_MAX_SECONDS', 600),
                'max_seconds_web' => 45,
                // Fewer cities than this is never taken for a whole country's list on the weakest evidence alone.
                'complete_floor' => 1000,
            ],
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
