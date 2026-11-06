<?php

namespace Tests\Scratch\MysqlSchema;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Models\ExternalOrderMapping;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Sync\ChannelOrderIngestor;
use Tests\Support\BuildsRemoteOrders;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

class OrderSnapshotTest extends TestCase
{
    use BuildsRemoteOrders;
    use BuildsSalesChannelSchema;

    public function test_a_changed_order_wipes_the_operator_and_notifier_state_kept_in_the_snapshot(): void
    {
        $this->buildSalesChannelSchema();
        $account = SalesChannelAccount::create(['channel' => 'trendyol', 'name' => 'Trendyol KSA', 'enabled' => true]);
        $ingestor = app(ChannelOrderIngestor::class);
        $ingestor->ingest($account, $this->remoteOrder(status: ChannelOrderStatus::Processing));

        $mapping = ExternalOrderMapping::first();
        $snapshot = $mapping->snapshot;
        $snapshot['channel_notified'] = ['tracking' => 'TRK-1', 'at' => '2026-11-10T10:05:00+00:00'];
        $snapshot['shipment_link'] = ['previous' => ['provider' => null, 'carrier' => 'X']];
        $mapping->forceFill(['snapshot' => $snapshot])->save();

        // the channel moves the package to Shipped (the confirmation we just sent): the payload hash changes
        $ingestor->ingest($account, $this->remoteOrder(status: ChannelOrderStatus::Shipped, updatedAt: '2026-11-10 11:00:00'));

        $after = ExternalOrderMapping::first()->snapshot;
        fwrite(STDERR, "\nSNAPSHOT AFTER INGEST: " . json_encode($after) . "\n");
        $this->assertArrayHasKey('channel_notified', $after);
    }
}
