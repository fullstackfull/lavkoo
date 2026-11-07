<?php

namespace App\Services\SalesChannels\Dispatch;

/** Whether one channel order may be turned into a provider shipment now, and if not, every reason (stable codes), most basic first. */
final readonly class DispatchVerdict
{
    /** @param list<string> $blockers */
    public function __construct(public array $blockers, public ?string $provider = null)
    {
    }

    public function open(): bool
    {
        return $this->blockers === [];
    }
}
