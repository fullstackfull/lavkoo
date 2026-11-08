<?php

namespace App\Services\SalesChannels\Listings;

use Illuminate\Support\Facades\DB;

/**
 * Is the STORE's own product fit to be listed anywhere at all?
 *
 * This is a different question from "has the channel's form been filled in" (`ListingRequirements`, which the channel itself answers). A channel will take a listing with one
 * blurry image and no barcode and then refuse it, or show it badly, days later. These are the things the shop can see about its own product before anybody is asked:
 *
 *  - a name, and a description;
 *  - a barcode or a code the channels can match on (a channel that matches by EAN/UPC has nothing to work with otherwise);
 *  - a category, so that the pharmacy gate and the channel's own product type have something to go on;
 *  - a thumbnail and at least `images_at_least` images (default 1): nothing here fetches an image or judges its pixels — it checks that the product HAS them;
 *  - a brand, when the owner asks for one;
 *  - for a product sold per variant: each variant needs its own quantity-bearing entry and a price, and the variant a draft names has to exist.
 *
 * Every problem is a stable code, so a screen can translate it and a test can name it. It reads the product and writes nothing. The thresholds are the owner's
 * (`sales_channels.listing.product_data`), because what a pharmacy wants of its own catalogue is not something the code should decide.
 */
class ProductListingReadiness
{
    public const NO_NAME = 'product_has_no_name';
    public const NO_DESCRIPTION = 'product_has_no_description';
    public const NO_IDENTIFIER = 'product_has_no_barcode_or_code';
    public const NO_CATEGORY = 'product_has_no_category';
    public const NO_BRAND = 'product_has_no_brand';
    public const NO_THUMBNAIL = 'product_has_no_thumbnail';
    public const NOT_ENOUGH_IMAGES = 'product_has_too_few_images';
    public const VARIANT_NOT_FOUND = 'product_has_no_such_variant';
    public const VARIANT_WITHOUT_A_PRICE = 'product_variant_has_no_price';
    public const VARIANT_KEY_REQUIRED = 'product_is_sold_per_variant_so_a_draft_needs_one';
    public const NOT_A_PHYSICAL_PRODUCT = 'product_is_not_a_physical_product_of_the_store';
    public const NOT_FOUND = 'product_not_found';

    /**
     * What is missing before this product (or this variant of it) may be offered anywhere.
     *
     * @return list<string> empty when there is nothing to fix
     */
    public function problemsFor(int $productId, string $variantKey = ''): array
    {
        $product = DB::table('products')->where('id', $productId)->first([
            'id', 'name', 'details', 'code', 'barcode', 'brand_id', 'category_ids', 'images', 'thumbnail', 'variation', 'added_by', 'product_type',
        ]);
        if ($product === null) {
            return [self::NOT_FOUND];
        }
        if (($product->added_by ?? 'admin') !== 'admin' || ($product->product_type ?? 'physical') !== 'physical') {
            return [self::NOT_A_PHYSICAL_PRODUCT];
        }

        return array_values(array_filter([
            ...$this->aboutTheProduct($product),
            ...$this->aboutTheImages($product),
            ...$this->aboutTheVariant($product, $variantKey),
        ]));
    }

    /** @return list<string> */
    private function aboutTheProduct(object $product): array
    {
        $problems = [];
        if (trim((string) ($product->name ?? '')) === '') {
            $problems[] = self::NO_NAME;
        }
        if (trim(strip_tags((string) ($product->details ?? ''))) === '') {
            $problems[] = self::NO_DESCRIPTION;
        }
        if (trim((string) ($product->barcode ?? '')) === '' && trim((string) ($product->code ?? '')) === '') {
            $problems[] = self::NO_IDENTIFIER;
        }
        if ($this->categoryIdsOf($product) === []) {
            $problems[] = self::NO_CATEGORY;
        }
        if ((bool) config('sales_channels.listing.product_data.brand_required', false) && (int) ($product->brand_id ?? 0) < 1) {
            $problems[] = self::NO_BRAND;
        }

        return $problems;
    }

    /** @return list<string> */
    private function aboutTheImages(object $product): array
    {
        $problems = [];
        if (trim((string) ($product->thumbnail ?? '')) === '') {
            $problems[] = self::NO_THUMBNAIL;
        }
        if (count($this->imagesOf($product)) < max(0, (int) config('sales_channels.listing.product_data.images_at_least', 1))) {
            $problems[] = self::NOT_ENOUGH_IMAGES;
        }

        return $problems;
    }

    /**
     * A product sold per variant is listed per variant: a draft for the whole of it has nothing to publish a quantity for, and a variant that is not there cannot be listed at
     * all. A variant without a price is not for sale in the store either (the stock write refuses it too), so it is not offered to a channel.
     *
     * @return list<string>
     */
    private function aboutTheVariant(object $product, string $variantKey): array
    {
        $variations = $this->variationsOf($product);
        if ($variations === []) {
            return [];
        }
        if ($variantKey === '') {
            return [self::VARIANT_KEY_REQUIRED];
        }

        foreach ($variations as $variation) {
            if ((string) ($variation['type'] ?? '') === $variantKey) {
                return (float) ($variation['price'] ?? 0) > 0 ? [] : [self::VARIANT_WITHOUT_A_PRICE];
            }
        }

        return [self::VARIANT_NOT_FOUND];
    }

    /** @return list<string> */
    private function imagesOf(object $product): array
    {
        $decoded = json_decode((string) ($product->images ?? ''), true);
        $images = [];
        foreach (is_array($decoded) ? $decoded : [] as $entry) {
            $image = is_array($entry) ? ($entry['image_name'] ?? $entry['image'] ?? null) : $entry;
            if (is_string($image) && trim($image) !== '') {
                $images[] = $image;
            }
        }

        return $images;
    }

    /** @return list<array<string, mixed>> */
    private function variationsOf(object $product): array
    {
        $decoded = json_decode((string) ($product->variation ?? ''), true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /** @return list<string> */
    private function categoryIdsOf(object $product): array
    {
        $decoded = json_decode((string) ($product->category_ids ?? ''), true);
        $ids = [];
        foreach (is_array($decoded) ? $decoded : [] as $entry) {
            $id = is_array($entry) ? ($entry['id'] ?? null) : $entry;
            if ($id !== null && (string) $id !== '') {
                $ids[] = (string) $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
