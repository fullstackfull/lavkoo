<?php

namespace App\Http\Controllers\Admin\OperationalCosts\Forms;

use App\Enums\OperationalCosts\CalcMethod;
use App\Enums\OperationalCosts\CostComponent;
use App\Enums\SalesChannels\SalesChannel;
use App\Http\Controllers\Admin\OperationalCosts\CostFormat;
use App\Http\Controllers\Admin\OperationalCosts\DecimalRules;
use App\Models\OperationalCostRule;
use Illuminate\Validation\Rule;

/**
 * The form of one contract rule and what it turns into.
 *
 * It is the one place that knows which figures each calculation method takes, in both directions: the fields a person typed become the `params` and `conditions`
 * ContractService stores (RuleValidator then judges them), and a stored rule becomes the values its form starts with. Nothing here supplies a figure: an empty
 * field stays empty, and the first thing a new rule form shows is a form with no number in it.
 */
final class ContractRuleForm
{
    /** Rows of a table of steps, zones or tiers that a new rule starts with, and that an edited rule gets after its own. */
    public const BLANK_ROWS_NEW = 4;

    public const BLANK_ROWS_EDIT = 2;

    private const LIST_CONDITIONS = ['cities', 'countries', 'payment_methods', 'service_levels'];

    private const FIGURE_CONDITIONS = ['min_weight_kg', 'max_weight_kg', 'min_value', 'max_value'];

    private const AMOUNT_REQUIRED = [CalcMethod::Fixed, CalcMethod::PerUnit, CalcMethod::PerShipment, CalcMethod::PerOrder, CalcMethod::PerDay, CalcMethod::PercentPlusFixed];

    private const PERCENT_REQUIRED = [CalcMethod::Percent, CalcMethod::PercentPlusFixed];

