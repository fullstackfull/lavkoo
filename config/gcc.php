<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Staff country scopes (GCC-14c)
    |--------------------------------------------------------------------------
    |
    | Off by default: every employee sees and changes what their role allows, in every country, exactly as before. When it is on, and only once
    | the `admin_country_scopes` table and the `products.storefront_country` column exist, an employee who has been given countries with
    | `gcc:staff-country:assign` works on the products of those countries only, in the product screens of the admin panel, and on the orders and
    | refund requests of those countries only, in the order, POS and refund screens of the admin panel. An employee with no country given stays unrestricted, and the
    | master admin is never restricted. Read docs/gcc/GCC-14C-STAFF-COUNTRY-SCOPES.md before turning it on.
    |
    */

    'staff' => [
        'country_scopes' => filter_var(env('GCC_STAFF_COUNTRY_SCOPES', false), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    |--------------------------------------------------------------------------
    | Seller settlements
    |--------------------------------------------------------------------------
    |
    | How a settlement is drawn from a seller's ledger (E-17).
    |
    */

    'settlements' => [
        // Off by default, and off means the platform of today: a settlement adds every entry of a seller that is waiting, whatever currency each one names, takes the label
        // of its first entry, and the screens print every amount as the platform's money. That is right only while every amount on the seller ledger is in the store's base
        // currency, which is what every writer of the ledger writes today (VendorLedger::record). It is also why the label of an entry is not trusted to split money while this
        // is off: the ledger once stamped the visitor's display currency on entries whose amount was in the base currency (VendorLedger::baseCurrency), so an installation may
        // hold entries whose label is not the currency of their amount. When it is on, the label is the currency: a settlement is drawn in one currency, entries of another are
        // left for a settlement of their own, an entry that names none is read as the base currency, and the screens print each settlement in its own currency
        // (App\Services\Marketplace\SettlementEngine). It has to be on before any market writes ledger entries in a currency of its own. Before turning it on: check the labels
        // of the entries still waiting against the above, and make sure `opening_balance` and `closing_balance` of `vendor_settlements` accept NULL, because a settlement of a
        // ledger that mixes currencies records neither and the engine refuses to draw on a table that cannot say so. That table has no migration in this repository, so neither
        // can be checked from the code; `php artisan marketplace:settle --dry-run` reports the second.
        'one_currency' => filter_var(env('GCC_SETTLEMENT_ONE_CURRENCY', false), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    |--------------------------------------------------------------------------
    | Country-scoped SKU and barcode (GCC-14b)
    |--------------------------------------------------------------------------
    |
    | Off by default: a product code and a barcode stay unique across the whole table, exactly as before. When it is on, and only once the products
    | table has its `storefront_country` column, the same code or barcode may exist in two countries and never twice in one. The gates, the lookups
    | and the importers read the answer from App\Services\Catalog\ProductCodeScope. Read docs/gcc/GCC-14B-COUNTRY-SCOPED-CODES.md and run
    | `php artisan gcc:catalog:code-readiness` before turning it on.
    |
    */

    'catalog' => [
        'country_scoped_codes' => filter_var(env('GCC_COUNTRY_SCOPED_CODES', false), FILTER_VALIDATE_BOOLEAN),
    ],

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

        'themes' => [
            // Off by default: one theme is active for the whole platform, as before, and activating a theme deactivates every other. When it is on, a theme belongs to one
            // storefront (`themes.storefront_country`, NULL for the default storefront's, which is every theme that exists today), activating it deactivates only the active
            // theme of that same storefront, and a request is served the active theme of its storefront, or the default storefront's when its own has none. It does nothing
            // until the themes migration has run, and it needs no other switch: while storefronts are off the only storefront a request can reach is the default one.
            // Read docs/gcc/F-01-STOREFRONT-THEME.md before turning it on.
            'enabled' => filter_var(env('GCC_STOREFRONT_THEMES', false), FILTER_VALIDATE_BOOLEAN),
        ],

        'legal' => [
            // Configuration, not a statement of what the law requires. A qualified reviewer confirms the list per country before release.
            'required_documents' => ['terms', 'privacy', 'refund', 'return', 'cancellation', 'shipping'],
            // Arabic is required everywhere, and English too because the storefront presents it. An English-only text never suffices.
            'required_locales' => ['ar', 'en'],
            // A country that does not present English may drop 'en' here. Changing this needs the same review as the documents.
            'locales_by_country' => [],
            // The business page (its slug) that stands for each document type. In a storefront other than the default one such a page shows that
            // storefront's own current text in the visitor's locale, or nothing; a page that is not listed here (about-us, the pages an admin adds)
            // stays the same in every storefront. The default storefront keeps serving its pages as they are.
            'page_slugs' => [
                'terms' => 'terms-and-conditions',
                'privacy' => 'privacy-policy',
                'refund' => 'refund-policy',
                'return' => 'return-policy',
                'cancellation' => 'cancellation-policy',
                'shipping' => 'shipping-policy',
            ],
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

        // The second factor of the sign-in (a TOTP code or a recovery code). `required` ships ON and fails closed: only false, 0, off or no switches it off
        // (App\Services\PlatformAdmin\PlatformAdminSettings::mfaRequired()); a typo or a blank value leaves it on. Off is for staging only. The numbers: a value below 1 is
        // the default, and each has a ceiling (the challenge 15 minutes and 10 tries, the lockout 20 failures and 60 minutes).
        'mfa' => [
            'required' => env('GCC_PLATFORM_ADMIN_MFA_REQUIRED', true),
            'challenge_ttl_minutes' => (int) env('GCC_PLATFORM_ADMIN_MFA_CHALLENGE_TTL_MINUTES', 5),
            'max_challenge_attempts' => (int) env('GCC_PLATFORM_ADMIN_MFA_MAX_CHALLENGE_ATTEMPTS', 5),
            'lockout_failures' => (int) env('GCC_PLATFORM_ADMIN_MFA_LOCKOUT_FAILURES', 10),
            'lockout_minutes' => (int) env('GCC_PLATFORM_ADMIN_MFA_LOCKOUT_MINUTES', 15),
        ],

        // A session unused for this long is over (at most 1440), and an admin has at most this many open at once (at most 20); the oldest is ended to make room.
        'session' => [
            'idle_minutes' => (int) env('GCC_PLATFORM_ADMIN_SESSION_IDLE_MINUTES', 60),
            'max_concurrent' => (int) env('GCC_PLATFORM_ADMIN_SESSION_MAX_CONCURRENT', 5),
        ],

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

    /*
    |--------------------------------------------------------------------------
    | Seller ledgers
    |--------------------------------------------------------------------------
    |
    | What a running balance on the seller ledger belongs to (E-17).
    |
    */

    'ledgers' => [
        // Off by default, and off means the platform of today: one chain per seller, so one `balance_after` running over everything the seller earned, whatever company sold
        // it and whatever currency it was in. That is right while a seller sells in one market, which is every seller on the live Saudi platform. When it is on, a chain is
        // named by the seller, the selling company and the currency: an entry reads the balance of its own chain, the row lock is taken on its own chain, and a reader can
        // ask for one chain's position instead of the sum of all of them (App\Services\Marketplace\LedgerChain). It has to be on before a seller earns in a second company or
        // a second currency, because until it is, those amounts are added together. Turning it on changes no figure on a ledger that holds one company and one currency, which
        // is what makes it safe to throw on the live Saudi ledger. Entries that name no currency belong to the base-currency chain, so years of unlabelled history stay in one
        // piece. The selling company is part of the key only where `vendor_ledger_entries` has a `legal_entity_id` column; that table has no migration in this repository (the
        // live one came from the installation dump), so an installation without the column keys its chains by seller and currency alone — and no writer in the application
        // passes a company yet, so on a live ledger the key is the seller and the currency whatever the column holds.
        //
        // IT IS NOT YET SAFE TO THROW ON A LEDGER THAT ALREADY HOLDS TWO CURRENCIES. A payout is still drawn on the seller's whole withdrawable amount and its reserve names
        // no currency, so on a two-currency ledger the reserve lands in the base-currency chain and takes that chain's running balance negative by the amount paid out of the
        // other, which still advertises money that has gone. A payout drawn per chain, or one that refuses while a seller holds more than one, is the owner's decision and the
        // gate on throwing this where a second currency is already on the ledger (docs/gcc: E-17.2).
        //
        // AND THE REVERSALS ALREADY ON THE LEDGER HAVE TO BE CHECKED BEFORE IT IS THROWN, which needs only the switch and not two currencies: a refund and the commission
        // reversed with it were labelled with the currency of the ORDER's commission snapshot, which is stamped when the order is placed and falls back to the currency the
        // customer was browsing in (OrderManager::recordOrderItemCommission), while the earning they reverse is labelled with the store's base currency. Where the two
        // disagree, the switch puts the reversal in a chain the earning was never in: the earning settles as payable while the refund waits in a settlement nobody can be
        // paid, so a line refunded IN FULL leaves the marketplace owing for a sale it gave back. A reversal written from now on takes the label of the entry it reverses
        // (RefundReversalService), so this is about the rows that are already there: the `refund` and `return_adjustment` entries of each `refund_order_details` reference
        // have to carry the currency of the `order_details` earning of the same line, and be relabelled where they do not.
        'keyed_by_entity_and_currency' => filter_var(env('GCC_LEDGER_KEYED_BY_ENTITY_AND_CURRENCY', false), FILTER_VALIDATE_BOOLEAN),
    ],

];
