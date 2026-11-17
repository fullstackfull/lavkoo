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
     * The products a scanned value answers to among the products the caller hands over (the owner's own, already narrowed by the caller).
     * With the scope off the answer is the old one: the first product whose code is the value, never its barcode. With the scope on it is
     * every product of the country whose code or barcode is the value, lowest id first; more than one is an ambiguity for the caller to
     * answer, never a product to pick.
     *
     * @return Collection<int, \App\Models\Product>
     */
    public function scan(EloquentBuilder $ownedProducts, mixed $code, ?string $country): Collection
    {
        if (!$this->enabled()) {
            return $ownedProducts->where(['code' => $code])->limit(1)->get();
        }

        return $this->restrictToCountry(query: $ownedProducts, country: $country)
            ->where(static fn (EloquentBuilder $match) => $match->where('code', $code)->orWhere('barcode', $code))
            ->orderBy('id')
            ->limit(self::CANDIDATE_LIMIT)
            ->get();
    }

    /** Whether the country a scan names can be used: any configured country, open or not, or none. Without the scope nothing in the request counts. */
    public function isScanCountryAllowed(mixed $requestedCountry): bool
    {
        return !$this->enabled()
            || $requestedCountry === null
            || $requestedCountry === ''
            || (is_string($requestedCountry) && in_array($requestedCountry, ProductCountry::options(), true));
    }

    /** The country a scan is narrowed to: the one it names, else the default storefront's. Null while the scope does not apply. */
    public function countryOfScan(mixed $requestedCountry): ?string
    {
        if (!$this->enabled()) {
            return null;
        }

        return $this->isScanCountryAllowed($requestedCountry) && is_string($requestedCountry) && $requestedCountry !== '' ? $requestedCountry : ProductCountry::defaultCountry();
    }

    /**
     * The country an order belongs to: orders carry none, it follows from their lines, and only when every line is a product of one country.
     * Null while the scope does not apply and for an order whose lines name none, two, or a product that is gone or has no country.
     */
    public function countryOfOrder(int|string|null $orderId): ?string
    {
        if ($orderId === null || !$this->enabled()) {
            return null;
        }

        $countries = DB::table('order_details')
            ->leftJoin(self::TABLE, self::TABLE . '.id', '=', 'order_details.product_id')
            ->where('order_details.order_id', $orderId)
            ->distinct()
            ->pluck(self::TABLE . '.' . StorefrontCatalog::COLUMN);

        return $countries->count() === 1 && is_string($countries->first()) && $countries->first() !== '' ? $countries->first() : null;
    }

    /**
     * The country of the store products a fulfilment provider's items are matched to. Provider connections have no country of their own yet, so
     * every one serves the default storefront's. Null while the scope does not apply.
     */
    public function countryOfProviderProducts(): ?string
    {
        return $this->enabled() ? ProductCountry::defaultCountry() : null;
    }

    /**
     * Whether a routing rule written for a SKU may fire for a line whose product is in the given country. A rule that names a country is for that
     * country's products; a rule that names none was written when the platform had one country, so it is for the default storefront's. A product
     * of no known country satisfies no SKU rule. Always true while the scope does not apply.
     */
    public function skuRuleAppliesTo(?string $ruleCountry, ?string $productCountry): bool
    {
        if (!$this->enabled()) {
            return true;
        }

        $ruleCountry = $ruleCountry === null ? '' : strtoupper(trim($ruleCountry));

        return $productCountry === ($ruleCountry === '' ? ProductCountry::defaultCountry() : $ruleCountry);
    }

    /**
     * The country of each of the given products, by product id, while the scope applies. A product that is gone, or has no country, is not in it.
     *
     * @param list<int> $productIds
     * @return array<int, string>
     */
    public function countriesOfProducts(array $productIds): array
    {
        if ($productIds === [] || !$this->enabled()) {
            return [];
        }

        return DB::table(self::TABLE)->whereIn('id', $productIds)->pluck(StorefrontCatalog::COLUMN, 'id')
            ->map(static fn ($country): string => (string) $country)
            ->filter(static fn (string $country): bool => $country !== '')
            ->all();
    }

    /** What a picker row adds to a product's label so that two countries' products of one name can be told apart: nothing unless the scope applies. */
    public function countryTag(object $product): string
    {
        $country = $this->enabled() ? $this->countryOfProduct($product) : null;

        return $country === null ? '' : ' · ' . $country;
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
