<?php

namespace Tests\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionProperty;

/** The few tables the product visibility scope reads, and a helper that adds a product to them. */
trait BuildsStorefrontCatalog
{
    protected function buildStorefrontCatalogTables(): void
    {
        foreach (['business_settings', 'products', 'sellers', 'brands'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('business_settings', function ($table) {
            $table->id();
            $table->string('type');
            $table->text('value')->nullable();
            $table->timestamps();
        });
        foreach (['product_brand' => '1', 'digital_product' => '0', 'business_mode' => 'multi'] as $type => $value) {
            DB::table('business_settings')->insert(['type' => $type, 'value' => $value]);
        }

        Schema::create('products', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('added_by')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->string('product_type')->default('physical');
            $table->boolean('status')->default(true);
            $table->boolean('request_status')->default(true);
            $table->integer('current_stock')->default(0);
            $table->decimal('unit_price', 24, 2)->default(0);
        });
        Schema::create('sellers', function ($table) {
            $table->id();
            $table->string('status')->nullable();
            $table->softDeletes();
        });
        Schema::create('brands', function ($table) {
            $table->id();
            $table->boolean('status')->default(true);
        });
    }

    /** The two relations every loaded Product brings along, for a test that reads products through the model. */
    protected function buildProductRelationTables(): void
    {
        foreach (['translations', 'reviews'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('translations', function ($table) {
            $table->id();
            $table->string('translationable_type');
            $table->unsignedBigInteger('translationable_id');
            $table->string('locale');
            $table->string('key');
            $table->text('value')->nullable();
        });
        Schema::create('reviews', function ($table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->boolean('status')->default(true);
        });
    }

    /**
     * A web request that no storefront middleware has reached yet, as when the providers boot: the container holds the request and no
     * StorefrontContext. The test process is a console, so the console flag is turned off for this application.
     */
    protected function serveAsHttpRequest(string $uri, array $headers = []): void
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $this->app->instance('request', Request::create($uri, 'GET', [], [], [], $server));
        (new ReflectionProperty($this->app, 'isRunningInConsole'))->setValue($this->app, false);
    }

    /** Adds the column the migration adds, the way the migration adds it. */
    protected function runStorefrontCountryMigration(): void
    {
        $migration = require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php');
        $migration->up();
    }

    /** The database as it is before the migration has run. */
    protected function removeStorefrontCountryColumn(): void
    {
        $migration = require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php');
        $migration->down();
    }

    protected function insertCatalogProduct(int $id, string $name, float $price = 10, int $stock = 5, ?string $country = null): void
    {
        DB::table('products')->insert(array_filter([
            'id' => $id,
            'name' => $name,
            'added_by' => 'admin',
            'status' => 1,
            'request_status' => 1,
            'current_stock' => $stock,
            'unit_price' => $price,
            'storefront_country' => $country,
        ], static fn ($value) => $value !== null));
    }
}
