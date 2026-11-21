<?php

namespace Tests\Feature\Storefront;

use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\DealOfTheDay;
use App\Models\FlashDeal;
use App\Models\StockClearanceSetup;
use App\Models\StorefrontEntityVisibility;
use App\Services\Commerce\CampaignResolver;
use App\Services\Storefront\StorefrontVisibility;
use App\Utils\BrandManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsStorefrontVisibilityWorld;
use Tests\TestCase;

/**
 * One mechanism decides what each storefront shows (B-11, B-13, B-15, B-16): a mapping that never touches the central row, with two
 * deliberately different defaults — a category or a brand is hidden by a row, a banner, a coupon or a campaign is assigned by one.
 *
 * With no mapping rows at all, every storefront shows exactly what it shows today, switch on or off, which is the state every
 * installation starts in and the state the live Saudi storefront runs in.
 */
class StorefrontCatalogueVisibilityTest extends TestCase
{
    use BuildsStorefrontVisibilityWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontVisibilityWorld();
        $this->seedStorefrontVisibilityWorld();
    }

    /** @return list<int> the categories the tree, the home page and the filters are built from */
    private function categoryIdsOfTheTree(): array
    {
        return Category::query()->where('position', 0)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int> the brands the brand list and the filters show */
    private function brandIdsOfTheList(): array
    {
        return Brand::active()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int> what the customer app's category list answers */
    private function categoryIdsOfTheApiList(): array
    {
        $categories = app(\App\Http\Controllers\RestAPI\v1\CategoryController::class)
            ->get_categories(Request::create('/api/v1/categories', 'GET'))
            ->getData(true);

        $ids = array_map(static fn (array $category): int => (int) $category['id'], $categories);
        sort($ids);

        return $ids;
    }

    /** @return list<int> what the customer app's brand list answers */
    private function brandIdsOfTheApiList(): array
    {
        $brands = app(\App\Http\Controllers\RestAPI\v1\BrandController::class)
            ->get_brands(Request::create('/api/v1/brands', 'GET', ['limit' => 50, 'offset' => 1]));

        $ids = collect($brands['brands'])->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        sort($ids);

        return $ids;
    }

    /** @return list<string> what the coupon list API answers */
    private function couponCodesOfTheApiList(): array
    {
        $list = app(\App\Http\Controllers\RestAPI\v1\CouponController::class)
            ->list(Request::create('/api/v1/coupon/list', 'GET', ['limit' => 50, 'offset' => 1]));

        return collect($list['coupons'])->pluck('code')->sort()->values()->all();
    }

    /** @return list<string> the names of the campaigns overlaying the home page */
    private function campaignNamesOverlayingHome(): array
    {
        Cache::flush();

        return collect(app(CampaignResolver::class)->overridesFor('home'))
            ->pluck('section.settings.name')
            ->sort()
            ->values()
            ->all();
    }

    private function everyAnswer(): array
    {
        return [
            'tree' => $this->categoryIdsOfTheTree(),
            'api_categories' => $this->categoryIdsOfTheApiList(),
            'brands' => $this->brandIdsOfTheList(),
            'banners' => Banner::query()->orderBy('id')->pluck('title')->all(),
            'coupons' => $this->couponCodesOfTheApiList(),
            'campaigns' => $this->campaignNamesOverlayingHome(),
        ];
    }

    public function test_an_empty_mapping_table_changes_nothing_while_the_storefront_switch_is_off(): void
    {
        $asShippedToday = $this->everyAnswer();

        $this->serveStorefront('SA');
        $this->assertSame($asShippedToday, $this->everyAnswer());

        $this->assertSame(0, DB::table(StorefrontVisibility::TABLE)->count(), 'the mapping table is empty');
    }

    public function test_an_empty_mapping_table_changes_nothing_with_the_storefront_switch_on(): void
    {
        $asShippedToday = $this->everyAnswer();

        $this->switchStorefrontsOn();

        foreach (['SA', 'KW'] as $country) {
            $this->serveStorefront($country);
            $this->assertSame($asShippedToday, $this->everyAnswer(), "storefront {$country} with an empty mapping table");
        }
    }

    public function test_while_no_mapping_row_exists_the_storefront_runs_the_query_it_runs_today(): void
    {
        $this->switchStorefrontsOn();
        $this->serveStorefront('SA');
        $withoutAnyMapping = Category::query()->where('position', 0)->toSql();

        $this->serveNoStorefront();
        $asItWasBeforeStorefronts = Category::query()->where('position', 0)->toSql();

        $this->assertSame($asItWasBeforeStorefronts, $withoutAnyMapping);

        StorefrontVisibility::set(entityType: StorefrontVisibility::CATEGORY, entityId: self::MAPPED_CATEGORY, countries: ['KW']);
        $this->serveStorefront('SA');

        $this->assertNotSame($asItWasBeforeStorefronts, Category::query()->where('position', 0)->toSql(), 'the predicate appears once a mapping exists');
    }

    public function test_a_category_hidden_for_kuwait_is_gone_from_its_tree_home_filters_and_page_and_stays_in_saudi_arabia(): void
    {
        $this->switchStorefrontsOn();
        StorefrontVisibility::set(entityType: StorefrontVisibility::CATEGORY, entityId: self::MAPPED_CATEGORY, countries: ['KW']);

        $this->serveStorefront('KW');
        $this->assertSame([self::SHARED_CATEGORY], $this->categoryIdsOfTheTree(), 'the Kuwaiti tree');
        $this->assertSame([self::SHARED_CATEGORY], $this->categoryIdsOfTheApiList(), 'the Kuwaiti app list');
        $this->assertSame([self::SHARED_CATEGORY], Category::query()->where('home_status', true)->pluck('id')->map(fn ($id) => (int) $id)->all(), 'the Kuwaiti home page');
        $this->assertSame([self::SHARED_CATEGORY], Category::query()->whereIn('id', [self::SHARED_CATEGORY, self::MAPPED_CATEGORY])->pluck('id')->map(fn ($id) => (int) $id)->all(), 'the Kuwaiti filter chips');
        $this->assertNull(Category::query()->where('slug', 'mapped')->first(), 'the Kuwaiti category page');

        $this->serveStorefront('SA');
        $this->assertSame([self::SHARED_CATEGORY, self::MAPPED_CATEGORY], $this->categoryIdsOfTheTree(), 'the Saudi tree');
        $this->assertSame([self::SHARED_CATEGORY, self::MAPPED_CATEGORY], $this->categoryIdsOfTheApiList(), 'the Saudi app list');
        $this->assertNotNull(Category::query()->where('slug', 'mapped')->first(), 'the Saudi category page');
    }

    public function test_removing_the_mapping_restores_the_category_and_the_central_row_never_changed(): void
    {
        $this->switchStorefrontsOn();
        $centralRow = (array) DB::table('categories')->where('id', self::MAPPED_CATEGORY)->first();

        StorefrontVisibility::set(entityType: StorefrontVisibility::CATEGORY, entityId: self::MAPPED_CATEGORY, countries: ['KW']);
        $this->serveStorefront('KW');
        $this->assertSame([self::SHARED_CATEGORY], $this->categoryIdsOfTheTree());
        $this->assertSame($centralRow, (array) DB::table('categories')->where('id', self::MAPPED_CATEGORY)->first(), 'the central row while hidden');

        StorefrontVisibility::set(entityType: StorefrontVisibility::CATEGORY, entityId: self::MAPPED_CATEGORY, countries: []);
        $this->serveStorefront('KW');

        $this->assertSame([self::SHARED_CATEGORY, self::MAPPED_CATEGORY], $this->categoryIdsOfTheTree(), 'removing the mapping restores it');
        $this->assertSame($centralRow, (array) DB::table('categories')->where('id', self::MAPPED_CATEGORY)->first(), 'the central row after the mapping is removed');
        $this->assertSame(0, DB::table(StorefrontVisibility::TABLE)->count());
    }

    public function test_hiding_for_one_storefront_leaves_every_other_storefront_and_the_panels_alone(): void
    {
        $this->switchStorefrontsOn();
        config(['gcc.storefronts.countries.AE.active' => true]);
        StorefrontVisibility::set(entityType: StorefrontVisibility::CATEGORY, entityId: self::MAPPED_CATEGORY, countries: ['KW']);

        foreach (['SA', 'AE'] as $country) {
            $this->serveStorefront($country);
            $this->assertSame([self::SHARED_CATEGORY, self::MAPPED_CATEGORY], $this->categoryIdsOfTheTree(), "storefront {$country}");
        }

        $this->serveNoStorefront();
        $this->assertSame([self::SHARED_CATEGORY, self::MAPPED_CATEGORY], $this->categoryIdsOfTheTree(), 'the admin and vendor panels, the seller APIs and the console');
    }

    public function test_a_brand_hidden_for_kuwait_is_gone_from_its_list_and_its_products_follow_their_own_visibility(): void
    {
        $this->switchStorefrontsOn();
        StorefrontVisibility::set(entityType: StorefrontVisibility::BRAND, entityId: self::MAPPED_BRAND, countries: ['KW']);

        $this->serveStorefront('KW');
        $this->assertSame([self::SHARED_BRAND], $this->brandIdsOfTheList(), 'the Kuwaiti brand list');
        $this->assertSame([self::SHARED_BRAND], BrandManager::getActiveBrandWithCountingAndPriorityWiseSorting()->pluck('id')->sort()->map(fn ($id) => (int) $id)->values()->all(), 'the Kuwaiti brand filter');

        $this->assertSame(
            [1, 2],
            DB::table('products')->where('status', 1)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'the products of a hidden brand keep the visibility they had: the two active ones stay active, the disabled one stays disabled',
        );

        $this->serveStorefront('SA');
        $this->assertSame([self::SHARED_BRAND, self::MAPPED_BRAND], $this->brandIdsOfTheList(), 'the Saudi brand list');
    }

    public function test_a_banner_assigned_to_one_storefront_shows_only_there_while_an_unassigned_banner_shows_in_both(): void
    {
        $this->switchStorefrontsOn();
        StorefrontVisibility::set(entityType: StorefrontVisibility::BANNER, entityId: 2, countries: ['SA']);

        $this->serveStorefront('SA');
        $this->assertSame(['Everywhere banner', 'Mapped banner'], Banner::query()->orderBy('id')->pluck('title')->all());

        $this->serveStorefront('KW');
        $this->assertSame(['Everywhere banner'], Banner::query()->orderBy('id')->pluck('title')->all());
    }

    public function test_a_coupon_limited_to_one_storefront_is_absent_from_another_storefronts_list_api(): void
    {
        $this->switchStorefrontsOn();
        $mappedCouponId = (int) DB::table('coupons')->where('code', 'MAPPED')->value('id');
        StorefrontVisibility::set(entityType: StorefrontVisibility::COUPON, entityId: $mappedCouponId, countries: ['SA']);

        $this->serveStorefront('SA');
        $this->assertSame(['EVERYWHERE', 'MAPPED'], $this->couponCodesOfTheApiList());

        $this->serveStorefront('KW');
        $this->assertSame(['EVERYWHERE'], $this->couponCodesOfTheApiList());
        $this->assertNotNull(Coupon::withoutGlobalScope('storefront_visibility')->find($mappedCouponId), 'the central coupon row is still there');
    }

    public function test_a_campaign_limited_to_one_storefront_overlays_only_that_storefront(): void
    {
        $this->switchStorefrontsOn();
        StorefrontVisibility::set(entityType: StorefrontVisibility::EXPERIENCE_CAMPAIGN, entityId: 2, countries: ['SA']);

        $this->serveStorefront('SA');
        $this->assertSame(['Everywhere campaign', 'Mapped campaign'], $this->campaignNamesOverlayingHome());

        $this->serveStorefront('KW');
        $this->assertSame(['Everywhere campaign'], $this->campaignNamesOverlayingHome());
    }

    public function test_the_two_defaults_are_not_the_same_rule(): void
    {
        $this->switchStorefrontsOn();
        $this->serveStorefront('KW');

        $this->assertSame(StorefrontVisibility::MODE_HIDDEN, StorefrontVisibility::modeOf(StorefrontVisibility::CATEGORY));
        $this->assertSame(StorefrontVisibility::MODE_HIDDEN, StorefrontVisibility::modeOf(StorefrontVisibility::BRAND));
        foreach ([StorefrontVisibility::BANNER, StorefrontVisibility::COUPON, StorefrontVisibility::FLASH_DEAL,
                  StorefrontVisibility::DEAL_OF_THE_DAY, StorefrontVisibility::CLEARANCE_SALE, StorefrontVisibility::EXPERIENCE_CAMPAIGN] as $entityType) {
            $this->assertSame(StorefrontVisibility::MODE_ASSIGNED, StorefrontVisibility::modeOf($entityType), $entityType);
        }

        // One row naming SA: the category it names stays visible in Kuwait, the banner it names does not.
        StorefrontVisibility::set(entityType: StorefrontVisibility::CATEGORY, entityId: self::MAPPED_CATEGORY, countries: ['SA']);
        StorefrontVisibility::set(entityType: StorefrontVisibility::BANNER, entityId: 2, countries: ['SA']);

        $this->assertTrue(StorefrontVisibility::isVisible(entityType: StorefrontVisibility::CATEGORY, entityId: self::MAPPED_CATEGORY, country: 'KW'), 'a category row hides, so Kuwait is untouched by an SA row');
        $this->assertFalse(StorefrontVisibility::isVisible(entityType: StorefrontVisibility::CATEGORY, entityId: self::MAPPED_CATEGORY, country: 'SA'), 'the category is hidden where its row names');
        $this->assertFalse(StorefrontVisibility::isVisible(entityType: StorefrontVisibility::BANNER, entityId: 2, country: 'KW'), 'a banner row assigns, so Kuwait loses it');
        $this->assertTrue(StorefrontVisibility::isVisible(entityType: StorefrontVisibility::BANNER, entityId: 2, country: 'SA'), 'the banner shows where its row names');
    }

    public function test_the_service_writes_only_configured_storefronts_and_leaves_the_central_row_alone(): void
    {
        $this->switchStorefrontsOn();
        $centralRow = (array) DB::table('brands')->where('id', self::MAPPED_BRAND)->first();

        StorefrontVisibility::set(entityType: StorefrontVisibility::BRAND, entityId: self::MAPPED_BRAND, countries: ['kw', 'ZZ', 'KW', '']);

        $this->assertSame(['KW'], StorefrontVisibility::storefrontsOf(entityType: StorefrontVisibility::BRAND, entityId: self::MAPPED_BRAND));
        $this->assertSame(1, StorefrontEntityVisibility::query()->count());
        $this->assertSame(StorefrontVisibility::MODE_HIDDEN, StorefrontEntityVisibility::query()->value('mode'));
        $this->assertSame($centralRow, (array) DB::table('brands')->where('id', self::MAPPED_BRAND)->first());
    }

    public function test_brands_carry_no_country_and_one_brand_lists_in_every_storefront_with_its_own_product_count(): void
    {
        $this->switchStorefrontsOn();

        foreach (['storefront_country', 'country', 'country_code'] as $column) {
            $this->assertFalse(Schema::hasColumn('brands', $column), "brands carries no [{$column}] column");
        }
        $this->assertNotContains('storefront_country', (new Brand)->getFillable());

        DB::table('products')->where('id', 2)->update(['storefront_country' => 'KW']);
        DB::table('products')->where('id', 1)->update(['brand_id' => self::MAPPED_BRAND]);

        foreach (['SA' => 1, 'KW' => 1] as $country => $expectedCount) {
            $this->serveStorefront($country);
            $brand = Brand::active()->withCount('brandProducts')->find(self::MAPPED_BRAND);

            $this->assertNotNull($brand, "the one brand row is listed in {$country}");
            $this->assertSame($expectedCount, (int) $brand->brand_products_count, "the product count {$country} counts for itself");
        }
    }

    public function test_a_deal_a_deal_of_the_day_and_a_clearance_sale_can_each_be_limited_to_chosen_storefronts(): void
    {
        $this->seedStorefrontPromotions();
        $this->switchStorefrontsOn();
        config(['gcc.storefronts.countries.AE.active' => true]);

        StorefrontVisibility::set(entityType: StorefrontVisibility::FLASH_DEAL, entityId: 2, countries: ['SA', 'AE']);
        StorefrontVisibility::set(entityType: StorefrontVisibility::DEAL_OF_THE_DAY, entityId: 2, countries: ['SA']);
        StorefrontVisibility::set(entityType: StorefrontVisibility::CLEARANCE_SALE, entityId: 2, countries: ['SA']);

        $readers = [
            'deals' => static fn (): array => FlashDeal::query()->orderBy('id')->pluck('title')->all(),
            'deals_of_the_day' => static fn (): array => DealOfTheDay::query()->orderBy('id')->pluck('title')->all(),
            'clearance_sales' => static fn (): array => StockClearanceSetup::query()->orderBy('id')->pluck('id')->map(static fn ($id) => (int) $id)->all(),
        ];

        $this->serveStorefront('SA');
        $this->assertSame(['Everywhere deal', 'Mapped deal'], $readers['deals']());
        $this->assertSame(['Everywhere deal of the day', 'Mapped deal of the day'], $readers['deals_of_the_day']());
        $this->assertSame([1, 2], $readers['clearance_sales']());

        // A second storefront on the deal's row keeps it there, and nowhere else: the mapping names storefronts, not one.
        $this->serveStorefront('AE');
        $this->assertSame(['Everywhere deal', 'Mapped deal'], $readers['deals']());
        $this->assertSame(['Everywhere deal of the day'], $readers['deals_of_the_day']());
        $this->assertSame([1], $readers['clearance_sales']());

        $this->serveStorefront('KW');
        $this->assertSame(['Everywhere deal'], $readers['deals']());
        $this->assertSame(['Everywhere deal of the day'], $readers['deals_of_the_day']());
        $this->assertSame([1], $readers['clearance_sales']());

        $this->assertSame(['AE', 'SA'], collect(StorefrontVisibility::storefrontsOf(entityType: StorefrontVisibility::FLASH_DEAL, entityId: 2))->sort()->values()->all());
    }

    public function test_the_web_reader_and_the_api_reader_answer_the_same_hidden_catalogue(): void
    {
        $this->switchStorefrontsOn();
        StorefrontVisibility::set(entityType: StorefrontVisibility::CATEGORY, entityId: self::MAPPED_CATEGORY, countries: ['KW']);
        StorefrontVisibility::set(entityType: StorefrontVisibility::BRAND, entityId: self::MAPPED_BRAND, countries: ['KW']);

        foreach (['SA' => [self::SHARED_CATEGORY, self::MAPPED_CATEGORY], 'KW' => [self::SHARED_CATEGORY]] as $country => $expectedCategories) {
            $this->serveStorefront($country);

            $this->assertSame($expectedCategories, $this->categoryIdsOfTheTree(), "the web tree of {$country}");
            $this->assertSame($expectedCategories, $this->categoryIdsOfTheApiList(), "the API category list of {$country}");

            $expectedBrands = $country === 'KW' ? [self::SHARED_BRAND] : [self::SHARED_BRAND, self::MAPPED_BRAND];
            $this->assertSame($expectedBrands, $this->brandIdsOfTheList(), "the web brand list of {$country}");
            $this->assertSame($expectedBrands, $this->brandIdsOfTheApiList(), "the API brand list of {$country}");
        }
    }

    public function test_the_migration_can_run_twice_and_be_rolled_back(): void
    {
        $migration = $this->storefrontVisibilityMigration();

        $migration->down();
        $this->assertFalse(Schema::hasTable(StorefrontVisibility::TABLE));

        $migration->up();
        $this->assertTrue(Schema::hasTable(StorefrontVisibility::TABLE));

        $migration->down();
        $migration->down();
        $this->assertFalse(Schema::hasTable(StorefrontVisibility::TABLE));
    }

    public function test_nothing_is_narrowed_before_the_mapping_migration_has_run(): void
    {
        $this->switchStorefrontsOn();
        $this->storefrontVisibilityMigration()->down();
        StorefrontVisibility::flush();

        $this->serveStorefront('KW');

        $this->assertSame([self::SHARED_CATEGORY, self::MAPPED_CATEGORY], $this->categoryIdsOfTheTree());
        $this->assertFalse(StorefrontVisibility::isTracked());
        $this->assertSame([], StorefrontVisibility::storefrontsOf(entityType: StorefrontVisibility::CATEGORY, entityId: self::MAPPED_CATEGORY));
    }
}
