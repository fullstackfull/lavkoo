<?php

namespace App\Services\Fulfillment\Readiness\Compatibility;

use App\Enums\Fulfillment\ProviderCode;
use Closure;
use Illuminate\Database\Query\Builder;

/**
 * The catalogue a provider can be assigned from: which products exist, who owns them, whether the SKU and barcode that
 * provider catalogs are matched on are present and unique. Counts only; no product name, SKU or price is printed.
 */
final class ProductsSection implements CompatibilitySection
{
    private const SECTION = 'products';

    public function checks(ReadOnlyProbe $probe, CompatibilityOptions $options): array
    {
        if (!$probe->hasTable('products')) {
            return [new CompatibilityCheck(self::SECTION, 'products', CompatibilityCheck::INFO, 'not checked: the table is missing (the schema check reports it)')];
        }

        $columns = $probe->columns('products');
        $hasPool = isset($columns['added_by'], $columns['product_type']);

        return array_values(array_filter([
            $hasPool ? $this->catalogue($probe) : null,
            isset($columns['fulfillment_provider']) ? $this->assignments($probe) : $this->assignmentsNotMigrated(),
            $hasPool && isset($columns['code'], $columns['barcode']) ? $this->identifiers($probe) : null,
            $hasPool && isset($columns['unit_price'], $columns['status']) ? $this->prices($probe) : null,
        ]));
    }

    private function catalogue(ReadOnlyProbe $probe): CompatibilityCheck
    {
        $limit = CompatibilityOptions::MAX_LISTED_VALUES * 2;
        $owners = Enumeration::describe($probe->distribution('products', 'added_by', $limit), ['admin', 'seller']);
        $types = Enumeration::describe($probe->distribution('products', 'product_type', $limit), ['physical', 'digital']);
        $pool = $probe->count('products', fn (Builder $query) => $this->pool($query));
        $surprises = Enumeration::hasSurprises($owners) || Enumeration::hasSurprises($types);

        return new CompatibilityCheck(
            section: self::SECTION,
            name: 'catalogue',
            status: $surprises ? CompatibilityCheck::WARN : CompatibilityCheck::INFO,
            detail: "{$pool} of {$owners['total_rows']} products are physical and added by the admin: the only ones that can be assigned to a provider"
                . ($surprises ? '; some owner or type values are not the ones the integration knows (see the facts)' : ''),
            facts: ['products' => $owners['total_rows'], 'assignable' => $pool, 'added_by' => $owners['values'], 'product_type' => $types['values'], 'unknown_added_by' => $owners['unknown'], 'unknown_product_type' => $types['unknown']],
        );
    }

    private function assignments(ReadOnlyProbe $probe): CompatibilityCheck
    {
        $description = Enumeration::describe(
            $probe->distribution('products', 'fulfillment_provider', CompatibilityOptions::MAX_LISTED_VALUES * 2),
            array_map(static fn (ProviderCode $code): string => $code->value, ProviderCode::cases()),
        );

        return new CompatibilityCheck(
            section: self::SECTION,
            name: 'provider assignments',
            status: Enumeration::hasSurprises($description) ? CompatibilityCheck::WARN : CompatibilityCheck::INFO,
            detail: Enumeration::hasSurprises($description)
                ? 'fulfillment_provider holds values that are not a provider code of this integration (see the facts)'
                : "{$description['null_rows']} products are fulfilled in-house; " . (array_sum($description['values']) > 0 ? 'the rest are assigned to a provider' : 'none is assigned to a provider yet'),
            facts: ['in_house' => $description['null_rows'], 'assigned' => $description['values'], 'unknown' => $description['unknown']],
        );
    }

    private function assignmentsNotMigrated(): CompatibilityCheck
    {
        return new CompatibilityCheck(
            section: self::SECTION,
            name: 'provider assignments',
            status: CompatibilityCheck::INFO,
            detail: 'products.fulfillment_provider does not exist yet: migration 2026_11_05_100100 adds it (nullable, so every existing product stays in-house)',
        );
    }

    private function identifiers(ReadOnlyProbe $probe): CompatibilityCheck
    {
        $blank = fn (string $column): Closure => fn (Builder $query) => $this->pool($query)->where(static fn (Builder $inner) => $inner->whereNull($column)->orWhere($column, ''));
        $blankCodes = $probe->count('products', $blank('code'));
        $blankBarcodes = $probe->count('products', $blank('barcode'));
        $duplicatedCodes = $probe->duplicatedValues('products', 'code', fn (Builder $query) => $this->pool($query));
        $duplicatedBarcodes = $probe->duplicatedValues('products', 'barcode', fn (Builder $query) => $this->pool($query));
        $duplicated = $duplicatedCodes + $duplicatedBarcodes;

        return new CompatibilityCheck(
            section: self::SECTION,
            name: 'SKUs and barcodes',
            status: $duplicated > 0 ? CompatibilityCheck::WARN : CompatibilityCheck::PASS,
            detail: $duplicated > 0
                ? "{$duplicatedCodes} SKU(s) and {$duplicatedBarcodes} barcode(s) are shared by more than one assignable product: matching a provider product on them is ambiguous, so those products need a manual match"
                : "no SKU or barcode is shared by two assignable products ({$blankCodes} have no SKU, {$blankBarcodes} no barcode)",
            facts: ['blank_sku' => $blankCodes, 'blank_barcode' => $blankBarcodes, 'duplicated_sku' => $duplicatedCodes, 'duplicated_barcode' => $duplicatedBarcodes],
        );
    }

    private function prices(ReadOnlyProbe $probe): CompatibilityCheck
    {
        $unpriced = $probe->count('products', fn (Builder $query) => $this->pool($query)->where('status', 1)->where('unit_price', '<=', 0));

        return new CompatibilityCheck(
            section: self::SECTION,
            name: 'published products without a price',
            status: CompatibilityCheck::INFO,
            detail: "{$unpriced} published assignable products have no selling price",
            facts: ['published_without_price' => $unpriced],
        );
    }

    private function pool(Builder $query): Builder
    {
        return $query->where('added_by', 'admin')->where('product_type', 'physical');
    }
}
