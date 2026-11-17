<?php

namespace Tests\Feature\Storefront;

use App\Http\Controllers\RestAPI\v1\ProductController;
use App\Http\Middleware\ResolveStorefront;
use App\Services\Storefront\StorefrontContext;
use App\Services\Theme\StorefrontThemeRenderer;
use App\Services\Theme\ThemeDelivery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Mockery;
use ReflectionClassConstant;
use Tests\TestCase;

/**
 * Storefront-facing cache entries are written under the storefront that asked for them, and a publish or a category change clears every
 * storefront's copy. While storefronts are off, and for the Saudi storefront, every key is the one it was before.
 */
class StorefrontCacheScopeTest extends TestCase
{
    private const HOME_CATEGORIES_KEY = 'cache_home_categories_api_listen';

    protected function setUp(): void
    {
        parent::setUp();

        config(['gcc.storefronts.enabled' => true]);
        Cache::flush();
    }

    public function test_the_storefront_facing_api_routes_bind_the_storefront_context(): void
    {
        foreach (['/api/v1/products/home-categories', '/api/v1/theme/home'] as $uri) {
            $this->assertContains('storefront', Route::getRoutes()->match(Request::create($uri, 'GET'))->middleware(), $uri);
        }
        $this->assertNotContains('storefront', Route::getRoutes()->match(Request::create('/api/v1/storefronts', 'GET'))->middleware());
    }

    public function test_home_categories_are_cached_under_the_storefront_that_asked_for_them(): void
    {
        app()->instance(StorefrontContext::class, new StorefrontContext('AE', 'AED', true));
        $this->expectHomeCategoriesCachedUnder('storefront:AE:' . self::HOME_CATEGORIES_KEY);

        app(ProductController::class)->get_home_categories(Request::create('/api/v1/home-categories', 'GET'));
    }

    public function test_the_saudi_storefront_keeps_the_home_categories_key_it_always_had(): void
    {
        $this->expectHomeCategoriesCachedUnder(self::HOME_CATEGORIES_KEY);

        app(ProductController::class)->get_home_categories(Request::create('/api/v1/home-categories', 'GET'));
    }

    public function test_home_categories_keep_the_unscoped_key_while_storefronts_are_switched_off(): void
    {
        config(['gcc.storefronts.enabled' => false]);
        app()->instance(StorefrontContext::class, new StorefrontContext('AE', 'AED', false));
        $this->expectHomeCategoriesCachedUnder(self::HOME_CATEGORIES_KEY);

        app(ProductController::class)->get_home_categories(Request::create('/api/v1/home-categories', 'GET'));
    }

    public function test_a_theme_flush_clears_the_copy_of_every_storefront(): void
    {
        $pageKey = StorefrontThemeRenderer::CACHE_KEY_PREFIX . 'home';
        $globalKey = StorefrontThemeRenderer::GLOBAL_SETTINGS_CACHE_KEY;
        $scopes = ['', 'storefront:AE:', 'storefront:OM:'];
        foreach ($scopes as $scope) {
            Cache::put($scope . $pageKey, ['sections' => []]);
            Cache::put($scope . $globalKey, ['settings' => null]);
        }

        app(StorefrontThemeRenderer::class)->flush(['home']);

        foreach ($scopes as $scope) {
            $this->assertFalse(Cache::has($scope . $pageKey), "page copy under [{$scope}]");
            $this->assertFalse(Cache::has($scope . $globalKey), "global settings copy under [{$scope}]");
        }
    }

    public function test_a_delivery_flush_clears_the_revision_of_every_storefront(): void
    {
        $prefix = (new ReflectionClassConstant(ThemeDelivery::class, 'CACHE_PREFIX'))->getValue();
        $scopes = ['', 'storefront:AE:', 'storefront:QA:'];
        foreach ($scopes as $scope) {
            Cache::put($scope . $prefix . 'revision', ['revision' => 1]);
        }

        app(ThemeDelivery::class)->flush();

        foreach ($scopes as $scope) {
            $this->assertFalse(Cache::has($scope . $prefix . 'revision'), "revision under [{$scope}]");
        }
    }

    public function test_a_category_change_clears_the_home_categories_of_every_storefront(): void
    {
        $scopedKey = 'storefront:AE:' . self::HOME_CATEGORIES_KEY;
        Cache::put(CACHE_HOME_CATEGORIES_API_LIST, [$scopedKey, self::HOME_CATEGORIES_KEY]);
        Cache::put($scopedKey, []);
        Cache::put(self::HOME_CATEGORIES_KEY, []);

        cacheRemoveByType('categories');

        $this->assertFalse(Cache::has($scopedKey));
        $this->assertFalse(Cache::has(self::HOME_CATEGORIES_KEY));
    }

    public function test_while_storefronts_are_on_a_response_varies_on_the_storefront_header(): void
    {
        $response = app(ResolveStorefront::class)->handle(
            Request::create('/api/v1/products/home-categories', 'GET'),
            fn () => response('ok'),
        );

        $this->assertContains(
            ResolveStorefront::HEADER,
            array_map('trim', explode(',', (string) $response->headers->get('Vary'))),
        );
    }

    public function test_while_storefronts_are_off_the_response_is_not_changed(): void
    {
        config(['gcc.storefronts.enabled' => false]);

        $response = app(ResolveStorefront::class)->handle(
            Request::create('/api/v1/products/home-categories', 'GET'),
            fn () => response('ok'),
        );

        $this->assertNull($response->headers->get('Vary'));
    }

    /** The home categories request reads the cache for its index, writes that index, and remembers the page under exactly one key. */
    private function expectHomeCategoriesCachedUnder(string $key): void
    {
        Cache::shouldReceive('get')->once()->andReturn([]);
        Cache::shouldReceive('put')->once()->andReturnTrue();
        Cache::shouldReceive('remember')
            ->once()
            ->with($key, Mockery::any(), Mockery::type(\Closure::class))
            ->andReturn([]);
    }
}
