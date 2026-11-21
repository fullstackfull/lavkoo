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

        'legal_entities' => [
            // Off by default: no order records who sold it, as before. When it is on, each order of a storefront is stamped with the id of that country's
            // company in the legal_entities table and a frozen copy of its identity. The table ships empty: the companies are facts the owner and counsel
            // supply (docs/gcc/GCC-13-LEGAL-ENTITIES.md). It does nothing while GCC_STOREFRONTS_ENABLED is off.
            'enabled' => filter_var(env('GCC_LEGAL_ENTITIES', false), FILTER_VALIDATE_BOOLEAN),
            // When on (and the registry is on), a storefront without an active company may not sell. Off by default, so a missing company never stops a sale
            // until the owner decides that it should.
            'required_to_sell' => filter_var(env('GCC_LEGAL_ENTITIES_REQUIRED', false), FILTER_VALIDATE_BOOLEAN),
        ],

        'payment_accounts' => [
            // Off by default: every gateway is read from the one platform-wide addon_settings row, exactly as today, and the Saudi configuration IS that
            // row. When it is on, a company's gateway account is resolved for the storefront a payment starts in, the payment is stamped with it, and
            // settlement, verification and refund use the stamped account and never a fresh look at the settings. The table ships empty; while a country
            // has no account the platform row answers for it, so Saudi Arabia keeps working with an empty table (GCC-19).
            'enabled' => filter_var(env('GCC_PAYMENT_ACCOUNTS', false), FILTER_VALIDATE_BOOLEAN),
            // Which gateway keys may be configured per company. Only the gateways somebody has read the multi-account documentation for belong here;
            // a gateway not listed is always read from the platform row, whatever the table holds.
            'gateways' => ['paymob_accept', 'fatoorah'],
            // A customer's return from a gateway proves nothing by itself. Off by default: the return is read exactly as it is today. When it is on, a
            // return settles only after the server has confirmed the payment with the gateway itself, and a return it cannot confirm settles nothing.
            'confirm_returns' => filter_var(env('GCC_PAYMENT_CONFIRM_RETURNS', false), FILTER_VALIDATE_BOOLEAN),
            // Which class asks each gateway, server to server, what became of a transaction. A gateway with no entry cannot be asked, so a return of
            // it is never confirmed and, while `confirm_returns` is on, never settles.
            'inquiries' => [
                'paymob_accept' => App\Services\Payments\PaymobTransactionInquiry::class,
            ],
            'inquiry' => [
                'connect_timeout' => (int) env('GCC_PAYMENT_INQUIRY_CONNECT_TIMEOUT', 10),
                'timeout' => (int) env('GCC_PAYMENT_INQUIRY_TIMEOUT', 30),
                // Paymob's regional host the inquiry is sent to, which must be one of PaymobTransactionInquiry::HOSTS; anything else is not called at all.
                'paymob_host' => env('GCC_PAYMOB_HOST', 'https://ksa.paymob.com'),
            ],
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
