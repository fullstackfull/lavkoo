<?php

namespace App\Services\Catalog;

use App\Services\Storefront\ProductCountry;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Whether a SKU or a barcode names one product across the whole catalogue or only inside one country (GCC-14b).
 *
 * It is the only place that knows the switch (`gcc.catalog.country_scoped_codes`) and the column (`products.storefront_country`). The switch
 * ships off, and it acts as off until the products migration has run: every answer is then the global one the platform has always given.
 * Nothing is cached, so a gate always sees what the table holds now.
 */
final class ProductCodeScope
{
    public const FLAG = 'gcc.catalog.country_scoped_codes';

    /** The country of a product no path gave one to: the default of the column. A seller product, a bulk import and an M5AZN import all take it. */
    public const TABLE_DEFAULT_COUNTRY = 'SA';

    private const TABLE = 'products';

    private const CANDIDATE_LIMIT = 50;

    public function enabled(): bool
    {
        return filter_var(config(self::FLAG, false), FILTER_VALIDATE_BOOLEAN) && StorefrontCatalog::columnReady();
    }

    /**
     * The country the add path writes for a product: what the admin chose while countries are tracked, and the default of the column for
     * everything else. It asks the same code that builds the row, so what is checked is what is stored.
     */
    public function countryOfNewProduct(string $addedBy, mixed $requestedCountry = null): string
    {
        $attributes = $addedBy === 'admin' ? ProductCountry::newProductAttributes(requestedCountry: $requestedCountry) : [];

        return $attributes[StorefrontCatalog::COLUMN] ?? self::TABLE_DEFAULT_COUNTRY;
    }

    /** The country a stored product belongs to, or null when the row does not say. */
    public function countryOfProduct(?object $product): ?string
    {
        $country = $product?->{StorefrontCatalog::COLUMN};

        return is_string($country) && $country !== '' ? $country : null;
    }

    public function isCodeTaken(string $code, ?string $country, ?int $ignoreProductId = null): bool
    {
        return $this->isTaken(column: 'code', value: $code, country: $country, ignoreProductId: $ignoreProductId);
    }

    public function isBarcodeTaken(string $barcode, ?string $country, ?int $ignoreProductId = null): bool
    {
        return $this->isTaken(column: 'barcode', value: $barcode, country: $country, ignoreProductId: $ignoreProductId);
    }

    /** A generated code is free where the product will be created: nowhere else matters once codes are per country. */
    public function generatedCodeIsFree(string $code, ?string $country): bool
    {
        return !$this->isCodeTaken(code: $code, country: $country);
    }

    public function uniqueRuleForCode(?string $country, ?int $ignoreProductId = null): Unique
    {
        return $this->uniqueRule(column: 'code', country: $country, ignoreProductId: $ignoreProductId);
    }

    public function uniqueRuleForBarcode(?string $country, ?int $ignoreProductId = null): Unique
    {
        return $this->uniqueRule(column: 'barcode', country: $country, ignoreProductId: $ignoreProductId);
    }

    /**
     * Every product that answers to a typed or scanned value, by its code or its barcode, lowest id first. A country narrows them while the
     * scope applies; a caller that gets more than one back has an ambiguity to answer, never a product to pick.
     *
     * @return Collection<int, object{id: int, name: ?string, code: ?string, barcode: ?string, storefront_country?: ?string}>
     */
    public function candidatesForCode(string $code, ?string $country = null): Collection
    {
        $columns = ['id', 'name', 'code', 'barcode'];
        if ($this->enabled()) {
            $columns[] = StorefrontCatalog::COLUMN;
        }

        return $this->restrictToCountry(query: DB::table(self::TABLE), country: $country)
            ->where(static fn (QueryBuilder $match) => $match->where('code', $code)->orWhere('barcode', $code))
            ->orderBy('id')
            ->limit(self::CANDIDATE_LIMIT)
            ->get($columns);
    }

    /** Keeps a products query to one country while the scope applies. With no country, or before the scope applies, the query is as it was. */
    public function restrictToCountry(EloquentBuilder|QueryBuilder $query, ?string $country): EloquentBuilder|QueryBuilder
    {
        if (!$this->scopesTo($country)) {
            return $query;
        }

        return $query->where(self::TABLE . '.' . StorefrontCatalog::COLUMN, $country);
    }

    private function isTaken(string $column, string $value, ?string $country, ?int $ignoreProductId): bool
    {
        $holders = $this->restrictToCountry(query: DB::table(self::TABLE), country: $country)->where($column, $value);

        return $holders
            ->when($ignoreProductId !== null, static fn (QueryBuilder $query) => $query->where('id', '!=', $ignoreProductId))
            ->exists();
    }

    private function uniqueRule(string $column, ?string $country, ?int $ignoreProductId): Unique
    {
        $rule = Rule::unique(self::TABLE, $column);
        if ($this->scopesTo($country)) {
            $rule->where(StorefrontCatalog::COLUMN, $country);
        }

        return $rule->ignore($ignoreProductId, 'id');
    }

    /** A product whose country is not known is checked against every country: refusing a code that is free is safe, accepting one that is held is not. */
    private function scopesTo(?string $country): bool
    {
        return $country !== null && $country !== '' && $this->enabled();
    }
}
