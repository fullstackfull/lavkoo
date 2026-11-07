<?php

/*
|--------------------------------------------------------------------------
| Operational costs and profitability
|--------------------------------------------------------------------------
|
| Deployment defaults only. There is NO price in this file: contract prices are entered in the admin panel, versioned, and kept
| in the database. A service nobody priced is reported as missing, never as free.
|
| Everything that calculates by itself ships OFF, and running the migrations switches nothing on. Two independent switches, in the order they are turned on:
|   1. OPERATIONAL_COSTS_ENABLED          - the master switch: off means nothing posts a cost entry, a FIFO consumption or a storage charge by itself (the scheduled runs,
|      the same commands run by hand, the sale-time recognition of goods sold) and the channel finance importer keeps the statement postings it reads WITHOUT posting them; they
|      are posted once, when it is switched on. Whatever a person enters stays available: contracts, purchase invoices, opening stock, product costs, decisions.
|   2. OPERATIONAL_COSTS_SCHEDULE_ENABLED - only has an effect while the master switch is on: off means the scheduler does not run `costs:refresh` and `costs:accrue-storage`
|      (they still run by hand). `channels:prune` is channel housekeeping, posts nothing, and does not depend on either.
|
| Both values are read with FILTER_VALIDATE_BOOLEAN, not a (bool) cast: the cast turns "off", "no" and "disabled" into TRUE. With a cached configuration (bootstrap/cache/config.php)
| a change in .env does nothing until `php artisan config:clear`.
*/
return [
    'enabled' => filter_var(env('OPERATIONAL_COSTS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'schedule_enabled' => filter_var(env('OPERATIONAL_COSTS_SCHEDULE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // The currency every report totals in. Amounts in another currency are converted with a recorded rate; with no rate they stay
    // in their own currency and the report says the total is incomplete.
    'base_currency' => env('OPERATIONAL_COSTS_BASE_CURRENCY', env('FULFILLMENT_CURRENCY', 'SAR')),

    // What the module takes from the platform's own tables instead of asking a person to type it a second time. The platform's tables stay the truth and are only ever read.
    // Every value ships `off`, and only the exact words below count (`true`, `1`, `yes` and a typo all mean off).
    'sources' => [
        // The cost of goods received on a purchase order. `off`: nothing. `shadow`: the commands only REPORT what would be done and write nothing anywhere. `on`: the layers can be
        // made by `costs:layers-from-purchase-orders --apply`, a purchase invoice line linked to an order line makes no second layer, and the latest order cost is an estimate of
        // what a unit costs. It also needs OPERATIONAL_COSTS_ENABLED. Nothing makes the layers by itself: the command is run by hand.
        'purchase_orders' => env('OPERATIONAL_COSTS_SOURCE_PURCHASE_ORDERS', 'off'),
    ],

    // Who writes cost entries for a sale, in order. Each one is idempotent and records a gap as MISSING.
    'contributors' => [
        \App\Services\OperationalCosts\Contributors\ProductCostContributor::class,
        \App\Services\OperationalCosts\Contributors\ShipmentCostContributor::class,
        \App\Services\OperationalCosts\Contributors\PaymentFeeContributor::class,
        \App\Services\OperationalCosts\Contributors\MarketplaceFeeContributor::class,
    ],

    // When a sale's goods cost is recognised (taken from the cost layers). Reversed if the order is cancelled before it ships.
    'cogs_recognition' => [
        'storefront' => 'delivered',
        'amazon' => 'shipped',
        'trendyol' => 'shipped',
    ],

    // How a shared charge (one fee for a shipment of several items) is split across the items when the contract does not say.
    'allocation' => [
        'default_basis' => 'value',
        'by_component' => [
            'shipping' => 'weight',
            'extra_weight' => 'weight',
            'picking' => 'quantity',
            'receiving' => 'quantity',
        ],
    ],

    'storage' => [
        // Storage is allocated to products one row per product per accrual; beyond this many products the charge is kept unallocated instead.
        'max_allocation_rows' => 5000,
    ],

    // An invoice line that differs from the estimate by no more than this percentage is considered matched.
    'reconciliation' => [
        'tolerance_percent' => 2,
    ],

    // Decimal places shown in reports. Storage is always six.
    'display_decimals' => 2,
];
