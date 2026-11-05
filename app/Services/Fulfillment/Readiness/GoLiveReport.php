<?php

namespace App\Services\Fulfillment\Readiness;

use App\Enums\Fulfillment\ProviderCode;

/** The go-live checklist of one provider, as it stands right now. */
final readonly class GoLiveReport
{
    /** @param list<GoLiveItem> $items */
    public function __construct(
        public ProviderCode $provider,
        public array $items,
    ) {
    }

    public function ready(): bool
    {
        return $this->unmet() === [];
    }

    /** @return list<GoLiveItem> the items that hold automatic sending back */
    public function unmet(): array
    {
        return array_values(array_filter($this->items, static fn (GoLiveItem $item): bool => $item->blocks()));
    }

    public function metCount(): int
    {
        return count(array_filter($this->items, static fn (GoLiveItem $item): bool => $item->status === GoLiveItem::MET));
    }
}
