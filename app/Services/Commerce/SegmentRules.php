<?php

namespace App\Services\Commerce;

use App\Services\Platform\Policy;

/**
 * What a segment rule may read, and how (Phase 3.4).
 *
 * Every field is a number computed from records the shop already keeps — order counts, order
 * recency, registration age, lifetime spend. §40 forbids inventing data and §41 forbids
 * hardcoding segments; this is the allowlist that satisfies both: "Repeat buyer" is
 * orders_count >= 2 written by an admin, not a class written by a developer.
 */
class SegmentRules
{
    public function __construct(private readonly Policy $policy)
    {
    }

    public const OPERATORS = [
        'equals', 'not_equals', 'greater_than', 'greater_than_or_equal',
        'less_than', 'less_than_or_equal', 'between',
    ];

    /**
     * Every field the metrics context computes — all numeric, so one operator set serves all.
     * days_since_last_order is NULL for a customer who never ordered; rules on it simply do not
     * match then, which reads correctly: they have no "last order" to be recent or overdue.
     */
    public const FIELDS = [
        'orders_count', 'days_since_last_order', 'days_since_registration', 'total_spent',
    ];

    /**
     * @return array{rules: array<int, array{field: string, operator: string, value: mixed}>, errors: array<int, string>}
     */
    public function validate(mixed $rows): array
    {
        if (!is_array($rows)) {
            return ['rules' => [], 'errors' => []];
        }

        $clean = [];
        $errors = [];

        // Refused, not truncated. Slicing the tail off meant an admin who went one over the limit
        // saw the rest saved and was never told what had been dropped.
        $limit = $this->policy->int('commerce_max_segment_rules');
        if (count($rows) > $limit) {
            return ['rules' => [], 'errors' => ['rules:at_most_' . $limit]];
        }

        foreach (array_values($rows) as $index => $row) {
            $label = 'rule_' . ($index + 1);

            $field = is_array($row) && is_string($row['field'] ?? null) ? $row['field'] : '';
            $operator = is_array($row) && is_string($row['operator'] ?? null) ? $row['operator'] : '';

            if (!in_array($field, self::FIELDS, true)) {
                $errors[] = $label . ':unknown_field';
                continue;
            }
            if (!in_array($operator, self::OPERATORS, true)) {
                $errors[] = $label . ':unknown_operator';
                continue;
            }

            $value = $row['value'] ?? null;

            if ($operator === 'between') {
                $pair = is_array($value) ? array_values($value) : explode(',', is_string($value) ? $value : '');
                if (count($pair) !== 2 || !is_numeric($pair[0]) || !is_numeric($pair[1])) {
                    $errors[] = $label . ':between_needs_two_numbers';
                    continue;
                }
                $value = [(float) min($pair[0], $pair[1]), (float) max($pair[0], $pair[1])];
            } elseif (!is_numeric($value)) {
                $errors[] = $label . ':value_must_be_a_number';
                continue;
            } else {
                $value = (float) $value;
            }

            $clean[] = ['field' => $field, 'operator' => $operator, 'value' => $value];
        }

        return ['rules' => $clean, 'errors' => $errors];
    }

    /**
     * One rule against one metrics context. NULL metric never matches — a shopper who never
     * ordered is not "0 days since last order".
     *
     * @param  array{field: string, operator: string, value: mixed}  $rule
     * @param  array<string, int|float|null>  $metrics
     */
    public function holds(array $rule, array $metrics): bool
    {
        $actual = $metrics[$rule['field']] ?? null;

        if ($actual === null) {
            return false;
        }

        $value = $rule['value'];

        return match ($rule['operator']) {
            'equals'                => (float) $actual === (float) $value,
            'not_equals'            => (float) $actual !== (float) $value,
            'greater_than'          => $actual > $value,
            'greater_than_or_equal' => $actual >= $value,
            'less_than'             => $actual < $value,
            'less_than_or_equal'    => $actual <= $value,
            'between'               => is_array($value) && $actual >= $value[0] && $actual <= $value[1],
            default                 => false,
        };
    }
}
