<?php

namespace App\Services\SalesChannels\Inventory\Allocation;

use App\Models\SalesChannelProductMapping;

/** A listing of a pool's stock that takes no part in its division and whose channel still shows `shown` units of it. */
final readonly class PoolOutsider
{
    public function __construct(public SalesChannelProductMapping $listing, public int $shown)
    {
    }
}
