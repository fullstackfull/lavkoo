<?php

namespace Tests\Feature\SalesChannels;

use App\Models\InventoryLocation;
use App\Models\InventoryLocationBalance;
use App\Models\InventorySourcePolicy;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use App\Services\SalesChannels\Inventory\ReservationService;
use App\Services\SalesChannels\Publishing\StockPricePublisher;
use App\Services\SalesChannels\Support\ChannelOperations;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\TestCase;

/** What is offered to a channel, when it is not, and what is remembered so the same thing is not sent again. */
class StockPricePublisherTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    private const TY = 'https://apigw.trendyol.com/integration';
    private const PRODUCT = 50;

    private StockPricePublisher $publisher;

    private InventoryLocation $own;

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 12:00:00 UTC'));
        $this->publisher = app(StockPricePublisher::class);
        $this->own = app(InventoryLocationService::class)->ensureDefaults()[2];
        $this->account = $this->trendyolAccount();
    }

    private function stock(int $available, string $quality = 'manual', ?CarbonInterface $syncedAt = null): void
    {
        InventoryLocationBalance::updateOrCreate(
            ['location_id' => $this->own->id, 'sku' => 'SKU-1', 'variant_key' => ''],
            ['product_id' => self::PRODUCT, 'available' => $available, 'quality' => $quality, 'source_synced_at' => $syncedAt ?? now()],
        );
    }

    private function listing(array $overrides = []): SalesChannelProductMapping
    {
        return SalesChannelProductMapping::create($overrides + [
            'account_id' => $this->account->id, 'product_id' => self::PRODUCT, 'listing_key' => 'BC-1', 'external_sku' => 'SKU-1', 'external_barcode' => 'BC-1', 'status' => 'mapped',
            'stock_location_id' => $this->own->id, 'inventory_publish' => true, 'price_publish' => true, 'price_source' => 'manual', 'snapshot' => ['manual_price' => '49.95'],
        ]);
    }

    /** @return list<Request> */
    private function sentTo(string $needle): array
    {
        return Http::recorded(fn (Request $request): bool => str_contains($request->url(), $needle))->values()->map(fn (array $pair): Request => $pair[0])->all();
    }

    private function accept(string $batch = 'batch-1'): void
    {
        $this->fakeOnly([self::TY . '/*' => Http::response(['batchRequestId' => $batch])]);
    }

    /** A later Http::fake() adds stubs behind the earlier ones; a test that changes what the channel says starts from a clean fake. */
    private function fakeOnly(array $stubs): void
    {
        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    /** Trendyol as it really behaves: every POST is accepted with a batch id, and the batch is read later with the outcome the test chose. */
    private function fakeBatches(string $outcome, string $reason = 'Product is locked'): void
    {
        $batches = [];
        $this->fakeOnly([self::TY . '/*' => function (Request $request) use (&$batches, $outcome, $reason) {
            if ($request->method() === 'POST') {
                $id = 'batch-' . (count($batches) + 1);
                $batches[$id] = $request->data()['items'];

                return Http::response(['batchRequestId' => $id]);
            }
            $id = basename((string) parse_url($request->url(), PHP_URL_PATH));
            $items = array_map(static fn (array $item): array => ['requestItem' => $item, 'status' => $outcome, 'failureReasons' => $outcome === 'FAILED' ? [$reason] : []], $batches[$id] ?? []);

            return Http::response(['batchRequestId' => $id, 'itemCount' => count($items), 'items' => $items]);
        }]);
    }

    // ---- stock ------------------------------------------------------------------------------------------

    public function test_the_quantity_is_what_is_sellable_less_open_orders_and_the_safety_buffer(): void
    {
        $this->stock(20);
        $mapping = $this->listing(['safety_buffer' => 2]);
        app(ReservationService::class)->reserve('order-1', $this->own->id, self::PRODUCT, '', 3);
        $this->accept();

        $result = $this->publisher->publishStock($this->account);

        $this->assertSame('ok', $result['result']);
        $this->assertSame(['considered' => 1, 'sent' => 1, 'accepted' => 1, 'failed' => 0, 'dry_run' => false, 'skipped' => []], $result['stats']);
        $this->assertSame(['items' => [['barcode' => 'BC-1', 'quantity' => 15]]], $this->sentTo('price-and-inventory')[0]->data());
        $mapping->refresh();
        $this->assertSame(15, $mapping->last_published_quantity);
        $this->assertSame(['queued', 'batch-1'], [$mapping->snapshot['publish']['stock']['state'], $mapping->snapshot['publish']['stock']['ref']], 'Accepted is queued, not applied.');
    }

    public function test_the_cap_limits_what_is_offered(): void
    {
        $this->stock(500);
        $this->listing(['max_publish_quantity' => 25]);
        $this->accept();

        $this->publisher->publishStock($this->account);

        $this->assertSame(25, $this->sentTo('price-and-inventory')[0]->data()['items'][0]['quantity']);
    }

    public function test_stock_nobody_vouches_for_is_never_offered(): void
    {
        $this->listing();
        Http::fake();

        $none = $this->publisher->publishStock($this->account);
        $this->stock(20, 'unknown');
        $unknown = $this->publisher->publishStock($this->account);
        $this->stock(20, 'confirmed', now()->subHours(5));
        $stale = $this->publisher->publishStock($this->account);

        $this->assertSame(['stock_not_confirmed:unknown' => 1], $none['stats']['skipped']);
        $this->assertSame(['stock_not_confirmed:unknown' => 1], $unknown['stats']['skipped']);
        $this->assertSame(['stock_not_confirmed:stale' => 1], $stale['stats']['skipped'], 'A confirmed figure that has not been refreshed for hours is stale.');
        Http::assertNothingSent();
    }

    public function test_amazon_held_stock_and_a_location_not_meant_for_the_channel_are_never_used(): void
    {
        $this->stock(20);
        $fba = $this->listing(['fulfillment_mode' => 'fba']);
        $fbaLocation = app(InventoryLocationService::class)->ensureFbaLocation($this->amazonAccount());
        $other = $this->listing(['listing_key' => 'BC-2', 'external_barcode' => 'BC-2', 'stock_location_id' => $fbaLocation->id]);
        $nowhere = $this->listing(['listing_key' => 'BC-3', 'external_barcode' => 'BC-3', 'stock_location_id' => null]);
        Http::fake();

        $result = $this->publisher->publishStock($this->account);

        $this->assertSame(['fba_listing' => 1, 'location_not_for_this_channel' => 1, 'no_stock_source' => 1], $result['stats']['skipped']);
        Http::assertNothingSent();
    }

    public function test_nothing_is_published_for_a_listing_nobody_opted_in(): void
    {
        $this->stock(20);
        $this->listing(['inventory_publish' => false]);
        Http::fake();

        $result = $this->publisher->publishStock($this->account);

        $this->assertSame(0, $result['stats']['considered']);
        Http::assertNothingSent();
    }

    public function test_an_account_policy_gives_the_source_and_the_buffer_of_an_opted_in_listing(): void
    {
        $this->stock(20);
        $this->listing(['inventory_publish' => true, 'stock_location_id' => null]);
        InventorySourcePolicy::create(['scope' => 'account', 'account_id' => $this->account->id, 'stock_location_id' => $this->own->id, 'safety_buffer' => 4, 'is_active' => true]);
        $this->accept();

        $this->publisher->publishStock($this->account);

        $this->assertSame(16, $this->sentTo('price-and-inventory')[0]->data()['items'][0]['quantity']);
    }

    public function test_an_account_policy_never_opts_a_listing_in(): void
    {
        $this->stock(20);
        $this->listing(['inventory_publish' => false, 'stock_location_id' => null]);
        InventorySourcePolicy::create(['scope' => 'account', 'account_id' => $this->account->id, 'stock_location_id' => $this->own->id, 'safety_buffer' => 4, 'is_active' => true]);
        Http::fake();

        $result = $this->publisher->publishStock($this->account);

        $this->assertSame(0, $result['stats']['considered']);
        Http::assertNothingSent();
    }

    public function test_the_same_quantity_is_not_sent_again_while_it_is_awaiting_its_result_or_already_shown(): void
    {
        $this->stock(20);
        $mapping = $this->listing();
        $this->accept();

        $this->publisher->publishStock($this->account);
        $queued = $this->publisher->publishStock($this->account);
        $this->assertSame(['awaiting_result' => 1], $queued['stats']['skipped']);

        $this->travel(2)->hours();
        $this->stock(20);
        $mapping->refresh()->forceFill(['remote_quantity' => 20])->save();
        $shown = $this->publisher->publishStock($this->account);
        $this->assertSame(['unchanged' => 1], $shown['stats']['skipped']);
        $this->assertCount(1, $this->sentTo('price-and-inventory'));
    }

    public function test_a_quantity_the_channel_shows_differently_is_sent_again(): void
    {
        $this->stock(20);
        $mapping = $this->listing();
        $this->accept();
        $this->publisher->publishStock($this->account);
        $this->travel(2)->hours();
        $this->stock(20);
        $mapping->refresh()->forceFill(['remote_quantity' => 7])->save();

        $second = $this->publisher->publishStock($this->account);

        $this->assertSame(1, $second['stats']['accepted']);
        $this->assertCount(2, $this->sentTo('price-and-inventory'));
    }

    public function test_a_change_the_channel_keeps_refusing_is_left_for_a_person_after_three_tries(): void
    {
        $this->stock(20);
        $mapping = $this->listing();
        Http::fake([self::TY . '/*' => Http::response(['errors' => [['key' => 'invalid.barcode', 'message' => 'Barcode not found']]], 400)]);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $result = $this->publisher->publishStock($this->account);
        }

        $this->assertCount(3, $this->sentTo('price-and-inventory'), 'The fourth cycle sends nothing.');
        $this->assertSame(['failed_repeatedly' => 1], $result['stats']['skipped']);
        $state = $mapping->refresh()->snapshot['publish']['stock'];
        $this->assertSame(['failed', 3], [$state['state'], $state['failures']]);
        $this->assertStringContainsString('Barcode not found', $state['error']);
        $this->assertNull($mapping->last_published_quantity, 'A refused change is not remembered as published.');
    }

    public function test_a_change_the_channel_accepts_and_then_reports_failed_is_left_for_a_person_after_three_tries(): void
    {
        $this->stock(20);
        $mapping = $this->listing();
        $this->fakeBatches('FAILED');

        for ($cycle = 1; $cycle <= 5; $cycle++) {
            $result = $this->publisher->publishStock($this->account);
            $this->publisher->settle($this->account);
        }

        $this->assertCount(3, $this->sentTo('price-and-inventory'), 'The accepted-then-failed value is sent three times and then left alone.');
        $this->assertSame(['failed_repeatedly' => 1], $result['stats']['skipped']);
        $state = $mapping->refresh()->snapshot['publish']['stock'];
        $this->assertSame(['failed', 3, false], [$state['state'], $state['failures'], $state['transient'] ?? false]);
    }

    public function test_a_value_the_channel_finally_applies_starts_its_count_over(): void
    {
        $this->stock(20);
        $mapping = $this->listing();
        $this->fakeBatches('FAILED');
        foreach ([1, 2] as $cycle) {
            $this->publisher->publishStock($this->account);
            $this->publisher->settle($this->account);
        }
        $this->assertSame(2, $mapping->refresh()->snapshot['publish']['stock']['failures']);

        $this->fakeBatches('SUCCESS');
        $this->publisher->publishStock($this->account);
        $this->publisher->settle($this->account);

        $state = $mapping->refresh()->snapshot['publish']['stock'];
        $this->assertSame(['applied', 0], [$state['state'], $state['failures']]);
    }

    public function test_an_outage_or_a_dropped_connection_never_counts_against_the_value(): void
    {
        $this->stock(20);
        $mapping = $this->listing();
        Http::fake([self::TY . '/*' => Http::response('Service Unavailable', 503)]);

        for ($cycle = 1; $cycle <= 5; $cycle++) {
            $result = $this->publisher->publishStock($this->account);
        }

        $state = $mapping->refresh()->snapshot['publish']['stock'];
        $this->assertSame(['failed', 0, true], [$state['state'], $state['failures'], $state['transient']], 'Five failing cycles are not five refusals.');
        $this->assertSame(1, $result['stats']['sent'], 'The next cycle still tries.');

        $this->fakeOnly([self::TY . '/*' => fn () => throw new ConnectionException('cURL error 6: Could not resolve host: apigw.trendyol.com')]);
        $this->publisher->publishStock($this->account);
        $this->assertSame(0, $mapping->refresh()->snapshot['publish']['stock']['failures'], 'A request that never left this host says nothing about the value.');

        $this->accept('batch-7');
        $recovered = $this->publisher->publishStock($this->account);
        $this->assertSame(1, $recovered['stats']['accepted'], 'Once the channel is back the value goes out without anyone editing it.');
    }

    public function test_a_price_that_rounds_to_zero_is_never_published(): void
    {
        $mapping = $this->listing(['snapshot' => ['manual_price' => '0.004']]);
        Http::fake();

        $result = $this->publisher->publishPrices($this->account);

        $this->assertSame(['price_not_positive' => 1], $result['stats']['skipped']);
        Http::assertNothingSent();
        $this->assertNull($mapping->refresh()->last_published_price);
    }

    public function test_a_price_is_judged_and_sent_as_the_channel_will_read_it(): void
    {
        $mapping = $this->listing(['snapshot' => ['manual_price' => '49.955']]);
        $this->accept();

        $this->publisher->publishPrices($this->account);

        $this->assertEquals(['items' => [['barcode' => 'BC-1', 'salePrice' => 49.96]]], $this->sentTo('price-and-inventory')[0]->data());
        $this->assertSame('49.960000', $mapping->refresh()->last_published_price);
    }

    public function test_a_dry_run_account_only_rehearses_and_remembers_nothing(): void
    {
        $this->stock(20);
        $mapping = $this->listing();
        $this->account->forceFill(['dry_run' => true])->save();
        Http::fake();

        $result = $this->publisher->publishStock($this->account->fresh());

        $this->assertTrue($result['stats']['dry_run']);
        $this->assertSame(0, $result['stats']['accepted']);
        $this->assertNull($mapping->refresh()->last_published_quantity);
        Http::assertNothingSent();
    }

    public function test_without_the_capability_nothing_is_attempted_and_it_is_a_skip_not_a_failure(): void
    {
        $this->stock(20);
        $this->listing();
        $this->account->forceFill(['capabilities' => ['connection_test', 'read_only_sync']])->save();
        Http::fake();

        $this->assertSame('skipped', $this->publisher->publishStock($this->account->fresh())['result']);
        Http::assertNothingSent();
    }

    // ---- price ------------------------------------------------------------------------------------------

    public function test_only_a_price_a_person_set_is_published(): void
    {
        $mapping = $this->listing();
        $this->accept();

        $result = $this->publisher->publishPrices($this->account);

        $this->assertSame(1, $result['stats']['accepted']);
        $this->assertEquals(['items' => [['barcode' => 'BC-1', 'salePrice' => 49.95]]], $this->sentTo('price-and-inventory')[0]->data());
        $this->assertSame('49.950000', $mapping->refresh()->last_published_price);
    }

    public function test_the_storefront_price_is_not_published_and_a_channel_that_owns_its_price_is_left_alone(): void
    {
        $this->listing(['price_source' => 'internal']);
        $this->listing(['listing_key' => 'BC-2', 'external_barcode' => 'BC-2', 'price_source' => 'channel']);
        $this->listing(['listing_key' => 'BC-3', 'external_barcode' => 'BC-3', 'snapshot' => []]);
        $this->listing(['listing_key' => 'BC-4', 'external_barcode' => 'BC-4', 'snapshot' => ['manual_price' => '0']]);
        Http::fake();

        $result = $this->publisher->publishPrices($this->account);

        $this->assertSame(['storefront_price_not_supported' => 1, 'channel_owns_price' => 1, 'no_manual_price' => 1, 'price_not_positive' => 1], $result['stats']['skipped']);
        Http::assertNothingSent();
    }

    // ---- following up -----------------------------------------------------------------------------------

    public function test_a_batch_result_settles_each_item_and_says_why_one_failed(): void
    {
        $this->stock(20);
        $applied = $this->listing();
        $refused = $this->listing(['listing_key' => 'BC-2', 'external_barcode' => 'BC-2']);
        InventoryLocationBalance::create(['location_id' => $this->own->id, 'product_id' => self::PRODUCT, 'variant_key' => '', 'sku' => 'SKU-2', 'available' => 20, 'quality' => 'manual', 'source_synced_at' => now()]);
        $this->accept('batch-9');
        $this->publisher->publishStock($this->account);
        $this->fakeOnly([self::TY . '/product/*' => Http::response(['batchRequestId' => 'batch-9', 'itemCount' => 2, 'items' => [
            ['requestItem' => ['barcode' => 'BC-1', 'quantity' => 20], 'status' => 'SUCCESS', 'failureReasons' => []],
            ['requestItem' => ['barcode' => 'BC-2', 'quantity' => 20], 'status' => 'FAILED', 'failureReasons' => ['Stock limit exceeded']],
        ]])]);

        $counts = $this->publisher->settle($this->account);

        $this->assertSame(['applied' => 1, 'failed' => 1, 'unconfirmed' => 0, 'pending' => 0, 'unreadable' => 0], $counts);
        $this->assertSame('applied', $applied->refresh()->snapshot['publish']['stock']['state']);
        $failed = $refused->refresh()->snapshot['publish']['stock'];
        $this->assertSame(['failed', 'Stock limit exceeded'], [$failed['state'], $failed['error']]);
    }

    public function test_a_batch_that_can_not_be_read_stays_queued_and_the_other_batches_are_still_followed(): void
    {
        $this->stock(20);
        $first = $this->listing();
        $second = $this->listing(['listing_key' => 'BC-2', 'external_barcode' => 'BC-2']);
        InventoryLocationBalance::create(['location_id' => $this->own->id, 'product_id' => self::PRODUCT, 'variant_key' => '', 'sku' => 'SKU-2', 'available' => 20, 'quality' => 'manual', 'source_synced_at' => now()]);
        foreach ([[$first, 'batch-1'], [$second, 'batch-2']] as [$mapping, $batch]) {
            $mapping->forceFill(['snapshot' => ['publish' => ['stock' => ['state' => 'queued', 'ref' => $batch, 'attempted' => '20', 'at' => now()->toIso8601String(), 'failures' => 0]]]])->save();
        }
        $this->fakeOnly([
            self::TY . '/product/*batch-1' => Http::response('Bad gateway', 502),
            self::TY . '/product/*batch-2' => Http::response(['batchRequestId' => 'batch-2', 'itemCount' => 1, 'items' => [['requestItem' => ['barcode' => 'BC-2', 'quantity' => 20], 'status' => 'SUCCESS', 'failureReasons' => []]]]),
        ]);

        $counts = $this->publisher->settle($this->account);

        $this->assertSame(['applied' => 1, 'failed' => 0, 'unconfirmed' => 0, 'pending' => 0, 'unreadable' => 1], $counts);
        $this->assertSame('queued', $first->refresh()->snapshot['publish']['stock']['state']);
        $this->assertSame('applied', $second->refresh()->snapshot['publish']['stock']['state']);
    }

    public function test_following_up_a_batch_works_with_read_only_access_and_survives_publishing_being_switched_off(): void
    {
        $this->stock(20);
        $mapping = $this->listing();
        $mapping->forceFill(['snapshot' => ['publish' => ['stock' => ['state' => 'queued', 'ref' => 'batch-3', 'attempted' => '20', 'at' => now()->toIso8601String(), 'failures' => 0]]]])->save();
        $this->account->forceFill(['capabilities' => ['connection_test', 'read_only_sync']])->save();
        $this->fakeOnly([self::TY . '/product/*' => Http::response(['batchRequestId' => 'batch-3', 'itemCount' => 1, 'items' => [['requestItem' => ['barcode' => 'BC-1', 'quantity' => 20], 'status' => 'SUCCESS', 'failureReasons' => []]]])]);

        $counts = $this->publisher->settle($this->account->fresh());

        $this->assertSame(1, $counts['applied'], 'Reading a batch result is a read: it needs no publishing switch.');

        $mapping->forceFill(['snapshot' => ['publish' => ['stock' => ['state' => 'queued', 'ref' => 'batch-4', 'attempted' => '20', 'at' => now()->toIso8601String(), 'failures' => 0]]]])->save();
        $this->account->forceFill(['capabilities' => ['connection_test']])->save();

        $counts = $this->publisher->settle($this->account->fresh());

        $this->assertSame(1, $counts['unreadable'], 'With every switch off the batch simply stays queued; nothing throws.');
        $this->assertSame('queued', $mapping->refresh()->snapshot['publish']['stock']['state']);
    }

    public function test_a_batch_still_running_stays_pending(): void
    {
        $this->stock(20);
        $mapping = $this->listing();
        $this->accept('batch-9');
        $this->publisher->publishStock($this->account);
        $this->fakeOnly([self::TY . '/product/*' => Http::response(['batchRequestId' => 'batch-9', 'itemCount' => 1, 'items' => [['requestItem' => ['barcode' => 'BC-1'], 'status' => 'IN_PROGRESS']]])]);

        $this->assertSame(1, $this->publisher->settle($this->account)['pending']);
        $this->assertSame('queued', $mapping->refresh()->snapshot['publish']['stock']['state']);
    }

    public function test_a_channel_with_no_batch_result_is_confirmed_by_reading_the_listing_back(): void
    {
        $amazon = $this->amazonAccount();
        $mapping = SalesChannelProductMapping::create([
            'account_id' => $amazon->id, 'product_id' => self::PRODUCT, 'listing_key' => 'SKU-1', 'external_sku' => 'SKU-1', 'status' => 'mapped', 'last_published_quantity' => 12, 'remote_quantity' => 12,
            'last_seen_at' => now(), 'snapshot' => ['publish' => ['stock' => ['state' => 'queued', 'ref' => 'sub-1', 'attempted' => '12', 'at' => now()->subMinutes(5)->toIso8601String(), 'failures' => 0]]],
        ]);
        $differs = SalesChannelProductMapping::create([
            'account_id' => $amazon->id, 'product_id' => 51, 'listing_key' => 'SKU-2', 'external_sku' => 'SKU-2', 'status' => 'mapped', 'last_published_quantity' => 12, 'remote_quantity' => 3,
            'last_seen_at' => now(), 'snapshot' => ['publish' => ['stock' => ['state' => 'queued', 'ref' => 'sub-2', 'attempted' => '12', 'at' => now()->subHours(3)->toIso8601String(), 'failures' => 0]]],
        ]);

        $counts = $this->publisher->settle($amazon);

        $this->assertSame(['applied' => 1, 'failed' => 0, 'unconfirmed' => 1, 'pending' => 0, 'unreadable' => 0], $counts);
        $this->assertSame('applied', $mapping->refresh()->snapshot['publish']['stock']['state']);
        $this->assertSame('unconfirmed', $differs->refresh()->snapshot['publish']['stock']['state']);
    }
}
