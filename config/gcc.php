<?php

return [

    /*
    |--------------------------------------------------------------------------
    | GCC storefronts
    |--------------------------------------------------------------------------
    |
    | Which storefront a request belongs to, across the six GCC markets. Switched off by default: while `enabled` is false,
    | every request resolves to `default_country`, the Saudi storefront the platform runs today. Even when it is on, a country
    | serves a storefront only while its `active` key is true, and the default country must stay active.
    |
    */

    'storefronts' => [
        'enabled' => filter_var(env('GCC_STOREFRONTS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

        'default_country' => env('GCC_DEFAULT_COUNTRY', 'SA'),

        'countries' => [
            'SA' => ['path' => 'sa', 'currency' => 'SAR', 'active' => true],
            'AE' => ['path' => 'ae', 'currency' => 'AED', 'active' => false],
            'KW' => ['path' => 'kw', 'currency' => 'KWD', 'active' => false],
            'QA' => ['path' => 'qa', 'currency' => 'QAR', 'active' => false],
            'BH' => ['path' => 'bh', 'currency' => 'BHD', 'active' => false],
            'OM' => ['path' => 'om', 'currency' => 'OMR', 'active' => false],
        ],

        'currency' => [
            // Off by default: every storefront reads the platform's own currency settings, as before. When it is on, a storefront other than the default
            // one trades in the currency of its `countries` entry: its prices are entered and shown in that currency, nothing is converted from another
            // storefront's amounts, and the three money settings (default currency, currency model, decimals) answer for that storefront alone. A storefront
            // may sell only while its currency exists and is active in the currencies table. Read docs/gcc/GCC-05-STOREFRONT-CURRENCY.md before turning it on.
            'enabled' => filter_var(env('GCC_STOREFRONT_CURRENCY', false), FILTER_VALIDATE_BOOLEAN),
            // The currencies that do not use two decimals (ISO 4217). Every other currency uses two.
            'decimals' => ['KWD' => 3, 'BHD' => 3, 'OMR' => 3],
            // A payment gateway takes the amount of a storefront with its own currency only when that currency exists in the gateway's list of currencies
            // (App\Traits\PaymentGatewayTrait) and has two decimals. A currency with three (KWD, BHD, OMR) goes only to a gateway listed here, after
            // somebody has read what that gateway does with a third decimal. Empty until then: a payment in such a currency is refused before it starts.
            'verified_three_decimal_gateways' => [],
        ],

        'legal' => [
            // Configuration, not a statement of what the law requires. A qualified reviewer confirms the list per country before release.
            'required_documents' => ['terms', 'privacy', 'refund', 'return', 'cancellation', 'shipping'],
            // Arabic is required everywhere, and English too because the storefront presents it. An English-only text never suffices.
            'required_locales' => ['ar', 'en'],
            // A country that does not present English may drop 'en' here. Changing this needs the same review as the documents.
            'locales_by_country' => [],
            // The default storefront keeps the documents it has always had, so it never becomes unusable (GCC-19).
            'grandfathered' => ['SA'],
            // Explicit acceptance is recorded only for the document types listed here (GCC-11). Nothing is assumed to need a checkbox.
            'explicit_acceptance' => [],
            // The legal entity a storefront contracts with. Recorded on every acceptance; set per storefront before release.
            'legal_entity' => env('GCC_LEGAL_ENTITY'),
            // Whether an acceptance records who accepted it. Off by default: the record keeps the text, its version and time only.
            'store_identity' => false,
        ],
    ],

];
