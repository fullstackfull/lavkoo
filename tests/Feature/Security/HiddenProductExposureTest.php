<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\APIGuestMiddleware;
use App\Http\Middleware\APILocalizationMiddleware;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\GuestMiddleware;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MaintenanceModeMiddleware;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordApiResponseShape;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Product;
use App\Utils\ProductManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsProductCatalog;
use Tests\TestCase;

/**
 * A product a customer may not see (disabled, never approved, or owned by a seller who is still pending) is not reachable by any other
 * door either: not through its id or slug, not through the reviews, counters and prices that hang off it, not through a banner that
 * points at it and not through a deal list. Every lookup answers for such a product exactly as it answers for an id that does not exist.
 */
class HiddenProductExposureTest extends TestCase
{
    use BuildsProductCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->buildCatalogTables();
        $this->registerMySqlLocate();
        $this->seedCatalog();
        $this->seedStoreSettings();
        DB::table('shops')->insert(['seller_id' => 0, 'author_type' => 'admin', 'name' => 'House Shop', 'slug' => 'house-shop']);
        DB::table('brands')->insert(['id' => 7, 'name' => 'Muted brand', 'status' => 0]);
        $this->insertProduct(id: 6, name: 'Beta branded lamp', extra: ['brand_id' => 7]);

        if (!defined('VIEW_FILE_NAMES')) {
            define('VIEW_FILE_NAMES', include resource_path('themes/default/file_names.php'));
        }
        View::addLocation(resource_path('themes/default'));
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class, RecordHttpTelemetry::class,
            MonitorRequest::class, RecordAnalytics::class, ActivationCheckMiddleware::class, APILocalizationMiddleware::class,
            APIGuestMiddleware::class, GuestMiddleware::class, MaintenanceModeMiddleware::class, RecordApiResponseShape::class,
        ]);
    }

    private function seedStoreSettings(): void
    {
        DB::table('currencies')->insert(['id' => 1, 'name' => 'USD', 'symbol' => '$', 'code' => 'USD', 'exchange_rate' => 1, 'status' => 1]);
        foreach (['system_default_currency' => '1', 'currency_model' => 'single_currency', 'currency_symbol_position' => 'left', 'decimal_point_settings' => '2', 'stock_limit' => '5'] as $type => $value) {
            $this->setBusinessSetting($type, $value);
        }
        $this->setBusinessSetting('language', [['id' => 1, 'name' => 'english', 'code' => 'en', 'status' => 1, 'default' => true]]);
        Cache::flush();
    }

    private function seedFeatureDeal(array $priority, array $memberIds): void
    {
        $this->setBusinessSetting('feature_deal_priority', array_merge([
            'custom_sorting_status' => 1, 'sort_by' => 'latest_created', 'out_of_stock_product' => 'default', 'temporary_close_sorting' => 'default',
        ], $priority));
        DB::table('flash_deals')->insert([
            'id' => 1, 'title' => 'Feature', 'deal_type' => 'feature_deal', 'status' => 1,
            'start_date' => date('Y-m-d', strtotime('-1 day')), 'end_date' => date('Y-m-d', strtotime('+1 day')),
        ]);
        foreach ($memberIds as $productId) {
            DB::table('flash_deal_products')->insert(['flash_deal_id' => 1, 'product_id' => $productId]);
        }
        Cache::flush();
    }

    private function approvedSellerWithProduct(int $sellerId, int $productId, bool $shopClosed): void
    {
        DB::table('sellers')->insert(['id' => $sellerId, 'status' => 'approved']);
        DB::table('shops')->insert(['seller_id' => $sellerId, 'name' => "Shop {$sellerId}", 'slug' => "shop-{$sellerId}", 'temporary_close' => $shopClosed]);
        $this->insertProduct(id: $productId, name: "Seller {$sellerId} lamp", userId: $sellerId, addedBy: 'seller');
    }

    #[DataProvider('hideModeCallers')]
    public function test_the_hide_mode_of_the_feature_deal_keeps_to_the_visible_members_of_the_deal(Closure $dealProducts): void
    {
        $this->seedFeatureDeal(priority: ['temporary_close_sorting' => 'hide'], memberIds: [1]);

        $this->assertSame([1], $this->idsOf($dealProducts()));
    }

    public static function hideModeCallers(): array
    {
        return [
            'the priority query over the active scope' => [fn () => ProductManager::getPriorityWiseFeatureDealQuery(query: Product::active()->whereIn('id', [1]), dataLimit: 'all')],
            'the home page and the offers menu' => [fn () => getFeaturedDealsProductList()],
            'the featured deals page' => [fn () => ProductManager::getProductListData(request: Request::create('/featured-deal-products', 'GET', ['offer_type' => 'featured_deal']))],
        ];
    }

    public function test_the_featured_deals_api_lists_only_the_visible_members_of_the_deal(): void
    {
        $this->seedFeatureDeal(priority: ['temporary_close_sorting' => 'hide'], memberIds: [1, 2]);

        $listed = $this->getJson('/api/v1/deals/featured')->assertOk()->json();

        $this->assertSame([1], $this->idsOf($listed));
    }

    public function test_the_hide_mode_keeps_the_products_of_open_shops_and_drops_those_of_temporarily_closed_ones(): void
    {
        $this->approvedSellerWithProduct(sellerId: 10, productId: 11, shopClosed: false);
        $this->approvedSellerWithProduct(sellerId: 20, productId: 12, shopClosed: true);
        $this->seedFeatureDeal(priority: ['temporary_close_sorting' => 'hide'], memberIds: [1, 3, 11, 12]);
        $deal = fn () => $this->idsOf(ProductManager::getPriorityWiseFeatureDealQuery(query: Product::active()->whereIn('id', [1, 3, 11, 12]), dataLimit: 'all'));

        $this->assertSame([1, 3, 11], $deal());

        Cache::forever(IN_HOUSE_SHOP_TEMPORARY_CLOSE_STATUS, 1);
        $this->assertSame([11], $deal());
    }

    public function test_the_other_sorting_modes_of_the_feature_deal_are_not_narrowed(): void
    {
        $this->seedFeatureDeal(priority: ['temporary_close_sorting' => 'default'], memberIds: [1, 2, 3]);

        $listed = ProductManager::getPriorityWiseFeatureDealQuery(query: Product::active()->whereIn('id', [1, 2, 3]), dataLimit: 'all');

        $this->assertSame([1, 3], $this->idsOf($listed));
    }
}
