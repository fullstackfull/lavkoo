<?php

namespace App\Services\Fulfillment\Readiness\Compatibility;

/**
 * The order lines. The ownership snapshot is taken from each line at checkout and the provider payload is built from it,
 * so what matters is that lines are whole: they point at an order and a product, carry a sane quantity and price, and keep
 * a readable copy of the product as it was ordered. Counts only; no product name or price is ever printed.
 */
final class OrderDetailsSection implements CompatibilitySection
{
    private const SECTION = 'order_details';

    public function checks(ReadOnlyProbe $probe, CompatibilityOptions $options): array
    {
        if (!$probe->hasTable('order_details')) {
            return [new CompatibilityCheck(self::SECTION, 'order lines', CompatibilityCheck::INFO, 'not checked: the table is missing (the schema check reports it)')];
        }

        $columns = array_keys($probe->columns('order_details'));
        $wanted = array_values(array_intersect(['order_id', 'product_id', 'qty', 'price', 'product_details', 'variant'], $columns));

        $tally = ['sampled' => 0, 'quantity_below_one' => 0, 'negative_price' => 0, 'without_product_id' => 0, 'with_variant' => 0, 'details_not_json' => 0, 'details_without_id' => 0, 'details_id_differs' => 0];
        $orderIds = [];
        $productIds = [];
        $probe->eachRecent('order_details', $wanted, $options->sample, function (object $row) use (&$tally, &$orderIds, &$productIds): void {
            $this->tally($tally, $row);
            $orderIds[] = $row->order_id ?? null;
            $productIds[] = $row->product_id ?? null;
        });

        return array_values(array_filter([
            new CompatibilityCheck(self::SECTION, 'volume', CompatibilityCheck::INFO, ($total = $probe->count('order_details')) . ' order lines', ['order_lines' => $total]),
            $this->lineQuality($tally),
            in_array('product_details', $columns, true) ? $this->productCopy($tally) : null,
            in_array('order_id', $columns, true) && $probe->hasTable('orders') ? $this->orphans($probe, $orderIds) : null,
            in_array('product_id', $columns, true) && $probe->hasTable('products') ? $this->missingProducts($probe, $productIds) : null,
        ]));
    }

    /** @param array<string, int> $tally */
    private function tally(array &$tally, object $row): void
    {
        $tally['sampled']++;
        $tally['quantity_below_one'] += property_exists($row, 'qty') && ($row->qty === null || (float) $row->qty < 1) ? 1 : 0;
        $tally['negative_price'] += isset($row->price) && (float) $row->price < 0 ? 1 : 0;
        $tally['without_product_id'] += property_exists($row, 'product_id') && ($row->product_id === null || (int) $row->product_id === 0) ? 1 : 0;
        $tally['with_variant'] += isset($row->variant) && trim((string) $row->variant) !== '' ? 1 : 0;

        if (!property_exists($row, 'product_details')) {
            return;
        }

        $copy = JsonShape::decode($row->product_details);
        if (!$copy['valid'] || !is_array($copy['value'])) {
            $tally['details_not_json']++;

            return;
        }

        $copiedId = JsonShape::text($copy['value'], 'id');
        $tally['details_without_id'] += $copiedId === null ? 1 : 0;
        $tally['details_id_differs'] += $copiedId !== null && isset($row->product_id) && $copiedId !== (string) $row->product_id ? 1 : 0;
    }

    /** @param array<string, int> $tally */
    private function lineQuality(array $tally): CompatibilityCheck
    {
        $problems = $tally['quantity_below_one'] + $tally['negative_price'];

        return new CompatibilityCheck(
            section: self::SECTION,
            name: 'quantities and prices',
            status: $problems > 0 ? CompatibilityCheck::WARN : CompatibilityCheck::PASS,
            detail: $problems > 0
                ? "{$tally['quantity_below_one']} of the {$tally['sampled']} newest lines have a quantity below 1 and {$tally['negative_price']} a negative price"
                : "the {$tally['sampled']} newest lines all have a positive quantity and a non-negative price",
            facts: ['sampled' => $tally['sampled'], 'quantity_below_one' => $tally['quantity_below_one'], 'negative_price' => $tally['negative_price'], 'without_product_id' => $tally['without_product_id'], 'with_variant' => $tally['with_variant']],
        );
    }

    /** @param array<string, int> $tally */
    private function productCopy(array $tally): CompatibilityCheck
    {
        $problems = $tally['details_not_json'] + $tally['details_without_id'];

        return new CompatibilityCheck(
            section: self::SECTION,
            name: 'product copy on the line',
            status: $problems > 0 ? CompatibilityCheck::WARN : CompatibilityCheck::PASS,
            detail: $problems > 0
                ? "{$tally['details_not_json']} of the {$tally['sampled']} newest lines keep no readable product copy and {$tally['details_without_id']} a copy without an id; the integration names a line from the product row instead"
                : "the product copy on the {$tally['sampled']} newest lines is readable JSON with an id",
            facts: ['sampled' => $tally['sampled'], 'not_json' => $tally['details_not_json'], 'without_id' => $tally['details_without_id'], 'id_differs_from_product_id' => $tally['details_id_differs']],
        );
    }

    /** @param list<mixed> $orderIds */
    private function orphans(ReadOnlyProbe $probe, array $orderIds): CompatibilityCheck
    {
        $referenced = array_values(array_unique(array_filter(array_map('strval', array_filter($orderIds, static fn (mixed $id): bool => $id !== null)))));
        $existing = array_flip($probe->existing('orders', 'id', $referenced));
        $orphaned = count(array_filter($referenced, static fn (string $id): bool => !isset($existing[$id])));

        return new CompatibilityCheck(
            section: self::SECTION,
            name: 'lines whose order is gone',
            status: $orphaned > 0 ? CompatibilityCheck::WARN : CompatibilityCheck::PASS,
            detail: $orphaned > 0 ? "{$orphaned} of " . count($referenced) . ' orders referenced by the newest lines do not exist' : 'every order referenced by the newest lines exists',
            facts: ['orders_referenced' => count($referenced), 'orders_missing' => $orphaned],
        );
    }

    /** @param list<mixed> $productIds */
    private function missingProducts(ReadOnlyProbe $probe, array $productIds): CompatibilityCheck
    {
        $referenced = array_values(array_unique(array_filter(array_map('strval', array_filter($productIds, static fn (mixed $id): bool => $id !== null && (int) $id !== 0)))));
        $existing = array_flip($probe->existing('products', 'id', $referenced));
        $missing = count(array_filter($referenced, static fn (string $id): bool => !isset($existing[$id])));

        return new CompatibilityCheck(
            section: self::SECTION,
            name: 'lines whose product is gone',
            status: CompatibilityCheck::INFO,
            detail: $missing > 0 ? "{$missing} of " . count($referenced) . ' products referenced by the newest lines no longer exist (normal for deleted products; their old orders are never re-routed)' : 'every product referenced by the newest lines exists',
            facts: ['products_referenced' => count($referenced), 'products_missing' => $missing],
        );
    }
}
