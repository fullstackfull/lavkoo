<?php

namespace Tests\Feature\SalesChannels;

use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Inventory\Allocation\DirtyPools;
use App\Services\SalesChannels\Inventory\Allocation\PoolAllocation;
use App\Services\SalesChannels\Inventory\Allocation\PoolAllocationService;
use App\Services\SalesChannels\Inventory\PoolKey;
use App\Services\SalesChannels\Publishing\DirtyPoolRun;
use App\Services\SalesChannels\Publishing\StockPricePublisher;
use App\Services\SalesChannels\Support\ChannelOperations;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsRemoteOrders;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\BuildsStockPools;
use Tests\Support\CreatesChannelAccounts;
use Tests\TestCase;

/**
 * A listing that takes no part in a pool any more (publishing switched off, a rule refuses it) still shows what we last wrote to its channel, and nothing can lower that. Those units
 * are counted as taken before the rest is shared, so a sibling is never raised into them, and the listing's record of its part is kept whatever its settings do.
 */
class PoolOutsidersTest extends TestCase
{
    use BuildsRemoteOrders;
    use BuildsSalesChannelSchema;
    use BuildsStockPools;
    use CreatesChannelAccounts;

    private const PRODUCT = 50;

    private SalesChannelAccount $first;

    private SalesChannelAccount $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00 UTC'));
        $this->first = $this->trendyolAccount(sellerId: '1234567');
        $this->second = $this->trendyolAccount(sellerId: '7654321');
        $this->storeProduct(self::PRODUCT);
        Http::fake(['apigw.trendyol.com/*' => Http::response(['batchRequestId' => 'batch-1'])]);
    }

    private function key(): PoolKey
    {
        return new PoolKey($this->torodLocation()->id, self::PRODUCT);
    }

    /** @return list<int> the quantities sent to one seller's channel, oldest first */
    private function sent(string $seller): array
    {
        return Http::recorded(fn (Request $request): bool => str_contains($request->url(), "/sellers/{$seller}/products/price-and-inventory"))
            ->flatMap(fn (array $pair): array => array_column($pair[0]->data()['items'], 'quantity'))->values()->all();
    }

    private function publishBoth(): void
    {
        $this->completeOrderSweep($this->first, $this->second);
        app(StockPricePublisher::class)->publishStock($this->first);
        app(StockPricePublisher::class)->publishStock($this->second);
    }

    /** Two channels sharing a stock of 10, each shown 5, and then the first one's publishing switched off in the admin: its channel keeps showing 5. */
    private function secondChannelAfterTheFirstWasSwitchedOff(): array
    {
        $this->providerFigure(self::PRODUCT, 10, now()->subMinutes(30));
        $a = $this->stockListing($this->first, self::PRODUCT, $this->torodLocation(), 'BAR-A');
        $b = $this->stockListing($this->second, self::PRODUCT, $this->torodLocation(), 'BAR-B');
        $this->publishBoth();
        $this->assertSame([5], $this->sent('1234567'));
        $this->assertSame([5], $this->sent('7654321'));
        $a->forceFill(['inventory_publish' => false])->save();
        app(DirtyPools::class)->mark([$this->key()]);
        $this->travel(20)->minutes();

        return [$a, $b];
    }

    public function test_switching_a_listing_off_does_not_let_its_sibling_take_the_units_the_first_channel_still_shows(): void
    {
        [$a, $b] = $this->secondChannelAfterTheFirstWasSwitchedOff();
        $this->providerFigure(self::PRODUCT, 10, now());

        $this->publishBoth();

        $shownByTheFirst = $a->refresh()->shownQuantity();
        $shownByTheSecond = $b->refresh()->shownQuantity();
        $this->assertSame(5, $shownByTheFirst, 'nothing is written for a listing that is switched off');
        $this->assertSame(5, $shownByTheSecond, 'the sibling is not raised into the 5 units that are still on the first channel');
        $this->assertLessThanOrEqual(10, $shownByTheFirst + $shownByTheSecond);
        $this->assertSame([5], $this->sent('7654321'));
    }

    public function test_a_sibling_gets_the_units_back_once_the_listing_that_was_switched_off_shows_less(): void
    {
        [$a, $b] = $this->secondChannelAfterTheFirstWasSwitchedOff();
        $this->providerFigure(self::PRODUCT, 10, now());
        $this->publishBoth();
        $this->assertSame(5, $b->refresh()->shownQuantity());

        $a->forceFill(['remote_quantity' => 0, 'last_seen_at' => now()])->save();
        $this->travel(2)->minutes();
        $this->publishBoth();

        $this->assertSame(10, $b->refresh()->shownQuantity(), 'the first channel was lowered by hand and read back: its units are the pool\'s again');
    }

    public function test_what_the_channel_shows_now_counts_not_what_was_written_once(): void
    {
        [$a] = $this->secondChannelAfterTheFirstWasSwitchedOff();
        $a->forceFill(['remote_quantity' => 2, 'last_seen_at' => now()->addMinute()])->save();
        $this->travel(2)->minutes();
        $this->providerFigure(self::PRODUCT, 10, now());

        $allocation = app(PoolAllocationService::class)->allocate($this->key());

        $this->assertSame(8, array_values($allocation->shares)[0]->entitlement, 'the first channel sold 3 of its 5 and shows 2: the other has the other 8');
    }

    public function test_the_listing_that_is_switched_off_is_recorded_as_taking_no_part_and_what_it_shows(): void
    {
        [$a] = $this->secondChannelAfterTheFirstWasSwitchedOff();

        app(PoolAllocationService::class)->allocate($this->key());

        $this->assertSame([(string) $this->key(), true, 5, PoolAllocation::OUTSIDE_THE_POOL], [$a->refresh()->allocation['pool'], $a->allocation['outside'], $a->allocation['shown'], $a->allocation['reason']]);
    }

    public function test_a_listing_nobody_ever_published_to_and_an_fba_listing_and_one_at_another_location_are_not_counted(): void
    {
        $this->providerFigure(self::PRODUCT, 10, now()->subMinutes(30));
        $this->stockListing($this->second, self::PRODUCT, $this->torodLocation(), 'BAR-B');
        $theSellersOwn = $this->stockListing($this->first, self::PRODUCT, $this->torodLocation(), 'BAR-OWN', ['inventory_publish' => false]);
        $theSellersOwn->forceFill(['remote_quantity' => 7, 'last_seen_at' => now()])->save();
        $amazon = $this->amazonAccount();
        $fba = $this->stockListing($amazon, self::PRODUCT, $this->torodLocation(), 'FBA-1', ['inventory_publish' => false, 'fulfillment_mode' => 'fba', 'last_published_quantity' => 4]);
        $elsewhere = $this->stockListing($this->first, self::PRODUCT, $this->ownLocation(), 'BAR-ELSEWHERE', ['inventory_publish' => false, 'last_published_quantity' => 6]);

        $allocation = app(PoolAllocationService::class)->allocate($this->key());

        $this->assertSame(10, array_values($allocation->shares)[0]->entitlement, 'one listing, 10 units: no one else is showing any of them');
        $this->assertNull($theSellersOwn->refresh()->allocation, 'what the seller keeps on a listing nobody published to is not ours');
        $this->assertNull($fba->refresh()->allocation);
        $this->assertNull($elsewhere->refresh()->allocation);
    }

    public function test_more_shown_outside_than_the_pool_holds_leaves_the_others_nothing(): void
    {
        [$a, $b] = $this->secondChannelAfterTheFirstWasSwitchedOff();
        $this->storeOrder(1, self::PRODUCT, 8);

        $allocation = app(PoolAllocationService::class)->allocate($this->key());

        $this->assertSame(2, $allocation->net);
        $this->assertSame(0, array_values($allocation->shares)[0]->publish(), 'the first channel shows 5 of the 2 that are left and nothing here can lower it: the second shows none');
        $this->assertSame(5, $a->refresh()->shownQuantity());
        $this->assertNotNull($b);
    }

    public function test_a_listing_whose_settings_were_saved_gets_its_allocation_back_at_the_next_division(): void
    {
        $this->providerFigure(self::PRODUCT, 10, now()->subMinutes(30));
        $a = $this->stockListing($this->first, self::PRODUCT, $this->torodLocation(), 'BAR-A');
        $this->stockListing($this->second, self::PRODUCT, $this->torodLocation(), 'BAR-B');
        $this->publishBoth();
        $this->assertNotNull($a->refresh()->allocation);

        $a->forceFill(['allocation' => null])->save();
        app(DirtyPools::class)->mark([$this->key()]);
        $this->travel(2)->seconds();
        app(DirtyPoolRun::class)->run();

        $this->assertNotNull($a->refresh()->allocation, 'the division did not change, so its version stayed: a listing that has no record of its part is told all the same');
        $this->assertSame((string) $this->key(), $a->allocation['pool']);
    }
}
