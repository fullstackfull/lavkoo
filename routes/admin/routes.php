<?php

use App\Http\Controllers\Admin\AdvancedSearchController;
use App\Http\Controllers\Admin\BusinessSettings\WebsiteSetupController;
use App\Http\Controllers\Admin\ExpenseTransactionReportController;
use App\Http\Controllers\Admin\Order\OrderEditController;
use App\Http\Controllers\Admin\Promotion\ClearanceSaleController;
use App\Http\Controllers\Admin\Promotion\ClearanceSalePrioritySetupController;
use App\Http\Controllers\Admin\Promotion\ClearanceSaleVendorOfferController;
use App\Http\Controllers\Admin\Settings\AddonActivationController;
use App\Http\Controllers\Admin\Settings\FirebaseOTPVerificationController;
use App\Http\Controllers\FirebaseController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SharedController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\POS\POSController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\ChattingController;
use App\Http\Controllers\Admin\POS\CartController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\Auth\LoginController;
use App\Http\Controllers\Admin\Order\OrderController;
use App\Http\Controllers\Admin\OrderReportController;
use App\Http\Controllers\Admin\Order\RefundController;
use App\Http\Controllers\Admin\POS\POSOrderController;
use App\Http\Controllers\Admin\Product\BrandController;
use App\Http\Controllers\Admin\ProductReportController;
use App\Http\Controllers\Admin\Vendor\VendorController;
use App\Http\Controllers\Admin\EmailTemplatesController;
use App\Http\Controllers\Admin\Product\ReviewController;
use App\Http\Controllers\Admin\Settings\AddonController;
use App\Http\Controllers\Admin\Settings\PagesController;
use App\Http\Controllers\Admin\Product\ProductController;
use App\Http\Controllers\Admin\ThirdParty\MailController;
use App\Http\Controllers\Admin\Product\CategoryController;
use App\Http\Controllers\Admin\Promotion\BannerController;
use App\Http\Controllers\Admin\Promotion\CouponController;
use App\Http\Controllers\Admin\Settings\SiteMapController;
use App\Http\Controllers\Admin\Customer\CustomerController;
use App\Http\Controllers\Admin\Employee\EmployeeController;
use App\Http\Controllers\Admin\Product\AttributeController;
use App\Http\Controllers\Admin\Settings\CurrencyController;
use App\Http\Controllers\Admin\Settings\LanguageController;
use App\Http\Controllers\Admin\TransactionReportController;
use App\Http\Controllers\Admin\InhouseProductSaleController;
use App\Http\Controllers\Admin\ProductStockReportController;
use App\Http\Controllers\Admin\Settings\ErrorLogsController;
use App\Http\Controllers\Admin\Employee\CustomRoleController;
use App\Http\Controllers\Admin\Product\SubCategoryController;
use App\Http\Controllers\Admin\Promotion\FlashDealController;
use App\Http\Controllers\Admin\CategoryShippingCostController;
use App\Http\Controllers\Admin\Settings\FileManagerController;
use App\Http\Controllers\Admin\Settings\InhouseShopController;
use App\Http\Controllers\Admin\Settings\SEOSettingsController;
use App\Http\Controllers\Admin\ThirdParty\SMSModuleController;
use App\Http\Controllers\Admin\ProductWishlistReportController;
use App\Http\Controllers\Admin\Shipping\ShippingTypeController;
use App\Http\Controllers\Admin\HelpAndSupport\ContactController;
use App\Http\Controllers\Admin\Product\SubSubCategoryController;
use App\Http\Controllers\Admin\Promotion\DealOfTheDayController;
use App\Http\Controllers\Admin\Promotion\FeaturedDealController;
use App\Http\Controllers\Admin\Promotion\MostDemandedController;
use App\Http\Controllers\Admin\Settings\OrderSettingsController;
use App\Http\Controllers\Admin\Settings\PrioritySetupController;
use App\Http\Controllers\Admin\Customer\CustomerWalletController;
use App\Http\Controllers\Admin\Deliveryman\DeliveryManController;
use App\Http\Controllers\Admin\Settings\SoftwareUpdateController;
use App\Http\Controllers\Admin\Settings\VendorSettingsController;
use App\Http\Controllers\Admin\Shipping\ShippingMethodController;
use App\Http\Controllers\Admin\ThirdParty\GoogleMapAPIController;
use App\Http\Controllers\Admin\ThirdParty\WithdrawalMethodController;
use App\Http\Controllers\Admin\VendorProductSaleReportController;
use App\Http\Controllers\Admin\Customer\CustomerLoyaltyController;
use App\Http\Controllers\Admin\HelpAndSupport\HelpTopicController;
use App\Http\Controllers\Admin\Report\RefundTransactionController;
use App\Http\Controllers\Admin\Settings\DatabaseSettingController;
use App\Http\Controllers\Admin\Settings\FeaturesSectionController;
use App\Http\Controllers\Admin\Settings\InvoiceSettingsController;
use App\Http\Controllers\Admin\ThirdParty\PaymentMethodController;
use App\Http\Controllers\Admin\Notification\NotificationController;
use App\Http\Controllers\Admin\Settings\BusinessSettingsController;
use App\Http\Controllers\Admin\Settings\RobotsMetaContentController;
use App\Http\Controllers\Admin\Settings\RedirectController;
use App\Http\Controllers\Admin\Commerce\CampaignController;
use App\Http\Controllers\Admin\Commerce\SegmentController;
use App\Http\Controllers\Admin\Commerce\CollectionController;
use App\Http\Controllers\Admin\Commerce\ExperimentController;
use App\Http\Controllers\Admin\Settings\AppBuilderController;
use App\Http\Controllers\Admin\Settings\ThemeManagementController;
use App\Http\Controllers\Admin\Settings\SeoTemplateController;
use App\Http\Controllers\Admin\Settings\SeoTranslationController;
use App\Http\Controllers\Admin\Settings\ThemeBuilderController;
use App\Http\Controllers\Admin\Settings\ThemeSettingsController;
use App\Http\Controllers\Admin\ThirdParty\SocialMediaChatController;
use App\Http\Controllers\Admin\Deliveryman\EmergencyContactController;
use App\Http\Controllers\Admin\HelpAndSupport\SupportTicketController;
use App\Http\Controllers\Admin\Payment\OfflinePaymentMethodController;
use App\Http\Controllers\Admin\Settings\DeliverymanSettingsController;
use App\Http\Controllers\Admin\Settings\DeliveryRestrictionController;
use App\Http\Controllers\Admin\Settings\EnvironmentSettingsController;
use App\Http\Controllers\Admin\Settings\SocialMediaSettingsController;
use App\Http\Controllers\Admin\SystemSetup\SystemLoginSetupController;
use App\Http\Controllers\Admin\ThirdParty\SocialLoginSettingsController;
use App\Http\Controllers\Admin\Deliveryman\DeliverymanWithdrawController;
use App\Http\Controllers\Admin\Settings\VendorRegistrationReasonController;
use App\Http\Controllers\Admin\Deliveryman\DeliveryManCashCollectController;
use App\Http\Controllers\Admin\Settings\StorageConnectionSettingsController;
use App\Http\Controllers\Admin\Settings\VendorRegistrationSettingController;
use App\Http\Controllers\Admin\Notification\PushNotificationSettingsController;
use App\Http\Controllers\Admin\ThirdParty\MetaAppIntegrationController;
//التكامل مع باقي المنصات////////////////////////////////////////////////////////////////// 30/8/2026
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\IntegrationTemplateController;
use App\Http\Controllers\Admin\CartMarketingController;
use App\Http\Controllers\Admin\ManualMessageController;
use App\Http\Controllers\Admin\AppCarouselController;
use App\Http\Controllers\Admin\ZenderSettingController;
use App\Http\Controllers\Admin\DeliverySyriaSettingController;
/*
| Lynomia/Zender OTP gateway settings, app carousel, manual messages and cart marketing.
|
| These routes used to sit in a bare `admin` prefix group with no authentication at all, so anyone could
| read and change the OTP gateway configuration (including pointing its base URL at their own server and
| pressing "test", which posts the stored API secret there), send messages through the store's messaging
| account, and edit the app carousel. They now carry the same guard as the rest of the admin panel plus
| the module permission of the area each belongs to. See docs/integrations/security-findings.md.
*/
Route::prefix('admin')->as('admin.')->middleware(['admin', 'actch:admin_panel'])->group(function () {
    Route::middleware('module:3rd_party_setup')->group(function () {
        Route::get('/otp/settings', [ZenderSettingController::class, 'show'])->name('zender.settings');
        Route::put('/zender/settings', [ZenderSettingController::class, 'update'])->name('zender.settings.update');
        Route::post('/zender/test', [ZenderSettingController::class, 'test'])->name('zender.test');
        Route::post('/zender/toggle', [ZenderSettingController::class, 'toggleStatus'])->name('zender.settings.toggle');

        Route::prefix('integrations/manual-message')
            ->as('integrations.manual-message.')
            ->controller(ManualMessageController::class)
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/check', 'check')->name('check');
                Route::post('/send', 'send')->name('send');
            });

        Route::prefix('integrations/cart-marketing')
            ->as('integrations.cart-marketing.')
            ->controller(CartMarketingController::class)
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/send', 'send')->name('send');
            });
    });

    Route::prefix('app-carousel')->as('app-carousel.')->middleware('module:marketing')->controller(AppCarouselController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/update', 'update')->name('update');
        Route::post('/delete-image', 'deleteImage')->name('delete-image');
    });
});
/////////////////////////////////////////////////////////////////////////////

Route::controller(SharedController::class)->group(function () {
    Route::post('change-language', 'changeLanguage')->name('change-language');
    Route::post('g-recaptcha-response-store', 'storeRecaptchaResponse')->name('g-recaptcha-response-store');
    Route::get('activation-check', 'getActivationCheckView')->name('system.activation-check');
    Route::post('activation-check', 'activationCheck');
});

Route::controller(FirebaseController::class)->group(function () {
    Route::post('system/subscribe-to-topic', 'subscribeToTopic')->name('system.subscribeToTopic');
});


// Rate limited: captcha was removed by product decision, so this is now the brute-force barrier.
Route::group(['prefix' => 'login', 'middleware' => ['throttle:auth']], function () {
    Route::get('{loginUrl}', [LoginController::class, 'index']);
    Route::post('/', [LoginController::class, 'login'])->name('login');
});

