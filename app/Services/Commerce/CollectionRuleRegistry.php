<?php

namespace App\Services\Commerce;

use App\Services\Platform\Policy;

/**
 * The only fields and operators a collection rule may use (§20–22).
 *
 * A rule is untrusted admin input that becomes a database query, which is exactly the shape SQL
 * injection wears when it arrives politely. So nothing here interpolates: every field maps to a
 * hardcoded column or metric, every operator to a fixed query method, and anything not in this
 * registry is refused at save time — not silently dropped at run time, where the merchant would
 * ship a collection that means something other than what they wrote.
 */
class CollectionRuleRegistry
{
    public function __construct(private readonly Policy $policy)
    {
    }

    private const NUMERIC_OPERATORS = [
        'equals', 'not_equals', 'greater_than', 'greater_than_or_equal',
        'less_than', 'less_than_or_equal', 'between',
    ];

    /**
     * field => [type, operators, where it lives].
     *
     * `column` compiles against products; `metric` against the precomputed product_metrics row
     * (COALESCEd to zero, so a product with no row yet is a product with no sales — true, and
     * cheap). Only metrics real data feeds are listed; §19 forbids inventing telemetry.
     *
     * @var array<string, array{type: string, operators: array<int, string>, column?: string, metric?: string}>
     */
    public const FIELDS = [
        'price'            => ['type' => 'numeric', 'operators' => self::NUMERIC_OPERATORS, 'column' => 'unit_price'],
        'stock'            => ['type' => 'numeric', 'operators' => self::NUMERIC_OPERATORS, 'column' => 'current_stock'],
        'discount'         => ['type' => 'numeric', 'operators' => self::NUMERIC_OPERATORS, 'column' => 'discount'],
        'category'         => ['type' => 'set',     'operators' => ['in', 'not_in'], 'column' => 'category_id'],
        'brand'            => ['type' => 'set',     'operators' => ['in', 'not_in'], 'column' => 'brand_id'],
        'featured'         => ['type' => 'boolean', 'operators' => ['equals'], 'column' => 'featured'],
        'created_at'       => ['type' => 'days',    'operators' => ['within_last_days'], 'column' => 'created_at'],
        'rating'           => ['type' => 'numeric', 'operators' => self::NUMERIC_OPERATORS, 'metric' => 'rating'],
        'sales_30d'        => ['type' => 'numeric', 'operators' => self::NUMERIC_OPERATORS, 'metric' => 'sales_30d'],
        'views_30d'        => ['type' => 'numeric', 'operators' => self::NUMERIC_OPERATORS, 'metric' => 'views_30d'],
        'carted_30d'       => ['type' => 'numeric', 'operators' => self::NUMERIC_OPERATORS, 'metric' => 'carted_30d'],
        'wishlist_count'   => ['type' => 'numeric', 'operators' => self::NUMERIC_OPERATORS, 'metric' => 'wishlist_count'],
    ];

    /** The rankings a collection may order by — metric columns plus the two catalogue orders. */
    public const SORTS = [
        'sales_30d', 'views_30d', 'carted_30d', 'rating', 'wishlist_count',
        'newest', 'price_low', 'price_high',
    ];

    /**
     * Validate untrusted rule rows into exactly what the resolver will compile — or name what is
     * wrong. Nothing invalid is "fixed up": a rule that cannot mean what it says is refused.
     *
     * @param  mixed  $rows
     * @return array{rules: array<int, array{field: string, operator: string, value: mixed}>, errors: array<int, string>}
     */
    public function validate(mixed $rows): array
    {
        if (!is_array($rows)) {
            // Nothing readable is NOT "no rules": a collection with no rules matches the whole
            // catalogue, and a malformed payload silently widening to everything is the exact
            // shape of corruption this validator exists to refuse.
            return ['rules' => [], 'errors' => ['rules:not_a_list']];
        }

        $clean = [];
        $errors = [];

        // Refused, not truncated. Slicing the tail off meant an admin who went one over the limit
        // saw the rest saved and was never told what had been dropped.
        $limit = $this->policy->int('commerce_max_collection_rules');
        if (count($rows) > $limit) {
            return ['rules' => [], 'errors' => ['rules:at_most_' . $limit]];
        }

        foreach (array_values($rows) as $index => $row) {
            $label = 'rule_' . ($index + 1);

            if (!is_array($row)) {
                $errors[] = $label . ':not_a_rule';
                continue;
            }

            $field = is_string($row['field'] ?? null) ? $row['field'] : '';
            $operator = is_string($row['operator'] ?? null) ? $row['operator'] : '';
            $definition = self::FIELDS[$field] ?? null;

            if ($definition === null) {
                $errors[] = $label . ':unknown_field';
                continue;
            }
            if (!in_array($operator, $definition['operators'], true)) {
                $errors[] = $label . ':operator_not_allowed_for_' . $field;
                continue;
            }

            $value = $this->cleanValue($definition['type'], $operator, $row['value'] ?? null);
            if ($value === null) {
                $errors[] = $label . ':value_does_not_fit_' . $field;
                continue;
            }

            $clean[] = ['field' => $field, 'operator' => $operator, 'value' => $value];
        }

        return ['rules' => $clean, 'errors' => $errors];
    }

    public function isSort(mixed $sort): bool
    {
        return is_string($sort) && in_array($sort, self::SORTS, true);
    }

    /**
     * A value coerced to the shape its type demands, or null when it cannot be.
     */
    private function cleanValue(string $type, string $operator, mixed $value): mixed
    {
        if ($type === 'boolean') {
            return in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true)
                ? in_array($value, [true, 1, '1', 'true'], true)
                : null;
        }

        if ($type === 'days') {
            return is_numeric($value) && (int) $value > 0 && (int) $value <= 3650 ? (int) $value : null;
        }

        if ($type === 'set') {
            $raw = is_array($value) ? $value : explode(',', is_string($value) ? $value : '');
            $ids = array_values(array_unique(array_filter(array_map('intval', $raw), fn ($id) => $id > 0)));

            return $ids === [] ? null : array_slice($ids, 0, 100);
        }

        // numeric
        if ($operator === 'between') {
            $pair = is_array($value) ? array_values($value) : explode(',', is_string($value) ? $value : '');
            if (count($pair) !== 2 || !is_numeric($pair[0]) || !is_numeric($pair[1])) {
                return null;
            }
            [$low, $high] = [(float) $pair[0], (float) $pair[1]];

            return $low <= $high ? [$low, $high] : [$high, $low];
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
