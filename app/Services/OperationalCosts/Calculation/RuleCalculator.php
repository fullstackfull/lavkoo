<?php

namespace App\Services\OperationalCosts\Calculation;

use App\Enums\OperationalCosts\CalcMethod;
use App\Models\OperationalCostRule;
use App\Services\Fulfillment\Support\ArabicText;
use App\Services\OperationalCosts\Data\RuleResult;
use App\Services\OperationalCosts\Data\ServiceContext;
use App\Services\OperationalCosts\Exceptions\CostInputMissing;
use App\Services\OperationalCosts\Support\Money;

/**
 * Turns one contract rule and the facts of one service into an amount.
 *
 * Pure: it reads the rule and the context and touches nothing else. Three outcomes, never blended:
 *   - a RuleResult        the rule applies and the amount is computed;
 *   - null                the rule does not apply to this service (its conditions are not met);
 *   - CostInputMissing    the rule applies but the cost cannot be determined: a fact it needs is unknown (weight, city, order value) or
 *                         its price list does not reach the service. The caller records the cost as missing, with the reason. An unknown
 *                         is never treated as zero.
 */
final class RuleCalculator
{
    /** @throws CostInputMissing */
    public function calculate(OperationalCostRule $rule, ServiceContext $context): ?RuleResult
    {
        if (!$this->isInForce($rule, $context) || !$this->conditionsHold($rule->conditions ?? [], $context)) {
            return null;
        }

        $method = $rule->method() ?? throw new CostInputMissing('unknown_calc_method');
        $params = $rule->params;
        $currency = $rule->currency ?: $context->currency;

        [$amount, $inputs, $quantity] = match ($method) {
            CalcMethod::Fixed => [Money::of($params['amount']), ['basis' => 'fixed'], null],
            CalcMethod::PerShipment => $this->perCount($params, $context->shipments, 'shipments'),
            CalcMethod::PerOrder => $this->perCount($params, $context->orders, 'orders'),
            CalcMethod::PerUnit => $this->perCount($params, $context->units, 'units'),
            CalcMethod::Percent => $this->percent($params, $context, withFixed: false),
            CalcMethod::PercentPlusFixed => $this->percent($params, $context, withFixed: true),
            CalcMethod::PerDay => $this->perDay($params, $context),
            CalcMethod::ByWeight => $this->bySteps($params, $context->weightKg, 'up_to_kg', 'extra_per_kg', 'extra_step_kg', 'weight_unknown', 'weight_kg'),
            CalcMethod::ByVolume => $this->bySteps($params, $context->volumeM3, 'up_to_m3', 'extra_per_m3', 'extra_step_m3', 'volume_unknown', 'volume_m3'),
            CalcMethod::ByZone => $this->byZone($params, $context),
            CalcMethod::TieredQuantity => $this->tieredQuantity($params, $context),
            CalcMethod::TieredValue => $this->tieredValue($params, $context),
        };

        // The rule applies to this service but its price list does not reach it (a weight above the last step, a city in no zone):
        // the cost is unknown, which is not the same as "this rule does not apply".
        if ($amount === null) {
            throw new CostInputMissing('outside_price_list');
        }

        return new RuleResult(
            amount: $amount,
            ruleId: (int) $rule->id,
            currency: $currency,
            inputs: ['method' => $method->value, 'rule_id' => (int) $rule->id, 'contract_id' => (int) $rule->contract_id] + $inputs,
            quantity: $quantity,
            unitAmount: $quantity !== null && !$quantity->isZero() ? $amount->dividedBy($quantity) : null,
        );
    }

    private function isInForce(OperationalCostRule $rule, ServiceContext $context): bool
    {
        if (!$rule->is_active) {
            return false;
        }
        $day = $context->serviceDate->format('Y-m-d');

        return ($rule->valid_from === null || $rule->valid_from->format('Y-m-d') <= $day)
            && ($rule->valid_to === null || $rule->valid_to->format('Y-m-d') >= $day);
    }

