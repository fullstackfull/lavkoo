<?php

namespace App\Utils;

use App\Models\DigitalProductVariation;
use App\Models\Product;
use App\Models\ProductSeo;

/**
 * A product names its files in its own row (gallery, thumbnail, preview, meta image, digital file), and a name is only a claim: a copy of a
 * product shares the files of its original, and the add form and the seller apps post names that a seller can take from the storefront or
 * write with the folder in front of them (`thumbnail/x.webp`). A file that more than one product holds is not deleted when one of them lets
 * go of it, so a seller who adds a product holding another seller's file names cannot delete those files by removing them again.
 *
 * Every caller deletes before the product's own row changes, so the product that lets go of the file still holds it and counts once.
 */
final class SharedProductFile
{
    private const COLUMN_OF_FOLDER = [
        'thumbnail' => 'thumbnail',
        'preview' => 'preview_file',
        'meta' => 'meta_image',
        'digital-product' => 'digital_file_ready',
    ];

    /** Whether the file at a path of the disk (`product/x.webp`, `/product/thumbnail/x.webp`) is held by more than one product. */
    public static function isShared(string $path): bool
    {
        $relative = ltrim($path, '/');
        if (!str_starts_with($relative, 'product/')) {
            return false;
        }

        $relative = substr($relative, strlen('product/'));
        $name = basename($relative);
        if ($name === '' || str_ends_with($relative, '/')) {
            return false;
        }
        $folder = preg_match('#^(' . implode('|', array_keys(self::COLUMN_OF_FOLDER)) . ')/[^/]+$#D', $relative, $match) === 1 ? $match[1] : '';

        return count(self::holderIds(relative: $relative, folder: $folder, name: $name)) > 1;
    }

    /** @return list<int|string> */
    private static function holderIds(string $relative, string $folder, string $name): array
    {
        $holderIds = [];
        $column = self::COLUMN_OF_FOLDER[$folder] ?? null;
        $candidates = Product::withoutGlobalScopes()
            ->where(static function ($query) use ($name, $column) {
                $query->where('images', 'like', '%' . $name . '%')->orWhere('color_image', 'like', '%' . $name . '%');
                if ($column !== null) {
                    $query->orWhere($column, $name);
                }
            })
            ->get(array_filter(['id', 'images', 'color_image', $column]));

        foreach ($candidates as $product) {
            if (self::holds(product: $product, relative: $relative, column: $column, name: $name)) {
                $holderIds[$product->getKey()] = true;
            }
        }
        foreach (self::otherTablesHolding(folder: $folder, name: $name) as $productId) {
            $holderIds[$productId] = true;
        }

        return array_keys($holderIds);
    }

    private static function holds(Product $product, string $relative, ?string $column, string $name): bool
    {
        if ($column !== null && $product->getRawOriginal($column) === $name) {
            return true;
        }

        foreach (['images', 'color_image'] as $list) {
            foreach ((array) json_decode((string) $product->getRawOriginal($list), true) as $image) {
                if ((is_array($image) ? ($image['image_name'] ?? null) : $image) === $relative) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return iterable<int|string> the products whose SEO row or digital variation holds the file */
    private static function otherTablesHolding(string $folder, string $name): iterable
    {
        return match ($folder) {
            'meta' => ProductSeo::query()->where('image', $name)->pluck('product_id'),
            'digital-product' => DigitalProductVariation::query()->where('file', $name)->pluck('product_id'),
            default => [],
        };
    }
}
