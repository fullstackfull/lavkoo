<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\StockSource;
use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\FulfillmentMode;
use App\Enums\SalesChannels\RoutingAction;
use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\FulfillmentRoutingDecision;
use App\Models\FulfillmentRoutingRule;
use App\Models\InventoryLocation;
use App\Models\ProviderFulfillment;
use App\Models\ProviderProductMapping;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use App\Services\SalesChannels\Routing\DynamicFulfillmentRoutingEngine;
use App\Services\SalesChannels\Sync\ChannelOrderIngestor;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsRemoteOrders;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\Support\FakeCurrencyRates;
use Tests\TestCase;

/** The decision engine: what it will not be talked out of, what an administrator's rules can decide, and what is kept of every decision. */
class RoutingEngineTest extends TestCase
{
    use BuildsRemoteOrders;
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    private DynamicFulfillmentRoutingEngine $engine;

    private InventoryLocation $torod;

    private InventoryLocation $m5azn;

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR']);
        [$this->torod, $this->m5azn] = app(InventoryLocationService::class)->ensureDefaults();
        $this->engine = app(DynamicFulfillmentRoutingEngine::class);
        $this->account = $this->trendyolAccount();
    }

    /** A listing linked to a product whose goods sit at a location. */
    private function stocked(string $sku, int $productId, InventoryLocation $location, int $providerStock = 50, bool $confirmed = true): void
    {
        SalesChannelProductMapping::create(['account_id' => $this->account->id, 'product_id' => $productId, 'listing_key' => $sku, 'external_sku' => $sku, 'external_barcode' => $sku, 'status' => 'mapped', 'stock_location_id' => $location->id]);
        ProviderProductMapping::create([
            'provider' => $location->provider, 'product_id' => $productId, 'variant_key' => '', 'external_id' => 'ext-' . $productId, 'status' => MappingStatus::Approved,
            'stock_source' => $confirmed ? StockSource::ProviderSync : StockSource::None, 'provider_stock' => $providerStock, 'provider_stock_synced_at' => now(),
        ]);
    }

    private function place(array $lines = null, array $overrides = [], string $orderId = 'ORD-1'): ExternalOrderMapping
    {
        $order = $this->remoteOrder($orderId, '900', ChannelOrderStatus::New, $lines ?? [$this->remoteLine('L1', 'SKU-1', 'SKU-1', 2, '100')], overrides: $overrides + ['fulfillmentChannelRaw' => 'seller_pays', 'fulfillmentMode' => FulfillmentMode::MerchantManaged]);

        return app(ChannelOrderIngestor::class)->ingest($this->account, $order)->mapping;
    }

    private function rule(array $values): FulfillmentRoutingRule
    {
        return FulfillmentRoutingRule::create($values + ['name' => 'Test rule', 'priority' => 100, 'is_active' => true, 'action' => 'route_to_provider']);
    }

    private function decide(ExternalOrderMapping $mapping): FulfillmentRoutingDecision
    {
        return $this->engine->route($mapping->refresh());
    }

    // ---- what the engine cannot be talked out of --------------------------------------------------------

    public function test_an_order_the_channel_ships_itself_is_never_given_to_a_provider_whatever_the_rules_say(): void
    {
        $this->stocked('SKU-1', 10, $this->torod);
        $this->rule(['action' => 'route_to_provider', 'target_provider' => 'torod_point', 'priority' => 1]);
        config(['sales_channels.managed_dispatch_enabled' => true]);
        $fba = $this->place(overrides: ['fulfillmentMode' => FulfillmentMode::Fba, 'fulfillmentChannelRaw' => 'AFN']);

        $decision = $this->decide($fba);

        $this->assertSame([RoutingAction::ChannelFulfills->value, 'channel_fulfills_order', null], [$decision->action, $decision->reason_code, $decision->provider]);
        $this->assertSame('externally_managed', $fba->refresh()->execution_policy);
        $this->assertSame(ExternalOrderMapping::LINK_NOT_APPLICABLE, $fba->shipment_link_state);
        $this->assertSame(0, ProviderFulfillment::count(), 'Nothing reaches a provider.');
    }

    public function test_an_order_whose_fulfilment_the_channel_did_not_state_waits_for_a_person(): void
    {
        $this->rule(['action' => 'route_to_provider', 'target_provider' => 'torod_point', 'priority' => 1]);

        $decision = $this->decide($this->place(overrides: ['fulfillmentMode' => FulfillmentMode::Unknown]));

        $this->assertSame(['manual_review', 'unknown_fulfillment_mode'], [$decision->action, $decision->reason_code]);
    }

    public function test_an_unreleased_or_closed_order_is_held(): void
    {
        $this->stocked('SKU-1', 10, $this->torod);
        $pending = $this->place(overrides: ['status' => ChannelOrderStatus::Pending, 'externalStatus' => 'Awaiting'], orderId: 'P');
        $cancelled = $this->place(overrides: ['status' => ChannelOrderStatus::Cancelled, 'externalStatus' => 'Cancelled'], orderId: 'C');

        $this->assertSame(['hold', 'order_not_releasable'], [$this->decide($pending)->action, $this->decide($pending)->reason_code]);
        $this->assertSame('hold', $this->decide($cancelled)->action);
    }

    public function test_a_package_whose_carrier_the_channel_arranges_is_only_followed_never_shipped_twice(): void
    {
        $this->stocked('SKU-1', 10, $this->torod);
        $this->rule(['action' => 'route_to_provider', 'target_provider' => 'torod_point', 'priority' => 1]);
        config(['sales_channels.managed_dispatch_enabled' => true]);
        $mapping = $this->place(overrides: ['fulfillmentChannelRaw' => 'trendyol_pays']);

        $decision = $this->decide($mapping);

        $this->assertSame(['monitor_only', 'carrier_arranged_by_channel'], [$decision->action, $decision->reason_code]);
        $this->assertSame(ExternalOrderMapping::LINK_UNLINKED, $mapping->refresh()->shipment_link_state, 'It waits to be linked to the shipment that exists.');
    }

    public function test_cash_on_delivery_waits_for_a_person_unless_the_account_allows_it(): void
    {
        $this->stocked('SKU-1', 10, $this->torod);
        $this->rule(['action' => 'monitor_only']);
        $cod = $this->place(overrides: ['isCod' => true]);

        $this->assertSame('cod_requires_review', $this->decide($cod)->reason_code);

        $this->account->forceFill(['settings' => array_replace($this->account->settings, ['route_cod_orders' => true])])->save();
        $this->assertSame('monitor_only', $this->engine->route($cod->refresh()->load('account'))->action, 'The account switched COD routing on, so the rules decide.');
    }

    public function test_a_line_not_linked_to_a_product_waits_for_a_person(): void
    {
        $this->rule(['action' => 'monitor_only']);

        $this->assertSame('unmapped_lines', $this->decide($this->place())->reason_code);
    }

    public function test_with_no_rule_nothing_is_routed(): void
    {
        $this->stocked('SKU-1', 10, $this->torod);

        $decision = $this->decide($this->place());

        $this->assertSame(['manual_review', 'no_rule_matched'], [$decision->action, $decision->reason_code]);
        $this->assertSame('manual_review', $this->place(orderId: 'B')->refresh()->execution_policy ?? 'manual_review');
    }

    public function test_an_account_that_follows_its_orders_does_so_when_no_rule_decides(): void
    {
        $this->stocked('SKU-1', 10, $this->torod);
        $this->account->forceFill(['execution_policy' => 'externally_managed'])->save();
        $mapping = $this->place();

        $decision = $this->decide($mapping);

        $this->assertSame([RoutingAction::MonitorOnly->value, 'account_default_externally_managed', null, null], [$decision->action, $decision->reason_code, $decision->rule_id, $decision->provider]);
        $this->assertSame(['externally_managed', ExternalOrderMapping::LINK_UNLINKED, null], [$mapping->refresh()->execution_policy, $mapping->shipment_link_state, $mapping->review_reason]);
        $this->assertSame('externally_managed', $decision->inputs['account_policy']);
        $this->assertSame(0, ProviderFulfillment::count(), 'Following an order creates no fulfilment.');
    }

    public function test_a_rule_still_wins_over_the_account_default_and_the_default_never_overrides_what_the_engine_refuses(): void
    {
        $this->stocked('SKU-1', 10, $this->torod);
        $this->account->forceFill(['execution_policy' => 'externally_managed'])->save();

        $fba = $this->place(orderId: 'FBA', overrides: ['fulfillmentMode' => FulfillmentMode::Fba, 'fulfillmentChannelRaw' => 'AFN']);
        $this->assertSame('channel_fulfills_order', $this->decide($fba)->reason_code);

        $cod = $this->place(orderId: 'COD', overrides: ['isCod' => true]);
        $this->assertSame('cod_requires_review', $this->decide($cod)->reason_code, 'Cash on delivery still waits for a person.');

        $this->rule(['action' => 'manual_review', 'priority' => 1]);
        $ruled = $this->place(orderId: 'RULED');
        $this->assertSame(['manual_review', 'rule_requires_manual_review'], [$this->decide($ruled)->action, $this->decide($ruled)->reason_code]);
    }

    public function test_the_standard_account_default_is_manual_review(): void
    {
        $this->stocked('SKU-1', 10, $this->torod);

        $this->assertSame('manual_review', $this->account->execution_policy);
        $this->assertSame('no_rule_matched', $this->decide($this->place())->reason_code);
    }

    // ---- what the rules decide --------------------------------------------------------------------------

    public function test_a_rule_can_say_the_order_is_followed_not_shipped_by_us(): void
    {
        $this->stocked('SKU-1', 10, $this->torod);
        $rule = $this->rule(['name' => 'Trendyol via Torod', 'action' => 'monitor_only', 'sales_channel' => 'trendyol', 'target_provider' => 'torod_point']);
        $mapping = $this->place();

        $decision = $this->decide($mapping);

        $this->assertSame(['monitor_only', $rule->id, 'torod_point'], [$decision->action, $decision->rule_id, $decision->provider]);
        $this->assertSame('Trendyol via Torod', $decision->rule_snapshot['name']);
        $this->assertSame(['externally_managed', 'torod_point', ExternalOrderMapping::LINK_UNLINKED, null], [$mapping->refresh()->execution_policy, $mapping->provider, $mapping->shipment_link_state, $mapping->review_reason]);
        $this->assertSame(0, ProviderFulfillment::count(), 'Following an order creates no fulfilment.');
    }

    public function test_a_rule_that_wants_a_shipment_created_is_recorded_as_wanted_and_the_order_waits_because_that_is_not_available(): void
    {
        $this->stocked('SKU-1', 10, $this->torod);
        $this->rule(['name' => 'App ships', 'action' => 'route_to_provider']);
        $mapping = $this->place();

        $decision = $this->decide($mapping);

        $this->assertSame(['manual_review', 'managed_dispatch_not_available'], [$decision->action, $decision->reason_code]);
        $this->assertSame(['route_to_provider', 'torod_point'], [$decision->inputs['wanted_action'], $decision->inputs['wanted_provider']], 'What the rule wanted is kept.');
        $this->assertSame('manual_review', $mapping->refresh()->execution_policy);
        $this->assertSame(0, ProviderFulfillment::count());
    }

    public function test_when_the_switch_is_on_a_rule_routes_each_line_to_the_provider_that_holds_it(): void
    {
        config(['sales_channels.managed_dispatch_enabled' => true]);
        $this->stocked('SKU-1', 10, $this->torod);
        $this->stocked('SKU-2', 11, $this->m5azn);
        $this->rule(['name' => 'Route by stock', 'action' => 'route_to_provider']);
        $mapping = $this->place([$this->remoteLine('L1', 'SKU-1', 'SKU-1', 1, '50'), $this->remoteLine('L2', 'SKU-2', 'SKU-2', 1, '60')]);

        $decision = $this->decide($mapping);

        $this->assertSame(['route_to_provider', null], [$decision->action, $decision->provider], 'Two providers: no single provider for the order.');
        $this->assertSame(['torod_point', 'm5azn'], array_column($decision->line_decisions, 'provider'));
        $this->assertSame(['torod_point', 'm5azn'], ExternalOrderLine::where('mapping_id', $mapping->id)->orderBy('id')->pluck('provider')->all(), 'Each line carries the provider whose cost it will incur.');
        $this->assertSame('managed', $mapping->refresh()->execution_policy);
    }

    public function test_a_rule_that_names_a_provider_which_does_not_hold_the_goods_does_not_send_them_there(): void
    {
        config(['sales_channels.managed_dispatch_enabled' => true]);
        $this->stocked('SKU-1', 10, $this->torod);
        $this->rule(['action' => 'route_to_provider', 'target_provider' => 'm5azn']);

        $decision = $this->decide($this->place());

        $this->assertSame(['manual_review', 'stock_not_at_target_provider'], [$decision->action, $decision->reason_code]);
    }

    public function test_the_most_specific_rule_wins_among_equal_priorities_and_a_lower_number_wins_over_both(): void
    {
        $this->stocked('SKU-1', 10, $this->torod);
        $general = $this->rule(['name' => 'General', 'action' => 'monitor_only', 'priority' => 100]);
        $specific = $this->rule(['name' => 'Specific', 'action' => 'hold', 'priority' => 100, 'sales_channel' => 'trendyol', 'sku' => 'SKU-1']);
        $mapping = $this->place();

        $this->assertSame($specific->id, $this->decide($mapping)->rule_id);

        $urgent = $this->rule(['name' => 'Urgent', 'action' => 'monitor_only', 'priority' => 1]);
        $this->assertSame($urgent->id, $this->engine->route($mapping->refresh())->rule_id, 'A new rule re-decides an order that has not been dispatched.');
        $this->assertSame(2, FulfillmentRoutingDecision::where('external_order_mapping_id', $mapping->id)->count());
        $this->assertSame(1, FulfillmentRoutingDecision::where('external_order_mapping_id', $mapping->id)->where('is_current', true)->count(), 'Exactly one current decision; the earlier one stays as history.');
    }

    public function test_rules_match_on_channel_account_product_location_city_stock_total_and_window(): void
    {
        $this->stocked('SKU-1', 10, $this->torod);
        $mapping = $this->place();
        $miss = [
            ['sales_channel' => 'amazon'], ['account_id' => 999], ['product_id' => 99], ['sku' => 'OTHER'], ['inventory_location_id' => $this->m5azn->id], ['product_provider' => 'm5azn'],
            ['country' => 'AE'], ['city' => 'Jeddah'], ['conditions' => ['min_order_total' => '1000']], ['conditions' => ['max_order_total' => '10']], ['conditions' => ['cod' => true]],
            ['valid_to' => now()->subDay()], ['valid_from' => now()->addDay()], ['is_active' => false],
        ];
        foreach ($miss as $index => $conditions) {
            $this->rule($conditions + ['name' => "miss {$index}", 'action' => 'hold']);
        }
        $this->assertSame('no_rule_matched', $this->decide($mapping)->reason_code, 'None of the non-matching rules decided.');

        $this->rule(['name' => 'Hit', 'action' => 'monitor_only', 'sales_channel' => 'trendyol', 'account_id' => $this->account->id, 'product_id' => 10, 'sku' => 'sku-1', 'inventory_location_id' => $this->torod->id,
            'product_provider' => 'torod_point', 'country' => 'SA', 'city' => 'riyadh', 'requires_confirmed_stock' => true, 'conditions' => ['min_order_total' => '100', 'max_order_total' => '500', 'cod' => false]]);
        $this->assertSame('monitor_only', $this->engine->route($mapping->refresh())->action);
    }

    public function test_a_rule_that_needs_confirmed_stock_does_not_match_stock_nobody_vouches_for(): void
    {
        $this->stocked('SKU-1', 10, $this->torod, confirmed: false);
        $this->rule(['action' => 'monitor_only', 'requires_confirmed_stock' => true]);

        $this->assertSame('no_rule_matched', $this->decide($this->place())->reason_code);
    }

    // ---- what is kept, and what is not decided twice ----------------------------------------------------

    public function test_the_same_decision_is_not_recorded_twice(): void
    {
        $this->stocked('SKU-1', 10, $this->torod);
        $this->rule(['action' => 'monitor_only']);
        $mapping = $this->place();

        $first = $this->decide($mapping);
        $second = $this->decide($mapping);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, FulfillmentRoutingDecision::count());
    }

    public function test_a_stored_decision_whose_keys_come_back_in_another_order_is_still_the_same_decision(): void
    {
        $this->stocked('SKU-1', 10, $this->torod);
        $this->rule(['action' => 'monitor_only']);
        $mapping = $this->place();
        $first = $this->decide($mapping);
        $sortedLikeMysqlDoes = array_map(static function (array $line): array {
            uksort($line, static fn (string $left, string $right): int => [strlen($left), $left] <=> [strlen($right), $right]);

            return $line;
        }, $first->line_decisions);
        $this->assertNotSame(array_keys($first->line_decisions[0]), array_keys($sortedLikeMysqlDoes[0]), 'The fixture has to change the order, or it proves nothing.');
        DB::table('fulfillment_routing_decisions')->where('id', $first->id)->update(['line_decisions' => json_encode($sortedLikeMysqlDoes)]);

        $second = $this->decide($mapping);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, FulfillmentRoutingDecision::count(), 'A JSON column does not keep the order of keys; that must not make an unchanged order look decided anew.');
    }

    public function test_a_decision_keeps_its_reason_its_inputs_and_the_time(): void
    {
        $this->stocked('SKU-1', 10, $this->torod);
        $this->rule(['name' => 'Follow', 'action' => 'monitor_only']);

        $decision = $this->decide($this->place());

        $this->assertStringContainsString("Rule 'Follow'", $decision->reason);
        $this->assertSame('trendyol', $decision->inputs['channel']);
        $this->assertSame(10, $decision->inputs['lines'][0]['product_id']);
        $this->assertTrue($decision->applied);
        $this->assertNotNull($decision->created_at);
        $this->assertSame('system', $decision->decided_by);
    }

    public function test_an_order_whose_shipment_is_linked_is_never_decided_again(): void
    {
        $this->stocked('SKU-1', 10, $this->torod);
        $this->rule(['name' => 'Follow', 'action' => 'monitor_only']);
        $mapping = $this->place();
        $first = $this->decide($mapping);
        $mapping->refresh()->forceFill(['shipment_link_state' => ExternalOrderMapping::LINK_LINKED, 'shipment_reference' => 'T-1', 'provider' => 'torod_point'])->save();
        $this->rule(['name' => 'Newer', 'action' => 'hold', 'priority' => 1]);

        $again = $this->engine->route($mapping->refresh());

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, FulfillmentRoutingDecision::count(), 'No re-routing after a shipment exists.');
    }

    public function test_an_order_the_application_dispatched_is_never_decided_again(): void
    {
        $this->stocked('SKU-1', 10, $this->torod);
        $this->rule(['action' => 'monitor_only']);
        $mapping = $this->place();
        $this->decide($mapping);
        $mapping->refresh()->forceFill(['order_id' => 777])->save();
        $this->rule(['name' => 'Newer', 'action' => 'hold', 'priority' => 1]);

        $this->engine->route($mapping->refresh());

        $this->assertSame(1, FulfillmentRoutingDecision::count());
    }

    public function test_a_reason_raised_by_someone_else_outlives_a_decision_of_ours(): void
    {
        $this->stocked('SKU-1', 10, $this->torod);
        $mapping = $this->place();
        $mapping->forceFill(['review_reason' => 'modified_after_routing'])->save();

        $this->decide($mapping);

        $this->assertSame('modified_after_routing', $mapping->refresh()->review_reason, 'Routing does not hide that the order changed after it was routed.');
    }
}
