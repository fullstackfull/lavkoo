<?php

namespace Tests\Feature\Storefront;

use App\Models\Category;
use App\Services\BannerPlacementService;
use App\Services\Commerce\CampaignResolver;
use App\Services\Storefront\StorefrontVisibility;
use App\Utils\BrandManager;
use App\Utils\CategoryManager;
use Illuminate\Support\Facades\Cache;
use Tests\Support\BuildsStorefrontVisibilityWorld;
use Tests\TestCase;

/**
 * Every cached catalogue answer carries the storefront that asked for it (B-13, B-15, B-16). A cache warmed as one storefront is never
 * served to another, in either direction, and the Saudi storefront keeps the plain key it writes under today.
 *
 * Each reader is warmed for real and read for real: an uncached path proves nothing here.
 */
class StorefrontCatalogueVisibilityCacheTest extends TestCase
{
    use BuildsStorefrontVisibilityWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontVisibilityWorld();
        $this->seedStorefrontVisibilityWorld();
        $this->insertBanner(id: 3, title: 'Everywhere main banner', type: 'Main Banner');
        $this->insertBanner(id: 4, title: 'Mapped main banner', type: 'Main Banner');

        // Kuwait sells its own copy of each product, so what the mapping removes from its lists is the mapping's doing and not an empty catalogue.
        $this->insertProduct(id: 4, name: 'Kuwaiti shared lamp', category: self::SHARED_CATEGORY, subCategory: null, extra: ['brand_id' => self::SHARED_BRAND, 'storefront_country' => 'KW']);
        $this->insertProduct(id: 5, name: 'Kuwaiti mapped lamp', category: self::MAPPED_CATEGORY, subCategory: null, extra: ['brand_id' => self::MAPPED_BRAND, 'storefront_country' => 'KW']);

