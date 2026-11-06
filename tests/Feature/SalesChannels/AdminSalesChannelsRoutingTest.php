<?php

namespace Tests\Feature\SalesChannels;

use App\Http\Controllers\Admin\SalesChannels\ChannelLabels;
use App\Models\FulfillmentRoutingDecision;
use App\Models\FulfillmentRoutingRule;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;

/** The rules an administrator writes, the invariants no rule can override, and the decisions the engine took. */
class AdminSalesChannelsRoutingTest extends AdminSalesChannelsTestCase
{
    private function index(array $query = []): string
    {
        return $this->get(route('admin.sales-channels.routing.index', $query))->assertOk()->getContent();
    }

    private function labels(): ChannelLabels
    {
        return app(ChannelLabels::class);
    }

    /** @return array<string, mixed> a rule that is valid as it is */
    private function ruleForm(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Trendyol via Torod', 'is_active' => 1, 'priority' => 10, 'action' => 'monitor_only', 'cod' => 'any',
        ], $overrides);
    }

    // ---- reading ------------------------------------------------------------------------------------------

    public function test_the_page_says_in_plain_words_what_the_engine_enforces_before_any_rule_and_that_no_rule_means_manual_review(): void
    {
        $html = $this->index();

        foreach (['invariants_come_first', 'invariant_channel_ships_it', 'invariant_unknown_unreleased_or_closed', 'invariant_dispatched_or_linked_is_never_decided_again',
            'invariant_channel_arranged_carrier_is_only_followed', 'invariant_cod_and_unlinked_lines_wait_for_a_person', 'then_the_first_matching_rule_decides', 'with_no_rule_the_answer_is_manual_review'] as $key) {
            $this->assertStringContainsString(e(translate($key)), $html, $key);
        }
        $this->assertStringContainsString(e(translate('managed_dispatch_not_available_explained')), $html);
        $this->assertStringContainsString(e(translate('no_routing_rules_yet')), $html);

        config(['sales_channels.managed_dispatch_enabled' => true]);
        $this->assertStringNotContainsString(e(translate('managed_dispatch_not_available_explained')), $this->index());
    }

    public function test_the_rules_are_listed_in_the_order_the_engine_tries_them(): void
    {
        $account = $this->trendyolAccount();
        $this->routingRule(['name' => 'Rule generic ten', 'priority' => 10]);
        $this->routingRule(['name' => 'Rule channel and sku ten', 'priority' => 10, 'sales_channel' => 'trendyol', 'sku' => 'SKU-1']);
        $this->routingRule(['name' => 'Rule generic five', 'priority' => 5]);
        $this->routingRule(['name' => 'Rule three conditions ten', 'priority' => 10, 'sales_channel' => 'trendyol', 'account_id' => $account->id, 'sku' => 'SKU-1']);
        $this->routingRule(['name' => 'Rule of seller nine', 'priority' => 1, 'seller_id' => 9]);

        $html = $this->index();

        $positions = array_map(fn (string $name): int => (int) strpos($html, $name), ['Rule generic five', 'Rule three conditions ten', 'Rule channel and sku ten', 'Rule generic ten']);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'lowest priority number first, the more specific rule first among equals');
        $this->assertGreaterThan($positions[3], (int) strpos($html, 'Rule of seller nine'), 'rules of another seller never compete with these, so they are listed apart');
    }

    public function test_each_rule_shows_every_condition_it_sets_as_a_labelled_chip(): void
    {
        $account = $this->trendyolAccount();
        $product = $this->product('Paracetamol 500mg', 'PARA-500');
        $own = app(InventoryLocationService::class)->ensureDefaults()[2];
        $this->routingRule([
            'name' => 'Very specific', 'sales_channel' => 'trendyol', 'account_id' => $account->id, 'fulfillment_mode' => 'fbm', 'execution_policy' => 'manual_review', 'product_id' => $product, 'sku' => 'SKU-9',
            'inventory_location_id' => $own->id, 'product_provider' => 'torod_point', 'country' => 'SA', 'city' => 'Riyadh', 'requires_confirmed_stock' => true,
            'conditions' => ['min_order_total' => '100.000000', 'max_order_total' => '500.000000', 'cod' => false], 'action' => 'route_to_provider', 'target_provider' => 'm5azn', 'target_location_id' => $own->id, 'note' => 'Reviewed by ops',
        ]);
        $this->routingRule(['name' => 'Anything']);

        $html = $this->index();

        foreach (['channel', 'account', 'fulfillment_mode', 'execution_policy', 'product', 'sku', 'stock_location', 'product_provider', 'country_code', 'city', 'confirmed_stock', 'min_order_total', 'max_order_total', 'cash_on_delivery'] as $key) {
            $this->assertStringContainsString('<span class="k-chip__key">' . e(translate($key)) . ':</span>', $html, $key);
        }
        foreach (['Trendyol KSA', 'Paracetamol 500mg', 'SKU-9', 'Own warehouse', 'Torod Point', 'Riyadh', '100.00', '500.00', 'Reviewed by ops', 'M5AZN'] as $shown) {
            $this->assertStringContainsString($shown, $html);
        }
        $this->assertStringContainsString(e(translate('every_order')), $html, 'a rule that sets nothing says it matches every order');
        $this->assertStringContainsString(e(translate('routing_action_route_to_provider')), $html);
    }

    public function test_a_rule_that_is_off_expired_or_not_started_says_so(): void
    {
        $this->routingRule(['name' => 'Switched off', 'is_active' => false]);
        $this->routingRule(['name' => 'Starts later', 'valid_from' => now()->addDays(3)]);
        $this->routingRule(['name' => 'Has ended', 'valid_to' => now()->subDay()]);

        $html = $this->index();

        $this->assertStringContainsString(e(translate('inactive')), $html);
        $this->assertStringContainsString(e(translate('not_started_yet')), $html);
        $this->assertStringContainsString(e(translate('expired')), $html);
    }

    public function test_editing_a_rule_shows_its_values_in_the_form(): void
    {
        $account = $this->trendyolAccount();
        $rule = $this->routingRule(['name' => 'Edit me', 'priority' => 33, 'sales_channel' => 'trendyol', 'account_id' => $account->id, 'sku' => 'SKU-EDIT', 'conditions' => ['min_order_total' => '12.500000', 'cod' => true],
            'valid_from' => '2026-12-01 00:00:00', 'note' => 'a note']);

        $html = $this->index(['edit' => $rule->id]);

        $this->assertStringContainsString('action="' . route('admin.sales-channels.routing.rules.update', $rule) . '"', $html);
        $this->assertStringContainsString('value="Edit me"', $html);
        $this->assertStringContainsString('value="33"', $html);
        $this->assertStringContainsString('value="SKU-EDIT"', $html);
        $this->assertStringContainsString('value="12.50"', $html);
        $this->assertStringContainsString('value="2026-12-01"', $html);
        $this->assertMatchesRegularExpression('/<option value="yes" selected>/', $html);
        $this->assertMatchesRegularExpression('/<option value="' . $account->id . '" selected>/', $html);
        $this->assertStringNotContainsString('action="' . route('admin.sales-channels.routing.rules.update', $rule) . '"', $this->index(), 'the edit form appears only when a rule is being edited');
    }

    // ---- creating -----------------------------------------------------------------------------------------

    public function test_a_rule_is_created_with_every_column_the_engine_reads(): void
    {
        $account = $this->trendyolAccount();
        $product = $this->product();
        $own = app(InventoryLocationService::class)->ensureDefaults()[2];

        $this->post(route('admin.sales-channels.routing.rules.store'), $this->ruleForm([
            'sales_channel' => 'trendyol', 'account_id' => $account->id, 'fulfillment_mode' => 'fbm', 'execution_policy' => 'manual_review', 'product_id' => $product, 'sku' => 'SKU-1',
            'inventory_location_id' => $own->id, 'product_provider' => 'torod_point', 'country' => 'sa', 'city' => 'Riyadh', 'requires_confirmed_stock' => 1,
            'min_order_total' => '100', 'max_order_total' => '500.50', 'cod' => 'no', 'action' => 'route_to_provider', 'target_provider' => 'm5azn', 'target_location_id' => $own->id,
            'note' => 'Reviewed by ops', 'valid_from' => '2026-12-01', 'valid_to' => '2026-12-31',
        ]))->assertRedirect(route('admin.sales-channels.routing.index'))->assertSessionHasNoErrors();

        $rule = FulfillmentRoutingRule::firstOrFail();
        $this->assertSame(
            ['Trendyol via Torod', 10, true, 0, 'trendyol', $account->id, 'fbm', 'manual_review', $product, 'SKU-1', $own->id, 'torod_point', 'SA', 'Riyadh', true, 'route_to_provider', 'm5azn', $own->id, 'Reviewed by ops', 1, 1],
            [$rule->name, $rule->priority, $rule->is_active, $rule->seller_id, $rule->sales_channel, $rule->account_id, $rule->fulfillment_mode, $rule->execution_policy, $rule->product_id, $rule->sku, $rule->inventory_location_id,
                $rule->product_provider, $rule->country, $rule->city, $rule->requires_confirmed_stock, $rule->action, $rule->target_provider, $rule->target_location_id, $rule->note, $rule->created_by, $rule->updated_by],
        );
        $this->assertSame(['min_order_total' => '100.000000', 'max_order_total' => '500.500000', 'cod' => false], $rule->conditions);
        $this->assertSame(['2026-12-01 00:00:00', '2026-12-31 23:59:59'], [$rule->valid_from->format('Y-m-d H:i:s'), $rule->valid_to->format('Y-m-d H:i:s')], 'the window covers whole days');
        $this->assertToastContains(translate('routing_rule_created'));
    }

    public function test_a_rule_that_sets_nothing_matches_everything_and_stores_no_condition(): void
    {
        $this->post(route('admin.sales-channels.routing.rules.store'), $this->ruleForm(['name' => 'Anything', 'action' => 'manual_review']))->assertSessionHasNoErrors();

        $rule = FulfillmentRoutingRule::firstOrFail();
        foreach (['sales_channel', 'account_id', 'fulfillment_mode', 'execution_policy', 'product_id', 'sku', 'inventory_location_id', 'product_provider', 'country', 'city', 'conditions', 'target_provider', 'target_location_id', 'note', 'valid_from', 'valid_to'] as $column) {
            $this->assertNull($rule->{$column}, $column);
        }
        $this->assertFalse($rule->requires_confirmed_stock);
    }

    public function test_a_rule_for_an_account_belongs_to_that_accounts_seller(): void
    {
        $account = $this->trendyolAccount();
        $account->forceFill(['seller_id' => 7])->save();

        $this->post(route('admin.sales-channels.routing.rules.store'), $this->ruleForm(['account_id' => $account->id]))->assertSessionHasNoErrors();
        $this->assertSame(7, FulfillmentRoutingRule::firstOrFail()->seller_id, 'the engine reads only the rules of the order\'s own seller');

        $this->post(route('admin.sales-channels.routing.rules.store'), $this->ruleForm(['account_id' => $account->id, 'seller_id' => 8]))->assertSessionHasErrorsIn('rule_new', 'seller_id');
        $this->assertSame(1, FulfillmentRoutingRule::count());
    }

    public function test_a_rule_cannot_name_one_channel_and_the_account_of_another(): void
    {
        $trendyol = $this->trendyolAccount();

        $this->post(route('admin.sales-channels.routing.rules.store'), $this->ruleForm(['sales_channel' => 'amazon', 'account_id' => $trendyol->id]))->assertSessionHasErrorsIn('rule_new', 'account_id');

        $this->assertSame(0, FulfillmentRoutingRule::count());
        $this->assertSame(translate('rule_account_is_of_another_channel'), session('errors')->getBag('rule_new')->first('account_id'));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidRules(): array
    {
        return [
            'no name' => [['name' => ''], 'name'],
            'a priority that is not a number' => [['priority' => 'high'], 'priority'],
            'a negative priority' => [['priority' => -1], 'priority'],
            'a priority with a fraction' => [['priority' => 1.5], 'priority'],
            'a channel that cannot be connected' => [['sales_channel' => 'storefront'], 'sales_channel'],
            'an account that does not exist' => [['account_id' => 9999], 'account_id'],
            'a mode that does not exist' => [['fulfillment_mode' => 'teleported'], 'fulfillment_mode'],
            'the mode that is never routed' => [['fulfillment_mode' => 'unknown'], 'fulfillment_mode'],
            'a policy that does not exist' => [['execution_policy' => 'whenever'], 'execution_policy'],
            'a product that does not exist' => [['product_id' => 9999], 'product_id'],
            'a location that does not exist' => [['inventory_location_id' => 9999], 'inventory_location_id'],
            'a provider we do not integrate' => [['product_provider' => 'dhl'], 'product_provider'],
            'a country that is not two letters' => [['country' => 'SAU'], 'country'],
            'a negative minimum total' => [['min_order_total' => -1], 'min_order_total'],
            'a maximum total below the minimum' => [['min_order_total' => 500, 'max_order_total' => 100], 'max_order_total'],
            'a cash on delivery choice that does not exist' => [['cod' => 'maybe'], 'cod'],
            'an action that does not exist' => [['action' => 'delete_the_order'], 'action'],
            'no action' => [['action' => ''], 'action'],
            'a target provider that is not one' => [['target_provider' => 'dhl'], 'target_provider'],
            'a target provider on an action that sends nothing' => [['action' => 'manual_review', 'target_provider' => 'torod_point'], 'target_provider'],
            'a target location on a held order' => [['action' => 'hold', 'target_location_id' => 1], 'target_location_id'],
            'a window that ends before it starts' => [['valid_from' => '2026-12-31', 'valid_to' => '2026-12-01'], 'valid_to'],
            'a date that is not a date' => [['valid_from' => 'next week'], 'valid_from'],
            'a note that is too long' => [['note' => str_repeat('x', 501)], 'note'],
        ];
    }

    #[DataProvider('invalidRules')]
    public function test_bad_input_is_rejected_on_the_new_rule_form_and_nothing_is_created(array $overrides, string $field): void
    {
        app(InventoryLocationService::class)->ensureDefaults();

        $this->post(route('admin.sales-channels.routing.rules.store'), $this->ruleForm($overrides))
            ->assertRedirect(route('admin.sales-channels.routing.index'))
            ->assertSessionHasErrorsIn('rule_new', $field);

        $this->assertSame(0, FulfillmentRoutingRule::count());
    }

    public function test_a_rejected_new_rule_comes_back_open_with_what_was_typed_and_why(): void
    {
        $this->post(route('admin.sales-channels.routing.rules.store'), $this->ruleForm(['name' => 'Typed name', 'priority' => 'high']));

        $html = $this->get(route('admin.sales-channels.routing.index'))->getContent();

        $this->assertMatchesRegularExpression('/<details\s+open\s*>/', $html);
        $this->assertStringNotContainsString('@open', $html);
        $this->assertStringContainsString('value="Typed name"', $html);
        $this->assertStringContainsString('role="alert"', $html);
    }

    // ---- editing, switching off ---------------------------------------------------------------------------

    public function test_a_rule_is_edited_and_a_field_emptied_is_cleared(): void
    {
        $account = $this->trendyolAccount();
        $rule = $this->routingRule(['name' => 'Before', 'priority' => 50, 'sales_channel' => 'trendyol', 'account_id' => $account->id, 'sku' => 'SKU-OLD', 'conditions' => ['min_order_total' => '10.000000'], 'note' => 'old note', 'created_by' => 3]);

        $this->post(route('admin.sales-channels.routing.rules.update', $rule), $this->ruleForm(['name' => 'After', 'priority' => 20, 'action' => 'hold', 'sku' => '', 'min_order_total' => '', 'note' => '']))
            ->assertRedirect(route('admin.sales-channels.routing.index'))->assertSessionHasNoErrors();

        $rule->refresh();
        $this->assertSame(['After', 20, 'hold', null, null, null, null, null, 3, 1], [$rule->name, $rule->priority, $rule->action, $rule->sku, $rule->conditions, $rule->note, $rule->sales_channel, $rule->account_id, $rule->created_by, $rule->updated_by]);
        $this->assertToastContains(translate('routing_rule_saved'));
        $this->assertSame(1, FulfillmentRoutingRule::count());
    }

    public function test_a_rejected_edit_goes_back_to_that_rules_form(): void
    {
        $rule = $this->routingRule(['name' => 'Keep me']);

        $this->post(route('admin.sales-channels.routing.rules.update', $rule), $this->ruleForm(['name' => '']))
            ->assertRedirect(route('admin.sales-channels.routing.index', ['edit' => $rule->id]))
            ->assertSessionHasErrorsIn('rule_edit', 'name');

        $this->assertSame('Keep me', $rule->fresh()->name);
        $this->assertStringContainsString('role="alert"', $this->get(route('admin.sales-channels.routing.index', ['edit' => $rule->id]))->getContent());
    }

    public function test_a_rule_is_switched_off_and_on_never_deleted(): void
    {
        $rule = $this->routingRule();

        $this->post(route('admin.sales-channels.routing.rules.toggle', $rule))->assertRedirect(route('admin.sales-channels.routing.index'));
        $this->assertFalse($rule->fresh()->is_active);
        $this->assertToastContains(translate('routing_rule_deactivated'));

        $this->post(route('admin.sales-channels.routing.rules.toggle', $rule));
        $this->assertTrue($rule->fresh()->is_active);

        $this->assertFalse(Route::has('admin.sales-channels.routing.rules.destroy'), 'there is no way to delete a rule');
        $this->delete(route('admin.sales-channels.routing.rules.update', $rule))->assertStatus(405);
        $this->assertSame(1, FulfillmentRoutingRule::count());
    }

    public function test_a_rule_that_is_not_there_is_not_found(): void
    {
        $this->post(route('admin.sales-channels.routing.rules.update', 9999), $this->ruleForm())->assertNotFound();
        $this->post(route('admin.sales-channels.routing.rules.toggle', 9999))->assertNotFound();
    }

    // ---- what the rules do ---------------------------------------------------------------------------------

    public function test_a_rule_written_here_decides_the_orders_it_matches_and_only_those(): void
    {
        $account = $this->trendyolAccount();
        $this->post(route('admin.sales-channels.routing.rules.store'), $this->ruleForm(['sales_channel' => 'trendyol', 'min_order_total' => 300, 'country' => 'sa', 'target_provider' => 'torod_point']))->assertSessionHasNoErrors();
        $small = $this->channelOrder($account, ['order_total' => '210.000000'], [['product_id' => $this->product()]]);
        $big = $this->channelOrder($account, ['order_total' => '420.000000'], [['product_id' => $this->product()]]);

        foreach ([$small, $big] as $order) {
            $this->from(route('admin.sales-channels.orders.show', $order))->post(route('admin.sales-channels.orders.route', $order));
        }

        $this->assertSame('no_rule_matched', FulfillmentRoutingDecision::where('external_order_mapping_id', $small->id)->firstOrFail()->reason_code, 'below the minimum total, the rule does not match');
        $decision = FulfillmentRoutingDecision::where('external_order_mapping_id', $big->id)->firstOrFail();
        $this->assertSame(['monitor_only', 'rule_monitor_only', 'torod_point'], [$decision->action, $decision->reason_code, $decision->provider]);
        $this->assertSame('Trendyol via Torod', $decision->rule_snapshot['name'], 'the decision keeps the rule as it was');
    }

    public function test_a_rule_that_is_switched_off_decides_nothing(): void
    {
        $account = $this->trendyolAccount();
        $rule = $this->routingRule(['action' => 'monitor_only', 'sales_channel' => 'trendyol']);
        $this->post(route('admin.sales-channels.routing.rules.toggle', $rule));
        $order = $this->channelOrder($account, [], [['product_id' => $this->product()]]);

        $this->from(route('admin.sales-channels.orders.show', $order))->post(route('admin.sales-channels.orders.route', $order));

        $this->assertSame('no_rule_matched', FulfillmentRoutingDecision::firstOrFail()->reason_code);
    }

    // ---- the decisions ------------------------------------------------------------------------------------

    private function decisions(int $count): SalesChannelAccount
    {
        $account = $this->trendyolAccount();
        for ($index = 1; $index <= $count; $index++) {
            $order = $this->channelOrder($account, ['external_order_id' => 'DEC-' . $index], []);
            $this->routingDecision($order, $index % 2 === 0
                ? ['action' => 'monitor_only', 'execution_mode' => 'externally_managed', 'reason_code' => 'rule_monitor_only', 'rule_snapshot' => ['name' => 'Rule of ' . $index], 'provider' => 'torod_point']
                : []);
        }

        return $account;
    }

    public function test_the_latest_decisions_are_paged_and_link_to_their_orders(): void
    {
        $this->decisions(30);

        $first = $this->index();

        $this->assertSame(25, preg_match_all('/orders\/\d+">#\d+<\/a>/', $first), 'a page holds 25 decisions');
        $this->assertStringContainsString(e($this->labels()->orderReason('no_rule_matched')), $first);
        $this->assertStringContainsString(route('admin.sales-channels.orders.show', 30), $first, 'the newest decision first, with a link to its order');
        $this->assertStringContainsString('page=2', $first);
        $this->assertStringContainsString('Rule of 30', $first);
    }

    public function test_the_decisions_can_be_filtered_by_action_and_by_channel(): void
    {
        $this->decisions(6);
        $amazon = $this->amazonAccount();
        $this->routingDecision($this->channelOrder($amazon, ['external_order_id' => 'AMZ-ONLY'], []), ['action' => 'channel_fulfills', 'execution_mode' => 'externally_managed', 'reason_code' => 'channel_fulfills_order']);

        $monitor = $this->index(['action' => 'monitor_only']);
        $this->assertSame(3, preg_match_all('/Rule of \d/', $monitor));
        $this->assertStringNotContainsString(e($this->labels()->orderReason('channel_fulfills_order')), $monitor);

        $amazonOnly = $this->index(['channel' => 'amazon']);
        $this->assertStringContainsString(e($this->labels()->orderReason('channel_fulfills_order')), $amazonOnly);
        $this->assertStringNotContainsString('Rule of', $amazonOnly);

        $this->assertStringContainsString(e(translate('no_decisions_yet')), $this->index(['action' => 'hold']), 'an empty list says so');
        $this->get(route('admin.sales-channels.routing.index', ['action' => ['x'], 'channel' => 'storefront']))->assertOk();
    }
}
