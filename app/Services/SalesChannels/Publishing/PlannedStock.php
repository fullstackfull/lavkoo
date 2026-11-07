<?php

namespace App\Services\SalesChannels\Publishing;

use App\Services\SalesChannels\Inventory\Allocation\PoolAllocation;

/** The quantity worked out for one listing, what the channel is believed to show now, and the division of the pool it was worked out from (none when the division is switched off). */
final readonly class PlannedStock
{
    public function __construct(public int $quantity, public ?int $shown, public ?PoolAllocation $allocation = null)
    {
    }

    public function isReduction(): bool
    {
        return $this->shown !== null && $this->quantity < $this->shown;
    }
}
