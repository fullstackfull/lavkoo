<?php

namespace Tests\Feature\SalesChannels;

use App\Models\SalesChannelAccount;
use App\Models\SalesChannelApiLog;
use App\Models\SalesChannelEvent;
use Illuminate\Support\Facades\Http;
use Tests\Support\Fixtures\TrendyolFixtures as Trendyol;

/** What the module said to the channels and what they said back: stored without secrets, printed as text. */
class AdminSalesChannelsLogsTest extends AdminSalesChannelsTestCase
{
    private function api(array $query = []): string
    {
        return $this->get(route('admin.sales-channels.logs.index', ['tab' => 'api'] + $query))->assertOk()->getContent();
    }

    private function events(array $query = []): string
    {
        return $this->get(route('admin.sales-channels.logs.index', ['tab' => 'events'] + $query))->assertOk()->getContent();
    }

    private function apiCall(SalesChannelAccount $account, array $attributes = []): SalesChannelApiLog
    {
        return SalesChannelApiLog::create($attributes + [
            'account_id' => $account->id, 'channel' => $account->channel, 'operation' => 'trendyol.orders_stream', 'correlation_id' => 'corr-' . $this->nextRecordNumber(), 'method' => 'GET',
            'url' => 'https://apigw.trendyol.com/integration/order/sellers/1234567/orders/stream', 'response_status' => 200, 'success' => true, 'dry_run' => false, 'duration_ms' => 182, 'created_at' => now(),
        ]);
    }

    private function event(SalesChannelAccount $account, array $attributes = []): SalesChannelEvent
    {
        return SalesChannelEvent::create($attributes + [
            'account_id' => $account->id, 'channel' => $account->channel, 'source' => 'webhook', 'event_type' => 'order_package.created', 'dedupe_key' => 'dedupe-' . $this->nextRecordNumber(),
            'external_reference' => '10234567890/3012345678', 'outcome' => 'applied', 'received_at' => now(), 'occurred_at' => now()->subMinute(),
        ]);
    }

    public function test_the_api_calls_are_listed_with_their_method_address_status_duration_and_error(): void
    {
        $account = $this->trendyolAccount();
        $this->apiCall($account);
        $this->apiCall($account, ['operation' => 'trendyol.price_inventory_update', 'method' => 'POST', 'url' => 'https://apigw.trendyol.com/integration/inventory/sellers/1234567/products/price-and-inventory',
            'response_status' => 500, 'success' => false, 'error_class' => 'server_error', 'error_message' => 'Trendyol answered 500', 'duration_ms' => 3010, 'request_summary' => ['body' => ['items' => [['barcode' => 'BC-1', 'quantity' => 4]]]],
            'response_summary' => '{"message":"boom"}']);

        $html = $this->api();

        foreach (['Trendyol KSA', 'trendyol.orders_stream', 'trendyol.price_inventory_update', '<code>POST</code>', 'price-and-inventory', '182 ms', '3010 ms', 'Trendyol answered 500', 'BC-1', 'boom', 'corr-'] as $shown) {
            $this->assertStringContainsString($shown, $html);
        }
        $this->assertStringContainsString('<details>', $html, 'the stored request and response are one click away, not in the way');
        $this->assertStringContainsString('aria-selected="true"', $html);
    }

    public function test_a_rehearsal_is_marked_as_one_and_not_as_a_status(): void
    {
        $this->apiCall($this->trendyolAccount(), ['dry_run' => true, 'success' => true, 'response_status' => null, 'method' => 'POST']);

        $html = $this->api();

        $this->assertStringContainsString(e(translate('rehearsal')), $html);
        $this->assertStringNotContainsString(e(translate('no_response')), $html);
    }

