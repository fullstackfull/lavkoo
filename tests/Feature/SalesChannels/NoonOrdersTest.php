<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Models\ExternalOrderMapping;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\ChannelAccountService;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Exceptions\RecipientUnavailable;
use App\Services\SalesChannels\Noon\NoonConnector;
use App\Services\SalesChannels\Support\ChannelOperations;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

/**
 * Reading orders noon sends us to fulfil from our OWN warehouse (FBPI).
 *
 * This is the order shape with the least in it, and most of this file is about what noon does NOT publish — because each absence is something a careless mapper would
 * invent:
 *
 *  - **an item has no quantity.** One `items[]` entry is one unit, and entries sharing a `partner_sku` become one line whose quantity is how many there are. Every
 *    `mp_item_nr` is kept, because a shipment is built from them and nothing else identifies the unit;
 *  - **an order has no status.** It is derived from the items' `mp_status` and `integration_status`, and `externalStatus` carries the real values rather than a word noon
 *    never used;
 *  - **the money is not there.** No order total, shipping, tax, discount, payment method or cash-on-delivery flag. The one money field is `delivered_invoice_price`, a
 *    DELIVERED figure a fresh order does not have. Nothing is estimated from any of it;
 *  - **the buyer's details are half an address.** First name, last name, city, administrative division. No street, no phone, no e-mail. So a dispatch is refused rather
 *    than a courier sent half an address.
 *
 * Every response is a fixture shaped after noon's own published schemas. No test reaches noon, and nothing here proves a real seller account works.
 */
class NoonOrdersTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private const PRODUCTION = 'https://noon-api-gateway.noon.partners';
    private const LOGIN = self::PRODUCTION . '/identity/public/v1/api/login';
    private const ORDERS = self::PRODUCTION . '/fbpi/v1/fbpi-orders/list';
    private const ORDER = self::PRODUCTION . '/fbpi/v1/fbpi-order/NO-1/get';
    private const CUSTOMER = self::PRODUCTION . '/fbpi/v1/fbpi-order/NO-1/customer-details/get';
    private const ORDER_UPDATE = self::PRODUCTION . '/fbpi/v1/fbpi-order/update';
    private const WAREHOUSE = 'WH-RUH-01';

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-14 12:00:00 UTC'));
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true, 'sales_channels.buyer_data_transfer_enabled' => true]);
        $this->account = $this->noonAccount();
    }

    // ---- the request noon documents ------------------------------------------------------------------------------

    public function test_the_sweep_names_one_warehouse_and_filters_on_creation_time_in_utc(): void
    {
        $this->fake([self::ORDERS => Http::response($this->page([$this->order()]))]);

        app(NoonConnector::class)->orders($this->account, CarbonImmutable::parse('2026-11-01 08:30:00 UTC'), null);

        $body = $this->bodyOf(self::ORDERS);
        $this->assertSame(self::WAREHOUSE, $body['warehouse_code'], 'noon lists orders for one named warehouse at a time');
        $this->assertSame('2026-11-01T08:30:00Z', $body['created_after']);
        $this->assertSame('2026-11-14T12:00:00Z', $body['created_before']);
    }

    public function test_the_page_token_rides_in_the_query_and_not_in_the_body(): void
    {
        // Sent in the body it would simply be ignored, and every page would be the first one.
        $this->fake([self::ORDERS => Http::response($this->page([$this->order()], 'TOKEN-2'))]);

        app(NoonConnector::class)->orders($this->account, CarbonImmutable::parse('2026-11-01 08:30:00 UTC'), ['next_token' => 'TOKEN-1', 'until' => '2026-11-14T12:00:00Z']);

        $this->assertStringContainsString('next_token=TOKEN-1', $this->sentTo(self::ORDERS)[0]->url());
        $this->assertArrayNotHasKey('next_token', $this->bodyOf(self::ORDERS));
    }

    public function test_the_first_page_sends_an_empty_token_as_noons_own_samples_do(): void
    {
        $this->fake([self::ORDERS => Http::response($this->page([$this->order()]))]);

        app(NoonConnector::class)->orders($this->account, CarbonImmutable::parse('2026-11-01 08:30:00 UTC'), null);

        $this->assertStringContainsString('next_token=', $this->sentTo(self::ORDERS)[0]->url());
    }

    public function test_an_account_with_no_warehouse_cannot_read_orders_and_sends_nothing(): void
    {
        // A missing setting is its own problem: not a capability nobody switched on, and not noon refusing anything.
        $this->account->forceFill(['settings' => ['country_code' => 'sa']])->save();
        $this->fake([self::ORDERS => Http::response($this->page([$this->order()]))]);

        try {
            app(NoonConnector::class)->orders($this->account->fresh(), CarbonImmutable::parse('2026-11-01'), null);
            $this->fail('a warehouse nobody chose must stop the read');
        } catch (ChannelUnavailableException $exception) {
            $this->assertSame(ChannelUnavailableException::SETTING_MISSING, $exception->reason);
        }
        $this->assertSame([], $this->sentTo(self::ORDERS));
    }

    // ---- where the pages end, which noon does not document -------------------------------------------------------

    public function test_a_token_noon_gave_carries_the_sweep_to_the_next_page(): void
    {
        $this->fake([self::ORDERS => Http::response($this->page([$this->order()], 'TOKEN-2'))]);

        $pages = app(NoonConnector::class)->orders($this->account, CarbonImmutable::parse('2026-11-01'), null);

        $this->assertTrue($pages->hasMore());
        $this->assertSame('TOKEN-2', $pages->nextCursor['next_token']);
    }

    public function test_an_empty_token_ends_the_run(): void
    {
        $this->fake([self::ORDERS => Http::response($this->page([$this->order()], ''))]);

        $this->assertFalse(app(NoonConnector::class)->orders($this->account, CarbonImmutable::parse('2026-11-01'), null)->hasMore());
    }

    public function test_a_token_identical_to_the_one_just_sent_ends_the_run_rather_than_looping_for_ever(): void
    {
        // noon does not document what the last page's token is. An unchanged one would have the sweep ask for the same page until something else stopped it.
        $this->fake([self::ORDERS => Http::response($this->page([$this->order()], 'TOKEN-1'))]);

        $pages = app(NoonConnector::class)->orders($this->account, CarbonImmutable::parse('2026-11-01'), ['next_token' => 'TOKEN-1', 'until' => '2026-11-14T12:00:00Z']);

        $this->assertFalse($pages->hasMore());
    }

    public function test_a_page_with_no_orders_ends_the_run_whatever_token_came_with_it(): void
    {
        $this->fake([self::ORDERS => Http::response($this->page([], 'TOKEN-9'))]);

        $this->assertFalse(app(NoonConnector::class)->orders($this->account, CarbonImmutable::parse('2026-11-01'), null)->hasMore());
    }

    public function test_the_window_a_cursor_was_issued_for_stays_the_same_across_its_pages(): void
    {
        // Asking "up to now" afresh on every page would keep moving the end of the window, and the sweep would never catch up with it.
        $this->fake([self::ORDERS => Http::response($this->page([$this->order()], 'TOKEN-3'))]);

        app(NoonConnector::class)->orders($this->account, CarbonImmutable::parse('2026-11-01'), ['next_token' => 'TOKEN-2', 'until' => '2026-11-10T00:00:00Z']);

        $this->assertSame('2026-11-10T00:00:00Z', $this->bodyOf(self::ORDERS)['created_before']);
    }

    public function test_an_order_with_no_number_is_skipped_and_counted_rather_than_mapped(): void
    {
        $this->fake([self::ORDERS => Http::response($this->page([$this->order(), ['mp_order_nr' => 'no-fbpi-number']]))]);

        $pages = app(NoonConnector::class)->orders($this->account, CarbonImmutable::parse('2026-11-01'), null);

        $this->assertCount(1, $pages->items);
        $this->assertSame(1, $pages->skipped);
    }

    // ---- an item has no quantity, so the units are counted -------------------------------------------------------

    public function test_units_sharing_a_partner_sku_become_one_line_whose_quantity_is_how_many_there_are(): void
    {
        $this->fake([self::ORDERS => Http::response($this->page([$this->order(items: [
            $this->unit('I-1', 'SKU-1'),
            $this->unit('I-2', 'SKU-1'),
            $this->unit('I-3', 'SKU-2'),
        ])]))]);

        $lines = app(NoonConnector::class)->orders($this->account, CarbonImmutable::parse('2026-11-01'), null)->items[0]->lines;

        $this->assertCount(2, $lines);
        $this->assertSame([2, 1], array_map(static fn ($line): int => $line->quantity, $lines));
        $this->assertSame(['SKU-1', 'SKU-2'], array_map(static fn ($line): ?string => $line->sku, $lines));
    }

    public function test_every_marketplace_item_number_is_kept_because_a_shipment_is_built_from_them(): void
    {
        $this->fake([self::ORDERS => Http::response($this->page([$this->order(items: [
            $this->unit('I-1', 'SKU-1'),
            $this->unit('I-2', 'SKU-1'),
        ])]))]);

        $line = app(NoonConnector::class)->orders($this->account, CarbonImmutable::parse('2026-11-01'), null)->items[0]->lines[0];

        $this->assertSame(['I-1', 'I-2'], $line->snapshot['mp_item_nrs']);
        $this->assertSame('I-1,I-2', $line->lineId);
    }

    public function test_a_unit_with_no_partner_sku_is_still_a_unit_of_the_order(): void
    {
        // Dropping it would make the order look smaller than it is. It is grouped under its own item number instead, which says plainly that we cannot match it.
        $this->fake([self::ORDERS => Http::response($this->page([$this->order(items: [
            $this->unit('I-1', 'SKU-1'),
            $this->unit('I-2', ''),
        ])]))]);

        $order = app(NoonConnector::class)->orders($this->account, CarbonImmutable::parse('2026-11-01'), null)->items[0];

        $this->assertCount(2, $order->lines);
        $this->assertNull($order->lines[1]->sku);
        $this->assertSame(2, $order->snapshot['units']);
    }

    public function test_a_cancelled_unit_is_counted_as_cancelled_and_stays_in_the_quantity(): void
    {
        $this->fake([self::ORDERS => Http::response($this->page([$this->order(items: [
            $this->unit('I-1', 'SKU-1'),
            $this->unit('I-2', 'SKU-1', mpStatus: 'MP_ITEM_STATUS_CANCELLED', reason: 'CUSTOMER_CANCELLED'),
        ])]))]);

        $line = app(NoonConnector::class)->orders($this->account, CarbonImmutable::parse('2026-11-01'), null)->items[0]->lines[0];

        $this->assertSame([2, 1], [$line->quantity, $line->quantityCancelled]);
        $this->assertSame(['CUSTOMER_CANCELLED'], $line->snapshot['cancellation_reason_codes']);
    }

    // ---- an order has no status, so it is derived -----------------------------------------------------------------

    public function test_an_order_whose_units_are_all_cancelled_is_cancelled(): void
    {
        $this->fake([self::ORDERS => Http::response($this->page([$this->order(items: [
            $this->unit('I-1', 'SKU-1', mpStatus: 'MP_ITEM_STATUS_CANCELLED'),
        ])]))]);

        $this->assertSame(ChannelOrderStatus::Cancelled, app(NoonConnector::class)->orders($this->account, CarbonImmutable::parse('2026-11-01'), null)->items[0]->status);
    }

    public function test_a_cancelled_unit_does_not_hold_an_otherwise_shipped_order_open(): void
    {
        $this->fake([self::ORDERS => Http::response($this->page([$this->order(items: [
            $this->unit('I-1', 'SKU-1', integration: 'INTEGRATION_ITEM_STATUS_SHIPPED'),
            $this->unit('I-2', 'SKU-2', mpStatus: 'MP_ITEM_STATUS_CANCELLED'),
        ])]))]);

        $this->assertSame(ChannelOrderStatus::Shipped, app(NoonConnector::class)->orders($this->account, CarbonImmutable::parse('2026-11-01'), null)->items[0]->status);
    }

    public function test_a_part_shipped_order_is_processing_and_not_shipped(): void
    {
        $this->fake([self::ORDERS => Http::response($this->page([$this->order(items: [
            $this->unit('I-1', 'SKU-1', integration: 'INTEGRATION_ITEM_STATUS_SHIPPED'),
            $this->unit('I-2', 'SKU-2', integration: 'INTEGRATION_ITEM_STATUS_ACKNOWLEDGED'),
        ])]))]);

        $this->assertSame(ChannelOrderStatus::Processing, app(NoonConnector::class)->orders($this->account, CarbonImmutable::parse('2026-11-01'), null)->items[0]->status);
    }

    public function test_an_out_of_stock_unit_leaves_the_order_processing_and_never_failed(): void
    {
        // noon cancels that unit and lets the rest proceed, so the ORDER is still alive.
        $this->fake([self::ORDERS => Http::response($this->page([$this->order(items: [
            $this->unit('I-1', 'SKU-1', integration: 'INTEGRATION_ITEM_STATUS_OUT_OF_STOCK'),
        ])]))]);

        $this->assertSame(ChannelOrderStatus::Processing, app(NoonConnector::class)->orders($this->account, CarbonImmutable::parse('2026-11-01'), null)->items[0]->status);
    }

    public function test_the_external_status_carries_noons_real_values_and_not_a_word_it_never_used(): void
    {
        $this->fake([self::ORDERS => Http::response($this->page([$this->order(items: [
            $this->unit('I-1', 'SKU-1', integration: 'INTEGRATION_ITEM_STATUS_SHIPPED'),
        ])]))]);

        $external = app(NoonConnector::class)->orders($this->account, CarbonImmutable::parse('2026-11-01'), null)->items[0]->externalStatus;

        $this->assertStringContainsString('mp:CONFIRMED', $external);
        $this->assertStringContainsString('integration:SHIPPED', $external);
    }

    // ---- the money noon does not publish -------------------------------------------------------------------------

    public function test_the_totals_noon_publishes_none_of_are_zero_and_said_to_be_so(): void
    {
        $this->fake([self::ORDERS => Http::response($this->page([$this->order()]))]);

        $order = app(NoonConnector::class)->orders($this->account, CarbonImmutable::parse('2026-11-01'), null)->items[0];

        $this->assertTrue($order->shippingTotal->isZero());
        $this->assertTrue($order->taxTotal->isZero());
        $this->assertTrue($order->discountTotal->isZero());
        // Said in so many words, so nobody reads those zeroes as figures noon gave us.
        $this->assertTrue($order->snapshot['noon_publishes_no_order_totals']);
        $this->assertTrue($order->snapshot['noon_publishes_no_settlement_endpoint']);
    }

    public function test_a_delivered_invoice_price_is_summed_over_the_units_of_the_line(): void
    {
        $this->fake([self::ORDERS => Http::response($this->page([$this->order(items: [
            $this->unit('I-1', 'SKU-1', price: 49.5),
            $this->unit('I-2', 'SKU-1', price: 49.5),
        ])]))]);

        $order = app(NoonConnector::class)->orders($this->account, CarbonImmutable::parse('2026-11-01'), null)->items[0];

        $this->assertSame('99.000000', $order->lines[0]->lineTotal->toStorage());
        $this->assertSame('99.000000', $order->orderTotal->toStorage());
    }

    public function test_a_unit_noon_gave_no_price_for_adds_nothing_and_is_counted(): void
    {
        // `delivered_invoice_price` is a DELIVERED figure: a fresh order does not have one, and reading its absence as a sale at nothing would put a false zero into the
        // revenue of this channel.
        $this->fake([self::ORDERS => Http::response($this->page([$this->order(items: [
            $this->unit('I-1', 'SKU-1', price: 49.5),
            $this->unit('I-2', 'SKU-1', price: 0),
            $this->unit('I-3', 'SKU-1'),
        ])]))]);

        $line = app(NoonConnector::class)->orders($this->account, CarbonImmutable::parse('2026-11-01'), null)->items[0]->lines[0];

        $this->assertSame('49.500000', $line->lineTotal->toStorage());
        $this->assertSame(3, $line->quantity);
        $this->assertSame(2, $line->snapshot['units_noon_gave_no_price_for']);
    }

    public function test_nothing_is_claimed_about_payment_or_cash_on_delivery(): void
    {
        $this->fake([self::ORDERS => Http::response($this->page([$this->order()]))]);

        $order = app(NoonConnector::class)->orders($this->account, CarbonImmutable::parse('2026-11-01'), null)->items[0];

        $this->assertFalse($order->isCod);
        $this->assertNull($order->paymentMethod);
    }

    public function test_an_fbpi_order_is_the_sellers_own_fulfilment(): void
    {
        $this->fake([self::ORDERS => Http::response($this->page([$this->order()]))]);

        $order = app(NoonConnector::class)->orders($this->account, CarbonImmutable::parse('2026-11-01'), null)->items[0];

        $this->assertSame('merchant_managed', $order->fulfillmentMode->value);
        $this->assertSame('fbpi', $order->fulfillmentChannelRaw);
        $this->assertSame('', $order->packageId, 'noon has no package of its own: a shipment is ours to create');
    }

    public function test_an_unreadable_creation_time_is_left_unknown_rather_than_set_to_now(): void
    {
        // "Now" would make an old order look like a new one, and a sweep would keep finding it.
        $this->fake([self::ORDERS => Http::response($this->page([$this->order(createdAt: 'not a date')]))]);

        $this->assertNull(app(NoonConnector::class)->orders($this->account, CarbonImmutable::parse('2026-11-01'), null)->items[0]->purchasedAt);
    }

    // ---- one order, by its number in the path --------------------------------------------------------------------

    public function test_one_order_is_read_with_its_number_in_the_path(): void
    {
        $this->fake([self::ORDER => Http::response($this->order())]);

        $order = app(NoonConnector::class)->order($this->account, 'NO-1');

        $this->assertSame('NO-1', $order?->orderId);
        $this->assertSame('GET', $this->sentTo(self::ORDER)[0]->method());
    }

    public function test_an_order_noon_does_not_know_is_null_rather_than_an_empty_order(): void
    {
        $this->fake([self::ORDER => Http::response([], 404)]);

        $this->assertNull(app(NoonConnector::class)->order($this->account, 'NO-1'));
    }

    public function test_an_answer_with_no_order_number_is_not_an_order(): void
    {
        $this->fake([self::ORDER => Http::response(['items' => []])]);

        $this->assertNull(app(NoonConnector::class)->order($this->account, 'NO-1'));
    }

    // ---- the buyer: half an address, and said to be --------------------------------------------------------------

    public function test_the_buyer_call_needs_the_transfer_approval_no_other_noon_read_needs(): void
    {
        $this->account->forceFill(['capability_approvals' => ['order_import' => ['by' => 1]]])->save();
        $this->fake([self::CUSTOMER => Http::response($this->customer())]);

        $this->expectException(ChannelUnavailableException::class);
        try {
            app(NoonConnector::class)->recipient($this->account->fresh(), $this->mapping());
        } finally {
            $this->assertSame([], $this->sentTo(self::CUSTOMER), 'nothing about a person leaves without the approval');
        }
    }

    public function test_noon_gives_a_name_and_a_city_and_no_address_so_the_dispatch_is_refused(): void
    {
        // THE GAP, surfacing through the machinery that already exists rather than through a special case: noon documents no street, phone or e-mail, so the recipient is
        // incomplete and a courier is never sent half an address.
        $this->fake([self::CUSTOMER => Http::response($this->customer())]);

        $recipient = app(NoonConnector::class)->recipient($this->account, $this->mapping());

        $this->assertContains('address_missing', $recipient->problems());
        $this->assertContains('phone_missing', $recipient->problems());
        $this->assertNotContains('name_missing', $recipient->problems());
        $this->assertNotContains('city_missing', $recipient->problems());
    }

    public function test_an_order_noon_cannot_find_is_reported_as_such_and_not_as_an_empty_buyer(): void
    {
        $this->fake([self::CUSTOMER => Http::response([], 404)]);

        try {
            app(NoonConnector::class)->recipient($this->account, $this->mapping());
            $this->fail('a 404 is not a buyer');
        } catch (RecipientUnavailable $exception) {
            $this->assertSame(RecipientUnavailable::NOT_FOUND, $exception->reason);
        }
    }

    public function test_a_failed_buyer_read_names_no_part_of_the_buyer(): void
    {
        $this->fake([self::CUSTOMER => Http::response(['first_name' => 'Somebody'], 500)]);

        try {
            app(NoonConnector::class)->recipient($this->account, $this->mapping());
            $this->fail('a 500 is not a buyer');
        } catch (RecipientUnavailable $exception) {
            $this->assertSame(RecipientUnavailable::CHANNEL_ERROR, $exception->reason);
            $this->assertStringNotContainsString('Somebody', $exception->getMessage());
        }
    }

    // ---- telling noon we cannot supply something -----------------------------------------------------------------

    public function test_an_out_of_stock_notice_names_the_units_and_the_only_status_noon_accepts(): void
    {
        $this->fake([self::ORDER_UPDATE => Http::response([])]);

        $result = app(NoonConnector::class)->cannotSupply($this->account, 'NO-1', ['I-2', 'I-3']);

        $this->assertTrue($result['sent']);
        $this->assertSame([
            'fbpi_order_nr' => 'NO-1',
            'items' => [
                ['mp_item_nr' => 'I-2', 'status' => 'UPDATE_ORDER_REQUEST_ITEM_STATUS_OUT_OF_STOCK'],
                ['mp_item_nr' => 'I-3', 'status' => 'UPDATE_ORDER_REQUEST_ITEM_STATUS_OUT_OF_STOCK'],
            ],
        ], $this->bodyOf(self::ORDER_UPDATE));
    }

    public function test_an_empty_list_of_units_sends_nothing_because_noon_cancels_what_this_names(): void
    {
        $this->fake([self::ORDER_UPDATE => Http::response([])]);

        $result = app(NoonConnector::class)->cannotSupply($this->account, 'NO-1', []);

        $this->assertFalse($result['sent']);
        $this->assertStringContainsString('never sent on a guess', (string) $result['refused']);
        $this->assertSame([], $this->sentTo(self::ORDER_UPDATE));
    }

    public function test_a_refused_notice_cancelled_nothing_and_says_so(): void
    {
        // The 200 carries no body, so the absence of a refusal is all there is. That is weak, which is why a refusal is reported plainly rather than swallowed.
        $this->fake([self::ORDER_UPDATE => Http::response(['message' => 'order already shipped'], 409)]);

        $result = app(NoonConnector::class)->cannotSupply($this->account, 'NO-1', ['I-2']);

        $this->assertFalse($result['sent']);
        $this->assertStringContainsString('nothing was cancelled', (string) $result['refused']);
    }

    public function test_without_the_dispatch_capability_no_out_of_stock_notice_leaves(): void
    {
        $this->account->forceFill(['capabilities' => ['connection_test', 'order_import']])->save();
        $this->fake([self::ORDER_UPDATE => Http::response([])]);

        $this->expectException(ChannelUnavailableException::class);
        try {
            app(NoonConnector::class)->cannotSupply($this->account->fresh(), 'NO-1', ['I-2']);
        } finally {
            $this->assertSame([], $this->sentTo(self::ORDER_UPDATE));
        }
    }

    public function test_a_rehearsing_account_cancels_nothing_at_noon(): void
    {
        $this->account->forceFill(['dry_run' => true])->save();
        $this->fake([self::ORDER_UPDATE => Http::response([])]);

        $result = app(NoonConnector::class)->cannotSupply($this->account->fresh(), 'NO-1', ['I-2']);

        $this->assertFalse($result['sent']);
        $this->assertNull($result['refused'], 'a rehearsal is not a refusal');
    }

    public function test_a_rehearsing_account_still_reads_orders_because_reading_changes_nothing(): void
    {
        // A rehearsal withholds WRITES. Reading what noon already holds changes nothing there, and an account being set up needs to see its orders before any write is
        // switched on -- that is the whole point of rehearsing. The out-of-stock notice above is the write, and that one is withheld.
        $this->account->forceFill(['dry_run' => true])->save();
        $this->fake([self::ORDERS => Http::response($this->page([$this->order()]))]);

        $pages = app(NoonConnector::class)->orders($this->account->fresh(), CarbonImmutable::parse('2026-11-01'), null);

        $this->assertCount(1, $pages->items);
        $this->assertCount(1, $this->sentTo(self::ORDERS));
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------

    private function noonAccount(): SalesChannelAccount
    {
        $account = app(ChannelAccountService::class)->create(
            ['channel' => 'noon', 'name' => 'noon KSA', 'external_account_id' => 'partner-1', 'currency' => 'SAR', 'settings' => ['warehouse_code' => self::WAREHOUSE, 'country_code' => 'sa']],
            ['key_id' => 'key-abc', 'private_key' => $this->aRealPrivateKey(), 'project_code' => 'proj_x', 'channel_identifier' => 'noon'],
            adminId: 1,
        );
        $account->forceFill([
            'enabled' => true, 'write_enabled' => true, 'dry_run' => false,
            'capabilities' => ['connection_test', 'read_only_sync', 'order_import', 'fulfillment_dispatch'],
            'capability_approvals' => [
                'order_import' => ['by' => 1],
                'fulfillment_dispatch' => ['by' => 1],
                ChannelOperations::SCOPE_BUYER_DATA_TRANSFER => ['by' => 1],
            ],
        ])->save();

        return $account->fresh();
    }

    private function mapping(): ExternalOrderMapping
    {
        return ExternalOrderMapping::create([
            'account_id' => $this->account->id,
            'external_order_id' => 'NO-1',
            'sales_channel' => 'noon',
            'external_package_id' => '',
            'external_status' => 'mp:CONFIRMED integration:ACKNOWLEDGED',
            'status' => ChannelOrderStatus::New->value,
        ]);
    }

    /**
     * One FBPI order, as noon's schema prints it.
     *
     * @param list<array<string, mixed>>|null $items
     * @return array<string, mixed>
     */
    private function order(?array $items = null, string $createdAt = '2026-11-10T09:15:00Z'): array
    {
        return [
            'fbpi_order_nr' => 'NO-1',
            'mp_code' => 'noon',
            'mp_order_nr' => 'MP-777',
            'mp_country_code' => 'sa',
            'customer_country_code' => 'sa',
            'merchant_code' => 'STR-ACME',
            'currency_code' => 'SAR',
            'warehouse_code' => self::WAREHOUSE,
            'order_created_at' => $createdAt,
            'items' => $items ?? [$this->unit('I-1', 'SKU-1')],
        ];
    }

    /** @return array<string, mixed> */
    private function unit(
        string $itemNumber,
        string $sku,
        string $mpStatus = 'MP_ITEM_STATUS_CONFIRMED',
        string $integration = 'INTEGRATION_ITEM_STATUS_ACKNOWLEDGED',
        ?float $price = null,
        ?string $reason = null,
    ): array {
        return array_filter([
            'mp_item_nr' => $itemNumber,
            'partner_sku' => $sku,
            'mp_status' => $mpStatus,
            'integration_status' => $integration,
            'delivered_invoice_price' => $price,
            'cancellation_reason_code' => $reason,
            'estimated_shipping_date' => '2026-11-12T00:00:00Z',
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * @param list<array<string, mixed>> $orders
     * @return array<string, mixed>
     */
    private function page(array $orders, string $token = 'TOKEN-NEXT'): array
    {
        return ['next_token' => $token, 'orders' => $orders];
    }

    /** @return array<string, mixed> */
    private function customer(): array
    {
        // Everything noon documents, and nothing more: no street, no phone, no e-mail.
        return ['first_name' => 'Layla', 'last_name' => 'Al-Harbi', 'city' => 'Riyadh', 'administrative_division' => 'Riyadh Region'];
    }

    private function aRealPrivateKey(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        return (string) $pem;
    }

    /**
     * Every pattern gets a trailing wildcard: the orders call carries `next_token` in its query, and a fake keyed on the bare path would not match it.
     *
     * @param array<string, mixed> $responses
     */
    private function fake(array $responses): void
    {
        $patterns = [self::LOGIN => Http::response('', 200, ['Set-Cookie' => 'noon_session=abc123; Path=/'])];
        foreach ($responses as $url => $response) {
            $patterns[$url . '*'] = $response;
        }

        Http::fake($patterns);
    }

    /** @return array<string, mixed> */
    private function bodyOf(string $url): array
    {
        return (array) $this->sentTo($url)[0]->data();
    }

    /** @return list<Request> */
    private function sentTo(string $needle): array
    {
        return Http::recorded(static fn (Request $request): bool => str_contains($request->url(), $needle))->values()->map(static fn (array $pair): Request => $pair[0])->all();
    }
}
