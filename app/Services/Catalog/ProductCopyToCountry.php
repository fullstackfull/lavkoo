<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Services\AuditLogger;
use App\Services\Storefront\ProductCountry;
use App\Services\Storefront\StorefrontCatalog;
use App\Traits\FileManagerTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Putting the same product in a second country, deliberately and once (GCC-14b slice 3, B-07).
 *
 * The plan's rule is that catalogue products stay independent per country (GCC-08, GCC-18): no shared stock, no price sync, nothing created
 * automatically. So a copy is a **new product row of its own**, and the only thing it inherits is what the product *is* — its name, its
 * description, its categories, its attributes and its variants' shapes. What a copy never inherits:
 *
 * - **Stock.** The copy starts at zero, and every variant's quantity starts at zero. Stock is physical and sits in one country.
 * - **Price.** The copy starts at zero — the unit price, the purchase price, the discount and every variant's price — because the source's
 *   price is in the source country's currency, and carrying it would read as "this is the price here" when nobody has said so. The copy is
 *   created **unpublished** so a product priced at zero cannot be sold before an admin prices it, and the admin lands on its edit form.
 * - **Its source's image files.** Every image is copied byte for byte to a file of its own (see `FileManagerTrait::copyStoredFile`). Sharing
 *   one file between two countries' products means deleting the source's image breaks the copy.
 * - **Anything a person did.** Reviews, wishlists, carts and order history belong to the row they were made against, and channel listings,
 *   routing rules and provider mappings are keyed by the product id. None of them is copied, and no link between the twins is written.
 *
 * The code and the barcode are kept **only when they are free in the target country**, which is the rule the forms use; when they are taken
 * there the copy is created without them, for the admin to type, exactly as the gallery's "use this product info" does today.
 *
 * Dormant with the country-scoped codes switch: with it off the action is not offered and refuses if it is called.
 */
final class ProductCopyToCountry
{
    use FileManagerTrait;

    /** Where each kind of image lives, and the column that remembers which disk it was written to. */
    private const IMAGE_FILES = [
        'thumbnail' => ['product/thumbnail/', 'thumbnail_storage_type'],
        'meta_image' => ['product/meta/', null],
        'preview_file' => ['product/preview/', 'preview_file_storage_type'],
        'digital_file_ready' => ['product/digital-product/', 'digital_file_ready_storage_type'],
    ];

    /** The columns that are the source's own history or the source's own numbers, and are never carried into another country. */
    private const NOT_CARRIED = [
        'current_stock' => 0,
        'unit_price' => 0,
        'purchase_price' => 0,
        'discount' => 0,
        'status' => 0,
        'featured_status' => 0,
        'featured' => 0,
        'is_shipping_cost_updated' => 0,
        'temp_shipping_cost' => 0,
        'denied_note' => null,
    ];

    public function __construct(
        private readonly ProductCodeScope $codeScope,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /** The action exists only once codes are country-scoped: before that every product is in one catalogue and a copy has no target. */
    public function isAvailable(): bool
    {
        return $this->codeScope->enabled() && ProductCountry::isTracked();
    }

    /**
     * The countries this product may be copied into: every configured one **except its own**. A copy into the source's country is not a copy,
     * it is a duplicate, and nothing in the plan asks for one.
     *
     * @return list<string>
     */
    public function targetsFor(Product $source): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $sourceCountry = $this->codeScope->countryOfProduct($source);

        return array_values(array_filter(ProductCountry::options(), static fn (string $country): bool => $country !== $sourceCountry));
    }

    /**
     * Copies the product into the target country and answers the new product.
     *
     * @throws ProductCopyRefused the action is not available, the target is not a configured country, or it is the source's own country
     */
    public function copy(Product $source, mixed $targetCountry): Product
    {
        $target = $this->targetOrRefuse(source: $source, targetCountry: $targetCountry);

        $attributes = $source->getAttributes();
        unset($attributes['id'], $attributes['created_at'], $attributes['updated_at']);

        [$images, $imagesNotCopied] = $this->copiedImages($source);
        $code = $this->keptWhenFree(value: $source->code, column: 'code', target: $target);
        $barcode = $this->keptWhenFree(value: $source->barcode, column: 'barcode', target: $target);

        $copy = new Product();
        $copy->forceFill($attributes);
        // Only ever write a column this row already has: an installation whose products table is older than one of these must not be given
        // a column by a copy.
        $copy->forceFill(array_intersect_key(self::NOT_CARRIED + $images + [
            StorefrontCatalog::COLUMN => $target,
            'code' => $code,
            'barcode' => $barcode,
            'slug' => $this->slugFor($source, $target),
            'variation' => $this->variationsWithoutStockOrPrice($source->getAttributes()['variation'] ?? null),
        ], $attributes));

        // A copy nobody can see a record of is the thing this criterion forbids, so the row and its audit line are written together or not at all.
        return DB::transaction(function () use ($copy, $source, $target, $code, $barcode, $imagesNotCopied): Product {
            $copy->save();

            $this->auditLogger->recordOrFail(
                action: 'catalog.product.copied_to_country',
                subject: $copy,
                context: [
                    'source_product_id' => (int) $source->id,
                    'source_country' => $this->codeScope->countryOfProduct($source),
                    'target_country' => $target,
                    'code_kept' => $code !== null && $code === $source->code,
                    'barcode_kept' => $barcode !== null && $barcode === $source->barcode,
                    'stock_and_price_carried' => false,
                    'images_not_copied' => $imagesNotCopied,
                ],
            );

            return $copy;
        });
    }

