<?php

namespace Tests\Feature\SalesChannels;

use App\Http\Controllers\Admin\SalesChannels\ChannelLabels;
use App\Models\ExternalOrderMapping;
use App\Models\FulfillmentRoutingDecision;
use App\Models\FulfillmentRoutingRule;
use App\Models\SalesChannelAccount;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Fixtures\TrendyolFixtures as Trendyol;

/** Orders as the channels know them, and what the application is doing about each. */
class AdminSalesChannelsOrdersTest extends AdminSalesChannelsTestCase
{
    private function index(array $query = []): string
    {
        return $this->get(route('admin.sales-channels.orders.index', $query))->assertOk()->getContent();
    }

    private function page(ExternalOrderMapping $order): string
    {
        return $this->get(route('admin.sales-channels.orders.show', $order))->assertOk()->getContent();
    }

    private function labels(): ChannelLabels
    {
        return app(ChannelLabels::class);
    }

    private function fakeOnly(array $stubs): void
    {
        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    /** An order whose line is linked to a product, the way a routable order looks. */
    private function routable(SalesChannelAccount $account, array $attributes = []): ExternalOrderMapping
    {
        return $this->channelOrder($account, $attributes, [['product_id' => $this->product()]]);
    }

    // ---- list ---------------------------------------------------------------------------------------------

    /** @return array<string, ExternalOrderMapping> */
    private function threeOrders(): array
    {
        $trendyol = $this->trendyolAccount();
        $amazon = $this->amazonAccount();

        return [
            'alpha' => $this->channelOrder($trendyol, ['external_order_id' => 'ORD-ALPHA', 'external_package_id' => '111222', 'status' => 'new', 'fulfillment_mode' => 'fbm', 'execution_policy' => 'manual_review',
                'review_reason' => 'unmapped_lines', 'purchased_at' => '2026-11-01 10:00:00']),
            'beta' => $this->channelOrder($amazon, ['external_order_id' => 'ORD-BETA', 'status' => 'shipped', 'fulfillment_mode' => 'fba', 'execution_policy' => 'externally_managed',
                'shipment_link_state' => 'not_applicable', 'purchased_at' => '2026-11-05 10:00:00']),
            'gamma' => $this->channelOrder($trendyol, ['external_order_id' => 'ORD-GAMMA', 'status' => 'delivered', 'fulfillment_mode' => null, 'order_total' => null, 'tracking_number' => 'TRK-GAMMA',
                'shipment_link_state' => 'linked', 'provider' => 'torod_point', 'shipment_reference' => 'TOR-GAMMA', 'purchased_at' => '2026-11-10 10:00:00',
                'snapshot' => ['is_cod' => true, 'destination' => ['city' => 'Jeddah', 'country' => 'SA']]]),
        ];
    }

    public function test_the_list_marks_who_fulfils_each_order_cash_on_delivery_and_what_it_is_worth(): void
    {
        $this->threeOrders();

        $html = $this->index();

        $this->assertStringContainsString(e(translate('fulfillment_mode_fba')), $html);
        $this->assertStringContainsString(e(translate('fulfillment_mode_fbm')), $html);
        $this->assertStringContainsString(e(translate('fulfillment_mode_unknown')), $html, 'a mode the channel did not state is shown as unknown, not as merchant-fulfilled');
        $this->assertSame(1, substr_count($html, e(translate('cash_on_delivery')) . '</span>'), 'only the order paid on delivery is marked');
        $this->assertStringContainsString('210.00', $html);
        $this->assertStringContainsString('SAR', $html);
        $this->assertStringContainsString(e(translate('unknown_value')), $html, 'an order with no total is not worth zero');
        $this->assertStringContainsString(e($this->labels()->orderReason('unmapped_lines')), $html);
        $this->assertStringContainsString('TRK-GAMMA', $html);
        $this->assertStringContainsString(e(translate('shipment_link_state_linked')), $html);
        $this->assertStringContainsString(e(translate('shipment_link_state_not_applicable')), $html);
        $this->assertStringContainsString(e(translate('not_decided_yet')), $html);
    }

    public function test_the_filters_narrow_the_list(): void
    {
        $this->threeOrders();
        $amazon = SalesChannelAccount::where('channel', 'amazon')->firstOrFail();
        $trendyol = SalesChannelAccount::where('channel', 'trendyol')->firstOrFail();

        $cases = [
            [[], ['ALPHA', 'BETA', 'GAMMA']],
            [['channel' => 'amazon'], ['BETA']],
            [['account' => $trendyol->id], ['ALPHA', 'GAMMA']],
            [['account' => $amazon->id], ['BETA']],
            [['status' => 'delivered'], ['GAMMA']],
            [['mode' => 'fba'], ['BETA']],
            [['mode' => 'fbm'], ['ALPHA']],
            [['mode' => 'unknown'], ['GAMMA']],
            [['policy' => 'manual_review'], ['ALPHA']],
            [['policy' => 'externally_managed'], ['BETA']],
            [['review' => 1], ['ALPHA']],
            [['link' => 'linked'], ['GAMMA']],
            [['link' => 'not_applicable'], ['BETA']],
            [['link' => 'undecided'], ['ALPHA']],
            [['from' => '2026-11-05', 'to' => '2026-11-09'], ['BETA']],
            [['from' => '2026-11-06'], ['GAMMA']],
            [['to' => '2026-11-01'], ['ALPHA']],
            [['q' => 'ORD-BETA'], ['BETA']],
            [['q' => '111222'], ['ALPHA']],
            [['q' => 'TRK-GAMMA'], ['GAMMA']],
            [['q' => 'TOR-GAMMA'], ['GAMMA']],
            [['q' => 'nothing like this'], []],
        ];
        foreach ($cases as [$query, $expected]) {
            $html = $this->index($query);
            foreach (['ALPHA', 'BETA', 'GAMMA'] as $name) {
                in_array($name, $expected, true) ? $this->assertStringContainsString('ORD-' . $name, $html, json_encode($query)) : $this->assertStringNotContainsString('ORD-' . $name, $html, json_encode($query));
            }
        }
        $this->assertStringContainsString(e(translate('no_order_matches_the_filters')), $this->index(['q' => 'nothing like this']));
    }

    public function test_a_filter_that_is_not_what_the_form_sends_is_no_filter(): void
    {
        $this->threeOrders();

        $html = $this->get(route('admin.sales-channels.orders.index', ['channel' => 'storefront', 'status' => ['x'], 'mode' => 'teleported', 'from' => '2026-13-45', 'to' => 'tomorrow', 'q' => ['a'], 'link' => 'maybe', 'account' => 'abc']))->assertOk()->getContent();

        $this->assertSame(3, preg_match_all('/ORD-(ALPHA|BETA|GAMMA)/', $html));
    }

    public function test_a_long_list_is_paged_newest_first_and_keeps_its_filters(): void
    {
        $account = $this->trendyolAccount();
        for ($index = 1; $index <= 30; $index++) {
            $this->channelOrder($account, ['external_order_id' => sprintf('PAGED-%02d', $index), 'purchased_at' => now()->subHours(40 - $index)]);
        }

        $first = $this->index(['channel' => 'trendyol']);
        $this->assertSame(25, preg_match_all('/PAGED-\d\d/', $first));
        $this->assertStringContainsString('PAGED-30', $first, 'the newest order is on the first page');
        $this->assertStringNotContainsString('PAGED-01', $first);
        $this->assertStringContainsString('channel=trendyol&amp;page=2', $first);
        $this->assertSame(5, preg_match_all('/PAGED-\d\d/', $this->index(['channel' => 'trendyol', 'page' => 2])));
    }

    // ---- one order ----------------------------------------------------------------------------------------

    public function test_the_order_page_shows_the_facts_the_lines_and_the_money_and_no_personal_data(): void
    {
        $account = $this->trendyolAccount();
        $product = $this->product('Paracetamol 500mg', 'PARA-500');
        $order = $this->channelOrder($account, [
            'external_order_id' => 'ORD-FACTS', 'external_package_id' => '777', 'status' => 'processing', 'external_status' => 'Picking', 'fulfillment_mode' => 'fbm', 'execution_policy' => 'manual_review',
            'review_reason' => 'no_rule_matched', 'order_total' => '210.000000', 'discount_total' => '5.000000', 'tax_total' => '27.390000', 'prices_include_tax' => true,
            'snapshot' => ['is_cod' => true, 'destination' => ['city' => 'Riyadh', 'country' => 'SA'], 'buyer_name' => 'Fatima Buyer', 'address' => '1 Secret Street', 'phone' => '+966500000000'],
        ], [
            ['external_line_id' => 'L1', 'sku' => 'SKU-A', 'barcode' => 'BC-A', 'title' => 'Paracetamol tablets', 'product_id' => $product, 'quantity' => 3, 'quantity_cancelled' => 1, 'quantity_returned' => 1, 'unit_price' => '70.000000', 'line_total' => '210.000000', 'provider' => 'torod_point'],
            ['external_line_id' => 'L2', 'sku' => 'SKU-B', 'barcode' => 'BC-B', 'title' => 'Unlinked item', 'product_id' => null, 'quantity' => 1],
        ]);

        $html = $this->page($order);

        foreach (['ORD-FACTS', '777', 'Picking', 'Riyadh, SA', 'Paracetamol tablets', 'SKU-A', 'BC-A', 'Paracetamol 500mg', 'PARA-500', 'Torod Point', '210.00', '5.00', '27.39', 'SAR'] as $shown) {
            $this->assertStringContainsString($shown, $html);
        }
        $this->assertStringContainsString(e(translate('cancelled')) . ': 1', $html);
        $this->assertStringContainsString(e(translate('returned')) . ': 1', $html);
        $this->assertStringContainsString(e($this->labels()->orderReason('no_rule_matched')), $html);
        $this->assertStringContainsString(e(translate('execution_policy_manual_review')), $html);
        $this->assertStringContainsString(e(translate('not_linked')), $html, 'the line with no product says so');
        $this->assertStringContainsString(route('admin.sales-channels.products.index', ['account' => $account->id, 'q' => 'SKU-B']), str_replace('&amp;', '&', $html), 'and points at where to link it');
        foreach (['Fatima Buyer', '1 Secret Street', '+966500000000'] as $personal) {
            $this->assertStringNotContainsString($personal, $html, 'the module stores no buyer data, and shows only the city and the country');
        }
    }

    public function test_an_amount_the_channel_did_not_give_is_not_shown_as_zero(): void
    {
        $order = $this->channelOrder($this->trendyolAccount(), ['order_total' => null, 'tax_total' => null, 'discount_total' => null, 'prices_include_tax' => null, 'snapshot' => []]);

        $html = $this->page($order);

        $this->assertGreaterThanOrEqual(4, substr_count($html, e(translate('unknown_value'))));
        $this->assertDoesNotMatchRegularExpression('/(?<![\d.])0\.00/', $html, 'an amount that is not there is not 0.00');
    }

    public function test_the_decisions_are_listed_newest_first_with_the_reason_in_words_and_the_rule_as_it_was(): void
    {
        $order = $this->routable($this->trendyolAccount());
        $this->routingDecision($order, ['version' => 1, 'is_current' => false, 'decided_by' => 'system']);
        $this->routingDecision($order, [
            'version' => 2, 'is_current' => true, 'action' => 'monitor_only', 'execution_mode' => 'externally_managed', 'provider' => 'torod_point', 'rule_id' => 5, 'reason_code' => 'rule_monitor_only',
            'rule_snapshot' => ['id' => 5, 'name' => 'Trendyol via Torod', 'priority' => 10, 'action' => 'monitor_only', 'target_provider' => 'torod_point'], 'decided_by' => 'admin',
            'line_decisions' => [['line' => 'L1', 'product_id' => 5, 'quantity' => 2, 'action' => 'monitor_only', 'provider' => 'torod_point']],
        ]);

        $html = $this->page($order);

        $this->assertLessThan(strpos($html, 'id="decision-1"'), strpos($html, 'id="decision-2"'), 'the newest decision comes first');
        $this->assertStringContainsString(e($this->labels()->orderReason('rule_monitor_only')), $html);
        $this->assertStringContainsString(e($this->labels()->orderReason('no_rule_matched')), $html);
        $this->assertStringContainsString('Trendyol via Torod', $html);
        $this->assertStringContainsString(e(translate('current_decision')), $html);
        $this->assertStringContainsString(e(translate('decided_by_an_administrator')), $html);
        $this->assertStringContainsString(e(translate('decision_per_line')), $html);
        $this->assertStringContainsString(e(translate('what_the_decision_was_based_on')), $html);
    }

    public function test_a_rule_that_wanted_a_provider_shipment_is_shown_as_wanted_and_not_done(): void
    {
        $order = $this->routable($this->trendyolAccount());
        $this->routingDecision($order, [
            'reason_code' => 'managed_dispatch_not_available', 'reason' => "Rule 'Torod for Trendyol' wants this order created at torod_point, but creating provider shipments for channel orders is not available in this version.",
            'inputs' => ['channel' => 'trendyol', 'wanted_action' => 'route_to_provider', 'wanted_provider' => 'torod_point', 'lines' => []],
            'rule_snapshot' => ['id' => 1, 'name' => 'Torod for Trendyol', 'priority' => 10], 'rule_id' => 1,
        ]);

        $html = $this->page($order);

        $this->assertStringContainsString(e(translate('what_the_rule_wanted')), $html);
        $this->assertStringContainsString(e($this->labels()->routingAction('route_to_provider')), $html);
        $this->assertStringContainsString('Torod Point', $html);
        $this->assertStringContainsString(e(translate('managed_dispatch_not_available_explained')), $html);
        $this->assertStringContainsString(e($this->labels()->orderReason('managed_dispatch_not_available')), $html);
    }

    public function test_a_tracking_link_is_a_link_only_when_it_is_a_web_address(): void
    {
        $account = $this->trendyolAccount();
        $safe = $this->channelOrder($account, ['tracking_number' => 'TRK-SAFE', 'tracking_url' => 'https://tracking.example.test/TRK-SAFE']);
        $unsafe = $this->channelOrder($account, ['tracking_number' => 'TRK-UNSAFE', 'tracking_url' => 'javascript:alert(document.cookie)']);

        $this->assertStringContainsString('href="https://tracking.example.test/TRK-SAFE"', $this->page($safe));
        $html = $this->page($unsafe);
        $this->assertStringContainsString('TRK-UNSAFE', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    // ---- deciding again -----------------------------------------------------------------------------------

    public function test_deciding_again_records_a_decision_and_a_repeat_changes_nothing(): void
    {
        $account = $this->trendyolAccount();
        $order = $this->routable($account);
        FulfillmentRoutingRule::create(['name' => 'Trendyol via Torod', 'priority' => 10, 'is_active' => true, 'seller_id' => 0, 'sales_channel' => 'trendyol', 'action' => 'monitor_only', 'target_provider' => 'torod_point']);
        Http::fake();

        $this->from(route('admin.sales-channels.orders.show', $order))->post(route('admin.sales-channels.orders.route', $order))->assertRedirect(route('admin.sales-channels.orders.show', $order));

        $decision = FulfillmentRoutingDecision::where('external_order_mapping_id', $order->id)->firstOrFail();
        $this->assertSame(['monitor_only', 'rule_monitor_only', 'admin', 1], [$decision->action, $decision->reason_code, $decision->decided_by, $decision->decided_by_id]);
        $this->assertSame('externally_managed', $order->fresh()->execution_policy);
        $this->assertToastContains(translate('routing_decision_recorded'));

        $this->forgetToasts();
        $this->from(route('admin.sales-channels.orders.show', $order))->post(route('admin.sales-channels.orders.route', $order));
        $this->assertSame(1, FulfillmentRoutingDecision::count(), 'the same decision is not recorded twice');
        $this->assertToastContains(translate('routing_decision_unchanged'));
        Http::assertNothingSent();
    }

    public function test_deciding_again_never_gives_a_channel_shipped_order_to_a_provider_whatever_a_rule_says(): void
    {
        $account = $this->amazonAccount();
        $order = $this->routable($account, ['fulfillment_mode' => 'fba']);
        FulfillmentRoutingRule::create(['name' => 'Everything to Torod', 'priority' => 1, 'is_active' => true, 'seller_id' => 0, 'action' => 'route_to_provider', 'target_provider' => 'torod_point']);

        $this->from(route('admin.sales-channels.orders.show', $order))->post(route('admin.sales-channels.orders.route', $order));

        $this->assertSame('channel_fulfills', FulfillmentRoutingDecision::firstOrFail()->action);
        $this->assertToastContains($this->labels()->orderReason('channel_fulfills_order'));
    }

    public function test_a_rule_asking_for_a_provider_shipment_is_recorded_as_wanted_and_the_order_waits(): void
    {
        $order = $this->routable($this->trendyolAccount());
        FulfillmentRoutingRule::create(['name' => 'Torod for Trendyol', 'priority' => 1, 'is_active' => true, 'seller_id' => 0, 'action' => 'route_to_provider', 'target_provider' => 'torod_point']);

        $this->from(route('admin.sales-channels.orders.show', $order))->post(route('admin.sales-channels.orders.route', $order));

        $decision = FulfillmentRoutingDecision::firstOrFail();
        $this->assertSame(['manual_review', 'managed_dispatch_not_available', 'route_to_provider', 'torod_point'], [$decision->action, $decision->reason_code, $decision->inputs['wanted_action'], $decision->inputs['wanted_provider']]);
        $this->assertSame('managed_dispatch_not_available', $order->fresh()->review_reason);
        $this->assertStringContainsString(e(translate('managed_dispatch_not_available_explained')), $this->page($order));
    }

    public function test_deciding_again_on_a_dispatched_order_that_was_never_decided_records_nothing_and_says_why(): void
    {
        $order = $this->routable($this->trendyolAccount(), ['shipment_link_state' => 'linked', 'provider' => 'torod_point', 'shipment_reference' => 'TOR-1', 'tracking_number' => 'TRK-1']);
        FulfillmentRoutingRule::create(['name' => 'Everything followed', 'priority' => 1, 'is_active' => true, 'seller_id' => 0, 'action' => 'monitor_only']);

        $this->from(route('admin.sales-channels.orders.show', $order))->post(route('admin.sales-channels.orders.route', $order))->assertRedirect(route('admin.sales-channels.orders.show', $order));

        $this->assertSame(0, FulfillmentRoutingDecision::count());
        $this->assertToastContains(translate('order_already_dispatched_decision_stands'));
    }

    public function test_a_decision_that_was_not_applied_is_marked_as_such(): void
    {
        $order = $this->routable($this->trendyolAccount());
        $this->routingDecision($order, ['applied' => false]);

        $html = $this->page($order);

        $this->assertStringContainsString(e(translate('not_applied')), $html);
        $this->assertStringNotContainsString(e(translate('applied_to_the_order')), $html);
    }

    public function test_an_order_with_no_lines_recorded_says_so(): void
    {
        $order = $this->channelOrder($this->trendyolAccount(), [], []);

        $this->assertStringContainsString(e(translate('no_lines_recorded')), $this->page($order));
    }

    // ---- linking a shipment -------------------------------------------------------------------------------

    private function link(ExternalOrderMapping $order, array $input): TestResponse
    {
        return $this->from(route('admin.sales-channels.orders.show', $order))->post(route('admin.sales-channels.orders.link-shipment', $order), $input);
    }

    public function test_a_shipment_that_someone_else_arranged_is_linked_by_provider_and_reference(): void
    {
        $order = $this->routable($this->trendyolAccount());

        $this->link($order, ['provider' => 'torod_point', 'reference' => 'TOR-1001', 'tracking_number' => 'TRK-1001', 'carrier' => 'SMSA'])->assertRedirect(route('admin.sales-channels.orders.show', $order))->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(['linked', 'torod_point', 'TOR-1001', 'TRK-1001', 'SMSA', 'externally_managed'], [$order->shipment_link_state, $order->provider, $order->shipment_reference, $order->tracking_number, $order->carrier, $order->execution_policy]);
        $this->assertToastContains(translate('shipment_linked'));
        $html = $this->page($order);
        $this->assertStringContainsString('TOR-1001', $html);
        $this->assertStringContainsString(e(translate('unlink_shipment')), $html);
        $this->assertStringNotContainsString('name="reference"', $html, 'a linked order offers to unlink, not to link another');
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function refusedLinks(): array
    {
        return [
            'an order the channel ships itself' => [['fulfillment_mode' => 'fba'], 'The channel ships this order itself; there is no provider shipment to link.'],
            'an order whose fulfilment nobody stated' => [['fulfillment_mode' => null], 'Who fulfils this order is not known yet.'],
            'an order the application already fulfils' => [['order_id' => 4242], 'The application already created a fulfilment for this order: it never gets a second shipment.'],
        ];
    }

    #[DataProvider('refusedLinks')]
    public function test_a_link_the_service_refuses_is_shown_as_the_service_worded_it(array $attributes, string $message): void
    {
        $order = $this->routable($this->trendyolAccount(), $attributes);

        $this->link($order, ['provider' => 'torod_point', 'reference' => 'TOR-2002'])->assertRedirect();

        $this->assertToastContains($message);
        $this->assertSame('unlinked', $order->fresh()->shipment_link_state ?? 'unlinked');
        $this->assertNull($order->fresh()->shipment_reference);
    }

    public function test_one_shipment_belongs_to_one_order(): void
    {
        $account = $this->trendyolAccount();
        $first = $this->routable($account);
        $second = $this->routable($account);
        $this->link($first, ['provider' => 'torod_point', 'reference' => 'TOR-3003']);
        $this->forgetToasts();

        $this->link($second, ['provider' => 'torod_point', 'reference' => 'TOR-3003']);

        $this->assertToastContains('That shipment is already linked to another order.');
        $this->assertNull($second->fresh()->shipment_reference);
        $this->forgetToasts();
        $this->link($first, ['provider' => 'm5azn', 'reference' => 'M5-1']);
        $this->assertToastContains('This order is already linked to another shipment: unlink it first.');
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badLinks(): array
    {
        return [
            'no provider' => [['reference' => 'X-1'], 'provider'],
            'a provider we do not integrate' => [['provider' => 'dhl', 'reference' => 'X-1'], 'provider'],
            'no reference' => [['provider' => 'torod_point'], 'reference'],
            'a reference that is too long' => [['provider' => 'torod_point', 'reference' => str_repeat('x', 121)], 'reference'],
            'a tracking number that is too long' => [['provider' => 'torod_point', 'reference' => 'X-1', 'tracking_number' => str_repeat('1', 121)], 'tracking_number'],
        ];
    }

    #[DataProvider('badLinks')]
    public function test_bad_input_to_the_link_form_is_rejected(array $input, string $field): void
    {
        $order = $this->routable($this->trendyolAccount());

        $this->link($order, $input)->assertSessionHasErrorsIn('link_shipment', $field);

        $this->assertNull($order->fresh()->shipment_reference);
    }

    public function test_an_order_whose_link_awaits_a_person_offers_both_taking_the_link_back_and_linking_the_right_shipment(): void
    {
        $account = $this->trendyolAccount();
        $conflicting = $this->routable($account, ['shipment_link_state' => 'conflict', 'review_reason' => 'shipment_linked_elsewhere']);

        $html = $this->page($conflicting);

        $this->assertStringContainsString(route('admin.sales-channels.orders.unlink-shipment', $conflicting), $html);
        $this->assertStringContainsString(route('admin.sales-channels.orders.link-shipment', $conflicting), $html);

        $linked = $this->routable($account, ['shipment_link_state' => 'linked', 'provider' => 'torod_point', 'shipment_reference' => 'TOR-8', 'tracking_number' => 'TRK-8']);
        $html = $this->page($linked);
        $this->assertStringContainsString(route('admin.sales-channels.orders.unlink-shipment', $linked), $html);
        $this->assertStringNotContainsString(route('admin.sales-channels.orders.link-shipment', $linked), $html, 'a linked order must be unlinked before another shipment is linked');

        $this->link($conflicting, ['provider' => 'torod_point', 'reference' => 'TOR-RIGHT'])->assertSessionHasNoErrors();
        $this->assertSame(['linked', null], [$conflicting->fresh()->shipment_link_state, $conflicting->fresh()->review_reason], 'linking the right shipment resolves the conflict');
    }

    public function test_every_list_shows_whose_account_the_rows_belong_to(): void
    {
        $account = $this->trendyolAccount();
        $account->forceFill(['seller_id' => 42])->save();
        $this->channelOrder($account, ['seller_id' => 42]);
        $this->listing($account);

        $this->assertStringContainsString('#42', $this->index());
        $this->assertStringContainsString('#42', $this->get(route('admin.sales-channels.products.index'))->getContent());
    }

    public function test_the_page_says_why_a_shipment_cannot_be_linked_instead_of_offering_a_form(): void
    {
        $account = $this->trendyolAccount();

        $channelShips = $this->page($this->routable($account, ['fulfillment_mode' => 'fba']));
        $this->assertStringContainsString(e(translate('shipment_link_refused_channel_ships')), $channelShips);
        $this->assertStringNotContainsString('name="reference"', $channelShips);

        $this->assertStringContainsString(e(translate('shipment_link_refused_mode_unknown')), $this->page($this->routable($account, ['fulfillment_mode' => null])));
        $this->assertStringContainsString(e(translate('shipment_link_refused_application_fulfils')), $this->page($this->routable($account, ['order_id' => 77])));
    }

    // ---- unlinking ----------------------------------------------------------------------------------------

    private function linked(array $attributes = []): ExternalOrderMapping
    {
        return $this->routable($this->trendyolAccount(), $attributes + ['shipment_link_state' => 'linked', 'provider' => 'torod_point', 'shipment_reference' => 'TOR-9', 'tracking_number' => 'TRK-9', 'execution_policy' => 'externally_managed']);
    }

    public function test_taking_a_link_back_needs_a_reason_and_clears_the_shipment(): void
    {
        $order = $this->linked();
        $url = route('admin.sales-channels.orders.unlink-shipment', $order);

        $this->from(route('admin.sales-channels.orders.show', $order))->post($url)->assertSessionHasErrorsIn('unlink_shipment', 'reason');
        $this->post($url, ['reason' => 'abc'])->assertSessionHasErrorsIn('unlink_shipment', 'reason');
        $this->assertSame('linked', $order->fresh()->shipment_link_state);

        $this->post($url, ['reason' => 'Linked to the wrong parcel'])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(['unlinked', null], [$order->shipment_link_state, $order->shipment_reference]);
        $this->assertToastContains(translate('shipment_unlinked'));
    }

    public function test_only_a_link_or_a_link_awaiting_a_person_can_be_taken_back(): void
    {
        $account = $this->trendyolAccount();
        $channelShipped = $this->routable($account, ['fulfillment_mode' => 'fba', 'shipment_link_state' => 'not_applicable']);
        $conflict = $this->routable($account, ['shipment_link_state' => 'conflict', 'review_reason' => 'shipment_linked_elsewhere']);

        $this->from(route('admin.sales-channels.orders.show', $channelShipped))->post(route('admin.sales-channels.orders.unlink-shipment', $channelShipped), ['reason' => 'Trying to unlink it']);
        $this->assertToastContains(translate('no_shipment_link_to_take_back'));
        $this->assertSame('not_applicable', $channelShipped->fresh()->shipment_link_state, 'an order the channel ships is not turned into one awaiting a link');

        $this->post(route('admin.sales-channels.orders.unlink-shipment', $conflict), ['reason' => 'Clearing the conflict']);
        $this->assertSame('unlinked', $conflict->fresh()->shipment_link_state);
    }

    // ---- telling the channel ------------------------------------------------------------------------------

    public function test_a_shipment_that_is_not_linked_with_a_tracking_number_is_not_reported(): void
    {
        $order = $this->routable($this->trendyolAccount());
        Http::fake();

        $this->from(route('admin.sales-channels.orders.show', $order))->post(route('admin.sales-channels.orders.notify', $order))->assertRedirect();

        $this->assertToastContains(translate('shipment_not_reported_nothing_sent'));
        $this->assertToastContains($this->labels()->channelReason('no_linked_shipment_with_tracking'));
        Http::assertNothingSent();
        $this->assertStringNotContainsString(route('admin.sales-channels.orders.notify', $order), $this->page($order), 'the button is offered only when there is something to report');
    }

    public function test_from_a_rehearsing_account_a_report_is_logged_and_never_sent(): void
    {
        $account = $this->trendyolAccount(writes: false);
        $order = $this->routable($account, ['external_order_id' => '10234567890', 'external_package_id' => '3012345678', 'shipment_link_state' => 'linked', 'provider' => 'torod_point', 'shipment_reference' => 'TOR-9', 'tracking_number' => 'TRK-9']);
        $this->fakeOnly([self::TY . '/order/sellers/*' => Http::response(Trendyol::stream([Trendyol::package()]))]);

        $this->from(route('admin.sales-channels.orders.show', $order))->post(route('admin.sales-channels.orders.notify', $order))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertToastContains($this->labels()->channelReason('dry_run'));
        $this->assertSame([], array_filter(Http::recorded()->map(fn (array $pair): Request => $pair[0])->all(), fn (Request $request): bool => $request->method() !== 'GET'), 'nothing but a read of the package');
        $this->assertArrayNotHasKey('channel_notified', (array) $order->fresh()->snapshot);
    }

    public function test_a_real_report_needs_the_confirmation_and_is_made_once(): void
    {
        $this->liveWritesOn();
        $account = $this->trendyolAccount(writes: true);
        $order = $this->routable($account, ['external_order_id' => '10234567890', 'external_package_id' => '3012345678', 'shipment_link_state' => 'linked', 'provider' => 'torod_point', 'shipment_reference' => 'TOR-9', 'tracking_number' => 'TRK-9']);
        $this->fakeOnly([
            self::TY . '/order/sellers/' . Trendyol::SELLER . '/v2/orders*' => Http::response(Trendyol::stream([Trendyol::package()])),
            self::TY . '/order/sellers/*' => Http::response('', 200),
        ]);
        $url = route('admin.sales-channels.orders.notify', $order);

        $this->from(route('admin.sales-channels.orders.show', $order))->post($url)->assertSessionHasErrorsIn('notify', 'confirm_live');
        Http::assertNothingSent();

        $this->post($url, ['confirm_live' => 1])->assertSessionHasNoErrors();
        $this->assertToastContains(translate('shipment_reported_to_channel'));
        $this->assertSame('TRK-9', $order->fresh()->snapshot['channel_notified']['tracking']);
        $this->assertStringContainsString(e(translate('channel_told')), $this->page($order));

        $this->forgetToasts();
        $this->post($url, ['confirm_live' => 1]);
        $this->assertToastContains($this->labels()->channelReason('already_notified'));
    }

    public function test_a_report_that_cannot_be_made_says_what_stopped_it_as_text(): void
    {
        $this->liveWritesOn();
        $account = $this->trendyolAccount(writes: true);
        $order = $this->routable($account, ['external_order_id' => '10234567890', 'external_package_id' => '3012345678', 'shipment_link_state' => 'linked', 'provider' => 'torod_point', 'shipment_reference' => 'TOR-9', 'tracking_number' => 'TRK-9']);
        $this->fakeOnly([self::TY . '/order/sellers/*' => Http::response(Trendyol::stream([Trendyol::package(['whoPays' => 1])]))]);

        $this->from(route('admin.sales-channels.orders.show', $order))->post(route('admin.sales-channels.orders.notify', $order), ['confirm_live' => 1]);

        $this->assertToastContains(translate('shipment_report_failed'));
        $this->assertToastContains("No Trendyol carrier code is configured for 'torod_point'");
        $this->assertSame([], array_filter(Http::recorded()->map(fn (array $pair): Request => $pair[0])->all(), fn (Request $request): bool => $request->method() !== 'GET'), 'nothing was written before the carrier was known');
        $html = $this->page($order);
        $this->assertStringContainsString("No Trendyol carrier code is configured for &#039;torod_point&#039;", $html, 'and the order keeps what stopped it');
    }

    public function test_the_routes_of_the_order_screens_cannot_be_reached_by_a_link(): void
    {
        $order = $this->routable($this->trendyolAccount());

        foreach (['route', 'link-shipment', 'unlink-shipment', 'notify'] as $action) {
            $this->get(route('admin.sales-channels.orders.' . $action, $order))->assertStatus(405);
        }
        $this->get(route('admin.sales-channels.orders.show', 99999))->assertNotFound();
    }
}
