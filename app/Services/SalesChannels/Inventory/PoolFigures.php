<?php

namespace App\Services\SalesChannels\Inventory;

use Carbon\CarbonInterface;

/**
 * What one pool (one product, or one variant of it, at one location) can still promise, and what it is made of.
 *
 *   Net     = max(0, P - Hs - Hc - B)     the units that may still be offered to the channels, all of them together
 *   deficit = max(0, Hs + Hc - P)         units promised to open orders beyond what the pool holds
 *
 * P is the location's own figure, Hs the units on store orders the provider has not counted yet, Hc the units on open channel orders, B the pool's safety buffer. A pool that
 * is refused (`refusal`) offers nothing, whatever its figure says.
 */
final readonly class PoolFigures
{
    /** The product is sold per variant and the listing names none: one figure cannot be spread over the variants. */
    public const VARIANT_KEY_REQUIRED = 'variant_key_required';

    /** The location holds one figure for the whole product and none for this variant: every variant would be offered all of it. */
    public const VARIANT_FIGURE_MISSING = 'variant_figure_missing';

    /** The stock is the storefront's own: the store already takes its sales off it and nothing takes channel units off it, so what to offer is the owner's decision. */
    public const STOREFRONT_OWNED = 'storefront_owned_needs_owner_decision';

    public function __construct(
        public ?int $available,
        public string $quality,
        public ?CarbonInterface $syncedAt,
        public string $source,
        public int $storeHolds,
        public int $channelHolds,
        public int $buffer,
        public ?string $refusal = null,
    ) {
    }

    /** @return array{net: int, deficit: int} */
    public static function balance(int $available, int $storeHolds, int $channelHolds, int $buffer): array
    {
        return [
            'net' => max(0, $available - $storeHolds - $channelHolds - $buffer),
            'deficit' => max(0, $storeHolds + $channelHolds - $available),
        ];
    }

    public function isPublishable(): bool
    {
        return $this->refusal === null && $this->available !== null && in_array($this->quality, [StockAvailability::CONFIRMED, StockAvailability::MANUAL], true);
    }

    /**
     * Can the pool cover this many units? The store's own stock is refused for the channels (what to offer from it is the owner's decision) but it is read like any other, so it can
     * say whether the shelf covers an order. Any other refusal stays one: a whole product's figure is no proof that a variant has the units.
     */
    public function covers(int $units): bool
    {
        $readable = $this->refusal === null || $this->refusal === self::STOREFRONT_OWNED;

        return $readable && $this->available !== null && in_array($this->quality, [StockAvailability::CONFIRMED, StockAvailability::MANUAL], true) && (int) $this->net() >= $units;
    }

    public function net(): ?int
    {
        return $this->available === null ? null : self::balance($this->available, $this->storeHolds, $this->channelHolds, $this->buffer)['net'];
    }

    public function deficit(): int
    {
        return $this->available === null ? 0 : self::balance($this->available, $this->storeHolds, $this->channelHolds, $this->buffer)['deficit'];
    }
}
