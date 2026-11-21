<?php

use App\Services\Catalog\ProductCodeIndex;
use App\Services\Catalog\ProductCodeScope;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The database refuses two products of one country that share a SKU or a barcode (B-04).
 *
 * **Behind the country-scoped codes switch, deliberately.** `php artisan migrate` is this project's documented upgrade step, and this is an
 * ordinary migration, so without the gate deploying the branch is what applies the index. Measured on MariaDB 10.11.14 against a catalogue
 * holding ONE in-country duplicate: `ProductCodeIndex::create()` refuses, the exception escapes the command, `migrate` exits 1, **nothing is
 * written to the migrations table and every migration queued behind this one never runs**. A branch that ships dormant must not be able to
 * stop an upgrade that way, so `up()` creates nothing while the switch is off.
 *
 * How the index is applied, then:
 *
 * - a fresh installation that migrates with the switch already on gets it here;
 * - an installation that migrated while the switch was off (this migration is recorded, so it will not run again) applies it with
 *   `php artisan gcc:catalog:code-readiness --apply`, which refuses the same duplicates this migration refuses and changes no row either.
 *
 * Still additive and still guarded: it creates nothing unless `products.storefront_country` is there (the 2026_11_25_100000 migration), and
 * it never deletes, merges or edits a row to make an index fit -- which of a pair of duplicates to keep is the owner's decision, taken with
 * `php artisan gcc:catalog:code-readiness` in hand. An operator who meets the refusal has lost nothing: the branch's gates refuse the same
 * duplicates at the application level whether or not the index exists.
 *
 * A code or a barcode that is NULL or empty is outside the index: see ProductCodeIndex for the rule and how each engine expresses it.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn(ProductCodeIndex::TABLE, StorefrontCatalog::COLUMN) || !app(ProductCodeScope::class)->enabled()) {
            return;
        }

        foreach (ProductCodeIndex::kinds() as $kind) {
            ProductCodeIndex::create(kind: $kind);
        }
    }

    /** Dropping the index loses no data: the gates keep refusing the duplicates it refused. Ungated, so a rollback is never a no-op. */
    public function down(): void
    {
        foreach (ProductCodeIndex::kinds() as $kind) {
            ProductCodeIndex::drop(kind: $kind);
        }
    }
};
