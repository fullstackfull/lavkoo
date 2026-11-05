<?php

namespace App\Services\Fulfillment\Readiness;

/**
 * One condition that has to hold before a provider may receive orders without a person approving each one.
 *
 * `evidence` is a short statement of fact (a count, an age, the names of what is missing): never a credential, never
 * anything personal. What the item means and what to do about it is a translated text keyed by `key`; `reasonKey` is the
 * translation key of a provider capability's own explanation, for the items that mirror a capability.
 */
final readonly class GoLiveItem
{
    public const MET = 'met';
    /** Not met, and automatic sending must wait. */
    public const UNMET = 'unmet';
    /** Not met, worth knowing, but it does not hold automatic sending back. */
    public const ADVISORY = 'advisory';

    public function __construct(
        public string $key,
        public string $status,
        public string $evidence = '',
        public ?string $reasonKey = null,
    ) {
    }

    public function blocks(): bool
    {
        return $this->status === self::UNMET;
    }

    public function labelKey(): string
    {
        return 'go_live_item_' . $this->key;
    }

    public function helpKey(): string
    {
        return 'go_live_item_' . $this->key . '_help';
    }
}
