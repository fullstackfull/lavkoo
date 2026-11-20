<?php

return [

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

];
