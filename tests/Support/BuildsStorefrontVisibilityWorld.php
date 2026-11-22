<?php

namespace Tests\Support;

use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use App\Services\Storefront\StorefrontVisibility;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The catalogue a storefront may hide from or be assigned: the store tables the readers go through, the mapping table of B-13/B-15/B-16,
 * and two categories, two brands, two banners, two coupons and two campaigns — one of each left alone, one of each mapped by a test.
 */
trait BuildsStorefrontVisibilityWorld
{
    use BuildsProductCatalog;
    use BuildsStorefrontCouponWorld;

    protected const SHARED_CATEGORY = 10;

    protected const MAPPED_CATEGORY = 30;

    protected const SHARED_BRAND = 1;

    protected const MAPPED_BRAND = 2;

    protected function buildStorefrontVisibilityWorld(): void
    {
        $this->buildCatalogTables();
        $this->buildStorefrontCouponWorld();
        $this->buildExperienceCampaignTable();
        $this->buildPromotionTablesTheReadersNeed();
        $this->widenTheTablesTheStorefrontReadersSelectFrom();
        $this->runProductCountryMigration();
        $this->runStorefrontVisibilityMigration();

        StorefrontVisibility::flush();
        Cache::flush();
    }

    /** Products already carry their own country on this line; a brand carries none, which is what B-11 pins. */
    protected function runProductCountryMigration(): void
    {
        (require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php'))->up();
        StorefrontCatalog::flush();
    }

    protected function runStorefrontVisibilityMigration(): void
    {
        Schema::dropIfExists(StorefrontVisibility::TABLE);
        $this->storefrontVisibilityMigration()->up();
        StorefrontVisibility::flush();
    }

    protected function storefrontVisibilityMigration(): object
    {
        return require database_path('migrations/2026_12_08_100000_create_storefront_entity_visibility_table.php');
    }

    /** Six storefronts configured, KW open beside SA: the switch itself stays in each test's hands. */
    protected function switchStorefrontsOn(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.KW.active' => true]);
    }

    /** Serves the rest of the test from this storefront, as the middleware does for a request. */
    protected function serveStorefront(string $country): void
    {
        app()->instance(StorefrontContext::class, StorefrontResolver::fromConfig()->context(explicitChoice: $country));
    }

    /** Back to a request that belongs to no storefront: a panel, a seller API, a queue or a console command. */
    protected function serveNoStorefront(): void
    {
        app()->forgetInstance(StorefrontContext::class);
    }

    protected function seedStorefrontVisibilityWorld(): void
    {
        DB::table('categories')->insert([
            ['id' => self::SHARED_CATEGORY, 'name' => 'Everywhere', 'slug' => 'everywhere', 'parent_id' => 0, 'position' => 0, 'home_status' => 1, 'priority' => 1],
            ['id' => self::MAPPED_CATEGORY, 'name' => 'Mapped', 'slug' => 'mapped', 'parent_id' => 0, 'position' => 0, 'home_status' => 1, 'priority' => 2],
        ]);
        DB::table('brands')->insert([
            ['id' => self::SHARED_BRAND, 'name' => 'Everywhere brand', 'slug' => 'everywhere-brand', 'status' => 1, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()],
            ['id' => self::MAPPED_BRAND, 'name' => 'Mapped brand', 'slug' => 'mapped-brand', 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->insertProduct(id: 1, name: 'Shared lamp', category: self::SHARED_CATEGORY, subCategory: null, extra: ['brand_id' => self::SHARED_BRAND]);
        $this->insertProduct(id: 2, name: 'Mapped lamp', category: self::MAPPED_CATEGORY, subCategory: null, extra: ['brand_id' => self::MAPPED_BRAND]);
        $this->insertProduct(id: 3, name: 'Disabled mapped lamp', status: 0, category: self::MAPPED_CATEGORY, subCategory: null, extra: ['brand_id' => self::MAPPED_BRAND]);

        $this->insertBanner(id: 1, title: 'Everywhere banner');
        $this->insertBanner(id: 2, title: 'Mapped banner');

        $this->giveCoupon(code: 'EVERYWHERE', country: null);
        $this->giveCoupon(code: 'MAPPED', country: null);

        $this->insertCampaign(id: 1, name: 'Everywhere campaign', slot: 'top');
        $this->insertCampaign(id: 2, name: 'Mapped campaign', slot: 'bottom');
    }

    protected function insertBanner(int $id, string $title, string $type = 'Home Promo Banner'): void
    {
        DB::table('banners')->insert([
            'id' => $id,
            'title' => $title,
            'banner_type' => $type,
            'theme' => theme_root_path() ?? 'default',
            'published' => 1,
            'priority' => $id,
            'resource_type' => 'category',
            'resource_id' => self::SHARED_CATEGORY,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function insertCampaign(int $id, string $name, string $slot, string $page = 'home'): void
    {
        DB::table('experience_campaigns')->insert([
            'id' => $id,
            'name' => $name,
            'status' => 'active',
            'page' => $page,
            'priority' => $id,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'overrides' => json_encode([['slot' => $slot, 'section' => ['type' => 'promo', 'settings' => ['name' => $name]]]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** The deal and clearance rows B-16 limits per storefront, with the columns their own readers select. */
    protected function seedStorefrontPromotions(): void
    {
        DB::table('flash_deals')->insert([
            ['id' => 1, 'title' => 'Everywhere deal', 'deal_type' => 'flash_deal', 'status' => 1, 'start_date' => now()->subDay(), 'end_date' => now()->addDay()],
            ['id' => 2, 'title' => 'Mapped deal', 'deal_type' => 'flash_deal', 'status' => 1, 'start_date' => now()->subDay(), 'end_date' => now()->addDay()],
        ]);
        DB::table('deal_of_the_days')->insert([
            ['id' => 1, 'title' => 'Everywhere deal of the day', 'product_id' => 1, 'status' => 1],
            ['id' => 2, 'title' => 'Mapped deal of the day', 'product_id' => 2, 'status' => 1],
        ]);
        DB::table('stock_clearance_setups')->insert([
            ['id' => 1, 'setup_by' => 'admin', 'shop_id' => 1, 'is_active' => 1, 'discount_type' => 'flat', 'discount_amount' => 1],
            ['id' => 2, 'setup_by' => 'admin', 'shop_id' => 2, 'is_active' => 1, 'discount_type' => 'flat', 'discount_amount' => 2],
        ]);
    }

    private function buildPromotionTablesTheReadersNeed(): void
    {
        Schema::dropIfExists('deal_of_the_days');
        Schema::create('deal_of_the_days', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->decimal('discount', 24, 2)->default(0);
            $table->string('discount_type')->default('flat');
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        Schema::table('stock_clearance_setups', function (Blueprint $table): void {
            $table->string('setup_by')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->string('discount_type')->nullable();
            $table->decimal('discount_amount', 24, 2)->default(0);
            $table->boolean('show_in_homepage')->default(false);
            $table->boolean('show_in_shop')->default(false);
        });
    }

    private function buildExperienceCampaignTable(): void
    {
        Schema::dropIfExists('experience_campaigns');
        Schema::create('experience_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('status')->default('draft');
            $table->string('page')->default('home');
            $table->integer('priority')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->text('overrides')->nullable();
            $table->timestamps();
        });
    }

    /** The columns the storefront answers select but the catalog builder does not need: the category icons, and the brand timestamps the brand list is ordered by. */
    private function widenTheTablesTheStorefrontReadersSelectFrom(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->string('icon')->nullable();
            $table->string('mobile_icon')->nullable();
            $table->string('icon_storage_type')->nullable();
            $table->boolean('auction_home_status')->default(false);
        });
        Schema::table('brands', function (Blueprint $table): void {
            $table->timestamps();
        });
    }
}
