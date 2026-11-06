<?php

namespace App\Services\SalesChannels\Contracts;

use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Data\Pages;
use App\Services\SalesChannels\Data\RemoteListing;

interface ReadsListings
{
    /**
     * @param array<string, mixed>|null $cursor null for the first page
     * @return Pages<RemoteListing>
     */
    public function listings(SalesChannelAccount $account, ?array $cursor): Pages;
}
