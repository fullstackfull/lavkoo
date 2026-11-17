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

    /*
    |--------------------------------------------------------------------------
    | Platform admin API (GCC-20, level 1: monitor)
    |--------------------------------------------------------------------------
    |
    | Read-only monitoring of every market for the platform owner, signed in with the admin identity and a token of its own (docs/gcc/GCC-20-PLATFORM-ADMIN-API.md).
    | Switched off by default: while `enabled` is false every route of the API answers 404 like a route that does not exist, and nothing is read or written.
    | Switched on before its migrations have run, it answers 503. Who may use it is decided by `gcc:platform-admin:grant` on the server, never by a request.
    |
    */

    'platform_admin' => [
        'enabled' => filter_var(env('GCC_PLATFORM_ADMIN_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

        // How long a token lives. A value below 1 means the default of 480; whatever is configured, 1440 (one day) is the most a token can live.
        'token_ttl_minutes' => (int) env('GCC_PLATFORM_ADMIN_TOKEN_TTL_MINUTES', 480),

        // Sign-in attempts per minute from one address with one e-mail. One address as a whole may make three times as many. Successful attempts count too.
        'login_attempts_per_minute' => (int) env('GCC_PLATFORM_ADMIN_LOGIN_ATTEMPTS_PER_MINUTE', 5),

        // Requests per minute from one address to any route of the API, counted before a token is looked up.
        'requests_per_minute' => (int) env('GCC_PLATFORM_ADMIN_REQUESTS_PER_MINUTE', 120),

        // Failed sign-ins of an admin who holds a grant are audited, at most this many rows per grant per hour. The rest only reach the log.
        'failed_login_audit_per_hour' => 10,

        // The window of an overview: its default length, and the longest span any query may read. Dates are calendar dates in the application time zone.
        'window_days_default' => 30,
        'window_days_max' => 92,

        // When a market raises an alert. A count at or above the number is enough; the critical number replaces the warning of the same alert.
        'alerts' => [
            'zero_stock_warning_count' => 10,
            'zero_stock_critical_count' => 50,
            // An order is stuck when it is still pending after this many hours. One order is information; the other numbers raise the severity.
            'pending_age_hours' => 24,
            'pending_warning_count' => 5,
            'pending_critical_count' => 20,
        ],
    ],

];
