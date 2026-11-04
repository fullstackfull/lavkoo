<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a product's stock is gone by the time the order is actually written.
 *
 * `product_stock_check()` runs *before* order generation, so between it passing and the decrement
 * landing another customer can take the last unit. This exception is what turns that race into a
 * refused order instead of a silent oversell — it is raised inside the order transaction, so
 * everything written for that order rolls back with it.
 *
 * It carries the product so the caller can say *which* item ran out rather than a generic apology.
 */
class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly int|string|null $productId = null,
        public readonly ?string $productName = null,
        public readonly int $requested = 0,
    ) {
        parent::__construct(sprintf(
            'Insufficient stock for product %s (requested %d).',
            $productName ?: ('#' . $productId),
            $requested
        ));
    }
}
