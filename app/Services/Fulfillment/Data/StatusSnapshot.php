<?php

namespace App\Services\Fulfillment\Data;

use Carbon\CarbonInterface;

/** A provider's own statement about one fulfillment's state, from a poll or a verification call. */
final readonly class StatusSnapshot
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public string $providerStatus,
        public ?CarbonInterface $observedAt = null,
        public array $raw = [],
    ) {
    }
}
