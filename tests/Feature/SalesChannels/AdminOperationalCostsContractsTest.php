<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\OperationalCosts\ContractStatus;
use App\Http\Controllers\Admin\OperationalCosts\CostLabels;
use App\Models\OperationalCostContract;
use App\Models\OperationalCostContractRevision;
use App\Models\OperationalCostRule;
use App\Services\OperationalCosts\ContractService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesOperationalCostData;

/** Contracts: a draft with no price in it, rules that only a draft takes, and a history that is never rewritten. Every price below is a number made up for the test. */
class AdminOperationalCostsContractsTest extends AdminPagesTestCase
{
    use CreatesOperationalCostData;

    private function showUrl(OperationalCostContract $contract): string
    {
        return route('admin.operational-costs.contracts.show', $contract->id);
    }

    /** @param array<string, mixed> $payload */
    private function addRule(OperationalCostContract $contract, array $payload): void
    {
        $this->from($this->showUrl($contract))->post(route('admin.operational-costs.contracts.rules.store', $contract->id), $payload);
    }

    /** @return array<string, mixed> */
    private function headerPayload(array $overrides = []): array
    {
        return $overrides + [
            'party_type' => 'fulfillment_provider', 'provider_code' => 'torod_point', 'party_name' => 'Torod warehouse', 'currency' => 'sar',
            'effective_from' => '2026-01-01', 'reference' => 'CT-1', 'expected_components' => ['shipping', 'picking'], 'tolerance_percent' => '3',
        ];
    }

    public function test_the_list_filters_by_kind_company_status_search_and_books(): void
    {
        $active = $this->activeContract('torod_point');
        $draft = $this->draftContract('m5azn', partyType: 'supplier');
        $seller = $this->draftContract('amazon', seller: 9, partyType: 'marketplace');

        $all = $this->get(route('admin.operational-costs.contracts.index'))->assertOk()->getContent();
        $this->assertStringContainsString($active->party_name, $all);
        $this->assertStringContainsString($draft->party_name, $all);
        $this->assertStringNotContainsString($seller->party_name, $all, 'A seller\'s contract is not in the platform\'s list.');

        $this->assertStringNotContainsString($draft->party_name, $this->get(route('admin.operational-costs.contracts.index', ['status' => 'active']))->getContent());
        $this->assertStringNotContainsString($active->party_name, $this->get(route('admin.operational-costs.contracts.index', ['party_type' => 'supplier']))->getContent());
        $this->assertStringContainsString($draft->party_name, $this->get(route('admin.operational-costs.contracts.index', ['provider' => 'm5azn']))->getContent());
        $this->assertStringContainsString($active->party_name, $this->get(route('admin.operational-costs.contracts.index', ['q' => 'TEST-1', 'status' => 'active']))->getContent());
        $this->assertStringContainsString($seller->party_name, $this->get(route('admin.operational-costs.contracts.index', ['seller' => 9]))->getContent());
        $this->assertStringContainsString($seller->party_name, $this->get(route('admin.operational-costs.contracts.index', ['seller' => 'all']))->getContent());
    }

    public function test_the_contracts_list_survives_a_hand_made_query_string(): void
    {
        $this->activeContract();

        $this->get(route('admin.operational-costs.contracts.index', ['status' => ['x'], 'q' => ['y'], 'seller' => ['z'], 'provider' => ['p']]))->assertOk();
    }

    public function test_a_new_contract_form_asks_for_no_price_and_fills_in_none(): void
    {
        $html = $this->get(route('admin.operational-costs.contracts.create'))->assertOk()->getContent();

        preg_match_all('/<input[^>]*type="number"[^>]*>/', $html, $numberFields);
        $this->assertNotEmpty($numberFields[0]);
        foreach ($numberFields[0] as $field) {
            $this->assertDoesNotMatchRegularExpression('/value="[^"]+"/', $field, 'A number field starts empty: ' . $field);
        }
        $this->assertStringContainsString('name="expected_components[]"', $html);
        foreach (ContractService::PARTY_TYPES as $partyType) {
            $this->assertStringContainsString('value="' . $partyType . '"', $html);
        }
    }

