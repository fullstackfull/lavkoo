<?php

return [

    /*
    |--------------------------------------------------------------------------
    | GCC storefronts
    |--------------------------------------------------------------------------
    |
    | Which storefront a request belongs to, across the six GCC markets. Switched off by default: while `enabled` is false,
    | every request resolves to `default_country`, the Saudi storefront the platform runs today.
    |
    */

    'storefronts' => [
        'enabled' => filter_var(env('GCC_STOREFRONTS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

        'default_country' => env('GCC_DEFAULT_COUNTRY', 'SA'),

        'countries' => [
            'SA' => ['path' => 'sa', 'currency' => 'SAR'],
            'AE' => ['path' => 'ae', 'currency' => 'AED'],
            'KW' => ['path' => 'kw', 'currency' => 'KWD'],
            'QA' => ['path' => 'qa', 'currency' => 'QAR'],
            'BH' => ['path' => 'bh', 'currency' => 'BHD'],
            'OM' => ['path' => 'om', 'currency' => 'OMR'],
        ],
    ],

];
