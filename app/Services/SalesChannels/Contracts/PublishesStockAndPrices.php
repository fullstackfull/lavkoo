<?php

namespace App\Services\SalesChannels\Contracts;

use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Data\PublishResult;
use App\Services\SalesChannels\Data\PublishUpdate;

/** Sets what a listing shows. Every method is a WRITE: the transport refuses it unless the account, the capability and an approval allow it. */
interface PublishesStockAndPrices
{
    /** @param list<PublishUpdate> $updates only the quantity of each is used */
    public function publishStock(SalesChannelAccount $account, array $updates): PublishResult;

    /** @param list<PublishUpdate> $updates only the price of each is used */
    public function publishPrices(SalesChannelAccount $account, array $updates): PublishResult;
}
