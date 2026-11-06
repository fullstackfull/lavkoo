<?php

/*
|--------------------------------------------------------------------------
| Operational costs and profitability
|--------------------------------------------------------------------------
|
| Deployment defaults only. There is NO price in this file: contract prices are entered in the admin panel, versioned, and kept
| in the database. A service nobody priced is reported as missing, never as free.
*/
return [
    // The currency every report totals in. Amounts in another currency are converted with a recorded rate; with no rate they stay
    // in their own currency and the report says the total is incomplete.
    'base_currency' => env('OPERATIONAL_COSTS_BASE_CURRENCY', env('FULFILLMENT_CURRENCY', 'SAR')),

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
