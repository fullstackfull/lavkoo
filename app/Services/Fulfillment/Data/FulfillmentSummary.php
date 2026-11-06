<?php

namespace App\Services\Fulfillment\Data;

use App\Models\ProviderFulfillment;
use Illuminate\Support\Collection;

/** The parent order's fulfillment picture, derived from its provider fulfillments. Read-only. */
final readonly class FulfillmentSummary
{
    /** @param Collection<int, ProviderFulfillment> $fulfillments */
    public function __construct(
        public string $status,
        public Collection $fulfillments,
        public bool $mixed,
        public bool $allRequiredFinished,
        public ?string $deliveryBlockedReason,
    ) {
    }

    public function hasProviderFulfillments(): bool
    {
        return $this->fulfillments->isNotEmpty();
    }
}
