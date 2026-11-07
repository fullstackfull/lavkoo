<?php

namespace Tests\Unit\OperationalCosts;

use App\Enums\OperationalCosts\CalcMethod;
use App\Http\Controllers\Admin\OperationalCosts\Forms\ContractRuleForm;
use App\Models\OperationalCostRule;
use App\Services\OperationalCosts\Calculation\RuleValidator;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The form of a contract rule: what each calculation method takes, in both directions, and that an empty field stays empty. */
class ContractRuleFormTest extends TestCase
{
    /** @return array<string, mixed> */
    private function input(array $fields): array
    {
        return $fields + ['service_type' => 'shipping', 'calc_method' => 'fixed'];
    }

    public function test_a_simple_method_takes_its_amount_and_nothing_else(): void
    {
        $data = ContractRuleForm::serviceData($this->input(['calc_method' => 'per_unit', 'amount' => '0.35', 'percent' => '9', 'extra_per_kg' => '4']));

        $this->assertSame(['amount' => '0.35'], $data['params'], 'A figure of another method is not carried along.');
        $this->assertSame(100, $data['priority'], 'A priority left empty is the one the service uses.');
        $this->assertFalse($data['is_active'], 'A switch that was not posted is off.');
        $this->assertNull($data['label']);
        $this->assertSame([], $data['conditions']);
    }

    public function test_a_percentage_keeps_its_limits_only_when_they_were_typed(): void
    {
        $this->assertSame(['percent' => '2.9'], ContractRuleForm::serviceData($this->input(['calc_method' => 'percent', 'percent' => '2.9', 'min' => null, 'max' => '']))['params']);
        $this->assertSame(['percent' => '2.9', 'min' => '1', 'max' => '30'], ContractRuleForm::serviceData($this->input(['calc_method' => 'percent', 'percent' => '2.9', 'min' => '1', 'max' => '30']))['params']);
        $this->assertSame(['percent' => '2', 'amount' => '1.5'], ContractRuleForm::serviceData($this->input(['calc_method' => 'percent_plus_fixed', 'percent' => '2', 'amount' => '1.5']))['params']);
    }

    public function test_a_zero_that_was_typed_is_kept_because_it_is_a_statement_and_not_an_absence(): void
    {
        $this->assertSame(['amount' => '0'], ContractRuleForm::serviceData($this->input(['amount' => '0']))['params']);
    }

    public function test_a_daily_charge_says_what_it_is_multiplied_by_and_defaults_to_nothing(): void
    {
        $this->assertSame(['amount' => '0.2', 'multiply_by' => 'units'], ContractRuleForm::serviceData($this->input(['calc_method' => 'per_day', 'amount' => '0.2', 'multiply_by' => 'units']))['params']);
        $this->assertSame(['amount' => '0.2', 'multiply_by' => 'none'], ContractRuleForm::serviceData($this->input(['calc_method' => 'per_day', 'amount' => '0.2']))['params']);
    }

    public function test_steps_are_kept_in_the_order_they_were_typed_even_when_the_request_hands_them_back_out_of_order(): void
    {
        $data = ContractRuleForm::serviceData($this->input([
            'calc_method' => 'by_weight',
            'weight_steps' => [2 => ['limit' => '10', 'amount' => '20'], 0 => ['limit' => '1', 'amount' => '10'], 1 => ['limit' => '5', 'amount' => '15'], 3 => ['limit' => null, 'amount' => null]],
            'extra_per_kg' => '2', 'extra_step_kg' => '',
        ]));

        $this->assertSame([['up_to_kg' => '1', 'amount' => '10'], ['up_to_kg' => '5', 'amount' => '15'], ['up_to_kg' => '10', 'amount' => '20']], $data['params']['steps']);
        $this->assertSame('2', $data['params']['extra_per_kg']);
        $this->assertArrayNotHasKey('extra_step_kg', $data['params']);
    }

    public function test_a_volume_table_uses_its_own_limit_name_and_ignores_the_weight_table(): void
    {
        $data = ContractRuleForm::serviceData($this->input([
            'calc_method' => 'by_volume',
            'volume_steps' => [['limit' => '0.5', 'amount' => '7']],
            'weight_steps' => [['limit' => '1', 'amount' => '10']],
        ]));

        $this->assertSame(['steps' => [['up_to_m3' => '0.5', 'amount' => '7']]], $data['params']);
    }

