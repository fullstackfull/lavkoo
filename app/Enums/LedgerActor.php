<?php

namespace App\Enums;

/** The staff member behind a manual ledger movement: the guard they are signed in under, which is also what the ledger records as the kind of actor. */
enum LedgerActor: string
{
    case Admin = 'admin';
    case Seller = 'seller';

    /** The person signed in under this guard right now, or null when nobody is. Never a value the request sent. */
    public function signedInId(): int|string|null
    {
        return auth($this->value)->id();
    }
}
