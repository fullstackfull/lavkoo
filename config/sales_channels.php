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

    'stock' => [
        // A quantity the source has not confirmed for this long is stale and is not published.
        'stale_after_minutes' => 180,
    ],

    'sync' => [
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
