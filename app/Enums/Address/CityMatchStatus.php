<?php

namespace App\Enums\Address;

/** The outcome of reading an old free-text city: only `Matched` may ever be applied without a person looking at it. */
enum CityMatchStatus: string
{
    case Matched = 'matched';
    case Ambiguous = 'ambiguous';
    case Conflict = 'conflict';
    case None = 'none';
}
