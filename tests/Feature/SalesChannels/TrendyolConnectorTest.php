<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\ConnectionStatus;
use App\Enums\SalesChannels\FulfillmentMode;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelApiLog;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\ChannelAccountService;
use App\Services\SalesChannels\Data\PublishUpdate;
use App\Services\SalesChannels\Data\RemoteFinancialEntry;
use App\Services\SalesChannels\Data\ShipmentConfirmation;
use App\Services\SalesChannels\Exceptions\ChannelPermissionDenied;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Support\ChannelOperations;
use App\Services\SalesChannels\Trendyol\TrendyolConnector;
use App\Services\SalesChannels\Trendyol\TrendyolFinanceMapper;
use App\Services\SalesChannels\Trendyol\TrendyolOrderMapper;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\Fixtures\TrendyolFixtures as Trendyol;
use Tests\TestCase;

/**
 * Every response is a fixture shaped after Trendyol's published models; stray requests are refused, so no test can reach Trendyol. They prove how the connector behaves
 * against those shapes, not that a live seller account works: that needs the real keys and a connection test (ideally on stage first).
 */
class TrendyolConnectorTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private const API = 'https://apigw.trendyol.com/integration';
    private const SELLER_PATH = '/sellers/' . Trendyol::SELLER;

    private TrendyolConnector $trendyol;

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 12:00:00 UTC'));
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        $this->trendyol = app(TrendyolConnector::class);
        $this->account = app(ChannelAccountService::class)->create(
            ['channel' => 'trendyol', 'name' => 'Trendyol KSA', 'external_account_id' => Trendyol::SELLER, 'currency' => 'SAR', 'settings' => ['storefront_code' => 'SA', 'integration_name' => 'PharmacyHub', 'carrier_codes' => ['smsa' => 'SMSA']]],
            ['api_key' => 'key-abc-123', 'api_secret' => 'secret-xyz-789'],
            adminId: 1,
        );
        $this->account->forceFill([
            'enabled' => true, 'write_enabled' => true, 'dry_run' => false,
            'capabilities' => ['connection_test', 'read_only_sync', 'product_sync', 'order_import', 'inventory_publishing', 'price_publishing', 'fulfillment_dispatch', 'financial_reconciliation'],
            'capability_approvals' => ['inventory_publishing' => ['by' => 1], 'price_publishing' => ['by' => 1], 'fulfillment_dispatch' => ['by' => 1], 'webhook_management' => ['by' => 1]],
        ])->save();
    }

    private function ms(string $time): int
    {
        return CarbonImmutable::parse($time . ' UTC')->getTimestamp() * 1000;
    }

    /** @return list<Request> */
    private function sentTo(string $needle): array
    {
        return Http::recorded(fn (Request $request): bool => str_contains($request->url(), $needle))->values()->map(fn (array $pair): Request => $pair[0])->all();
    }

    /** @return array<string, string> */
    private function query(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    // ---- request shape ----------------------------------------------------------------------------------

    public function test_every_call_carries_basic_credentials_the_user_agent_and_the_storefront(): void
    {
        Http::fake([self::API . '/*' => Http::response(Trendyol::stream([]))]);

        $this->trendyol->orders($this->account, CarbonImmutable::parse('2026-11-05T00:00:00Z'), null);

        $request = $this->sentTo('/orders/stream')[0];
        $this->assertSame('Basic ' . base64_encode('key-abc-123:secret-xyz-789'), $request->header('Authorization')[0]);
        $this->assertSame(Trendyol::SELLER . ' - PharmacyHub', $request->header('User-Agent')[0]);
        $this->assertSame('SA', $request->header('storeFrontCode')[0]);
        $this->assertSame('ar', $request->header('Accept-Language')[0]);
    }

    public function test_the_stage_account_uses_the_stage_host_and_a_turkish_account_sends_no_storefront(): void
    {
        $this->account->forceFill(['environment' => 'sandbox', 'settings' => array_replace($this->account->settings, ['storefront_code' => ''])])->save();
        Http::fake(['https://stageapigw.trendyol.com/*' => Http::response(Trendyol::stream([]))]);

        $this->trendyol->orders($this->account->fresh(), CarbonImmutable::parse('2026-11-05T00:00:00Z'), null);

        $request = $this->sentTo('stageapigw.trendyol.com/integration/order/sellers/' . Trendyol::SELLER . '/orders/stream')[0];
        $this->assertFalse($request->hasHeader('storeFrontCode'));
    }

    public function test_no_secret_reaches_the_log(): void
    {
        Http::fake([self::API . '/*' => Http::response(Trendyol::stream([]))]);

        $this->trendyol->orders($this->account, CarbonImmutable::parse('2026-11-05T00:00:00Z'), null);

        $stored = json_encode(SalesChannelApiLog::all()->toArray());
        foreach (['key-abc-123', 'secret-xyz-789', base64_encode('key-abc-123:secret-xyz-789')] as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }
        $this->assertNotSame(0, SalesChannelApiLog::count());
    }

    public function test_a_401_means_the_key_or_secret_is_wrong_and_a_403_names_the_usual_causes(): void
    {
        Http::fake([self::API . '/*' => Http::response(['status' => 401, 'exception' => 'ClientApiAuthenticationException'], 401)]);
        try {
            $this->trendyol->orders($this->account, CarbonImmutable::parse('2026-11-05T00:00:00Z'), null);
            $this->fail('A refused key was treated as success.');
        } catch (ChannelUnavailableException $exception) {
            $this->assertSame(ChannelUnavailableException::AUTH_NEEDED, $exception->reason);
        }
    }

    public function test_a_403_is_reported_with_its_usual_causes(): void
    {
        Http::fake([self::API . '/*' => Http::response('', 403)]);

        $this->expectException(ChannelPermissionDenied::class);
        $this->expectExceptionMessage('User-Agent');
        $this->trendyol->orders($this->account, CarbonImmutable::parse('2026-11-05T00:00:00Z'), null);
    }

    // ---- order mapping ----------------------------------------------------------------------------------

    public function test_a_package_is_mapped_with_its_money_and_nothing_personal(): void
    {
        $order = app(TrendyolOrderMapper::class)->map(Trendyol::package(), $this->account);

        $this->assertSame('10234567890', $order->orderId);
        $this->assertSame('3012345678', $order->packageId);
        $this->assertSame(ChannelOrderStatus::New, $order->status);
        $this->assertSame(FulfillmentMode::MerchantManaged, $order->fulfillmentMode);
        $this->assertSame('trendyol_pays', $order->fulfillmentChannelRaw);
        $this->assertSame('SAR', $order->currency);
        $this->assertSame('200.000000', $order->itemsTotal->toStorage());
        $this->assertSame('20.000000', $order->discountTotal->toStorage(), 'Only the seller\'s own discount reduces revenue; the Trendyol-funded one is reimbursed.');
        $this->assertSame('23.478261', $order->taxTotal->toStorage(), 'The VAT contained in 180.00 at 15 %.');
        $this->assertSame('170.00', $order->orderTotal->toDisplay());
        $this->assertTrue($order->pricesIncludeTax);
        $this->assertSame('2026-11-05T07:50:00+00:00', $order->purchasedAt->toIso8601String(), 'orderDate is documented as GMT+3: the offset is removed.');
        $this->assertSame('2026-11-05T08:00:00+00:00', $order->updatedAt->toIso8601String());
        $this->assertSame(['city' => 'Riyadh', 'country' => 'SA'], $order->destination);
        $this->assertSame('Aramex', $order->carrier);
        $this->assertSame('7440123456', $order->trackingNumber);

        $line = $order->lines[0];
        $this->assertSame(['4400000001', 'SKU-1', '6281234567890', '998877', 2, 0], [$line->lineId, $line->sku, $line->barcode, $line->externalProductId, $line->quantity, $line->quantityCancelled]);
        $this->assertSame('10.000000', $line->snapshot['ty_discount'], 'Two units at 5.00 each.');

        $stored = json_encode([$order->snapshot, $line->snapshot]);
        foreach (['Test', 'Buyer', 'buyer-token', '1 Test Street', '+966500000000', '1000000000', '300000000000003', 'RRRD1234'] as $personal) {
            $this->assertStringNotContainsString($personal, $stored, "{$personal} must not be kept.");
        }
    }

    public function test_the_seller_pays_agreement_is_read_per_package_and_uses_the_number_the_seller_pushed(): void
    {
        $order = app(TrendyolOrderMapper::class)->map(Trendyol::package(['whoPays' => 1, 'cargoSenderNumber' => 'OWN-777']), $this->account);

        $this->assertSame('seller_pays', $order->fulfillmentChannelRaw);
        $this->assertSame('OWN-777', $order->trackingNumber);
    }

    public function test_every_documented_status_maps_and_an_unknown_one_goes_to_review(): void
    {
        $mapper = app(TrendyolOrderMapper::class);
        $expected = [
            'Awaiting' => ChannelOrderStatus::Pending, 'Verified' => ChannelOrderStatus::Pending, 'Created' => ChannelOrderStatus::New,
            'Picking' => ChannelOrderStatus::Processing, 'Invoiced' => ChannelOrderStatus::Processing, 'Shipped' => ChannelOrderStatus::Shipped,
            'AtCollectionPoint' => ChannelOrderStatus::Shipped, 'Delivered' => ChannelOrderStatus::Delivered, 'Returned' => ChannelOrderStatus::Returned,
            'UnDelivered' => ChannelOrderStatus::Failed, 'Cancelled' => ChannelOrderStatus::Cancelled, 'UnSupplied' => ChannelOrderStatus::Cancelled,
            'UnPacked' => ChannelOrderStatus::Cancelled, 'BrandNewStatus' => ChannelOrderStatus::AwaitingReview,
        ];
        foreach ($expected as $trendyol => $ours) {
            $this->assertSame($ours, $mapper->status($trendyol), $trendyol);
        }
        $this->assertFalse($mapper->status('Awaiting')->allowsFulfilment(), 'A package whose payment is not confirmed is never fulfilled.');
    }

    public function test_a_replaced_package_is_terminal_and_its_lines_are_not_sold_twice(): void
    {
        $split = app(TrendyolOrderMapper::class)->map(Trendyol::package(['status' => 'UnPacked', 'shipmentPackageStatus' => 'UnPacked']), $this->account);
        $this->assertSame(2, $split->lines[0]->quantityCancelled);

        $remainder = app(TrendyolOrderMapper::class)->map(Trendyol::package(['shipmentPackageId' => 3012345999, 'createdBy' => 'split', 'originPackageIds' => [3012345678]]), $this->account);
        $this->assertSame('3012345678', $remainder->replacesPackageId);
        $this->assertSame(0, $remainder->lines[0]->quantityCancelled);
    }

    public function test_package_discounts_are_shared_out_exactly_when_lines_carry_no_unit_detail(): void
    {
        $lines = [
            Trendyol::line(['lineId' => 1, 'quantity' => 1, 'lineGrossAmount' => 100.0, 'discountDetails' => []]),
            Trendyol::line(['lineId' => 2, 'quantity' => 2, 'lineGrossAmount' => 100.0, 'discountDetails' => []]),
        ];

        $order = app(TrendyolOrderMapper::class)->map(Trendyol::package(['lines' => $lines, 'packageSellerDiscount' => 10.0, 'packageTyDiscount' => 0.0, 'packageTotalPrice' => 290.0]), $this->account);

        $this->assertSame(['3.333333', '6.666667'], [$order->lines[0]->discount->toStorage(), $order->lines[1]->discount->toStorage()]);
        $this->assertSame('10.000000', $order->discountTotal->toStorage(), 'The parts add up to the package figure to the last digit.');
        $this->assertSame('package_allocated', $order->snapshot['discount_source']);
    }

    public function test_cash_on_delivery_and_unexplained_flags_are_carried_without_guessing(): void
    {
        $mapper = app(TrendyolOrderMapper::class);

        $this->assertTrue($mapper->map(Trendyol::package(['isCod' => true]), $this->account)->isCod);
        foreach (['is4P', '3pByTrendyol', 'micro'] as $flag) {
            $this->assertSame(FulfillmentMode::Unknown, $mapper->map(Trendyol::package([$flag => true]), $this->account)->fulfillmentMode, "{$flag} has no documented meaning for a Gulf seller, so no rule may route it.");
        }
    }

    public function test_history_gives_the_shipped_and_delivered_times_in_utc(): void
    {
        $package = Trendyol::package(['status' => 'Delivered', 'shipmentPackageStatus' => 'Delivered', 'packageHistories' => [
            ['createdDate' => $this->ms('2026-11-05 08:00:00'), 'status' => 'Created'],
            ['createdDate' => $this->ms('2026-11-06 09:00:00'), 'status' => 'Shipped'],
            ['createdDate' => $this->ms('2026-11-06 20:00:00'), 'status' => 'Delivered'],
        ]]);

        $order = app(TrendyolOrderMapper::class)->map($package, $this->account);

        $this->assertSame('2026-11-06T09:00:00+00:00', $order->shippedAt->toIso8601String());
        $this->assertSame('2026-11-06T20:00:00+00:00', $order->deliveredAt->toIso8601String());
    }

    // ---- reading orders ---------------------------------------------------------------------------------

    public function test_the_stream_is_read_by_last_modified_window_and_continues_with_an_identical_query(): void
    {
        Http::fake([self::API . '/*' => Http::sequence()->push(Trendyol::stream([Trendyol::package()], 'CUR-1'))->push(Trendyol::stream([]))]);

        $first = $this->trendyol->orders($this->account, CarbonImmutable::parse('2026-11-05T00:00:00Z'), null);
        $second = $this->trendyol->orders($this->account, CarbonImmutable::parse('2026-11-05T00:00:00Z'), $first->nextCursor);

        $requests = $this->sentTo('/orders/stream');
        $one = $this->query($requests[0]);
        $two = $this->query($requests[1]);
        $this->assertSame(['200', (string) $this->ms('2026-11-05 00:00:00'), (string) $this->ms('2026-11-06 12:00:00')], [$one['size'], $one['lastModifiedStartDate'], $one['lastModifiedEndDate']]);
        $this->assertArrayNotHasKey('nextCursor', $one);
        $this->assertSame('CUR-1', $two['nextCursor']);
        unset($two['nextCursor']);
        $this->assertSame($one, $two, 'Changing a filter while reusing a cursor is a 400 at Trendyol.');
        $this->assertNull($second->nextCursor);
        $this->assertSame('2026-11-05T08:00:00+00:00', $first->highWatermark->toIso8601String());
    }

    public function test_a_long_gap_is_read_in_windows_of_at_most_fourteen_days(): void
    {
        Http::fake([self::API . '/*' => Http::response(Trendyol::stream([]))]);

        $cursor = null;
        $calls = 0;
        do {
            $page = $this->trendyol->orders($this->account, CarbonImmutable::parse('2026-10-07T12:00:00Z'), $cursor);
            $cursor = $page->nextCursor;
        } while ($cursor !== null && ++$calls < 10);

        $queries = array_map(fn (Request $request): array => $this->query($request), $this->sentTo('/orders/stream'));
        $this->assertCount(3, $queries, '30 days is two full windows and a remainder.');
        foreach ($queries as $query) {
            $this->assertLessThanOrEqual(14 * 86_400_000, (int) $query['lastModifiedEndDate'] - (int) $query['lastModifiedStartDate']);
        }
        $this->assertSame($queries[0]['lastModifiedEndDate'], $queries[1]['lastModifiedStartDate']);
        $this->assertSame((string) $this->ms('2026-11-06 12:00:00'), $queries[2]['lastModifiedEndDate']);
    }

    public function test_history_older_than_trendyol_keeps_is_not_asked_for(): void
    {
        Http::fake([self::API . '/*' => Http::response(Trendyol::stream([]))]);

        $this->trendyol->orders($this->account, CarbonImmutable::parse('2025-01-01T00:00:00Z'), null);

        $start = (int) $this->query($this->sentTo('/orders/stream')[0])['lastModifiedStartDate'];
        $this->assertGreaterThanOrEqual($this->ms('2026-11-06 12:00:00') - 89 * 86_400_000, $start);
    }

    public function test_a_package_without_an_id_is_skipped_and_counted_not_fatal(): void
    {
        Http::fake([self::API . '/*' => Http::response(Trendyol::stream([['orderNumber' => 'X'], Trendyol::package()]))]);

        $page = $this->trendyol->orders($this->account, CarbonImmutable::parse('2026-11-05T00:00:00Z'), null);

        $this->assertCount(1, $page->items);
        $this->assertSame(1, $page->skipped);
    }

    public function test_one_package_is_looked_up_in_order_v2_and_a_missing_one_is_null(): void
    {
        Http::fake([
            self::API . '/order/sellers/' . Trendyol::SELLER . '/v2/orders*' => Http::sequence()->push(Trendyol::stream([Trendyol::package()]))->push(Trendyol::stream([])),
        ]);

        $found = $this->trendyol->order($this->account, '10234567890', '3012345678');
        $missing = $this->trendyol->order($this->account, '10234567890', '3012345678');

        $this->assertSame('3012345678', $found->packageId);
        $this->assertNull($missing);
        $this->assertSame('3012345678', $this->query($this->sentTo('/v2/orders')[0])['shipmentPackageIds']);
    }

    public function test_a_rate_limit_pauses_the_work_instead_of_failing_it(): void
    {
        Http::fake([self::API . '/*' => Http::response(['errors' => [['key' => 'too.many.requests']]], 429)]);

        $this->expectException(\App\Services\SalesChannels\Exceptions\ChannelRateLimitedException::class);
        $this->trendyol->orders($this->account, CarbonImmutable::parse('2026-11-05T00:00:00Z'), null);
    }

    // ---- listings ---------------------------------------------------------------------------------------

    public function test_approved_variants_become_listings_keyed_by_barcode_with_their_state(): void
    {
        $contents = [
            Trendyol::content('BC-1', 'SKU-1'),
            Trendyol::content('BC-2', 'SKU-2', ['onSale' => false]),
            Trendyol::content('BC-3', 'SKU-3', ['locked' => true]),
            Trendyol::content('BC-4', 'SKU-4', ['archived' => true]),
            Trendyol::content('BC-5', 'SKU-5', ['blacklisted' => true]),
        ];
        Http::fake([self::API . '/*' => Http::response(Trendyol::approvedPage($contents))]);

        $page = $this->trendyol->listings($this->account, null);

        $this->assertSame(['on_sale', 'not_on_sale', 'locked', 'archived', 'blacklisted'], array_map(fn ($listing): ?string => $listing->status, $page->items));
        $first = $page->items[0];
        $this->assertSame(['BC-1', 'BC-1', 'SKU-1', '998877', 'MAIN-1'], [$first->listingKey, $first->barcode, $first->sku, $first->externalId, $first->parentId]);
        $this->assertSame('99.50', $first->price->toDisplay());
        $this->assertSame(14, $first->quantity);
        $this->assertSame('phase', array_key_first($page->nextCursor));
        $this->assertSame('unapproved', $page->nextCursor['phase'], 'Unapproved products are read after the approved ones.');
    }

    public function test_pages_are_followed_then_the_token_beyond_the_ten_thousand_window_then_the_unapproved_products(): void
    {
        Http::fake([self::API . '/*' => Http::sequence()
            ->push(Trendyol::approvedPage([Trendyol::content('BC-1')], 0, 3))
            ->push(Trendyol::approvedPage([Trendyol::content('BC-2')], 99, 150, 'TOKEN-1'))
            ->push(Trendyol::approvedPage([Trendyol::content('BC-3')], 0, 1))
            ->push(['totalElements' => 1, 'totalPages' => 1, 'page' => 0, 'size' => 100, 'content' => [Trendyol::unapprovedProduct('BC-9', 'rejected')]])]);

        $one = $this->trendyol->listings($this->account, null);
        $this->assertSame(['phase' => 'approved', 'page' => 1, 'from' => null], $one->nextCursor);

        $window = $this->trendyol->listings($this->account, ['phase' => 'approved', 'page' => 99]);
        $this->assertSame(['phase' => 'approved', 'token' => 'TOKEN-1', 'from' => null], $window->nextCursor);

        $this->trendyol->listings($this->account, ['phase' => 'approved', 'token' => 'TOKEN-1']);
        $tokenQuery = $this->query($this->sentTo('/products/approved')[2]);
        $this->assertSame('TOKEN-1', $tokenQuery['nextPageToken']);
        $this->assertArrayNotHasKey('page', $tokenQuery, 'A page token is sent without a page number.');

        $unapproved = $this->trendyol->listings($this->account, ['phase' => 'unapproved', 'page' => 0]);
        $this->assertNull($unapproved->nextCursor);
        $this->assertSame('rejected', $unapproved->items[0]->status);
        $this->assertSame(['Image quality'], $unapproved->items[0]->snapshot['reject_reasons']);
    }

    public function test_an_incremental_listing_read_asks_for_what_changed(): void
    {
        Http::fake([self::API . '/*' => Http::response(Trendyol::approvedPage([]))]);

        $this->trendyol->listings($this->account, ['phase' => 'approved', 'page' => 0, 'from' => $this->ms('2026-11-01 00:00:00')]);

        $query = $this->query($this->sentTo('/products/approved')[0]);
        $this->assertSame([(string) $this->ms('2026-11-01 00:00:00'), 'VARIANT_MODIFIED_DATE', '100'], [$query['startDate'], $query['dateQueryType'], $query['size']]);
    }

    // ---- finances ---------------------------------------------------------------------------------------

    public function test_a_sale_row_gives_the_sale_and_the_commission_as_a_cost(): void
    {
        $entries = app(TrendyolFinanceMapper::class)->settlement(Trendyol::settlement(), 'Sale', $this->account);

        $this->assertSame([RemoteFinancialEntry::SALE, RemoteFinancialEntry::FEE], array_map(fn ($entry): string => $entry->category, $entries));
        $this->assertSame('145.000000', $entries[0]->amount->toStorage());
        $this->assertSame(CostComponent::MarketplaceCommission, $entries[1]->component);
        $this->assertSame('25.000000', $entries[1]->amount->toStorage(), 'A commission of -25.00 on the statement is a cost of 25.00.');
        $this->assertSame(['10234567890', '3012345678'], [$entries[1]->orderId, $entries[1]->packageId]);
    }

    public function test_a_return_row_gives_the_refund_and_gives_commission_back(): void
    {
        $row = Trendyol::settlement('row-2', 25.0, 0.0, ['transactionType' => 'Return', 'debt' => 170.0, 'sellerRevenue' => -145.0]);

        $entries = app(TrendyolFinanceMapper::class)->settlement($row, 'Return', $this->account);

        $this->assertSame(RemoteFinancialEntry::REFUND, $entries[0]->category);
        $this->assertSame('170.000000', $entries[0]->amount->toStorage());
        $this->assertSame('-25.000000', $entries[1]->amount->toStorage(), 'The commission returned is a negative cost.');
    }

    public function test_a_fact_has_the_same_identity_whatever_id_trendyol_gave_the_row(): void
    {
        $mapper = app(TrendyolFinanceMapper::class);

        $before = $mapper->settlement(Trendyol::settlement('old-id'), 'Sale', $this->account);
        $after = $mapper->settlement(Trendyol::settlement('re-issued-id'), 'Sale', $this->account);

        $this->assertSame(array_map(fn ($e): string => $e->identity, $before), array_map(fn ($e): string => $e->identity, $after));
        $this->assertNotSame($before[0]->identity, $before[1]->identity, 'The sale and its commission are two facts.');
    }

    public function test_other_financials_are_classified_and_the_unknown_is_kept_for_a_person(): void
    {
        $mapper = app(TrendyolFinanceMapper::class);
        $row = fn (string $description, float $debt = 40.0, float $credit = 0.0): array => ['id' => 'x', 'transactionDate' => Trendyol::CREATED_MS, 'description' => $description, 'debt' => $debt, 'credit' => $credit, 'orderNumber' => null, 'shipmentPackageId' => null];

        $cargo = $mapper->other($row('Cargo invoice 55'), 'DeductionInvoices', $this->account)[0];
        $this->assertSame([RemoteFinancialEntry::FEE, CostComponent::MarketplaceShipping, '40.000000'], [$cargo->category, $cargo->component, $cargo->amount->toStorage()]);

        $mystery = $mapper->other($row('Something nobody described'), 'DeductionInvoices', $this->account)[0];
        $this->assertSame(RemoteFinancialEntry::UNCLASSIFIED, $mystery->category);

        $this->assertSame(RemoteFinancialEntry::DUPLICATE_OF_ANOTHER_ENTRY, $mapper->other($row('Commission', 25.0), 'CommissionInvoice', $this->account)[0]->category, 'The commission is already counted from the sale rows.');
        $this->assertSame(RemoteFinancialEntry::PAYOUT, $mapper->other($row('Payment', 0.0, 900.0), 'PaymentOrder', $this->account)[0]->category);
        $this->assertSame('-15.000000', $mapper->other($row('Credit', 0.0, 15.0), 'CreditNote', $this->account)[0]->amount->toStorage(), 'Money credited to us is a negative cost.');
        $this->assertSame(RemoteFinancialEntry::UNCLASSIFIED, $mapper->other($row('x'), 'SomeFutureType', $this->account)[0]->category);
    }

    public function test_the_statement_is_walked_type_by_type_and_window_by_window(): void
    {
        Http::fake([self::API . '/*' => Http::response(Trendyol::statement([]))]);

        $cursor = null;
        $calls = 0;
        do {
            $page = $this->trendyol->finances($this->account, CarbonImmutable::parse('2026-10-01T00:00:00Z'), CarbonImmutable::parse('2026-11-06T00:00:00Z'), $cursor);
            $cursor = $page->nextCursor;
        } while ($cursor !== null && ++$calls < 40);

        $requests = array_merge($this->sentTo('/settlements'), $this->sentTo('/otherfinancials'));
        $this->assertCount(18, $requests, '36 days is 3 windows of at most 15 days; each has 2 settlement types and 4 other types.');
        $first = array_map(fn (Request $request): array => $this->query($request), $this->sentTo('/settlements'));
        $this->assertSame(['Sale', 'Return'], [$first[0]['transactionType'], $first[1]['transactionType']]);
        $this->assertSame($first[0]['endDate'], $first[2]['startDate'], 'The next window starts where the previous one ended.');
        foreach ($first as $query) {
            $this->assertLessThanOrEqual(15 * 86_400_000, (int) $query['endDate'] - (int) $query['startDate']);
            $this->assertSame('1000', $query['size']);
        }
    }

    public function test_more_pages_of_one_type_are_followed_first(): void
    {
        Http::fake([self::API . '/*' => Http::response(Trendyol::statement([], 0, 3))]);

        $page = $this->trendyol->finances($this->account, CarbonImmutable::parse('2026-11-01T00:00:00Z'), CarbonImmutable::parse('2026-11-06T00:00:00Z'), null);

        $this->assertSame(['stage' => 'settlements', 'type' => 0, 'page' => 1], array_intersect_key($page->nextCursor, array_flip(['stage', 'type', 'page'])));
    }

    // ---- publishing -------------------------------------------------------------------------------------

    private function update(int $mappingId = 1, ?string $barcode = '6281234567890', ?int $quantity = null, ?string $price = null, ?string $listPrice = null): PublishUpdate
    {
        return new PublishUpdate($mappingId, $barcode ?? 'none', 'SKU-1', $barcode, $quantity, $price === null ? null : Money::of($price), $listPrice === null ? null : Money::of($listPrice), 'SAR');
    }

    public function test_a_quantity_is_published_by_barcode_and_returns_a_batch_to_follow(): void
    {
        Http::fake([self::API . '/*' => Http::response(['batchRequestId' => 'fa75dfd5-batch'])]);

        $result = $this->trendyol->publishStock($this->account, [$this->update(7, quantity: 20)]);

        $this->assertSame([7 => 'fa75dfd5-batch'], $result->accepted, 'Accepted means queued, not applied.');
        $request = $this->sentTo('/inventory/sellers/' . Trendyol::SELLER . '/products/price-and-inventory')[0];
        $this->assertSame(['items' => [['barcode' => '6281234567890', 'quantity' => 20]]], $request->data());
    }

    public function test_a_price_is_published_with_only_the_fields_given(): void
    {
        Http::fake([self::API . '/*' => Http::response(['batchRequestId' => 'b-2'])]);

        $this->trendyol->publishPrices($this->account, [$this->update(1, price: '49.95'), $this->update(2, barcode: 'BC-2', price: '59.90', listPrice: '69.90')]);

        $items = $this->sentTo('price-and-inventory')[0]->data()['items'];
        $this->assertEquals([['barcode' => '6281234567890', 'salePrice' => 49.95], ['barcode' => 'BC-2', 'salePrice' => 59.9, 'listPrice' => 69.9]], $items);
    }

    public function test_an_update_trendyol_would_misread_is_never_sent(): void
    {
        Http::fake([self::API . '/*' => Http::response(['batchRequestId' => 'b-3'])]);

        $result = $this->trendyol->publishPrices($this->account, [
            $this->update(1, price: '0'),
            $this->update(2, price: '50', listPrice: '40'),
            $this->update(3, barcode: null, price: '50'),
            $this->update(4),
        ]);
        $stock = $this->trendyol->publishStock($this->account, [$this->update(5, quantity: 20_001), $this->update(6, quantity: -1)]);

        $this->assertSame([], $result->accepted);
        $this->assertStringContainsString('zero price', $result->failed[1]);
        $this->assertStringContainsString('list price', $result->failed[2]);
        $this->assertStringContainsString('no barcode', $result->failed[3]);
        $this->assertSame('No price to publish.', $result->failed[4]);
        $this->assertCount(2, $stock->failed);
        Http::assertNothingSent();
    }

    public function test_more_than_a_thousand_items_go_in_separate_requests(): void
    {
        Http::fake([self::API . '/*' => Http::sequence()->push(['batchRequestId' => 'b-a'])->push(['batchRequestId' => 'b-b'])]);
        $updates = [];
        for ($index = 1; $index <= 1001; $index++) {
            $updates[] = $this->update($index, barcode: "BC-{$index}", quantity: 1);
        }

        $result = $this->trendyol->publishStock($this->account, $updates);

        $requests = $this->sentTo('price-and-inventory');
        $this->assertSame([1000, 1], array_map(fn (Request $request): int => count($request->data()['items']), $requests));
        $this->assertSame(['b-a', 'b-b'], [$result->accepted[1], $result->accepted[1001]]);
    }

    public function test_trendyols_reason_comes_back_for_every_item_of_a_refused_request(): void
    {
        Http::fake([self::API . '/*' => Http::response(['errors' => [['key' => 'invalid.barcode', 'message' => 'Barcode is not found', 'errorCode' => '400']]], 400)]);

        $result = $this->trendyol->publishStock($this->account, [$this->update(1, quantity: 3), $this->update(2, barcode: 'BC-2', quantity: 4)]);

        $this->assertSame([], $result->accepted);
        $this->assertStringContainsString('Barcode is not found', $result->failed[1]);
        $this->assertSame($result->failed[1], $result->failed[2]);
    }

    public function test_an_account_in_dry_run_sends_nothing_and_says_so(): void
    {
        $this->account->forceFill(['dry_run' => true])->save();
        Http::fake();

        $result = $this->trendyol->publishStock($this->account->fresh(), [$this->update(1, quantity: 3)]);

        $this->assertTrue($result->dryRun);
        $this->assertSame([], $result->accepted);
        Http::assertNothingSent();
        $this->assertTrue(SalesChannelApiLog::where('operation', 'trendyol.price_inventory_update')->first()->dry_run);
    }

    public function test_publishing_is_refused_outright_without_the_approval(): void
    {
        $this->account->forceFill(['capability_approvals' => []])->save();
        Http::fake();

        $this->expectException(ChannelUnavailableException::class);
        $this->trendyol->publishPrices($this->account->fresh(), [$this->update(1, price: '10')]);
    }

    public function test_a_batch_result_says_per_barcode_what_happened(): void
    {
        Http::fake([self::API . '/*' => Http::response(['batchRequestId' => 'b-1', 'itemCount' => 2, 'failedItemCount' => 1, 'items' => [
            ['requestItem' => ['barcode' => 'BC-1', 'quantity' => 5], 'status' => 'SUCCESS', 'failureReasons' => []],
            ['requestItem' => ['barcode' => 'BC-2', 'salePrice' => 9.0], 'status' => 'FAILED', 'failureReasons' => ['Price change limit exceeded']],
        ]])]);

        $result = $this->trendyol->batchResult($this->account, 'b-1');

        $this->assertTrue($result['complete']);
        $this->assertSame(['status' => 'FAILED', 'reasons' => ['Price change limit exceeded']], $result['items']['BC-2']);
        $this->assertSame('SUCCESS', $result['items']['BC-1']['status']);
    }

    public function test_a_batch_still_running_is_not_complete(): void
    {
        Http::fake([self::API . '/*' => Http::response(['batchRequestId' => 'b-1', 'itemCount' => 2, 'items' => [['requestItem' => ['barcode' => 'BC-1'], 'status' => 'IN_PROGRESS']]])]);

        $this->assertFalse($this->trendyol->batchResult($this->account, 'b-1')['complete']);
    }

    // ---- picking and tracking ---------------------------------------------------------------------------

    private function confirmation(string $carrier = 'smsa'): ShipmentConfirmation
    {
        return new ShipmentConfirmation('10234567890', '3012345678', $carrier, 'SMSA Express', 'TRK-9', CarbonImmutable::parse('2026-11-06 09:30:00 UTC'), ['4400000001' => 2]);
    }

    public function test_a_seller_pays_package_gets_picking_then_the_tracking_number_with_the_mapped_carrier(): void
    {
        Http::fake([
            self::API . '/order/sellers/' . Trendyol::SELLER . '/v2/orders*' => Http::response(Trendyol::stream([Trendyol::package(['whoPays' => 1])])),
            self::API . '/order/sellers/*' => Http::response('', 200),
        ]);

        $this->assertTrue($this->trendyol->confirmShipment($this->account, $this->confirmation()));

        $picking = $this->sentTo('/shipment-packages/3012345678')[0];
        $this->assertSame('PUT', $picking->method());
        $this->assertSame('{"status":"Picking","lines":[{"lineId":4400000001,"quantity":2}],"params":{}}', json_encode($picking->data()), 'Trendyol expects an empty object for params, not an empty list.');
        $tracking = $this->sentTo('/tracking-details')[0];
        $this->assertSame(['cargoSenderNumber' => 'TRK-9', 'providerCode' => 'SMSA'], $tracking->data());
    }

    public function test_a_package_trendyol_pays_the_carrier_for_is_only_moved_to_picking(): void
    {
        Http::fake([
            self::API . '/order/sellers/' . Trendyol::SELLER . '/v2/orders*' => Http::response(Trendyol::stream([Trendyol::package()])),
            self::API . '/order/sellers/*' => Http::response('', 200),
        ]);

        $this->assertTrue($this->trendyol->confirmShipment($this->account, $this->confirmation()));

        $this->assertCount(1, $this->sentTo('/shipment-packages/3012345678'));
        $this->assertCount(0, $this->sentTo('/tracking-details'), 'Trendyol issues the tracking number and label for a package it pays the carrier for.');
    }

    public function test_a_carrier_with_no_trendyol_code_stops_before_anything_is_written(): void
    {
        Http::fake([self::API . '/order/sellers/' . Trendyol::SELLER . '/v2/orders*' => Http::response(Trendyol::stream([Trendyol::package(['whoPays' => 1])]))]);

        try {
            $this->trendyol->confirmShipment($this->account, $this->confirmation('torod'));
            $this->fail('A carrier Trendyol does not know was sent.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('torod', $exception->getMessage());
        }

        $this->assertCount(0, $this->sentTo('/shipment-packages/3012345678'));
    }

    public function test_a_package_that_has_moved_on_is_left_alone(): void
    {
        foreach (['Shipped', 'Cancelled', 'UnPacked', 'Delivered'] as $status) {
            Http::fake([self::API . '/order/sellers/*' => Http::response(Trendyol::stream([Trendyol::package(['whoPays' => 1, 'status' => $status, 'shipmentPackageStatus' => $status])]))]);
            $this->assertFalse($this->trendyol->confirmShipment($this->account, $this->confirmation()), $status);
        }

        $this->assertCount(0, array_filter($this->sentTo('/order/sellers/'), fn (Request $request): bool => $request->method() === 'PUT'));
    }

    public function test_the_tracking_number_is_not_sent_twice(): void
    {
        Http::fake([
            self::API . '/order/sellers/' . Trendyol::SELLER . '/v2/orders*' => Http::response(Trendyol::stream([Trendyol::package(['whoPays' => 1, 'status' => 'Picking', 'shipmentPackageStatus' => 'Picking', 'cargoSenderNumber' => 'TRK-9'])])),
            self::API . '/order/sellers/*' => Http::response('', 200),
        ]);

        $this->assertTrue($this->trendyol->confirmShipment($this->account, $this->confirmation()));

        $this->assertCount(0, $this->sentTo('/tracking-details'));
    }

    public function test_an_account_in_dry_run_does_not_claim_a_shipment_was_confirmed(): void
    {
        $this->account->forceFill(['dry_run' => true])->save();
        Http::fake([self::API . '/order/sellers/' . Trendyol::SELLER . '/v2/orders*' => Http::response(Trendyol::stream([Trendyol::package(['whoPays' => 1])]))]);

        $this->assertFalse($this->trendyol->confirmShipment($this->account->fresh(), $this->confirmation()));

        $this->assertCount(0, array_filter(Http::recorded()->all(), fn (array $pair): bool => $pair[0]->method() === 'PUT'));
    }

    // ---- connection test --------------------------------------------------------------------------------

    public function test_a_connection_test_probes_products_orders_and_the_statement_and_only_reads(): void
    {
        Http::fake([
            self::API . '/product/*' => Http::response(Trendyol::approvedPage([])),
            self::API . '/order/*' => Http::response(Trendyol::stream([])),
            self::API . '/finance/*' => Http::response(Trendyol::statement([])),
        ]);

        $result = $this->trendyol->testConnection($this->account);

        $this->assertTrue($result->ok());
        $this->assertEqualsCanonicalizing(['products:read', 'orders:read', 'finance:read'], $result->granted);
        $this->assertSame([], $result->missing);
        $this->assertSame(Trendyol::SELLER, $result->externalAccountId);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('GET', $request->method());
        }
    }

    public function test_wrong_keys_are_reported_as_an_authorisation_problem(): void
    {
        Http::fake([self::API . '/*' => Http::response(['status' => 401, 'exception' => 'ClientApiAuthenticationException'], 401)]);

        $result = $this->trendyol->testConnection($this->account);

        $this->assertFalse($result->ok());
        $this->assertSame(ConnectionStatus::AuthExpired, $result->status);
    }

    public function test_a_service_that_refuses_is_named_in_the_result(): void
    {
        Http::fake([
            self::API . '/product/*' => Http::response(Trendyol::approvedPage([])),
            self::API . '/order/*' => Http::response(Trendyol::stream([])),
            self::API . '/finance/*' => Http::response('', 403),
        ]);

        $result = $this->trendyol->testConnection($this->account);

        $this->assertTrue($result->ok());
        $this->assertSame(['finance:read'], $result->missing);
        $this->assertStringContainsString('finance:read', $result->message);
    }

    public function test_stage_answering_503_points_at_the_ip_allow_list(): void
    {
        $this->account->forceFill(['environment' => 'sandbox'])->save();
        Http::fake(['https://stageapigw.trendyol.com/*' => Http::response('', 503)]);

        $result = $this->trendyol->testConnection($this->account->fresh());

        $this->assertFalse($result->ok());
        $this->assertStringContainsString('allow-listed', $result->message);
    }

    public function test_the_seller_id_is_needed_and_nothing_is_sent_without_it(): void
    {
        $this->account->forceFill(['external_account_id' => null])->save();
        Http::fake();

        $this->assertFalse($this->trendyol->testConnection($this->account->fresh())->ok());
        Http::assertNothingSent();
    }

    public function test_the_capability_matrix_is_honest_about_what_is_not_built(): void
    {
        $matrix = $this->trendyol->capabilityMatrix();

        $this->assertTrue($matrix['order_v2']['built']);
        $this->assertTrue($matrix['product_v2']['built']);
        $this->assertFalse($matrix['product_create_update']['built']);
        $this->assertFalse($matrix['cancel_unsupplied']['built']);
        $this->assertTrue($matrix['claims_returns']['built'], 'Claims are read; approving or rejecting them is not built.');
        $this->assertFalse($matrix['fulfilment_by_trendyol']['documented']);
    }
}
