<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown inside a sale's transaction when its Idempotency-Key already belongs to a sale that was placed. The answer is that sale's order,
 * and nothing is stocked, debited or written again.
 */
class PosSaleReplayedException extends RuntimeException
{
    public function __construct(public readonly int $orderId)
    {
        parent::__construct("The sale was already placed as order #{$orderId}.");
    }
}
