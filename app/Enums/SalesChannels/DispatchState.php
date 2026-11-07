<?php

namespace App\Enums\SalesChannels;

/** Where the application is in turning one channel order into a provider shipment. */
enum DispatchState: string
{
    /** An attempt is running and holds the claim. */
    case Claimed = 'claimed';
    /** Something must change first (a switch, the buyer's details, a product mapping) or time must pass; `reason_code` says what. */
    case Blocked = 'blocked';
    /** The internal order exists: from here the existing Torod Point / M5AZN fulfilment owns the provider call, its retries and its reconciliation. */
    case Materialized = 'materialized';
    /** The provider holds the shipment. */
    case Accepted = 'accepted';
    /** The fulfilment ended without a shipment (refused, cancelled at the provider): a person decides. */
    case Failed = 'failed';
    /** The channel order was cancelled. */
    case Cancelled = 'cancelled';

    public function labelKey(): string
    {
        return 'dispatch_state_' . $this->value;
    }

    /** Whether the application may still start (or restart) the work for this order. */
    public function mayStart(): bool
    {
        return in_array($this, [self::Blocked, self::Claimed], true);
    }
}
