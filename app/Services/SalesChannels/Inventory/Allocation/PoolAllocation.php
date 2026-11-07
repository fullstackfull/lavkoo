<?php

namespace App\Services\SalesChannels\Inventory\Allocation;

use App\Services\SalesChannels\Inventory\PoolKey;

/**
 * The division of one pool as the single writer left it: what the pool can offer, what each listing is entitled to and may be shown, and whether a quantity may be raised.
 * `reason` is set (and `net` is null) when the pool offers nothing at all: it is refused, or its figure is not one that may be published.
 */
final readonly class PoolAllocation
{
    public const DEFICIT_BLOCK = 'deficit_block';
    public const ORDER_SYNC_STALE = 'order_sync_stale';

    /**
     * @param array<int, MemberShare> $shares by listing id
     * @param list<int> $staleAccounts accounts whose order import is behind
     */
    public function __construct(
        public PoolKey $key,
        public int $version,
        public ?int $net,
        public int $deficit,
        public bool $deficitHold,
        public ?string $reason,
        public int $storefrontReserve,
        public array $shares,
        public array $staleAccounts,
    ) {
    }

    /** Why no quantity of this pool may be raised right now, or null when one may. */
    public function blockedBy(): ?string
    {
        return self::raisingBlockedBy($this->deficit, $this->deficitHold, $this->staleAccounts);
    }

    /** @param list<int> $staleAccounts */
    public static function raisingBlockedBy(int $deficit, bool $deficitHold, array $staleAccounts): ?string
    {
        return match (true) {
            $deficit > 0 || $deficitHold => self::DEFICIT_BLOCK,
            $staleAccounts !== [] => self::ORDER_SYNC_STALE,
            default => null,
        };
    }
}
