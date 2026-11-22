<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when stock left the shelf for a sale and the movement row describing it could not be written.
 *
 * `InventoryService::record()` is deliberately non-throwing: it was written for the procurement receipt path, where a missing
 * log line must never roll back goods that have physically arrived. For a SALE the trade runs the other way round. The whole
 * point of the sale movement is that `current_stock` can be reconciled against its own history, and a sale that moved stock
 * without saying so cannot be found again by anyone counting the shelf.
 *
 * So the sale refuses instead. Refusing is recoverable at the counter; an unrecorded sale is recoverable nowhere.
 */
class SaleNotRecordedException extends RuntimeException
{
    public function __construct(
        public readonly int|string|null $productId = null,
        public readonly ?string $productName = null,
        public readonly int $quantity = 0,
    ) {
        parent::__construct(sprintf(
            'Stock movement could not be recorded for the sale of %d x product %s; the sale was refused.',
            $quantity,
            $productName ?: ('#' . $productId)
        ));
    }
}
