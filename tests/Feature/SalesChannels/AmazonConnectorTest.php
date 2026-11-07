<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\ConnectionStatus;
use App\Enums\SalesChannels\FulfillmentMode;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelApiLog;
use App\Models\SalesChannelProductMapping;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Amazon\AmazonConnector;
use App\Services\SalesChannels\ChannelAccountService;
use App\Services\SalesChannels\Data\PublishUpdate;
use App\Services\SalesChannels\Data\RemoteFinancialEntry;
use App\Services\SalesChannels\Data\ShipmentConfirmation;
use App\Services\SalesChannels\Exceptions\ChannelPermissionDenied;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Support\ChannelCredentialStore;
use App\Services\SalesChannels\Support\ChannelOperations;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\Fixtures\AmazonFixtures as Amazon;
use Tests\TestCase;

/**
 * Every response here is a fixture shaped after Amazon's published models; no test can reach Amazon (stray requests are refused). These prove how the connector
 * behaves against those shapes. They do not prove that a live seller account works: that needs the real credentials and a connection test.
 */
class AmazonConnectorTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private const LWA = 'https://api.amazon.com/auth/o2/token';
    private const SP = 'https://sellingpartnerapi-eu.amazon.com';

    private AmazonConnector $amazon;

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        $this->amazon = app(AmazonConnector::class);
        $this->account = app(ChannelAccountService::class)->create(
            ['channel' => 'amazon', 'name' => 'Amazon KSA', 'external_account_id' => 'A1SELLER', 'currency' => 'SAR', 'settings' => ['region' => 'eu', 'marketplace_ids' => [Amazon::MARKETPLACE]]],
            ['lwa_client_id' => 'amzn1.application-oa2-client.test', 'lwa_client_secret' => 'client-secret-xyz', 'refresh_token' => 'Atzr|refresh-token-abc'],
            adminId: 1,
        );
        $this->account->forceFill([
            'enabled' => true, 'write_enabled' => true, 'dry_run' => false,
            'capabilities' => ['connection_test', 'read_only_sync', 'product_sync', 'order_import', 'inventory_publishing', 'price_publishing', 'fulfillment_dispatch', 'financial_reconciliation'],
            'capability_approvals' => ['inventory_publishing' => ['by' => 1], 'price_publishing' => ['by' => 1], 'fulfillment_dispatch' => ['by' => 1]],
        ])->save();
    }

    private function token(): array
    {
        return [self::LWA => Http::response(['access_token' => 'Atza|access-token-123', 'token_type' => 'bearer', 'expires_in' => 3600, 'refresh_token' => 'Atzr|refresh-token-abc'])];
    }

    private function sentTo(string $needle): array
    {
        return Http::recorded(fn (Request $request): bool => str_contains($request->url(), $needle))->values()->map(fn (array $pair): Request => $pair[0])->all();
    }

    // ---- authentication ---------------------------------------------------------------------------------

    public function test_the_first_call_signs_in_and_the_token_is_reused_until_shortly_before_it_ends(): void
    {
        Http::fake($this->token() + [self::SP . '/*' => Http::response(Amazon::searchOrders([]))]);

        $this->amazon->orders($this->account, CarbonImmutable::parse('2026-11-01T00:00:00Z'), null);
        $this->amazon->orders($this->account, CarbonImmutable::parse('2026-11-01T00:00:00Z'), null);

        $this->assertCount(1, $this->sentTo('auth/o2/token'), 'One sign-in serves both calls.');
        $request = $this->sentTo('/orders/2026-01-01/orders')[0];
        $this->assertSame('Atza|access-token-123', $request->header('x-amz-access-token')[0]);
        $this->assertStringContainsString('PharmacyChannelHub/1.0 (Language=PHP/', $request->header('user-agent')[0]);
        $form = $this->sentTo('auth/o2/token')[0]->data();
        $this->assertSame('refresh_token', $form['grant_type']);
        $this->assertArrayNotHasKey('scope', $form);

    }

    public function test_a_token_with_less_than_five_minutes_left_is_replaced(): void
    {
        Http::fake($this->token() + [self::SP . '/*' => Http::response(Amazon::searchOrders([]))]);

        $this->amazon->orders($this->account, CarbonImmutable::parse('2026-11-01T00:00:00Z'), null);
        $this->travel(56)->minutes();
        $this->amazon->orders($this->account, CarbonImmutable::parse('2026-11-01T00:00:00Z'), null);

        $this->assertCount(2, $this->sentTo('auth/o2/token'));
    }

    public function test_no_secret_reaches_the_log(): void
    {
        Http::fake($this->token() + [self::SP . '/*' => Http::response(Amazon::searchOrders([]))]);

        $this->amazon->orders($this->account, CarbonImmutable::parse('2026-11-01T00:00:00Z'), null);

        $stored = json_encode(SalesChannelApiLog::all()->toArray());
        foreach (['client-secret-xyz', 'Atzr|refresh-token-abc', 'Atza|access-token-123'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }
    }

    public function test_a_refresh_token_amazon_refuses_marks_the_account_and_stops_the_work(): void
    {
        Http::fake([self::LWA => Http::response(['error' => 'invalid_grant', 'error_description' => 'The request has an invalid grant parameter : refresh_token'], 400)]);

        try {
            $this->amazon->orders($this->account, CarbonImmutable::parse('2026-11-01T00:00:00Z'), null);
            $this->fail('Work went on with a refused refresh token.');
        } catch (ChannelUnavailableException $exception) {
            $this->assertSame(ChannelUnavailableException::AUTH_NEEDED, $exception->reason);
        }

        $this->assertSame(ConnectionStatus::Revoked, $this->account->fresh()->connectionStatus());
        Http::assertSentCount(1);
    }

    public function test_a_rejected_client_secret_is_reported_as_an_application_credential_problem(): void
    {
        Http::fake([self::LWA => Http::response(['error' => 'invalid_client', 'error_description' => 'Client authentication failed'], 401)]);

        $this->expectException(ChannelUnavailableException::class);
        try {
            $this->amazon->orders($this->account, CarbonImmutable::parse('2026-11-01T00:00:00Z'), null);
        } finally {
            $this->assertSame(ConnectionStatus::AuthExpired, $this->account->fresh()->connectionStatus());
        }
    }

    public function test_an_expired_token_is_replaced_once_and_the_call_repeated(): void
    {
        Http::fake($this->token() + [self::SP . '/*' => Http::sequence()
            ->push(['errors' => [['code' => 'Unauthorized', 'message' => 'Access to requested resource is denied.', 'details' => 'The access token you provided has expired.']]], 403)
            ->push(Amazon::searchOrders([Amazon::order()]))]);

        $page = $this->amazon->orders($this->account, CarbonImmutable::parse('2026-11-01T00:00:00Z'), null);

        $this->assertCount(1, $page->items);
        $this->assertCount(2, $this->sentTo('auth/o2/token'));
        $this->assertCount(2, $this->sentTo('/orders/2026-01-01/orders'));
    }

    public function test_a_403_with_a_fresh_token_means_a_missing_role_and_is_not_retried_forever(): void
    {
        Http::fake($this->token() + [self::SP . '/*' => Http::response(['errors' => [['code' => 'Unauthorized', 'message' => 'Access to requested resource is denied.']]], 403)]);

        try {
            $this->amazon->orders($this->account, CarbonImmutable::parse('2026-11-01T00:00:00Z'), null);
            $this->fail('A permission problem was treated as success.');
        } catch (ChannelPermissionDenied) {
            $this->assertCount(2, $this->sentTo('/orders/2026-01-01/orders'), 'One call, one repeat with a fresh token, then the refusal stands.');
        }
    }

    // ---- orders -----------------------------------------------------------------------------------------

    public function test_an_fbm_order_is_mapped_with_its_money_and_nothing_personal(): void
    {
        Http::fake($this->token() + [self::SP . '/*' => Http::response(Amazon::searchOrders([Amazon::order()]))]);

        $page = $this->amazon->orders($this->account, CarbonImmutable::parse('2026-11-01T00:00:00Z'), null);

        $order = $page->items[0];
        $this->assertSame(FulfillmentMode::Fbm, $order->fulfillmentMode);
        $this->assertSame('MFN', $order->fulfillmentChannelRaw);
        $this->assertSame(ChannelOrderStatus::New, $order->status);
        $this->assertSame('SAR', $order->currency);
        $this->assertSame('200.000000', $order->itemsTotal->toStorage());
        $this->assertSame('10.000000', $order->shippingTotal->toStorage());
        $this->assertSame('5.000000', $order->discountTotal->toStorage(), 'Amazon reports the discount as a negative breakdown; it is a positive discount here.');
        $this->assertSame('25.000000', $order->taxTotal->toStorage());
        $this->assertSame('230.00', $order->orderTotal->toDisplay());
        $this->assertFalse($order->pricesIncludeTax, 'A separate TAX breakdown means the item figure is tax-exclusive.');
        $this->assertSame(['city' => 'Riyadh', 'country' => 'SA'], $order->destination);
        $this->assertSame('44000000000001', $order->lines[0]->lineId);
        $this->assertSame('SKU-1', $order->lines[0]->sku);
        $stored = json_encode([$order->snapshot, $order->lines[0]->snapshot]);
        foreach (['Test Buyer', 'buyer-token', '1 Test Street', '+966500000000'] as $personal) {
            $this->assertStringNotContainsString($personal, $stored);
        }
    }

    public function test_only_merchant_fulfilment_on_an_amazon_channel_is_fbm(): void
    {
        $fba = Amazon::order('171-0000002-0000002', 'AMAZON', 'SHIPPED');
        $mcf = Amazon::order('171-0000003-0000003', 'AMAZON', 'SHIPPED', ['salesChannel' => ['channelName' => 'NON_AMAZON']]);
        $unknown = Amazon::order('171-0000004-0000004');
        unset($unknown['fulfillment']['fulfilledBy']);
        Http::fake($this->token() + [self::SP . '/*' => Http::response(Amazon::searchOrders([$fba, $mcf, $unknown]))]);

        $modes = array_map(fn ($order): FulfillmentMode => $order->fulfillmentMode, $this->amazon->orders($this->account, CarbonImmutable::parse('2026-11-01T00:00:00Z'), null)->items);

        $this->assertSame([FulfillmentMode::Fba, FulfillmentMode::Unknown, FulfillmentMode::Unknown], $modes);
    }

    public function test_statuses_cancellations_and_deliveries_are_mapped(): void
    {
        $cancelled = Amazon::order('171-0000005-0000005', 'MERCHANT', 'CANCELLED');
        $partial = Amazon::order('171-0000006-0000006', 'MERCHANT', 'PARTIALLY_SHIPPED');
        $pending = Amazon::order('171-0000007-0000007', 'MERCHANT', 'PENDING');
        $delivered = Amazon::order('171-0000008-0000008', 'MERCHANT', 'SHIPPED', ['packages' => [['packageReferenceId' => '1', 'packageStatus' => ['status' => 'DELIVERED'], 'carrier' => 'Aramex', 'shipTime' => '2026-11-06T07:00:00Z', 'trackingNumber' => 'TRK123']]]);
        $itemCancelled = Amazon::order('171-0000009-0000009');
        $itemCancelled['orderItems'][0]['cancellation'] = ['cancellationExecution' => ['cancelledBy' => 'BUYER', 'cancelReason' => 'CHANGED_MIND']];
        Http::fake($this->token() + [self::SP . '/*' => Http::response(Amazon::searchOrders([$cancelled, $partial, $pending, $delivered, $itemCancelled]))]);

        $orders = $this->amazon->orders($this->account, CarbonImmutable::parse('2026-11-01T00:00:00Z'), null)->items;

        $this->assertSame(ChannelOrderStatus::Cancelled, $orders[0]->status);
        $this->assertSame(2, $orders[0]->lines[0]->quantityCancelled);
        $this->assertSame(ChannelOrderStatus::Processing, $orders[1]->status);
        $this->assertSame(ChannelOrderStatus::Pending, $orders[2]->status);
        $this->assertFalse($orders[2]->status->allowsFulfilment(), 'An order Amazon has not released is never fulfilled.');
        $this->assertSame(ChannelOrderStatus::Delivered, $orders[3]->status);
        $this->assertSame('TRK123', $orders[3]->trackingNumber);
        $this->assertSame('Aramex', $orders[3]->carrier);
        $this->assertSame(2, $orders[4]->lines[0]->quantityCancelled);
    }

    public function test_cash_on_delivery_is_noticed(): void
    {
        $cod = Amazon::order('171-0000010-0000010', 'MERCHANT', 'UNSHIPPED', ['payment' => ['paymentExecutions' => [['paymentMethod' => 'CashOnDelivery']]]]);
        Http::fake($this->token() + [self::SP . '/*' => Http::response(Amazon::searchOrders([$cod]))]);

        $this->assertTrue($this->amazon->orders($this->account, CarbonImmutable::parse('2026-11-01T00:00:00Z'), null)->items[0]->isCod);
    }

    public function test_the_order_search_asks_for_what_it_needs_and_continues_with_an_identical_query(): void
    {
        Http::fake($this->token() + [self::SP . '/*' => Http::sequence()->push(Amazon::searchOrders([Amazon::order()], 'NEXT-TOKEN-1'))->push(Amazon::searchOrders([]))]);

        $first = $this->amazon->orders($this->account, CarbonImmutable::parse('2026-11-01T00:00:00Z'), null);
        $second = $this->amazon->orders($this->account, CarbonImmutable::parse('2026-11-01T00:00:00Z'), $first->nextCursor);

        $requests = $this->sentTo('/orders/2026-01-01/orders');
        parse_str(parse_url($requests[0]->url(), PHP_URL_QUERY), $one);
        parse_str(parse_url($requests[1]->url(), PHP_URL_QUERY), $two);
        $this->assertSame('2026-11-01T00:00:00Z', $one['lastUpdatedAfter']);
        $this->assertSame(Amazon::MARKETPLACE, $one['marketplaceIds']);
        $this->assertSame('100', $one['maxResultsPerPage']);
        $this->assertStringContainsString('FULFILLMENT', $one['includedData']);
        $this->assertStringContainsString('PROCEEDS', $one['includedData']);
        $this->assertStringNotContainsString('BUYER', $one['includedData'], 'The buyer is not requested unless the account asks for it.');
        $this->assertSame('NEXT-TOKEN-1', $two['paginationToken']);
        unset($two['paginationToken']);
        $this->assertSame($one, $two, 'The token belongs to the query that produced it.');
        $this->assertNull($second->nextCursor);
        $this->assertSame('2026-11-05T09:30:00+00:00', $first->highWatermark->toIso8601String());
    }

    public function test_one_order_can_be_looked_up_and_a_missing_one_is_null(): void
    {
        Http::fake($this->token() + [self::SP . '/orders/2026-01-01/orders/171-0000001-0000001*' => Http::response(['order' => Amazon::order()]), self::SP . '/orders/2026-01-01/orders/MISSING*' => Http::response(['errors' => [['code' => 'NotFound']]], 404)]);

        $this->assertSame('171-0000001-0000001', $this->amazon->order($this->account, '171-0000001-0000001')->orderId);
        $this->assertNull($this->amazon->order($this->account, 'MISSING'));
    }

    // ---- listings ---------------------------------------------------------------------------------------

    public function test_listings_say_who_fulfils_them_and_carry_price_and_quantity(): void
    {
        Http::fake($this->token() + [self::SP . '/*' => Http::response(['numberOfResults' => 3, 'items' => [Amazon::listing('SKU-FBM'), Amazon::listing('SKU-FBA', ['AMAZON_EU']), Amazon::listing('SKU-BOTH', ['DEFAULT', 'AMAZON_EU'])]])]);

        $page = $this->amazon->listings($this->account, null);

        $this->assertSame(['fbm', 'fba', 'both'], array_map(fn ($listing): ?string => $listing->fulfillmentMode, $page->items));
        $this->assertSame('99.50', $page->items[0]->price->toDisplay());
        $this->assertSame('SAR', $page->items[0]->currency);
        $this->assertSame(12, $page->items[0]->quantity);
        $this->assertNull($page->items[1]->quantity, 'Amazon reports no quantity for FBA.');
        $this->assertSame('B0TESTASIN1', $page->items[0]->externalId);
        $this->assertSame('SKU-FBM', $page->items[0]->listingKey);
        $this->assertNull($page->nextCursor);
    }

    public function test_a_search_that_hits_amazons_cap_is_split_in_half_instead_of_silently_truncated(): void
    {
        Http::fake($this->token() + [self::SP . '/*' => Http::response(['numberOfResults' => 1000, 'items' => []])]);

        $page = $this->amazon->listings($this->account, ['windows' => [['2026-01-01T00:00:00Z', '2026-03-01T00:00:00Z']], 'token' => null]);

        $this->assertSame([], $page->items);
        $windows = $page->nextCursor['windows'];
        $this->assertCount(2, $windows);
        $this->assertSame('2026-01-01T00:00:00Z', $windows[0][0]);
        $this->assertSame($windows[0][1], $windows[1][0]);
        $this->assertSame('2026-03-01T00:00:00Z', $windows[1][1]);
    }

    public function test_listing_pages_are_followed_window_by_window(): void
    {
        Http::fake($this->token() + [self::SP . '/*' => Http::sequence()
            ->push(['numberOfResults' => 25, 'items' => [Amazon::listing('A')], 'pagination' => ['nextToken' => 'PAGE2']])
            ->push(['numberOfResults' => 25, 'items' => [Amazon::listing('B')]])]);

        $first = $this->amazon->listings($this->account, ['windows' => [['2026-01-01T00:00:00Z', '2026-02-01T00:00:00Z'], ['2026-02-01T00:00:00Z', '2026-03-01T00:00:00Z']], 'token' => null]);
        $this->assertSame('PAGE2', $first->nextCursor['token']);
        $second = $this->amazon->listings($this->account, $first->nextCursor);

        $this->assertSame([['2026-02-01T00:00:00Z', '2026-03-01T00:00:00Z']], $second->nextCursor['windows']);
        $requests = $this->sentTo('/listings/2021-08-01/items/A1SELLER');
        parse_str(parse_url($requests[1]->url(), PHP_URL_QUERY), $query);
        $this->assertSame('PAGE2', $query['pageToken']);
        $this->assertSame('2026-01-01T00:00:00Z', $query['lastUpdatedAfter'], 'A page token only works with the query that made it.');
    }

    // ---- FBA stock --------------------------------------------------------------------------------------

    public function test_fba_stock_is_read_with_its_breakdown(): void
    {
        Http::fake($this->token() + [self::SP . '/*' => Http::response(['payload' => ['granularity' => ['granularityType' => 'Marketplace', 'granularityId' => Amazon::MARKETPLACE], 'inventorySummaries' => [Amazon::inventorySummary('SKU-1', 7)]], 'pagination' => ['nextToken' => 'T30']])]);

        $page = $this->amazon->inventory($this->account, null);

        $item = $page->items[0];
        $this->assertSame([7, 3, 1, 3, 10], [$item->available, $item->reserved, $item->unfulfillable, $item->inbound, $item->total]);
        $this->assertSame('X00TESTFNSKU', $item->fnSku);
        $this->assertSame(['token' => 'T30', 'm' => 0], $page->nextCursor);
        $request = $this->sentTo('/fba/inventory/v1/summaries')[0];
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
        $this->assertSame(['Marketplace', Amazon::MARKETPLACE, Amazon::MARKETPLACE, 'true'], [$query['granularityType'], $query['granularityId'], $query['marketplaceIds'], $query['details']]);
    }

    // ---- finances ---------------------------------------------------------------------------------------

    public function test_fees_become_costs_sales_are_not_costs_and_signs_are_taken_as_given(): void
    {
        Http::fake($this->token() + [self::SP . '/*' => Http::response(['payload' => ['transactions' => [Amazon::transaction()]]])]);

        $entries = $this->amazon->finances($this->account, CarbonImmutable::parse('2026-11-01T00:00:00Z'), CarbonImmutable::parse('2026-11-07T00:00:00Z'), null)->items;

        $byRaw = collect($entries)->keyBy('rawType');
        $commission = $byRaw->first(fn ($entry): bool => str_contains($entry->rawType, 'Commission'));
        $fba = $byRaw->first(fn ($entry): bool => str_contains($entry->rawType, 'FBA fees'));
        $sale = $byRaw->first(fn ($entry): bool => str_contains($entry->rawType, 'Product Charges'));

        $this->assertSame(RemoteFinancialEntry::FEE, $commission->category);
        $this->assertSame('marketplace_commission', $commission->component->value);
        $this->assertSame('20.000000', $commission->amount->toStorage(), 'A -20.00 expense is a 20.00 cost.');
        $this->assertSame('fba_fulfillment', $fba->component->value);
        $this->assertSame('10.000000', $fba->amount->toStorage());
        $this->assertSame(RemoteFinancialEntry::SALE, $sale->category, 'The sale is already counted from the order.');
        $this->assertSame('171-0000001-0000001', $commission->orderId);
        $this->assertSame('SKU-1', $commission->sku);
    }

    private function feesOf(array $transaction): array
    {
        Http::fake($this->token() + [self::SP . '/*' => Http::response(['payload' => ['transactions' => [$transaction]]])]);

        return array_values(array_filter(
            $this->amazon->finances($this->account, CarbonImmutable::parse('2026-11-01T00:00:00Z'), CarbonImmutable::parse('2026-11-07T00:00:00Z'), null)->items,
            fn ($entry): bool => $entry->category === RemoteFinancialEntry::FEE && $entry->component->value === 'marketplace_commission',
        ));
    }

    public function test_a_transaction_that_carries_the_same_money_at_both_levels_is_counted_once(): void
    {
        $fees = $this->feesOf(Amazon::twoItemTransaction(transactionLevel: true));

        $this->assertCount(1, $fees, 'The transaction-level commission already adds up the items: reading the items too would count it twice.');
        $this->assertSame('40.000000', $fees[0]->amount->toStorage());
    }

    public function test_two_items_with_the_same_fee_are_two_facts_when_only_the_items_carry_breakdowns(): void
    {
        $fees = $this->feesOf(Amazon::twoItemTransaction(transactionLevel: false));

        $this->assertCount(2, $fees);
        $this->assertCount(2, array_unique(array_map(fn ($entry): string => $entry->identity, $fees)), 'Same path, same amount, different item: two facts, not a duplicate.');
        $this->assertSame(['SKU-A', 'SKU-B'], array_map(fn ($entry): ?string => $entry->sku, $fees), 'Each fact carries its own item\'s SKU.');
        $this->assertSame('40.000000', collect($fees)->reduce(fn ($sum, $entry) => $sum->plus($entry->amount), Money::zero())->toStorage());
    }

    public function test_redelivering_a_multi_item_transaction_gives_the_same_identities(): void
    {
        $first = array_map(fn ($entry): string => $entry->identity, $this->feesOf(Amazon::twoItemTransaction(transactionLevel: false)));
        $second = array_map(fn ($entry): string => $entry->identity, $this->feesOf(Amazon::twoItemTransaction(transactionLevel: false)));

        $this->assertSame($first, $second);
    }

    public function test_every_marketplace_of_the_account_is_read_for_stock_statements_and_listings_one_after_the_other(): void
    {
        $this->account->forceFill(['marketplace_ids' => ['A17E79C6D8DWNP', 'A2VIGQ35RCS4UG']])->save();
        Http::fake($this->token() + [self::SP . '/*' => Http::response(['payload' => ['inventorySummaries' => [], 'transactions' => []], 'items' => [], 'numberOfResults' => 0])]);
        $from = CarbonImmutable::parse('2026-11-01T00:00:00Z');
        $to = CarbonImmutable::parse('2026-11-07T00:00:00Z');

        $stock = $this->amazon->inventory($this->account, null);
        $this->amazon->inventory($this->account, $stock->nextCursor);
        $statement = $this->amazon->finances($this->account, $from, $to, null);
        $this->amazon->finances($this->account, $from, $to, $statement->nextCursor);
        $listings = $this->amazon->listings($this->account, null);
        $this->amazon->listings($this->account, $listings->nextCursor);

        $this->assertSame(['m' => 1], $stock->nextCursor);
        $this->assertNull($this->amazon->inventory($this->account, $stock->nextCursor)->nextCursor, 'The last marketplace ends the read.');
        $markets = fn (string $path, string $parameter): array => array_map(function ($request) use ($parameter): string {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query[$parameter];
        }, $this->sentTo($path));
        $this->assertSame(['A17E79C6D8DWNP', 'A2VIGQ35RCS4UG'], array_slice($markets('/fba/inventory/v1/summaries', 'marketplaceIds'), 0, 2));
        $this->assertSame(['A17E79C6D8DWNP', 'A2VIGQ35RCS4UG'], array_slice($markets('/finances/2024-06-19/transactions', 'marketplaceId'), 0, 2));
        $this->assertSame(['A17E79C6D8DWNP', 'A2VIGQ35RCS4UG'], array_slice($markets('/listings/2021-08-01/items/', 'marketplaceIds'), 0, 2));
    }

    public function test_a_listing_window_is_split_down_to_a_couple_of_seconds_and_one_that_cannot_be_split_is_reported(): void
    {
        $capped = ['items' => [], 'numberOfResults' => 1000];
        Http::fake($this->token() + [self::SP . '/*' => Http::response($capped)]);

        $wide = $this->amazon->listings($this->account, null);
        $this->assertCount(2, $wide->nextCursor['windows'], 'A full window is halved.');

        $narrow = ['windows' => [['2026-11-05T10:00:00Z', '2026-11-05T10:30:00Z']], 'token' => null, 'm' => 0];
        $half = $this->amazon->listings($this->account, $narrow);
        $this->assertCount(2, $half->nextCursor['windows'], 'Half an hour is still worth halving: updates can come in bursts.');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be read completely');
        $this->amazon->listings($this->account, ['windows' => [['2026-11-05T10:00:00Z', '2026-11-05T10:00:01Z']], 'token' => null, 'm' => 0]);
    }

    public function test_a_breakdown_type_nobody_has_classified_is_kept_for_review_not_counted(): void
    {
        $odd = Amazon::transaction('TX-2', 'Shipment', [['breakdownType' => 'BrandNewThingAmazonInvented', 'breakdownAmount' => ['currencyCode' => 'SAR', 'currencyAmount' => '-7.00'], 'breakdowns' => []]]);
        Http::fake($this->token() + [self::SP . '/*' => Http::response(['payload' => ['transactions' => [$odd]]])]);

        $entries = $this->amazon->finances($this->account, CarbonImmutable::parse('2026-11-01T00:00:00Z'), CarbonImmutable::parse('2026-11-07T00:00:00Z'), null)->items;

        $this->assertSame(RemoteFinancialEntry::UNCLASSIFIED, $entries[0]->category);
    }

    public function test_a_transfer_to_the_bank_is_a_payout_never_revenue(): void
    {
        $transfer = Amazon::transaction('TX-3', 'Transfer', [['breakdownType' => 'Transfer', 'breakdownAmount' => ['currencyCode' => 'SAR', 'currencyAmount' => '-900.00'], 'breakdowns' => []]]);
        Http::fake($this->token() + [self::SP . '/*' => Http::response(['payload' => ['transactions' => [$transfer]]])]);

        $this->assertSame(RemoteFinancialEntry::PAYOUT, $this->amazon->finances($this->account, CarbonImmutable::parse('2026-11-01T00:00:00Z'), CarbonImmutable::parse('2026-11-07T00:00:00Z'), null)->items[0]->category);
    }

    public function test_the_same_transaction_has_the_same_identity_every_time_and_the_window_stops_short_of_now(): void
    {
        Http::fake($this->token() + [self::SP . '/*' => Http::response(['payload' => ['transactions' => [Amazon::transaction()]]])]);

        $one = $this->amazon->finances($this->account, CarbonImmutable::parse('2026-11-01T00:00:00Z'), now(), null)->items;
        $two = $this->amazon->finances($this->account, CarbonImmutable::parse('2026-11-01T00:00:00Z'), now(), null)->items;

        $this->assertSame(array_map(fn ($entry): string => $entry->identity, $one), array_map(fn ($entry): string => $entry->identity, $two));
        parse_str(parse_url($this->sentTo('/finances/2024-06-19/transactions')[0]->url(), PHP_URL_QUERY), $query);
        $this->assertTrue(CarbonImmutable::parse($query['postedBefore'])->lt(now()->subMinutes(2)), 'Amazon refuses a range that ends less than two minutes ago.');
    }

    // ---- publishing -------------------------------------------------------------------------------------

    private function mapping(): SalesChannelProductMapping
    {
        return SalesChannelProductMapping::create(['account_id' => $this->account->id, 'listing_key' => 'SKU-1', 'external_sku' => 'SKU-1', 'status' => 'mapped', 'product_id' => 5, 'snapshot' => ['product_type' => 'COSMETICS']]);
    }

    private function update(SalesChannelProductMapping $mapping, ?int $quantity = null, ?string $price = null): PublishUpdate
    {
        return new PublishUpdate($mapping->id, 'SKU-1', 'SKU-1', null, $quantity, $price === null ? null : Money::of($price), null, 'SAR');
    }

    public function test_a_price_is_previewed_then_patched_with_the_documented_body(): void
    {
        Http::fake($this->token() + [self::SP . '/*' => Http::sequence()->push(['sku' => 'SKU-1', 'status' => 'VALID', 'issues' => []])->push(['sku' => 'SKU-1', 'status' => 'ACCEPTED', 'submissionId' => 'sub-1', 'issues' => []])]);
        $mapping = $this->mapping();

        $result = $this->amazon->publishPrices($this->account, [$this->update($mapping, price: '49.95')]);

        $this->assertSame([$mapping->id => 'sub-1'], $result->accepted);
        $requests = $this->sentTo('/listings/2021-08-01/items/A1SELLER/SKU-1');
        $this->assertCount(2, $requests);
        $this->assertSame('PATCH', $requests[0]->method());
        parse_str(parse_url($requests[0]->url(), PHP_URL_QUERY), $preview);
        $this->assertSame('VALIDATION_PREVIEW', $preview['mode']);
        parse_str(parse_url($requests[1]->url(), PHP_URL_QUERY), $real);
        $this->assertArrayNotHasKey('mode', $real);
        $body = $requests[1]->data();
        $this->assertSame('COSMETICS', $body['productType']);
        $patch = $body['patches'][0];
        $this->assertSame(['op' => 'replace', 'path' => '/attributes/purchasable_offer'], ['op' => $patch['op'], 'path' => $patch['path']]);
        $this->assertSame(['marketplace_id' => Amazon::MARKETPLACE, 'currency' => 'SAR', 'audience' => 'ALL'], array_diff_key($patch['value'][0], ['our_price' => 1]));
        $this->assertEqualsWithDelta(49.95, $patch['value'][0]['our_price'][0]['schedule'][0]['value_with_tax'], 0.0001);
    }

    public function test_an_update_amazon_would_reject_is_never_sent_and_comes_back_with_amazons_reason(): void
    {
        Http::fake($this->token() + [self::SP . '/*' => Http::response(['sku' => 'SKU-1', 'status' => 'INVALID', 'issues' => [['code' => '90220', 'message' => 'A required attribute is missing.', 'severity' => 'ERROR']]])]);
        $mapping = $this->mapping();

        $result = $this->amazon->publishPrices($this->account, [$this->update($mapping, price: '49.95')]);

        $this->assertSame([], $result->accepted);
        $this->assertStringContainsString('A required attribute is missing', $result->failed[$mapping->id]);
        $this->assertCount(1, $this->sentTo('/listings/2021-08-01/items'), 'Only the preview went out.');
    }

    public function test_a_quantity_is_merged_into_the_merchant_channel_and_nothing_else_is_touched(): void
    {
        Http::fake($this->token() + [self::SP . '/*' => Http::sequence()->push(['status' => 'VALID', 'issues' => []])->push(['status' => 'ACCEPTED', 'submissionId' => 'sub-2'])]);
        $mapping = $this->mapping();

        $this->amazon->publishStock($this->account, [$this->update($mapping, quantity: 20)]);

        $patch = $this->sentTo('/listings/2021-08-01/items/A1SELLER/SKU-1')[1]->data()['patches'][0];
        $this->assertSame(['op' => 'merge', 'path' => '/attributes/fulfillment_availability', 'value' => [['fulfillment_channel_code' => 'DEFAULT', 'quantity' => 20]]], $patch);
    }

    public function test_an_account_in_dry_run_sends_no_write_and_says_so(): void
    {
        $this->account->forceFill(['dry_run' => true])->save();
        Http::fake($this->token() + [self::SP . '/*' => Http::response(['status' => 'VALID', 'issues' => []])]);
        $mapping = $this->mapping();

        $result = $this->amazon->publishPrices($this->account->fresh(), [$this->update($mapping, price: '49.95')]);

        $this->assertTrue($result->dryRun);
        $this->assertSame([], $result->accepted);
        $logged = SalesChannelApiLog::where('operation', 'amazon.listings_patch_price')->first();
        $this->assertTrue($logged->dry_run);
        $this->assertCount(0, array_filter($this->sentTo('/listings/2021-08-01/items'), fn (Request $request): bool => !str_contains($request->url(), 'VALIDATION_PREVIEW')));
    }

    public function test_publishing_is_refused_outright_without_the_approval(): void
    {
        $this->account->forceFill(['capability_approvals' => []])->save();
        Http::fake($this->token() + [self::SP . '/*' => Http::response(['status' => 'VALID'])]);

        $this->expectException(ChannelUnavailableException::class);
        $this->amazon->publishPrices($this->account->fresh(), [$this->update($this->mapping(), price: '49.95')]);
    }

    // ---- shipment confirmation --------------------------------------------------------------------------

    public function test_a_shipment_is_confirmed_with_the_carrier_and_tracking_number(): void
    {
        Http::fake($this->token() + [self::SP . '/*' => Http::response('', 204)]);

        $ok = $this->amazon->confirmShipment($this->account, new ShipmentConfirmation('171-0000001-0000001', '1', 'SMSA', 'SMSA Express', 'TRK-9', CarbonImmutable::parse('2026-11-06T09:30:00Z'), ['44000000000001' => 2]));

        $this->assertTrue($ok);
        $request = $this->sentTo('/orders/v0/orders/171-0000001-0000001/shipmentConfirmation')[0];
        $detail = $request->data()['packageDetail'];
        $this->assertSame(Amazon::MARKETPLACE, $request->data()['marketplaceId']);
        $this->assertSame(['Other', 'SMSA Express', 'TRK-9', '2026-11-06T09:30:00Z'], [$detail['carrierCode'], $detail['carrierName'], $detail['trackingNumber'], $detail['shipDate']]);
        $this->assertSame([['orderItemId' => '44000000000001', 'quantity' => 2]], $detail['orderItems']);
    }

    // ---- connection test --------------------------------------------------------------------------------

    private function participations(bool $participating = true): array
    {
        return ['payload' => [['marketplace' => ['id' => Amazon::MARKETPLACE, 'name' => 'Amazon.sa', 'countryCode' => 'SA', 'defaultCurrencyCode' => 'SAR'], 'participation' => ['isParticipating' => $participating, 'hasSuspendedListings' => false], 'storeName' => 'Test store']]];
    }

    public function test_a_connection_test_reads_the_marketplaces_and_probes_each_permission(): void
    {
        Http::fake($this->token() + [
            self::SP . '/sellers/v1/marketplaceParticipations*' => Http::response($this->participations()),
            self::SP . '/orders/*' => Http::response(Amazon::searchOrders([])),
            self::SP . '/listings/*' => Http::response(['numberOfResults' => 0, 'items' => []]),
            self::SP . '/fba/*' => Http::response(['payload' => ['inventorySummaries' => []]]),
            self::SP . '/finances/*' => Http::response(['payload' => ['transactions' => []]]),
        ]);

        $result = $this->amazon->testConnection($this->account);

        $this->assertTrue($result->ok());
        $this->assertSame([], $result->missing);
        $this->assertEqualsCanonicalizing(['sellers:read', 'orders:read', 'listings:read', 'fba_inventory:read', 'finances:read'], $result->granted);
        $this->assertSame('A1SELLER', $result->externalAccountId);
        foreach (Http::recorded() as [$request]) {
            $this->assertContains($request->method(), ['GET', 'POST'], 'A connection test only reads (the sign-in is the only POST).');
        }
    }

    public function test_a_role_the_authorisation_lacks_is_named_in_the_result(): void
    {
        Http::fake($this->token() + [
            self::SP . '/sellers/v1/marketplaceParticipations*' => Http::response($this->participations()),
            self::SP . '/orders/*' => Http::response(Amazon::searchOrders([])),
            self::SP . '/listings/*' => Http::response(['errors' => [['code' => 'Unauthorized', 'message' => 'Access to requested resource is denied.']]], 403),
            self::SP . '/fba/*' => Http::response(['payload' => ['inventorySummaries' => []]]),
            self::SP . '/finances/*' => Http::response(['errors' => [['code' => 'Unauthorized']]], 403),
        ]);

        $result = $this->amazon->testConnection($this->account);

        $this->assertTrue($result->ok());
        $this->assertEqualsCanonicalizing(['listings:read', 'finances:read'], $result->missing);
        $this->assertStringContainsString('missing', $result->message);
    }

    public function test_a_marketplace_the_seller_does_not_sell_in_is_reported(): void
    {
        Http::fake($this->token() + [self::SP . '/sellers/v1/marketplaceParticipations*' => Http::response($this->participations(false)), self::SP . '/*' => Http::response(['payload' => []])]);

        $result = $this->amazon->testConnection($this->account);

        $this->assertContains('marketplace:' . Amazon::MARKETPLACE, $result->missing);
    }

    public function test_a_connection_test_needs_the_seller_id_and_never_creates_anything(): void
    {
        $this->account->forceFill(['external_account_id' => null])->save();
        Http::fake();

        $result = $this->amazon->testConnection($this->account->fresh());

        $this->assertFalse($result->ok());
        Http::assertNothingSent();
    }

    public function test_the_sandbox_account_uses_the_sandbox_host_and_the_region_picks_the_endpoint(): void
    {
        $this->account->forceFill(['environment' => 'sandbox'])->save();
        Http::fake($this->token() + ['sandbox.sellingpartnerapi-eu.amazon.com/*' => Http::response(Amazon::searchOrders([]))]);

        $this->amazon->orders($this->account->fresh(), CarbonImmutable::parse('2026-11-01T00:00:00Z'), null);

        $this->assertCount(1, $this->sentTo('sandbox.sellingpartnerapi-eu.amazon.com/orders/2026-01-01/orders'));
        $this->assertSame(self::SP, app(\App\Services\SalesChannels\Amazon\AmazonClient::class)->baseUrl($this->account->forceFill(['environment' => 'production'])));
    }

    public function test_the_capability_matrix_is_honest_about_what_is_not_built(): void
    {
        $matrix = $this->amazon->capabilityMatrix();

        $this->assertTrue($matrix['orders_v2026_01_01']['built']);
        $this->assertFalse($matrix['notifications']['built']);
        $this->assertFalse($matrix['reports']['built']);
        $this->assertFalse($matrix['catalog_items']['built']);
        $this->assertFalse($matrix['feeds_json_listings']['built'], 'The feed operations are on the allowed list but nothing calls them: price and stock go one SKU at a time.');
        $this->assertFalse($matrix['product_type_definitions']['built']);
    }

    public function test_every_operation_the_matrix_calls_built_is_called_by_the_connector_and_every_one_it_does_not_is_not(): void
    {
        $sources = '';
        foreach (glob(app_path('Services/SalesChannels/Amazon/*.php')) as $file) {
            if (!str_ends_with($file, 'AmazonOperations.php')) {
                $sources .= file_get_contents($file);
            }
        }
        $notBuilt = [
            'amazon.feeds_create_document', 'amazon.feeds_upload', 'amazon.feeds_create', 'amazon.feeds_get', 'amazon.feeds_document', 'amazon.product_type_definition', 'amazon.listing_get',
            'amazon.catalog_search', 'amazon.finances_groups', 'amazon.reports_create', 'amazon.reports_get', 'amazon.reports_document', 'amazon.report_download', 'amazon.notifications_read', 'amazon.notifications_write',
        ];

        foreach ($notBuilt as $operation) {
            $this->assertStringNotContainsString("'{$operation}'", $sources, "{$operation} is called, so the matrix may not say it is not built.");
        }
        foreach (['amazon.listings_search', 'amazon.listings_patch_price', 'amazon.listings_patch_quantity', 'amazon.listings_validate', 'amazon.orders_search', 'amazon.order_get', 'amazon.order_confirm_shipment', 'amazon.fba_inventory', 'amazon.finances_transactions'] as $operation) {
            $this->assertStringContainsString("'{$operation}'", $sources, "{$operation} is what the matrix calls built.");
        }
    }
}
