<?php

namespace App\Services\SalesChannels\Contracts;

use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Data\Pages;
use App\Services\SalesChannels\Data\RemoteFinancialEntry;
use Carbon\CarbonInterface;

interface ReadsFinances
{
    /**
     * Fees, adjustments, refunds and settlements posted between two instants.
     *
     * @param array<string, mixed>|null $cursor
     * @return Pages<RemoteFinancialEntry>
     */
    public function finances(SalesChannelAccount $account, CarbonInterface $from, CarbonInterface $to, ?array $cursor): Pages;
}
