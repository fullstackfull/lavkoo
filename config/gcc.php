<?php

return [

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
