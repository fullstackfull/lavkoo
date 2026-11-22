<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Support\Facades\Cache;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * A cache entry built from the storefront's own products is written under that storefront's key, and a change to the products clears the
 * entry of every storefront. While storefronts are off, and for the Saudi storefront, every key is the one it always was.
 */
class StorefrontProductCachesTest extends TestCase
{
    use BuildsStorefrontCatalog;

    /** The caches whose content comes from Product::active(), and so differs from one storefront to the next. */
    private const PRODUCT_CACHE_KEYS = [
        'CACHE_FOR_ALL_PRODUCTS_COLOR_LIST',
        'CACHE_SHOP_TABLE',
        'CACHE_MAIN_CATEGORIES_LIST',
        'CACHE_HOME_CATEGORIES_LIST',
        'CACHE_PRIORITY_WISE_BRANDS_LIST',
        'CACHE_FOR_IN_HOUSE_ALL_PRODUCTS',
        'CACHE_FOR_CLEARANCE_SALE_PRODUCTS_COUNT',
        'CACHE_FOR_HOME_PAGE_TOP_VENDORS_LIST',
        'CACHE_FOR_HOME_PAGE_MORE_VENDORS_LIST',
        'CACHE_FOR_HOME_PAGE_JUST_FOR_YOU_PRODUCT_LIST',
        'CACHE_FOR_RANDOM_SINGLE_PRODUCT',
        'CACHE_FOR_MOST_DEMANDED_PRODUCT_ITEM',
        'CACHE_FOR_HOME_PAGE_TOP_RATED_PRODUCT_LIST',
        'CACHE_FOR_HOME_PAGE_BEST_SELL_PRODUCT_LIST',
        'CACHE_FOR_HOME_PAGE_LATEST_PRODUCT_LIST',
        'CACHE_FOR_PRODUCTS_MAX_UNIT_PRICE',
        'CACHE_FOR_PRODUCTS_MIN_UNIT_PRICE',
        'CACHE_PROVIDER_PUBLISHING_HOUSES_LIST',
        'CACHE_PROVIDER_PUBLISHING_HOUSES_COUNT',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['gcc.storefronts.countries.AE.active' => true, 'gcc.storefronts.countries.KW.active' => true]);
        Cache::flush();
    }

    private function switchStorefrontsOn(): void
    {
        config(['gcc.storefronts.enabled' => true]);
    }

    private function serveRequestFrom(string $country): void
    {
        app()->instance(StorefrontContext::class, StorefrontResolver::fromConfig()->context(explicitChoice: $country));
    }

    public function test_the_key_is_unchanged_while_storefronts_are_off(): void
    {
        $this->serveRequestFrom('AE');

        $this->assertSame('some_key', storefrontCacheKey(key: 'some_key'));
    }

    public function test_the_key_is_unchanged_for_the_saudi_storefront(): void
    {
        $this->switchStorefrontsOn();
        $this->serveRequestFrom('SA');

        $this->assertSame('some_key', storefrontCacheKey(key: 'some_key'));
    }

    /** The admin panel, a queue or a console command reads every country, so it must not fill the Saudi storefront's entry. */
    public function test_a_request_that_was_not_bound_to_a_storefront_has_a_key_of_its_own(): void
    {
        $this->switchStorefrontsOn();

        $this->assertSame('storefront:*:some_key', storefrontCacheKey(key: 'some_key'));
    }

    public function test_an_unbound_request_never_fills_the_saudi_storefronts_price_range(): void
    {
        $this->buildStorefrontCatalogTables();
        $this->buildProductRelationTables();
        $this->runStorefrontCountryMigration();
        $this->insertCatalogProduct(id: 1, name: 'Saudi serum', price: 40);
        $this->insertCatalogProduct(id: 2, name: 'Emirati serum', price: 5000, country: 'AE');
        $this->switchStorefrontsOn();

        $rangeSeenByTheAdminPanel = getProductMaxUnitPriceRange();
        $this->serveRequestFrom('SA');

        $this->assertSame(5000, $rangeSeenByTheAdminPanel);
        $this->assertSame(500, getProductMaxUnitPriceRange());
    }

    public function test_another_storefront_writes_under_its_own_key(): void
    {
        $this->switchStorefrontsOn();
        $this->serveRequestFrom('AE');

        $this->assertSame('storefront:AE:some_key', storefrontCacheKey(key: 'some_key'));
    }

    public function test_a_storefront_request_the_middleware_has_not_reached_writes_under_the_key_of_the_storefront_it_asks_for(): void
    {
        $this->switchStorefrontsOn();

        $this->serveAsHttpRequest('/products?storefront=AE');
        $this->assertSame('storefront:AE:some_key', storefrontCacheKey(key: 'some_key'));

        $this->serveAsHttpRequest('/products');
        $this->assertSame('some_key', storefrontCacheKey(key: 'some_key'));
    }

    public function test_a_panel_or_a_seller_api_request_has_the_key_of_its_own_whatever_the_address_says(): void
    {
        $this->switchStorefrontsOn();

        foreach (['/admin/dashboard', '/vendor/products/list', '/api/v3/seller/products/list'] as $uri) {
            $this->serveAsHttpRequest($uri . '?storefront=AE');
            $this->assertSame('storefront:*:some_key', storefrontCacheKey(key: 'some_key'), $uri);
        }
    }

    public function test_while_storefronts_are_off_forgetting_a_key_forgets_only_that_key(): void
    {
        Cache::put('some_key', 'cached', 60);
        Cache::put('storefront:AE:some_key', 'cached', 60);

        cacheForgetInEveryStorefront(key: 'some_key');

        $this->assertFalse(Cache::has('some_key'));
        $this->assertTrue(Cache::has('storefront:AE:some_key'));
    }

    public function test_while_storefronts_are_off_a_missing_or_broken_storefront_configuration_does_not_reach_the_caches(): void
    {
        foreach ([null, ['storefronts' => ['enabled' => false, 'default_country' => 'XX', 'countries' => []]]] as $gcc) {
            config(['gcc' => $gcc]);

            $this->assertSame('some_key', storefrontCacheKey(key: 'some_key'));
            cacheRemoveByType(type: 'products');
        }
    }

    public function test_forgetting_a_key_forgets_it_in_every_storefront(): void
    {
        $this->switchStorefrontsOn();
        foreach (['some_key', 'storefront:AE:some_key', 'storefront:KW:some_key', 'storefront:*:some_key', 'storefront:AE:other_key'] as $key) {
            Cache::put($key, 'cached', 60);
        }

        cacheForgetInEveryStorefront(key: 'some_key');

        $this->assertFalse(Cache::has('some_key'));
        $this->assertFalse(Cache::has('storefront:AE:some_key'));
        $this->assertFalse(Cache::has('storefront:KW:some_key'));
        $this->assertFalse(Cache::has('storefront:*:some_key'));
        $this->assertTrue(Cache::has('storefront:AE:other_key'));
    }

    public function test_a_cached_price_range_is_kept_per_storefront(): void
    {
        $this->buildStorefrontCatalogTables();
        $this->buildProductRelationTables();
        $this->runStorefrontCountryMigration();
        $this->insertCatalogProduct(id: 1, name: 'Saudi serum', price: 40);
        $this->insertCatalogProduct(id: 2, name: 'Emirati serum', price: 5000, country: 'AE');
        $this->switchStorefrontsOn();

        $this->serveRequestFrom('SA');
        $saudiRange = getProductMaxUnitPriceRange();

        $this->serveRequestFrom('AE');
        $emiratiRange = getProductMaxUnitPriceRange();

        $this->serveRequestFrom('SA');
        $this->assertSame(500, $saudiRange);
        $this->assertSame(5000, $emiratiRange);
        $this->assertSame($saudiRange, getProductMaxUnitPriceRange());
        $this->assertTrue(Cache::has(CACHE_FOR_PRODUCTS_MAX_UNIT_PRICE));
        $this->assertTrue(Cache::has('storefront:AE:' . CACHE_FOR_PRODUCTS_MAX_UNIT_PRICE));
    }

    public function test_a_change_to_the_products_clears_every_storefronts_product_caches(): void
    {
        $this->switchStorefrontsOn();
        foreach (self::PRODUCT_CACHE_KEYS as $name) {
            Cache::put(constant($name), 'saudi', 60);
            Cache::put('storefront:AE:' . constant($name), 'emirati', 60);
            Cache::put('storefront:*:' . constant($name), 'every country', 60);
        }

        cacheRemoveByType(type: 'products');
        cacheRemoveByType(type: 'shops');

        foreach (self::PRODUCT_CACHE_KEYS as $name) {
            $this->assertFalse(Cache::has(constant($name)), "{$name} must be cleared for the Saudi storefront.");
            $this->assertFalse(Cache::has('storefront:AE:' . constant($name)), "{$name} must be cleared for another storefront.");
            $this->assertFalse(Cache::has('storefront:*:' . constant($name)), "{$name} must be cleared for a request bound to none.");
        }
    }

    public function test_a_registered_scoped_key_is_cleared_with_its_group(): void
    {
        $this->switchStorefrontsOn();
        $this->serveRequestFrom('AE');
        $scopedKey = storefrontCacheKey(key: 'cache_flash_deal_default');
        Cache::put(CACHE_FLASH_DEAL_KEYS, [$scopedKey], 60);
        Cache::put($scopedKey, 'emirati', 60);

        cacheRemoveByType(type: 'flash_deals');

        $this->assertFalse(Cache::has($scopedKey));
    }

    /** A new use of one of these caches must not forget the storefront key, or one market would serve another's products. */
    public function test_the_product_caches_are_never_written_or_cleared_without_the_storefront_key(): void
    {
        $unwrapped = [];
        $pattern = '/Cache::(remember|rememberForever|put|add|forget|get|has)\(\s*(' . implode('|', self::PRODUCT_CACHE_KEYS) . ')\b/';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (file($file->getPathname()) as $number => $line) {
                if (preg_match($pattern, $line, $match)) {
                    $unwrapped[] = str_replace(base_path() . '/', '', $file->getPathname()) . ':' . ($number + 1) . ' ' . $match[2];
                }
            }
        }

        $this->assertSame([], $unwrapped, "These cache keys are used without storefrontCacheKey(key: ) or cacheForgetInEveryStorefront(key: ):\n" . implode("\n", $unwrapped));
    }
}