    public function test_a_row_left_blank_is_not_a_row_but_a_row_half_filled_is_passed_on_for_the_service_to_refuse(): void
    {
        $data = ContractRuleForm::serviceData($this->input([
            'calc_method' => 'tiered_quantity',
            'quantity_tiers' => [['from' => '1', 'to' => '5', 'amount' => '10', 'mode' => 'flat'], ['from' => null, 'to' => null, 'amount' => null, 'mode' => 'flat'], ['from' => '6', 'to' => null, 'amount' => null, 'mode' => 'per_unit']],
        ]));

        $this->assertSame([
            ['from' => '1', 'to' => '5', 'amount' => '10', 'mode' => 'flat'],
            ['from' => '6', 'to' => null, 'amount' => null, 'mode' => 'per_unit'],
        ], $data['params']['tiers']);
    }

    public function test_a_value_tier_has_an_amount_or_a_percentage_as_it_was_typed(): void
    {
        $data = ContractRuleForm::serviceData($this->input([
            'calc_method' => 'tiered_value',
            'value_tiers' => [['from' => '0', 'to' => '100', 'amount' => '3', 'percent' => null], ['from' => '101', 'to' => null, 'amount' => null, 'percent' => '2.5']],
        ]));

        $this->assertSame([['from' => '0', 'to' => '100', 'amount' => '3', 'percent' => null], ['from' => '101', 'to' => null, 'amount' => null, 'percent' => '2.5']], $data['params']['tiers']);
    }

    public function test_a_zone_lists_its_cities_and_countries_however_they_were_separated(): void
    {
        $data = ContractRuleForm::serviceData($this->input([
            'calc_method' => 'by_zone',
            'zones' => [['name' => 'Central', 'cities' => "Riyadh, Qassim;Hail\nBuraydah،Unaizah", 'countries' => 'SA , ae', 'amount' => '11'], ['name' => null, 'cities' => null, 'countries' => null, 'amount' => null]],
        ]));

        $this->assertSame([['name' => 'Central', 'cities' => ['Riyadh', 'Qassim', 'Hail', 'Buraydah', 'Unaizah'], 'countries' => ['SA', 'ae'], 'amount' => '11']], $data['params']['zones']);
    }

    public function test_conditions_are_lists_and_figures_and_an_empty_one_is_no_condition(): void
    {
        $data = ContractRuleForm::serviceData($this->input(['conditions' => [
            'cities' => 'Riyadh, Jeddah, riyadh', 'countries' => '', 'payment_methods' => 'cash_on_delivery', 'service_levels' => null,
            'sales_channels' => ['trendyol', ''], 'min_weight_kg' => '0.5', 'max_weight_kg' => '', 'min_value' => '0', 'max_value' => null,
        ]]));

        $this->assertSame(['cities' => ['Riyadh', 'Jeddah', 'riyadh'], 'payment_methods' => ['cash_on_delivery'], 'sales_channels' => ['trendyol'], 'min_weight_kg' => '0.5', 'min_value' => '0'], $data['conditions']);
    }

