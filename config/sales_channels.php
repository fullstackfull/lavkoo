<?php

/*
|--------------------------------------------------------------------------
| Sales channels (Amazon, Trendyol)
|--------------------------------------------------------------------------
|
| Deployment-level defaults only. Credentials are NEVER read from this file or from the environment: they are entered in the admin panel and stored
| encrypted on the channel account. Everything ships OFF.
|
| Outbound traffic needs three independent switches, all of which must be on for a write to leave this host:
|   1. SALES_CHANNELS_ENABLED               - the global kill switch: off means no call of any kind, not even a connection test.
|   2. SALES_CHANNELS_EXTERNAL_WRITES_ENABLED - off means READ-ONLY: only GETs and sign-ins leave the host, whatever the admin panel says.
|   3. the account's own switches (enabled, write enabled, dry run, and the capability being used with its approval).
|
| The two environment values are read with FILTER_VALIDATE_BOOLEAN, not a (bool) cast: the cast turns "off", "no" and "disabled" into TRUE.
*/
return [
    'enabled' => filter_var(env('SALES_CHANNELS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'external_writes_enabled' => filter_var(env('SALES_CHANNELS_EXTERNAL_WRITES_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // Classes whose static operations() list the calls each connector may make (see ChannelOperations).
    'operation_providers' => [
        \App\Services\SalesChannels\Trendyol\TrendyolOperations::class,
        \App\Services\SalesChannels\Amazon\AmazonOperations::class,
    ],

    'queue' => [
        'connection' => env('SALES_CHANNELS_QUEUE_CONNECTION'),
        'name' => env('SALES_CHANNELS_QUEUE', 'sales_channels'),
    ],

    'http' => [
        'connect_timeout' => 5,
        'timeout' => 30,
        // Reads (and writes that set an absolute value, so that repeating them is harmless) are retried this many times on a rate limit, a
        // 5xx or a dropped connection, with exponential back-off and the channel's own Retry-After when it sends one.
        'retries' => 3,
        'retry_base_milliseconds' => 500,
        'retry_max_seconds' => 30,
    ],

    'logs' => [
        'retention_days' => 60,
        'max_body_bytes' => 8192,
    ],

    // Our own pacing, kept a little under each channel's published limit so the channel's 429 is the exception. A fixed window: `max` calls per `per_seconds`.
    // `bucket` makes operations that the channel counts together share one allowance (the connection test's order probe uses the order search's).
    'rate_limits' => [
        'trendyol' => [
            // Trendyol: 50 requests per 10 seconds to the same endpoint, then HTTP 429; no Retry-After is sent.
            'default' => ['max' => 40, 'per_seconds' => 10],
            'operations' => [
                'trendyol.orders_list' => ['max' => 25, 'per_seconds' => 60],
                'trendyol.orders_stream' => ['max' => 10, 'per_seconds' => 60],
                'trendyol.probe_orders' => ['max' => 10, 'per_seconds' => 60, 'bucket' => 'trendyol.orders_stream'],
                'trendyol.probe_finance' => ['max' => 80, 'per_seconds' => 60, 'bucket' => 'trendyol.finance_settlements'],
                'trendyol.claims_list' => ['max' => 100, 'per_seconds' => 60],
                'trendyol.finance_settlements' => ['max' => 80, 'per_seconds' => 60],
                'trendyol.finance_other' => ['max' => 80, 'per_seconds' => 60],
                'trendyol.price_inventory_update' => ['max' => 300, 'per_seconds' => 60],
                'trendyol.products_create' => ['max' => 150, 'per_seconds' => 60],
                'trendyol.products_update' => ['max' => 150, 'per_seconds' => 60],
                'trendyol.orders_picking' => ['max' => 250, 'per_seconds' => 60],
                'trendyol.orders_tracking' => ['max' => 250, 'per_seconds' => 60],
            ],
        ],
        'amazon' => [
            'default' => ['max' => 2, 'per_seconds' => 1],
            'operations' => [
                'amazon.lwa_token' => ['max' => 10, 'per_seconds' => 10],
                // searchOrders: 0.0056 requests/second, burst 20.
                'amazon.orders_search' => ['max' => 18, 'per_seconds' => 3600],
                'amazon.probe_orders' => ['max' => 18, 'per_seconds' => 3600, 'bucket' => 'amazon.orders_search'],
                'amazon.order_get' => ['max' => 25, 'per_seconds' => 60],
                'amazon.order_confirm_shipment' => ['max' => 2, 'per_seconds' => 1],
                'amazon.listings_search' => ['max' => 5, 'per_seconds' => 1],
                'amazon.probe_listings' => ['max' => 5, 'per_seconds' => 1, 'bucket' => 'amazon.listings_search'],
                'amazon.listing_get' => ['max' => 5, 'per_seconds' => 1],
                'amazon.listings_validate' => ['max' => 5, 'per_seconds' => 1],
                'amazon.listings_patch_price' => ['max' => 5, 'per_seconds' => 1],
                'amazon.listings_patch_quantity' => ['max' => 5, 'per_seconds' => 1],
                'amazon.catalog_search' => ['max' => 2, 'per_seconds' => 1],
                'amazon.product_type_definition' => ['max' => 5, 'per_seconds' => 1],
                'amazon.fba_inventory' => ['max' => 2, 'per_seconds' => 1],
                'amazon.probe_fba_inventory' => ['max' => 2, 'per_seconds' => 1, 'bucket' => 'amazon.fba_inventory'],
                // listTransactions: 0.5 requests/second, burst 10.
                'amazon.finances_transactions' => ['max' => 10, 'per_seconds' => 20],
                'amazon.probe_finances' => ['max' => 10, 'per_seconds' => 20, 'bucket' => 'amazon.finances_transactions'],
                'amazon.finances_groups' => ['max' => 10, 'per_seconds' => 20],
                // getMarketplaceParticipations: 0.016 requests/second, burst 15.
                'amazon.probe_participations' => ['max' => 10, 'per_seconds' => 900],
                'amazon.reports_create' => ['max' => 10, 'per_seconds' => 900],
                'amazon.reports_document' => ['max' => 10, 'per_seconds' => 900],
                'amazon.feeds_create' => ['max' => 4, 'per_seconds' => 300],
                'amazon.feeds_create_document' => ['max' => 10, 'per_seconds' => 20],
            ],
        ],
    ],

    'amazon' => [
        'lwa_url' => 'https://api.amazon.com/auth/o2/token',
        'endpoints' => [
            'production' => ['na' => 'https://sellingpartnerapi-na.amazon.com', 'eu' => 'https://sellingpartnerapi-eu.amazon.com', 'fe' => 'https://sellingpartnerapi-fe.amazon.com'],
            'sandbox' => ['na' => 'https://sandbox.sellingpartnerapi-na.amazon.com', 'eu' => 'https://sandbox.sellingpartnerapi-eu.amazon.com', 'fe' => 'https://sandbox.sellingpartnerapi-fe.amazon.com'],
        ],
        // The User-Agent Amazon requires on every call: application, version, language.
        'user_agent' => ['application' => 'PharmacyChannelHub', 'version' => '1.0'],
        // A fresh access token is fetched when the one held has less than this many seconds left (tokens last 3600).
        'token_refresh_margin_seconds' => 300,
        // Everything searchOrders is asked to include. BUYER is requested only when the account asks for buyer data; names, addresses and phones are never stored on the channel order record.
        'orders_included_data' => ['FULFILLMENT', 'PROCEEDS', 'PACKAGES', 'CANCELLATION', 'TAX', 'RECIPIENT', 'PAYMENT'],
        // Amazon's financial breakdown types are an open set. These patterns decide what a type means for our books; anything that matches none is kept as an event for a person, never as a cost or as revenue.
        'finance_map' => [
            'fee' => [
                '/commission|referral/i' => 'marketplace_commission',
                '/^fba[ _-]?fees?$/i' => 'fba_fulfillment',
                '/fba.*(fulfil|pick|pack|weight|handling|delivery|order|unit)/i' => 'fba_fulfillment',
                '/fba.*(storage)|storage/i' => 'fba_storage',
                '/fba.*return|return.*fee|restocking/i' => 'fba_return',
                '/closing|per.?item|subscription|service.?fee|fee/i' => 'marketplace_service_fee',
            ],
            'sale' => ['/^(sales?|product charges?|principal|tax|shipping|gift.?wrap|discount|promotion|goodwill|regulatory)/i'],
            'payout' => ['/transfer|disburse|payout|settlement/i'],
            'refund' => ['/refund/i'],
            'adjustment' => ['/reimburs|adjust|claim|chargeback|liquidation|retro/i'],
        ],
    ],

    'trendyol' => [
        'bases' => ['production' => 'https://apigw.trendyol.com', 'sandbox' => 'https://stageapigw.trendyol.com'],
        // The documentation says `orderDate` is "GMT+3" while every other timestamp is true UTC; the sample arithmetic shows it shifted by three hours. Verify on a stage order, then adjust if needed.
        'order_date_offset_milliseconds' => 10800000,
        // Largest date range Trendyol accepts per call (orders: 14 days, finance: 15 days).
        'orders_window_days' => 14,
        'finance_window_days' => 15,
        // Each financial transaction type is read with its own request in the international (Gulf) API.
        'finance_settlement_types' => ['Sale', 'Return'],
        'finance_other_types' => ['DeductionInvoices', 'CreditNote', 'CommissionInvoice', 'PaymentOrder'],
        // A DeductionInvoices row only has a free-text description: these patterns decide what the deduction is. A description that matches none stays unclassified for a person.
        'finance_map' => [
            'deduction' => [
                '/cargo|shipping|carrier|freight|kargo|شحن/iu' => 'marketplace_shipping',
                '/platform|service[ _-]?fee|hizmet|خدمة/iu' => 'marketplace_service_fee',
                '/\bads?\b|advert|reklam|إعلان/iu' => 'marketplace_service_fee',
            ],
        ],
        // The largest body a webhook delivery may have before it is refused (a package with many lines is a few kilobytes).
        'webhook_max_bytes' => 262144,
    ],

    'stock' => [
        // A quantity the source has not confirmed for this long is stale and is not published.
        'stale_after_minutes' => 180,
    ],

    // The class that speaks to each channel. A new channel is a connector class added here.
    'connectors' => [
        'amazon' => \App\Services\SalesChannels\Amazon\AmazonConnector::class,
        'trendyol' => \App\Services\SalesChannels\Trendyol\TrendyolConnector::class,
    ],

    'sync' => [
        // One run reads at most this many pages and then pauses with its place saved, so a large backlog never holds the lease for hours.
        'max_pages_per_run' => 25,
        // Fees and corrections reach a statement days after the sale: each finance run re-reads this far back.
        'finance_overlap_days' => 3,
        'finance_backfill_days' => 30,
        // A run re-reads this far before its watermark so that an order changed while the previous run was reading is not missed.
        'overlap_minutes' => 10,
        // A run that crashed loses its lease after this long.
        'lease_minutes' => 20,
        // After repeated failures a resource backs off for this many minutes per consecutive failure, up to the cap.
        'backoff_minutes_per_failure' => 5,
        'backoff_cap_minutes' => 120,
        // How often each resource is due, in minutes.
        'intervals' => [
            'orders' => 5,
            'shipments' => 30,
            'inventory' => 30,
            'listings' => 360,
            'finances' => 720,
        ],
        'order_backfill_days' => 30,
    ],
];
