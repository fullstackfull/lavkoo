<?php

namespace App\Services\SalesChannels\Inventory\Allocation;

/** One listing's claim on a pool: how it takes its part, how much of it the channel may be shown at most, what is held back from it. */
final readonly class AllocationMember
{
    /**
     * @param string|null $mode one of StockShare::MODES, or null for an equal part of what is left
     * @param int $value units (fixed) or percent of the pool (percent); not used by the other modes
     * @param int|null $priority lower fills first; a listing with none comes after every listing that has one
     * @param int|null $cap the most the channel may be shown (null: no limit)
     * @param int $buffer units this listing keeps back for itself
     * @param int $lag units this channel is expected to sell before an order of it is imported
     */
    public function __construct(
        public int $listingId,
        public ?string $mode = null,
        public int $value = 0,
        public ?int $priority = null,
        public ?int $cap = null,
        public int $buffer = 0,
        public int $lag = 0,
    ) {
    }

    /** The most entitlement that can ever reach the channel: what is shown is the entitlement less the buffer and the lag, and never more than the cap. */
    public function ceiling(): ?int
    {
        return $this->cap === null ? null : max(0, $this->cap) + max(0, $this->buffer) + max(0, $this->lag);
    }
}
