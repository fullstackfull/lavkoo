<?php

/*
|--------------------------------------------------------------------------
| Marketing consent (GCC)
|--------------------------------------------------------------------------
|
| Whether a marketing message needs a recorded consent, and what the answer is for a customer who has never
| answered. Everything here is a deployment-level default; the consent rows themselves live in
| `customer_marketing_consents` and are never edited.
|
| The feature ships OFF. With `enabled` false every marketing sender behaves exactly as it did before this code
| existed: the same recipients, the same text, the same queries. Turning it on is a deliberate act.
*/

return [

    'consent' => [

        /*
        | The one switch. Off by default.
        |
        | Read with FILTER_VALIDATE_BOOLEAN, not a (bool) cast, for the reason config/fulfillment.php gives: the cast
        | turns the usual ways of writing "off" (off, no, n, disabled, 0.0) into TRUE, so a person typing one of them
        | in a hurry would switch the thing ON. Here only true, 1, on and yes mean on.
        |
        | The gate ALSO stays off until the table exists (App\Services\Marketing\MarketingConsentGate::enabled), so the
        | code can be deployed before its migration runs.
        */
        'enabled' => filter_var(env('GCC_MARKETING_CONSENT', false), FILTER_VALIDATE_BOOLEAN),

        /*
        | What a market answers for a customer who has never answered. THIS IS A SETTING BECAUSE THE LEGAL ANSWER IS
        | OPEN (Discovery Q-15: the owner has not said whether each market is opt-in or opt-out).
        |
        |   opt_in   a marketing send needs a recorded grant. No record means NO SEND.  <-- shipped default
        |   opt_out  a marketing send is allowed until a withdrawal is recorded.
        |
        | `opt_in` is the stricter of the two and it is what ships, for every market including Saudi Arabia. It does not
        | move Saudi behaviour today, because `enabled` is false: with the switch off this value is never read.
        |
        | What it means the day the switch is turned on: a Saudi customer with no consent row stops receiving marketing
        | until they consent, existing newsletter subscribers included — the carry-over question A2-13 records as open.
        | If the owner's legal answer is that Saudi is opt-out, that is ONE SETTING, not a code change:
        | GCC_MARKETING_CONSENT_POLICY_SA=opt_out.
        */
        'default_policy' => env('GCC_MARKETING_CONSENT_DEFAULT_POLICY', 'opt_in'),

        /*
        | Per-market overrides of `default_policy`. Null (the shipped value) means "use the default".
        */
        'policies' => [
            'SA' => env('GCC_MARKETING_CONSENT_POLICY_SA'),
            'AE' => env('GCC_MARKETING_CONSENT_POLICY_AE'),
            'KW' => env('GCC_MARKETING_CONSENT_POLICY_KW'),
            'QA' => env('GCC_MARKETING_CONSENT_POLICY_QA'),
            'BH' => env('GCC_MARKETING_CONSENT_POLICY_BH'),
            'OM' => env('GCC_MARKETING_CONSENT_POLICY_OM'),
        ],

        /*
        | The market a subject is filed under when nothing says otherwise: the one market the store sells in today.
        | A customer with no address is a Saudi customer until a storefront layer says which store they came from.
        */
        'home_market' => env('GCC_MARKETING_CONSENT_HOME_MARKET', 'SA'),
    ],
];
