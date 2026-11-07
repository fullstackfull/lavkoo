<?php

namespace App\Services\SalesChannels\Contracts;

use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Data\Pages;
use App\Services\SalesChannels\Data\RemoteInventoryItem;

interface ReadsChannelInventory
{
    /**
     * Stock the CHANNEL holds for us (Amazon FBA). Not the stock we publish to it.
     *
     * @param array<string, mixed>|null $cursor
     * @return Pages<RemoteInventoryItem>
     */
    public function inventory(SalesChannelAccount $account, ?array $cursor): Pages;
}
