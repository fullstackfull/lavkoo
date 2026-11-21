<?php

use App\Services\Catalog\ProductCodeIndex;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The database refuses two products of one country that share a SKU or a barcode (B-04).
 *
 * Additive and guarded. It creates nothing unless `products.storefront_country` is there (the 2026_11_25_100000 migration), and
 * `ProductCodeIndex::create()` refuses — writing nothing — when the live catalogue already holds a value twice inside one country.
 * It never deletes, merges or edits a row to make an index fit: which of a pair of duplicates to keep is the owner's decision, taken
 * with `php artisan gcc:catalog:code-readiness` in hand. An operator who meets the refusal has lost nothing: the branch's gates refuse
 * the same duplicates at the application level whether or not the index exists.
 *
 * A code or a barcode that is NULL or empty is outside the index: see ProductCodeIndex for the rule and how each engine expresses it.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn(ProductCodeIndex::TABLE, StorefrontCatalog::COLUMN)) {
            return;
        }

        foreach (ProductCodeIndex::kinds() as $kind) {
            ProductCodeIndex::create(kind: $kind);
        }
    }

    /** Dropping the index loses no data: the gates keep refusing the duplicates it refused. */
    public function down(): void
    {
        foreach (ProductCodeIndex::kinds() as $kind) {
            ProductCodeIndex::drop(kind: $kind);
        }
    }
};
