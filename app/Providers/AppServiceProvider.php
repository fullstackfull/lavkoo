<?php

namespace App\Providers;

use App\Enums\GlobalConstant;
use Illuminate\Support\Facades\Event;
use App\Listeners\Security\RecordAuthenticationEvents;
use App\Models\BusinessPage;
use App\Models\BusinessSetting;
use App\Models\Contact;
use App\Models\Currency;
use App\Models\LoginSetup;
use App\Models\Order;
use App\Models\Product;
use App\Models\RefundRequest;
use App\Models\Setting;
use App\Models\Shop;
use App\Models\SocialMedia;
use App\Models\StockClearanceProduct;
use App\Models\SupportTicket;
use App\Models\User;
use App\Traits\AddonHelper;
use App\Traits\CacheManagerTrait;
use App\Traits\FileManagerTrait;
use App\Traits\ThemeHelper;
use App\Services\AuditLogger;
use App\Services\SellerIntelligence\Producers\BrandComplianceProducer;
use App\Services\SellerIntelligence\Producers\CatalogIntegrityProducer;
use App\Services\SellerIntelligence\Producers\FinanceIntegrityProducer;
use App\Services\SellerIntelligence\Producers\InventoryRiskProducer;
use App\Services\SellerIntelligence\Producers\OrderStateProducer;
use App\Services\SellerIntelligence\Producers\OrderStuckProducer;
use App\Services\SellerIntelligence\Producers\PricingRiskProducer;
use App\Services\SellerIntelligence\Producers\ReturnsRiskProducer;
use App\Services\SellerIntelligence\Producers\ShippingExceptionProducer;
use App\Services\SellerIntelligence\Producers\StaleInventoryProducer;
use App\Services\SellerIntelligence\Producers\ListingQualityProducer;
use App\Services\SellerIntelligence\Producers\OrderSlaProducer;
use App\Services\Marketplace\PricingPolicyService;
use App\Services\SellerAutomation\Actions\HideListingAction;
use App\Services\SellerAutomation\Actions\PublishListingAction;
use App\Services\SellerAutomation\Actions\SetDiscountAction;
use App\Services\SellerAutomation\AutomationRegistry;
use App\Services\SellerAutomation\Triggers\LowStockTrigger;
use App\Services\SellerAutomation\Triggers\OutOfStockTrigger;
use App\Services\SellerAutomation\Triggers\RestockedTrigger;
use App\Services\SellerAutomation\Triggers\StaleStockTrigger;
use App\Services\SellerIntelligence\SellerInsightEngine;
use App\Services\SellerIntelligence\Severity\SellerBaselineProvider;
use App\Services\SellerIntelligence\Severity\SeverityEngine;
use App\Traits\UpdateClass;
use App\Utils\Helpers;
use App\Utils\ProductManager;
use Exception;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

ini_set('memory_limit', -1);
ini_set('upload_max_filesize', '180M');
ini_set('post_max_size', '200M');

class AppServiceProvider extends ServiceProvider
{
    use AddonHelper;
    use CacheManagerTrait;
    use FileManagerTrait;
    use ThemeHelper;
    use UpdateClass;

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        // Services take the logger as `?AuditLogger $audit = null` so a test can
        // construct them bare. Laravel's container returns a parameter's default
        // without attempting resolution unless the class is explicitly bound, so
        // without this line every one of those constructors received null and
        // every `$this->audit?->record()` in the marketplace was a silent no-op.
        $this->app->singleton(AuditLogger::class);

        // One engine, one ordered set of producers. Registered here rather than discovered, so the
        // order the Action Center falls back on is explicit and a new producer is a deliberate act.
        $this->app->singleton(SellerInsightEngine::class, fn ($app) => new SellerInsightEngine(
            producers: [
                // Ordered by how much a seller can lose by not knowing. Finance first: uncredited
                // money is the only finding here that is unambiguously theirs and unambiguously
                // wrong. Then the deadlines, then the catalogue, then the slow-burning ones.
                $app->make(FinanceIntegrityProducer::class),
                $app->make(OrderSlaProducer::class),
                $app->make(OrderStuckProducer::class),
                $app->make(OrderStateProducer::class),
                $app->make(ReturnsRiskProducer::class),
                $app->make(ShippingExceptionProducer::class),
                $app->make(InventoryRiskProducer::class),
                $app->make(PricingRiskProducer::class),
                $app->make(ListingQualityProducer::class),
                $app->make(CatalogIntegrityProducer::class),
                $app->make(BrandComplianceProducer::class),
                $app->make(StaleInventoryProducer::class),
            ],
            // Severity is measured against the seller's own business rather than declared, so both
            // of these are load-bearing: without them a detector's own guess stands, which is the
            // pre-Phase-3 behaviour and the reason a rare product's stockout ranked with a best
            // seller's.
            severity: $app->make(SeverityEngine::class),
            baselines: $app->make(SellerBaselineProvider::class),
        ));