        $this->switchStorefrontsOn();
    }

    /**
     * Warms the reader as one storefront and reads it as the other, both ways round. The answer of the storefront that asked second must
     * never be the one the first warmed.
     *
     * @param callable(): mixed $read what one storefront is answered
     * @param callable(mixed): array<int|string, mixed> $shape the part of the answer the criterion is about
     */
    private function assertNoStorefrontIsServedAnothersCache(callable $read, callable $shape, array $saudi, array $kuwait, string $reader): void
    {
        foreach ([['SA', $saudi, 'KW', $kuwait], ['KW', $kuwait, 'SA', $saudi]] as [$warms, $warmsAnswer, $asks, $asksAnswer]) {
            Cache::flush();

            $this->serveStorefront($warms);
            $this->assertSame($warmsAnswer, $shape($read()), "{$reader}: {$warms} warms the cache");

            $this->serveStorefront($asks);
            $this->assertSame($asksAnswer, $shape($read()), "{$reader}: {$asks} reads after {$warms} warmed it");
        }
    }

    /** @return callable(mixed): list<string> */
    private function titles(string $key = 'name'): callable
    {
        return static fn ($answer): array => collect($answer)->pluck($key)->map(static fn ($value): string => (string) $value)->sort()->values()->all();
    }

    public function test_the_category_tree_cache_is_not_served_across_storefronts(): void
    {
        StorefrontVisibility::set(entityType: StorefrontVisibility::CATEGORY, entityId: self::MAPPED_CATEGORY, countries: ['KW']);

        $this->assertNoStorefrontIsServedAnothersCache(
            read: fn () => (new Category)->cacheMainCategoriesList(),
            shape: $this->titles(),
            saudi: ['Everywhere', 'Mapped'],
            kuwait: ['Everywhere'],
            reader: 'the category tree',
        );
    }

    public function test_the_home_category_cache_is_not_served_across_storefronts(): void
    {
        StorefrontVisibility::set(entityType: StorefrontVisibility::CATEGORY, entityId: self::MAPPED_CATEGORY, countries: ['KW']);

        $this->assertNoStorefrontIsServedAnothersCache(
            read: fn () => (new Category)->cacheHomeCategoriesList(),
            shape: $this->titles(),
            saudi: ['Everywhere', 'Mapped'],
            kuwait: ['Everywhere'],
            reader: 'the home page categories',
        );
    }

    public function test_the_category_filter_cache_is_not_served_across_storefronts(): void
    {
        StorefrontVisibility::set(entityType: StorefrontVisibility::CATEGORY, entityId: self::MAPPED_CATEGORY, countries: ['KW']);

        $this->assertNoStorefrontIsServedAnothersCache(
            read: fn () => CategoryManager::getCategoriesWithCountingAndPriorityWiseSorting(),
            shape: $this->titles(),
            saudi: ['Everywhere', 'Mapped'],
            kuwait: ['Everywhere'],
            reader: 'the category filter',
        );
    }

    public function test_the_brand_list_cache_is_not_served_across_storefronts(): void
    {
        StorefrontVisibility::set(entityType: StorefrontVisibility::BRAND, entityId: self::MAPPED_BRAND, countries: ['KW']);

        $this->assertNoStorefrontIsServedAnothersCache(
            read: fn () => BrandManager::getActiveBrandWithCountingAndPriorityWiseSorting(),
            shape: $this->titles(),
            saudi: ['Everywhere brand', 'Mapped brand'],
            kuwait: ['Everywhere brand'],
            reader: 'the brand list',
        );
    }

    public function test_the_banner_table_cache_is_not_served_across_storefronts(): void
    {
        StorefrontVisibility::set(entityType: StorefrontVisibility::BANNER, entityId: 2, countries: ['SA']);
        StorefrontVisibility::set(entityType: StorefrontVisibility::BANNER, entityId: 4, countries: ['SA']);

        $this->assertNoStorefrontIsServedAnothersCache(
            read: fn () => (new Category)->cacheBannerTable(),
            shape: $this->titles(key: 'title'),
            saudi: ['Everywhere banner', 'Everywhere main banner', 'Mapped banner', 'Mapped main banner'],
            kuwait: ['Everywhere banner', 'Everywhere main banner'],
            reader: 'the banner table',
        );
    }

    public function test_the_main_banner_cache_is_not_served_across_storefronts(): void
    {
        StorefrontVisibility::set(entityType: StorefrontVisibility::BANNER, entityId: 4, countries: ['SA']);

        $this->assertNoStorefrontIsServedAnothersCache(
            read: fn () => (new Category)->cacheBannerForTypeMainBanner(),
            shape: $this->titles(key: 'title'),
            saudi: ['Everywhere main banner', 'Mapped main banner'],
            kuwait: ['Everywhere main banner'],
            reader: 'the main banner slider',
        );
    }

    public function test_the_home_promo_banner_cache_is_not_served_across_storefronts(): void
    {
        StorefrontVisibility::set(entityType: StorefrontVisibility::BANNER, entityId: 2, countries: ['SA']);

        $this->assertNoStorefrontIsServedAnothersCache(
            read: fn () => app(BannerPlacementService::class)->getHomePromoBanners(),
            shape: $this->titles(key: 'title'),
            saudi: ['Everywhere banner', 'Mapped banner'],
            kuwait: ['Everywhere banner'],
            reader: 'the home promo grid',
        );
    }

    public function test_the_clearance_sale_setup_cache_is_not_served_across_storefronts(): void
    {
        $this->seedStorefrontPromotions();
        StorefrontVisibility::set(entityType: StorefrontVisibility::CLEARANCE_SALE, entityId: 2, countries: ['SA']);

        $this->assertNoStorefrontIsServedAnothersCache(
            read: static fn (): array => [
                'shop_1' => (new Category)->cacheClearanceSaleSetupTable(shopId: 1)?->id,
                'shop_2' => (new Category)->cacheClearanceSaleSetupTable(shopId: 2)?->id,
            ],
            shape: static fn (array $answer): array => $answer,
            saudi: ['shop_1' => 1, 'shop_2' => 2],
            kuwait: ['shop_1' => 1, 'shop_2' => null],
            reader: 'the clearance sale setup',
        );
    }

    public function test_the_campaign_overlay_cache_is_not_served_across_storefronts(): void
    {
        StorefrontVisibility::set(entityType: StorefrontVisibility::EXPERIENCE_CAMPAIGN, entityId: 2, countries: ['SA']);

        $this->assertNoStorefrontIsServedAnothersCache(
            read: fn () => app(CampaignResolver::class)->overridesFor('home'),
            shape: static fn (array $overrides): array => collect($overrides)->pluck('section.settings.name')->sort()->values()->all(),
            saudi: ['Everywhere campaign', 'Mapped campaign'],
            kuwait: ['Everywhere campaign'],
            reader: 'the campaign overlay',
        );
    }

    public function test_the_saudi_storefront_keeps_the_keys_it_writes_under_today(): void
    {
        $this->serveStorefront('SA');

        (new Category)->cacheMainCategoriesList();
        (new Category)->cacheBannerTable();
        (new Category)->cacheBannerForTypeMainBanner();
        app(CampaignResolver::class)->overridesFor('home');

        foreach ([CACHE_MAIN_CATEGORIES_LIST, CACHE_BANNER_TABLE, 'cache_banner_type_main_banner_default', 'commerce_campaign_overrides_home'] as $key) {
            $this->assertTrue(Cache::has($key), "the Saudi storefront writes under [{$key}]");
            $this->assertFalse(Cache::has('storefront:SA:' . $key), "the Saudi storefront writes no second copy of [{$key}]");
        }
    }

    public function test_a_banner_change_forgets_every_storefronts_copy(): void
    {
        foreach (['SA', 'KW'] as $country) {
            $this->serveStorefront($country);
            (new Category)->cacheBannerTable();
            (new Category)->cacheBannerForTypeMainBanner();
        }

        cacheRemoveByType(type: 'banners');

        foreach ([CACHE_BANNER_TABLE, 'cache_banner_type_main_banner_default'] as $key) {
            $this->assertFalse(Cache::has($key), "the Saudi copy of [{$key}]");
            $this->assertFalse(Cache::has('storefront:KW:' . $key), "the Kuwaiti copy of [{$key}]");
        }
    }

    public function test_a_campaign_change_forgets_every_storefronts_overlay(): void
    {
        foreach (['SA', 'KW'] as $country) {
            $this->serveStorefront($country);
            app(CampaignResolver::class)->overridesFor('home');
        }

        CampaignResolver::forget();

        $this->assertFalse(Cache::has('commerce_campaign_overrides_home'), 'the Saudi overlay');
        $this->assertFalse(Cache::has('storefront:KW:commerce_campaign_overrides_home'), 'the Kuwaiti overlay');
    }

    public function test_with_no_mapping_rows_every_storefront_is_cached_the_answer_it_has_today(): void
    {
        $readers = [
            'tree' => fn (): array => $this->titles()((new Category)->cacheMainCategoriesList()),
            'brands' => fn (): array => $this->titles()(BrandManager::getActiveBrandWithCountingAndPriorityWiseSorting()),
            'banners' => fn (): array => $this->titles(key: 'title')((new Category)->cacheBannerTable()),
            'campaigns' => fn (): array => collect(app(CampaignResolver::class)->overridesFor('home'))->pluck('section.settings.name')->sort()->values()->all(),
        ];

        Cache::flush();
        $this->serveNoStorefront();
        config(['gcc.storefronts.enabled' => false]);
        $asShippedToday = array_map(static fn (callable $read): array => $read(), $readers);

        $this->switchStorefrontsOn();
        foreach (['SA', 'KW'] as $country) {
            Cache::flush();
            $this->serveStorefront($country);
            $this->assertSame($asShippedToday, array_map(static fn (callable $read): array => $read(), $readers), "storefront {$country} with an empty mapping table");
        }
    }
}
