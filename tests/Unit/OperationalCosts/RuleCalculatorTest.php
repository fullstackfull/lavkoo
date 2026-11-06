<?php

namespace Tests\Unit\OperationalCosts;

use App\Models\OperationalCostRule;
use App\Services\OperationalCosts\Calculation\RuleCalculator;
use App\Services\OperationalCosts\Data\ServiceContext;
use App\Services\OperationalCosts\Exceptions\CostInputMissing;
use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * The figures in these tests are arbitrary numbers chosen to exercise the arithmetic. They are not anyone's prices.
 */
class RuleCalculatorTest extends TestCase
{
    private RuleCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new RuleCalculator();
    }

    /** @param array<string, mixed> $params */
    private function rule(string $method, array $params, ?array $conditions = null, array $extra = []): OperationalCostRule
    {
        return (new OperationalCostRule())->forceFill($extra + [
            'id' => 7, 'contract_id' => 3, 'service_type' => 'shipping', 'calc_method' => $method, 'params' => $params,
            'conditions' => $conditions, 'currency' => 'SAR', 'priority' => 100, 'is_active' => true,
        ]);
    }

    private function context(array $overrides = []): ServiceContext
    {
        return new ServiceContext(...($overrides + [
            'serviceDate' => CarbonImmutable::parse('2026-11-10'),
            'currency' => 'SAR',
            'units' => 3,
            'value' => Money::of('200'),
            'weightKg' => Money::of('2.5'),
            'city' => 'Riyadh',
            'country' => 'SA',
        ]));
    }

    public function test_fixed_per_unit_per_shipment_and_per_order(): void
    {
        $this->assertSame('4.500000', $this->calculator->calculate($this->rule('fixed', ['amount' => '4.5']), $this->context())->amount->toStorage());
        $this->assertSame('6.000000', $this->calculator->calculate($this->rule('per_unit', ['amount' => '2']), $this->context())->amount->toStorage());
        $this->assertSame('5.000000', $this->calculator->calculate($this->rule('per_shipment', ['amount' => '5']), $this->context())->amount->toStorage());
        $this->assertSame('7.000000', $this->calculator->calculate($this->rule('per_order', ['amount' => '7']), $this->context())->amount->toStorage());
    }

    public function test_a_fixed_fee_per_shipment_is_not_multiplied_by_the_number_of_items(): void
    {
        $oneItem = $this->calculator->calculate($this->rule('per_shipment', ['amount' => '5']), $this->context(['units' => 1]));
        $tenItems = $this->calculator->calculate($this->rule('per_shipment', ['amount' => '5']), $this->context(['units' => 10]));

        $this->assertSame($oneItem->amount->toStorage(), $tenItems->amount->toStorage());
    }

    public function test_percentage_with_a_minimum_and_a_maximum(): void
    {
        $rule = $this->rule('percent', ['percent' => '2', 'min' => '5', 'max' => '10']);

        $this->assertSame('5.000000', $this->calculator->calculate($rule, $this->context(['value' => Money::of('100')]))->amount->toStorage());
        $this->assertSame('6.000000', $this->calculator->calculate($rule, $this->context(['value' => Money::of('300')]))->amount->toStorage());
        $this->assertSame('10.000000', $this->calculator->calculate($rule, $this->context(['value' => Money::of('900')]))->amount->toStorage());
    }

    public function test_percentage_plus_a_fixed_amount(): void
    {
        $result = $this->calculator->calculate($this->rule('percent_plus_fixed', ['percent' => '2.9', 'amount' => '1']), $this->context(['value' => Money::of('100')]));

        $this->assertSame('3.900000', $result->amount->toStorage());
    }

    public function test_a_percentage_of_an_unknown_value_is_missing_not_zero(): void
    {
        $this->expectException(CostInputMissing::class);
        $this->calculator->calculate($this->rule('percent', ['percent' => '2']), $this->context(['value' => null]));
    }

    public function test_per_day_with_and_without_a_multiplier(): void
    {
        $plain = $this->calculator->calculate($this->rule('per_day', ['amount' => '2', 'multiply_by' => 'none']), $this->context(['days' => 30]));
        $perUnit = $this->calculator->calculate($this->rule('per_day', ['amount' => '0.5', 'multiply_by' => 'units']), $this->context(['days' => 10, 'units' => 4]));

        $this->assertSame('60.000000', $plain->amount->toStorage());
        $this->assertSame('20.000000', $perUnit->amount->toStorage());
    }

    public function test_by_weight_picks_the_first_step_that_covers_the_parcel(): void
    {
        $rule = $this->rule('by_weight', ['steps' => [['up_to_kg' => '1', 'amount' => '10'], ['up_to_kg' => '5', 'amount' => '15']]]);

        $this->assertSame('10.000000', $this->calculator->calculate($rule, $this->context(['weightKg' => Money::of('0.8')]))->amount->toStorage());
        $this->assertSame('10.000000', $this->calculator->calculate($rule, $this->context(['weightKg' => Money::of('1')]))->amount->toStorage(), 'A parcel exactly at a limit belongs to that step.');
        $this->assertSame('15.000000', $this->calculator->calculate($rule, $this->context(['weightKg' => Money::of('2.5')]))->amount->toStorage());
    }

    public function test_by_weight_charges_every_started_extra_step_past_the_last_limit(): void
    {
        $rule = $this->rule('by_weight', ['steps' => [['up_to_kg' => '5', 'amount' => '15']], 'extra_per_kg' => '2', 'extra_step_kg' => '1']);

        $this->assertSame('19.000000', $this->calculator->calculate($rule, $this->context(['weightKg' => Money::of('7')]))->amount->toStorage());
        $this->assertSame('17.000000', $this->calculator->calculate($rule, $this->context(['weightKg' => Money::of('5.5')]))->amount->toStorage(), 'Half a kilo past the limit is one started step.');
        $this->assertSame('19.000000', $this->calculator->calculate($rule, $this->context(['weightKg' => Money::of('6.2')]))->amount->toStorage(), '1.2 kg past the limit is two started steps.');
    }

    public function test_by_weight_beyond_the_price_list_is_missing_when_no_extra_charge_exists(): void
    {
        $rule = $this->rule('by_weight', ['steps' => [['up_to_kg' => '5', 'amount' => '15']]]);

        try {
            $this->calculator->calculate($rule, $this->context(['weightKg' => Money::of('9')]));
            $this->fail('A parcel beyond the price list must not be priced.');
        } catch (CostInputMissing $missing) {
            $this->assertSame('outside_price_list', $missing->reason);
        }
    }

    public function test_by_weight_with_an_unknown_weight_is_missing(): void
    {
        $rule = $this->rule('by_weight', ['steps' => [['up_to_kg' => '5', 'amount' => '15']]]);

        try {
            $this->calculator->calculate($rule, $this->context(['weightKg' => null]));
            $this->fail('An unknown weight must not be priced as zero kilos.');
        } catch (CostInputMissing $missing) {
            $this->assertSame('weight_unknown', $missing->reason);
        }
    }

    public function test_by_zone_matches_the_city_and_says_when_none_does(): void
    {
        $rule = $this->rule('by_zone', ['zones' => [
            ['name' => 'Central', 'cities' => ['riyadh'], 'countries' => [], 'amount' => '12'],
            ['name' => 'Abroad', 'cities' => [], 'countries' => ['AE'], 'amount' => '40'],
        ]]);

        $this->assertSame('12.000000', $this->calculator->calculate($rule, $this->context(['city' => ' RIYADH ']))->amount->toStorage());
        $this->assertSame('40.000000', $this->calculator->calculate($rule, $this->context(['city' => null, 'country' => 'ae']))->amount->toStorage());

        $this->expectException(CostInputMissing::class);
        $this->calculator->calculate($rule, $this->context(['city' => 'Jeddah']));
    }

    public function test_by_zone_with_no_destination_is_missing(): void
    {
        $rule = $this->rule('by_zone', ['zones' => [['name' => 'Central', 'cities' => ['riyadh'], 'countries' => [], 'amount' => '12']]]);

        try {
            $this->calculator->calculate($rule, $this->context(['city' => null, 'country' => null]));
            $this->fail('Without a destination the zone cannot be known.');
        } catch (CostInputMissing $missing) {
            $this->assertSame('destination_unknown', $missing->reason);
        }
    }

    public function test_tiered_quantity_flat_and_per_unit(): void
    {
        $rule = $this->rule('tiered_quantity', ['tiers' => [
            ['from' => '1', 'to' => '5', 'amount' => '10', 'mode' => 'flat'],
            ['from' => '6', 'to' => null, 'amount' => '1.5', 'mode' => 'per_unit'],
        ]]);

        $this->assertSame('10.000000', $this->calculator->calculate($rule, $this->context(['units' => 4]))->amount->toStorage());
        $this->assertSame('15.000000', $this->calculator->calculate($rule, $this->context(['units' => 10]))->amount->toStorage());
    }

    public function test_tiered_value_by_amount_and_by_percentage(): void
    {
        $rule = $this->rule('tiered_value', ['tiers' => [
            ['from' => '0', 'to' => '100', 'amount' => '3'],
            ['from' => '100.01', 'to' => null, 'percent' => '2'],
        ]]);

        $this->assertSame('3.000000', $this->calculator->calculate($rule, $this->context(['value' => Money::of('50')]))->amount->toStorage());
        $this->assertSame('8.000000', $this->calculator->calculate($rule, $this->context(['value' => Money::of('400')]))->amount->toStorage());
    }

    public function test_a_rule_whose_conditions_are_not_met_does_not_apply(): void
    {
        $rule = $this->rule('fixed', ['amount' => '4'], ['payment_methods' => ['cod']]);

        $this->assertNull($this->calculator->calculate($rule, $this->context(['paymentMethod' => 'prepaid'])));
        $this->assertSame('4.000000', $this->calculator->calculate($rule, $this->context(['paymentMethod' => 'cod']))->amount->toStorage());
    }

    public function test_a_condition_on_an_unknown_fact_is_missing_not_skipped(): void
    {
        $rule = $this->rule('fixed', ['amount' => '4'], ['cities' => ['riyadh']]);

        $this->expectException(CostInputMissing::class);
        $this->calculator->calculate($rule, $this->context(['city' => null]));
    }

    public function test_weight_conditions(): void
    {
        $rule = $this->rule('fixed', ['amount' => '9'], ['min_weight_kg' => '10']);

        $this->assertNull($this->calculator->calculate($rule, $this->context(['weightKg' => Money::of('2')])));
        $this->assertSame('9.000000', $this->calculator->calculate($rule, $this->context(['weightKg' => Money::of('12')]))->amount->toStorage());
    }

    public function test_a_rule_outside_its_validity_dates_or_inactive_does_not_apply(): void
    {
        $expired = $this->rule('fixed', ['amount' => '4'], null, ['valid_to' => '2026-01-01']);
        $inactive = $this->rule('fixed', ['amount' => '4'], null, ['is_active' => false]);
        $notYet = $this->rule('fixed', ['amount' => '4'], null, ['valid_from' => '2027-01-01']);

        $this->assertNull($this->calculator->calculate($expired, $this->context()));
        $this->assertNull($this->calculator->calculate($inactive, $this->context()));
        $this->assertNull($this->calculator->calculate($notYet, $this->context()));
    }

    public function test_the_result_records_what_it_was_computed_from(): void
    {
        $result = $this->calculator->calculate($this->rule('percent', ['percent' => '2']), $this->context(['value' => Money::of('250')]));

        $this->assertSame(7, $result->ruleId);
        $this->assertSame('percent', $result->inputs['method']);
        $this->assertSame('250.000000', $result->inputs['base_value']);
        $this->assertSame(3, $result->inputs['contract_id']);
    }
}