    public function test_a_new_rule_form_starts_with_no_figure_in_it(): void
    {
        $values = ContractRuleForm::defaults(null);

        foreach (['amount', 'percent', 'min', 'max', 'extra_per_kg', 'extra_step_kg', 'extra_per_m3', 'extra_step_m3', 'service_type', 'calc_method', 'label', 'priority', 'valid_from', 'valid_to'] as $field) {
            $this->assertNull($values[$field], $field);
        }
        $this->assertTrue($values['is_active']);
        $this->assertSame('none', $values['multiply_by']);
        foreach (['weight_steps', 'volume_steps', 'zones', 'quantity_tiers', 'value_tiers'] as $table) {
            $this->assertCount(ContractRuleForm::BLANK_ROWS_NEW, $values[$table], $table);
            foreach ($values[$table] as $row) {
                $this->assertSame([], array_filter($row, static fn ($cell, $key): bool => $cell !== null && $key !== 'mode', ARRAY_FILTER_USE_BOTH), 'A blank row holds nothing.');
            }
        }
        $this->assertSame('', $values['conditions']['cities']);
        $this->assertSame([], $values['conditions']['sales_channels']);
        $this->assertNull($values['conditions']['min_weight_kg']);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function storedRules(): array
    {
        return [
            'fixed' => [['calc_method' => 'fixed', 'params' => ['amount' => '12.5']]],
            'per unit' => [['calc_method' => 'per_unit', 'params' => ['amount' => '0.35']]],
            'per shipment' => [['calc_method' => 'per_shipment', 'params' => ['amount' => '9']]],
            'per order' => [['calc_method' => 'per_order', 'params' => ['amount' => '4']]],
            'percent' => [['calc_method' => 'percent', 'params' => ['percent' => '2.9', 'min' => '1', 'max' => '30']]],
            'percent plus fixed' => [['calc_method' => 'percent_plus_fixed', 'params' => ['percent' => '2', 'amount' => '1.5', 'max' => '9']]],
            'per day' => [['calc_method' => 'per_day', 'params' => ['amount' => '0.2', 'multiply_by' => 'volume_m3']]],
            'by weight' => [['calc_method' => 'by_weight', 'params' => ['steps' => [['up_to_kg' => '1', 'amount' => '10'], ['up_to_kg' => '5', 'amount' => '15']], 'extra_per_kg' => '2', 'extra_step_kg' => '0.5']]],
            'by volume' => [['calc_method' => 'by_volume', 'params' => ['steps' => [['up_to_m3' => '0.5', 'amount' => '7']]]]],
            'by zone' => [['calc_method' => 'by_zone', 'params' => ['zones' => [['name' => 'Central', 'cities' => ['riyadh', 'qassim'], 'countries' => ['SA'], 'amount' => '11']]]]],
            'tiered quantity' => [['calc_method' => 'tiered_quantity', 'params' => ['tiers' => [['from' => '1', 'to' => '5', 'amount' => '10', 'mode' => 'flat'], ['from' => '6', 'to' => null, 'amount' => '2', 'mode' => 'per_unit']]]]],
            'tiered value' => [['calc_method' => 'tiered_value', 'params' => ['tiers' => [['from' => '0', 'to' => '100', 'amount' => '3'], ['from' => '101', 'to' => null, 'percent' => '2.5']]]]],
        ];
    }

    /** @param array<string, mixed> $stored */
    #[DataProvider('storedRules')]
    public function test_a_stored_rule_comes_back_from_its_form_as_the_same_rule(array $stored): void
    {
        $validator = new RuleValidator();
        $conditions = ['cities' => ['riyadh'], 'payment_methods' => ['cash_on_delivery'], 'sales_channels' => ['amazon'], 'min_weight_kg' => '0.5'];
        $params = $validator->validate('shipping', $stored['calc_method'], $stored['params'], $conditions);
        $rule = new OperationalCostRule(['service_type' => 'shipping', 'label' => 'A rule', 'calc_method' => $stored['calc_method'], 'params' => $params, 'conditions' => $conditions, 'priority' => 7, 'is_active' => true]);

        $values = ContractRuleForm::defaults($rule);
        $again = ContractRuleForm::serviceData($values);

        $this->assertSame($params, $validator->validate('shipping', $again['calc_method'], $again['params'], $again['conditions']), 'Opening a rule and saving it unchanged changes nothing.');
        $this->assertSame(['riyadh'], $again['conditions']['cities']);
        $this->assertSame(['amazon'], $again['conditions']['sales_channels']);
        $this->assertSame(7, $again['priority']);
        $this->assertSame('A rule', $again['label']);
        $this->assertSame('shipping', $again['service_type']);
    }

    public function test_the_stored_figures_are_shown_without_the_padding_of_storage_and_the_tables_get_blank_rows_after_them(): void
    {
        $rule = new OperationalCostRule(['service_type' => 'shipping', 'calc_method' => 'by_weight', 'params' => ['steps' => [['up_to_kg' => '1.000000', 'amount' => '10.500000']], 'extra_per_kg' => '2.000000'], 'priority' => 100, 'is_active' => true]);

        $values = ContractRuleForm::defaults($rule);

        $this->assertSame([['limit' => '1', 'amount' => '10.5'], ['limit' => null, 'amount' => null], ['limit' => null, 'amount' => null]], $values['weight_steps']);
        $this->assertSame('2', $values['extra_per_kg']);
        $this->assertSame(ContractRuleForm::BLANK_ROWS_EDIT, count($values['volume_steps']), 'A table the rule does not use is blank.');
    }

    /** @return array<string, array{array<string, mixed>, list<string>}> */
    public static function shapes(): array
    {
        return [
            'a valid fixed rule' => [['service_type' => 'shipping', 'calc_method' => 'fixed', 'amount' => '12.5'], []],
            'a fixed rule with no amount' => [['service_type' => 'shipping', 'calc_method' => 'fixed'], ['amount']],
            'a fixed rule with a decimal comma' => [['service_type' => 'shipping', 'calc_method' => 'fixed', 'amount' => '12,5'], ['amount']],
            'a percent rule with no percentage' => [['service_type' => 'shipping', 'calc_method' => 'percent'], ['percent']],
            'a percentage of four digits' => [['service_type' => 'shipping', 'calc_method' => 'percent', 'percent' => '1000'], ['percent']],
            'a service a contract can not price' => [['service_type' => 'cogs', 'calc_method' => 'fixed', 'amount' => '1'], ['service_type']],
            'no service' => [['calc_method' => 'fixed', 'amount' => '1'], ['service_type']],
            'a method that does not exist' => [['service_type' => 'shipping', 'calc_method' => 'made_up', 'amount' => '1'], ['calc_method']],
            'a text priority' => [['service_type' => 'shipping', 'calc_method' => 'fixed', 'amount' => '1', 'priority' => 'high'], ['priority']],
            'a priority out of range' => [['service_type' => 'shipping', 'calc_method' => 'fixed', 'amount' => '1', 'priority' => '70000'], ['priority']],
            'an end before the start' => [['service_type' => 'shipping', 'calc_method' => 'fixed', 'amount' => '1', 'valid_from' => '2026-05-01', 'valid_to' => '2026-04-01'], ['valid_to']],
            'an end with no start' => [['service_type' => 'shipping', 'calc_method' => 'fixed', 'amount' => '1', 'valid_to' => '2026-04-01'], []],
            'a bad step amount' => [['service_type' => 'shipping', 'calc_method' => 'by_weight', 'weight_steps' => [['limit' => '1', 'amount' => 'ten']]], ['weight_steps.0.amount']],
            'a bad tier percentage' => [['service_type' => 'shipping', 'calc_method' => 'tiered_value', 'value_tiers' => [['from' => '0', 'percent' => '1.12345']]], ['value_tiers.0.percent']],
            'a bad tier mode' => [['service_type' => 'shipping', 'calc_method' => 'tiered_quantity', 'quantity_tiers' => [['from' => '1', 'amount' => '1', 'mode' => 'odd']]], ['quantity_tiers.0.mode']],
            'an unknown channel condition' => [['service_type' => 'shipping', 'calc_method' => 'fixed', 'amount' => '1', 'conditions' => ['sales_channels' => ['myspace']]], ['conditions.sales_channels.0']],
            'a bad weight condition' => [['service_type' => 'shipping', 'calc_method' => 'fixed', 'amount' => '1', 'conditions' => ['min_weight_kg' => 'heavy']], ['conditions.min_weight_kg']],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $failing
     */
    #[DataProvider('shapes')]
    public function test_the_shape_of_every_field_is_checked_before_the_service_looks_at_the_figures(array $input, array $failing): void
    {
        $validator = Validator::make($input, ContractRuleForm::rules($input));

        $this->assertSame($failing, array_keys($validator->errors()->messages()));
    }

    public function test_every_calculation_method_is_covered_by_a_stored_example(): void
    {
        $covered = array_map(static fn (array $case): string => $case[0]['calc_method'], self::storedRules());

        $this->assertEqualsCanonicalizing(array_map(static fn (CalcMethod $method): string => $method->value, CalcMethod::cases()), array_values($covered));
    }
}
