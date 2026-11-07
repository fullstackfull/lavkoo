<?php

namespace App\Services\SalesChannels\Data;

use Carbon\CarbonInterface;

/**
 * A customer return as the channel reports it, reduced to what changes our books: which order package, which lines, how many units the channel has ACCEPTED back.
 * A claim still waiting for a decision, rejected or cancelled returns nothing.
 */
final readonly class RemoteReturn
{
    /** @param array<string, int> $acceptedUnits external line id => units accepted back (every line the claim names, 0 included) */
    public function __construct(
        public string $claimId,
        public string $orderId,
        public string $packageId,
        public string $status,
        public array $acceptedUnits,
        public ?CarbonInterface $updatedAt,
    ) {
    }
}