        // What a seller may build a rule out of. Registered rather than discovered for the same
        // reason as the producers: adding a way for the marketplace to change a shop unattended
        // should be a deliberate line in a file somebody reviews, not a class appearing in a folder.
        $this->app->singleton(AutomationRegistry::class, fn ($app) => new AutomationRegistry(
            triggers: [
                $app->make(OutOfStockTrigger::class),
                $app->make(LowStockTrigger::class),
                $app->make(RestockedTrigger::class),
                $app->make(StaleStockTrigger::class),
            ],
            actions: [
                $app->make(HideListingAction::class),
                $app->make(PublishListingAction::class),
                $app->make(SetDiscountAction::class),
            ],
        ));

        // One instance, so its per-request cache is worth having: a bulk price change asks about
        // the same shop's floor once per product, and a service resolved fresh each time would
        // turn that into one query per row.
        $this->app->singleton(PricingPolicyService::class);

        // One measurement of each seller's size per sweep, shared by every detector.
        $this->app->singleton(SellerBaselineProvider::class);

        $loader = AliasLoader::getInstance();
        $loader->alias('Helper', \App\Utils\Helpers::class);
        $loader->alias('Madzipper', \Madnest\Madzipper\Madzipper::class);
        $loader->alias('Excel', \Maatwebsite\Excel\Facades\Excel::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */

    public function boot(): void
    {
        // Authentication events, registered here because App\Providers\EventServiceProvider is not
        // in bootstrap/providers.php — the application relies on listener auto-discovery, and
        // discovery only finds `handle` and `__invoke`, never a subscriber's own method names.
        Event::subscribe(RecordAuthenticationEvents::class);

        if (!in_array(request()->ip(), ['127.0.0.1', '::1']) && env('FORCE_HTTPS')) {
            \URL::forceScheme('https');
        }
        if (!App::runningInConsole()) {
            Paginator::useBootstrap();

            Config::set('addon_admin_routes', $this->getAddonAdminRoutes());
            Config::set('get_payment_publish_status', $this->getPaymentPublishStatus());
            Config::set('get_theme_routes', $this->getThemeRoutesArray());

            try {
                $hasBusinessSettings = Cache::remember('_schema_has_business_settings', 21600, fn() => Schema::hasTable('business_settings'));
                $hasPublishingHouses = Cache::remember('_schema_has_publishing_houses', 21600, fn() => Schema::hasTable('publishing_houses'));
                $hasAuthors = Cache::remember('_schema_has_authors', 21600, fn() => Schema::hasTable('authors'));

                if ($hasBusinessSettings) {
                    $this->setStorageConnectionEnvironment();
                    $this->cacheInHouseShopInTemporaryStatus();

                    $web = $this->cacheBusinessSettingsTable();

                    $firebaseOTPVerification = getWebConfig(name: 'firebase_otp_verification');
                    $firebaseOTPVerificationStatus = (int)($firebaseOTPVerification && $firebaseOTPVerification['status'] && $firebaseOTPVerification['web_api_key']);

                    $headerPublishingHouses = $hasPublishingHouses ? Cache::remember(CACHE_PROVIDER_PUBLISHING_HOUSES_COUNT, 3600, fn() => ProductManager::getPublishingHouseList(type: 'count')) : null;
                    $publishingHouses = $hasPublishingHouses ? Cache::remember(CACHE_PROVIDER_PUBLISHING_HOUSES_LIST, 3600, fn() => ProductManager::getPublishingHouseList()) : null;

                    $systemColors = getWebConfig('colors');
                    $web_config = [
                        'primary_color' => $systemColors['primary'] ?? '',
                        'secondary_color' => $systemColors['secondary'] ?? '',
                        'primary_color_light' => $systemColors['primary_light'] ?? '',
                        'panel_sidebar_color' => $systemColors['panel-sidebar'] ?? '',
                        'name' => Helpers::get_settings($web, 'company_name'),
                        'company_name' => getWebConfig(name: 'company_name'),
                        'phone' => getWebConfig(name: 'company_phone'),
                        'web_logo' => getWebConfig(name: 'company_web_logo'),
                        'mob_logo' => getWebConfig(name: 'company_mobile_logo'),
                        'fav_icon' => getWebConfig(name: 'company_fav_icon'),
                        'email' => getWebConfig(name: 'company_email'),
                        'about' => Helpers::get_settings($web, 'about_us'),
                        'footer_logo' => getWebConfig(name: 'company_footer_logo'),
                        'copyright_text' => getWebConfig(name: 'company_copyright_text'),
                        'decimal_point_settings' => !empty(getWebConfig(name: 'decimal_point_settings')) ? getWebConfig(name: 'decimal_point_settings') : 0,
                        'seller_registration' => getWebConfig(name: 'seller_registration') ?? 0,
                        'wallet_status' => getWebConfig(name: 'wallet_status'),
                        'loyalty_point_status' => getWebConfig(name: 'loyalty_point_status'),
                        'guest_checkout_status' => getWebConfig(name: 'guest_checkout'),
                        'digital_product_setting' => getWebConfig(name: 'digital_product'),
                        'language' => getWebConfig(name: 'language'),
                        'publishing_houses_header' => $headerPublishingHouses,
                        'publishing_houses' => $publishingHouses,
                        'digital_product_authors' => $hasAuthors ? Cache::remember('_product_authors_list', 3600, fn() => ProductManager::getProductAuthorList()) : null,
                        'firebase_otp_verification' => $firebaseOTPVerification,
                        'firebase_otp_verification_status' => $firebaseOTPVerificationStatus,
                        'meta_title' => getWebConfig(name: 'company_name') . ' ' . translate('Online_Shopping') . ' | ' . getWebConfig(name: 'company_name') . ' ' . translate('ecommerce'),
                        'meta_description' => substr(strip_tags(str_replace('&nbsp;', ' ', (BusinessPage::where('slug', 'about-us')->first()?->description ?? ''))), 0, 160),
                    ];

                    if ((!Request::is('admin') && !Request::is('admin/*') && !Request::is('seller/*') && !Request::is('vendor/*')) || Request::is('vendor/auth/registration/*')) {
                        $userId = Auth::guard('customer')->user() ? Auth::guard('customer')->id() : 0;
                        $flashDeal = ProductManager::getPriorityWiseFlashDealsProductsQuery(userId: $userId);

                        $shops = Shop::whereHas('seller', function ($query) {
                            return $query->approved();
                        })->take(9)->get();

                        $paymentGatewayPublishedStatus = config('get_payment_publish_status') ?? 0;

                        $paymentGatewaysQuery = Setting::whereIn('settings_type', ['payment_config'])->where('is_active', 1);
                        if ($paymentGatewayPublishedStatus == 1) {
                            $paymentsGatewaysList = $paymentGatewaysQuery->select('key_name', 'additional_data')->get();
                        } else {
                            $paymentsGatewaysList = $paymentGatewaysQuery->whereIn('key_name', GlobalConstant::DEFAULT_PAYMENT_GATEWAYS)->select('key_name', 'additional_data')->get();
                        }

                        $customerLoginOptions = LoginSetup::where(['key' => 'login_options'])->first()?->value ?? '';
                        $customerSocialLoginOptions = LoginSetup::where(['key' => 'social_media_for_login'])->first()?->value ?? '';
                        $customerSocialLoginOptions = json_decode($customerSocialLoginOptions, true) ?? [];
                        $socialLoginConfigStatus = $this->checkCustomerSocialMediaLoginAbility();

                        foreach ($customerSocialLoginOptions as $socialKey => $socialLoginService) {
                            $customerSocialLoginOptions[$socialKey] = isset($socialLoginConfigStatus[$socialKey]) && $socialLoginConfigStatus[$socialKey] && $socialLoginService ? 1 : 0;
                        }

                        $socialLoginTextShowStatus = false;
                        foreach ($customerSocialLoginOptions as $socialLoginService) {
                            if ($socialLoginService == 1) {
                                $socialLoginTextShowStatus = true;
                            }
                        }
                        $totalDiscountProducts = Cache::remember('_total_discount_products', 3600, function () {
                            $stockClearanceProductIds = StockClearanceProduct::active()->pluck('product_id')->toArray();
                            return Product::active()
                                ->withCount('reviews')
                                ->where(function ($q) use ($stockClearanceProductIds) {
                                    $q->where('discount', '!=', 0)->orWhereIn('id', $stockClearanceProductIds);
                                })->count();
                        });

                        $web_config += [
                            'cookie_setting' => Helpers::get_settings($web, 'cookie_setting'),
                            'announcement' => getWebConfig(name: 'announcement'),
                            'currency_model' => getWebConfig(name: 'currency_model'),
                            'currencies' => Currency::where(['status' => 1])->get(),
                            'main_categories' => $this->cacheMainCategoriesList(),
                            'priority_wise_brands' => $this->cachePriorityWiseBrandList(),
                            'business_mode' => getWebConfig(name: 'business_mode'),
                            'social_media' => SocialMedia::where('active_status', 1)->get(),
                            'ios' => getWebConfig(name: 'download_app_apple_store'),
                            'android' => getWebConfig(name: 'download_app_google_store'),
                            'refund_policy' => getWebConfig(name: 'refund-policy'),
                            'return_policy' => getWebConfig(name: 'return-policy'),
                            'cancellation_policy' => getWebConfig(name: 'cancellation-policy'),
                            'shipping_policy' => getWebConfig(name: 'shipping-policy'),
                            'flash_deals' => $flashDeal['flashDeal'],
                            'flash_deals_products' => $flashDeal['flashDealProducts'] ?? [],
                            'shops' => $shops,
                            'brand_setting' => getWebConfig(name: 'product_brand'),
                            'discount_product' => $totalDiscountProducts,
                            'socials_login' => getWebConfig(name: 'social_login'),
                            'social_login_text' => $socialLoginTextShowStatus,
                            'popup_banner' => $this->cacheBannerTable(bannerType: 'Popup Banner'),
                            'header_banner' => $this->cacheBannerTable(bannerType: 'Header Banner'),
                            'payments_list' => $paymentsGatewaysList, // Fashion_theme
                            'ref_earning_status' => getWebConfig('ref_earning_status'),
                            'customer_login_options' => json_decode($customerLoginOptions, true),
                            'customer_social_login_options' => $customerSocialLoginOptions,
                            'customer_phone_verification' => getLoginConfig(key: 'phone_verification'),
                            'customer_email_verification' => getLoginConfig(key: 'email_verification'),
                            'default_meta_content' => $this->cacheRobotsMetaContent(page: 'default'),
                            'analytic_scripts' => $this->cacheActiveAnalyticScript(),
                            'clearance_sale_product_count' => $this->cacheClearanceSaleProductsCount(),
                            'business_pages' => $this->cacheBusinessPagesTable(),
                        ];

                    }

                    // Language
                    $language = getWebConfig(name: 'language') ?? [];
                    if (!App::runningInConsole() && (Request::is('admin') || Request::is('admin/*'))) {
                        $this->adminSidebarLayoutCount();
                    }
                    // Currency
                    Helpers::currency_load();
                    View::share(['web_config' => $web_config, 'language' => $language]);
                    Schema::defaultStringLength(191);
                }
         } catch (\Throwable $exception) {
                \Log::error('AppServiceProvider boot: ' . $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine());
            }

            try {
                if (!in_array(request()->ip(), ['127.0.0.1', '::1'])) {
                    $this->autoClearDebugBarLogs();
                }
            } catch (Exception $exception) {
            }
        }

        /**
         * Paginate a standard Laravel Collection.
         *
         * @param int $perPage
         * @param int $total
         * @param int $page
         * @param string $pageName
         * @return array
         */

        Collection::macro('paginate', function ($perPage, $total = null, $page = null, $pageName = 'page') {
            $page = $page ?: LengthAwarePaginator::resolveCurrentPage($pageName);

            return new LengthAwarePaginator(
                $this->forPage($page, $perPage),
                $total ?: $this->count(),
                $perPage,
                $page,
                [
                    'path' => LengthAwarePaginator::resolveCurrentPath(),
                    'pageName' => $pageName,
                ]
            );
        });
    }

    protected function adminSidebarLayoutCount(): void
    {
        View::composer('layouts.admin.app', function ($view) {
            $sidebarOrderCounts = Order::selectRaw('order_status, COUNT(*) as total')
                ->groupBy('order_status')
                ->pluck('total', 'order_status');

            $sidebarTotalOrders = $sidebarOrderCounts->sum();
            $sidebarRefundCounts = RefundRequest::selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');
            $sidebarUnseenMessages = Contact::where('seen', 0)->count();
            $view->with(compact(
                'sidebarOrderCounts',
                'sidebarTotalOrders',
                'sidebarRefundCounts',
                'sidebarUnseenMessages',
            ));
        });
    }

    protected function autoClearDebugBarLogs(): void
    {
        $key = 'debugbar:last_clear';
        $minutes = 60;
        $lastClear = Cache::get($key);

        if (!$lastClear || now()->diffInMinutes($lastClear) >= $minutes) {
            $debugBarPath = storage_path('debugbar');
            if (File::exists($debugBarPath)) {
                foreach (File::files($debugBarPath) as $file) {
                    File::delete($file);
                }
            }

            $logFile = storage_path('logs/laravel.log');
            if (File::exists($logFile)) {
                file_put_contents($logFile, '');
            }

            Cache::put($key, now(), $minutes);
        }
    }
}