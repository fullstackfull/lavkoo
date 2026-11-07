<?php

namespace App\Services\OperationalCosts\Data;

use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonInterface;

/**
 * Goods coming into stock at a cost: what InventoryCostLayerService::receive() turns into a cost layer.
 *
 * `sourceFrom` and `sourceTo` say which units of a purchase-order line the layer holds (units `sourceFrom` + 1 to `sourceTo`); only a layer made from a purchase order has them.
 * `supplierId` is the platform's supplier (the name, `supplierName`, is the snapshot that outlives it) and `receivedAtBasis` says where `receivedAt` comes from.
 */
final readonly class LayerReceipt
{
    public function __construct(
        public int $locationId,
        public int $productId,
        public int $quantity,
        public Money $unitCost,
        public string $currency,
        public CarbonInterface $receivedAt,
        public string $sourceType,
        public string $identity,
        public string $variantKey = '',
        public ?string $sku = null,
        public ?int $sourceId = null,
        public ?string $sourceRef = null,
        public ?Money $landedUnitCost = null,
        public ?string $supplierName = null,
        public int $sellerId = 0,
        public ?int $sourceFrom = null,
        public ?int $sourceTo = null,
        public ?int $supplierId = null,
        public ?string $receivedAtBasis = null,
    ) {
    }
}
