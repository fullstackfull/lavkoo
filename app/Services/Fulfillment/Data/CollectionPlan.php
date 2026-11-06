<?php

namespace App\Services\Fulfillment\Data;

/**
 * Who collects how much cash on delivery for one parent order.
 *
 * `blockedReason` set means the order's collection cannot be assigned safely (a split between collectors
 * that has not been enabled and verified, or an amount that cannot be converted) — its fulfillments are
 * held, not sent.
 */
final readonly class CollectionPlan
{
    /** @param array<string, float> $codByProvider provider code => cash that provider collects */
    public function __construct(
        public bool $isCod,
        public ?string $blockedReason,
        public array $codByProvider,
        public ?float $orderTotal,
    ) {
    }
}