    public function test_the_api_calls_can_be_filtered(): void
    {
        $trendyol = $this->trendyolAccount();
        $amazon = $this->amazonAccount();
        $this->apiCall($trendyol, ['operation' => 'trendyol.orders_stream']);
        $this->apiCall($trendyol, ['operation' => 'trendyol.products_approved', 'success' => false, 'response_status' => 503, 'error_message' => 'unavailable']);
        $this->apiCall($trendyol, ['operation' => 'trendyol.price_inventory_update', 'dry_run' => true]);
        $this->apiCall($amazon, ['operation' => 'amazon.orders_search', 'channel' => 'amazon']);

        $cases = [
            [[], ['trendyol.orders_stream', 'trendyol.products_approved', 'trendyol.price_inventory_update', 'amazon.orders_search']],
            [['account' => $amazon->id], ['amazon.orders_search']],
            [['operation' => 'trendyol.'], ['trendyol.orders_stream', 'trendyol.products_approved', 'trendyol.price_inventory_update']],
            [['operation' => 'trendyol.products'], ['trendyol.products_approved']],
            [['result' => 'failed'], ['trendyol.products_approved']],
            [['result' => 'ok'], ['trendyol.orders_stream', 'trendyol.price_inventory_update', 'amazon.orders_search']],
            [['dry_run' => 'yes'], ['trendyol.price_inventory_update']],
            [['dry_run' => 'no'], ['trendyol.orders_stream', 'trendyol.products_approved', 'amazon.orders_search']],
            [['account' => $trendyol->id, 'result' => 'failed'], ['trendyol.products_approved']],
        ];
        foreach ($cases as [$query, $expected]) {
            $html = $this->api($query);
            foreach (['trendyol.orders_stream', 'trendyol.products_approved', 'trendyol.price_inventory_update', 'amazon.orders_search'] as $operation) {
                in_array($operation, $expected, true) ? $this->assertStringContainsString($operation, $html, json_encode($query)) : $this->assertStringNotContainsString($operation, $html, json_encode($query));
            }
        }
        $this->assertStringContainsString(e(translate('no_api_call_matches_the_filters')), $this->api(['operation' => 'nothing.like.this']));
    }

