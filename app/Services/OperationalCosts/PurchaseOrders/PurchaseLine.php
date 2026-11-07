<?php

namespace App\Services\OperationalCosts\PurchaseOrders;

use Carbon\CarbonImmutable;

/**
 * One received line of a platform purchase order, as the platform stores it (read, never written). `unitCost` and `currency` are exactly what the order holds: a line with no cost
 * is zero there, and the order's currency is free text that may be empty.
 */
final readonly class PurchaseLine
{
    public function __construct(
        public int $id,
        public int $purchaseOrderId,
        public string $reference,
        public ?int $supplierId,
        public ?string $supplierName,
        public ?int $orderSellerId,
        public ?string $currency,
        public ?int $productId,
        public int $quantityReceived,
        public mixed $unitCost,
        public ?CarbonImmutable $updatedAt,
    ) {
    }
}