Route::group(['prefix' => 'admin', 'as' => 'admin.', 'middleware' => ['admin', 'actch:admin_panel']], function () {
   



       Route::group(['prefix' => 'meta-app', 'as' => 'meta-app.'], function () {
    Route::controller(MetaAppIntegrationController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('store', 'store')->name('store');
    });});




 // V2 sidebar pin shortcuts (server-side persistence, per-user)
    Route::group(['prefix' => 'v2/sidebar-pins', 'as' => 'v2.sidebar-pins.'], function () {
        Route::get('/', [\App\Http\Controllers\Admin\V2SidebarPinController::class, 'index'])->name('index');
        Route::post('toggle',  [\App\Http\Controllers\Admin\V2SidebarPinController::class, 'toggle'])->name('toggle');
        Route::post('replace', [\App\Http\Controllers\Admin\V2SidebarPinController::class, 'replace'])->name('replace');
    });

    Route::get('component', function () {
        return view('layouts.admin.component');
    });
    Route::get('component-snippets', function () {
        return view('layouts.admin.component-snippets');
    });

    Route::controller(AdvancedSearchController::class)->group(function () {
        Route::get('advanced-search', 'getSearch')->name('advanced-search');
        Route::post('advanced-search-recent', 'recentSearch')->name('advanced-search-recent');
    });
    Route::controller(\App\Http\Controllers\Admin\V2AdvancedSearchController::class)
        ->prefix('v2')->name('v2.')
        ->group(function () {
            Route::get('advanced-search', 'getSearch')->name('advanced-search');
        });
    Route::group(['prefix' => 'dashboard', 'as' => 'dashboard.'], function () {
        Route::controller(DashboardController::class)->group(function () {
            Route::get('', 'index')->name('index');
            Route::post('order-status', 'getOrderStatus')->name('order-status');
            Route::get('earning-statistics', 'getEarningStatistics')->name('earning-statistics');
            Route::get('order-statistics', 'getOrderStatistics')->name('order-statistics');
            Route::get('real-time-activities', 'getRealTimeActivities')->name('real-time-activities');
        });
    });

    // One searchable index over every settings page. The 71 templates under
    // business-settings alone span four route prefixes with no shared entry point.
    Route::get('settings', function (\App\Services\Admin\SettingsIndexService $settings) {
        $groups = $settings->groups();
        return view('admin-views.settings.index', ['groups' => $groups, 'total' => $settings->count($groups)]);
    })->name('settings.index');

    /*
    | The unified audit center (spec item 84). Read-only: no edit or delete path exists.
    |
    | Its own group, gated on system settings rather than on the marketplace module it used to sit
    | inside. Theme changes, commerce, the developer console and approvals all write to this trail,
    | and an admin role without the unrelated marketplace flag could not read a single row of any of
    | it. The URL and the route name are unchanged, so every existing link keeps working.
    */
    Route::group(['prefix' => 'marketplace', 'as' => 'marketplace.', 'middleware' => ['module:system_settings']], function () {
        Route::get('audit-log', [\App\Http\Controllers\Admin\Marketplace\AuditLogController::class, 'index'])->name('audit-log');
    });

    /*
    | Authentication security: the bot defence on every sign-in form, and how a customer recovers
    | their account. Both had settings rows and no writer anywhere in the admin panel.
    */
    Route::controller(\App\Http\Controllers\Admin\Settings\AuthenticationSecurityController::class)
        ->prefix('settings/authentication')->name('settings.authentication.')->middleware('module:system_settings')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'update')->name('update');
        });

    /*
    | Platform policies: every rule the platform applies to itself, in one place.
    |
    | The control-surface audit found ninety hard-coded thresholds that a marketplace would want to
    | set — what counts as low stock, how long a courier may go quiet, how short a password may be.
    | They are declared in PolicyRegistry and edited here; the page is generated from the
    | declarations, so a new rule needs no route, controller or template of its own.
    */
    Route::controller(\App\Http\Controllers\Admin\Settings\PlatformPolicyController::class)
        ->prefix('settings/policies')->name('settings.policies.')->middleware('module:system_settings')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('{group}', 'update')->name('update');
            // Last, so it cannot swallow the named routes above.
            Route::get('{group}', 'index')->name('group');
        });

    // Kohl design-system gallery. Every primitive on one page, so a change to the
    // system is reviewable in the real admin shell — in light/dark and LTR/RTL —
    // rather than discovered later on a production screen.
    Route::view('design-system', 'admin-views.kohl.gallery')->name('design-system');

    /*
    | Developer portal: the live API surface for the app teams, the vendor app and any outside
    | integration — derived from the route table, the controllers and their validation, so it
    | cannot describe an endpoint this application does not serve.
    */
    Route::controller(\App\Http\Controllers\Admin\Telemetry\DeveloperPortalController::class)
        ->prefix('developer')->name('developer.')->middleware('module:system_settings')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            // Downloads live under their own prefix. Named /openapi and /postman they would sit on
            // the same paths as the sections that describe them, and the named routes win over the
            // {section} catch-all — so the two documentation pages became unreachable file
            // downloads, which a browser check caught by trying to navigate to one.
            Route::get('download/openapi', 'openapi')->name('openapi');
            Route::get('download/postman', 'postman')->name('postman');
            Route::get('endpoint/{id}', 'endpoint')->name('endpoint');
            // How other sections link in. They know an endpoint by its path, not by the portal's
            // internal id, so they hand over the path and the portal resolves it.
            Route::get('lookup', 'lookup')->name('lookup');
            // Writes, so they are POSTs: capturing a snapshot records history, and rebuilding the
            // manifest discards a cache. Neither belongs behind a link a browser can prefetch.
            // The console is a POST for the same reason and a stronger one — it SENDS a request at
            // the live API, which is not something a prefetch, a crawler or a shared link may do.
            Route::post('try/{id}', 'try')->name('try');
            Route::post('snapshot', 'snapshot')->name('snapshot');
            Route::post('refresh', 'refresh')->name('refresh');
            // Last, so it cannot swallow the named routes above.
            Route::get('{section}', 'index')->name('section');
        });

    /*
    | Analytics: behaviour, not traffic. Visits, sources, funnels, products, search and revenue,
    | read from the analytics rollups with today spliced in live.
    */
    Route::controller(\App\Http\Controllers\Admin\Telemetry\AnalyticsController::class)
        ->prefix('analytics')->name('analytics.')->middleware('module:reports')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            // Named routes first: a {section} catch-all registered before these would swallow them.
            Route::get('live-feed', 'live')->name('live-feed');
            Route::get('export/{dimension}', 'export')->name('export');
            Route::get('campaign/{id}/qr', 'campaignQr')->name('campaign.qr')->whereNumber('id');
            // Writes are POSTs: creating a campaign issues a public link, and toggling one takes a
            // live link out of service. Neither belongs behind a URL a browser can prefetch.
            Route::post('campaign', 'storeCampaign')->name('campaign.store');
            Route::post('campaign/{id}/toggle', 'toggleCampaign')->name('campaign.toggle')->whereNumber('id');
            Route::get('{section}', 'index')->name('section');
        });

    // Monitoring: the live state of the server, the store and both apps. The
    // pulse feed is excluded from telemetry so the page cannot watch itself.
    Route::group(['prefix' => 'monitoring', 'as' => 'monitoring.', 'middleware' => ['module:system_settings']], function () {
        Route::controller(\App\Http\Controllers\Admin\Telemetry\MonitoringController::class)->group(function () {
            Route::get('', 'index')->name('index');
            // The header strip's own feed: polled every few seconds, so it stays cheap and
            // separate from a section's full payload.
            Route::get('pulse', 'pulse')->name('pulse');
            // The two writes in this otherwise read-only area, both POST and both declared before
            // the catch-all below so they are named actions rather than section names.
            //
            // Put a failed job back on its queue, or drop it.
            Route::post('queues/failed/{action}/{uuid}', 'failedJob')
                ->where('action', 'retry|discard')
                ->name('failed-job');
            // Queue a full rebuild of the product search index, for after a bulk import writes rows
            // the index observer never saw.
            Route::post('search/rebuild', 'rebuildSearchIndex')->name('search.rebuild');
            // Every section on one route. A new section needs an entry in MonitoringNavigation and
            // a panel class — nothing here changes, so the two can never drift apart.
            Route::get('{section}', 'index')->name('section')->where('section', '[a-z0-9\-]+');
        });

        /*
        | The writes. Declared in their own controller so the reader above stays a reader, and all
        | of them POST — an action that changes what monitoring watches must not sit behind a URL a
        | browser can prefetch. Registered BEFORE the {section} catch-all above would matter if they
        | shared a prefix; they do not, because each lives under its own segment.
        */
        Route::controller(\App\Http\Controllers\Admin\Telemetry\MonitoringActionController::class)
            ->prefix('actions')->name('actions.')
            ->group(function () {
                Route::post('settings', 'saveSettings')->name('settings');
                Route::post('synthetics/add', 'addJourney')->name('synthetics.add');
                Route::post('synthetics/remove', 'removeJourney')->name('synthetics.remove');
                Route::post('annotate', 'annotate')->name('annotate');
                Route::post('backups/recorded', 'recordBackup')->name('backups.recorded');
                Route::post('backups/restore-tested', 'recordRestoreTest')->name('backups.restore-tested');
                Route::post('deployments/recorded', 'recordDeployment')->name('deployments.recorded');
                Route::post('incidents/{action}/{id}', 'incident')
                    ->where('action', 'acknowledge|note|attribute|resolve')->whereNumber('id')
                    ->name('incident');
                Route::post('alerts/{action}', 'alertRule')
                    ->where('action', 'save|enable|silence|delete|seed')
                    ->name('alert-rule');
            });
    });

    Route::get('logout', [LoginController::class, 'logout'])->name('logout');

    Route::group(['prefix' => 'pos', 'as' => 'pos.', 'middleware' => ['module:pos']], function () {
        Route::controller(POSController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::any('change-customer', 'changeCustomer')->name('change-customer');
            Route::post('update-discount', 'updateDiscount')->name('update-discount');
            Route::post('coupon-discount', 'getCouponDiscount')->name('coupon-discount');
            Route::get('quick-view', 'getQuickView')->name('quick-view');
            Route::get('search-product', 'getSearchedProductsView')->name('search-product');
        });

        Route::controller(CartController::class)->group(function () {
            Route::post('get-variant-price', 'getVariantPrice')->name('get-variant-price');
            Route::post('quantity-update', 'updateQuantity')->name('update-quantity');
            Route::get('get-cart-ids', 'getCartIds')->name('get-cart-ids');
            Route::get('clear-cart-ids', 'clearSessionCartIds')->name('clear-cart-ids');
            Route::post('add-to-cart', 'addToCart')->name('add-to-cart');
            Route::post('cart-remove', 'removeCart')->name('remove-cart');
            Route::any('cart-empty', 'emptyCart')->name('empty-cart');
            Route::any('change-cart', 'changeCart')->name('change-cart');
            Route::get('new-cart-id', 'addNewCartId')->name('new-cart-id');
        });

        Route::controller(POSOrderController::class)->group(function () {
            Route::post('order-details/{id}', 'index')->name('order-details');
            Route::post('order-place', 'placeOrder')->name('place-order');
            Route::any('cancel-order', 'cancelOrder')->name('cancel-order');
            Route::any('view-hold-orders', 'getAllHoldOrdersView')->name('view-hold-orders');
        });
    });

    Route::group(['prefix' => 'profile', 'as' => 'profile.'], function () {
        Route::controller(ProfileController::class)->group(function () {
            Route::get('update/{id}', 'getUpdateView')->name('update');
            Route::post('update/{id}', 'update');
            Route::patch('update/{id}', 'updatePassword');
        });
    });

    Route::group(['prefix' => 'products', 'as' => 'products.', 'middleware' => ['module:catalog']], function () {
        Route::controller(ProductController::class)->group(function () {
            Route::get('list/{type}', 'index')->name('list');
            Route::get('add', 'getAddView')->name('add');
            Route::post('add', 'add')->name('store');
            Route::get('view/{addedBy}/{id}', 'getView')->name('view');
            Route::post('sku-combination', 'getSkuCombinationView')->name('sku-combination');
            Route::post('digital-variation-combination', 'getDigitalVariationCombinationView')->name('digital-variation-combination');
            Route::post('digital-variation-file-delete', 'deleteDigitalVariationFile')->name('digital-variation-file-delete');
            Route::post('featured-status', 'updateFeaturedStatus')->name('featured-status');
            Route::get('get-categories', 'getCategories')->name('get-categories');
            Route::post('status-update', 'updateStatus')->name('status-update');
            // Bulk publish / unpublish / feature. Delegates per product, so the
            // vendor-approval rule stays in updateStatus() alone.
            Route::post('bulk-product-status', 'bulkUpdateStatus')->name('bulk-product-status');
            Route::get('barcode/{id}', 'getBarcodeView')->name('barcode');
            Route::get('export-excel/{type}', 'exportList')->name('export-excel');
            Route::get('stock-limit-list/{type}', 'getStockLimitListView')->name('stock-limit-list');
            Route::delete('delete/{id}', 'delete')->name('delete');
            Route::get('update/{id}', 'getUpdateView')->name('update');
            Route::post('update/{id}', 'update');
            Route::post('update-product-images/{id}', 'updateProductImages')->name('update-product-images');
            Route::get('delete-image', 'deleteImage')->name('delete-image');
            Route::get('get-variations', 'getVariations')->name('get-variations');
            Route::post('update-quantity', 'updateQuantity')->name('update-quantity');
            Route::get('bulk-import', 'getBulkImportView')->name('bulk-import');
            Route::post('bulk-import', 'importBulkProduct');
            Route::get('updated-product-list/{type}', 'updatedProductList')->name('updated-product-list');
            Route::post('updated-shipping', 'updatedShipping')->name('updated-shipping');
            Route::post('deny', 'deny')->name('deny');
            Route::post('approve-status', 'approveStatus')->name('approve-status');
            Route::get('product-gallery', 'getProductGalleryView')->name('product-gallery');
            Route::get('stock-limit-status/{type}', 'getStockLimitStatus')->name('stock-limit-status');
            Route::post('delete-preview-file', 'deletePreviewFile')->name('delete-preview-file');
            Route::get('request-restock-list', 'getRequestRestockListView')->name('request-restock-list');
            Route::get('export-restock', 'exportRestockList')->name('restock-export');
            Route::delete('restock-delete/{id}', 'deleteRestock')->name('restock-delete');
        });
    });

    Route::group(['prefix' => 'products', 'as' => 'products.'], function () {
        Route::controller(ProductController::class)->group(function () {
            Route::get('multiple-product-details', 'getMultipleProductDetailsView')->name('multiple-product-details');
            Route::get('search', 'getSearchedProductsView')->name('search-product');
            Route::get('search-all-product', 'getSearchedAllProductsView')->name('search-all-type-product');
            // Pickers on the product form (frequently bought together): searchable, id-based.
            Route::get('picker-options', 'getProductPickerOptions')->name('picker-options');
            Route::get('picker-labels', 'getProductPickerLabels')->name('picker-labels');
        });
    });

    Route::group(['prefix' => 'orders', 'as' => 'orders.', 'middleware' => ['module:orders']], function () {
        Route::controller(\App\Http\Controllers\Admin\Order\DeliverySyriaDispatchController::class)->group(function () {
            Route::post('delivery-syria/dispatch', 'dispatchOrder')->name('delivery-syria.dispatch');
        });
        Route::controller(OrderController::class)->group(function () {
            Route::get('list/{status}', 'index')->name('list');
            Route::get('export-excel/{status}', 'exportList')->name('export-excel');
            Route::get('generate-invoice/{id}', 'generateInvoice')->name('generate-invoice')->withoutMiddleware(['module:orders']);
            Route::get('details/{id}', 'getView')->name('details');
            Route::post('address-update', 'updateAddress')->name('address-update'); // update address from order details
            Route::post('update-deliver-info', 'updateDeliverInfo')->name('update-deliver-info');
            Route::get('add-delivery-man/{order_id}/{d_man_id}', 'addDeliveryMan')->name('add-delivery-man');
            Route::post('amount-date-update', 'updateAmountDate')->name('amount-date-update');
            Route::get('customers', 'getCustomers')->name('customers');
            Route::post('payment-status', 'updatePaymentStatus')->name('payment-status');
            Route::get('inhouse-order-filter', 'filterInHouseOrder')->name('inhouse-order-filter');
            Route::post('digital-file-upload-after-sell', 'uploadDigitalFileAfterSell')->name('digital-file-upload-after-sell');
            Route::post('status', 'updateStatus')->name('status');
            // Bulk status change. Delegates per order to updateStatus, so every guard
            // and side effect of a single change applies to each one in the batch.
            Route::post('bulk-status', 'bulkUpdateStatus')->name('bulk-status');
            Route::post('customer-return-amount', 'orderReturnAmountToCustomer')->name('customer-return-amount');
            Route::post('customer-due-amount', 'orderDueAmountSwitchToCOD')->name('customer-due-amount');
            Route::post('customer-due-amount-mark-as-paid', 'orderDueAmountMarkAsPaid')->name('customer-due-amount-mark-as-paid');
        });

        Route::controller(OrderEditController::class)->group(function () {
            Route::get('search-for-edit-order-product', 'getSearchEditOrderProductsView')->name('search-for-edit-order-product');
            Route::post('edit-order-product-modal-view', 'getEditOrderProductModalView')->name('edit-order-product-modal-view');
            Route::post('edit-order-product-add', 'addEditOrderProduct')->name('edit-order-product-add');
            Route::post('edit-order-product-variant-price', 'checkProductVariantPrice')->name('edit-order-product-variant-price');
            Route::post('edit-order-product-list-update', 'updateEditOrderProductList')->name('edit-order-product-list-update');
            Route::post('edit-order-product-remove', 'removeEditOrderProduct')->name('edit-order-product-remove');
            Route::post('edit-order-generate', 'generateEditOrderByProductList')->name('edit-order-generate');
        });
    });

    // Attribute
    Route::group(['prefix' => 'attribute', 'as' => 'attribute.', 'middleware' => ['module:catalog']], function () {
        Route::controller(AttributeController::class)->group(function () {
            Route::get('view', 'index')->name('view');
            Route::post('store', 'add')->name('store');
            Route::get('update/{id}', 'getUpdateView')->name('update');
            Route::get('get-translation-data/{id}', 'getTranslationData')->name('translation-data');
            Route::post('update/{id}', 'update');
            Route::post('delete', 'delete')->name('delete');
        });
    });

    // Brand
    Route::group(['prefix' => 'brand', 'as' => 'brand.', 'middleware' => ['module:catalog']], function () {
        Route::controller(BrandController::class)->group(function () {
            Route::get('list', 'index')->name('list');
            Route::get('add-new', 'getAddView')->name('add-new');
            Route::post('add-new', 'add');
            Route::get('update/{id}', 'getUpdateView')->name('update');
            Route::post('update/{id}', 'update');
            Route::post('delete', 'delete')->name('delete');
            Route::get('export', 'exportList')->name('export');
            Route::post('status-update', 'updateStatus')->name('status-update');
            Route::post('load-more-brands', 'loadMoreBrands')->name('load-more-brands');
        });
    });

    // Category
    Route::group(['prefix' => 'category', 'as' => 'category.', 'middleware' => ['module:catalog']], function () {
        Route::controller(CategoryController::class)->group(function () {
            Route::get('view', 'index')->name('view');
            Route::post('add-new', 'add')->name('store');
            Route::get('update', 'getUpdateView')->name('update');
            Route::post('update', 'update');
            Route::post('delete', 'delete')->name('delete');
            Route::post('status', 'updateStatus')->name('status');
            Route::get('export', 'getExportList')->name('export');
        });
    });

    // Sub Category
    Route::group(['prefix' => 'sub-category', 'as' => 'sub-category.', 'middleware' => ['module:catalog']], function () {
        Route::controller(SubCategoryController::class)->group(function () {
            Route::get('view', 'index')->name('view');
            Route::post('store', 'add')->name('store');
            Route::post('update/{id}', 'update');
            Route::post('delete', 'delete')->name('delete');
            Route::get('export', 'getExportList')->name('export');
            Route::post('load-more-categories', 'loadMoreCategories')->name('load-more-categories');
        });
    });

    // Sub Sub Category
    Route::group(['prefix' => 'sub-sub-category', 'as' => 'sub-sub-category.', 'middleware' => ['module:catalog']], function () {
        Route::controller(SubSubCategoryController::class)->group(function () {
            Route::get('view', 'index')->name('view');
            Route::post('store', 'add')->name('store');
            Route::post('update/{id}', 'update');
            Route::post('delete', 'delete')->name('delete');
            Route::post('get-sub-category', 'getSubCategory')->name('getSubCategory');
            Route::get('export', 'getExportList')->name('export');
        });
    });

    // Banner
    Route::group(['prefix' => 'banner', 'as' => 'banner.', 'middleware' => ['module:marketing']], function () {
        Route::controller(BannerController::class)->group(function () {
            Route::get('list', 'index')->name('list');
            Route::get('placement-guide', 'getPlacementGuide')->name('placement-guide');
            Route::get('theme-layout', 'getThemeLayoutView')->name('theme-layout');
            Route::post('add', 'add')->name('store');
            Route::post('delete', 'delete')->name('delete');
            Route::post('status', 'updateStatus')->name('status');
            Route::get('update/{id}', 'getUpdateView')->name('update');
            Route::post('update/{id}', 'update');
        });
    });
    // Customer Routes, Customer wallet Routes, Customer Loyalty Routes
    Route::group(['prefix' => 'customer', 'as' => 'customer.', 'middleware' => ['module:people']], function () {
        Route::controller(CustomerController::class)->group(function () {
            Route::get('list', 'index')->name('list');
            Route::get('view/{user_id}', 'getView')->name('view');
            Route::get('order-list-export/{user_id}', 'exportOrderList')->name('order-list-export');
            Route::post('status-update', 'updateStatus')->name('status-update');
            // Bulk block / unblock. Delegates per customer so each one still has their
            // tokens revoked and receives the notification.
            Route::post('bulk-status', 'bulkUpdateStatus')->name('bulk-status');
            Route::delete('delete/{id}', 'deleteCustomer')->name('delete');
            Route::get('subscriber-list', 'getSubscriberListView')->name('subscriber-list');
            Route::get('subscriber-list/export', 'exportSubscribersList')->name('subscriber-list.export');
            Route::get('export', 'exportList')->name('export');
            Route::get('customer-list-search', 'getCustomerList')->name('customer-list-search');
            Route::get('customer-list-without-all-customer', 'getCustomerListWithoutAllCustomerName')->name('customer-list-without-all-customer');
            Route::post('add', 'add')->name('add');
            Route::post('profile-update', 'updateProfile')->name('profile-update');
        });

        Route::group(['prefix' => 'wallet', 'as' => 'wallet.'], function () {
            Route::controller(CustomerWalletController::class)->group(function () {
                Route::get('report', 'index')->name('report');
                Route::post('add-fund', 'addFund')->name('add-fund');
                Route::get('export', 'exportList')->name('export');
                Route::get('bonus-setup', 'getBonusSetupView')->name('bonus-setup');
                Route::post('bonus-setup', 'addBonusSetup');
                Route::post('bonus-setup-update', 'update')->name('bonus-setup-update');
                Route::post('bonus-setup-status', 'updateStatus')->name('bonus-setup-status');
                Route::get('bonus-setup/edit/{id}', 'getUpdateView')->name('bonus-setup-edit');
                Route::delete('bonus-setup-delete', 'deleteBonus')->name('bonus-setup-delete');
            });
        });

        Route::group(['prefix' => 'loyalty', 'as' => 'loyalty.'], function () {
            Route::controller(CustomerLoyaltyController::class)->group(function () {
                Route::get('report', 'index')->name('report');
                Route::get('export', 'exportList')->name('export');
            });
        });
    });

    Route::group(['prefix' => 'report', 'as' => 'report.', 'middleware' => ['module:reports']], function () {
        Route::controller(InhouseProductSaleController::class)->group(function () {
            Route::get('inhouse-product-sale', 'index')->name('inhouse-product-sale');
        });
    });

    Route::group(['prefix' => 'vendors', 'as' => 'vendors.', 'middleware' => ['module:people']], function () {
        Route::controller(VendorController::class)->group(function () {
            Route::get('list', 'index')->name('vendor-list');
            Route::get('add', 'getAddView')->name('add');
            Route::POST('add', 'add');
            Route::get('order-list-export/{vendor_id}', 'exportOrderList')->name('order-list-export');
            Route::post('status', 'updateStatus')->name('updateStatus');
            // Bulk approve / suspend / reject. Delegates per vendor so each transition
            // still picks its own email template and suspension still rotates the token.
            Route::post('bulk-status', 'bulkUpdateStatus')->name('bulk-status');
            Route::get('export', 'exportList')->name('export');

            Route::post('sales-commission-update/{id}', 'updateSalesCommission')->name('sales-commission-update');
            Route::get('order-details/{order_id}/{vendor_id}', 'getOrderDetailsView')->name('order-details');
            Route::get('view/{id}/{tab?}', 'getView')->name('view');
            Route::post( 'update_setting/{id}', 'updateSetting')->name('update-setting');

            Route::get('withdraw-list', 'getWithdrawListView')->name('withdraw_list');
            Route::get('withdraw-list-export-excel', 'exportWithdrawList')->name('withdraw-list-export-excel');
            Route::get('withdraw-view/{withdrawId}/{vendorId}', 'getWithdrawView')->name('withdraw_view');
            Route::post('withdraw-status/{id}', 'withdrawStatus')->name('withdraw_status');

            Route::post('load-more-stores', 'loadMoreStores')->name('load-more-stores');
                       Route::delete('{id}', 'destroy')->name('destroy');  

        });

    });


    Route::group(['prefix' => 'employee', 'as' => 'employee.', 'middleware' => ['module:people']], function () {
        Route::controller(EmployeeController::class)->group(function () {
            Route::get('list', 'index')->name('list');
            Route::get('add', 'getAddView')->name('add-new');
            Route::post('add', 'add')->name('add-new-post');
            Route::get('export', 'exportList')->name('export');
            Route::get('view/{id}', 'getView')->name('view');
            Route::get('update/{id}', 'getUpdateView')->name('update');
            Route::post('update/{id}', 'update');
            Route::post('status', 'updateStatus')->name('status');
        });
    });

    Route::group(['prefix' => 'custom-role', 'as' => 'custom-role.', 'middleware' => ['module:people']], function () {
        Route::controller(CustomRoleController::class)->group(function () {
            Route::get('add', 'index')->name('create');
            Route::post('add', 'add')->name('store');
            Route::get('update/{id}', 'getUpdateView')->name('update');
            Route::post('update/{id}', 'update');
            Route::post('employee-role-status', 'updateStatus')->name('employee-role-status');
            Route::post('delete', 'delete')->name('delete');
            Route::get('export', 'exportList')->name('export');
        });
    });

    /*  report */
    // Marketplace financial core (Phase 3, Stage B): settlements, and the vendor ledger they read.
    // Under the same reports module the earning reports already live under, since it is the same
    // audience — whoever reconciles the marketplace's money.
    Route::group(['prefix' => 'marketplace', 'as' => 'marketplace.', 'middleware' => ['module:marketplace']], function () {
        Route::controller(\App\Http\Controllers\Admin\Marketplace\SettlementController::class)->group(function () {
            Route::group(['prefix' => 'settlements', 'as' => 'settlements.'], function () {
                // What the marketplace promises its sellers about when they are paid.
                Route::post('terms', [\App\Http\Controllers\Admin\Marketplace\SettlementController::class, 'updateTerms'])->name('terms');
                Route::get('/', 'index')->name('index');
                Route::post('calculate', 'calculate')->name('calculate');
                Route::post('toggle-maker-checker', 'toggleMakerChecker')->name('toggle-maker-checker');
                Route::get('{id}', 'show')->whereNumber('id')->name('show');
                Route::post('{id}/approve', 'approve')->whereNumber('id')->name('approve');
                Route::post('{id}/mark-paid', 'markPaid')->whereNumber('id')->name('mark-paid');
                Route::post('{id}/cancel', 'cancel')->whereNumber('id')->name('cancel');
            });
            Route::get('ledger/{sellerId}', 'ledger')->whereNumber('sellerId')->name('ledger');
        });

        // Admin lifecycle for seller-initiated ledger payouts: review/approve, mark paid, or reject
        // (releasing the reservation). The seller side shipped without this, leaving requested payouts
        // with no way to be approved or paid.
        Route::controller(\App\Http\Controllers\Admin\Marketplace\PayoutController::class)->group(function () {
            Route::group(['prefix' => 'payouts', 'as' => 'payouts.'], function () {
                Route::get('/', 'index')->name('index');
                Route::post('{id}/approve', 'approve')->whereNumber('id')->name('approve');
                Route::post('{id}/mark-paid', 'markPaid')->whereNumber('id')->name('mark-paid');
                // What happens after the bank sends it back. Both are POSTs and both move money.
                Route::post('{id}/mark-failed', 'markFailed')->whereNumber('id')->name('mark-failed');
                Route::post('{id}/reissue', 'reissue')->whereNumber('id')->name('reissue');
                Route::post('{id}/reject', 'reject')->whereNumber('id')->name('reject');
            });
        });

        // The approvals inbox (spec item 82): the operable surface of the reusable maker-checker
        // engine. The engine enforces maker != checker and one-decision-per-actor; the controller
        // only carries the admin's identity in.
        Route::group(['prefix' => 'approvals', 'as' => 'approvals.'], function () {
            Route::controller(\App\Http\Controllers\Admin\Marketplace\ApprovalController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('{id}/approve', 'approve')->whereNumber('id')->name('approve');
                Route::post('{id}/reject', 'reject')->whereNumber('id')->name('reject');
            });
        });

        // Product moderation with history (spec item 7): approve/reject/needs-changes/suspend, single
        // and bulk, each writing a history event and an audit line. Separate from the legacy product
        // controller so the moderation trail is added without touching the save path.
        Route::group(['prefix' => 'product-moderation', 'as' => 'product-moderation.'], function () {
            Route::controller(\App\Http\Controllers\Admin\Marketplace\ProductModerationController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('history/{productId}', 'history')->whereNumber('productId')->name('history');
                Route::post('moderate', 'moderate')->name('moderate');
                Route::post('bulk', 'bulk')->name('bulk');
            });
        });

        // Per-category governance (spec item 10): return window, tax class, required attributes and a
        // moderation flag, each inheriting the global default when left blank.
        Route::group(['prefix' => 'category-governance', 'as' => 'category-governance.'], function () {
            Route::controller(\App\Http\Controllers\Admin\Marketplace\CategoryGovernanceController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('update', 'update')->name('update');
            });
        });

        // Seller KYC verification: review submitted documents and arm the (default-off) payout gate.
        Route::group(['prefix' => 'seller-verification', 'as' => 'seller-verification.'], function () {
            Route::controller(\App\Http\Controllers\Admin\Marketplace\SellerVerificationController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('document/{id}', 'document')->whereNumber('id')->name('document');
                Route::post('approve', 'approve')->name('approve');
                Route::post('reject', 'reject')->name('reject');
                Route::post('settings', 'updateSettings')->name('settings');
            });
        });

        // Brand registry: who may sell under a brand, on what evidence, and the switch that turns
        // the answer from a report into a refusal. Every decision on this page is a person's.
        Route::group(['prefix' => 'brand-registry', 'as' => 'brand-registry.'], function () {
            Route::controller(\App\Http\Controllers\Admin\Marketplace\BrandRegistryController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('document/{id}', 'document')->whereNumber('id')->name('document');
                Route::post('approve', 'approve')->name('approve');
                Route::post('reject', 'reject')->name('reject');
                Route::post('revoke', 'revoke')->name('revoke');
                Route::post('enforcement', 'updateEnforcement')->name('enforcement');
            });
        });

        // What the sellers are doing with the platform: rules that change catalogues unattended,
        // keys that act as a shop without a person, endpoints we call, staff who are not the
        // account holder, bulk operations. Read-mostly. The three writes are the interventions
        // only the marketplace can make — stopping a rule that is damaging a catalogue, killing a
        // leaked key, and switching off an endpoint being hammered — and each is recorded.
        Route::group(['prefix' => 'seller-operations', 'as' => 'seller-operations.'], function () {
            Route::controller(\App\Http\Controllers\Admin\Marketplace\SellerOperationsController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('issues', 'issues')->name('issues');
                Route::get('automation', 'automation')->name('automation');
                Route::get('integrations', 'integrations')->name('integrations');
                Route::get('team', 'team')->name('team');
                Route::get('bulk-jobs', 'bulkJobs')->name('bulk-jobs');
                Route::post('suspend-rule', 'suspendRule')->name('suspend-rule');
                Route::post('release-rule', 'releaseRule')->name('release-rule');
                Route::post('revoke-key', 'revokeKey')->name('revoke-key');
                Route::post('disable-webhook', 'disableWebhook')->name('disable-webhook');
            });
        });

        // Seller performance scorecard: quality metrics and a derived health tier per seller.
        Route::group(['prefix' => 'seller-scorecard', 'as' => 'seller-scorecard.'], function () {
            Route::controller(\App\Http\Controllers\Admin\Marketplace\SellerScorecardController::class)->group(function () {
                Route::get('/', 'index')->name('index');
            });
        });

        // Procurement (Stage C): suppliers and purchase orders. Receiving a PO line increments the
        // catalogue stock the storefront sells — the supply side of the same inventory.
        Route::group(['prefix' => 'suppliers', 'as' => 'suppliers.'], function () {
            Route::controller(\App\Http\Controllers\Admin\Marketplace\SupplierController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->name('store');
                Route::put('{id}', 'update')->whereNumber('id')->name('update');
                Route::delete('{id}', 'destroy')->whereNumber('id')->name('destroy');
            });
        });
        Route::group(['prefix' => 'purchase-orders', 'as' => 'purchase-orders.'], function () {
            Route::controller(\App\Http\Controllers\Admin\Marketplace\PurchaseOrderController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('create', 'create')->name('create');
                Route::post('/', 'store')->name('store');
                Route::get('{id}', 'show')->whereNumber('id')->name('show');
                Route::post('{id}/place', 'place')->whereNumber('id')->name('place');
                Route::post('{id}/receive', 'receive')->whereNumber('id')->name('receive');
                Route::post('{id}/cancel', 'cancel')->whereNumber('id')->name('cancel');
            });
        });

        // Inventory adjustments + movement log (Stage C): reasoned stock changes with an auditable
        // history, seeded here and by purchase-order receipts.
        Route::group(['prefix' => 'inventory-adjustments', 'as' => 'inventory-adjustments.'], function () {
            Route::controller(\App\Http\Controllers\Admin\Marketplace\InventoryAdjustmentController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('adjust', 'adjust')->name('adjust');
            });
        });

        // Seller SLA policy (Stage E): configurable thresholds over the scorecard metrics, evaluated
        // into a breach ledger that opens on a crossed line and clears on recovery.
        Route::group(['prefix' => 'sla', 'as' => 'sla.'], function () {
            Route::controller(\App\Http\Controllers\Admin\Marketplace\SlaController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('settings', 'updateSettings')->name('settings');
                Route::post('evaluate', 'evaluate')->name('evaluate');
            });
        });

        // Returns logistics (Stage C): the RMA queue and its state machine — receiving a restockable
        // return puts stock back through the inventory movement log.
        Route::group(['prefix' => 'returns', 'as' => 'returns.'], function () {
            Route::controller(\App\Http\Controllers\Admin\Marketplace\ReturnLogisticsController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->name('store');
                Route::post('{id}/transit', 'transit')->whereNumber('id')->name('transit');
                Route::post('{id}/receive', 'receive')->whereNumber('id')->name('receive');
                Route::post('{id}/reject', 'reject')->whereNumber('id')->name('reject');
            });
        });

        // Financial reconciliation (Stage E): read-only integrity checks over the ledger, commission
        // snapshots and settlements.
        Route::get('reconciliation', [\App\Http\Controllers\Admin\Marketplace\ReconciliationController::class, 'index'])->name('reconciliation');

        // Batch & expiry tracking (Stage C): dated batches, expiry visibility, and write-off of
        // expired stock through the inventory adjustment path.
        Route::group(['prefix' => 'batches', 'as' => 'batches.'], function () {
            Route::controller(\App\Http\Controllers\Admin\Marketplace\BatchController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->name('store');
                Route::post('{id}/write-off', 'writeOff')->whereNumber('id')->name('write-off');
            });
        });

        // Multi-warehouse (Stage C): a location registry that partitions the real current_stock, with
        // placement from the unallocated remainder and inter-warehouse transfers (sellable total kept).
        Route::group(['prefix' => 'warehouses', 'as' => 'warehouses.'], function () {
            Route::controller(\App\Http\Controllers\Admin\Marketplace\WarehouseController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->name('store');
                Route::put('{id}', 'update')->whereNumber('id')->name('update');
                Route::post('place', 'place')->name('place');
                Route::post('transfer', 'transfer')->name('transfer');
            });
        });

        // Fulfilment (Stage C): the pick/pack/ship workflow overlay — forward-only, never touches
        // order status.
        Route::group(['prefix' => 'fulfillments', 'as' => 'fulfillments.'], function () {
            Route::controller(\App\Http\Controllers\Admin\Marketplace\FulfillmentController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->name('store');
                Route::post('{id}/advance', 'advance')->whereNumber('id')->name('advance');
                Route::post('{id}/cancel', 'cancel')->whereNumber('id')->name('cancel');
            });
        });

        // Shipping zones (Stage C): destination-based rate rules with a resolver that falls back to the
        // existing flat shipping where no zone matches.
        Route::group(['prefix' => 'shipping-zones', 'as' => 'shipping-zones.'], function () {
            Route::controller(\App\Http\Controllers\Admin\Marketplace\ShippingZoneController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->name('store');
                Route::post('toggle', 'toggle')->name('toggle');
                Route::put('{id}', 'update')->whereNumber('id')->name('update');
                Route::delete('{id}', 'destroy')->whereNumber('id')->name('destroy');
            });
        });

        // Commission rules (Stage B): the writer for the commission engine's rule table — define a rate
        // by scope (global/category/vendor/product) and priority. Non-breaking: no active rule leaves the
        // engine on its legacy flat percentage.
        Route::group(['prefix' => 'commission-rules', 'as' => 'commission-rules.'], function () {
            Route::controller(\App\Http\Controllers\Admin\Marketplace\CommissionRuleController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->name('store');
                Route::post('{id}/toggle', 'toggle')->whereNumber('id')->name('toggle');
                Route::put('{id}', 'update')->whereNumber('id')->name('update');
                Route::delete('{id}', 'destroy')->whereNumber('id')->name('destroy');
            });
        });

        // Payment orchestration (Stage E): gateway routing rules — hide or prefer a gateway by amount
        // or country. Non-breaking: no rule leaves the offered gateways unchanged.
        Route::group(['prefix' => 'payment-routing', 'as' => 'payment-routing.'], function () {
            Route::controller(\App\Http\Controllers\Admin\Marketplace\PaymentRoutingController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->name('store');
                Route::put('{id}', 'update')->whereNumber('id')->name('update');
                Route::delete('{id}', 'destroy')->whereNumber('id')->name('destroy');
            });
        });

        // Exchange-rate governance (Stage E): bulk rate update + audited change history over the
        // platform's existing currencies. Conversion itself is unchanged.
        Route::group(['prefix' => 'exchange-rates', 'as' => 'exchange-rates.'], function () {
            Route::controller(\App\Http\Controllers\Admin\Marketplace\ExchangeRateController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('bulk-update', 'bulkUpdate')->name('bulk-update');
            });
        });

    });

    Route::group(['prefix' => 'report', 'as' => 'report.', 'middleware' => ['module:reports']], function () {
        Route::group(['prefix' => 'transaction', 'as' => 'transaction.'], function () {
            Route::controller(RefundTransactionController::class)->group(function () {
                Route::get('refund-transaction-list', 'index')->name('refund-transaction-list');
                Route::get('refund-transaction-export', 'exportRefundTransaction')->name('refund-transaction-export');
                Route::get('refund-transaction-summary-pdf', 'getRefundTransactionPDF')->name('refund-transaction-summary-pdf');
            });
        });
    });

    Route::group(['prefix' => 'report', 'as' => 'report.', 'middleware' => ['module:reports']], function () {
        Route::controller(ReportController::class)->group(function () {
            Route::get('admin-earning', 'admin_earning')->name('admin-earning');
            Route::get('admin-earning-excel-export', 'exportAdminEarning')->name('admin-earning-excel-export');
            Route::post('admin-earning-duration-download-pdf', 'admin_earning_duration_download_pdf')->name('admin-earning-duration-download-pdf');
            Route::get('vendor-earning', 'vendorEarning')->name('vendor-earning');
            Route::get('vendor-earning-excel-export', 'exportVendorEarning')->name('vendor-earning-excel-export');
            Route::any('set-date', 'set_date')->name('set-date');
        });

        Route::controller(OrderReportController::class)->group(function () {
            Route::get('order', 'order_list')->name('order');
            Route::get('order-report-excel', 'orderReportExportExcel')->name('order-report-excel');
            Route::get('order-report-pdf', 'exportOrderReportInPDF')->name('order-report-pdf');
        });

        Route::controller(ProductReportController::class)->group(function () {
            Route::get('all-product', 'all_product')->name('all-product');
            Route::get('all-product-excel', 'allProductExportExcel')->name('all-product-excel');
        });

        Route::controller(VendorProductSaleReportController::class)->group(function () {
            Route::get('vendor-report', 'vendorReport')->name('vendor-report');
            Route::get('vendor-report-export', 'exportVendorReport')->name('vendor-report-export');
        });
    });

    Route::group(['prefix' => 'transaction', 'as' => 'transaction.', 'middleware' => ['module:reports']], function () {
        Route::controller(TransactionReportController::class)->group(function () {
            Route::get('order-transaction-list', 'order_transaction_list')->name('order-transaction-list');
            Route::get('pdf-order-wise-transaction', 'pdf_order_wise_transaction')->name('pdf-order-wise-transaction');
            Route::get('order-transaction-export-excel', 'orderTransactionExportExcel')->name('order-transaction-export-excel');
            Route::get('order-transaction-summary-pdf', 'order_transaction_summary_pdf')->name('order-transaction-summary-pdf');
            Route::get('wallet-bonus', 'wallet_bonus')->name('wallet-bonus');
        });

        Route::controller(ExpenseTransactionReportController::class)->group(function () {
            Route::get('expense-transaction-list', 'getExpenseTransactionList')->name('expense-transaction-list');
            Route::get('pdf-order-wise-expense-transaction', 'generateOrderWiseExpenseTransactionPdf')->name('pdf-order-wise-expense-transaction');
            Route::get('expense-transaction-export-excel', 'expenseTransactionExportExcel')->name('expense-transaction-export-excel');
            Route::get('expense-transaction-summary-pdf', 'generateExpenseTransactionSummaryPDF')->name('expense-transaction-summary-pdf');
        });
    });

    Route::group(['prefix' => 'stock', 'as' => 'stock.', 'middleware' => ['module:reports']], function () {
        Route::controller(ProductStockReportController::class)->group(function () {
            //product stock report
            Route::get('product-stock', 'index')->name('product-stock');
            Route::get('product-stock-export', 'export')->name('product-stock-export');
        });

        //product in wishlist report
        Route::controller(ProductWishlistReportController::class)->group(function () {
            Route::get('product-in-wishlist', 'index')->name('product-in-wishlist');
            Route::get('wishlist-product-export', 'export')->name('wishlist-product-export');
        });
    });

    // Reviews
    Route::group(['prefix' => 'reviews', 'as' => 'reviews.', 'middleware' => ['module:people']], function () {
        Route::controller(ReviewController::class)->group(function () {
            Route::get('list', 'index')->name('list');
            Route::post('status', 'updateStatus')->name('status');
            Route::get('export', 'exportList')->name('export');
            Route::get('customer-list-search', 'getCustomerList')->name('customer-list-search');
            Route::any('search-product', 'search')->name('search-product');
            Route::post('add-review-reply', 'addReviewReply')->name('add-review-reply');
            Route::any('search-vendor', 'searchVendor')->name('search-vendor');
        });
    });

    // Coupon
    Route::group(['prefix' => 'coupon', 'as' => 'coupon.', 'middleware' => ['module:marketing']], function () {
        Route::controller(CouponController::class)->group(function () {
            Route::get('add', 'getAddListView')->name('add');
            Route::post('add', 'add');
            Route::get('export', 'exportList')->name('export');
            Route::get('quick-view-details', 'quickView')->name('quick-view-details');
            Route::get('update/{id}', 'getUpdateView')->name('update');
            Route::post('update/{id}', 'update');
            Route::get('status/{id}/{status}', 'updateStatus')->name('status');
            Route::post('bulk-status', 'bulkStatus')->name('bulk-status');
            Route::post('ajax-get-vendor', 'getVendorList')->name('ajax-get-vendor');
            Route::delete('delete/{id}', 'delete')->name('delete');
        });
    });

    Route::group(['prefix' => 'deal', 'as' => 'deal.', 'middleware' => ['module:marketing']], function () {
        Route::controller(FlashDealController::class)->group(function () {
            Route::get('flash', 'index')->name('flash');
            Route::get('flash/add', 'getAddView')->name('flash-add');
            Route::post('flash', 'add');
            Route::get('update/{id}', 'getUpdateView')->name('update');
            Route::post('update/{id}', 'update')->name('update-data');
            Route::post('status-update', 'updateStatus')->name('status-update');
            Route::post('delete-product', 'delete')->name('delete-product');
            Route::get('add-product/{deal_id}', 'getAddProductView')->name('add-product');
            Route::post('add-product/{deal_id}', 'addProduct');
            Route::any('search-product', 'search')->name('search-product');
        });

        Route::controller(DealOfTheDayController::class)->group(function () {
            Route::get('day', 'index')->name('day');
            Route::post('day', 'add');
            Route::post('day-status-update', 'updateStatus')->name('day-status-update');
            Route::get('day-update/{id}', 'getUpdateView')->name('day-update');
            Route::post('day-update/{id}', 'update');
            Route::post('day-delete', 'delete')->name('day-delete');
        });

        Route::controller(FeaturedDealController::class)->group(function () {
            Route::get('feature', 'index')->name('feature');
            Route::get('feature/new', 'getAddView')->name('feature-add');
            Route::get('feature-update/{id}', 'getUpdateView')->name('edit');
            Route::post('feature-update', 'update')->name('featured-update');
            Route::post('feature-status', 'updateStatus')->name('feature-status');
        });

        Route::group(['prefix' => 'clearance-sale', 'as' => 'clearance-sale.'], function () {
            Route::controller(ClearanceSaleController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('status-update', 'updateStatus')->name('status-update');
                Route::post('update-config', 'updateClearanceConfig')->name('update-config');
                Route::post('update-seo-meta', 'updateClearanceSeoConfig')->name('update-seo-meta');
                Route::get('search', 'getSearchedProductsView')->name('search-product-for-clearance');
                Route::get('multiple-product-details', 'getMultipleProductDetailsView')->name('multiple-clearance-product-details');
                Route::post('add-clearance-product', 'addClearanceProduct')->name('add-product');
                Route::post('clearance-product-status-update', 'updateProductStatus')->name('product-status-update');
                Route::delete('clearance-delete/{product_id}', 'deleteClearanceProduct')->name('clearance-delete');
                Route::delete('clearance-products-delete', 'deleteClearanceAllProduct')->name('clearance-delete-all-product');
                Route::post('update-discount', 'updateDiscountAmount')->name('update-discount');
            });

            Route::controller(ClearanceSaleVendorOfferController::class)->group(function () {
                Route::get('vendor-offers', 'index')->name('vendor-offers');
                Route::get('vendor-search', 'getSearchedVendorsView')->name('search-vendor-for-clearance');
                Route::post('vendor-add', 'addClearanceVendorProduct')->name('vendor-add');
                Route::post('update-status', 'updateVendorStatus')->name('update-vendor-status');
                Route::post('update-offer-status', 'updateVendorOfferStatus')->name('update-vendor-offer-status');
                Route::delete('delete-vendor/{id}', 'deleteVendorOffer')->name('vendor-delete');
            });

            Route::controller(ClearanceSalePrioritySetupController::class)->group(function () {
                Route::get('priority-setup', 'index')->name('priority-setup');
                Route::post('priority-setup-config', 'updateConfig')->name('priority-setup-config');
            });
        });
    });

    /** Notification and push notification */
    Route::group(['prefix' => 'push-notification', 'as' => 'push-notification.', 'middleware' => ['module:marketing']], function () {
        Route::controller(PushNotificationSettingsController::class)->group(function () {
            Route::get('index', 'index')->name('index');
            Route::post('update', 'updatePushNotificationMessage')->name('update');
        });
    });

    Route::group(['prefix' => 'notification', 'as' => 'notification.', 'middleware' => ['module:marketing']], function () {
        Route::controller(NotificationController::class)->group(function () {
            Route::get('index', 'index')->name('index');
            Route::post('index', 'add');
            Route::get('update/{id}', 'getUpdateView')->name('update');
            Route::post('update/{id}', 'update');
            Route::post('delete', 'delete')->name('delete');
            Route::post('update-status', 'updateStatus')->name('update-status');
            Route::post('resend-notification', 'resendNotification')->name('resend-notification');
        });
    });

    Route::group(['prefix' => 'support-ticket', 'as' => 'support-ticket.', 'middleware' => ['module:people']], function () {
        Route::controller(SupportTicketController::class)->group(function () {
            Route::get('view', 'index')->name('view');
            Route::post('status', 'updateStatus')->name('status');
            Route::get('single-ticket/{id}', 'getView')->name('singleTicket');
            Route::post('single-ticket/{id}', 'reply')->name('replay');
        });
    });

    Route::group(['prefix' => 'messages', 'as' => 'messages.'], function () {
        Route::controller(ChattingController::class)->group(function () {
            Route::get('index/{type}', 'index')->name('index');
            Route::get('message', 'getMessageByUser')->name('message');
            Route::post('message', 'addAdminMessage');
        });
    });

    Route::group(['prefix' => 'contact', 'as' => 'contact.', 'middleware' => ['module:people']], function () {
        Route::controller(ContactController::class)->group(function () {
            Route::get('list', 'index')->name('list');
            Route::get('view/{id}', 'getView')->name('view');
            Route::post('filer', 'getListByFilter')->name('filter');
            Route::post('delete', 'delete')->name('delete');
            Route::post('update/{id}', 'update')->name('update');
            Route::post('store', 'add')->name('store');
            Route::post('send-mail/{id}', 'sendMail')->name('send-mail');
        });
    });

    Route::group(['prefix' => 'delivery-man', 'as' => 'delivery-man.', 'middleware' => ['module:people']], function () {
        Route::controller(DeliveryManController::class)->group(function () {
            Route::get('list', 'index')->name('list');
            Route::get('add', 'getAddView')->name('add');
            Route::post('add', 'add');
            Route::post('status-update', 'updateStatus')->name('status-update');
            Route::get('export', 'exportList')->name('export');
            Route::get('update/{id}', 'getUpdateView')->name('edit');
            Route::post('update/{id}', 'update')->name('update');
            Route::delete('delete/{id}', 'delete')->name('delete');
            Route::get('earning-statement-overview/{id}', 'getEarningOverview')->name('earning-statement-overview');
            Route::get('order-wise-earning/{id}', 'getOrderWiseEarningView')->name('order-wise-earning');
            Route::get('order-list-by-filer/{id}', 'getOrderWiseEarningListByFilter')->name('order-wise-earning-list-by-filter');
            Route::get('order-history-log/{id}', 'getOrderHistoryList')->name('order-history-log');
            Route::get('order-history-log-export/{id}', 'getOrderHistoryListExport')->name('order-history-log-export');
            Route::get('rating/{id}', 'getRatingView')->name('rating');
            Route::get( 'ajax-order-status-history/{order}', 'getOrderStatusHistory')->name('ajax-order-status-history');
        });

        Route::controller(DeliveryManCashCollectController::class)->group(function () {
            Route::get('collect-cash/{id}', 'index')->name('collect-cash');
            Route::post('cash-receive/{id}', 'getCashReceive')->name('cash-receive');
        });

        Route::controller(DeliverymanWithdrawController::class)->group(function () {
            Route::get('withdraw-list', 'index')->name('withdraw-list');
            Route::post('withdraw-list', 'getFiltered');
            Route::get('withdraw-list-export', 'exportList')->name('withdraw-list-export');
            Route::get('withdraw-view/{withdraw_id}', 'getView')->name('withdraw-view');
            Route::post( 'withdraw-update-status/{id}', 'updateStatus')->name('withdraw-update-status');
        });

        Route::group(['prefix' => 'emergency-contact', 'as' => 'emergency-contact.'], function () {
            Route::controller(EmergencyContactController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('add', 'add')->name('add');
                Route::get( 'update/{id}', 'getUpdateView')->name('update');
                Route::post( 'update/{id}', 'update');
                Route::post('ajax-status-change', 'updateStatus')->name('ajax-status-change');
                Route::delete('destroy', 'delete')->name('destroy');
            });
        });
    });

    Route::group(['prefix' => 'most-demanded', 'as' => 'most-demanded.', 'middleware' => ['module:marketing']], function () {
        Route::controller(MostDemandedController::class)->group(function () {
            Route::get('/', 'getListView')->name('index');
            Route::post('store', 'add')->name('store');
            Route::get('update/{id}', 'getUpdateView')->name('edit');
            Route::post('update/{id}', 'update')->name('update');
            Route::post('delete', 'delete')->name('delete');
            Route::post('status', 'updateStatus')->name('status-update');
        });
    });

    Route::group(['prefix' => 'addon', 'as' => 'addon.'], function () {
        Route::controller(AddonController::class)->group(function () {
            Route::get('', 'index')->name('index');
            Route::post('publish', 'publish')->name('publish');
            Route::post('activation', 'activation')->name('activation');
            Route::post('upload', 'upload')->name('upload');
            Route::post('delete', 'delete')->name('delete');
        });
    });

    Route::group(['prefix' => 'system-setup', 'as' => 'system-setup.'], function () {

        Route::group(['middleware' => ['module:themes_and_addons']], function () {
            // The legacy theme installer (zip upload + licence check + WEB_THEME switching) is
            // retired: the storefront runs one built-in theme, managed under admin/theme.
            // The old bookmark keeps working via this redirect.
            Route::redirect('theme/setup', '/admin/theme')->name('theme.setup');

            Route::group(['prefix' => 'addon', 'as' => 'addon.'], function () {
                Route::controller(AddonController::class)->group(function () {
                    Route::get('', 'index')->name('index');
                    Route::post('publish', 'publish')->name('publish');
                    Route::post('activation', 'activation')->name('activation');
                    Route::post('upload', 'upload')->name('upload');
                    Route::post('delete', 'delete')->name('delete');
                });
            });

            Route::group(['prefix' => 'addon-activation', 'as' => 'addon-activation.'], function () {
                Route::controller(AddonActivationController::class)->group(function () {
                    Route::get('', 'index')->name('index');
                    Route::post('activation', 'activation')->name('activation');
                    Route::post('activate-from-list', 'activateFromList')->name('activate-from-list');
                });
            });
        });

        Route::group(['middleware' => ['module:system_settings']], function () {

            Route::controller(EnvironmentSettingsController::class)->group(function () {
                Route::get('environment-setup', 'index')->name('environment-setup');
                Route::post('environment-setup', 'update');
                Route::post('environment-update-force-https', 'updateForceHttps')->name('environment-https-setup');
                Route::post('optimize-system', 'optimizeSystem')->name('optimize-system');
                Route::post('install-passport', 'installPassport')->name('install-passport');
            });

            Route::controller(BusinessSettingsController::class)->group(function () {
                Route::get('app-settings', 'getAppSettingsView')->name('app-settings');
                Route::post('app-settings', 'updateAppSettings');
                Route::get('app-deep-link', 'getAppDeepLinkView')->name('app-deep-link');
                Route::post('app-deep-link', 'updateAppDeepLink')->name('app-deep-link-store');
            });

            Route::controller(SoftwareUpdateController::class)->group(function () {
                Route::get('software-update', 'index')->name('software-update');
                Route::post('software-update', 'update');
            });

            Route::group(['prefix' => 'language', 'as' => 'language.', 'middleware' => ['module:system_settings']], function () {
                Route::controller(LanguageController::class)->group(function () {
                    Route::get('/', 'index')->name('index');
                    Route::post('add', 'add')->name('add-new');
                    Route::post('update-status', 'updateStatus')->name('update-status');
                    Route::post('update-default-status', 'updateDefaultStatus')->name('update-default-status');
                    Route::post('update', 'update')->name('update');
                    Route::get('delete/{lang}', 'delete')->name('delete');
                    Route::get('translate/{lang}', 'getTranslateView')->name('translate');
                    Route::get('translate-list/{lang}', 'getTranslateList')->name('translate.list');
                    Route::post('translate-submit/{lang}', 'updateTranslate')->name('translate-submit');
                    Route::post('remove-key/{lang}', 'deleteTranslateKey')->name('remove-key');
                    Route::any('auto-translate/{lang}', 'getAutoTranslate')->name('auto-translate');
                    Route::any('auto-translate-all/{lang}', 'getAutoTranslateAllMessages')->name('auto-translate-all');
                });
            });

            Route::group(['prefix' => 'currency', 'as' => 'currency.', 'middleware' => ['module:system_settings']], function () {
                Route::controller(CurrencyController::class)->group(function () {
                    Route::get('view', 'index')->name('view');
                    Route::post('store', 'add')->name('store');
                    Route::post('update', 'update')->name('update');
                    Route::post('delete', 'delete')->name('delete');
                    Route::post('status', 'status')->name('status');
                    Route::post('check-currency-update', 'checkSystemCurrency')->name('check-currency-update');
                    Route::post('system-currency-update', 'updateSystemCurrency')->name('system-currency-update');
                    // Single- or multi-currency. 35 branch sites read it and only the installer
                    // ever wrote it, so the shape of the whole money model was fixed at install.
                    Route::post('currency-model', 'updateCurrencyModel')->name('currency-model');
                });
            });

            Route::controller(DatabaseSettingController::class)->group(function () {
                Route::get('db-index', 'index')->name('db-index');
                Route::post('db-clean', 'delete')->name('clean-db');
            });

            Route::group(['prefix' => 'login-settings', 'as' => 'login-settings.'], function () {
                Route::controller(SystemLoginSetupController::class)->group(function () {
                    Route::get('customer-login-setup', 'getCustomerLoginSetupView')->name('customer-login-setup');
                    Route::post('customer-login-setup', 'updateCustomerLoginSetup');
                    Route::post('customer-config-validation', 'getConfigValidation')->name('config-status-validation');

                    Route::get('otp-setup', 'getOtpSetupView')->name('otp-setup');
                    Route::post('otp-setup', 'updateOtpSetup');

                    Route::get('login-url-setup', 'getLoginSetupView')->name('login-url-setup');
                    Route::post('login-url-setup', 'updateLoginSetupView');
                });
            });

            Route::group(['prefix' => 'email-templates', 'as' => 'email-templates.', 'middleware' => ['module:system_settings']], function () {
                Route::controller(EmailTemplatesController::class)->group(function () {
                    Route::get('index', 'index')->name('index');
                    Route::get('/' . '/{type}' . '/{tab}', 'getView')->name('view');
                    Route::post('update/{type}' . '/{tab}', 'update')->name('update');
                    Route::post( 'update-status/{type}' . '/{tab}', 'updateStatus')->name('update-status');
                });
            });

            Route::group(['prefix' => 'file-manager', 'as' => 'file-manager.', 'middleware' => ['module:system_settings']], function () {
                Route::controller(FileManagerController::class)->group(function () {
                    Route::get('index', 'index')->name('index');
                    Route::get('download/{file_name}', 'download')->name('download');
                    Route::post('image-upload', 'upload')->name('image-upload');
                });
            });
        });
    });

    Route::group(['prefix' => 'third-party', 'as' => 'third-party.'], function () {
        Route::group(['middleware' => ['module:3rd_party_setup']], function () {
            Route::group(['prefix' => 'payment-method', 'as' => 'payment-method.'], function () {
                Route::controller(PaymentMethodController::class)->group(function () {
                    Route::get('/', 'index')->name('index');
                    Route::put('addon-payment-set', 'UpdatePaymentConfig')->name('addon-payment-set');
                    Route::post('payment-status', 'UpdateStatus')->name('payment-status');
                });

                /*
                 * MyFatoorah has its own page because the shared gateway form cannot hold separate
                 * sandbox and production keys (it writes the same values into both blobs) and stores
                 * what it is given in the clear. The on/off switch stays on the shared page with every
                 * other gateway; this is where the credentials, the connection test, the webhook URL
                 * and the payment monitor live.
                 */
                Route::group(['prefix' => 'myfatoorah', 'as' => 'myfatoorah.'], function () {
                    Route::controller(\App\Http\Controllers\Admin\MyFatoorah\SettingsController::class)->group(function () {
                        Route::get('/', 'index')->name('index');
                        Route::put('settings', 'update')->name('settings');
                        Route::post('test-connection', 'testConnection')->name('test-connection');
                        Route::post('refresh-methods', 'refreshMethods')->name('refresh-methods');
                        Route::post('reconcile', 'reconcile')->name('reconcile');
                        Route::post('attempts/{attempt}/reverify', 'reverify')->name('attempts.reverify')->whereNumber('attempt');
                        Route::post('attempts/{attempt}/never-created', 'confirmNeverCreated')->name('attempts.never-created')->whereNumber('attempt');
                        Route::post('attempts/{attempt}/rerun-hook', 'rerunHook')->name('attempts.rerun-hook')->whereNumber('attempt');
                        Route::post('attempts/{attempt}/refund', 'refund')->name('attempts.refund')->whereNumber('attempt');
                        Route::post('refunds/{refund}/executed', 'markRefundExecuted')->name('refunds.executed')->whereNumber('refund');
                    });
                });
          


     // MTN Cash
            Route::post(
                'mtn-cash/generate-keys',
                [\App\Http\Controllers\Payment_Methods\MtnCashPaymentController::class, 'generateKeys']
            )->name('mtn-cash.generate-keys');


Route::get(
    'mtn-cash/activation-precheck',
    [\App\Http\Controllers\Payment_Methods\MtnCashPaymentController::class, 'activationPrecheck']
)->name('mtn-cash.activation-precheck');


Route::post(
    'mtn-cash/activate',
    [\App\Http\Controllers\Payment_Methods\MtnCashPaymentController::class, 'activate']
)->name('mtn-cash.activate');


Route::get(
    'mtn-cash/auth-test',
    [\App\Http\Controllers\Payment_Methods\MtnCashPaymentController::class, 'authTest']
)->name('mtn-cash.auth-test');


Route::get(
    'mtn-cash/import-original-keys',
    [\App\Http\Controllers\Payment_Methods\MtnCashPaymentController::class, 'importOriginalKeys']
)->name('mtn-cash.import-original-keys');















  });

            Route::group(['prefix' => 'offline-payment-method', 'as' => 'offline-payment-method.'], function () {
                Route::controller(OfflinePaymentMethodController::class)->group(function () {
                    Route::get('index', 'index')->name('index');
                    Route::get('add', 'getAddView')->name('add');
                    Route::post('add', 'add');
                    Route::get('update/{id}', 'getUpdateView')->name('update');
                    Route::post('update/{id}', 'update');
                    Route::post('delete', 'delete')->name('delete');
                    Route::post('update-status', 'updateStatus')->name('update-status');
                });
            });

            Route::group(['prefix' => 'firebase-configuration', 'as' => 'firebase-configuration.'], function () {
                Route::controller(PushNotificationSettingsController::class)->group(function () {
                    Route::get('setup', 'getFirebaseConfigurationView')->name('setup');
                    Route::post('setup', 'getFirebaseConfigurationUpdate');
                });
                Route::controller(FirebaseOTPVerificationController::class)->group(function () {
                    Route::get('authentication', 'index')->name('authentication');
                    Route::post('update', 'updateAuthentication')->name('update');
                    Route::post('firebase-config-validation', 'getConfigValidation')->name('config-status-validation');
                });
            });

            Route::controller(BusinessSettingsController::class)->group(function () {
                Route::get('analytics-index', 'getAnalyticsView')->name('analytics-index');
                Route::post('analytics-update', 'updateAnalytics')->name('analytics-update');
            });

            Route::group(['prefix' => 'social-login', 'as' => 'social-login.'], function () {
                Route::controller(SocialLoginSettingsController::class)->group(function () {
                    Route::get('view', 'index')->name('view');
                    Route::post('update/{service}', 'update')->name('update');
                    Route::post('update-apple/{service}', 'updateAppleLogin')->name('update-apple');
                });
            });

            Route::group(['prefix' => 'storage-connection-settings', 'as' => 'storage-connection-settings.'], function () {
                Route::controller(StorageConnectionSettingsController::class)->group(function () {
                    Route::get('index', 'index')->name('index');
                    Route::post('update-storage-type', 'updateStorageType')->name('update-storage-type');
                    Route::post('s3-credential', 'updateS3Credential')->name('s3-credential');
                });
            });

            Route::group(['prefix' => 'social-media-chat', 'as' => 'social-media-chat.'], function () {
                Route::controller(SocialMediaChatController::class)->group(function () {
                    Route::post('update/{service}', 'update')->name('update');
                });
            });

            Route::group(['prefix' => 'mail', 'as' => 'mail.'], function () {
                Route::controller(MailController::class)->group(function () {
                    Route::get('', 'index')->name('index');
                    Route::post('update', 'update')->name('update');
                    Route::post('update-sendgrid', 'updateSendGrid')->name('update-sendgrid');
                    Route::post('send', 'send')->name('send');
                });
            });

            Route::controller(SMSModuleController::class)->group(function () {
                Route::get('sms-module', 'index')->name('sms-module');
                Route::put('addon-sms-set', 'update')->name('addon-sms-set');
                Route::post('send-test-sms', 'sendSMS')->name('send-test-sms');
            });


            Route::controller(GoogleMapAPIController::class)->group(function () {
                Route::get('map-api', 'index')->name('map-api');
                Route::post('map-api', 'update');
            });

            Route::controller(\App\Http\Controllers\Admin\ThirdParty\DeliverySyriaController::class)->group(function () {
                Route::get('delivery-syria', 'index')->name('delivery-syria');
                Route::post('delivery-syria', 'update');
                Route::post('delivery-syria/verify-sync', 'verifySync')->name('delivery-syria.verify-sync');
            });

            Route::group(['prefix' => 'withdraw-method', 'as' => 'withdraw-method.'], function () {
                Route::controller(WithdrawalMethodController::class)->group(function () {
                    Route::get('list', 'index')->name('list');
                    Route::get('add', 'getAddView')->name('add');
                    Route::post('add', 'add');
                    Route::delete('delete/{id}', 'delete')->name('delete');
                    Route::post('default-status-update', 'updateDefaultStatus')->name('default-status');
                    Route::post('status-update', 'updateStatus')->name('status-update');
                    Route::get('update/{id}', 'getUpdateView')->name('edit');
                    Route::post('update', 'update')->name('update');
                });
            });
        });
    });

    Route::group(['prefix' => 'business-settings', 'as' => 'business-settings.'], function () {

        Route::group(['middleware' => ['module:business_settings']], function () {
            Route::group(['prefix' => 'web-config', 'as' => 'web-config.'], function () {
                Route::controller(BusinessSettingsController::class)->group(function () {
                    Route::get('/', 'index')->name('index');
                    Route::post('/', 'updateSettings')->name('update');
                });
            });

            Route::controller(BusinessSettingsController::class)->group(function () {
                Route::post('maintenance-mode', 'updateSystemMode')->name('maintenance-mode');
            });


            Route::controller(WebsiteSetupController::class)->group(function () {
                Route::get('website-setup', 'getView')->name('website-setup');
                Route::post('website-setup', 'updateWebsiteSetup');
            });

            Route::group(['prefix' => 'vendor-settings', 'as' => 'vendor-settings.'], function () {
                Route::controller(VendorSettingsController::class)->group(function () {
                    Route::get('', 'index')->name('index');
                    Route::post('update-vendor-settings', 'update')->name('update-vendor-settings');
                });
            });

            Route::group(['prefix' => 'product-settings', 'as' => 'product-settings.'], function () {
                Route::controller(BusinessSettingsController::class)->group(function () {
                    Route::get('/', 'getProductSettingsView')->name('index');
                    Route::post('/', 'updateProductSettings');
                });
            });

            Route::group(['prefix' => 'delivery-man-settings', 'as' => 'delivery-man-settings.'], function () {
                Route::controller(DeliverymanSettingsController::class)->group(function () {
                    Route::get('/', 'index')->name('index');
                    Route::post('delivery-man-settings/update', 'update')->name('update');
                    Route::post('delivery-man-settings/upload-picture', 'uploadPicture')->name('upload-picture');
                });
            });

            Route::controller(CustomerController::class)->group(function () {
                Route::get('customer-settings', 'getCustomerSettingsView')->name('customer-settings');
                Route::post('customer-settings', 'updateCustomer');
            });

            Route::group(['prefix' => 'order-settings', 'as' => 'order-settings.'], function () {
                Route::controller(OrderSettingsController::class)->group(function () {
                    Route::get('index', 'index')->name('index');
                    Route::post('update-order-settings', 'update')->name('update-order-settings');
                });
            });

            Route::controller(BusinessSettingsController::class)->group(function () {
                Route::get('refund-setup', 'getRefundSetupView')->name('refund-setup');
                Route::post('refund-setup', 'updateRefundSetup')->name('refund-setup-update');
            });

            Route::group(['prefix' => 'abandoned-cart', 'as' => 'abandoned-cart.'], function () {
                Route::controller(\App\Http\Controllers\Admin\Settings\AbandonedCartSettingsController::class)->group(function () {
                    Route::get('index', 'index')->name('index');
                    Route::post('update', 'update')->name('update');
                });
            });

            Route::group(['prefix' => 'shipping-method', 'as' => 'shipping-method.'], function () {
                Route::controller(ShippingMethodController::class)->group(function () {
                    Route::get('index', 'index')->name('index');
                    Route::post('index', 'add');
                    Route::get('update' . '/{id}', 'getUpdateView')->name('update');
                    Route::post('update' . '/{id}', 'update');
                    Route::post('update-status', 'updateStatus')->name('update-status');
                    Route::post('delete', 'delete')->name('delete');
                    Route::post('update-shipping-responsibility', 'updateShippingResponsibility')->name('update-shipping-responsibility');
                });
            });

            Route::group(['prefix' => 'shipping-type', 'as' => 'shipping-type.'], function () {
                Route::post('index', [ShippingTypeController::class, 'addOrUpdate'])->name('index');
            });

            Route::group(['prefix' => 'category-shipping-cost', 'as' => 'category-shipping-cost.'], function () {
                Route::controller(CategoryShippingCostController::class)->group(function () {
                    Route::post('store', 'add')->name('store');
                });
            });

            Route::group(['prefix' => 'delivery-zone', 'as' => 'delivery-zone.'], function () {
                Route::controller(DeliveryRestrictionController::class)->group(function () {
                    Route::get('/', 'index')->name('index');
                    Route::post('add-delivery-country', 'add')->name('add-delivery-country');
                    Route::delete('delivery-country-delete', 'delete')->name('delivery-country-delete');
                    Route::post('add-zip-code', 'addZipCode')->name('add-zip-code');
                    Route::delete('zip-code-delete', 'deleteZipCode')->name('zip-code-delete');
                    Route::post('country-restriction-status-change', 'countryRestrictionStatusChange')->name('country-restriction-status-change');
                    Route::post("zipcode-restriction-status-change", 'zipcodeRestrictionStatusChange')->name('zipcode-restriction-status-change');
                    Route::post('zipcode-field-status-change', 'zipcodeFieldStatusChange')->name('zipcode-field-status-change');
                });
            });

            Route::group(['prefix' => 'invoice-settings', 'as' => 'invoice-settings.'], function () {
                Route::controller(InvoiceSettingsController::class)->group(function () {
                    Route::get('/', 'index')->name('index');
                    Route::post('/', 'update')->name('update');
                });
            });
        });
    });

    Route::group(['prefix' => 'business-settings', 'as' => 'business-settings.'], function () {

        Route::group(['middleware' => ['module:business_settings']], function () {
            Route::controller(InhouseShopController::class)->group(function () {
                Route::get('inhouse-shop', 'index')->name('inhouse-shop');
                Route::post('inhouse-shop', 'update');
                Route::post('inhouse-shop-temporary-close', 'getTemporaryClose')->name('inhouse-shop-temporary-close');
                Route::post('vacation-update', 'updateVacation')->name('inhouse-shop-vacation-update');

                Route::get('inhouse-shop/setup', 'getSetupView')->name('inhouse-shop-setup');
                Route::post('inhouse-shop/setup', 'updateSetup');
            });
        });

        Route::group(['prefix' => 'priority-setup', 'as' => 'priority-setup.'], function () {
            Route::controller(PrioritySetupController::class)->group(function () {
                Route::get('', 'index')->name('index');
                Route::post('', 'update');
                Route::post('update-by-type', 'updateByType')->name('update-by-type');
            });
        });
    });

    /*
     * App Builder — the same experience engine, entered as the app rather than as the theme.
     *
     * Pages of a channel are the part nothing could manage before; everything else here links to
     * the screens that already do the job (the composer, publishing, versions, global styles)
     * rather than growing a second copy of them.
     */
    /*
     * Commerce Experience (Phase 3) — configuration that changes what sections MAY show.
     * Same module gate as the builder that consumes it; writes are further guarded by
     * ThemePermissionService inside each controller.
     */
    Route::group(['prefix' => 'commerce', 'as' => 'commerce.', 'middleware' => ['module:themes_and_addons']], function () {
        Route::controller(CollectionController::class)->group(function () {
            Route::get('collections', 'index')->name('collections.index');
            Route::post('collections/store', 'store')->name('collections.store');
            Route::post('collections/update', 'update')->name('collections.update');
            Route::post('collections/delete', 'delete')->name('collections.delete');
            Route::get('collections/preview', 'preview')->name('collections.preview');
        });
        Route::controller(CampaignController::class)->group(function () {
            Route::get('campaigns', 'index')->name('campaigns.index');
            Route::post('campaigns/store', 'store')->name('campaigns.store');
            Route::post('campaigns/update', 'update')->name('campaigns.update');
            Route::post('campaigns/delete', 'delete')->name('campaigns.delete');
        });
        Route::controller(SegmentController::class)->group(function () {
            Route::get('segments', 'index')->name('segments.index');
            Route::post('segments/store', 'store')->name('segments.store');
            Route::post('segments/update', 'update')->name('segments.update');
            Route::post('segments/delete', 'delete')->name('segments.delete');
        });
        Route::controller(ExperimentController::class)->group(function () {
            Route::get('experiments', 'index')->name('experiments.index');
            Route::post('experiments/store', 'store')->name('experiments.store');
            Route::post('experiments/update', 'update')->name('experiments.update');
            Route::post('experiments/delete', 'delete')->name('experiments.delete');
        });
    });

    Route::group(['prefix' => 'app-builder', 'as' => 'app-builder.', 'middleware' => ['module:themes_and_addons']], function () {
        Route::controller(AppBuilderController::class)->group(function () {
            Route::get('', 'index')->name('index');
            Route::get('pages', 'pages')->name('pages');
            Route::get('sections', 'sections')->name('sections');
            Route::get('media', 'media')->name('media');
            Route::get('templates', 'templates')->name('templates');
            Route::get('health', 'health')->name('health');
            Route::post('pages/store', 'storePage')->name('pages.store');
            Route::post('pages/update', 'updatePage')->name('pages.update');
            Route::post('pages/delete', 'deletePage')->name('pages.delete');
        });
    });

    /*
     * Theme Management — the single admin surface for the storefront's look: themes, versions
     * (draft/publish/restore), the section builder, and the colour/typography settings.
     */
    Route::group(['prefix' => 'theme', 'as' => 'theme.', 'middleware' => ['module:themes_and_addons']], function () {
        Route::controller(ThemeManagementController::class)->group(function () {
            Route::get('', 'index')->name('index');
            Route::post('store', 'store')->name('store');
            Route::post('delete', 'delete')->name('delete');
            Route::post('activate', 'activate')->name('activate');
            Route::post('version/publish', 'publishVersion')->name('version.publish');
            Route::post('version/schedule', 'scheduleVersion')->name('version.schedule');
            Route::post('version/duplicate', 'duplicateVersion')->name('version.duplicate');
            Route::post('version/restore', 'restoreVersion')->name('version.restore');
            Route::get('version/export', 'exportVersion')->name('version.export');
            Route::get('example', 'downloadExample')->name('example');
            Route::post('import', 'importTheme')->name('import');
            Route::post('import-preset', 'importPreset')->name('import-preset');
            Route::post('asset/upload', 'uploadAsset')->name('asset.upload');
            // POST, not GET: deleting is state-changing, and a GET delete is reachable by any
            // <img src> on a page an admin happens to visit (no CSRF token involved).
            Route::post('asset/delete', 'deleteAsset')->name('asset.delete');
        });

        // Global theme settings (branding / colors / typography / layout) — draft-scoped.
        Route::group(['prefix' => 'settings', 'as' => 'settings.'], function () {
            Route::controller(ThemeSettingsController::class)->group(function () {
                Route::get('', 'index')->name('index');
                Route::post('update', 'update')->name('update');
            });
        });

        // Visual Theme Builder (Phase 1.2) — all mutations target a DRAFT version.
        Route::group(['prefix' => 'builder', 'as' => 'builder.'], function () {
            Route::controller(ThemeBuilderController::class)->group(function () {
                Route::get('', 'index')->name('index');
                Route::get('section-schema', 'sectionSchema')->name('section-schema');
                // Pickers: which category / brand / product / flash deal a section shows.
                Route::get('resources', 'resources')->name('resources');
                Route::get('resource-labels', 'resourceLabels')->name('resource-labels');
                // Where a link goes, composed from what the merchant picked rather than typed.
                Route::post('link/compose', 'composeLink')->name('link.compose');
            Route::post('preview/start', 'startPreview')->name('preview.start');
            Route::post('preview/link', 'previewLink')->name('preview.link');
            Route::get('preview/stop', 'stopPreview')->name('preview.stop');
                Route::post('section/add', 'addSection')->name('section.add');
                Route::post('section/update', 'updateSection')->name('section.update');
                Route::post('section/reorder', 'reorderSections')->name('section.reorder');
                Route::post('section/toggle', 'toggleSection')->name('section.toggle');
                Route::post('section/duplicate', 'duplicateSection')->name('section.duplicate');
                Route::post('section/delete', 'deleteSection')->name('section.delete');
                // Delivery rules: schedule window + platform/audience targeting, stored as
                // indexed columns rather than settings JSON — the delivery pipeline filters on them.
                Route::post('section/delivery-rules', 'updateDeliveryRules')->name('section.delivery-rules');

                // Blocks: the repeatable children of a section (hero slides, promo tiles, footer columns).
                Route::get('block-schema', 'blockSchema')->name('block-schema');
                Route::post('block/add', 'addBlock')->name('block.add');
                Route::post('block/update', 'updateBlock')->name('block.update');
                Route::post('block/reorder', 'reorderBlocks')->name('block.reorder');
                Route::post('block/toggle', 'toggleBlock')->name('block.toggle');
                Route::post('block/duplicate', 'duplicateBlock')->name('block.duplicate');
                Route::post('block/delete', 'deleteBlock')->name('block.delete');

                // Images for image fields, stored through the hardened ThemeAssetService.
                Route::post('media/upload', 'uploadMedia')->name('media.upload');
                Route::post('media/delete', 'deleteMedia')->name('media.delete');
                Route::get('media/library', 'mediaLibrary')->name('media.library');
            });
        });
    });

    Route::group(['prefix' => 'seo-settings', 'as' => 'seo-settings.'], function () {
        Route::group(['middleware' => ['module:business_settings']], function () {
            Route::controller(SEOSettingsController::class)->group(function () {
                Route::get('web-master-tool', 'index')->name('web-master-tool');
                Route::post('web-master-tool', 'updateWebMasterTool');
                Route::get('robot-txt', 'getRobotTxtView')->name('robot-txt');
                Route::post('robot-text', 'updateRobotText')->name('update-robot-text');
            });

            Route::group(['prefix' => 'robots-meta-content', 'as' => 'robots-meta-content.'], function () {
                Route::controller(RobotsMetaContentController::class)->group(function () {
                    Route::get('', 'index')->name('index');
                    Route::post('add-page', 'addPage')->name('add-page');
                    Route::get('delete-page', 'getPageDelete')->name('delete-page');
                    Route::get('page-content-view', 'getPageAddContentView')->name('page-content-view');
                    Route::post('page-content-update', 'getPageContentUpdate')->name('page-content-update');
                });
            });

            Route::group(['prefix' => 'translations', 'as' => 'translations.'], function () {
                Route::controller(SeoTranslationController::class)->group(function () {
                    Route::get('', 'index')->name('index');
                    Route::post('save', 'save')->name('save');
                    Route::get('delete', 'delete')->name('delete');
                });
            });

            Route::group(['prefix' => 'templates', 'as' => 'templates.'], function () {
                Route::controller(SeoTemplateController::class)->group(function () {
                    Route::get('', 'index')->name('index');
                    Route::post('save', 'save')->name('save');
                    Route::get('delete', 'delete')->name('delete');
                    Route::get('preview', 'preview')->name('preview');
                });
            });

            Route::group(['prefix' => 'redirects', 'as' => 'redirects.'], function () {
                Route::controller(RedirectController::class)->group(function () {
                    Route::get('', 'index')->name('index');
                    Route::post('store', 'store')->name('store');
                    Route::post('update/{id}', 'update')->name('update');
                    Route::get('status', 'updateStatus')->name('status');
                    Route::get('delete', 'delete')->name('delete');
                });
            });

            // SEO Health: read-only audit over resolved product SEO (surfaces SeoAuditService).
            Route::get('health', [\App\Http\Controllers\Admin\Settings\SeoHealthController::class, 'index'])->name('health');

            Route::controller(SiteMapController::class)->group(function () {
                Route::get('sitemap', 'index')->name('sitemap');
                Route::get('sitemap-generate-download', 'getGenerateAndDownload')->name('sitemap-generate-download');
                Route::get('sitemap-generate-upload', 'getGenerateAndUpload')->name('sitemap-generate-upload');
                Route::post('sitemap-manual-upload', 'getUpload')->name('sitemap-manual-upload');
                Route::get('sitemap-download', 'getDownload')->name('sitemap-download');
                Route::get('sitemap-delete', 'getDelete')->name('sitemap-delete');
            });

            Route::group(['prefix' => 'error-logs', 'as' => 'error-logs.'], function () {
                Route::controller(ErrorLogsController::class)->group(function () {
                    Route::get('index', 'index')->name('index');
                    Route::post('index', 'update');
                    Route::delete('index', 'delete');
                    Route::delete('delete-selected-error-logs', 'deleteSelectedErrorLogs')->name('delete-selected-error-logs');
                });
            });
        });
    });

    Route::group(['prefix' => 'pages-and-media', 'as' => 'pages-and-media.'], function () {
        Route::group(['middleware' => ['module:business_settings']], function () {
            Route::controller(PagesController::class)->group(function () {
                Route::get('list', 'index')->name('list');
                Route::get('add', 'getAddView')->name('add');
                Route::post('add', 'getAdd');
                Route::get('update', 'getUpdateView')->name('update');
                Route::post('update', 'getUpdate');
                Route::post('delete-image', 'getDeleteImage')->name('delete.image');
                Route::post('delete', 'getDelete')->name('delete');
                Route::post('update-status', 'updateStatus')->name('update-status');
            });

            Route::controller(FeaturesSectionController::class)->group(function () {
                Route::get('company-reliability', 'getCompanyReliabilityView')->name('company-reliability');
                Route::post('company-reliability', 'updateCompanyReliability');
            });

            Route::controller(SocialMediaSettingsController::class)->group(function () {
                Route::get('social-media', 'index')->name('social-media');
                Route::post('social-media-store', 'add')->name('social-media-store');
                Route::post('social-media-edit', 'getUpdate')->name('social-media-edit');
                Route::post('social-media-update', 'update')->name('social-media-update');
                Route::post('social-media-delete', 'delete')->name('social-media-delete');
                Route::post('social-media-status-update', 'updateStatus')->name('social-media-status-update');
            });

            Route::group(['prefix' => 'vendor-registration-settings', 'as' => 'vendor-registration-settings.'], function () {
                Route::controller(VendorRegistrationSettingController::class)->group(function () {
                    Route::get('index', 'index')->name('index');
                    Route::post('index', 'updateHeaderSection');
                    Route::get('with-us', 'getSellWithUsView')->name('with-us');
                    Route::post('with-us', 'updateSellWithUsSection');
                    Route::get('business-process', 'getBusinessProcessView')->name('business-process');
                    Route::post('business-process', 'updateBusinessProcess');
                    Route::get('download-app', 'getDownloadAppView')->name('download-app');
                    Route::post('download-app', 'updateDownloadAppSection');
                    Route::get('faq', 'getFAQView')->name('faq');
                });
            });

            Route::group(['prefix' => 'vendor-registration-reason', 'as' => 'vendor-registration-reason.'], function () {
                Route::controller(VendorRegistrationReasonController::class)->group(function () {
                    Route::post('add', 'add')->name('add');
                    Route::get('update', 'getUpdateView')->name('update');
                    Route::post('update', 'update');
                    Route::post('update-status', 'updateStatus')->name('update-status');
                    Route::post('delete', 'delete')->name('delete');
                });
            });

            Route::controller(FeaturesSectionController::class)->group(function () {
                Route::get('features-section', 'index')->name('features-section');
                Route::post('features-section/submit', 'update')->name('features-section.submit');
                Route::post('features-section/icon-remove', 'delete')->name('features-section.icon-remove');
            });
        });
    });

    Route::group(['prefix' => 'business-settings', 'as' => 'business-settings.', 'middleware' => ['module:marketing']], function () {
        Route::controller(BusinessSettingsController::class)->group(function () {
            Route::get('announcement', 'getAnnouncementView')->name('announcement');
            Route::post('announcement', 'updateAnnouncement');
        });
    });

    Route::group(['prefix' => 'helpTopic', 'as' => 'helpTopic.', 'middleware' => ['module:business_settings']], function () {
        Route::controller(HelpTopicController::class)->group(function () {
            Route::get('index', 'index')->name('list');
            Route::post('add-new', 'add')->name('add-new');
            Route::post('status/{id}', 'updateStatus')->name('status');
            Route::get( 'update/{id}', 'getUpdateResponse')->name('update');
            Route::post('feature-status-update', 'updateFeatureStatus')->name('feature-status-update');
            Route::post('update' . '/{id}', 'update');
            Route::post('delete', 'delete')->name('delete');
        });
    });

    Route::group(['prefix' => 'refund-section', 'as' => 'refund-section.', 'middleware' => ['module:orders']], function () {
        Route::group(['prefix' => 'refund', 'as' => 'refund.'], function () {
            Route::controller(RefundController::class)->group(function () {
                Route::get('list/{status}', 'index')->name('list');
                Route::get('export/{status}', 'exportList')->name('export');
                Route::get('details/{id}', 'getDetailsView')->name('details');
                Route::post('refund-status-update', 'updateRefundStatus')->name('refund-status-update');
            });
        });
    });

    // Auction Time Adjustment — demo mode only, requires the Auction addon
    if (config('app.mode') == 'demo' && function_exists('getCheckAddonPublishedStatus') && getCheckAddonPublishedStatus(moduleName: 'Auction')) {
        Route::get('auction-time/{auction_id}', function ($auction_id) {
            $auction = \Modules\Auction\app\Models\AuctionProduct::findOrFail($auction_id);
            return response()->make(view('auction-time-form', compact('auction'))->render());
        })->name('auction.time.form');

        Route::post('auction-time/{auction_id}', function (\Illuminate\Http\Request $request, $auction_id) {
            $request->validate([
                'start_time' => ['required', 'date'],
                'end_time'   => ['required', 'date', 'after:start_time'],
            ]);

            $auction = \Modules\Auction\app\Models\AuctionProduct::findOrFail($auction_id);
            $auction->update([
                'start_time' => $request['start_time'],
                'end_time'   => $request['end_time'],
            ]);

            return redirect()->route('admin.auction.time.form', $auction_id)
                ->with('success', 'Auction times updated successfully.');
        })->name('auction.time.update');
    }
});