    /**
     * What a request must satisfy before ContractService looks at the figures: the shape of every field, and the single figure each simple method cannot do without.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function rules(array $input): array
    {
        $method = CalcMethod::tryFrom((string) ($input['calc_method'] ?? ''));
        $figure = DecimalRules::amount();

        return [
            'service_type' => ['required', Rule::in(array_map(static fn (CostComponent $component): string => $component->value, CostComponent::contractPriced()))],
            'calc_method' => ['required', Rule::enum(CalcMethod::class)],
            'label' => ['nullable', 'string', 'max:160'],
            'priority' => ['nullable', 'integer', 'between:0,65535'],
            'is_active' => ['nullable', 'boolean'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', ...(filled($input['valid_from'] ?? null) ? ['after_or_equal:valid_from'] : [])],

            'conditions' => ['nullable', 'array'],
            'conditions.cities' => ['nullable', 'string', 'max:2000'],
            'conditions.countries' => ['nullable', 'string', 'max:500'],
            'conditions.payment_methods' => ['nullable', 'string', 'max:500'],
            'conditions.service_levels' => ['nullable', 'string', 'max:500'],
            'conditions.sales_channels' => ['nullable', 'array'],
            'conditions.sales_channels.*' => ['string', Rule::in(array_map(static fn (SalesChannel $channel): string => $channel->value, SalesChannel::cases()))],
            'conditions.min_weight_kg' => $figure,
            'conditions.max_weight_kg' => $figure,
            'conditions.min_value' => $figure,
            'conditions.max_value' => $figure,

            'amount' => DecimalRules::amount(required: in_array($method, self::AMOUNT_REQUIRED, true)),
            'percent' => DecimalRules::percent(required: in_array($method, self::PERCENT_REQUIRED, true)),
            'min' => $figure,
            'max' => $figure,
            'multiply_by' => ['nullable', Rule::in(['none', 'units', 'volume_m3'])],
            'extra_per_kg' => $figure,
            'extra_step_kg' => $figure,
            'extra_per_m3' => $figure,
            'extra_step_m3' => $figure,

            'weight_steps' => ['nullable', 'array', 'max:50'],
            'weight_steps.*.limit' => $figure,
            'weight_steps.*.amount' => $figure,
            'volume_steps' => ['nullable', 'array', 'max:50'],
            'volume_steps.*.limit' => $figure,
            'volume_steps.*.amount' => $figure,
            'zones' => ['nullable', 'array', 'max:100'],
            'zones.*.name' => ['nullable', 'string', 'max:120'],
            'zones.*.cities' => ['nullable', 'string', 'max:2000'],
            'zones.*.countries' => ['nullable', 'string', 'max:500'],
            'zones.*.amount' => $figure,
            'quantity_tiers' => ['nullable', 'array', 'max:50'],
            'quantity_tiers.*.from' => $figure,
            'quantity_tiers.*.to' => $figure,
            'quantity_tiers.*.amount' => $figure,
            'quantity_tiers.*.mode' => ['nullable', Rule::in(['flat', 'per_unit'])],
            'value_tiers' => ['nullable', 'array', 'max:50'],
            'value_tiers.*.from' => $figure,
            'value_tiers.*.to' => $figure,
            'value_tiers.*.amount' => $figure,
            'value_tiers.*.percent' => DecimalRules::percent(),
        ];
    }

    /**
     * What ContractService::addRule() and updateRule() take, from a validated request.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function serviceData(array $input): array
    {
        $method = CalcMethod::from((string) $input['calc_method']);

        return [
            'service_type' => (string) $input['service_type'],
            'calc_method' => $method->value,
            'label' => $input['label'] ?? null,
            'priority' => (int) ($input['priority'] ?? 100),
            'is_active' => (bool) ($input['is_active'] ?? false),
            'valid_from' => $input['valid_from'] ?? null,
            'valid_to' => $input['valid_to'] ?? null,
            'params' => self::params($method, $input),
            'conditions' => self::conditions((array) ($input['conditions'] ?? [])),
        ];
    }

    /**
     * The values a rule form starts with: the stored rule's, or none at all for a new one. Repeating tables get blank rows after the stored ones.
     *
     * @return array<string, mixed>
     */
    public static function defaults(?OperationalCostRule $rule): array
    {
        $params = $rule?->params ?? [];
        $method = $rule?->method();
        $blank = $rule === null ? self::BLANK_ROWS_NEW : self::BLANK_ROWS_EDIT;
        $steps = array_map(static fn (array $step): array => ['limit' => self::figure($step['up_to_kg'] ?? $step['up_to_m3'] ?? null), 'amount' => self::figure($step['amount'] ?? null)], (array) ($params['steps'] ?? []));

        return [
            'service_type' => $rule?->service_type,
            'calc_method' => $rule?->calc_method,
            'label' => $rule?->label,
            'priority' => $rule?->priority,
            'is_active' => $rule === null ? true : (bool) $rule->is_active,
            'valid_from' => $rule?->valid_from?->toDateString(),
            'valid_to' => $rule?->valid_to?->toDateString(),
            'conditions' => self::conditionDefaults($rule?->conditions ?? []),

            'amount' => self::figure($params['amount'] ?? null),
            'percent' => self::figure($params['percent'] ?? null),
            'min' => self::figure($params['min'] ?? null),
            'max' => self::figure($params['max'] ?? null),
            'multiply_by' => $params['multiply_by'] ?? 'none',
            'extra_per_kg' => self::figure($params['extra_per_kg'] ?? null),
            'extra_step_kg' => self::figure($params['extra_step_kg'] ?? null),
            'extra_per_m3' => self::figure($params['extra_per_m3'] ?? null),
            'extra_step_m3' => self::figure($params['extra_step_m3'] ?? null),

            'weight_steps' => self::padded($method === CalcMethod::ByWeight ? $steps : [], ['limit' => null, 'amount' => null], $blank),
            'volume_steps' => self::padded($method === CalcMethod::ByVolume ? $steps : [], ['limit' => null, 'amount' => null], $blank),
            'zones' => self::padded($method === CalcMethod::ByZone ? array_map(static fn (array $zone): array => [
                'name' => $zone['name'] ?? null,
                'cities' => implode(', ', (array) ($zone['cities'] ?? [])),
                'countries' => implode(', ', (array) ($zone['countries'] ?? [])),
                'amount' => self::figure($zone['amount'] ?? null),
            ], (array) ($params['zones'] ?? [])) : [], ['name' => null, 'cities' => null, 'countries' => null, 'amount' => null], $blank),
            'quantity_tiers' => self::padded($method === CalcMethod::TieredQuantity ? array_map(static fn (array $tier): array => [
                'from' => self::figure($tier['from'] ?? null),
                'to' => self::figure($tier['to'] ?? null),
                'amount' => self::figure($tier['amount'] ?? null),
                'mode' => $tier['mode'] ?? 'flat',
            ], (array) ($params['tiers'] ?? [])) : [], ['from' => null, 'to' => null, 'amount' => null, 'mode' => 'flat'], $blank),
            'value_tiers' => self::padded($method === CalcMethod::TieredValue ? array_map(static fn (array $tier): array => [
                'from' => self::figure($tier['from'] ?? null),
                'to' => self::figure($tier['to'] ?? null),
                'amount' => self::figure($tier['amount'] ?? null),
                'percent' => self::figure($tier['percent'] ?? null),
            ], (array) ($params['tiers'] ?? [])) : [], ['from' => null, 'to' => null, 'amount' => null, 'percent' => null], $blank),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function params(CalcMethod $method, array $input): array
    {
        return match ($method) {
            CalcMethod::Fixed, CalcMethod::PerUnit, CalcMethod::PerShipment, CalcMethod::PerOrder => self::filled($input, ['amount']),
            CalcMethod::Percent => self::filled($input, ['percent', 'min', 'max']),
            CalcMethod::PercentPlusFixed => self::filled($input, ['percent', 'amount', 'min', 'max']),
            CalcMethod::PerDay => self::filled($input, ['amount']) + ['multiply_by' => $input['multiply_by'] ?? 'none'],
            CalcMethod::ByWeight => ['steps' => self::steps((array) ($input['weight_steps'] ?? []), 'up_to_kg')] + self::filled($input, ['extra_per_kg', 'extra_step_kg']),
            CalcMethod::ByVolume => ['steps' => self::steps((array) ($input['volume_steps'] ?? []), 'up_to_m3')] + self::filled($input, ['extra_per_m3', 'extra_step_m3']),
            CalcMethod::ByZone => ['zones' => self::zones((array) ($input['zones'] ?? []))],
            CalcMethod::TieredQuantity => ['tiers' => self::rows((array) ($input['quantity_tiers'] ?? []), ['from', 'to', 'amount', 'mode'])],
            CalcMethod::TieredValue => ['tiers' => self::rows((array) ($input['value_tiers'] ?? []), ['from', 'to', 'amount', 'percent'])],
        };
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private static function filled(array $input, array $keys): array
    {
        return array_filter(array_intersect_key($input, array_flip($keys)), static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function steps(array $rows, string $limitKey): array
    {
        return array_map(
            static fn (array $row): array => [$limitKey => $row['limit'] ?? null, 'amount' => $row['amount'] ?? null],
            self::rows($rows, ['limit', 'amount']),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function zones(array $rows): array
    {
        return array_map(static fn (array $zone): array => [
            'name' => $zone['name'] ?? '',
            'cities' => self::list($zone['cities'] ?? null),
            'countries' => self::list($zone['countries'] ?? null),
            'amount' => $zone['amount'] ?? null,
        ], self::rows($rows, ['name', 'cities', 'countries', 'amount']));
    }

    /**
     * The rows of a repeating table that hold something. A row left entirely blank is not a row; a row half filled is passed on as it is, for the service to refuse.
     *
     * @param array<int, mixed> $rows
     * @param list<string> $keys
     * @return list<array<string, mixed>>
     */
    private static function rows(array $rows, array $keys): array
    {
        // A validated request hands the rows back in the order it first met a filled cell of each; the order they were typed in is the order of their keys.
        ksort($rows, SORT_NUMERIC);
        $kept = [];
        foreach ($rows as $row) {
            $row = array_intersect_key((array) $row, array_flip($keys));
            $hasFigures = array_filter($row, static fn ($value, $key): bool => $key !== 'mode' && $value !== null && $value !== '', ARRAY_FILTER_USE_BOTH) !== [];
            if ($hasFigures) {
                $kept[] = array_map(static fn ($value) => $value === '' ? null : $value, $row);
            }
        }

        return $kept;
    }

    /**
     * @param array<string, mixed> $conditions
     * @return array<string, mixed>
     */
    private static function conditions(array $conditions): array
    {
        $clean = [];
        foreach (self::LIST_CONDITIONS as $key) {
            $list = self::list($conditions[$key] ?? null);
            if ($list !== []) {
                $clean[$key] = $list;
            }
        }
        $channels = array_values(array_filter((array) ($conditions['sales_channels'] ?? []), static fn ($channel): bool => is_string($channel) && $channel !== ''));
        if ($channels !== []) {
            $clean['sales_channels'] = $channels;
        }
        foreach (self::FIGURE_CONDITIONS as $key) {
            if (isset($conditions[$key]) && $conditions[$key] !== '') {
                $clean[$key] = $conditions[$key];
            }
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $conditions
     * @return array<string, mixed>
     */
    private static function conditionDefaults(array $conditions): array
    {
        $values = ['sales_channels' => (array) ($conditions['sales_channels'] ?? [])];
        foreach (self::LIST_CONDITIONS as $key) {
            $values[$key] = implode(', ', (array) ($conditions[$key] ?? []));
        }
        foreach (self::FIGURE_CONDITIONS as $key) {
            $values[$key] = self::figure($conditions[$key] ?? null);
        }

        return $values;
    }

    /** @return list<string> the items of a list typed with commas, semicolons or line breaks */
    private static function list(mixed $text): array
    {
        if (!is_string($text)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\x{060C}\r\n]+/u', $text) ?: []), static fn (string $item): bool => $item !== '')));
    }

    private static function figure(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : CostFormat::figure((string) $value);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $blankRow
     * @return list<array<string, mixed>>
     */
    private static function padded(array $rows, array $blankRow, int $blank): array
    {
        return [...array_values($rows), ...array_fill(0, $blank, $blankRow)];
    }
}
