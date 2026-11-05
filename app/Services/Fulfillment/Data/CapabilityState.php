<?php

namespace App\Services\Fulfillment\Data;

/** One capability of a provider and why it is, or is not, usable right now. */
final readonly class CapabilityState
{
    public const ENABLED = 'enabled';
    public const READY_DISABLED = 'ready_disabled';
    public const NEEDS_CONFIGURATION = 'needs_configuration';
    public const UNVERIFIED = 'unverified';
    public const UNDOCUMENTED = 'undocumented';

    public function __construct(
        public string $key,
        public string $state,
        public ?string $reason = null,
    ) {
    }

    public function isUsable(): bool
    {
        return $this->state === self::ENABLED;
    }
}
