<?php

namespace App\Services\SalesChannels\Publishing;

/**
 * What the settings say about publishing one listing, after the listing's own values, then its product policy, then its account policy have been applied in that order.
 * `shareMode`, `shareValue` and `priority` say how the listing takes its part of a pool several listings offer (see StockAllocator); nothing set is an equal part.
 */
final readonly class PublishPolicy
{
    public function __construct(
        public ?int $stockLocationId,
        public bool $inventoryPush,
        public bool $pricePush,
        public string $priceSource,
        public int $safetyBuffer,
        public ?int $maxQuantity,
        public ?string $shareMode = null,
        public int $shareValue = 0,
        public ?int $priority = null,
    ) {
    }
}
