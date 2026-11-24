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

    /*
    |--------------------------------------------------------------------------
    | Seller and seller staff market scopes
    |--------------------------------------------------------------------------
    |
    | The markets a seller account, or one of its staff, may work in (K-09). Off by default, and it acts as off until the grant
    | table exists: nothing is read then, and every seller endpoint answers what it has always answered. A principal with no
    | grant row is unrestricted, so turning it on changes nothing until a market is granted.
    |
    | The grant lives in the table and nowhere else. A market a request names is a request, never a grant: it is checked against
    | the rows and refused when they do not hold it. The only way to widen a grant is to change the grant.
    |
    */

    'seller' => [
        'market_scopes' => filter_var(env('GCC_SELLER_MARKET_SCOPES', false), FILTER_VALIDATE_BOOLEAN),
    ],

    'storefronts' => [
        'enabled' => filter_var(env('GCC_STOREFRONTS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

        'default_country' => env('GCC_DEFAULT_COUNTRY', 'SA'),

        'countries' => [
            'SA' => ['path' => 'sa', 'currency' => 'SAR', 'active' => true, 'name' => 'Saudi Arabia'],
            'AE' => ['path' => 'ae', 'currency' => 'AED', 'active' => false, 'name' => 'United Arab Emirates'],
            'KW' => ['path' => 'kw', 'currency' => 'KWD', 'active' => false, 'name' => 'Kuwait'],
            'QA' => ['path' => 'qa', 'currency' => 'QAR', 'active' => false, 'name' => 'Qatar'],
            'BH' => ['path' => 'bh', 'currency' => 'BHD', 'active' => false, 'name' => 'Bahrain'],
            'OM' => ['path' => 'om', 'currency' => 'OMR', 'active' => false, 'name' => 'Oman'],
        ],

        'paths' => [
            // Off by default: the web routes answer at the root only, as today, and a country path answers 404. When it is on, a request under an ACTIVE market's path
            // (`/kw/shop-cart`) is served by the same route as `/shop-cart`, as that market: the segment is taken off before routing, so there is one route table and one
            // set of route names, and the paths the application generates while serving such a request are given the market back, so a link on a Kuwaiti page stays
            // Kuwaiti. The default storefront keeps its bare addresses -- `/sa/...` is served, nothing generates it -- and the panel, the API and the dev paths are never
            // given a market (App\Services\Storefront\StorefrontPathPrefix). An inactive market's path answers 404, as it does now.
            'enabled' => filter_var(env('GCC_STOREFRONT_PATHS', false), FILTER_VALIDATE_BOOLEAN),
        ],

        'queues' => [
            // Off by default: every job and every queued listener waits on the queue it waits on today, whatever market it carries. When it is on, work
            // that carries a market other than the default storefront's waits on that market's own queue (`storefront-kw` and so on), so an operator can
            // work one market (`queue:work --queue=storefront-kw`), pace it, or stop reserving from it (`queue:pause storefront-kw`) without touching the
            // rest. The default storefront keeps today's queue, so a worker that is already running never stops working the live market's jobs.
            //
            // Turning this on needs a worker for each market's queue; `php artisan gcc:storefront-queues` prints the names, what is waiting on each and
            // the command to run. Without one, that market's jobs wait instead of running -- they are not lost, and switching this off again sends the
            // next of them back to today's queue.
            'per_market' => filter_var(env('GCC_STOREFRONT_QUEUES', false), FILTER_VALIDATE_BOOLEAN),
            'prefix' => env('GCC_STOREFRONT_QUEUE_PREFIX', 'storefront-'),
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

        // The mobile number a courier is given, per market (C-15). Fulfilment holds a shipment whose phone it cannot read rather than send a
        // courier a number they cannot call, and the one rule it knew was the Saudi one -- which would hold every order of the other five
        // markets, where a mobile is eight digits with no trunk zero. The dialling code and the national length are facts that do not move;
        // WHICH prefixes inside that length are mobile does, and number portability means a prefix no longer names an operator, so a
        // `pattern` is left empty until somebody reads the market's current numbering plan and fills it in. Saudi Arabia keeps the pattern
        // that already shipped, so nothing about a Saudi shipment changes. An empty pattern accepts any number of the right length.
        'phone' => [
            'SA' => ['country_code' => '966', 'national_length' => 9, 'trunk_zero' => true, 'pattern' => '/^5\d{8}$/'],
            'AE' => ['country_code' => '971', 'national_length' => 9, 'trunk_zero' => true, 'pattern' => null],
            'KW' => ['country_code' => '965', 'national_length' => 8, 'trunk_zero' => false, 'pattern' => null],
            'QA' => ['country_code' => '974', 'national_length' => 8, 'trunk_zero' => false, 'pattern' => null],
            'BH' => ['country_code' => '973', 'national_length' => 8, 'trunk_zero' => false, 'pattern' => null],
            'OM' => ['country_code' => '968', 'national_length' => 8, 'trunk_zero' => false, 'pattern' => null],
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
        'market_accounts' => [
            // Off by default, and off means the platform of today: a customer has the one wallet balance and the one point balance the `users` row keeps
            // (`wallet_balance`, `loyalty_point`), every read answers from those two columns whatever market is asked for, and no account table is read —
            // not even to ask whether it is there. The four wallet writers and the two point writers stay the only authority on a balance, so nothing a
            // Saudi customer does is reached by this key while it is off. When it is on, and once the expand migration has run, a customer has one account
            // per market (`customer_market_wallet_accounts`, `customer_market_loyalty_accounts`): a balance is that market's own, a credit or a debit
            // touches the account of the market it names and no other, and nothing is converted between two markets. Nothing in the application asks for a
            // market balance yet, so turning it on moves no money on its own: the writers are wired market by market in a later slice (GCC-15, E-07).
            // Read docs/gcc/GCC-15-MARKET-ACCOUNTS.md before turning it on.
            'enabled' => filter_var(env('GCC_MARKET_ACCOUNTS', false), FILTER_VALIDATE_BOOLEAN),

            // The shadow half of the move (E-07), off by default and needing the key above. While it is on, every movement written to the
            // one balance a customer has is also written to their account in the market that movement names, in the same transaction, and
            // the two are compared; a difference is written to the log and nothing else happens. It is a mirror and never an authority:
            // the `users` row stays the only balance anything reads, and a mirror that cannot be made — a market nobody named, an account
            // the backfill has not opened yet, a figure the account refuses — is written down and never allowed to fail the write it was
            // following. Run wallet:backfill-market-accounts before turning this on: a mirror never opens an account, because opening one
            // inside the writer's own transaction is what deadlocks (see MarketAccountRepository::open).
            'shadow_writes' => filter_var(env('GCC_MARKET_WALLETS_SHADOW', false), FILTER_VALIDATE_BOOLEAN),
        ],

        'payment_methods' => [
            // Off by default: every storefront offers the ways to pay that the platform's payment settings offer (cash_on_delivery, digital_payment, offline_payment), as before.
            // When it is on, a country may switch any of the three off for its own storefront: the checkout, the account pages and the answer to the apps (GET /api/v1/config)
            // of that storefront read the method as switched off, and an order or a payment that asks for it there is refused before anything is written. A storefront can
            // only switch off: a method the platform has off stays off in every storefront, whatever is listed here. Read docs/gcc/STOREFRONT-PAYMENT-METHODS.md before turning it on.
            'enabled' => filter_var(env('GCC_STOREFRONT_PAYMENT_METHODS', false), FILTER_VALIDATE_BOOLEAN),
            // Country code => the methods that country has switched off, for example 'KW' => ['offline_payment']. A country that lists nothing offers what the platform offers.
            // Configuration, not a statement of what the law allows: a qualified reviewer confirms the list per country before release.
            'switched_off' => [],
        ],

        'payment_accounts' => [
            // Off by default: every gateway is read from the one platform-wide addon_settings row, exactly as today, and the Saudi configuration IS that
            // row. When it is on, a company's gateway account is resolved for the storefront a payment starts in, the payment is stamped with it, and
            // settlement, verification and refund use the stamped account and never a fresh look at the settings. The table ships empty; while a country
            // has no account the platform row answers for it, so Saudi Arabia keeps working with an empty table (GCC-19).
            'enabled' => filter_var(env('GCC_PAYMENT_ACCOUNTS', false), FILTER_VALIDATE_BOOLEAN),
            // Which gateway keys may be configured per company. A gateway not listed is always read from the platform row, whatever the table holds, and has
            // no per-company notification address either.
            //
            // Paymob only, for now, because it is the one gateway wired end to end: the account's credentials are what its payment starts with and its
            // return is verified against the account's own secret. MyFatoorah's notification address IS per account (the mechanism is the same), but its
            // outbound calls still read the one platform settings row through GatewayConfigRepository, so listing it would stamp a payment with an account
            // whose credentials nothing reads. It joins this list in the same change that makes MyFatoorah read its api_key from the account.
            'gateways' => ['paymob_accept'],
            // A customer's return from a gateway proves nothing by itself. Off by default: the return is read exactly as it is today. When it is on, a
            // return settles only after the server has confirmed the payment with the gateway itself, and a return it cannot confirm settles nothing.
            'confirm_returns' => filter_var(env('GCC_PAYMENT_CONFIRM_RETURNS', false), FILTER_VALIDATE_BOOLEAN),
            // A payment expects ONE confirmation address, and that address is the one it recorded when it started (GCC-21). Off by default: every
            // address reads a confirmation exactly as it does today. When it is on, the platform-wide address confirms a payment only where the payment
            // expects it — so a payment that recorded a company account's own address is confirmed there and not at the public one.
            //
            // A payment that recorded NO address expects the platform one, and that is not a gap in the record: it is what every payment placed before
            // this existed, every payment of a market with no account, and every payment of a gateway that is notified at no address of its own
            // positively expects. Those keep being confirmed at the platform address with this on, which is the half of the owner's decision that keeps
            // money from being stranded.
            //
            // Deliberately separate from `enabled`, and inert without it: the address a payment expects is read from the record the binding writes, so
            // this must not be turned on before that binding has been writing. With `enabled` off no new payment records an address AND the per-account
            // addresses resolve nothing, so enforcing here would leave an already-recorded payment with no live address at all.
            //
            // WHAT THIS SWITCH DOES AND DOES NOT REVERSE. It governs which address CONFIRMS a payment, and turning it off restores exactly today's
            // answer on every confirmation path -- measured, including the queries issued. It does NOT reverse the two conditions GCC-21 added to
            // App\Services\Payments\GatewayAccountDirectory::webhookUrlOf(), which answers which address an account is NAMED at: those apply whatever
            // this switch says, because an address that is named where nothing is delivered would be recorded onto a payment as the address its
            // confirmation is expected at. Under the gateway list above they move no answer at all; they move one only once this gateway joins it.
            // Reviewer B of the stage-C round found that "reversible by flipping one setting" did not hold of those two lines.
            'one_confirmation_address' => filter_var(env('GCC_PAYMENT_ONE_CONFIRMATION_ADDRESS', false), FILTER_VALIDATE_BOOLEAN),
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

        'refunds' => [
            // Off by default: a refund gives back exactly what it gives back today — the stored amount of the refund converted at the rate of the day it
            // is paid out. When it is on, a refund gives back its share of the payment the customer actually made, in the currency that payment was taken
            // in, and the merchant account that took it is what the payout is worked out from (App\Services\Storefront\RefundPayout). The rate of the
            // day is not an input while it is on.
            //
            // Which of the two a refund SHOULD give back after the rates or the settings have moved is the owner's decision and the finance review's, and
            // it has not been taken; nor has whether credit notes exist, nor whether a refund may only ever return to the original merchant account. The
            // mechanism is built and left off until it is. Read docs/gcc/ for D1-10 before turning it on.
            'from_original_payment' => filter_var(env('GCC_REFUND_FROM_ORIGINAL_PAYMENT', false), FILTER_VALIDATE_BOOLEAN),
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

    /*
    |--------------------------------------------------------------------------
    | Account deletion
    |--------------------------------------------------------------------------
    |
    | What a customer's own account deletion does about the money the store still holds for them (E-08).
    |
    */

    'account_deletion' => [
        // Off by default, and off means the platform of today: a customer deletes their account through the app or the website while their wallet still
        // holds money or their loyalty points still stand, both routes answer that the account was deleted, and the two ledgers are left behind with
        // nobody left to name them. When it is on, the customer's own two routes refuse that deletion while either holding is at or above one halala, each
        // answering in the shape it already answers its ongoing-order refusal with. Turning it on takes away something a Saudi customer can do today, so it
        // is the owner's decision; the ledger rows are kept either way, which is the promise of E-08. Before turning it on, note that a customer cannot
        // clear a small residue themselves: wallet payment at checkout is all or nothing, this platform has no customer withdrawal, points below the
        // conversion minimum cannot be converted, and the only admin wallet action adds funds. So a refusal stands until somebody can zero the holding,
        // which is why the administrator's own deletion route is left unguarded, and why anonymising rather than refusing is the question for the owner.
        'refuse_while_balance_remains' => filter_var(env('GCC_REFUSE_ACCOUNT_DELETE_WITH_BALANCE', false), FILTER_VALIDATE_BOOLEAN),
    ],

];
