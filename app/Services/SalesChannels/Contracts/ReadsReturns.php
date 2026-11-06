<?php

namespace App\Services\SalesChannels\Contracts;

use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Data\Pages;
use App\Services\SalesChannels\Data\RemoteReturn;
use Carbon\CarbonInterface;

interface ReadsReturns
{
    /**
     * Customer returns changed since `$since`, page by page.
     *
     * @param array<string, mixed>|null $cursor null for the first page
     * @return Pages<RemoteReturn>
     */
    public function returns(SalesChannelAccount $account, CarbonInterface $since, ?array $cursor): Pages;
}
