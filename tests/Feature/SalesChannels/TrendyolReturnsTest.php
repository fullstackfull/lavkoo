<?php

namespace Tests\Feature\SalesChannels;

use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\SalesChannelSyncState;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\OperationalCosts\Sales\SaleDocumentReader;
use App\Services\SalesChannels\Support\ChannelOperations;
use App\Services\SalesChannels\Sync\ChannelSyncService;
use App\Services\SalesChannels\Trendyol\TrendyolReturnMapper;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\Support\FakeCurrencyRates;
use Tests\Support\Fixtures\TrendyolFixtures as Trendyol;
use Tests\TestCase;

/** Customer returns: only units the channel has ACCEPTED back are returned, they are set (not added) and several claims on one line add up. */
class TrendyolReturnsTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    private const TY = 'https://apigw.trendyol.com/integration';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR', 'sales_channels.enabled' => true, 'sales_channels.sync.order_backfill_days' => 5]);
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 12:00:00 UTC'));
    }

    private function line(): ExternalOrderLine
    {
        $account = $this->trendyolAccount();
        $mapping = ExternalOrderMapping::create(['account_id' => $account->id, 'sales_channel' => 'trendyol', 'seller_id' => 0, 'external_order_id' => '10234567890', 'external_package_id' => '3012345678', 'status' => 'delivered', 'currency' => 'SAR', 'prices_include_tax' => true, 'purchased_at' => '2026-11-05 09:00:00']);

        return ExternalOrderLine::create(['mapping_id' => $mapping->id, 'external_line_id' => '4400000001', 'sku' => 'SKU-1', 'quantity' => 3, 'quantity_cancelled' => 0, 'line_total' => '300.000000', 'discount_total' => '0', 'tax_total' => '39.130435', 'currency' => 'SAR', 'status' => 'open']);
    }

    public function test_only_accepted_claim_items_count_and_the_outbound_package_is_the_key(): void
    {
        $return = app(TrendyolReturnMapper::class)->map(Trendyol::claim(['Accepted', 'WaitingInAction', 'Rejected', 'Accepted']));

        $this->assertSame(['10234567890', '3012345678', ['4400000001' => 2]], [$return->orderId, $return->packageId, $return->acceptedUnits], 'The return parcel id is not the package we hold.');
        $this->assertSame(['4400000001' => 0], app(TrendyolReturnMapper::class)->map(Trendyol::claim(['Created', 'InAnalysis', 'Cancelled', 'Unresolved']))->acceptedUnits, 'Named, with nothing accepted.');
        $this->assertNull(app(TrendyolReturnMapper::class)->map(['claimId' => 'x']));
    }

    public function test_a_returned_unit_reduces_the_sale_and_reading_the_claim_again_changes_nothing(): void
    {
        $line = $this->line();
        $account = $line->order->account;
        Http::fake([self::TY . '/order/sellers/1234567/claims*' => Http::response(Trendyol::claimsPage([Trendyol::claim(['Accepted'])]))]);
        $sync = app(ChannelSyncService::class);

        $first = $sync->syncReturns($account);
        $this->travel(40)->minutes();
        $second = $sync->syncReturns($account);

        $this->assertSame(['ok', 1, 0], [$first['result'], $first['stats']['applied'], $first['stats']['unchanged']]);
        $this->assertSame(1, $second['stats']['unchanged']);
        $this->assertSame(1, $line->refresh()->quantity_returned);
        $document = app(SaleDocumentReader::class)->forChannelOrder($line->mapping_id);
        $this->assertSame(1, $document->lines[0]->quantityReturned);
        $this->assertTrue($document->lines[0]->refunded->isPositive(), 'The returned unit is refunded revenue.');
    }

    public function test_two_claims_on_one_line_add_up_and_a_claim_that_changes_is_set_not_added(): void
    {
        $line = $this->line();
        $account = $line->order->account;
        Http::fake([self::TY . '/order/sellers/1234567/claims*' => Http::sequence()
            ->push(Trendyol::claimsPage([Trendyol::claim(['Accepted'], 'claim-1'), Trendyol::claim(['Accepted'], 'claim-2')]))
            ->push(Trendyol::claimsPage([Trendyol::claim(['Rejected'], 'claim-1')]))]);
        $sync = app(ChannelSyncService::class);

        $sync->syncReturns($account);
        $this->assertSame(2, $line->refresh()->quantity_returned);

        $this->travel(40)->minutes();
        $sync->syncReturns($account);
        $this->assertSame(1, $line->refresh()->quantity_returned, 'Claim 1 was rejected on a second look: only claim 2 remains.');
    }

    public function test_it_never_exceeds_what_was_sold_and_an_unknown_order_is_counted_and_ignored(): void
    {
        $line = $this->line();
        $account = $line->order->account;
        $oversized = Trendyol::claim(['Accepted', 'Accepted', 'Accepted', 'Accepted', 'Accepted']);
        $stranger = Trendyol::claim(['Accepted'], 'claim-9', ['orderNumber' => 'NOT-HELD', 'orderOutboundPackageId' => 1]);
        Http::fake([self::TY . '/order/sellers/1234567/claims*' => Http::response(Trendyol::claimsPage([$oversized, $stranger]))]);

        $result = app(ChannelSyncService::class)->syncReturns($account);

        $this->assertSame(3, $line->refresh()->quantity_returned);
        $this->assertSame(1, $result['stats']['unknown_order']);
    }

    public function test_a_claim_for_an_order_that_arrives_later_is_read_again_and_applied_then(): void
    {
        $account = $this->trendyolAccount();
        $claim = Trendyol::claim(['Accepted'], 'claim-late', ['claimDate' => Trendyol::CREATED_MS, 'lastModifiedDate' => Trendyol::CREATED_MS + 3_600_000]);
        Http::fake([self::TY . '/order/sellers/1234567/claims*' => Http::response(Trendyol::claimsPage([$claim]))]);
        $sync = app(ChannelSyncService::class);

        $first = $sync->syncReturns($account);
        $state = SalesChannelSyncState::where(['account_id' => $account->id, 'resource' => 'returns'])->firstOrFail();

        $this->assertSame(1, $first['stats']['unknown_order']);
        $this->assertSame('2026-11-05T09:00:00+00:00', $state->watermark->utc()->toIso8601String(), 'The watermark stays at the claim whose order is not held yet, not at the newest claim.');

        $mapping = ExternalOrderMapping::create(['account_id' => $account->id, 'sales_channel' => 'trendyol', 'seller_id' => 0, 'external_order_id' => '10234567890', 'external_package_id' => '3012345678', 'status' => 'delivered', 'currency' => 'SAR', 'purchased_at' => '2026-11-05 09:00:00']);
        $line = ExternalOrderLine::create(['mapping_id' => $mapping->id, 'external_line_id' => '4400000001', 'sku' => 'SKU-1', 'quantity' => 3, 'quantity_cancelled' => 0, 'line_total' => '300.000000', 'currency' => 'SAR', 'status' => 'open']);
        $this->travel(40)->minutes();
        $second = $sync->syncReturns($account);

        $this->assertSame(1, $second['stats']['applied'], 'The order has arrived: the claim is applied now.');
        $this->assertSame(1, $line->refresh()->quantity_returned);
    }

    public function test_the_claims_request_asks_for_a_window_and_pages_on(): void
    {
        $line = $this->line();
        Http::fake([self::TY . '/order/sellers/1234567/claims*' => Http::sequence()->push(Trendyol::claimsPage([], 0, 2))->push(Trendyol::claimsPage([], 1, 2))]);

        app(ChannelSyncService::class)->syncReturns($line->order->account);

        $requests = Http::recorded()->values()->map(fn (array $pair) => $pair[0])->all();
        $this->assertCount(2, $requests);
        parse_str((string) parse_url($requests[1]->url(), PHP_URL_QUERY), $query);
        $this->assertSame(['1', '200'], [$query['page'], $query['size']]);
    }
}
