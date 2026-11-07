<?php

namespace App\Services\SalesChannels\Inventory;

use App\Models\SalesChannelProductMapping;

/** Which pool: a product, or one variant of it, at one location. */
final readonly class PoolKey
{
    public function __construct(public int $locationId, public int $productId, public string $variantKey = '')
    {
    }

    public static function ofListing(SalesChannelProductMapping $listing, int $locationId): self
    {
        return new self($locationId, (int) $listing->product_id, (string) $listing->variant_key);
    }

    /** @return array{location_id: int, product_id: int, variant_key: string} */
    public function columns(): array
    {
        return ['location_id' => $this->locationId, 'product_id' => $this->productId, 'variant_key' => $this->variantKey];
    }

    public function __toString(): string
    {
        return $this->locationId . ':' . $this->productId . ':' . $this->variantKey;
    }
}
