<?php

namespace App\Services\Catalog;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

/**
 * A variant's SKU names one variant inside its product's country, as the product's own code names one product there (GCC-14b slice 3, B-06).
 *
 * Variants are not rows: they live as a JSON list in `products.variation`, each `{type, price, sku, qty}`, so no index can hold this and no
 * gate checked it before. Two variants of one product could share a SKU, and a SKU another product already used could be typed again — and
 * then a scan of that SKU names two things.
 *
 * It follows the product's country, which is `ProductCodeScope`'s answer, and it is dormant with the scope: while the switch is off, or before
 * the country column exists, every question answers the way it did before this class existed (no duplicate is found, no product holds a SKU),
 * so no form that saves today starts being refused.
 *
 * **Reading a SKU out of SQL.** The list is a JSON string in a text column and the two engines disagree about JSON, so a candidate is found by
 * a cheap text filter and then **confirmed in PHP** by decoding the list. The filter is a superset, never a narrower: it is only applied for a
 * SKU written in characters `json_encode` leaves alone, and a SKU with anything else in it falls back to decoding every product of the country.
 */
final class ProductVariantSku
{
    private const TABLE = 'products';

    /** The field the product forms and the seller apps already show a variant SKU error under. */
    private const ERROR_FIELD = 'sku_error';

    /** A SKU json_encode writes out unchanged, so a LIKE over the stored JSON can be used to narrow the candidates before they are decoded. */
    private const PLAIN_SKU = '/^[A-Za-z0-9 ._:#()-]+$/';

    public function __construct(private readonly ProductCodeScope $codeScope)
    {
    }

    public function applies(): bool
    {
        return $this->codeScope->enabled();
    }

    /**
     * The variant SKUs a save is submitting, in the order the form lists them. Both the panels and the seller apps post one `sku_<variant>`
     * field per variant, so one reader serves the web and the API.
     *
     * @return array<string, string> the field name => the SKU, for the fields that carry one
     */
    public function submitted(object $request): array
    {
        $submitted = [];
        foreach ($request->all() as $field => $value) {
            if (is_string($field) && str_starts_with($field, 'sku_') && is_scalar($value) && trim((string) $value) !== '') {
                $submitted[$field] = trim((string) $value);
            }
        }

        return $submitted;
    }

    /**
     * The one gate of the admin form, the vendor form and both seller apps, so the four cannot drift apart. It adds nothing while the scope
     * does not apply, which is what keeps every form that saves today saving. The field is the one the forms already show variant SKU errors
     * under, so no screen changes to carry the message.
     */
    public function guard(Validator $validator, object $request, ?string $country, ?int $ignoreProductId = null): void
    {
        foreach ($this->refusals(request: $request, country: $country, ignoreProductId: $ignoreProductId) as $refusal) {
            $validator->errors()->add(self::ERROR_FIELD, $refusal);
        }
    }

    /**
     * What a save of this product may not do with its variant SKUs: repeat one inside the product, or take one a product of the same country
     * already holds.
     *
     * @return list<string> one message per offending SKU, in the order the form lists them
     */
    public function refusals(object $request, ?string $country, ?int $ignoreProductId = null): array
    {
        if (!$this->applies()) {
            return [];
        }

        $submitted = $this->submitted($request);
        $refusals = array_map(
            static fn (string $sku): string => translate('two_variations_of_this_product_have_the_same_sku') . ': ' . $sku,
            $this->repeatedWithinTheProduct($submitted),
        );

        foreach (array_unique(array_values($submitted)) as $sku) {
            $holder = $this->heldByAnotherProduct(sku: $sku, country: $country, ignoreProductId: $ignoreProductId);
            if ($holder !== null) {
                $refusals[] = translate('this_variation_sku_belongs_to_another_product_of_the_same_country') . ': ' . $sku . ' (' . $holder . ')';
            }
        }

        return $refusals;
    }

    /**
     * The SKUs the submission uses more than once. Compared as PHP compares them, which is how the gates of this branch compare a product's
     * own code: two variants whose SKUs differ only in letter case are two SKUs.
     *
     * @param array<string, string> $submitted
     * @return list<string>
     */
    public function repeatedWithinTheProduct(array $submitted): array
    {
        $counts = array_count_values(array_values($submitted));

        return array_values(array_keys(array_filter($counts, static fn (int $times): bool => $times > 1)));
    }

    /**
     * The name of a product of the given country that already holds this variant SKU, ignoring the product being edited. Null when it is free,
     * and null while the scope does not apply.
     */
    public function heldByAnotherProduct(string $sku, ?string $country, ?int $ignoreProductId = null): ?string
    {
        if (!$this->applies()) {
            return null;
        }

        $holder = $this->candidates(country: $country, sku: $sku)
            ->when($ignoreProductId !== null, static fn (QueryBuilder $query) => $query->where('id', '!=', $ignoreProductId))
            ->get(['id', 'name', 'variation'])
            ->first(fn (object $product): bool => $this->skusOf($product->variation)->contains($sku));

        return $holder === null ? null : (string) $holder->name;
    }

    /**
     * The products of the country whose own code, barcode or **variant SKU** is the scanned value: what a till needs, because the label on a
     * box of one size carries that size's SKU, not the product's. The code and barcode half is `ProductCodeScope::scan`, so a scan behaves
     * exactly as it did before this class when the scope does not apply.
     *
     * @return Collection<int, \App\Models\Product>
     */
    public function scan(EloquentBuilder $ownedProducts, mixed $code, ?string $country): Collection
    {
        $byCode = $this->codeScope->scan(ownedProducts: (clone $ownedProducts), code: $code, country: $country);
        if (!$this->applies() || !is_scalar($code) || (string) $code === '') {
            return $byCode;
        }

        $sku = (string) $code;
        $byVariantSku = $this->codeScope->restrictToCountry(query: (clone $ownedProducts), country: $country)
            ->when($this->isPlain($sku), static fn (EloquentBuilder $query) => $query->where('variation', 'like', '%' . self::likeEscaped($sku) . '%'))
            ->orderBy('id')
            ->get()
            ->filter(fn (object $product): bool => $this->skusOf($product->variation)->contains($sku));

        return $byCode->concat($byVariantSku)->unique('id')->sortBy('id')->values();
    }

    /** @return Collection<int, string> the SKUs of one product's stored variation list */
    public function skusOf(mixed $variation): Collection
    {
        $variants = json_decode(is_string($variation) ? $variation : '', true);

        return collect(is_array($variants) ? $variants : [])
            ->map(static fn ($variant) => is_array($variant) && isset($variant['sku']) && is_scalar($variant['sku']) ? trim((string) $variant['sku']) : '')
            ->filter(static fn (string $sku): bool => $sku !== '')
            ->values();
    }

    /** The products of one country that could hold the SKU: narrowed by text where the SKU allows it, and decoded afterwards either way. */
    private function candidates(?string $country, string $sku): QueryBuilder
    {
        $query = $this->codeScope->restrictToCountry(query: DB::table(self::TABLE), country: $country);

        return $this->isPlain($sku)
            ? $query->where('variation', 'like', '%' . self::likeEscaped($sku) . '%')
            : $query;
    }

    private function isPlain(string $sku): bool
    {
        return preg_match(self::PLAIN_SKU, $sku) === 1;
    }

    private static function likeEscaped(string $sku): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $sku);
    }
}
