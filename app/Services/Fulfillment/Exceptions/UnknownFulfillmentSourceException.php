<?php

namespace App\Services\Fulfillment\Exceptions;

use RuntimeException;

/**
 * A line names an execution source this installation cannot resolve, and the owner's rule is to refuse it (C-03).
 *
 * Thrown from inside the checkout transaction, which is what makes it a refusal rather than a warning: the order is not written.
 * It carries the stored value so an operator reading the log knows which code to put back in the configuration -- or which
 * product to reassign.
 */
class UnknownFulfillmentSourceException extends RuntimeException
{
    public function __construct(public readonly string $storedSource, public readonly int $productId)
    {
        parent::__construct("Product {$productId} names fulfillment source '{$storedSource}', which this installation does not know.");
    }
}
