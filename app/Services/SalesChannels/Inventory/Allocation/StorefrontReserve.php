<?php

namespace App\Services\SalesChannels\Inventory\Allocation;

/** The part of a pool kept out of the channels' reach for the storefront: a number of units, or a percentage of the pool. */
final readonly class StorefrontReserve
{
    public const FIXED = 'fixed';
    public const PERCENT = 'percent';

    public function __construct(public string $mode = self::PERCENT, public int $value = 0)
    {
    }

    public static function none(): self
    {
        return new self();
    }

    /** The reserve in hundredths of a unit, so that a percentage of a small pool is not rounded before the rest is divided. */
    public function hundredths(int $net): int
    {
        $value = max(0, $this->value);

        return $this->mode === self::FIXED ? min($value, $net) * 100 : $net * min(100, $value);
    }
}
