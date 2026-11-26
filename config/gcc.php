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

        /*
        | Asking an employee to prove who they are again before an action that changes who may do what (G-14).
        |
        | Off by default: the panel's sensitive actions are carried out on the strength of the session, exactly as they are today. With it
        | on, a request that adds, rewrites or switches off an employee, that adds, rewrites, switches off or deletes a role, or that empties
        | a table from the database settings screen, is refused unless the employee has typed their OWN password within the last
        | `step_up.minutes`. The password is sent with the action itself in `step_up_password`; proving it stamps the session, so a run of
        | actions inside the window asks once. Nothing else about the panel changes, and no screen asks for the field while the switch is off.
        |
        | `minutes` is how long one proof lasts. Ten is the figure the card names. A value below one is read as one: a window of zero would
        | ask for the password on every request, including the second half of a two-request action, which is not a security property but a
        | way of making the screen unusable.
        */
        'step_up' => [
            'enabled' => filter_var(env('GCC_ADMIN_STEP_UP', false), FILTER_VALIDATE_BOOLEAN),
            'minutes' => (int) env('GCC_ADMIN_STEP_UP_MINUTES', 10),
            'attempts_per_minute' => (int) env('GCC_ADMIN_STEP_UP_ATTEMPTS_PER_MINUTE', 5),
        ],
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

        // `timezone` is the market's own clock, for the times an operator of that market TYPES (F-08). `config/app.php` sets one zone for
        // the whole platform (`Asia/Dhaka`, which is the upstream vendor's), so "publish at nine" entered on the Emirati screen meant nine
        // o'clock in Dhaka -- six in the morning in Dubai. The stored moment stays absolute and in the platform's zone, as every other
        // timestamp of this database is; what the market's zone decides is how the operator's wall clock is read and written back to them.
        // The six are the GCC's, none of which observes daylight saving, so no market's schedule moves twice a year.
        'countries' => [
            'SA' => ['path' => 'sa', 'currency' => 'SAR', 'active' => true, 'name' => 'Saudi Arabia', 'timezone' => 'Asia/Riyadh'],
            'AE' => ['path' => 'ae', 'currency' => 'AED', 'active' => false, 'name' => 'United Arab Emirates', 'timezone' => 'Asia/Dubai'],
            'KW' => ['path' => 'kw', 'currency' => 'KWD', 'active' => false, 'name' => 'Kuwait', 'timezone' => 'Asia/Kuwait'],
            'QA' => ['path' => 'qa', 'currency' => 'QAR', 'active' => false, 'name' => 'Qatar', 'timezone' => 'Asia/Qatar'],
            'BH' => ['path' => 'bh', 'currency' => 'BHD', 'active' => false, 'name' => 'Bahrain', 'timezone' => 'Asia/Bahrain'],
            'OM' => ['path' => 'om', 'currency' => 'OMR', 'active' => false, 'name' => 'Oman', 'timezone' => 'Asia/Muscat'],
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

            // Off by default: a queued job runs in the market of the REQUEST that dispatched it, and a job dispatched where no market is
            // bound -- a provider webhook, the recovery sweep, the scheduler, the console -- runs unbound, exactly as every job runs today.
            // When it is on, a job whose payload names a RECORD (an order, a shipment, a stored provider event, a product) runs in the market
            // that record belongs to, and falls back to the dispatching request's market when the record cannot be read.
            //
            // It is a switch of its own rather than part of GCC_STOREFRONTS_ENABLED because it changes WHICH market a job is run in, not
            // whether markets exist: a provider webhook is deliberately served across every country (StorefrontCatalog's cross-country
            // paths), so the market of its work can only come from the record, and reading the record costs a query a job did not make
            // before. Turning it on needs the orders snapshot migration to have run; until it has, every job falls back as before.
            'market_from_record' => filter_var(env('GCC_STOREFRONT_QUEUE_MARKET_FROM_RECORD', false), FILTER_VALIDATE_BOOLEAN),
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

            // What copying one market's theme into another does with the banner rows its sections point at (F-11). `banners` is shared
            // by every storefront but ASSIGNED per market, so a copied block pointing at the source market's row renders nothing in the
            // target market. `duplicate` (the default) gives the copy a row of its own, so editing the Emirati banner leaves the Saudi
            // one alone; `share` assigns the ONE row to both markets, so a single edit reaches both. This is the open owner decision
            // Q-19, and it is a setting so answering it is not a code change.
            //
            // There is deliberately NO setting for the assets: `theme_assets.theme_id` is a single column, so sharing cannot be
            // expressed without a schema change, and a shared FILE cannot be offered because deleting an asset deletes its file --
            // the copy's logo would take the source market's logo with it. An asset is copied bytes and all, or reported as not copied.
            'copy' => [
                'banners' => env('GCC_STOREFRONT_THEME_COPY_BANNERS', 'duplicate'),
            ],
        ],
        // Where a customer LANDS after the gateway (D2-14). A payment is already finished in the storefront it started in; the page the
        // customer is then sent to is `payment-success` or `payment-fail`, which are unprefixed routes, so a Kuwaiti shopper who has just
        // paid in dinars is answered the default market's page -- its money, its legal texts, its catalogue.
        //
        //   'unchanged'  the default, and today's URL exactly
        //   'query'      appends `storefront=<code>`, which every route of the website already resolves
        //
        // THE PATH-PREFIX FORM IS DELIBERATELY NOT BUILT. Emitting `/kw/payment-success` means deciding the public URL shape of every
        // return page on every market, which is D2-14's open owner decision. An unrecognised value reads as `unchanged`, so a value typed
        // in hope changes nothing. The storefront is read from the payment's own record and never from the gateway's request.
        'payment_return' => [
            'url_form' => env('GCC_PAYMENT_RETURN_URL_FORM', App\Services\Payments\PaymentReturnsToItsStorefront::UNCHANGED),
        ],

        // What a storefront must have before it may sell, beyond its legal texts, its currency and its selling company (D2-05, D1-16).
        //
        // `needs_a_payment_account` adds one prerequisite to `StorefrontPreflight`: a market that takes ONLINE payments must have a gateway
        // that could actually take one -- switched on, its credentials in the column its own mode reads, able to take the market's own
        // currency, and with the provider's support for that market CONFIRMED (gcc.payments.capabilities). A market selling
        // cash-on-delivery only needs no gateway and is ready without one.
        //
        // OFF BY DEFAULT, and it has to be: what "ready to sell" includes beyond the legal texts is the owner's open decision on D1-16, and
        // the provider confirmations the check reads are the owner's open Q-33. A market must not be refused for the absence of a record
        // nobody has been asked for yet. With it off the gate answers exactly what it answers today, and the check costs one config read.
        'sale_gate' => [
            'needs_a_payment_account' => filter_var(env('GCC_SALE_GATE_NEEDS_A_PAYMENT_ACCOUNT', false), FILTER_VALIDATE_BOOLEAN),

            // Whether a TILL asks the sale gate before it opens a new sale (D1-16; `App\Services\Checkout\PointOfSaleSaleRule`).
            //
            // The owner's decision I2-09 is that it should: a new point-of-sale sale follows the country of its products and respects the
            // sale gate and the market's state, while orders already placed keep being processed. The rule is built and wired into all
            // three tills, and it ships OFF for a reason that was MEASURED rather than assumed. A market whose lifecycle record is absent
            // is a DRAFT, not an active market, so turning this on refuses a till sale in any market nobody has made active -- and at
            // least six existing test worlds sell at a till in exactly such a market (PosOrderRecordsItsMarketTest,
            // StorefrontOrderWritersTest, OrderCurrencyOnEverySurfaceTest, OrderMoneyRowCurrencyTest, StorefrontStopContinuityTest,
            // PushMarketTest). Those worlds are legitimately incomplete -- a CUSTOMER could not check out in them either -- so completing
            // them is the work that has to come first, across lanes this track does not own.
            //
            // With it off the three tills behave exactly as they do today, which the first test of the rule asserts.
            'at_the_till' => filter_var(env('GCC_SALE_GATE_AT_THE_TILL', false), FILTER_VALIDATE_BOOLEAN),
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

            // The third stage (E-01, GCC-15 slice 3), and the first one that changes an answer: the markets whose OWN ACCOUNT is the
            // balance they read. Empty by default, which is the platform of today -- every market reads the one `users` column, whatever
            // the two switches above are set to. A market listed here answers its account's balance where a customer is shown their
            // wallet or their points, and a debit of that market is judged against it, so a customer with money in Riyadh and none in
            // Kuwait City cannot pay with a Kuwaiti wallet. Nothing is listed until the backfill has been rehearsed on a disposable copy
            // of the real database and the owner has approved it (charter section 4): a market whose account was never filled would read
            // as a customer with nothing. The writes are unchanged -- every movement still goes to the `users` column and is mirrored
            // into its market's account, and the two legitimately part company once a second market holds money, which is what the
            // mirror's divergence line is for. Country codes, for example ['KW'].
            'answers_for' => [],
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
            // Whether a market's gateway account stops answering for that market's sales when the account belongs to ANOTHER legal
            // company (D1-03). Off by default, and the default is the whole point: the account the registry holds for a market takes the
            // payment whichever company it is filed under, which is what every payment on this platform does today.
            //
            // It is off rather than on because the repository answers the question twice, in opposite directions, and neither answer is
            // the owner's. `App\Services\Payments\PaymentBelongsToOrder` records in as many words that a market's gateway account MAY
            // belong to another country's company, and refuses to compare the two at refund time; criterion M2-06 says the selling entity
            // is what picks the account. Until the owner settles it (Q-D1-1) the switch ships off and nothing changes, because the
            // alternative is a settlement rule nobody approved deciding where a market's money lands.
            //
            // `filter_var` rather than a bare `env()`, like every switch in this section: `env('X', false)` hands back the STRING "false"
            // when somebody writes `GCC_...=false` in an environment file, and a non-empty string is truthy, so a bare read would turn the
            // switch ON for an operator trying to turn it off.
            'account_belongs_to_the_selling_company' => filter_var(env('GCC_PAYMENT_ACCOUNT_IS_THE_SELLING_COMPANYS', false), FILTER_VALIDATE_BOOLEAN),
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
        // The language a market writes in to a recipient who has chosen none (A1-17). EMPTY as it ships, so every market answers nothing and a
        // recipient with no language of their own is written to exactly as today -- which is what a single-market shop wants and what six
        // markets do not: a Kuwaiti customer who has chosen no language should not be written to in the language of whichever order a queue
        // worker handled before theirs. A market is listed here once somebody has decided what it writes in, e.g. 'KW' => 'ar'. A locale the
        // platform has not installed is ignored rather than bound, because translate() would write language files for it.
        'language' => [
            'by_country' => [],
            // The languages each market PRESENTS, in the order an operator listed them (I1-16). EMPTY as it ships, so every market presents
            // whatever the platform's own language list presents -- which is the platform of today and the only answer a single-market shop
            // can give. A market is listed here once somebody has decided which languages it offers, e.g. 'KW' => ['ar', 'en'].
            //
            // What a list buys is not a longer menu: it is the rule that a market never writes to a customer in a language its own storefront
            // does not offer. A customer whose stored language is one the market does not present is written to in that MARKET's language
            // (`by_country` above, else the first of this list) rather than in one its pages are not translated into.
            //
            // A locale the platform has not installed is dropped rather than offered, for the same reason a default is: translate() would
            // write language files for a locale nobody can read. The DIRECTION is not here -- it is declared per locale by the platform's own
            // language list, which is where the website already reads it, and deriving it from the language code is what that test forbids.
            'lists_by_country' => [],
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

        // The oldest contract level of the shell this server will answer (App\Services\PlatformAdmin\PlatformAdminContract). It ships UNSET, so nothing is
        // forced and every build is answered, one from before the header included. Set it to 1 and a shell that does not send
        // `X-Platform-Admin-Contract: 1` is refused with 426 and told both numbers, the sign-in included -- a build that cannot read the answers must
        // not be given a session to read them with. It can NEVER be set above the level the server itself speaks: a minimum of 2 against a server of 1
        // would refuse every request including the sign-in, leaving no session to read the floor from, which is how the seller version gate once locked
        // every seller out. A value that is not a positive whole number means no floor at all. This is NOT the seller app's version gate and does not
        // touch it: the shell can stop being able to read this API without the seller app needing a release.
        // Read raw rather than cast here, as the second-factor switch is: `PlatformAdminContract::minSupported()` reads it strictly, so that `1.5` or
        // `1x` mean NO floor instead of the 1 an `(int)` cast would quietly make of them. For a floor, the unsafe direction of a nonsense value is on.
        'min_contract' => env('GCC_PLATFORM_ADMIN_MIN_CONTRACT', 0),

        // The window of an overview: its default length, and the longest span any query may read. Dates are calendar dates in the application time zone.
        'window_days_default' => 30,
        'window_days_max' => 92,

        // How long a proof of the second factor stays fresh for an action that asks for one again (L-15), and how many exports one admin may ask for in
        // an hour. Each has a ceiling the configuration cannot pass: 15 minutes and 20 exports.
        'step_up_minutes' => (int) env('GCC_PLATFORM_ADMIN_STEP_UP_MINUTES', 5),
        'exports_per_hour' => (int) env('GCC_PLATFORM_ADMIN_EXPORTS_PER_HOUR', 3),

        'reports' => [
            // Turning six currencies into one figure. It ships EMPTY, so no combined figure is ever produced and every answer says why
            // (App\Services\PlatformAdmin\PlatformSalesCombination). Writing rates here is a finance decision and not a code change: name the
            // currency to convert into, a rate for every currency a window can hold, where the rates came from and the day they are from, and the
            // combined figure is then sent WITH that rate, source and date so a reader can check it. Leave any of the four out and there is no
            // combined figure at all -- an unlabelled total of six currencies looks like money and is not.
            'conversion' => [
                'currency' => null,
                'source' => null,
                'as_of' => null,
                'rates' => [],
            ],
        ],

        // When a market raises an alert. A count at or above the number is enough; the critical number replaces the warning of the same alert.
        'alerts' => [
            'zero_stock_warning_count' => 10,
            'zero_stock_critical_count' => 50,
            // Stock BELOW zero is not an ordinary state of a shop -- it is a sale that should have been refused -- so one product is already
            // information and these two numbers are far lower than the zero-stock pair above. A value below 1 means the default.
            'oversold_warning_count' => 5,
            'oversold_critical_count' => 20,
            // An order is stuck when it is still pending after this many hours. One order is information; the other numbers raise the severity.
            'pending_age_hours' => 24,
            'pending_warning_count' => 5,
            'pending_critical_count' => 20,
            // The operational health signals of L-09, one pair of counts each. One row is information; these raise it. The owner sets the
            // real numbers -- what counts as a warning differs by signal, and a single unreconciled cost may deserve more than one stuck
            // payment attempt does -- and a ruling changes a line here rather than any code.
            'health' => [
                'payments_stuck_warning_count' => 5,
                'payments_stuck_critical_count' => 20,
                'payments_unreconciled_warning_count' => 5,
                'payments_unreconciled_critical_count' => 20,
                'channels_failing_warning_count' => 5,
                'channels_failing_critical_count' => 20,
                'carriers_failing_warning_count' => 5,
                'carriers_failing_critical_count' => 20,
                'books_need_a_person_warning_count' => 5,
                'books_need_a_person_critical_count' => 20,
                'shop_issues_open_warning_count' => 5,
                'shop_issues_open_critical_count' => 20,
                'approvals_pending_warning_count' => 5,
                'approvals_pending_critical_count' => 20,
            ],
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

    'shipping' => [
        // H1-06: the one engine that decides whether a basket may be shipped to a destination and whether that crosses a border. OFF as it
        // ships, and with it off the engine answers "local, allowed" for everything, so every checkout surface behaves exactly as today --
        // which is right for a platform serving one country: it has no cross-border question to answer. Turning it on is what says a second
        // country is open, and it only ever REFUSES: a product with no permission row (H1-03) and a route no carrier declares (H1-02) are
        // both read as no, so turning it on can never open a shipment that was closed.
        'cross_border' => [
            'enabled' => filter_var(env('GCC_CROSS_BORDER_SHIPPING', false), FILTER_VALIDATE_BOOLEAN),

            // H1-01: WHICH source market may sell into which destination. The engine above says whether the platform asks the question at
            // all; this says which answers are yes, and it ships EMPTY -- so with the engine on, every storefront sells inside its own
            // country and nothing else, which is the requirement's own default. One entry per SOURCE market, listing the destinations it
            // may sell into; `*` means every destination this platform serves, and is a deliberate decision rather than a shortcut:
            //
            //   'SA' => ['KW', 'BH'],
            //
            // A source market selling into its own country is always open and needs no entry: that is not a cross-border sale. The list is
            // what the owner writes down before a release; a pair opened or closed by a person afterwards is a row in
            // `storefront_destination_pairs`, which wins over this list for that pair alone and records who changed it.
            'pairs' => [],

            // The kill switch, and it fails CLOSED rather than open. True refuses every cross-border destination whatever the list and
            // whatever anybody has opened, so cross-border selling can be stopped in one environment variable without editing six lists
            // and without turning the whole engine off -- which would stop the REFUSALS too, since the engine answers "local, allowed" when
            // it is off. It is the switch to reach for when a border closes overnight.
            'closed' => filter_var(env('GCC_CROSS_BORDER_CLOSED', false), FILTER_VALIDATE_BOOLEAN),
        ],

        // H1-04: regulated health and pharmaceutical goods, which do not cross a border because a courier flies there. OFF as it ships,
        // and `off` is a position rather than an empty list: a platform selling inside one country holds no export licence and needs none.
        // In `strict` mode App\Services\Shipping\RegulatedShippingGate refuses a FOREIGN destination for a product nobody classified --
        // no category means refused -- and, for a product these lists name, asks for a recorded clearance: a reference on the product's
        // destination permission row (H1-03) plus a person whose role carries `clearance_module`. A carrier covering the route changes
        // none of it. Which classes may cross which border, and who may approve the list, is the owner's Q-13; the lists ship empty, so
        // turning the mode on without them refuses only what nobody has classified.
        'regulated' => [
            'mode' => env('GCC_REGULATED_SHIPPING_MODE', App\Services\Shipping\RegulatedShippingGate::MODE_OFF),

            // The admin module a person must hold before a clearance they recorded counts. Role 1 always may.
            'clearance_module' => env('GCC_REGULATED_SHIPPING_CLEARANCE_MODULE', 'catalog'),

            // The owner's lists. A product is regulated when one of them names it: its category, a word in its name, or its brand.
            'category_ids' => [],
            'name_patterns' => [],
            'brand_ids' => [],

            // Per destination, merged with the lists above so a global rule is never lost by naming a country:
            //   'AE' => ['mode' => 'strict', 'category_ids' => ['12'], 'name_patterns' => [], 'brand_ids' => []],
            'destinations' => [],
        ],

        // H1-09: customs duty on a cross-border order. A duty rate is not a thing a shop may invent -- it belongs to the destination's
        // tariff, it turns on what the goods are and where they were made, and it is the owner's to give per route with a competent source
        // behind it (Q-11). So the routes below ship EMPTY, and every cross-border route therefore answers "not quoted", which is the
        // honest answer: App\Services\Shipping\CustomsDutyQuote tells "not quoted" from "nothing payable" because a zero is an ANSWER --
        // it says the goods cross free -- and a platform that records zero where it means "we do not know" charges the customer nothing and
        // then has them handed a bill at the door.
        //
        //   'off'      the default: there is no duty on this platform, which is the truth for a shop selling inside one country
        //   'declare'  a cross-border order carries its quote, and an unquotable route says so; nothing is refused
        //   'strict'   a cross-border order on a route nobody has written a rule for is REFUSED before payment (the requirement's own rule)
        //
        // An unrecognised value is read as `off`, so a typo never starts refusing orders.
        'duty' => [
            'mode' => env('GCC_CUSTOMS_DUTY_MODE', App\Services\Shipping\CustomsDuty::MODE_OFF),

            // One entry per route, keyed `SOURCE:DESTINATION`; `*` on either side means any country, and the exact route wins over a
            // wildcard. Each needs BOTH a rate and a payer to be a quote at all, because who pays is a commercial term and not a rounding:
            // the buyer settling with the carrier at the door and the seller paying up front are different prices and different promises.
            //
            //   'SA:KW' => ['percentage' => 5, 'payer' => 'buyer_on_delivery', 'de_minimis' => 100],
            //
            // `percentage` 0 is a real answer where somebody wrote it down (these goods cross free); a missing one is not. `de_minimis` is
            // the destination's threshold, under which a parcel enters free, read in the order's own currency -- converting it here would
            // invent a rate. `payer` is `buyer_on_delivery` or `seller`.
            'routes' => [],
        ],
    ],

    'refunds' => [
        // Whether cancelling an order pays the customer's wallet back (E-13, finding E04-F10). Today a POS sale paid with the wallet refunds
        // itself and any other wallet-paid order does not: the customer keeps waiting until somebody refunds it by hand. Each answer has a
        // cost -- automatic means an operator's click moves money, manual means a forgotten refund stays with the platform -- so it is the
        // owner's decision: docs/gcc/OPEN-FINANCIAL-DECISIONS.md section 3.
        //
        //   'manual_as_today'          the default: only a POS wallet sale refunds itself, exactly as the platform runs now
        //   'every_paid_cancellation'  option A: any cancelled order with money on it pays the wallet back, whatever it was paid with
        //   'wallet_payments_only'     option C: automatic for a wallet payment, by hand where the money must return the way it came in
        //
        // Paying once per cancellation holds under every value: the status claim decides it (RF-04), not the setting. An unrecognised value
        // is read as the default.
        'when_a_cancellation_refunds_the_wallet' => env(
            'GCC_CANCELLATION_REFUNDS_THE_WALLET',
            App\Services\Refund\WhenACancellationRefundsTheWallet::MANUAL_AS_TODAY,
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification accounts per market (I1-01, I1-02)
    |--------------------------------------------------------------------------
    |
    | Off by default: every e-mail, one-time code, WhatsApp message and push leaves through the one account this installation has, exactly as
    | today. When it is on, and only once `notification_channel_profiles` exists, a market with an ACTIVE row of its own for a channel sends
    | that channel's messages through that account -- its own mail host and from address, its own SMS gateways, its own WhatsApp inbox, its
    | own push credentials -- and a market with no row of its own uses the shared row, which is what every market does until an operator
    | writes one. The table ships empty, so turning the switch on alone changes nothing either.
    |
    | A market's own account is the WHOLE answer for its channel: a Kuwaiti one-time code may not go out through a gateway the Saudi account
    | happens to have enabled. Credentials are stored encrypted and are never in a response or a log; `php artisan gcc:notification-readiness`
    | reports what each market would send through and what is missing, without printing a secret.
    |
    */

    // How long the records of this programme are kept (I2-01, I2-05). The rules themselves, and why each one is what it is, are in
    // App\Services\Retention\LegalRecordRetention; only the budgets are settings.
    'retention' => [
        'legal' => [
            // Days after which a legal text that is still a DRAFT and was NEVER approved may be removed. Zero means never, which is what
            // it ships as and what every installation keeps until somebody chooses otherwise. A text that was ever approved, published,
            // archived or revoked is kept for good whatever this says: an acceptance row points at it by id, version and hash.
            'draft_days' => (int) env('GCC_LEGAL_DRAFT_RETENTION_DAYS', 0),
        ],
    ],

    'notifications' => [
        'profiles' => filter_var(env('GCC_NOTIFICATION_PROFILES', false), FILTER_VALIDATE_BOOLEAN),

        // How many attempts ONE message gets (I1-09). It ships at 1 -- one attempt, no retry, which is exactly what this platform does
        // today: `PushNotificationTrait::sendNotificationToHttp()` catches and returns false, and `EmailTemplateTrait`'s catch block is
        // EMPTY, so a transient failure (an SMTP connection reset, an FCM 503, a token minted a second before it expired) simply lost the
        // message. Raising it is the operator's decision; the first test asserts that at 1 nothing changed. Capped at 5, because a
        // notification is often sent inside a web request.
        'attempts' => (int) env('GCC_NOTIFICATION_ATTEMPTS', 1),

        // Milliseconds to wait before each further attempt, multiplied by the attempt number. ZERO by default and deliberately: a backoff
        // that holds a web request open is a worse failure than the one it is recovering from. A queue worker may set one.
        'retry_delay_ms' => (int) env('GCC_NOTIFICATION_RETRY_DELAY_MS', 0),
    ],

    'loyalty' => [
        // What a loyalty point is worth in wallet money (E-14, finding LF-1), and the one setting that settles a disagreement older than
        // this work: the mobile apps credit `rate` points as ONE DOLLAR and the website credits them as ONE UNIT OF THE CURRENCY IN FORCE,
        // so on a Saudi store the app pays 3.75 times what the website pays for the same points. Both formulas are kept and reachable, and
        // the default is the platform exactly as it runs today -- each path with its own formula, to the digit, so no customer's credit
        // moves while the question is open.
        //
        //   'as_each_path_does_today'      the default: the app's formula in the app, the website's on the website
        //   'one_dollar_per_rate'          option A: `rate` points buy one US dollar's worth, which is what the admin field's label says
        //   'one_unit_in_force_per_rate'   option B: `rate` points buy one unit of the currency in force, which is what the website pays
        //
        // Changing it changes what a customer is paid for points they already hold, in one direction or the other, so it is the owner's
        // decision and nobody else's: docs/gcc/OPEN-FINANCIAL-DECISIONS.md section 1. An unrecognised value is read as the default.
        'point_value' => env('GCC_LOYALTY_POINT_VALUE', App\Services\Loyalty\WhatAPointIsWorth::AS_EACH_PATH_DOES_TODAY),

        // What happens when a refund takes back more points than the customer still holds (E-14). Today the balance goes
        // NEGATIVE: both writers check a `point_to_wallet` debit against the balance and neither checks a `refund_order`
        // debit at all, so a customer holding 10 points whose order is refunded for 30 ends on -20 and the next order they
        // place starts by paying that off. The criterion itself names the three answers and says the choice is the owner's.
        //
        //   'allow_as_today'  the default: the debit is written in full and the balance goes negative, to the point
        //   'refuse'          the points row is refused and nothing is written; the refund's MONEY is unaffected
        //   'clamp_to_zero'   the refund takes what is there and no more, and the row records the debit actually taken
        //
        // It decides what a customer owes for points they have already spent, so it is the owner's and nobody else's:
        // docs/gcc/OPEN-FINANCIAL-DECISIONS.md section 5. An unrecognised value is read as the default.
        'when_a_refund_would_take_points_below_zero' => env(
            'GCC_REFUND_POINTS_BELOW_ZERO',
            App\Services\Loyalty\WhenARefundWouldTakePointsBelowZero::ALLOW_AS_TODAY,
        ),
    ],

    'payments' => [
        // What a payment a gateway took is worth in the unit this platform STORES money in (E-V1). `Convert::usdPaymentModule`
        // converts a payment into the store's DEFAULT currency and stops, so on a store whose default is not the dollar its answer
        // is `default_rate` times the stored figure. Under `multi_currency` on a Saudi store a customer who pays 100 riyals into
        // the wallet has 100 stored where 26.67 is what 100 riyals is worth, and every screen that puts a stored figure back into
        // riyals shows 375: the customer paid 100 and the account reads 375. The same conversion sets the amount a top-up hands the
        // gateway, the minimum and maximum top-up checks, and the catalogue's price-range filter on the website and in the seller API.
        //
        //   'as_today'                       the default: the module's own arithmetic, branch for branch, to the digit
        //   'the_rate_of_the_currency_paid'  the correction: `amount * usd_rate / rate_paid`, the inverse of what charged the gateway
        //
        // Today's platform is single-currency, where both readings are the identity and nothing can differ. Switching it changes what
        // a stored unit is worth -- the same question as what a loyalty point is worth -- and a platform that has already run
        // `multi_currency` holds balances written at the old reading, so what happens to those is part of the answer. The owner's
        // decision and nobody else's: docs/gcc/OPEN-FINANCIAL-DECISIONS.md section 4. An unrecognised value is read as the default.
        'what_a_payment_is_worth' => env(
            'GCC_WHAT_A_PAYMENT_IS_WORTH',
            App\Services\Payments\WhatAPaymentIsWorth::AS_TODAY,
        ),

        // WHICH PROVIDER SUPPORT HAS ACTUALLY BEEN CONFIRMED, per gateway, market, currency and method (D2-05).
        //
        // The foundation rule is that a country named in documentation counts for nothing. A gateway's own currency list says what the
        // GATEWAY can do in general; it says nothing about whether this merchant's account with that provider is live in Kuwait, in
        // dinars, for cards. That is a fact somebody obtains from the provider and writes down with a date -- the owner's open Q-33, for
        // every cell.
        //
        // An entry grants nothing unless it carries `confirmed` STRICTLY TRUE (not 1, not 'yes'), a `provider` naming who confirmed it, a
        // readable `confirmed_on` that is not in the future, and a non-empty `currencies` list. `methods` is optional and absent means
        // every method, because a provider confirmation that does not break down by method is the ordinary case.
        //
        //   'gateways' => ['paymob_accept' => ['SA' => [
        //       'confirmed' => true, 'provider' => 'Paymob', 'confirmed_on' => '2026-01-15', 'currencies' => ['SAR'],
        //   ]]],
        //
        // MODE SHIPS `off`, where the registry is not consulted and refuses nothing, because a default-deny registry switched on with an
        // empty table is a platform that cannot take a payment anywhere. `enforce` is default deny and is a deliberate act, taken once the
        // cells are written down. The register itself ships EMPTY: every cell is a question for the provider, and this file is not the
        // place to answer it on their behalf.
        'capabilities' => [
            'mode' => env('GCC_PAYMENT_CAPABILITIES_MODE', App\Services\Payments\GatewayCapabilityRegistry::OFF),
            'gateways' => [],
        ],

        // Finding the payments a gateway TOOK and this shop never heard about (D2-12, D2-16).
        //
        // Every Paymob payment here is settled by the customer's browser coming back, and the callback reads
        // `session('payment_id')` to know which payment it is about -- so a customer who paid and then closed the tab,
        // lost signal or was timed out of their session leaves the money with Paymob and the payment row unpaid for
        // ever: no order, no wallet credit, and nothing on the platform that notices. The sweep asks Paymob, with the
        // reference the payment start already sends it (`special_reference`, the payment's own id), and settles
        // through the same service a confirmed browser return settles through.
        //
        // OFF AS IT SHIPS, and the command says so and stops: it creates orders and credits wallets on the strength
        // of what a gateway says, so a schedule written before an operator has decided to run it moves no money. It
        // never fails a payment -- a refusal is reported and the row is left exactly as it was.
        'recovery_sweep' => [
            'enabled' => filter_var(env('GCC_PAYMENT_RECOVERY_SWEEP', false), FILTER_VALIDATE_BOOLEAN),
            // A customer still at the gateway is not an abandoned payment, and settling one under them would have the
            // shop and the browser settling at once. Fifteen minutes is longer than any checkout and shorter than any
            // reconciliation window.
            'minimum_age_minutes' => (int) env('GCC_PAYMENT_RECOVERY_SWEEP_MIN_AGE', 15),
            // How far back a run looks. Beyond this a payment is a reconciliation question for a person, not something
            // a sweep should settle into an order whose stock, prices and coupons have all moved on.
            'oldest_days' => (int) env('GCC_PAYMENT_RECOVERY_SWEEP_OLDEST_DAYS', 7),
            // Payments considered per run, oldest first: the oldest unsettled payment has been owed longest.
            'batch' => (int) env('GCC_PAYMENT_RECOVERY_SWEEP_BATCH', 200),
        ],
    ],

    'tax' => [
        // Whether the tax already inside a tax-included price is recorded on the order (D1-07). Off by default, and off is the platform of
        // today: a store running the tax module in `is_included` mode writes `total_tax_amount` 0, every line's `tax` 0 and no row in
        // `order_taxes`, while the order's `tax_model` says `include` -- so a taxed sale records no tax at all and nothing can answer what
        // VAT was collected on it.
        //
        // When it is on, and only once `orders.included_tax_amount` and `order_details.included_tax` exist, the checkout computes the tax
        // inside the gross price (`gross * rate / (100 + rate)`, App\Services\Tax\IncludedTax) and writes it into those two columns ALONE.
        // No figure the customer was charged moves: `order_amount`, `total_tax_amount` and each line's `tax` are untouched, because the tax
        // is already in the price and `total_tax_amount` is a figure the panel ADDS when it recomputes an order.
        //
        // Whether a tax-included order must store its tax, and under which country's rules, is the owner's and the finance review's. The
        // mechanism is here so that answering it is a setting rather than a change to the checkout.
        'record_included_amount' => filter_var(env('GCC_TAX_RECORD_INCLUDED_AMOUNT', false), FILTER_VALIDATE_BOOLEAN),
    ],

];
