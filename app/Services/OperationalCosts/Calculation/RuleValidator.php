<?php

namespace App\Services\OperationalCosts\Calculation;

use App\Enums\OperationalCosts\CalcMethod;
use App\Enums\OperationalCosts\CostComponent;
use App\Services\OperationalCosts\Exceptions\InvalidContractChange;
use App\Services\OperationalCosts\Support\Money;

/**
 * Checks a rule's figures when they are typed in, so that the calculator never meets a malformed one. It checks shape and sanity
 * (numbers are numbers, tiers do not overlap, a percentage is a percentage); it never supplies a missing figure.
 */
final class RuleValidator
{
    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed>|null $conditions
     * @return array<string, mixed> the params as they will be stored (numbers normalised to decimal strings)
     * @throws InvalidContractChange
     */
    public function validate(string $serviceType, string $calcMethod, array $params, ?array $conditions = null): array
    {
        if (CostComponent::tryFrom($serviceType)?->isContractPriced() !== true) {
            throw new InvalidContractChange("'{$serviceType}' is not a service a contract can price.");
        }
        $method = CalcMethod::tryFrom($calcMethod) ?? throw new InvalidContractChange("Unknown calculation method '{$calcMethod}'.");

        $clean = match ($method) {
            CalcMethod::Fixed, CalcMethod::PerUnit, CalcMethod::PerShipment, CalcMethod::PerOrder => $this->only($params, ['amount'], ['amount']),
            CalcMethod::Percent => $this->percent($params, withFixed: false),
            CalcMethod::PercentPlusFixed => $this->percent($params, withFixed: true),
            CalcMethod::PerDay => $this->perDay($params),
            CalcMethod::ByWeight => $this->steps($params, 'up_to_kg', 'extra_per_kg', 'extra_step_kg'),
            CalcMethod::ByVolume => $this->steps($params, 'up_to_m3', 'extra_per_m3', 'extra_step_m3'),
            CalcMethod::ByZone => $this->zones($params),
            CalcMethod::TieredQuantity => $this->tiers($params, valueTiers: false),
            CalcMethod::TieredValue => $this->tiers($params, valueTiers: true),
        };

        if ($conditions !== null) {
            $this->conditions($conditions);
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string> $allowed
     * @param list<string> $required
     * @return array<string, mixed>
     */
    private function only(array $params, array $allowed, array $required): array
    {
        foreach ($required as $key) {
            if (!array_key_exists($key, $params) || $params[$key] === '' || $params[$key] === null) {
                throw new InvalidContractChange("The figure '{$key}' is required.");
            }
        }
        $clean = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $params) && $params[$key] !== '' && $params[$key] !== null) {
                $clean[$key] = $this->nonNegative($params[$key], $key);
            }
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function percent(array $params, bool $withFixed): array
    {
        $clean = $this->only($params, $withFixed ? ['percent', 'amount', 'min', 'max'] : ['percent', 'min', 'max'], $withFixed ? ['percent', 'amount'] : ['percent']);
        if (Money::of($clean['percent'])->compareTo(100) > 0) {
            throw new InvalidContractChange('A percentage cannot be more than 100.');
        }
        if (isset($clean['min'], $clean['max']) && Money::of($clean['min'])->compareTo($clean['max']) > 0) {
            throw new InvalidContractChange('The minimum charge is above the maximum charge.');
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function perDay(array $params): array
    {
        $clean = $this->only($params, ['amount'], ['amount']);
        $multiplier = $params['multiply_by'] ?? 'none';
        if (!in_array($multiplier, ['none', 'units', 'volume_m3'], true)) {
            throw new InvalidContractChange("A daily charge is multiplied by 'none', 'units' or 'volume_m3'.");
        }
        $clean['multiply_by'] = $multiplier;

        return $clean;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function steps(array $params, string $limitKey, string $extraKey, string $extraStepKey): array
    {
        $steps = $params['steps'] ?? null;
        if (!is_array($steps) || $steps === []) {
            throw new InvalidContractChange('At least one step is required.');
        }
        $clean = [];
        $previous = null;
        foreach (array_values($steps) as $step) {
            $limit = $this->positive($step[$limitKey] ?? null, $limitKey);
            if ($previous !== null && Money::of($limit)->compareTo($previous) <= 0) {
                throw new InvalidContractChange('Steps must be in increasing order without repeating a limit.');
            }
            $previous = $limit;
            $clean[] = [$limitKey => $limit, 'amount' => $this->nonNegative($step['amount'] ?? null, 'amount')];
        }
        $result = ['steps' => $clean];
        if (isset($params[$extraKey]) && $params[$extraKey] !== '') {
            $result[$extraKey] = $this->nonNegative($params[$extraKey], $extraKey);
            $result[$extraStepKey] = isset($params[$extraStepKey]) && $params[$extraStepKey] !== '' ? $this->positive($params[$extraStepKey], $extraStepKey) : '1.000000';
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function zones(array $params): array
    {
        $zones = $params['zones'] ?? null;
        if (!is_array($zones) || $zones === []) {
            throw new InvalidContractChange('At least one zone is required.');
        }
        $clean = [];
        foreach (array_values($zones) as $zone) {
            $cities = array_values(array_filter(array_map(static fn ($city): string => mb_strtolower(trim((string) $city)), (array) ($zone['cities'] ?? [])), static fn (string $city): bool => $city !== ''));
            $countries = array_values(array_filter(array_map(static fn ($country): string => strtoupper(trim((string) $country)), (array) ($zone['countries'] ?? [])), static fn (string $country): bool => $country !== ''));
            if ($cities === [] && $countries === []) {
                throw new InvalidContractChange('A zone needs at least one city or country.');
            }
            $clean[] = [
                'name' => trim((string) ($zone['name'] ?? '')),
                'cities' => $cities,
                'countries' => $countries,
                'amount' => $this->nonNegative($zone['amount'] ?? null, 'amount'),
            ];
        }

        return ['zones' => $clean];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function tiers(array $params, bool $valueTiers): array
    {
        $tiers = $params['tiers'] ?? null;
        if (!is_array($tiers) || $tiers === []) {
            throw new InvalidContractChange('At least one tier is required.');
        }
        $clean = [];
        $previousEnd = null;
        foreach (array_values($tiers) as $tier) {
            $from = $this->nonNegative($tier['from'] ?? null, 'from');
            $to = isset($tier['to']) && $tier['to'] !== '' ? $this->nonNegative($tier['to'], 'to') : null;
            if ($to !== null && Money::of($to)->compareTo($from) < 0) {
                throw new InvalidContractChange('A tier ends before it starts.');
            }
            if ($previousEnd !== null && Money::of($from)->compareTo($previousEnd) <= 0) {
                throw new InvalidContractChange('Tiers must not overlap and must be in increasing order.');
            }
            $previousEnd = $to ?? '999999999999';
            $entry = ['from' => $from, 'to' => $to];
            if ($valueTiers) {
                $hasAmount = isset($tier['amount']) && $tier['amount'] !== '';
                $hasPercent = isset($tier['percent']) && $tier['percent'] !== '';
                if ($hasAmount === $hasPercent) {
                    throw new InvalidContractChange('Each value tier has either an amount or a percentage.');
                }
                $entry += $hasAmount ? ['amount' => $this->nonNegative($tier['amount'], 'amount')] : ['percent' => $this->nonNegative($tier['percent'], 'percent')];
            } else {
                $mode = $tier['mode'] ?? 'flat';
                if (!in_array($mode, ['flat', 'per_unit'], true)) {
                    throw new InvalidContractChange("A quantity tier is 'flat' or 'per_unit'.");
                }
                $entry += ['amount' => $this->nonNegative($tier['amount'] ?? null, 'amount'), 'mode' => $mode];
            }
            $clean[] = $entry;
        }

        return ['tiers' => $clean];
    }

    /** @param array<string, mixed> $conditions */
    private function conditions(array $conditions): void
    {
        $known = ['cities', 'countries', 'payment_methods', 'sales_channels', 'service_levels', 'min_weight_kg', 'max_weight_kg', 'min_value', 'max_value'];
        foreach (array_keys($conditions) as $key) {
            if (!in_array($key, $known, true)) {
                throw new InvalidContractChange("Unknown condition '{$key}'.");
            }
        }
        foreach (['min_weight_kg', 'max_weight_kg', 'min_value', 'max_value'] as $key) {
            if (isset($conditions[$key]) && $conditions[$key] !== '') {
                $this->nonNegative($conditions[$key], $key);
            }
        }
    }

    private function nonNegative(mixed $value, string $label): string
    {
        $money = $this->number($value, $label);
        if ($money->isNegative()) {
            throw new InvalidContractChange("'{$label}' cannot be negative.");
        }

        return $money->toStorage();
    }

    private function positive(mixed $value, string $label): string
    {
        $money = $this->number($value, $label);
        if (!$money->isPositive()) {
            throw new InvalidContractChange("'{$label}' must be more than zero.");
        }

        return $money->toStorage();
    }

    private function number(mixed $value, string $label): Money
    {
        if ($value === null || $value === '' || !is_scalar($value) || is_bool($value)) {
            throw new InvalidContractChange("'{$label}' must be a number.");
        }
        try {
            return Money::of(is_string($value) ? $value : (string) $value);
        } catch (\InvalidArgumentException) {
            throw new InvalidContractChange("'{$label}' must be a number.");
        }
    }
}
