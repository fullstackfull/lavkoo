<?php

namespace Tests\Support\Concurrency;

use App\Models\ExternalOrderMapping;
use App\Services\SalesChannels\Dispatch\ChannelOrderDispatcher;
use App\Services\SalesChannels\Dispatch\ManagedShipmentMirror;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Support\Fixtures\TrendyolFixtures;

/** What a worker process does for the dispatch concurrency tests. The channel is a fixture: no worker ever reaches a real service. */
final class DispatchOperations
{
    /** @return array{state: string, order_id: int|null, reason: string|null} */
    public static function dispatchChannelOrder(array $args): array
    {
        config([
            'sales_channels.enabled' => true, 'sales_channels.managed_dispatch_enabled' => true, 'sales_channels.buyer_data_transfer_enabled' => true,
            'operational_costs.base_currency' => 'SAR',
        ]);
        $mapping = ExternalOrderMapping::findOrFail((int) $args['mapping_id']);
        $package = TrendyolFixtures::package(['shipmentPackageId' => (int) $mapping->external_package_id, 'shipmentNumber' => (int) $mapping->external_package_id, 'orderNumber' => $mapping->external_order_id]);
        Http::swap(new Factory());
        Http::fake(['*' => Http::response(TrendyolFixtures::stream([$package]))]);

        $outcome = app(ChannelOrderDispatcher::class)->dispatch($mapping, trigger: (string) ($args['trigger'] ?? 'manual'));

        return ['state' => $outcome->state, 'order_id' => $outcome->internalOrderId, 'reason' => $outcome->reason];
    }

    public static function mirrorManagedShipment(array $args): string
    {
        return app(ManagedShipmentMirror::class)->mirror(ExternalOrderMapping::findOrFail((int) $args['mapping_id']));
    }
}
