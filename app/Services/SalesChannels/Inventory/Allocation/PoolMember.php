<?php

namespace App\Services\SalesChannels\Inventory\Allocation;

use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Publishing\PublishPolicy;

/** A listing that takes part in a pool, with the account it belongs to and the settings that apply to it. */
final readonly class PoolMember
{
    public function __construct(
        public SalesChannelProductMapping $listing,
        public SalesChannelAccount $account,
        public PublishPolicy $policy,
    ) {
    }
}
