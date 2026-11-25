<?php

namespace App\Services;

use App\Models\CategoryShippingCost;
use App\Models\Scopes\StaffCountryShippingScope;

class CategoryShippingCostService
{
    /**
     * The categories a seller has no cost row for at all, so the screen can make one for each.
     *
     * Asked WITHOUT the staff country scope, deliberately, and this is the reason it is a method rather than two lines on the screen. The
     * screen's listing is narrowed to the employee's countries (GCC-14c slice 6); "which categories already have a row" is not a listing but
     * a question about the whole table, and asking it narrowed would answer that every category of another market's rows is missing and have
     * the screen make a second row for each one, every time an employee of another country opened it.
     *
     * @param list<int|string> $categoryIds
     * @return list<int|string> the ids with no row, in the order they were given
     */
    public function categoriesWithNoCostRow(array $categoryIds, int $sellerId = 0): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $recorded = CategoryShippingCost::withoutGlobalScope(StaffCountryShippingScope::class)
            ->where('seller_id', $sellerId)
            ->whereIn('category_id', $categoryIds)
            ->pluck('category_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return array_values(array_filter($categoryIds, static fn (mixed $id): bool => !in_array((string) $id, $recorded, true)));
    }

    /**
     * @param string $addedBy
     * @param int|string $id
     * @return array
     */
    public function getAddCategoryWiseShippingCostData(string $addedBy, int|string $id): array
    {
        return [
            'seller_id' => $addedBy === 'seller' ? auth('seller')->id() : 0,
            'category_id' => $id,
            'cost' => 0,
        ];
    }

    /**
     * @param int $key
     * @param int|string $id
     * @param object $request
     * @return array
     */
    public function getUpdateCategoryWiseShippingData(int $key, int|string $id, object $request): array
    {
        return [
            'cost' => currencyConverter($request['cost'][$key], 'usd'),
            'multiply_qty' => isset($request['multiplyQTY']) ? (in_array($id, $request['multiplyQTY']) ? 1 : 0) : 0
        ];
    }
}