    public function test_whatever_a_log_holds_is_printed_as_text_and_never_as_markup(): void
    {
        $account = $this->trendyolAccount();
        $this->apiCall($account, [
            'url' => 'https://example.test/"><script>alert("url")</script>', 'success' => false, 'response_status' => 400, 'error_message' => '<img src=x onerror=alert("error")>',
            'request_summary' => ['body' => ['note' => '<b onmouseover=alert("request")>x</b>']], 'response_summary' => '<script>alert("response")</script>',
        ]);
        $this->event($account, ['event_type' => 'order_package.<svg onload=alert(1)>', 'outcome_note' => '<script>alert("note")</script>', 'payload' => ['status' => '<script>alert("payload")</script>']]);

        foreach ([$this->api(), $this->events()] as $html) {
            $this->assertDoesNotMatchRegularExpression('/<script>alert\(/', $html);
            $this->assertStringNotContainsString('<img src=x', $html);
            $this->assertStringNotContainsString('<svg onload', $html);
            $this->assertStringNotContainsString('<b onmouseover', $html);
        }
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;response&quot;)&lt;/script&gt;', $this->api());
    }

    public function test_a_long_list_is_paged_by_fifty_and_keeps_its_filters(): void
    {
        $account = $this->trendyolAccount();
        for ($index = 1; $index <= 60; $index++) {
            $this->apiCall($account, ['operation' => sprintf('trendyol.paged_%02d', $index)]);
        }

        $first = $this->api(['operation' => 'trendyol.paged']);
        $this->assertSame(50, preg_match_all('/trendyol\.paged_\d\d/', $first));
        $this->assertStringContainsString('trendyol.paged_60', $first, 'newest first');
        $this->assertStringContainsString('operation=trendyol.paged&amp;', $first);
        $this->assertSame(10, preg_match_all('/trendyol\.paged_\d\d/', $this->api(['operation' => 'trendyol.paged', 'page' => 2])));
    }

    public function test_the_events_are_listed_with_their_source_type_reference_and_outcome(): void
    {
        $account = $this->trendyolAccount();
        $this->event($account);
        $this->event($account, ['source' => 'poll', 'event_type' => 'finance.unclassified', 'external_reference' => 'ORD-7|', 'outcome' => 'needs_review', 'payload' => ['category' => 'unclassified', 'amount' => '12.500000', 'raw_type' => 'Mystery fee']]);
        $this->event($account, ['outcome' => 'failed', 'outcome_note' => 'The package was not valid']);

        $html = $this->events();

        foreach (['Trendyol KSA', 'order_package.created', 'finance.unclassified', '10234567890/3012345678', 'ORD-7|', 'Mystery fee', 'The package was not valid'] as $shown) {
            $this->assertStringContainsString($shown, $html);
        }
        foreach (['event_source_webhook', 'event_source_poll', 'event_outcome_applied', 'event_outcome_needs_review', 'event_outcome_failed'] as $key) {
            $this->assertStringContainsString(e(translate($key)), $html, $key);
        }
    }

    public function test_the_events_can_be_filtered_and_the_ones_that_need_review_are_one_choice_away(): void
    {
        $account = $this->trendyolAccount();
        $other = $this->amazonAccount();
        $this->event($account, ['event_type' => 'order_package.created']);
        $this->event($account, ['source' => 'poll', 'event_type' => 'finance.unclassified', 'outcome' => 'needs_review']);
        $this->event($other, ['channel' => 'amazon', 'event_type' => 'amazon.notification', 'source' => 'notification', 'outcome' => 'received']);

        $cases = [
            [[], ['order_package.created', 'finance.unclassified', 'amazon.notification']],
            [['outcome' => 'needs_review'], ['finance.unclassified']],
            [['source' => 'webhook'], ['order_package.created']],
            [['source' => 'notification'], ['amazon.notification']],
            [['event_type' => 'finance.'], ['finance.unclassified']],
            [['account' => $other->id], ['amazon.notification']],
        ];
        foreach ($cases as [$query, $expected]) {
            $html = $this->events($query);
            foreach (['order_package.created', 'finance.unclassified', 'amazon.notification'] as $type) {
                in_array($type, $expected, true) ? $this->assertStringContainsString($type, $html, json_encode($query)) : $this->assertStringNotContainsString($type, $html, json_encode($query));
            }
        }
    }

    public function test_the_tabs_and_their_empty_states_say_what_is_missing(): void
    {
        $this->trendyolAccount();

        $this->assertStringContainsString(e(translate('no_api_calls_yet')), $this->api());
        $this->assertStringContainsString(e(translate('no_events_yet')), $this->events());
        $this->assertStringContainsString(route('admin.sales-channels.logs.index', ['tab' => 'events']), $this->api());
        $this->assertStringContainsString(route('admin.sales-channels.logs.index', ['tab' => 'api']), $this->events());
        $this->assertStringContainsString(e(translate('channel_logs_are_redacted_note')), $this->api());
    }

    public function test_a_filter_that_matches_no_event_says_so_and_offers_the_reset(): void
    {
        $this->event($this->trendyolAccount());

        $html = $this->events(['outcome' => 'failed']);

        $this->assertStringContainsString(e(translate('no_event_matches_the_filters')), $html);
        $this->assertStringContainsString(e(translate('no_event_matches_the_filters_help')), $html);
        $this->assertStringContainsString('href="' . route('admin.sales-channels.logs.index', ['tab' => 'events']) . '"', $html);
        $this->assertStringNotContainsString(e(translate('no_events_yet')), $html, 'there are events: the filter hides them');
    }

    public function test_values_that_are_not_what_the_form_sends_are_no_filter_and_no_error(): void
    {
        $this->apiCall($this->trendyolAccount());

        $html = $this->get(route('admin.sales-channels.logs.index', ['tab' => ['x'], 'account' => 'abc', 'operation' => ['y'], 'result' => 'maybe', 'dry_run' => ['yes'], 'source' => 'carrier pigeon', 'outcome' => ['z']]))->assertOk()->getContent();

        $this->assertStringContainsString('trendyol.orders_stream', $html);
    }

    public function test_a_call_made_through_the_real_transport_leaves_a_log_with_no_secret_in_it(): void
    {
        $account = $this->trendyolAccount(['connection_test']);
        Http::fake([self::TY . '/*' => Http::response(Trendyol::stream([]))]);

        $this->post(route('admin.sales-channels.accounts.test', $account));

        $this->assertGreaterThan(0, SalesChannelApiLog::count());
        $html = $this->api();
        $this->assertStringContainsString('trendyol.probe_orders', $html);
        $this->assertNoSecretIn($html, 'the API log');
        $this->assertStringNotContainsString(base64_encode('key-abc-123:secret-xyz-789'), $html, 'nor the Basic credentials the key and the secret make');
        $this->assertNoSecretIn($this->events(), 'the event log');
    }
}
