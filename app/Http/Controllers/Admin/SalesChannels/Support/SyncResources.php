<?php

namespace App\Http\Controllers\Admin\SalesChannels\Support;

use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\ChannelConnectorRegistry;
use App\Services\SalesChannels\Contracts\ReadsChannelInventory;
use App\Services\SalesChannels\Contracts\ReadsFinances;
use App\Services\SalesChannels\Contracts\ReadsListings;
use App\Services\SalesChannels\Contracts\ReadsOrders;
use App\Services\SalesChannels\Contracts\ReadsReturns;
use App\Services\SalesChannels\Sync\ChannelSyncService;

/** What a channel can be read for. A connector implements only what the channel's documentation allows, so a button for the rest would only ever report a failure. */
final class SyncResources
{
    /** @var array<string, class-string> resource => the connector ability that reads it */
    public const ABILITIES = [
        ChannelSyncService::ORDERS => ReadsOrders::class,
        ChannelSyncService::RETURNS => ReadsReturns::class,
        ChannelSyncService::LISTINGS => ReadsListings::class,
        ChannelSyncService::INVENTORY => ReadsChannelInventory::class,
        ChannelSyncService::FINANCES => ReadsFinances::class,
    ];

    public function __construct(private readonly ChannelConnectorRegistry $connectors)
    {
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::ABILITIES);
    }

    /** @return list<string> the resources the account's channel can be read for, in the order they are listed */
    public function supportedBy(SalesChannelAccount $account): array
    {
        return array_values(array_filter(self::all(), fn (string $resource): bool => $this->connectors->supports($account, self::ABILITIES[$resource])));
    }
}
