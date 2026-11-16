<?php

namespace Tests\Feature\Storefront;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * A banner belongs to no country, but the product it points at does. A storefront is shown the banners whose product it may sell, and never
 * another country's price or stock inside a banner. While storefronts are off every banner is answered as before.
 */
class StorefrontBannerTest extends TestCase
{
    use BuildsStorefrontCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontCatalogTables();
        $this->buildProductRelationTables();
        $this->buildTheBannerTables();
        $this->runStorefrontCountryMigration();

        $this->insertCatalogProduct(id: 1, name: 'Saudi serum', price: 40, stock: 12);
        $this->insertCatalogProduct(id: 2, name: 'Kuwaiti serum', price: 9, stock: 7, country: 'KW');
        $this->bannerFor(productId: 1);
        $this->bannerFor(productId: 2);
        $this->bannerFor(productId: 2);

        config(['gcc.storefronts.countries.KW.active' => true]);
        Cache::flush();
    }

    private function buildTheBannerTables(): void
    {
        foreach (['products', 'colors', 'storages', 'banners'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('products', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->string('code')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('added_by')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->string('product_type')->default('physical');
            $table->boolean('status')->default(true);
            $table->boolean('request_status')->default(true);
            $table->integer('current_stock')->default(0);
            $table->decimal('unit_price', 24, 2)->default(0);
            $table->decimal('discount', 24, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->text('colors')->nullable();
            $table->text('category_ids')->nullable();
            $table->text('choice_options')->nullable();
            $table->text('variation')->nullable();
            $table->text('images')->nullable();
            $table->text('color_image')->nullable();
            $table->string('thumbnail')->nullable();
            $table->timestamps();
        });
        Schema::create('colors', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->timestamps();
        });
        Schema::create('storages', function ($table) {
            $table->id();
            $table->string('data_type');
            $table->unsignedBigInteger('data_id');
            $table->string('key');
            $table->string('value')->nullable();
            $table->timestamps();
        });
        Schema::create('banners', function ($table) {
            $table->id();
            $table->string('photo')->nullable();
            $table->string('mobile_photo')->nullable();
            $table->string('banner_type')->nullable();
            $table->string('layout')->nullable();
            $table->integer('priority')->nullable();
            $table->string('theme')->default('default');
            $table->boolean('published')->default(true);
            $table->string('url')->nullable();
            $table->string('resource_type')->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('title')->nullable();
            $table->string('sub_title')->nullable();
            $table->string('button_text')->nullable();
            $table->string('background_color')->nullable();
            $table->timestamps();
        });
    }

    private function bannerFor(int $productId): void
    {
        DB::table('banners')->insert([
            'banner_type' => 'Main Banner', 'theme' => 'default', 'published' => 1, 'resource_type' => 'product', 'resource_id' => $productId,
            'url' => '', 'title' => 'serum', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return list<int|null> the product each banner points at */
    private function bannerProducts(string $storefront): array
    {
        Cache::flush();
        $this->serveAsHttpRequest('/api/v1/banners');

        return collect($this->withHeader('X-Storefront', $storefront)->getJson('/api/v1/banners')->assertOk()->json())
            ->map(fn (array $banner) => $banner['resource_id'])
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function test_a_storefront_is_shown_only_the_banners_of_products_it_may_sell(): void
    {
        config(['gcc.storefronts.enabled' => true]);

        $this->assertSame([1], $this->bannerProducts('SA'));
        $this->assertSame([2, 2], $this->bannerProducts('KW'));
    }

    public function test_the_price_and_stock_of_another_countrys_product_are_never_inlined_in_a_banner(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $this->serveAsHttpRequest('/api/v1/banners');

        $body = $this->withHeader('X-Storefront', 'SA')->getJson('/api/v1/banners')->assertOk()->getContent();

        $this->assertStringNotContainsString('Kuwaiti serum', $body);
        $this->assertStringContainsString('Saudi serum', $body);
    }

    public function test_while_storefronts_are_off_every_banner_is_answered_as_before(): void
    {
        $this->assertSame([1, 2, 2], collect($this->bannerProducts('SA'))->sort()->values()->all());
    }
}
