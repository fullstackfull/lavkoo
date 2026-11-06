<?php

namespace Tests\Scratch\MysqlSchema;

use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Data\RemoteListing;
use App\Services\SalesChannels\Sync\ListingSyncService;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

class ListingSnapshotTest extends TestCase
{
    use BuildsSalesChannelSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
    }

    private function listing(int $quantity): RemoteListing
    {
        return new RemoteListing('999', null, 'content-1', '999', null, 'Title', null, 'onSale', Money::of('99'), 'SAR', $quantity, CarbonImmutable::parse('2026-11-10'), ['title' => 'Title']);
    }

    public function test_a_listing_that_changed_at_the_channel_wipes_the_operator_state_kept_in_the_same_json_column(): void
    {
        $account = SalesChannelAccount::create(['channel' => 'trendyol', 'name' => 'Trendyol KSA', 'enabled' => true]);
        $listings = app(ListingSyncService::class);
        $listings->sync($account, [$this->listing(5)]);

        $row = SalesChannelProductMapping::first();
        $snapshot = $row->snapshot;
        $snapshot['manual_price'] = '120.000000';
        $snapshot['publish']['stock'] = ['state' => 'failed', 'attempted' => '3', 'failures' => 2];
        $row->forceFill(['snapshot' => $snapshot])->save();

        // the channel now reports another quantity (for example because OUR publish just changed it)
        $listings->sync($account, [$this->listing(3)]);

        $after = SalesChannelProductMapping::first()->snapshot;
        fwrite(STDERR, "\nSNAPSHOT AFTER SYNC: " . json_encode($after) . "\n");
        $this->assertArrayHasKey('manual_price', $after);
    }
}
