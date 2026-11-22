<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The world the money-and-SEO tests look at: the storefront money world (six markets, their currencies, the platform's own settings)
 * plus a catalogue whose products belong to a country, so the page, the facets, the sitemap and the API all read the same rows.
 *
 * The price column holds more decimals than any currency needs, as the platform's own migration does, so a dinar price of three
 * decimals is stored as it was typed and nothing in these tests can hide a rounding change.
 */
trait BuildsStorefrontSeoWorld
{
    use BuildsStorefrontMoneyWorld;

    protected function buildStorefrontSeoWorld(): void
    {
        $this->buildStorefrontMoneyWorld();

        foreach (['products', 'brands', 'categories', 'sellers', 'translations', 'reviews'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('added_by')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('product_type')->default('physical');
            $table->boolean('status')->default(true);
            $table->boolean('request_status')->default(true);
            $table->integer('current_stock')->default(0);
            $table->decimal('unit_price', 40, 20)->default(0);
            $table->decimal('discount', 40, 20)->default(0);
            $table->string('discount_type')->nullable();
            $table->string('code')->nullable();
            $table->string('barcode')->nullable();
            $table->text('details')->nullable();
            $table->string('storefront_country', 2)->nullable()->index();
            $table->timestamps();
        });
        Schema::create('brands', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->unsignedBigInteger('parent_id')->default(0);
            $table->integer('position')->default(0);
            $table->integer('priority')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        Schema::create('sellers', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->nullable();
            $table->softDeletes();
        });
        Schema::create('translations', function (Blueprint $table): void {
            $table->id();
            $table->string('translationable_type');
            $table->unsignedBigInteger('translationable_id');
            $table->string('locale');
            $table->string('key');
            $table->text('value')->nullable();
        });
        Schema::create('reviews', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->boolean('status')->default(true);
        });

        DB::table('brands')->insert(['id' => 1, 'name' => 'Lavkoo Care', 'slug' => 'lavkoo-care', 'status' => 1]);
        DB::table('categories')->insert(['id' => 1, 'name' => 'Skin care', 'slug' => 'skin-care', 'parent_id' => 0, 'position' => 0, 'status' => 1]);

        $this->setBusinessSettings([
            'product_brand' => '1',
            'digital_product' => '0',
            'business_mode' => 'multi',
            'currency_symbol_position' => 'left',
            'language' => json_encode([['id' => 1, 'code' => 'en', 'status' => 1], ['id' => 2, 'code' => 'ar', 'status' => 1]]),
        ]);

        config(['app.url' => 'https://lavkoo.test']);
        $this->app['url']->forceRootUrl('https://lavkoo.test');
        $this->app['url']->forceScheme('https');
        Cache::flush();
    }

    protected function dropStorefrontSeoWorld(): void
    {
        foreach (['products', 'brands', 'categories', 'sellers', 'translations', 'reviews'] as $table) {
            Schema::dropIfExists($table);
        }

        $this->dropStorefrontMoneyWorld();
    }

    /** @param array<string, string> $settings */
    protected function setBusinessSettings(array $settings): void
    {
        foreach ($settings as $type => $value) {
            DB::table('business_settings')->updateOrInsert(['type' => $type], ['value' => $value]);
        }

        clearWebConfigCacheKeys();
        Cache::forget(CACHE_BUSINESS_SETTINGS_TABLE);
    }

    protected function addProduct(int $id, string $name, string $slug, float|string $price, ?string $country, array $extra = []): void
    {
        DB::table('products')->insert(array_merge([
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
            'added_by' => 'admin',
            'brand_id' => 1,
            'category_id' => 1,
            'product_type' => 'physical',
            'status' => 1,
            'request_status' => 1,
            'current_stock' => 7,
            'unit_price' => $price,
            'discount' => 0,
            'discount_type' => 'flat',
            'storefront_country' => $country,
        ], $extra));
    }
}