    /**
     * @param array<string, mixed> $conditions
     * @throws CostInputMissing when a condition looks at a fact that is not known
     */
    private function conditionsHold(array $conditions, ServiceContext $context): bool
    {
        foreach ([
            'cities' => [$context->city === null || ArabicText::normalize($context->city) === '' ? null : ArabicText::normalize($context->city), 'city_unknown'],
            'countries' => [$context->country === null ? null : strtoupper(trim($context->country)), 'country_unknown'],
            'payment_methods' => [$context->paymentMethod, 'payment_method_unknown'],
            'sales_channels' => [$context->salesChannel, 'sales_channel_unknown'],
            'service_levels' => [$context->serviceLevel, 'service_level_unknown'],
        ] as $key => [$actual, $missingReason]) {
            $allowed = $this->normaliseList($key, (array) ($conditions[$key] ?? []));
            if ($allowed === []) {
                continue;
            }
            if ($actual === null || $actual === '') {
                throw new CostInputMissing($missingReason);
            }
            if (!in_array($actual, $allowed, true)) {
                return false;
            }
        }

        foreach (['min_weight_kg' => ['weightKg', 1], 'max_weight_kg' => ['weightKg', -1], 'min_value' => ['value', 1], 'max_value' => ['value', -1]] as $key => [$property, $direction]) {
            if (!isset($conditions[$key]) || $conditions[$key] === '') {
                continue;
            }
            $actual = $context->{$property} ?? throw new CostInputMissing($property === 'weightKg' ? 'weight_unknown' : 'value_unknown');
            if ($actual->compareTo($conditions[$key]) * $direction < 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<mixed> $values
     * @return list<string>
     */
    private function normaliseList(string $key, array $values): array
    {
        return array_values(array_filter(array_map(static fn ($value): string => match ($key) {
            'cities' => ArabicText::normalize((string) $value),
            'countries' => strtoupper(trim((string) $value)),
            default => trim((string) $value),
        }, $values), static fn (string $value): bool => $value !== ''));
    }

    /**
     * @param array<string, mixed> $params
     * @return array{0: Money, 1: array<string, mixed>, 2: ?Money}
     */
    private function perCount(array $params, int $count, string $label): array
    {
        $unit = Money::of($params['amount']);

        return [$unit->times($count), ['basis' => $label, 'count' => $count, 'unit_amount' => $unit->toStorage()], Money::of($count)];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{0: Money, 1: array<string, mixed>, 2: ?Money}
     */
    private function percent(array $params, ServiceContext $context, bool $withFixed): array
    {
        $value = $context->value ?? throw new CostInputMissing('value_unknown');
        $amount = $value->percent($params['percent']);
        $inputs = ['basis' => 'percent', 'percent' => (string) $params['percent'], 'base_value' => $value->toStorage()];
        if ($withFixed) {
            $amount = $amount->plus($params['amount']);
            $inputs['fixed'] = (string) $params['amount'];
        }
        $amount = $this->clamp($amount, $params, $inputs);

        return [$amount, $inputs, null];
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $inputs
     */
    private function clamp(Money $amount, array $params, array &$inputs): Money
    {
        if (isset($params['min']) && $amount->compareTo($params['min']) < 0) {
            $inputs['clamped'] = 'min';
            $amount = Money::of($params['min']);
        }
        if (isset($params['max']) && $amount->compareTo($params['max']) > 0) {
            $inputs['clamped'] = 'max';
            $amount = Money::of($params['max']);
        }

        return $amount;
    }

    /**
     * @param array<string, mixed> $params
     * @return array{0: Money, 1: array<string, mixed>, 2: ?Money}
     */
    private function perDay(array $params, ServiceContext $context): array
    {
        $factor = match ($params['multiply_by'] ?? 'none') {
            'units' => Money::of($context->units),
            'volume_m3' => $context->volumeM3 ?? throw new CostInputMissing('volume_unknown'),
            default => Money::of(1),
        };
        $days = Money::of(max(0, $context->days));
        $unit = Money::of($params['amount']);

        return [
            $unit->times($days)->times($factor),
            ['basis' => 'per_day', 'days' => $context->days, 'multiplied_by' => $params['multiply_by'] ?? 'none', 'factor' => $factor->toStorage(), 'unit_amount' => $unit->toStorage()],
            $days->times($factor),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{0: ?Money, 1: array<string, mixed>, 2: ?Money}
     */
    private function bySteps(array $params, ?Money $measure, string $limitKey, string $extraKey, string $extraStepKey, string $missingReason, string $label): array
    {
        $measure ?? throw new CostInputMissing($missingReason);
        $inputs = ['basis' => $label, $label => $measure->toStorage()];

        $last = null;
        foreach ($params['steps'] as $step) {
            $last = $step;
            if ($measure->compareTo($step[$limitKey]) <= 0) {
                return [Money::of($step['amount']), $inputs + ['step_limit' => $step[$limitKey]], null];
            }
        }

        // Beyond the last step: only a contract that states an extra charge prices it; otherwise the service is outside the price list.
        if ($last === null || !isset($params[$extraKey])) {
            return [null, $inputs + ['outside_price_list' => true], null];
        }
        $excess = $measure->minus($last[$limitKey]);
        $stepSize = Money::of($params[$extraStepKey] ?? 1);
        $extraSteps = $excess->dividedBy($stepSize, 0)->round(0);
        // Every started step is charged (a 4.2 kg parcel over a 4 kg limit with a 1 kg step pays for one more kilo).
        if ($extraSteps->times($stepSize)->compareTo($excess) < 0) {
            $extraSteps = $extraSteps->plus(1);
        }
        $amount = Money::of($last['amount'])->plus(Money::of($params[$extraKey])->times($extraSteps));

        return [$amount, $inputs + ['step_limit' => $last[$limitKey], 'extra_steps' => $extraSteps->toStorage(), 'extra_unit' => (string) $params[$extraKey]], null];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{0: ?Money, 1: array<string, mixed>, 2: ?Money}
     */
    private function byZone(array $params, ServiceContext $context): array
    {
        $city = $context->city === null ? null : ArabicText::normalize($context->city);
        $city = $city === '' ? null : $city;
        $country = $context->country === null ? null : strtoupper(trim($context->country));
        $country = $country === '' ? null : $country;
        if ($city === null && $country === null) {
            throw new CostInputMissing('destination_unknown');
        }

        foreach ($params['zones'] as $zone) {
            $zoneCities = array_map(static fn ($zoneCity): string => ArabicText::normalize((string) $zoneCity), $zone['cities']);
            $zoneCountries = array_map(static fn ($zoneCountry): string => strtoupper(trim((string) $zoneCountry)), $zone['countries']);
            // A zone listing cities is matched by city; a zone listing only countries is matched by country (any city of it).
            $matches = $zoneCities !== []
                ? ($city !== null && in_array($city, $zoneCities, true))
                : ($country !== null && in_array($country, $zoneCountries, true));
            if ($matches) {
                return [Money::of($zone['amount']), ['basis' => 'zone', 'zone' => $zone['name'], 'city' => $city, 'country' => $country], null];
            }
        }

        return [null, ['basis' => 'zone', 'outside_price_list' => true, 'city' => $city, 'country' => $country], null];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{0: ?Money, 1: array<string, mixed>, 2: ?Money}
     */
    private function tieredQuantity(array $params, ServiceContext $context): array
    {
        $units = $context->units;
        foreach ($params['tiers'] as $tier) {
            if ($units >= (int) $tier['from'] && ($tier['to'] === null || $units <= (int) $tier['to'])) {
                $amount = Money::of($tier['amount']);
                $total = ($tier['mode'] ?? 'flat') === 'per_unit' ? $amount->times($units) : $amount;

                return [$total, ['basis' => 'tiered_quantity', 'units' => $units, 'tier_from' => $tier['from'], 'tier_to' => $tier['to'], 'mode' => $tier['mode'] ?? 'flat'], Money::of($units)];
            }
        }

        return [null, ['basis' => 'tiered_quantity', 'units' => $units, 'outside_price_list' => true], null];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{0: ?Money, 1: array<string, mixed>, 2: ?Money}
     */
    private function tieredValue(array $params, ServiceContext $context): array
    {
        $value = $context->value ?? throw new CostInputMissing('value_unknown');
        foreach ($params['tiers'] as $tier) {
            if ($value->compareTo($tier['from']) >= 0 && ($tier['to'] === null || $value->compareTo($tier['to']) <= 0)) {
                $amount = isset($tier['percent']) ? $value->percent($tier['percent']) : Money::of($tier['amount']);

                return [$amount, ['basis' => 'tiered_value', 'value' => $value->toStorage(), 'tier_from' => $tier['from'], 'tier_to' => $tier['to']], null];
            }
        }

        return [null, ['basis' => 'tiered_value', 'value' => $value->toStorage(), 'outside_price_list' => true], null];
    }
}