    public function test_a_contract_is_created_as_a_draft_with_no_rules_and_its_history_starts(): void
    {
        $response = $this->post(route('admin.operational-costs.contracts.store'), $this->headerPayload());

        $contract = OperationalCostContract::firstOrFail();
        $response->assertRedirect($this->showUrl($contract));
        $this->assertSame(ContractStatus::Draft, $contract->status);
        $this->assertSame('SAR', $contract->currency, 'The currency is stored as an upper-case code.');
        $this->assertSame(0, $contract->seller_id);
        $this->assertSame(0, $contract->rules()->count());
        $this->assertSame(['shipping', 'picking'], $contract->settings['expected_components']);
        $this->assertSame('3', $contract->settings['tolerance_percent']);
        $this->assertSame(['created'], OperationalCostContractRevision::where('contract_id', $contract->id)->pluck('action')->all());
        $this->assertSame(1, OperationalCostContractRevision::first()->actor_id, 'The admin who typed it in is recorded.');
    }

    public function test_a_contract_can_belong_to_a_seller(): void
    {
        $this->post(route('admin.operational-costs.contracts.store'), $this->headerPayload(['seller_id' => '9']));

        $this->assertSame(9, OperationalCostContract::firstOrFail()->seller_id);
    }

    public function test_a_contract_with_a_bad_header_is_refused_and_nothing_is_written(): void
    {
        foreach ([
            ['party_type' => 'friend'],
            ['party_type' => ''],
            ['provider_code' => 'has spaces'],
            ['party_name' => ''],
            ['currency' => 'S'],
            ['currency' => 'SAR1'],
            ['effective_from' => 'tomorrow'],
            ['effective_from' => '2026-01-01', 'effective_to' => '2025-12-31'],
            ['expected_components' => ['cogs']],
            ['tolerance_percent' => '2,5'],
            ['seller_id' => '-1'],
        ] as $bad) {
            $this->from(route('admin.operational-costs.contracts.create'))->post(route('admin.operational-costs.contracts.store'), $this->headerPayload($bad))
                ->assertRedirect(route('admin.operational-costs.contracts.create'))
                ->assertSessionHasErrors();
        }

        $this->assertSame(0, OperationalCostContract::count());
    }

