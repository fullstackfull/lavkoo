<?php

namespace App\Services\SellerAutomation;

use Illuminate\Database\Eloquent\Builder;

/**
 * The part of the catalogue a rule is pointed at.
 *
 * Empty means the whole shop, which is what every rule written before this existed meant, so the
 * absence of a scope can never change what a rule does.
 *
 * Categories are matched against `category_ids`, the JSON column the product form writes, rather
 * than the three flattened `category_id` / `sub_category_id` / `sub_sub_category_id` columns: a
 * product filed under a sub-category is inside its parent category, and a seller who scoped a rule
 * to "Skincare" means the products under it too.
 */
class RuleScope
{
    /** How many ids one scope may name, so a rule cannot smuggle an unbounded `IN` clause. */
    public const LIMIT = 100;

    public const FIELDS = ['brand_ids', 'category_ids', 'product_ids'];

    public static function rules(): array
    {
        return [
            'brand_ids' => 'nullable|array|max:' . self::LIMIT,
            'brand_ids.*' => 'integer|min:1',
            'category_ids' => 'nullable|array|max:' . self::LIMIT,
            'category_ids.*' => 'integer|min:1',
            'product_ids' => 'nullable|array|max:' . self::LIMIT,
            'product_ids.*' => 'integer|min:1',
        ];
    }

    /**
     * Keep only the three lists, as integers, dropping the empty ones.
     *
     * A scope stored as `['brand_ids' => []]` and one stored as `null` have to behave identically,
     * so only the non-empty lists survive and a scope with nothing in it becomes nothing at all.
     *
     * @return array<string, array<int, int>>
     */
    public static function clean(?array $scope): array
    {
        $clean = [];

        foreach (self::FIELDS as $field) {
            $ids = array_values(array_unique(array_filter(
                array_map('intval', (array) ($scope[$field] ?? [])),
                fn (int $id) => $id > 0,
            )));

            if ($ids !== []) {
                $clean[$field] = array_slice($ids, 0, self::LIMIT);
            }
        }

        return $clean;
    }

    /** @param array<string, array<int, int>>|null $scope */
    public static function apply(Builder $query, ?array $scope): Builder
    {
        $scope = self::clean($scope);

        if (isset($scope['brand_ids'])) {
            $query->whereIn('brand_id', $scope['brand_ids']);
        }

        if (isset($scope['product_ids'])) {
            $query->whereIn('id', $scope['product_ids']);
        }

        if (isset($scope['category_ids'])) {
            $query->where(function (Builder $where) use ($scope) {
                foreach ($scope['category_ids'] as $categoryId) {
                    // The column holds `[{"id":"5","position":1}, …]`, so the id is matched with its
                    // quotes and key around it. Without them, category 5 would also match 15 and 51.
                    $where->orWhere('category_ids', 'like', '%"id":"' . $categoryId . '"%')
                        ->orWhere('category_id', $categoryId)
                        ->orWhere('sub_category_id', $categoryId)
                        ->orWhere('sub_sub_category_id', $categoryId);
                }
            });
        }

        return $query;
    }

    /** Whether a rule is pointed at part of the catalogue rather than all of it. */
    public static function isNarrowed(?array $scope): bool
    {
        return self::clean($scope) !== [];
    }
}
