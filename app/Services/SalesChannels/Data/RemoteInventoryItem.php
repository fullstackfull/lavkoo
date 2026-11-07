<?php

namespace App\Services\SalesChannels\Data;

use Carbon\CarbonInterface;

/** Stock the channel itself holds for us (Amazon FBA). `available` is what it can sell now; the other figures are reported only when the channel gives them. */
final readonly class RemoteInventoryItem
{
    public function __construct(
        public string $sku,
        public ?string $asin,
        public ?string $fnSku,
        public ?string $marketplaceId,
        public ?int $available,
        public ?int $reserved,
        public ?int $unfulfillable,
        public ?int $inbound,
        public ?int $total,
        public ?CarbonInterface $lastUpdated,
        public ?string $condition = null,
    ) {
    }
}