    public function test_the_contract_page_shows_the_status_validity_expected_services_and_the_rules_in_words(): void
    {
        $contracts = app(ContractService::class);
        $contract = $this->draftContract('torod_point');
        $contracts->addRule($contract, ['service_type' => 'shipping', 'calc_method' => 'per_shipment', 'params' => ['amount' => '12.5']], 1);
        $contracts->addRule($contract, ['service_type' => 'cod_collection', 'calc_method' => 'percent', 'params' => ['percent' => '2.9', 'min' => '3', 'max' => '20'], 'conditions' => ['payment_methods' => ['cash_on_delivery']]], 1);
        $contracts->addRule($contract, ['service_type' => 'extra_weight', 'calc_method' => 'by_weight', 'params' => ['steps' => [['up_to_kg' => '1', 'amount' => '8'], ['up_to_kg' => '5', 'amount' => '14']], 'extra_per_kg' => '2', 'extra_step_kg' => '1']], 1);
        $contracts->addRule($contract, ['service_type' => 'zone_surcharge', 'calc_method' => 'by_zone', 'params' => ['zones' => [['name' => 'Remote', 'cities' => ['Abha'], 'countries' => [], 'amount' => '9']]]], 1);

        $html = $this->get($this->showUrl($contract))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('contract_status_draft')), $html);
        $this->assertStringContainsString(e(translate('party_type_fulfillment_provider')), $html);
        $this->assertStringContainsString('2026-01-01', $html);
        $this->assertStringContainsString(e(translate('open_ended')), $html);
        $this->assertStringContainsString(e(translate('cost_component_shipping')), $html);
        $this->assertStringContainsString(e(translate('calc_method_per_shipment')), $html);
        $this->assertStringContainsString('12.50 SAR', $html);
        $this->assertStringContainsString('2.9%', $html);
        $this->assertStringContainsString('3.00 SAR', $html);
        $this->assertStringContainsString('20.00 SAR', $html);
        $this->assertStringContainsString(e(translate('condition_payment_methods')) . ':', $html);
        $this->assertStringContainsString('cash_on_delivery', $html);
        $this->assertStringContainsString(e(translate('rule_up_to_kg')) . ' 1', $html);
        $this->assertStringContainsString('14.00 SAR', $html);
        $this->assertStringContainsString(e(translate('rule_beyond_the_last_step_each_extra')), $html);
        $this->assertStringContainsString('Remote', $html);
        $this->assertStringContainsString('abha', $html, 'A city is shown as it is matched: normalised.');
        $this->assertStringContainsString(e(translate('contract_revision_created')), $html);
        $this->assertStringContainsString(e(translate('contract_revision_rule_added')), $html);
        $this->assertStringContainsString(route('admin.operational-costs.contracts.rules.create', $contract->id), $html);
    }

    public function test_a_draft_that_prices_nothing_says_so_and_offers_the_way_to_add_a_rule(): void
    {
        $draft = $this->draftContract();

        $html = $this->get($this->showUrl($draft))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('this_contract_prices_nothing_yet')), $html);
        $this->assertStringContainsString(route('admin.operational-costs.contracts.rules.create', $draft->id), $html);
        $this->assertStringContainsString(route('admin.operational-costs.contracts.activate', $draft->id), $html);
        $this->assertStringContainsString(route('admin.operational-costs.contracts.archive', $draft->id), $html);
        $this->assertStringNotContainsString(route('admin.operational-costs.contracts.new-version', $draft->id), $html);
        $this->assertMatchesRegularExpression('#<input[^>]*name="reason"[^>]*required#', $html, 'Activating and archiving ask for a reason.');
    }

    public function test_a_rule_priced_by_weight_that_states_no_extra_charge_says_what_happens_beyond_its_last_step(): void
    {
        $contract = $this->draftContract();
        app(ContractService::class)->addRule($contract, ['service_type' => 'shipping', 'calc_method' => 'by_weight', 'params' => ['steps' => [['up_to_kg' => '5', 'amount' => '14']]]], 1);

        $html = $this->get($this->showUrl($contract))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('rule_not_priced_so_the_cost_is_missing')), $html);
    }

    public function test_the_page_of_an_active_contract_offers_no_way_to_edit_it_only_a_new_version(): void
    {
        $contract = $this->activeContract();

        $html = $this->get($this->showUrl($contract))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('contract_status_active')), $html);
        $this->assertStringContainsString(e(translate('this_contract_is_not_a_draft_its_rules_never_change_note')), $html);
        $this->assertStringNotContainsString(route('admin.operational-costs.contracts.edit', $contract->id), $html);
        $this->assertStringNotContainsString(route('admin.operational-costs.contracts.rules.create', $contract->id), $html);
        $this->assertStringNotContainsString(route('admin.operational-costs.contracts.activate', $contract->id), $html);
        $this->assertStringContainsString(route('admin.operational-costs.contracts.new-version', $contract->id), $html);
        $this->assertStringContainsString(e(translate('contract_revision_activated')), $html);
    }

    public function test_the_version_chain_and_the_version_in_force_on_a_day_are_shown(): void
    {
        $contracts = app(ContractService::class);
        $first = $this->activeContract('torod_point', '12', 0, '2026-01-01');
        $second = $contracts->startNewVersion($first, '2026-07-01', 1, 'a price change');
        $contracts->activate($second, 1, 'the new prices');

        $page = $this->get($this->showUrl($first->fresh()))->assertOk()->getContent();
        $this->assertStringContainsString('>v1<', $page);
        $this->assertStringContainsString('>v2<', $page);
        $this->assertStringContainsString(e(translate('contract_status_superseded')), $page);
        $this->assertStringContainsString('2026-01-01 → 2026-06-30', $page, 'The first version ends the day before the second starts.');

        $inMarch = $this->get($this->showUrl($first) . '?on=2026-03-01')->getContent();
        $this->assertStringContainsString('2026-03-01: <a href="' . $this->showUrl($first) . '">v1</a>', $inMarch);
        $inAugust = $this->get($this->showUrl($first) . '?on=2026-08-01')->getContent();
        $this->assertStringContainsString('2026-08-01: <a href="' . $this->showUrl($second) . '">v2</a>', $inAugust);
        $before = $this->get($this->showUrl($first) . '?on=2025-12-31')->getContent();
        $this->assertStringContainsString(e(translate('no_version_of_this_contract_was_in_force_on_that_day')), $before);
        $this->get($this->showUrl($first) . '?on=not-a-date')->assertOk();
    }

    /** @return array<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function ruleMethods(): array
    {
        return [
            'fixed' => [['calc_method' => 'fixed', 'amount' => '12.5'], ['amount' => '12.500000']],
            'per unit' => [['calc_method' => 'per_unit', 'amount' => '0.35'], ['amount' => '0.350000']],
            'per shipment' => [['calc_method' => 'per_shipment', 'amount' => '9'], ['amount' => '9.000000']],
            'per order' => [['calc_method' => 'per_order', 'amount' => '4'], ['amount' => '4.000000']],
            'percent' => [['calc_method' => 'percent', 'percent' => '2.9', 'min' => '1', 'max' => '30'], ['percent' => '2.900000', 'min' => '1.000000', 'max' => '30.000000']],
            'percent plus fixed' => [['calc_method' => 'percent_plus_fixed', 'percent' => '2', 'amount' => '1.5'], ['percent' => '2.000000', 'amount' => '1.500000']],
            'per day' => [['calc_method' => 'per_day', 'amount' => '0.2', 'multiply_by' => 'units'], ['amount' => '0.200000', 'multiply_by' => 'units']],
            'by weight' => [
                ['calc_method' => 'by_weight', 'weight_steps' => [['limit' => '1', 'amount' => '10'], ['limit' => '5', 'amount' => '15'], ['limit' => '', 'amount' => '']], 'extra_per_kg' => '2', 'extra_step_kg' => '1'],
                ['steps' => [['up_to_kg' => '1.000000', 'amount' => '10.000000'], ['up_to_kg' => '5.000000', 'amount' => '15.000000']], 'extra_per_kg' => '2.000000', 'extra_step_kg' => '1.000000'],
            ],
            'by volume' => [
                ['calc_method' => 'by_volume', 'volume_steps' => [['limit' => '0.5', 'amount' => '7']]],
                ['steps' => [['up_to_m3' => '0.500000', 'amount' => '7.000000']]],
            ],
            'by zone' => [
                ['calc_method' => 'by_zone', 'zones' => [['name' => 'Central', 'cities' => 'Riyadh, Qassim', 'countries' => 'sa', 'amount' => '11'], ['name' => '', 'cities' => '', 'countries' => '', 'amount' => '']]],
                ['zones' => [['name' => 'Central', 'cities' => ['riyadh', 'qassim'], 'countries' => ['SA'], 'amount' => '11.000000']]],
            ],
            'tiered quantity' => [
                ['calc_method' => 'tiered_quantity', 'quantity_tiers' => [['from' => '1', 'to' => '5', 'amount' => '10', 'mode' => 'flat'], ['from' => '6', 'to' => '', 'amount' => '2', 'mode' => 'per_unit']]],
                ['tiers' => [['from' => '1.000000', 'to' => '5.000000', 'amount' => '10.000000', 'mode' => 'flat'], ['from' => '6.000000', 'to' => null, 'amount' => '2.000000', 'mode' => 'per_unit']]],
            ],
            'tiered value' => [
                ['calc_method' => 'tiered_value', 'value_tiers' => [['from' => '0', 'to' => '100', 'amount' => '3', 'percent' => ''], ['from' => '101', 'to' => '', 'amount' => '', 'percent' => '2.5']]],
                ['tiers' => [['from' => '0.000000', 'to' => '100.000000', 'amount' => '3.000000'], ['from' => '101.000000', 'to' => null, 'percent' => '2.500000']]],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $expectedParams
     */
    #[DataProvider('ruleMethods')]
    public function test_each_calculation_method_stores_exactly_the_figures_that_were_typed(array $fields, array $expectedParams): void
    {
        $contract = $this->draftContract();

        $this->addRule($contract, $fields + ['service_type' => 'shipping', 'label' => 'A rule', 'priority' => '20', 'is_active' => '1', 'valid_from' => '2026-02-01', 'valid_to' => '2026-12-31']);

        $rule = OperationalCostRule::firstOrFail();
        $this->assertSame($contract->id, $rule->contract_id);
        $this->assertEquals($expectedParams, $rule->params, 'The figures are compared string by string; only the order of keys may differ, because MySQL sorts the keys of a JSON column.');
        $this->assertSame('A rule', $rule->label);
        $this->assertSame(20, $rule->priority);
        $this->assertTrue($rule->is_active);
        $this->assertSame('2026-02-01', $rule->valid_from->toDateString());
        $this->assertSame(['rule_added'], OperationalCostContractRevision::where('contract_id', $contract->id)->where('action', 'rule_added')->pluck('action')->all());
        $this->assertContains(translate('rule_added'), $this->flashedToasts());
    }

    public function test_the_conditions_of_a_rule_are_stored_as_lists(): void
    {
        $contract = $this->draftContract();

        $this->addRule($contract, [
            'service_type' => 'shipping', 'calc_method' => 'fixed', 'amount' => '5',
            'conditions' => ['cities' => "Riyadh\nJeddah", 'payment_methods' => 'cash_on_delivery, card', 'sales_channels' => ['trendyol'], 'min_weight_kg' => '0.5', 'max_value' => '1000', 'countries' => '', 'service_levels' => ''],
        ]);

        $conditions = OperationalCostRule::firstOrFail()->conditions;
        $this->assertSame(['riyadh', 'jeddah'], array_map('strtolower', $conditions['cities']));
        $this->assertSame(['cash_on_delivery', 'card'], $conditions['payment_methods']);
        $this->assertSame(['trendyol'], $conditions['sales_channels']);
        $this->assertSame('0.5', $conditions['min_weight_kg'] ?? null);
        $this->assertArrayNotHasKey('countries', $conditions, 'A condition left empty is no condition.');
    }

    public function test_a_figure_that_is_not_a_plain_decimal_is_refused_and_no_rule_is_written(): void
    {
        $contract = $this->draftContract();
        $form = route('admin.operational-costs.contracts.rules.create', $contract->id);

        foreach (['abc', '1e3', '12,5', '-5', '5 SAR', '1.0000001', '٣'] as $bad) {
            $this->from($form)->post(route('admin.operational-costs.contracts.rules.store', $contract->id), ['service_type' => 'shipping', 'calc_method' => 'fixed', 'amount' => $bad])
                ->assertRedirect($form)
                ->assertSessionHasErrors('amount');
        }
        $this->from($form)->post(route('admin.operational-costs.contracts.rules.store', $contract->id), ['service_type' => 'shipping', 'calc_method' => 'by_weight', 'weight_steps' => [['limit' => '1', 'amount' => 'ten']]])
            ->assertSessionHasErrors('weight_steps.0.amount');
        $this->from($form)->post(route('admin.operational-costs.contracts.rules.store', $contract->id), ['service_type' => 'shipping', 'calc_method' => 'fixed'])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, OperationalCostRule::count());
    }

    public function test_a_service_a_contract_cannot_price_and_a_method_that_does_not_exist_are_refused(): void
    {
        $contract = $this->draftContract();

        $this->addRule($contract, ['service_type' => 'cogs', 'calc_method' => 'fixed', 'amount' => '5']);
        $this->addRule($contract, ['service_type' => 'shipping', 'calc_method' => 'made_up', 'amount' => '5']);

        $this->assertSame(0, OperationalCostRule::count());
    }

    public function test_when_the_figures_do_not_make_a_price_list_the_validators_words_are_shown(): void
    {
        $contract = $this->draftContract();

        $this->addRule($contract, ['service_type' => 'shipping', 'calc_method' => 'percent', 'percent' => '150']);
        $this->assertContains('A percentage cannot be more than 100.', $this->flashedToasts());

        $this->addRule($contract, ['service_type' => 'shipping', 'calc_method' => 'by_weight', 'weight_steps' => [['limit' => '5', 'amount' => '1'], ['limit' => '3', 'amount' => '2']]]);
        $this->assertContains('Steps must be in increasing order without repeating a limit.', $this->flashedToasts());

        $this->addRule($contract, ['service_type' => 'shipping', 'calc_method' => 'by_zone', 'zones' => [['name' => 'Nowhere', 'cities' => '', 'countries' => '', 'amount' => '3']]]);
        $this->assertContains('A zone needs at least one city or country.', $this->flashedToasts());

        $this->addRule($contract, ['service_type' => 'shipping', 'calc_method' => 'tiered_value', 'value_tiers' => [['from' => '0', 'to' => '10', 'amount' => '1', 'percent' => '2']]]);
        $this->assertContains('Each value tier has either an amount or a percentage.', $this->flashedToasts());

        $this->assertSame(0, OperationalCostRule::count());
    }

    public function test_an_active_contract_takes_no_rule_and_none_of_its_rules_can_be_changed_or_removed(): void
    {
        $contract = $this->activeContract('torod_point', '12');
        $rule = $contract->rules()->firstOrFail();

        $this->addRule($contract, ['service_type' => 'picking', 'calc_method' => 'fixed', 'amount' => '1']);
        $this->assertContains('This contract is in force and cannot be edited: start a new version instead.', $this->flashedToasts());
        $this->from($this->showUrl($contract))->post(route('admin.operational-costs.contracts.rules.update', [$contract->id, $rule->id]), ['service_type' => 'shipping', 'calc_method' => 'fixed', 'amount' => '99']);
        $this->from($this->showUrl($contract))->post(route('admin.operational-costs.contracts.rules.destroy', [$contract->id, $rule->id]));

        $this->assertSame(1, $contract->rules()->count());
        $this->assertSame(['amount' => '12.000000'], $rule->fresh()->params);
        $this->get(route('admin.operational-costs.contracts.rules.create', $contract->id))->assertRedirect($this->showUrl($contract));
        $this->get(route('admin.operational-costs.contracts.rules.edit', [$contract->id, $rule->id]))->assertRedirect($this->showUrl($contract));
        $this->get(route('admin.operational-costs.contracts.edit', $contract->id))->assertRedirect($this->showUrl($contract));
        $this->assertContains(translate('contract_not_editable_start_a_new_version'), $this->flashedToasts());
    }

    public function test_the_rule_form_tells_the_administrator_that_no_figure_is_filled_in_and_names_the_contract_currency(): void
    {
        $contract = $this->draftContract();

        $html = $this->get(route('admin.operational-costs.contracts.rules.create', $contract->id))->assertOk()->getContent();

        $this->assertStringContainsString(e(CostLabels::filled(translate('no_figure_is_filled_in_for_you_note'), ['currency' => $contract->currency])), $html);
    }

    public function test_the_rule_form_starts_empty_and_an_edited_rule_starts_with_the_stored_figures(): void
    {
        $contract = $this->draftContract();
        $rule = app(ContractService::class)->addRule($contract, ['service_type' => 'shipping', 'calc_method' => 'percent', 'params' => ['percent' => '2.5', 'min' => '4']], 1);

        $new = $this->get(route('admin.operational-costs.contracts.rules.create', $contract->id))->assertOk()->getContent();
        preg_match_all('/<input[^>]*type="number"[^>]*>/', $new, $numberFields);
        foreach ($numberFields[0] as $field) {
            $this->assertDoesNotMatchRegularExpression('/value="[^"]+"/', $field, 'A new rule starts with no figure: ' . $field);
        }
        foreach (['fixed', 'percent', 'by_weight', 'by_volume', 'by_zone', 'tiered_quantity', 'tiered_value'] as $method) {
            $this->assertStringContainsString('<option value="' . $method . '"', $new);
        }

        $edit = $this->get(route('admin.operational-costs.contracts.rules.edit', [$contract->id, $rule->id]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="percent"[^>]*value="2\.5"/', $edit);
        $this->assertMatchesRegularExpression('/id="min"[^>]*value="4"/', $edit);
        $this->assertMatchesRegularExpression('/<option value="percent" selected>/', $edit);
    }

    public function test_a_rule_of_a_draft_can_be_changed_and_removed_and_each_step_is_in_the_history(): void
    {
        $contract = $this->draftContract();
        $rule = app(ContractService::class)->addRule($contract, ['service_type' => 'shipping', 'calc_method' => 'per_shipment', 'params' => ['amount' => '12']], 1);

        $this->from($this->showUrl($contract))->post(route('admin.operational-costs.contracts.rules.update', [$contract->id, $rule->id]), ['service_type' => 'shipping', 'calc_method' => 'per_shipment', 'amount' => '15.25', 'priority' => '5', 'is_active' => '1'])
            ->assertRedirect($this->showUrl($contract));
        $this->assertSame(['amount' => '15.250000'], $rule->fresh()->params);
        $this->assertSame(5, $rule->fresh()->priority);

        $this->from($this->showUrl($contract))->post(route('admin.operational-costs.contracts.rules.destroy', [$contract->id, $rule->id]))->assertRedirect($this->showUrl($contract));
        $this->assertSame(0, OperationalCostRule::count());
        $this->assertSame(['created', 'rule_added', 'rule_changed', 'rule_removed'], OperationalCostContractRevision::where('contract_id', $contract->id)->pluck('action')->all());
    }

    public function test_a_rule_of_another_contract_cannot_be_reached_through_this_one(): void
    {
        $mine = $this->draftContract('torod_point');
        $other = $this->draftContract('m5azn');
        $rule = app(ContractService::class)->addRule($other, ['service_type' => 'shipping', 'calc_method' => 'fixed', 'params' => ['amount' => '1']], 1);

        $this->get(route('admin.operational-costs.contracts.rules.edit', [$mine->id, $rule->id]))->assertNotFound();
        $this->post(route('admin.operational-costs.contracts.rules.destroy', [$mine->id, $rule->id]))->assertNotFound();
    }

    public function test_activating_needs_a_reason_and_at_least_one_rule_and_freezes_the_contract(): void
    {
        $contract = $this->draftContract();

        $this->from($this->showUrl($contract))->post(route('admin.operational-costs.contracts.activate', $contract->id), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->from($this->showUrl($contract))->post(route('admin.operational-costs.contracts.activate', $contract->id), ['reason' => 'ready']);
        $this->assertContains('A contract without a single active price rule cannot be activated.', $this->flashedToasts());
        $this->assertSame(ContractStatus::Draft, $contract->fresh()->status);

        app(ContractService::class)->addRule($contract, ['service_type' => 'shipping', 'calc_method' => 'fixed', 'params' => ['amount' => '1']], 1);
        $this->from($this->showUrl($contract))->post(route('admin.operational-costs.contracts.activate', $contract->id), ['reason' => 'signed on 1 Jan'])->assertRedirect($this->showUrl($contract));

        $this->assertSame(ContractStatus::Active, $contract->fresh()->status);
        $this->assertSame('signed on 1 Jan', OperationalCostContractRevision::where('action', 'activated')->firstOrFail()->reason);
        $this->assertContains(translate('contract_activated'), $this->flashedToasts());
    }

    public function test_overlapping_dates_of_the_same_company_refuse_the_activation(): void
    {
        $this->activeContract('torod_point', '12', 0, '2026-01-01');
        $second = $this->draftContract('torod_point', from: '2026-03-01');
        app(ContractService::class)->addRule($second, ['service_type' => 'shipping', 'calc_method' => 'fixed', 'params' => ['amount' => '1']], 1);

        $this->from($this->showUrl($second))->post(route('admin.operational-costs.contracts.activate', $second->id), ['reason' => 'second contract']);

        $this->assertSame(ContractStatus::Draft, $second->fresh()->status);
        $this->assertStringContainsString('overlap', implode(' ', $this->flashedToasts()));
    }

    public function test_a_new_version_needs_a_start_date_and_a_reason_and_starts_a_draft_copy_of_the_rules(): void
    {
        $contract = $this->activeContract('torod_point', '12', 0, '2026-01-01');
        $url = route('admin.operational-costs.contracts.new-version', $contract->id);

        $this->from($this->showUrl($contract))->post($url, ['effective_from' => '2026-07-01', 'reason' => ''])->assertSessionHasErrors('reason');
        $this->from($this->showUrl($contract))->post($url, ['effective_from' => 'soon', 'reason' => 'x'])->assertSessionHasErrors('effective_from');
        $this->from($this->showUrl($contract))->post($url, ['effective_from' => '2025-12-01', 'reason' => 'too early']);
        $this->assertContains('A new version must start after the version it replaces.', $this->flashedToasts());
        $this->assertSame(1, OperationalCostContract::count());

        $this->from($this->showUrl($contract))->post($url, ['effective_from' => '2026-07-01', 'reason' => 'prices change in July']);

        $draft = OperationalCostContract::where('version', 2)->firstOrFail();
        $this->assertSame(ContractStatus::Draft, $draft->status);
        $this->assertSame($contract->root_id, $draft->root_id);
        $this->assertSame(1, $draft->rules()->count(), 'The new version starts from a copy of the rules, for a person to edit.');
        $this->assertSame(ContractStatus::Active, $contract->fresh()->status, 'The version in force is untouched until the new one is activated.');
        $this->assertStringContainsString('prices change in July', OperationalCostContractRevision::where('contract_id', $draft->id)->firstOrFail()->reason);

        $this->from($this->showUrl($contract))->post($url, ['effective_from' => '2026-09-01', 'reason' => 'again']);
        $this->assertContains('This contract already has a draft version: finish that one first.', $this->flashedToasts());
        $this->assertSame(2, OperationalCostContract::count());
    }

    public function test_only_a_draft_can_be_archived_and_it_needs_a_reason(): void
    {
        $draft = $this->draftContract('m5azn');
        $active = $this->activeContract('torod_point');

        $this->from($this->showUrl($draft))->post(route('admin.operational-costs.contracts.archive', $draft->id), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertSame(ContractStatus::Draft, $draft->fresh()->status);

        $this->from($this->showUrl($active))->post(route('admin.operational-costs.contracts.archive', $active->id), ['reason' => 'no longer needed']);
        $this->assertContains('Only a draft can be archived. An active contract ends by a successor taking over, or by its end date.', $this->flashedToasts());
        $this->assertSame(ContractStatus::Active, $active->fresh()->status);

        $this->from($this->showUrl($draft))->post(route('admin.operational-costs.contracts.archive', $draft->id), ['reason' => 'entered by mistake'])->assertRedirect($this->showUrl($draft));
        $this->assertSame(ContractStatus::Archived, $draft->fresh()->status);
        $this->assertSame('entered by mistake', OperationalCostContractRevision::where('action', 'archived')->firstOrFail()->reason);
    }

    public function test_the_header_of_a_draft_can_be_edited_and_the_other_settings_are_kept(): void
    {
        $draft = $this->draftContract();
        $draft->forceFill(['settings' => ['expected_components' => ['shipping'], 'allocation_basis' => ['shipping' => 'weight']]])->save();

        $this->get(route('admin.operational-costs.contracts.edit', $draft->id))->assertOk()->assertSee('value="Test company torod_point"', false);
        $this->from($this->showUrl($draft))->post(route('admin.operational-costs.contracts.update', $draft->id), $this->headerPayload(['party_name' => 'Renamed warehouse', 'expected_components' => ['shipping', 'packing']]))
            ->assertRedirect($this->showUrl($draft));

        $draft = $draft->fresh();
        $this->assertSame('Renamed warehouse', $draft->party_name);
        $this->assertSame(['shipping', 'packing'], $draft->settings['expected_components']);
        $this->assertSame(['shipping' => 'weight'], $draft->settings['allocation_basis'], 'A setting the form does not hold is left as it was.');
        $this->assertContains('updated', OperationalCostContractRevision::where('contract_id', $draft->id)->pluck('action')->all());
    }

    public function test_the_header_of_an_active_contract_is_not_edited(): void
    {
        $contract = $this->activeContract();

        $this->from($this->showUrl($contract))->post(route('admin.operational-costs.contracts.update', $contract->id), $this->headerPayload(['party_name' => 'Renamed']));

        $this->assertSame('Test company torod_point', $contract->fresh()->party_name);
        $this->assertContains('This contract is in force and cannot be edited: start a new version instead.', $this->flashedToasts());
    }

    public function test_unknown_contracts_and_rules_are_not_found(): void
    {
        $this->get(route('admin.operational-costs.contracts.show', 999))->assertNotFound();
        $this->get('/admin/operational-costs/contracts/abc')->assertNotFound();
        $this->post(route('admin.operational-costs.contracts.activate', 999), ['reason' => 'x'])->assertNotFound();
    }
}
