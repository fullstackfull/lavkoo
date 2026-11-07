<?php

namespace App\Services\OperationalCosts\Reports;

use Illuminate\Support\Facades\DB;

/** The names of the products a report mentions, read in one query. A product that no longer exists simply has no name here; the caller shows its id. */
class ProductNames
{
    /**
     * @param iterable<int|string|null> $productIds
     * @return array<int, string> name by product id
     */
    public function lookup(iterable $productIds): array
    {
        $ids = [];
        foreach ($productIds as $productId) {
            if (is_numeric($productId)) {
                $ids[(int) $productId] = (int) $productId;
            }
        }
        if ($ids === []) {
            return [];
        }

        return DB::table('products')->whereIn('id', array_values($ids))->pluck('name', 'id')->map(static fn ($name): string => (string) $name)->all();
    }
}
