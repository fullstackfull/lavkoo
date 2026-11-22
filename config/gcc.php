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
