<?php

namespace App\Services\SalesChannels\Inventory\Allocation;

/** How one pool's net was divided: the storefront's reserve and the entitlement of every listing. The reserve and the entitlements never add up to more than the net. */
final readonly class AllocationResult
{
    /** @param array<int, MemberShare> $shares by listing id */
    public function __construct(public int $net, public int $storefrontReserve, public array $shares)
    {
    }

    public function entitled(): int
    {
        return array_sum(array_map(static fn (MemberShare $share): int => $share->entitlement, $this->shares));
    }

    /** Units of the net that belong to nobody in particular: the storefront keeps them. */
    public function unassigned(): int
    {
        return $this->net - $this->storefrontReserve - $this->entitled();
    }
}
