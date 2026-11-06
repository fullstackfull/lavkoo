<?php

namespace App\Services\SalesChannels\Contracts;

use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Data\Pages;
use App\Services\SalesChannels\Data\RemoteOrder;
use Carbon\CarbonInterface;

interface ReadsOrders
{
    /**
     * Orders changed since `$since` (the channel's own "last modified"), page by page.
     *
     * @param array<string, mixed>|null $cursor null for the first page
     * @return Pages<RemoteOrder>
     */
    public function orders(SalesChannelAccount $account, CarbonInterface $since, ?array $cursor): Pages;

    /** One order (or package) by its channel id, for a notification or a webhook that only says "this one changed". */
    public function order(SalesChannelAccount $account, string $orderId, ?string $packageId = null): ?RemoteOrder;
}
