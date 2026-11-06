<?php

namespace App\Services\SalesChannels\Inventory;

use Carbon\CarbonInterface;

/**
 * How many units of a product a location can promise, and how far that number can be trusted.
 *
 * `quality` is the whole point. A quantity is publishable only when it is `confirmed` (the location's own source of truth reported it
 * recently) or `manual` (a person typed it and it is labelled as such). `stale` (the source has not been heard from for too long) and
 * `unknown` (no source, or one nobody has verified) are never published: an estimate is never offered to a customer as stock.
 */
final readonly class StockAvailability
{
    public const CONFIRMED = 'confirmed';
    public const MANUAL = 'manual';
    public const STALE = 'stale';
    public const UNKNOWN = 'unknown';

    public function __construct(
        public ?int $available,
        public string $quality,
        public ?CarbonInterface $syncedAt,
        public string $source,
        public int $inFlight = 0,
    ) {
    }

    public static function unknown(string $source = 'none'): self
    {
        return new self(null, self::UNKNOWN, null, $source);
    }

    public function isPublishable(): bool
    {
        return $this->available !== null && in_array($this->quality, [self::CONFIRMED, self::MANUAL], true);
    }

    /** What may be offered: the quantity less what open orders have already been promised and the location has not yet taken off its count. */
    public function sellable(): ?int
    {
        return $this->available === null ? null : max(0, $this->available - $this->inFlight);
    }
}
