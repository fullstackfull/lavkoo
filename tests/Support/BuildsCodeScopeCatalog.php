<?php

namespace Tests\Support;

use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The product table the SKU and barcode tests need: only the columns a code lookup, a seller scan and a bulk import touch. Like the other
 * schema builders it is INFERRED from the models and the code that reads it (the installation dump is not in the repository), so it proves
 * the logic and not the live schema. Tables are dropped before they are built: on a real engine the database must be a throwaway one.
 */
trait BuildsCodeScopeCatalog
{
    protected function buildCodeScopeProducts(bool $withCountryColumn = true): void
    {
        Schema::dropIfExists('products');
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->string('code')->nullable();
            $table->string('barcode')->nullable();
            $table->string('added_by')->default('admin');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('product_type')->default('physical');
            $table->boolean('status')->default(true);
            $table->boolean('request_status')->default(true);
            $table->decimal('unit_price', 24, 2)->default(10);
            $table->text('variation')->nullable();
            $table->string('fulfillment_provider')->nullable();
            $table->timestamps();
        });

        if ($withCountryColumn) {
            $this->addStorefrontCountryColumn();
        }
        StorefrontCatalog::flush();
    }

    /** The two relations every loaded Product brings along, and the settings table the model reads while it loads. */
    protected function buildCodeScopeRelationTables(): void
    {
        foreach (['translations', 'reviews', 'business_settings'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->text('value')->nullable();
            $table->timestamps();
        });
        foreach (['product_brand' => '1', 'digital_product' => '0', 'business_mode' => 'multi'] as $type => $value) {
            DB::table('business_settings')->insert(['type' => $type, 'value' => $value]);
        }

        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->string('translationable_type');
            $table->unsignedBigInteger('translationable_id');
            $table->string('locale');
            $table->string('key');
            $table->text('value')->nullable();
        });
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->boolean('status')->default(true);
        });
    }

    /** Adds the column the way the migration adds it. */
    protected function addStorefrontCountryColumn(): void
    {
        (require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php'))->up();
        StorefrontCatalog::flush();
    }

    protected function dropStorefrontCountryColumn(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('storefront_country'));
        StorefrontCatalog::flush();
    }

    protected function scopeCodesByCountry(bool $on = true): void
    {
        config(['gcc.catalog.country_scoped_codes' => $on]);
    }

    /** @param array<string, mixed> $extra */
    protected function insertCodedProduct(string $code, string $country = 'SA', ?string $barcode = null, array $extra = []): int
    {
        $row = array_merge(['name' => 'Product ' . $code, 'code' => $code, 'barcode' => $barcode], $extra);
        if (Schema::hasColumn('products', 'storefront_country')) {
            $row['storefront_country'] = $country;
        }

        return (int) DB::table('products')->insertGetId($row);
    }
}
