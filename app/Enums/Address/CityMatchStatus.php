<?php

namespace App\Enums\Address;

/** The outcome of reading an old free-text city: only `Matched` may ever be applied without a person looking at it. */
enum CityMatchStatus: string
{
    case Matched = 'matched';
    /** Exactly one place has the name, but it is a small one and nothing else confirms it: as likely a district typed in the city box. */
    case Unconfirmed = 'unconfirmed';
    case Ambiguous = 'ambiguous';
    case Conflict = 'conflict';
    case None = 'none';
}
