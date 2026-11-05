<?php

namespace App\Services\Fulfillment\Data;

final readonly class ReadinessReport
{
    /**
     * @param list<string> $missing configuration keys that block outbound use
     * @param array<string, CapabilityState> $capabilities
     */
    public function __construct(
        public bool $configured,
        public array $missing,
        public array $capabilities,
    ) {
    }

    public function capability(string $key): ?CapabilityState
    {
        return $this->capabilities[$key] ?? null;
    }

    public function canUse(string $key): bool
    {
        return ($this->capabilities[$key] ?? null)?->isUsable() ?? false;
    }
}
