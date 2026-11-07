<?php

namespace App\Services\SalesChannels\Inventory\Allocation;

/** What one listing is entitled to, and what it may be shown: the entitlement less the listing's buffer and the units it is expected to sell before we hear of them. */
final readonly class MemberShare
{
    public function __construct(
        public int $listingId,
        public string $rule,
        public int $entitlement,
        public int $buffer,
        public int $lag,
    ) {
    }

    public function publish(): int
    {
        return max(0, $this->entitlement - $this->buffer - $this->lag);
    }
}
