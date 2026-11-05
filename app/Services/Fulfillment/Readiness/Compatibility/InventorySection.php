<?php

namespace App\Services\Fulfillment\Readiness\Compatibility;

use Illuminate\Database\Query\Builder;

/**
 * Stock: what the M5AZN stock sync would overwrite. The sync writes products.current_stock, and for a variant product it
 * rewrites that variant's `qty` inside the `variation` JSON and then sets current_stock to the sum of the variants. So the
 * variation JSON must be readable, every variant must carry a type, a price and a numeric qty, and current_stock should
 * already equal the sum of the variants; where it does not, the first sync will change the number.
 */
final class InventorySection implements CompatibilitySection
{
    private const SECTION = 'inventory';

    private const MOVEMENT_TYPES = ['adjustment', 'receipt', 'sale', 'return', 'transfer'];

    public function checks(ReadOnlyProbe $probe, CompatibilityOptions $options): array
    {
        $columns = $probe->columns('products');
        if (!isset($columns['current_stock'], $columns['added_by'], $columns['product_type'])) {
            return [new CompatibilityCheck(self::SECTION, 'stock', CompatibilityCheck::INFO, 'not checked: products.current_stock, added_by or product_type is missing (the schema check reports it)')];
        }

        return array_values(array_filter([
            $this->levels($probe),
            isset($columns['variation']) ? $this->variants($probe, $options) : null,
            $this->ledger($probe),
        ]));
    }

    private function levels(ReadOnlyProbe $probe): CompatibilityCheck
    {
        $negative = $probe->count('products', fn (Builder $query) => $this->pool($query)->where('current_stock', '<', 0));
        $missing = $probe->count('products', fn (Builder $query) => $this->pool($query)->whereNull('current_stock'));
        $units = (int) $probe->sum('products', 'current_stock', fn (Builder $query) => $this->pool($query));

        return new CompatibilityCheck(
            section: self::SECTION,
            name: 'stock levels',
            status: $negative + $missing > 0 ? CompatibilityCheck::WARN : CompatibilityCheck::PASS,
            detail: $negative + $missing > 0
                ? "{$negative} assignable product(s) have negative stock and {$missing} none recorded: a provider sync would overwrite them with a non-negative number"
                : "no assignable product has negative or missing stock ({$units} units in total)",
            facts: ['negative_stock' => $negative, 'missing_stock' => $missing, 'units_in_stock' => $units],
        );
    }

    private function variants(ReadOnlyProbe $probe, CompatibilityOptions $options): CompatibilityCheck
    {
        $tally = ['sampled' => 0, 'with_variants' => 0, 'not_json' => 0, 'variants' => 0, 'without_type' => 0, 'without_price' => 0, 'without_numeric_qty' => 0, 'stock_differs_from_variant_sum' => 0];
        $withVariants = static fn (Builder $query) => $query
            ->where('added_by', 'admin')->where('product_type', 'physical')
            ->whereNotNull('variation')->where('variation', '<>', '')->where('variation', '<>', '[]');

        $probe->eachRecent('products', ['variation', 'current_stock'], $options->sample, function (object $row) use (&$tally): void {
            $this->tally($tally, $row);
        }, 'id', $withVariants);

        $broken = $tally['not_json'] + $tally['without_type'] + $tally['without_numeric_qty'];

        return new CompatibilityCheck(
            section: self::SECTION,
            name: 'variant stock',
            status: $broken + $tally['stock_differs_from_variant_sum'] > 0 ? CompatibilityCheck::WARN : CompatibilityCheck::PASS,
            detail: match (true) {
                $tally['sampled'] === 0 => 'no assignable product has variants',
                $broken > 0 => "{$broken} problem(s) in the variants of {$tally['sampled']} products: unreadable JSON, a variant without a type, or without a numeric qty; the stock sync could not update them",
                $tally['stock_differs_from_variant_sum'] > 0 => "{$tally['stock_differs_from_variant_sum']} of {$tally['sampled']} variant products have a current_stock that is not the sum of their variants: the first stock sync will change it",
                default => "{$tally['sampled']} variant products: JSON readable, every variant typed with a numeric qty, current_stock equals the variant sum",
            },
            facts: $tally,
        );
    }

    /** @param array<string, int> $tally */
    private function tally(array &$tally, object $row): void
    {
        $tally['sampled']++;
        $decoded = JsonShape::decode($row->variation ?? null);
        if (!$decoded['valid'] || !is_array($decoded['value'])) {
            $tally['not_json']++;

            return;
        }

        $tally['with_variants']++;
        $sum = 0;
        foreach ($decoded['value'] as $variant) {
            $tally['variants']++;
            $tally['without_type'] += is_array($variant) && JsonShape::text($variant, 'type') !== null ? 0 : 1;
            $tally['without_price'] += is_array($variant) && is_numeric($variant['price'] ?? null) ? 0 : 1;
            $numericQty = is_array($variant) && is_numeric($variant['qty'] ?? null);
            $tally['without_numeric_qty'] += $numericQty ? 0 : 1;
            $sum += $numericQty ? (int) $variant['qty'] : 0;
        }

        $tally['stock_differs_from_variant_sum'] += (int) ($row->current_stock ?? 0) !== $sum ? 1 : 0;
    }

    private function ledger(ReadOnlyProbe $probe): CompatibilityCheck
    {
        if (!$probe->hasTable('stock_movements')) {
            return new CompatibilityCheck(self::SECTION, 'stock movement history', CompatibilityCheck::INFO, 'there is no stock_movements table: stock syncs still work but leave no history line');
        }

        $description = isset($probe->columns('stock_movements')['type'])
            ? Enumeration::describe($probe->distribution('stock_movements', 'type', CompatibilityOptions::MAX_LISTED_VALUES * 2), self::MOVEMENT_TYPES)
            : null;
        $total = $probe->count('stock_movements');

        return new CompatibilityCheck(
            section: self::SECTION,
            name: 'stock movement history',
            status: CompatibilityCheck::INFO,
            detail: "{$total} stock movements recorded; a provider sync adds one \"adjustment\" per change",
            facts: ['movements' => $total] + ($description === null ? [] : ['by_type' => $description['values']]),
        );
    }

    private function pool(Builder $query): Builder
    {
        return $query->where('added_by', 'admin')->where('product_type', 'physical');
    }
}
