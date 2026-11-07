<?php

namespace Tests\Feature\SalesChannels;

use App\Models\ExternalOrderMapping;
use App\Models\InventoryLocation;
use App\Models\InventoryLocationBalance;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelApiLog;
use App\Models\SalesChannelProductMapping;
use App\Models\SalesChannelSyncState;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use App\Services\SalesChannels\Support\ChannelCredentialStore;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Sleep;
use Tests\Support\Fixtures\TrendyolFixtures as Trendyol;

/** Everything on an account page that calls a channel: what is sent, what is only rehearsed, and how each outcome is reported. */
class AdminSalesChannelsOperationsTest extends AdminSalesChannelsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    /** A clean fake: a later Http::fake() adds stubs behind the earlier ones. */
    private function fakeOnly(array $stubs): void
    {
        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    /** @return list<Request> */
    private function sent(string $needle = ''): array
    {
        return Http::recorded(fn (Request $request): bool => str_contains($request->url(), $needle))->values()->map(fn (array $pair): Request => $pair[0])->all();
    }

    private function show(SalesChannelAccount $account): string
    {
        return $this->get(route('admin.sales-channels.accounts.show', $account))->assertOk()->getContent();
    }

    // ---- connection test ----------------------------------------------------------------------------------

    public function test_a_connection_test_only_reads_and_a_good_result_switches_nothing_on(): void
    {
        $account = $this->trendyolAccount(['connection_test']);
        $this->fakeOnly([self::TY . '/*' => Http::response(Trendyol::stream([]))]);
        $before = $account->only(['enabled', 'write_enabled', 'dry_run', 'capabilities', 'capability_approvals']);

        $this->post(route('admin.sales-channels.accounts.test', $account))->assertRedirect(route('admin.sales-channels.accounts.show', $account));

        $account->refresh();
        $this->assertSame('ok', $account->connection_status);
        $this->assertSame(['products:read', 'orders:read', 'finance:read'], $account->granted_permissions);
        $this->assertSame($before, $account->only(array_keys($before)), 'a test never enables, allows or approves anything');
        $this->assertToastContains(translate('connection_test_passed'));
        $this->assertSame(['GET'], array_values(array_unique(array_map(fn (Request $request): string => $request->method(), $this->sent()))), 'a test only reads');
        $this->assertSame(3, SalesChannelApiLog::count(), 'and every call it made is logged');

        $html = $this->show($account);
        $this->assertStringContainsString(e(translate('connection_status_ok')), $html);
        $this->assertStringContainsString('products:read', $html);
        $this->assertStringNotContainsString(e(translate('switch_on')) . '</button>', substr($html, (int) strpos($html, 'id="capability-fulfillment_dispatch"'), 200), 'no button switches dispatch on because the test went well');
    }

    public function test_a_test_that_found_gaps_says_so_and_lists_what_is_missing(): void
    {
        $account = $this->trendyolAccount(['connection_test']);
        $this->fakeOnly([
            self::TY . '/product/*' => Http::response('', 500),
            self::TY . '/order/*' => Http::response(Trendyol::stream([])),
            self::TY . '/finance/*' => Http::response(Trendyol::statement([])),
        ]);

        $this->post(route('admin.sales-channels.accounts.test', $account));

        $this->assertToastContains(translate('connection_test_passed_with_gaps'));
        $this->assertSame(['products:read'], $account->fresh()->missing_permissions);
        $this->assertStringContainsString('products:read', $this->show($account));
    }

    public function test_a_refused_key_is_a_failed_test_that_marks_the_account_and_shows_the_channels_words_as_text(): void
    {
        $account = $this->trendyolAccount(['connection_test']);
        $this->fakeOnly([self::TY . '/*' => Http::response(['status' => 401, 'exception' => '<b>ClientApiAuthenticationException</b>'], 401)]);

        $this->post(route('admin.sales-channels.accounts.test', $account));

        $this->assertToastContains(translate('connection_test_failed'));
        $this->assertToastContains('rejected the API key or secret');
        $this->assertSame('auth_expired', $account->fresh()->connection_status);
        $this->assertStringContainsString(route('admin.sales-channels.accounts.show', $account), $this->get(route('admin.sales-channels.index'))->getContent(), 'the hub lists it as needing a new authorisation');
    }

    public function test_with_the_global_switch_off_nothing_leaves_the_host_and_the_refusal_is_the_answer(): void
    {
        config(['sales_channels.enabled' => false]);
        $account = $this->trendyolAccount(['connection_test']);

        $this->post(route('admin.sales-channels.accounts.test', $account));

        $this->assertToastContains(translate('connection_test_failed'));
        $this->assertToastContains('Sales channel integrations are switched off');
        Http::assertNothingSent();
    }

    // ---- sync ---------------------------------------------------------------------------------------------

    public function test_orders_are_read_and_the_counts_are_reported(): void
    {
        $account = $this->trendyolAccount();
        $this->fakeOnly([self::TY . '/order/sellers/*' => Http::response(Trendyol::stream([Trendyol::package()]))]);

        $this->post(route('admin.sales-channels.accounts.sync', ['account' => $account, 'resource' => 'orders']))->assertRedirect(route('admin.sales-channels.accounts.show', $account));

        $this->assertToastContains(translate('run_finished'));
        $this->assertToastContains(translate('run_stat_created') . ': 1');
        $this->assertSame(1, ExternalOrderMapping::count());
        $this->assertNotNull(SalesChannelSyncState::where(['account_id' => $account->id, 'resource' => 'orders'])->firstOrFail()->last_success_at);
        $this->assertStringContainsString(e(translate('ok')), $this->show($account), 'the last run is shown in the table');
    }

    public function test_listings_returns_and_statements_are_read_the_same_way(): void
    {
        $account = $this->trendyolAccount();
        $this->fakeOnly([
            self::TY . '/product/*' => Http::sequence()->push(Trendyol::approvedPage([Trendyol::content()]))->push(Trendyol::approvedPage([])),
            self::TY . '/finance/*' => Http::response(Trendyol::statement([])),
            self::TY . '/order/*' => Http::response(Trendyol::claimsPage([])),
        ]);

        $this->post(route('admin.sales-channels.accounts.sync', ['account' => $account, 'resource' => 'listings']));
        $this->assertToastContains(translate('run_finished'));
        $this->assertSame(1, SalesChannelProductMapping::count());
        $this->assertSame('unmapped', SalesChannelProductMapping::firstOrFail()->status, 'reading a channel links nothing');

        $this->forgetToasts();
        $this->post(route('admin.sales-channels.accounts.sync', ['account' => $account, 'resource' => 'returns']));
        $this->assertToastContains(translate('run_finished'));

        $this->forgetToasts();
        $this->post(route('admin.sales-channels.accounts.sync', ['account' => $account, 'resource' => 'finances']));
        $this->assertToastContains(translate('run_finished'));
        $this->assertSame(['listings', 'returns', 'finances'], SalesChannelSyncState::orderBy('id')->pluck('resource')->all());
    }

    public function test_a_resource_the_channel_cannot_be_read_for_has_no_button(): void
    {
        $trendyol = $this->trendyolAccount();
        $amazon = $this->amazonAccount();

        $trendyolPage = $this->show($trendyol);
        $this->assertStringContainsString(route('admin.sales-channels.accounts.sync', ['account' => $trendyol, 'resource' => 'returns']), $trendyolPage);
        $this->assertStringNotContainsString(route('admin.sales-channels.accounts.sync', ['account' => $trendyol, 'resource' => 'inventory']), $trendyolPage, 'Trendyol holds no stock of ours to read');
        $this->assertStringContainsString(e(translate('this_channel_has_nothing_to_read_here')), $trendyolPage);

        $amazonPage = $this->show($amazon);
        $this->assertStringContainsString(route('admin.sales-channels.accounts.sync', ['account' => $amazon, 'resource' => 'inventory']), $amazonPage);
        $this->assertStringNotContainsString(route('admin.sales-channels.accounts.sync', ['account' => $amazon, 'resource' => 'returns']), $amazonPage);
    }

    public function test_a_resource_that_is_not_switched_on_is_skipped_and_nothing_is_asked(): void
    {
        $account = $this->trendyolAccount(['connection_test']);
        Http::fake();

        $this->post(route('admin.sales-channels.accounts.sync', ['account' => $account, 'resource' => 'orders']));

        $this->assertToastContains(translate('run_skipped_nothing_asked'));
        $this->assertToastContains(translate('channel_reason_capability_off'));
        $this->assertStringNotContainsString(translate('run_finished'), implode(' ', $this->flashedToasts()), 'a skipped run is not a finished one');
        Http::assertNothingSent();
    }

    public function test_a_switch_that_is_off_is_reported_as_the_reason_nothing_happened(): void
    {
        $account = $this->trendyolAccount();
        config(['sales_channels.enabled' => false]);

        $this->post(route('admin.sales-channels.accounts.sync', ['account' => $account, 'resource' => 'orders']));

        $this->assertToastContains(translate('channel_reason_global_off'));
        $account->forceFill(['enabled' => false])->save();
        config(['sales_channels.enabled' => true]);
        $this->forgetToasts();
        $this->post(route('admin.sales-channels.accounts.sync', ['account' => $account, 'resource' => 'orders']));
        $this->assertToastContains(translate('channel_reason_account_off'));
        Http::assertNothingSent();
    }

    public function test_a_run_that_is_already_going_is_not_started_again(): void
    {
        $account = $this->trendyolAccount();
        SalesChannelSyncState::create(['account_id' => $account->id, 'resource' => 'orders', 'lease_token' => 'held', 'lease_expires_at' => now()->addMinutes(10)]);

        $this->post(route('admin.sales-channels.accounts.sync', ['account' => $account, 'resource' => 'orders']));

        $this->assertToastContains(translate('run_busy_explained'));
        Http::assertNothingSent();
        $this->assertStringContainsString(e(translate('running')), $this->show($account));
    }

    public function test_a_channel_that_says_slow_down_is_a_pause_not_a_failure(): void
    {
        $account = $this->trendyolAccount();
        $this->fakeOnly([self::TY . '/order/sellers/*' => Http::response(['errors' => [['key' => 'too.many.requests']]], 429, ['Retry-After' => '45'])]);

        $this->post(route('admin.sales-channels.accounts.sync', ['account' => $account, 'resource' => 'orders']));

        $this->assertToastContains(translate('run_rate_limited_explained'));
        $this->assertToastContains('45');
        $state = SalesChannelSyncState::where(['account_id' => $account->id, 'resource' => 'orders'])->firstOrFail();
        $this->assertSame(0, $state->consecutive_failures, 'a rate limit is not counted as a failure');
        $this->assertNotNull($state->backoff_until);
    }

    public function test_a_failed_run_says_so_and_what_the_channel_said_is_shown_as_text(): void
    {
        $account = $this->trendyolAccount();
        $this->fakeOnly([self::TY . '/order/sellers/*' => Http::response(['errors' => [['message' => '<script>alert(1)</script>']]], 400)]);

        $this->post(route('admin.sales-channels.accounts.sync', ['account' => $account, 'resource' => 'orders']));

        $this->assertToastContains(translate('run_failed_explained'));
        $shown = implode(' ', $this->flashedToasts());
        $this->assertStringNotContainsString('<script>', $shown, 'a toast is HTML: the channel\'s words must be escaped');
        $this->assertStringContainsString('&lt;script&gt;', $shown);
        $html = $this->show($account);
        $this->assertStringContainsString(e(translate('failing')), $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_asking_to_read_what_the_channel_cannot_give_is_skipped_with_the_reason_and_leaves_no_failure_behind(): void
    {
        $account = $this->trendyolAccount();

        $this->post(route('admin.sales-channels.accounts.sync', ['account' => $account, 'resource' => 'inventory']))->assertRedirect();

        $this->assertToastContains(translate('run_skipped_nothing_asked'));
        $this->assertToastContains(translate('channel_reason_not_supported_by_channel'));
        $this->assertSame(0, SalesChannelSyncState::where(['account_id' => $account->id, 'resource' => 'inventory'])->count(), 'a resource that can never be read must not start backing off');
        Http::assertNothingSent();
    }

    // ---- publish ------------------------------------------------------------------------------------------

    private function publishable(SalesChannelAccount $account, array $listing = []): SalesChannelProductMapping
    {
        $product = $this->product();
        $own = app(InventoryLocationService::class)->ensureDefaults()[2];
        InventoryLocationBalance::create(['location_id' => $own->id, 'product_id' => $product, 'variant_key' => '', 'sku' => 'SKU-PUB', 'available' => 20, 'quality' => 'manual', 'source_synced_at' => now()]);

        return $this->listing($account, $listing + [
            'status' => 'mapped', 'product_id' => $product, 'listing_key' => 'BC-PUB', 'external_sku' => 'SKU-PUB', 'external_barcode' => 'BC-PUB',
            'stock_location_id' => $own->id, 'inventory_publish' => true, 'price_publish' => true, 'price_source' => 'manual', 'snapshot' => ['manual_price' => '49.95'],
        ]);
    }

    public function test_publishing_from_a_rehearsing_account_sends_nothing_and_says_it_was_a_rehearsal(): void
    {
        $account = $this->trendyolAccount(writes: false);
        $listing = $this->publishable($account);
        Http::fake();

        $this->post(route('admin.sales-channels.accounts.publish', ['account' => $account, 'kind' => 'stock']))->assertRedirect(route('admin.sales-channels.accounts.show', $account));
        $this->post(route('admin.sales-channels.accounts.publish', ['account' => $account, 'kind' => 'price']));

        Http::assertNothingSent();
        $this->assertToastContains(translate('publish_rehearsed_nothing_sent'));
        $this->assertStringNotContainsString(translate('run_finished'), implode(' ', $this->flashedToasts()));
        $this->assertSame(2, SalesChannelApiLog::where('dry_run', true)->count(), 'the exact request each time is kept in the log');
        $listing->refresh();
        $this->assertNull($listing->last_published_quantity, 'nothing is remembered as sent');
        $this->assertArrayNotHasKey('publish', (array) $listing->snapshot);
        $this->assertStringContainsString(e(translate('rehearsal_only_nothing_is_sent')), $this->show($account));
    }

    public function test_a_real_publish_needs_the_administrators_confirmation_and_then_sends_what_was_asked(): void
    {
        $this->liveWritesOn();
        $account = $this->trendyolAccount(writes: true);
        $listing = $this->publishable($account);
        $this->fakeOnly([self::TY . '/*' => Http::response(['batchRequestId' => 'batch-77'])]);
        $url = route('admin.sales-channels.accounts.publish', ['account' => $account, 'kind' => 'stock']);

        $this->from(route('admin.sales-channels.accounts.show', $account))->post($url)->assertSessionHasErrorsIn('publish_stock', 'confirm_live');
        Http::assertNothingSent();

        $this->post($url, ['confirm_live' => 1])->assertSessionHasNoErrors();

        $request = $this->sent('price-and-inventory')[0];
        $this->assertSame(['items' => [['barcode' => 'BC-PUB', 'quantity' => 20]]], $request->data());
        $this->assertSame('POST', $request->method());
        $this->assertToastContains(translate('run_finished'));
        $this->assertToastContains(translate('run_stat_accepted') . ': 1');
        $this->assertSame(['queued', 'batch-77'], [$listing->fresh()->snapshot['publish']['stock']['state'], $listing->fresh()->snapshot['publish']['stock']['ref']], 'accepted by the channel is queued, not applied');
        $page = $this->show($account);
        $this->assertStringContainsString(e(translate('this_sends_to_the_channel_for_real')), $page);
    }

    public function test_a_price_set_by_a_person_is_published_the_same_way(): void
    {
        $this->liveWritesOn();
        $account = $this->trendyolAccount(writes: true);
        $this->publishable($account);
        $this->fakeOnly([self::TY . '/*' => Http::response(['batchRequestId' => 'batch-78'])]);

        $this->post(route('admin.sales-channels.accounts.publish', ['account' => $account, 'kind' => 'price']), ['confirm_live' => 1]);

        $this->assertSame(['items' => [['barcode' => 'BC-PUB', 'salePrice' => 49.95]]], $this->sent('price-and-inventory')[0]->data());
    }

    public function test_what_nobody_vouches_for_is_not_offered_and_the_reason_is_reported(): void
    {
        $this->liveWritesOn();
        $account = $this->trendyolAccount(writes: true);
        app(InventoryLocationService::class)->ensureDefaults();
        $this->publishable($account, ['stock_location_id' => InventoryLocation::where('code', 'torod-warehouse')->value('id')]);
        Http::fake();

        $this->post(route('admin.sales-channels.accounts.publish', ['account' => $account, 'kind' => 'stock']), ['confirm_live' => 1]);

        $this->assertToastContains(translate('publish_skip_stock_not_confirmed'));
        Http::assertNothingSent();
    }

    public function test_a_switch_that_is_off_stops_a_publish_and_the_page_says_which(): void
    {
        $account = $this->trendyolAccount(writes: true);
        $this->publishable($account);
        Http::fake();

        $this->post(route('admin.sales-channels.accounts.publish', ['account' => $account, 'kind' => 'stock']));
        $this->assertToastContains(translate('channel_reason_writes_off_environment'));

        $this->liveWritesOn();
        $account->forceFill(['capability_approvals' => []])->save();
        $this->forgetToasts();
        $this->post(route('admin.sales-channels.accounts.publish', ['account' => $account, 'kind' => 'stock']), ['confirm_live' => 1]);
        $this->assertToastContains(translate('channel_reason_not_approved'));
        Http::assertNothingSent();
    }

    public function test_what_the_channel_queued_is_followed_up_on_request(): void
    {
        $account = $this->trendyolAccount(writes: true);
        $listing = $this->publishable($account, ['snapshot' => ['publish' => ['stock' => ['state' => 'queued', 'ref' => 'batch-9', 'attempted' => '20', 'at' => now()->subMinutes(5)->toIso8601String(), 'failures' => 0]]]]);
        $this->fakeOnly([self::TY . '/product/*' => Http::response(['batchRequestId' => 'batch-9', 'itemCount' => 1, 'status' => 'COMPLETED', 'items' => [['requestItem' => ['barcode' => 'BC-PUB'], 'status' => 'SUCCESS', 'failureReasons' => []]]])]);

        $this->post(route('admin.sales-channels.accounts.publish', ['account' => $account, 'kind' => 'settle']))->assertRedirect();

        $this->assertToastContains(translate('settle_finished'));
        $this->assertToastContains(translate('run_stat_applied') . ': 1');
        $this->assertSame('applied', $listing->fresh()->snapshot['publish']['stock']['state']);
    }

    public function test_a_follow_up_that_finds_a_failed_change_asks_for_attention(): void
    {
        $account = $this->trendyolAccount(writes: true);
        $listing = $this->publishable($account, ['snapshot' => ['publish' => ['stock' => ['state' => 'queued', 'ref' => 'batch-9', 'attempted' => '20', 'at' => now()->subMinutes(5)->toIso8601String(), 'failures' => 0]]]]);
        $this->fakeOnly([self::TY . '/product/*' => Http::response(['batchRequestId' => 'batch-9', 'itemCount' => 1, 'status' => 'COMPLETED', 'items' => [['requestItem' => ['barcode' => 'BC-PUB'], 'status' => 'FAILED', 'failureReasons' => ['Barcode is not found']]]])]);

        $this->post(route('admin.sales-channels.accounts.publish', ['account' => $account, 'kind' => 'settle']))->assertRedirect();

        $this->assertToastContains(translate('settle_needs_attention'));
        $this->assertToastContains(translate('run_stat_failed') . ': 1');
        $this->assertSame('failed', $listing->fresh()->snapshot['publish']['stock']['state']);
    }

    public function test_a_follow_up_that_the_channel_cannot_answer_is_reported_as_failed(): void
    {
        $account = $this->trendyolAccount(writes: true);
        $this->publishable($account, ['snapshot' => ['publish' => ['stock' => ['state' => 'queued', 'ref' => 'batch-9', 'attempted' => '20', 'at' => now()->subMinutes(5)->toIso8601String(), 'failures' => 0]]]]);
        $this->fakeOnly([self::TY . '/product/*' => Http::response('', 400)]);

        $this->post(route('admin.sales-channels.accounts.publish', ['account' => $account, 'kind' => 'settle']))->assertRedirect();

        $this->assertToastContains(translate('run_failed_explained'));
    }

    public function test_linked_shipments_are_reported_to_the_channel_only_as_a_rehearsal_from_a_rehearsing_account(): void
    {
        $account = $this->trendyolAccount(writes: false);
        $order = $this->channelOrder($account, ['status' => 'new', 'shipment_link_state' => 'linked', 'provider' => 'torod_point', 'shipment_reference' => 'TOR-1', 'tracking_number' => 'TRK-1', 'external_package_id' => '3012345678']);
        $this->fakeOnly([self::TY . '/order/sellers/*' => Http::response(Trendyol::stream([Trendyol::package(['whoPays' => 1])]))]);

        $this->post(route('admin.sales-channels.accounts.publish', ['account' => $account, 'kind' => 'shipments']))->assertRedirect();

        $this->assertToastContains(translate('publish_rehearsed_nothing_sent'));
        $this->assertSame([], array_filter($this->sent(), fn (Request $request): bool => $request->method() !== 'GET'), 'only the package was read');
        $this->assertArrayNotHasKey('channel_notified', (array) $order->fresh()->snapshot);
    }

    /** A linked shipment with a tracking number that the channel has not been told about. */
    private function shipmentToReport(SalesChannelAccount $account): ExternalOrderMapping
    {
        return $this->channelOrder($account, [
            'external_order_id' => '10234567890', 'external_package_id' => '3012345678', 'status' => 'new', 'shipment_link_state' => 'linked',
            'provider' => 'torod_point', 'shipment_reference' => 'TOR-9', 'tracking_number' => 'TRK-9',
        ]);
    }

    public function test_a_real_run_of_shipment_reports_says_how_many_were_sent(): void
    {
        $this->liveWritesOn();
        $account = $this->trendyolAccount(writes: true);
        $order = $this->shipmentToReport($account);
        $this->fakeOnly([
            self::TY . '/order/sellers/' . Trendyol::SELLER . '/v2/orders*' => Http::response(Trendyol::stream([Trendyol::package()])),
            self::TY . '/order/sellers/*' => Http::response('', 200),
        ]);

        $this->post(route('admin.sales-channels.accounts.publish', ['account' => $account, 'kind' => 'shipments']), ['confirm_live' => 1])->assertRedirect();

        $this->assertToastContains(translate('shipments_run_finished'));
        $this->assertToastContains(translate('run_stat_sent') . ': 1');
        $this->assertSame('TRK-9', $order->fresh()->snapshot['channel_notified']['tracking']);
    }

    public function test_a_real_run_in_which_a_report_fails_is_a_warning_with_the_count(): void
    {
        $this->liveWritesOn();
        $account = $this->trendyolAccount(writes: true);
        $this->shipmentToReport($account);
        $this->fakeOnly([self::TY . '/order/sellers/*' => Http::response(Trendyol::stream([Trendyol::package(['whoPays' => 1])]))]);

        $this->post(route('admin.sales-channels.accounts.publish', ['account' => $account, 'kind' => 'shipments']), ['confirm_live' => 1])->assertRedirect();

        $this->assertToastContains(translate('shipments_some_failed'));
        $this->assertToastContains(translate('run_stat_failed') . ': 1');
    }

    public function test_an_unknown_kind_of_publish_or_sync_is_not_found(): void
    {
        $account = $this->trendyolAccount();

        $this->post(route('admin.sales-channels.accounts.publish', ['account' => $account, 'kind' => 'everything']))->assertNotFound();
        $this->post(route('admin.sales-channels.accounts.sync', ['account' => $account, 'resource' => 'customers']))->assertNotFound();
    }

    // ---- webhook ------------------------------------------------------------------------------------------

    public function test_the_page_shows_the_address_to_register_at_trendyol_and_never_calls_the_channel_by_being_opened(): void
    {
        $account = $this->trendyolAccount();
        Http::fake();

        $html = $this->show($account);

        $this->assertStringContainsString('value="' . route('sales_channels.webhooks.receive', $account->id) . '"', $html);
        $this->assertStringContainsString('https://shop.example.test/api/sales-channel-webhooks/' . $account->id, $html);
        $this->assertStringContainsString(e(translate('load_registered_webhooks')), $html);
        Http::assertNothingSent();
        $this->assertStringNotContainsString('webhook-address', $this->show($this->amazonAccount()), 'only Trendyol has this webhook');
    }

    public function test_registering_the_webhook_from_a_rehearsing_account_sends_nothing(): void
    {
        $account = $this->trendyolAccount(writes: false);
        Http::fake();

        $this->post(route('admin.sales-channels.accounts.webhook', $account))->assertRedirect();

        Http::assertNothingSent();
        $this->assertToastContains(translate('publish_rehearsed_nothing_sent'));
        $this->assertToastContains(route('sales_channels.webhooks.receive', $account->id));
        $key = app(ChannelCredentialStore::class)->get($account->fresh())->secret('webhook_api_key');
        $this->assertNotNull($key, 'the key Trendyol will send back was created and stored');
        $this->assertStringNotContainsString($key, $this->show($account), 'and is never shown');
    }

    public function test_registering_the_webhook_for_real_needs_confirmation_and_sends_the_address_of_the_route(): void
    {
        $this->liveWritesOn();
        $account = $this->trendyolAccount(writes: true);
        $this->fakeOnly([self::TY . '/webhook/*' => Http::response(['id' => 'wh-42'])]);
        $url = route('admin.sales-channels.accounts.webhook', $account);

        $this->from(route('admin.sales-channels.accounts.show', $account))->post($url, ['statuses' => ['CREATED', 'SHIPPED']])->assertSessionHasErrorsIn('webhook', 'confirm_live');
        Http::assertNothingSent();

        $this->post($url, ['statuses' => ['CREATED', 'SHIPPED'], 'confirm_live' => 1])->assertSessionHasNoErrors();

        $body = $this->sent('/webhook/sellers/1234567/webhooks')[0]->data();
        $this->assertSame(['url' => 'https://shop.example.test/api/sales-channel-webhooks/' . $account->id, 'authenticationType' => 'API_KEY', 'subscribedStatuses' => ['CREATED', 'SHIPPED']], array_diff_key($body, ['apiKey' => 1]));
        $this->assertToastContains(translate('webhook_registered'));
        $this->assertToastContains('wh-42');
        $this->assertStringNotContainsString($body['apiKey'], $this->show($account), 'the key sent to Trendyol is not shown');
    }

    public function test_an_address_trendyol_would_refuse_is_reported_before_anything_is_sent(): void
    {
        URL::forceRootUrl('https://trendyol-bridge.example.test');
        $account = $this->trendyolAccount(writes: false);
        Http::fake();

        $this->post(route('admin.sales-channels.accounts.webhook', $account));

        $this->assertToastContains("refuses a webhook address containing 'trendyol'");
        Http::assertNothingSent();
    }

    public function test_the_webhook_needs_its_own_approval_and_a_status_trendyol_knows(): void
    {
        $this->liveWritesOn();
        $account = $this->trendyolAccount(writes: true);
        $account->forceFill(['capability_approvals' => []])->save();
        Http::fake();

        $this->post(route('admin.sales-channels.accounts.webhook', $account), ['confirm_live' => 1]);
        $this->assertToastContains('No one has approved this kind of write (webhook_management)');

        $this->from(route('admin.sales-channels.accounts.show', $account))->post(route('admin.sales-channels.accounts.webhook', $account), ['confirm_live' => 1, 'statuses' => ['TELEPORTED']])
            ->assertSessionHasErrorsIn('webhook', 'statuses.0');
        Http::assertNothingSent();
    }

    public function test_registered_webhooks_are_listed_only_when_asked_and_only_the_fields_worth_showing(): void
    {
        $account = $this->trendyolAccount();
        $this->fakeOnly([self::TY . '/webhook/*' => Http::response([[
            'id' => 'wh-1', 'url' => 'https://shop.example.test/api/sales-channel-webhooks/1', 'status' => 'ACTIVE', 'authenticationType' => 'API_KEY',
            'subscribedStatuses' => ['CREATED', 'SHIPPED'], 'apiKey' => 'key-abc-123', 'password' => 'secret-xyz-789',
        ]])]);

        $this->post(route('admin.sales-channels.accounts.webhook.list', $account))->assertRedirect(route('admin.sales-channels.accounts.show', $account));

        $html = $this->show($account);
        foreach (['wh-1', 'ACTIVE', 'CREATED, SHIPPED'] as $shown) {
            $this->assertStringContainsString($shown, $html);
        }
        $this->assertNoSecretIn($html, 'the list of webhooks, even though the channel sent them');
        $this->assertCount(1, $this->sent(), 'one read, when the button was pressed');
        $this->assertStringNotContainsString('wh-1', $this->show($account), 'the list is not kept: opening the page again asks for nothing');
    }

    public function test_a_channel_with_no_webhooks_registered_says_so(): void
    {
        $account = $this->trendyolAccount();
        $this->fakeOnly([self::TY . '/webhook/*' => Http::response([])]);

        $this->post(route('admin.sales-channels.accounts.webhook.list', $account))->assertRedirect(route('admin.sales-channels.accounts.show', $account));

        $this->assertStringContainsString(e(translate('no_webhooks_registered')), $this->show($account));
    }

    public function test_a_list_the_channel_refuses_to_give_is_reported(): void
    {
        $account = $this->trendyolAccount();
        $this->fakeOnly([self::TY . '/webhook/*' => Http::response(['message' => 'nope'], 500)]);

        $this->post(route('admin.sales-channels.accounts.webhook.list', $account))->assertRedirect();

        $this->assertToastContains(translate('webhook_list_failed'));
    }

    public function test_the_webhook_belongs_to_trendyol_only(): void
    {
        $amazon = $this->amazonAccount();

        $this->post(route('admin.sales-channels.accounts.webhook', $amazon))->assertNotFound();
        $this->post(route('admin.sales-channels.accounts.webhook.list', $amazon))->assertNotFound();
    }

    // ---- rate limits and methods --------------------------------------------------------------------------

    public function test_every_call_to_a_channel_is_a_post_with_a_rate_limit(): void
    {
        $limits = [
            'accounts.test' => 'throttle:10,1', 'accounts.sync' => 'throttle:6,1', 'accounts.publish' => 'throttle:6,1', 'accounts.webhook' => 'throttle:6,1',
            'accounts.webhook.list' => 'throttle:6,1', 'orders.notify' => 'throttle:6,1',
        ];

        foreach ($limits as $name => $throttle) {
            $route = Route::getRoutes()->getByName('admin.sales-channels.' . $name);
            $this->assertSame(['POST'], $route->methods(), $name . ' is not reachable by GET');
            $this->assertContains($throttle, $route->gatherMiddleware(), $name . ' is not rate limited');
        }
    }

    public function test_a_click_happy_session_is_stopped_by_the_rate_limit(): void
    {
        $account = $this->trendyolAccount(['connection_test']);
        Http::fake();

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->post(route('admin.sales-channels.accounts.sync', ['account' => $account, 'resource' => 'orders']))->assertRedirect();
        }

        $this->post(route('admin.sales-channels.accounts.sync', ['account' => $account, 'resource' => 'orders']))->assertStatus(429);
    }

    public function test_none_of_these_actions_can_be_triggered_by_a_link(): void
    {
        $account = $this->trendyolAccount();

        foreach (['accounts.test', 'accounts.webhook', 'accounts.webhook.list', 'accounts.writes', 'accounts.dry-run'] as $name) {
            $this->get(route('admin.sales-channels.' . $name, $account))->assertStatus(405);
        }
        $this->get(route('admin.sales-channels.accounts.sync', ['account' => $account, 'resource' => 'orders']))->assertStatus(405);
        $this->get(route('admin.sales-channels.accounts.publish', ['account' => $account, 'kind' => 'stock']))->assertStatus(405);
    }
}
