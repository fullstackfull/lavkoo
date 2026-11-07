<?php

namespace App\Services\OperationalCosts\Exceptions;

use RuntimeException;

/**
 * A rule applies to the service but a fact it needs is not known (the weight of the parcel, the city, the exchange rate). The cost is
 * then recorded as MISSING with this reason; it is never priced as if the fact were zero.
 */
final class CostInputMissing extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }
}
