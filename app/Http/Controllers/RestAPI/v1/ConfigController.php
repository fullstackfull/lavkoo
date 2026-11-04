<?php

namespace App\Http\Controllers\RestAPI\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Request;
use App\Traits\CacheManagerTrait;
use App\Traits\MaintenanceModeTrait;
use App\Traits\SettingsTrait;
use App\Utils\Helpers;
use App\Utils\ProductManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Modules\AI\app\Traits\AIModuleManager;
use Modules\TaxModule\app\Models\SystemTaxSetup;
use function App\Utils\payment_gateways;
use App\Models\MetaAppIntegration;
class ConfigController extends Controller
{
    use SettingsTrait, MaintenanceModeTrait, CacheManagerTrait;
    use AIModuleManager;

    public function configuration(): JsonResponse
    {
        $socialLoginConfig = [];
        foreach (getWebConfig(name: 'social_login') as $social) {
            $config = [
                'login_medium' => $social['login_medium'],
                'status' => (boolean)$social['status']
            ];
            $socialLoginConfig[] = $config;
        }

        foreach (getWebConfig(name: 'apple_login') as $social) {
            $config = [
                'login_medium' => $social['login_medium'],
                'status' => (boolean)$social['status']
            ];
            $socialLoginConfig[] = $config;
        }

        $languageArray = [];
        foreach (getWebConfig(name: 'pnc_language') as $language) {
            $languageArray[] = [
                'code' => $language,
                'name' => Helpers::get_language_name($language)
            ];
        }

        $offlinePayment = null;
        $offlinePaymentStatus = getWebConfig(name: 'offline_payment')['status'] == 1 ?? 0;
        if ($offlinePaymentStatus) {
            $offlinePayment = [
                'name' => 'offline_payment',
                'image' => dynamicAsset(path: 'public/assets/back-end/img/pay-offline.png'),
            ];
        }

        $paymentMethods = payment_gateways();
        $paymentMethods->map(function ($payment) {
            $payment->additional_datas = json_decode($payment->additional_data);

            unset(
                $payment->additional_data,
                $payment->live_values,
                $payment->test_values,
                $payment->id,
                $payment->settings_type,
                $payment->mode,
                $payment->is_active,
                $payment->created_at,
                $payment->updated_at
            );
        });

        $shippingType = $this->cacheInHouseShippingType();
        $companyLogo = getWebConfig(name: 'company_web_logo');
        $companyFavIcon = getWebConfig(name: 'company_fav_icon');
        $companyShopBanner = getWebConfig(name: 'shop_banner');

        $loginOptions = getLoginConfig(key: 'login_options');
        $socialMediaLoginOptions = getLoginConfig(key: 'social_media_for_login');

        foreach ($socialMediaLoginOptions as $socialMediaLoginKey => $socialMediaLogin) {
            $socialMediaLoginOptions[$socialMediaLoginKey] = (int)$socialMediaLogin;
        }

        $customerLogin = [
            'login_option' => $loginOptions,
            'social_media_login_options' => $socialMediaLoginOptions
        ];

        $emailVerification = getLoginConfig(key: 'email_verification') ?? 0;
        $phoneVerification = getLoginConfig(key: 'phone_verification') ?? 0;

        $firebaseOTPVerification = getWebConfig(name: 'firebase_otp_verification');
        $customerVerification = [
            'status' => (int)($emailVerification == 1 || $phoneVerification == 1) ? 1 : 0,
            'phone' => (int)$phoneVerification,
            'email' => (int)$emailVerification,
            'firebase' => (int)($firebaseOTPVerification && $firebaseOTPVerification['status'] && $firebaseOTPVerification['web_api_key']),
        ];

        $maintenanceMode = [
            'maintenance_status' => (int)$this->checkMaintenanceMode(),
            'selected_maintenance_system' => getWebConfig(name: 'maintenance_system_setup') ?? [],
            'maintenance_messages' => getWebConfig(name: 'maintenance_message_setup') ?? [],
            'maintenance_type_and_duration' => getWebConfig(name: 'maintenance_duration_setup') ?? [],
        ];

        $themeComfortablePanelVersion = '';

        $systemTax = null;
        if (getCheckAddonPublishedStatus(moduleName: 'TaxModule')) {
            $systemTax = SystemTaxSetup::where('is_active', 1)->where('is_default', 1)->first();
        }

        $auctionFeatureStatus = getWebConfig(name: 'auction_feature_status');

        $systemColors = getWebConfig('colors');



$metaAppTracking = MetaAppIntegration::where('app_type', 'customer')
    ->get()
    ->keyBy('platform');




        return response()->json([

'meta_app_tracking' => [
'android' => isset($metaAppTracking['android']) ? [
    'enabled' => (bool) $metaAppTracking['android']->is_active,
    'meta_app_id' => $metaAppTracking['android']->meta_app_id,
    'client_token' => $metaAppTracking['android']->client_token,
    'package_name' => $metaAppTracking['android']->package_name,
    'events' => $metaAppTracking['android']->events ?? [],
] : null,

    'ios' => isset($metaAppTracking['ios']) ? [
    'enabled' => (bool) $metaAppTracking['ios']->is_active,
    'meta_app_id' => $metaAppTracking['ios']->meta_app_id,
    'client_token' => $metaAppTracking['ios']->client_token,
    'bundle_id' => $metaAppTracking['ios']->bundle_id,
    'events' => $metaAppTracking['ios']->events ?? [],
] : null,
],

            'primary_color' => $systemColors['primary'],
            'secondary_color' => $systemColors['secondary'],
            'primary_color_light' => $systemColors['primary_light'] ?? '',
            'brand_setting' => (string)getWebConfig(name: 'product_brand'),
            'digital_product_setting' => (string)getWebConfig(name: 'digital_product'),
            'system_default_currency' => (int)getWebConfig(name: 'system_default_currency'),
            'digital_payment' => (boolean)getWebConfig(name: 'digital_payment')['status'] ?? 0,
            'cash_on_delivery' => (boolean)getWebConfig(name: 'cash_on_delivery')['status'] ?? 0,
            'seller_registration' => (string)getWebConfig(name: 'seller_registration') ?? 0,
            'pos_active' => (string)getWebConfig(name: 'seller_pos') ?? 0,
            'company_name' => getWebConfig(name: 'company_name') ?? '',
            'company_phone' => getWebConfig(name: 'company_phone') ?? '',
            'company_email' => getWebConfig(name: 'company_email') ?? '',
            'company_logo' => $companyLogo,
            'company_cover_image' => $companyShopBanner,
            'company_fav_icon' => $companyFavIcon,
            'delivery_country_restriction' => (int)getWebConfig(name: 'delivery_country_restriction'),
            'delivery_zip_code_area_restriction' => (int)getWebConfig(name: 'delivery_zip_code_area_restriction'),
            'base_urls' => [
                'product_image_url' => ProductManager::product_image_path('product'),
                'product_thumbnail_url' => ProductManager::product_image_path('thumbnail'),
                'digital_product_url' => dynamicStorage(path: 'storage/app/public/product/digital-product'),
                'brand_image_url' => dynamicStorage(path: 'storage/app/public/brand'),
                'customer_image_url' => dynamicStorage(path: 'storage/app/public/profile'),
                'banner_image_url' => dynamicStorage(path: 'storage/app/public/banner'),
                'category_image_url' => dynamicStorage(path: 'storage/app/public/category'),
                'review_image_url' => dynamicStorage(path: 'storage/app/public'),
                'seller_image_url' => dynamicStorage(path: 'storage/app/public/seller'),
                'shop_image_url' => dynamicStorage(path: 'storage/app/public/shop'),
                'notification_image_url' => dynamicStorage(path: 'storage/app/public/notification'),
                'delivery_man_image_url' => dynamicStorage(path: 'storage/app/public/delivery-man'),
                'support_ticket_image_url' => dynamicStorage(path: 'storage/app/public/support-ticket'),
                'chatting_image_url' => dynamicStorage(path: 'storage/app/public/chatting'),
            ],
            'static_urls' => [
                'contact_us' => route('contacts'),
                'brands' => route('brands'),
                'categories' => route('categories'),
                'customer_account' => route('user-account'),
            ],
            'about_us' => null,
            'privacy_policy' => null,
            'faq' => $this->cacheHelpTopicTable(),
            'terms_&_conditions' => null,
            'refund_policy' => null,
            'return_policy' => null,
            'cancellation_policy' => null,
            'shipping_policy' => null,
            'company_reliability' => getWebConfig(name: 'company_reliability'),
            'currency_list' => $this->cacheCurrencyTable(),
            'currency_symbol_position' => getWebConfig(name: 'currency_symbol_position') ?? 'right',
            'business_mode' => getWebConfig(name: 'business_mode'),
            'language' => $languageArray,
            'colors' => $this->cacheColorsList(),
            'unit' => units(),
            'shipping_method' => getWebConfig(name: 'shipping_method'),
            'email_verification' => (boolean)getLoginConfig(key: 'email_verification'),
            'phone_verification' => (boolean)getLoginConfig(key: 'phone_verification'),
            'country_code' => getWebConfig(name: 'country_code'),
            'social_login' => $socialLoginConfig,
            'currency_model' => getWebConfig(name: 'currency_model'),
            'forgot_password_verification' => getWebConfig(name: 'forgot_password_verification'),
            'announcement' => getWebConfig(name: 'announcement'),
            'pixel_analytics' => getWebConfig(name: 'pixel_analytics'),
            'software_version' => SOFTWARE_VERSION,
            'decimal_point_settings' => (int)getWebConfig(name: 'decimal_point_settings'),
            'inhouse_selected_shipping_type' => $shippingType,
            'billing_input_by_customer' => (int)getWebConfig(name: 'billing_input_by_customer'),
            'minimum_order_limit' => (int)getWebConfig(name: 'minimum_order_limit'),
            'wallet_status' => (int)getWebConfig(name: 'wallet_status'),
            'loyalty_point_status' => (int)getWebConfig(name: 'loyalty_point_status'),
            'loyalty_point_exchange_rate' => (int)getWebConfig(name: 'loyalty_point_exchange_rate'),
            'loyalty_point_minimum_point' => (int)getWebConfig(name: 'loyalty_point_minimum_point'),
            'payment_methods' => $paymentMethods,
            'offline_payment' => $offlinePayment,
            'payment_method_image_path' => dynamicStorage(path: 'storage/app/public/payment_modules/gateway_image'),
            'ref_earning_status' => getWebConfig(name: 'ref_earning_status') ?? 0,
            'active_theme' => theme_root_path(),
            'theme_comfortable_panel_version' => $themeComfortablePanelVersion,
            'popular_tags' => $this->cacheTagTable(),
            'guest_checkout' => (int)getWebConfig(name: 'guest_checkout'),
            'upload_picture_on_delivery' => getWebConfig(name: 'upload_picture_on_delivery'),
            'user_app_version_control' => getWebConfig(name: 'user_app_version_control'),
            'seller_app_version_control' => getWebConfig(name: 'seller_app_version_control'),
            'delivery_man_app_version_control' => getWebConfig(name: 'delivery_man_app_version_control'),
            'add_funds_to_wallet' => (int)getWebConfig(name: 'add_funds_to_wallet'),
            'minimum_add_fund_amount' => getWebConfig(name: 'minimum_add_fund_amount'),
            'maximum_add_fund_amount' => getWebConfig(name: 'maximum_add_fund_amount'),
            'inhouse_temporary_close' => getWebConfig(name: 'temporary_close'),
            'inhouse_vacation_add' => getWebConfig(name: 'vacation_add'),
            'free_delivery_status' => (int)getWebConfig(name: 'free_delivery_status'),
            'free_delivery_over_amount' => getWebConfig(name: 'free_delivery_over_amount'),
            'free_delivery_responsibility' => getWebConfig(name: 'free_delivery_responsibility'),
            'free_delivery_over_amount_seller' => getWebConfig(name: 'free_delivery_over_amount_seller'),
            'minimum_order_amount_status' => (int)getWebConfig(name: 'minimum_order_amount_status'),
            'minimum_order_amount' => getWebConfig(name: 'minimum_order_amount'),
            'minimum_order_amount_by_seller' => (int)getWebConfig(name: 'minimum_order_amount_by_seller'),
            'order_verification' => (int)getWebConfig(name: 'order_verification'),
            'referral_customer_signup_url' => route('home') . '?referral_code=',
            'system_timezone' => getWebConfig(name: 'timezone'),
            'refund_day_limit' => getWebConfig(name: 'refund_day_limit') ?? 0,
            'map_api_status' => (int)getWebConfig(name: 'map_api_status'),
            'default_location' => getWebConfig(name: 'default_location'),
            'vendor_review_reply_status' => (int)getWebConfig(name: 'vendor_review_reply_status') ?? 0,
            'maintenance_mode' => $maintenanceMode,
            'customer_login' => $customerLogin,
            'customer_verification' => $customerVerification,
            'otp_resend_time' => getWebConfig(name: 'otp_resend_time') ?? 60,
            'vendor_forgot_password_method' => getWebConfig(name: 'vendor_forgot_password_method') ?? 'phone',
            'vendor_forgot_password_sms_method' => $firebaseOTPVerification['status'] == 1 ? 'firebase' : 'sms',
            'deliveryman_forgot_password_method' => getWebConfig(name: 'deliveryman_forgot_password_method') ?? 'phone',
            'blog_page' => Route::has('app.blog.index') && getWebConfig(name: 'blog_feature_active_status') ? route('app.blog.index') : '',
            'in_house_shop' => getInHouseShopConfig(),
            'product_max_unit_price_range' => getProductMaxUnitPriceRange(),
            'product_min_unit_price_range' => getProductMinUnitPriceRange(),

            'system_tax_type' => $systemTax?->tax_type ?? 'order_wise',
            'system_tax_include_status' => (int)$systemTax?->is_included,
            'is_ai_features_enabled' => (int)$this->getActiveAIProviderConfig()?->status ?? 0,
            'server_upload_max_filesize' => ini_get('upload_max_filesize'),
            'server_post_max_size' => ini_get('post_max_size'),
            'system_image_file_upload_max_size' => getFileUploadMaxSize(type: 'image'),
            'system_general_file_upload_max_size' => getFileUploadMaxSize(type: 'file'),
            'vendor_can_edit_order_status' => getWebConfig('vendor_can_edit_order') ?? 0,

            'auction_feature_status' => $auctionFeatureStatus,
            'auction_commission_percentage' => getWebConfig(name: 'auction_commission_percentage'),
            'auction_expired_visibility_duration' => getWebConfig(name: 'auction_expired_visibility_duration'),
            'auction_entry_fee_amount_status' => getWebConfig(name: 'auction_entry_fee_amount_status'),
            'auction_entry_fee_amount_value' => getWebConfig(name: 'auction_entry_fee_amount_value'),
            'auction_upcoming_visibility_duration' => getWebConfig(name: 'auction_upcoming_visibility_duration'),
            'auction_winner_claim_time_limit' => getWebConfig(name: 'auction_winner_claim_time_limit'),
            'auction_home_page_setup' => getWebConfig(name: 'auction_home_page_setup'),
            'auction_home_bg_image' => getWebConfig(name: 'auction_home_bg_image'),
            'auction_commitments' => getWebConfig(name: 'auction_commitments'),
            'active_auction_for_vendor' => $auctionFeatureStatus && (int)(getWebConfig(name: 'active_auction_for_vendor') ?? 0),
            'active_auction_for_customer' => $auctionFeatureStatus && (int)(getWebConfig(name: 'active_auction_for_customer') ?? 0),
        ]);
    }

    public function getBusinessPagesList(Request $request)
    {
        $businessPages = $this->cacheBusinessPagesTable();
        if ($request['type'] == 'default') {
            $businessPages = $businessPages?->where('default_status', 1);
        }
        if ($request['type'] == 'pages') {
            $businessPages = $businessPages?->where('default_status', 0);
        }
        return $businessPages->values();
    }
}