    private function targetOrRefuse(Product $source, mixed $targetCountry): string
    {
        if (!$this->isAvailable()) {
            throw ProductCopyRefused::notAvailable();
        }

        if (!is_string($targetCountry) || !in_array($targetCountry, ProductCountry::options(), true)) {
            throw ProductCopyRefused::noTarget();
        }

        if ($targetCountry === $this->codeScope->countryOfProduct($source)) {
            throw ProductCopyRefused::theSourceCountry($targetCountry);
        }

        return $targetCountry;
    }

    /** The code or the barcode when nothing in the target country holds it, and nothing when something does: the admin types a new one there. */
    private function keptWhenFree(mixed $value, string $column, string $target): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $taken = $column === 'code'
            ? $this->codeScope->isCodeTaken(code: $value, country: $target)
            : $this->codeScope->isBarcodeTaken(barcode: $value, country: $target);

        return $taken ? null : $value;
    }

    /**
     * Every image of the copy is a file of its own. The gallery lists (`images`, `color_image`) hold a name and the disk it was written to;
     * the single files hold a name and remember the disk in a column of their own.
     *
     * @return array{0: array<string, mixed>, 1: list<string>} the columns to write, and the kinds whose source file could not be read
     */
    private function copiedImages(Product $source): array
    {
        $columns = [];
        $notCopied = [];

        foreach (self::IMAGE_FILES as $column => [$directory, $storageColumn]) {
            $copied = $this->copyStoredFile(dir: $directory, fileName: $source->getAttributes()[$column] ?? null);
            $hadOne = ($source->getAttributes()[$column] ?? null) !== null && ($source->getAttributes()[$column] ?? '') !== '';
            $columns[$column] = $copied['name'] ?? null;
            if ($storageColumn !== null) {
                $columns[$storageColumn] = $copied['storage'] ?? null;
            }
            if ($hadOne && $copied === null) {
                $notCopied[] = $column;
            }
        }

        foreach (['images', 'color_image'] as $column) {
            [$columns[$column], $missing] = $this->copiedImageList($source->getAttributes()[$column] ?? null);
            if ($missing > 0) {
                $notCopied[] = $column;
            }
        }

        return [$columns, $notCopied];
    }

    /** @return array{0: string, 1: int} the rewritten list, and how many of its files could not be read */
    private function copiedImageList(mixed $stored): array
    {
        $entries = json_decode(is_string($stored) ? $stored : '', true);
        if (!is_array($entries)) {
            return [(string) json_encode([]), 0];
        }

        $missing = 0;
        $copies = [];
        foreach ($entries as $entry) {
            $name = is_array($entry) ? ($entry['image_name'] ?? null) : $entry;
            $copied = $this->copyStoredFile(dir: 'product/', fileName: is_string($name) ? $name : null);
            if ($copied === null) {
                $missing++;

                continue;
            }
            $copies[] = is_array($entry)
                ? ['image_name' => $copied['name'], 'storage' => $copied['storage']] + array_diff_key($entry, ['image_name' => null, 'storage' => null])
                : $copied['name'];
        }

        return [(string) json_encode($copies), $missing];
    }

    /** The variants of the copy: the same shapes and the same SKUs, with no quantity and no price, because neither crosses a border. */
    private function variationsWithoutStockOrPrice(mixed $stored): string
    {
        $variants = json_decode(is_string($stored) ? $stored : '', true);
        if (!is_array($variants)) {
            return (string) json_encode([]);
        }

        return (string) json_encode(array_map(
            static fn ($variant) => is_array($variant) ? ['price' => 0, 'qty' => 0] + $variant : $variant,
            $variants,
        ));
    }

    /** A slug names one product; the copy is another product, so it gets one of its own, built the way the add form builds it. */
    private function slugFor(Product $source, string $target): string
    {
        return Str::slug((string) ($source->getAttributes()['name'] ?? 'product'), '-') . '-' . strtolower($target) . '-' . Str::random(6);
    }
}
