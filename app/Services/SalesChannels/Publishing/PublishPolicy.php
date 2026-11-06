<?php

namespace App\Services\SalesChannels\Publishing;

/** What the settings say about publishing one listing, after the listing's own values, then its product policy, then its account policy have been applied in that order. */
final readonly class PublishPolicy
{
    public function __construct(
        public ?int $stockLocationId,
        public bool $inventoryPush,
        public bool $pricePush,
        public string $priceSource,
        public int $safetyBuffer,
        public ?int $maxQuantity,
    ) {
    }
}
