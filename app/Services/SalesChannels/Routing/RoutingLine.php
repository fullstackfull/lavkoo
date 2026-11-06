<?php

namespace App\Services\SalesChannels\Routing;

/** One line of an order as the routing engine sees it: which product, how many, where its stock sits and who holds that stock. */
final readonly class RoutingLine
{
    public function __construct(
        public int $lineId,
        public string $externalLineId,
        public ?int $productId,
        public ?string $sku,
        public int $quantity,
        public ?int $locationId,
        public ?string $provider,
        public bool $stockConfirmed,
        public bool $cancelled,
    ) {
    }
}
