<?php

namespace Tests\Feature\Storefront;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * A product asked for by its slug or id answers only in the storefront that owns it. The product lists already follow the storefront;
 * these single-product reads must not hand another country's price, stock or reviewers to a customer who knows a slug or an id.
 */
class StorefrontByIdReadsTest extends TestCase
{
    use BuildsStorefrontCatalog;

    private const SAUDI_SLUG = 'vitamin-serum-sa';

    private const KUWAIT_SLUG = 'vitamin-serum-kw';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontCatalogTables();
        $this->runStorefrontCountryMigration();
        $this->buildTablesTheSingleProductReadsLoad();

        $this->insertCatalogProduct(id: 1, name: 'Vitamin serum', price: 40, stock: 12);
        $this->insertCatalogProduct(id: 2, name: 'Vitamin serum', price: 9.5, stock: 7, country: 'KW');
        DB::table('products')->where('id', 1)->update(['slug' => self::SAUDI_SLUG]);
        DB::table('products')->where('id', 2)->update(['slug' => self::KUWAIT_SLUG]);

        foreach ([1 => 5, 2 => 3] as $productId => $rating) {
            DB::table('reviews')->insert(['product_id' => $productId, 'customer_id' => 9, 'rating' => $rating, 'comment' => 'review of ' . $productId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('users')->insert(['id' => 9, 'f_name' => 'Reviewer']);

        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.KW.active' => true]);
    }

    private function buildTablesTheSingleProductReadsLoad(): void
    {
        foreach (['slug'] as $column) {
            Schema::table('products', fn ($table) => $table->string($column)->nullable());
        }
        foreach (['translations', 'reviews', 'users', 'storages', 'review_replies', 'order_details', 'wishlists', 'tags', 'product_tag', 'stock_clearance_products', 'stock_clearance_setups'] as $table) {
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
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->integer('rating')->default(5);
            $table->text('comment')->nullable();
            $table->text('attachment')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('image')->nullable();
        });
        Schema::create('storages', function ($table) {
            $table->id();
            $table->string('data_type');
            $table->unsignedBigInteger('data_id');
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });
        Schema::create('review_replies', function ($table) {
            $table->id();
            $table->unsignedBigInteger('review_id');
            $table->text('reply_text')->nullable();
        });
        Schema::create('order_details', function ($table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->integer('qty')->default(1);
            $table->string('delivery_status')->nullable();
        });
        Schema::create('wishlists', function ($table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('customer_id')->nullable();
        });
        Schema::create('tags', function ($table) {
            $table->id();
            $table->string('tag')->nullable();
        });
        Schema::create('product_tag', function ($table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('tag_id');
        });
        Schema::create('stock_clearance_products', function ($table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('setup_id')->nullable();
            $table->boolean('is_active')->default(false);
            $table->string('added_by')->nullable();
        });
        Schema::create('stock_clearance_setups', function ($table) {
            $table->id();
            $table->boolean('is_active')->default(false);
            $table->date('duration_start_date')->nullable();
            $table->date('duration_end_date')->nullable();
            $table->string('offer_active_time')->nullable();
            $table->time('offer_active_range_start')->nullable();
            $table->time('offer_active_range_end')->nullable();
        });
    }

    /** A request the way the app sends it: a storefront header, no session. */
    private function asStorefront(string $country): static
    {
        return $this->withHeader('X-Storefront', $country);
    }

    public function test_the_reviews_of_a_product_are_not_served_in_another_storefront(): void
    {
        $this->asStorefront('SA')->getJson('/api/v1/products/reviews/' . self::KUWAIT_SLUG . '?limit=10&offset=1')
            ->assertNotFound()
            ->assertJsonPath('errors.code', 'product-001');
    }

    public function test_the_reviews_of_a_product_are_served_in_its_own_storefront(): void
    {
        $this->asStorefront('KW')->getJson('/api/v1/products/reviews/' . self::KUWAIT_SLUG . '?limit=10&offset=1')
            ->assertOk()
            ->assertJsonPath('total_size', 1)
            ->assertJsonPath('reviews.0.comment', 'review of 2');
    }

    public function test_related_products_are_not_listed_for_a_product_of_another_storefront(): void
    {
        $this->asStorefront('SA')->getJson('/api/v1/products/related-products/' . self::KUWAIT_SLUG . '?guest_id=7001')
            ->assertNotFound()
            ->assertJsonPath('errors.code', 'product-001');
    }

    public function test_the_rating_of_a_product_is_not_served_in_another_storefront(): void
    {
        $this->asStorefront('KW')->getJson('/api/v1/products/rating/2')->assertOk()->assertExactJson([3]);
        $this->asStorefront('SA')->getJson('/api/v1/products/rating/2')->assertOk()->assertExactJson([0]);
    }

    public function test_the_counts_and_the_share_link_of_a_product_are_not_served_in_another_storefront(): void
    {
        $this->asStorefront('SA')->getJson('/api/v1/products/counter/' . self::KUWAIT_SLUG)->assertStatus(403);
        $this->asStorefront('SA')->getJson('/api/v1/products/social-share-link/' . self::KUWAIT_SLUG)->assertStatus(403);
        $this->asStorefront('KW')->getJson('/api/v1/products/counter/' . self::KUWAIT_SLUG)->assertOk()->assertJsonPath('order_count', 0);
    }

    public function test_while_storefronts_are_off_every_product_answers_as_before(): void
    {
        config(['gcc.storefronts.enabled' => false]);

        $this->asStorefront('SA')->getJson('/api/v1/products/reviews/' . self::KUWAIT_SLUG . '?limit=10&offset=1')->assertOk();
        $this->asStorefront('SA')->getJson('/api/v1/products/rating/2')->assertOk()->assertExactJson([3]);
        $this->assertSame(2, Product::query()->count());
    }
}
