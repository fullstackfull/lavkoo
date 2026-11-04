<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Contracts\Repositories\AnalyticScriptRepositoryInterface;
use App\Contracts\Repositories\DeliveryManRepositoryInterface;
use App\Contracts\Repositories\OfflinePaymentMethodRepositoryInterface;
use App\Contracts\Repositories\SettingRepositoryInterface;
use App\Contracts\Repositories\SocialMediaRepositoryInterface;
use App\Contracts\Repositories\VendorRepositoryInterface;
use App\Enums\GlobalConstant;
use App\Http\Requests\Admin\DeepLinkRequest;
use App\Http\Requests\Admin\ProductSettingsUpdateRequest;
use App\Services\DeepLink\AppLinkService;
use App\Services\DeepLink\AssociationFileWriter;
use App\Services\SettingService;
use App\Traits\CalculatorTrait;
use App\Traits\PaymentGatewayTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Traits\SettingsTrait;
use App\Traits\FileManagerTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\View\View;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\RedirectResponse;
use App\Http\Controllers\BaseController;
use App\Services\BusinessSettingService;
use App\Http\Requests\Admin\BusinessSettingRequest;
use App\Contracts\Repositories\CurrencyRepositoryInterface;
use App\Contracts\Repositories\BusinessSettingRepositoryInterface;
use Illuminate\Support\Facades\Cache;

class BusinessSettingsController extends BaseController
{

    use SettingsTrait, CalculatorTrait, PaymentGatewayTrait;
    use FileManagerTrait {
        delete as deleteFile;
        update as updateFile;
    }

    public function __construct(
        private readonly BusinessSettingRepositoryInterface      $businessSettingRepo,
        private readonly AnalyticScriptRepositoryInterface       $analyticScriptRepo,
        private readonly VendorRepositoryInterface               $vendorRepo,
        private readonly DeliveryManRepositoryInterface          $deliveryManRepo,
        private readonly CurrencyRepositoryInterface             $currencyRepo,
        private readonly SocialMediaRepositoryInterface          $socialMediaRepo,
        private readonly BusinessSettingService                  $businessSettingService,
        private readonly SettingService                          $settingService,
        private readonly SettingRepositoryInterface              $settingRepo,
        private readonly OfflinePaymentMethodRepositoryInterface $offlinePaymentMethodRepo,
    ) {}

    /**
     * @param Request|null $request
     * @param string|null $type
     * @return View Index function is the starting point of a controller
     * Index function is the starting point of a controller
     */
    public function index(Request|null $request, ?string $type = null): View
    {
        $web = $this->businessSettingRepo->getListWhere(dataLimit: 'all');
        $settings = $this->getSettings($web, 'colors');
        $data = json_decode($settings['value'], true);

        $systemDefaultCurrency = $this->getSettings(object: $web, type: 'system_default_currency')->value ?? '';
        $systemCurrency = $this->currencyRepo->getFirstWhere(params: ['id' => $systemDefaultCurrency]);

        $currencySupportGateway = $this->checkCurrencySupportGateway(currencyCode: $systemCurrency);


        $businessSetting = [
            'primary_color' => $data['primary'] ?? '',
            'secondary_color' => $data['secondary'] ?? '',
            'primary_color_light' => $data['primary_light'] ?? '',
            'company_name' => $this->getSettings(object: $web, type: 'company_name')->value ?? '',
            'company_email' => $this->getSettings(object: $web, type: 'company_email')->value ?? '',
            'company_phone' => $this->getSettings(object: $web, type: 'company_phone')->value ?? '',
            'language' => $this->getSettings(object: $web, type: 'language')->value ?? '',
            'web_logo' => $this->getSettings(object: $web, type: 'company_web_logo')->value ?? '',
            'mob_logo' => $this->getSettings(object: $web, type: 'company_mobile_logo')->value ?? '',
            'fav_icon' => $this->getSettings(object: $web, type: 'company_fav_icon')->value ?? '',
            'footer_logo' => $this->getSettings(object: $web, type: 'company_footer_logo')->value ?? '',
            'shop_address' => $this->getSettings(object: $web, type: 'shop_address')->value ?? '',
            'company_copyright_text' => $this->getSettings(object: $web, type: 'company_copyright_text')->value ?? '',
            'system_default_currency' => $systemDefaultCurrency,
            'gateway_currency_support' => $currencySupportGateway,
            'currency_symbol_position' => $this->getSettings(object: $web, type: 'currency_symbol_position')->value ?? '',
            'business_mode' => $this->getSettings(object: $web, type: 'business_mode')->value ?? '',
            'email_verification' => $this->getSettings(object: $web, type: 'email_verification')->value ?? '',
            'otp_verification' => $this->getSettings(object: $web, type: 'otp_verification')->value ?? '',
            'guest_checkout' => $this->getSettings(object: $web, type: 'guest_checkout')->value ?? '',
            'pagination_limit' => getWebConfig(name: 'pagination_limit'),
            'copyright_text' => $this->getSettings(object: $web, type: 'company_copyright_text')->value ?? '',
            'decimal_point_settings' => $this->getSettings(object: $web, type: 'decimal_point_settings')->value ?? 0,
            'loader_gif' => $this->getSettings(object: $web, type: 'loader_gif')->value ?? '',
            'default_location' => json_decode($this->getSettings(object: $web, type: 'default_location')?->value ?? '', true) ?? [],
            'maintenance_mode' => $this->getSettings(object: $web, type: 'maintenance_mode')->value ?? 0,
        ];

        $maintenanceSystemSetup = json_decode($this->getSettings(object: $web, type: 'maintenance_system_setup')?->value, true) ?? [];
        $CurrencyList = $this->currencyRepo->getListWhere(dataLimit: 'all');

        $selectedMaintenanceDuration = getWebConfig(name: 'maintenance_duration_setup') ?? [];
        $maintenanceStartDate = isset($selectedMaintenanceDuration['start_date']) ? Carbon::parse($selectedMaintenanceDuration['start_date']) : null;
        $maintenanceEndDate = isset($selectedMaintenanceDuration['end_date']) ? Carbon::parse($selectedMaintenanceDuration['end_date']) : null;
        $selectedMaintenanceMessage = getWebConfig(name: 'maintenance_message_setup') ?? [];

        return view('admin-views.business-settings.website-info', [
            'CurrencyList' => $CurrencyList,
            'businessSetting' => $businessSetting,
            'maintenanceStartDate' => $maintenanceStartDate,
            'maintenanceEndDate' => $maintenanceEndDate,
            'selectedMaintenanceDuration' => $selectedMaintenanceDuration,
            'maintenanceSystemSetup' => $maintenanceSystemSetup,
            'selectedMaintenanceMessage' => $selectedMaintenanceMessage,
            'cashOnDelivery' => getWebConfig(name: 'cash_on_delivery'),
            'digitalPayment' => getWebConfig(name: 'digital_payment'),
            'offlinePayment' => getWebConfig(name: 'offline_payment'),
            'cookieSetting' => getWebConfig(name: 'cookie_setting'),
            'systemCurrency' => $systemCurrency,
            'checkMinimumOneDigitalPayment' => $this->checkMinimumOneDigitalPayment()
        ]);
    }

    public function updateSettings(BusinessSettingRequest $request, BusinessSettingService $businessSettingService): RedirectResponse
    {
        if ($request['email_verification'] == 1) {
            $request['phone_verification'] = 0;
        } elseif ($request['phone_verification'] == 1) {
            $request['email_verification'] = 0;
        }
        $this->businessSettingRepo->updateOrInsert(type: 'company_name', value: $request['company_name']);
        $this->businessSettingRepo->updateOrInsert(type: 'company_email', value: $request['company_email']);
        $this->businessSettingRepo->updateOrInsert(type: 'company_phone', value: $request['company_phone']);
        $this->businessSettingRepo->updateOrInsert(type: 'country_code', value: $request['country_code']);
        $this->businessSettingRepo->updateOrInsert(type: 'shop_address', value: $request['shop_address']);
        // Optional legal identifiers shown in the storefront footer when filled.
        foreach (['company_registration_no', 'company_vat_no', 'company_platform_no'] as $identifier) {
            $this->businessSettingRepo->updateOrInsert(type: $identifier, value: strip_tags((string) $request[$identifier]));
        }
        $this->businessSettingRepo->updateOrInsert(type: 'pagination_limit', value: $request['pagination_limit']);

        $appAppleStore = json_encode(['status' => $request['app_store_download_status'] ?? 0, 'link' => $request['app_store_download_url']]);
        $this->businessSettingRepo->updateOrInsert(type: 'download_app_apple_store', value: $appAppleStore);

        $appGoogleStore = json_encode(['status' => $request['play_store_download_status'] ?? 0, 'link' => $request['play_store_download_url']]);
        $this->businessSettingRepo->updateOrInsert(type: 'download_app_google_store', value: $appGoogleStore);

        $defaultLocation = json_encode(['lat' => $request['latitude'], 'lng' => $request['longitude']]);
        $this->businessSettingRepo->updateOrInsert(type: 'default_location', value: $defaultLocation);

        $this->businessSettingRepo->updateOrInsert(type: 'timezone', value: $request['timezone']);
        $this->businessSettingRepo->updateOrInsert(type: 'currency_symbol_position', value: $request['currency_symbol_position']);
        $this->businessSettingRepo->updateOrInsert(type: 'decimal_point_settings', value: $request['decimal_point_settings'] ?? 0);

        $this->businessSettingRepo->updateOrInsert(type: 'business_mode', value: $request['business_mode']);
        $this->businessSettingRepo->updateOrInsert(type: 'sales_commission', value: $request->get('sales_commission', 0));


        $this->businessSettingRepo->updateOrInsert(type: 'company_copyright_text', value: $request['company_copyright_text']);
        $this->businessSettingRepo->updateOrInsert(type: 'cookie_setting', value: json_encode([
            'status' => $request->get('cookie_status', 0),
            'cookie_text' => $request['cookie_text'],
        ]));
        updateSetupGuideCacheKey(key: 'general_setup', panel: 'admin');

        $this->updateSystemCurrency(request: $request);
        $paymentResponse = $this->updatePaymentOption(request: $request);
        if ($paymentResponse instanceof RedirectResponse) {
            return $paymentResponse;
        }

        ToastMagic::success(translate('updated_successfully'));
        return redirect()->route('admin.business-settings.web-config.index');
    }

    public function updateSystemCurrency(object|array $request): void
    {
        $this->businessSettingRepo->updateWhere(params: ['type' => 'system_default_currency'], data: ['value' => $request['currency_id']]);
        $currencyModel = getWebConfig(name: 'currency_model');
        if ($currencyModel == 'multi_currency') {
            $default = $this->currencyRepo->getFirstWhere(params: ['id' => $request['currency_id']]);
            $allCurrencies = $this->currencyRepo->getListWhere(dataLimit: 'all');
            foreach ($allCurrencies as $data) {
                $exchangeRateWithPrecision = $this->getDivideWithDynamicPrecision(numerator: $data['exchange_rate'], denominator: $default['exchange_rate'], maxPrecision: 20);
                $this->currencyRepo->update(id: $data['id'], data: ['exchange_rate' => $exchangeRateWithPrecision]);
            }
        }

        session()->forget('usd');
        session()->forget('default');
        session()->forget('system_default_currency_info');
        session()->forget('currency_code');
        session()->forget('currency_symbol');
        session()->forget('currency_exchange_rate');
    }

    public function updateSystemMode(Request $request): RedirectResponse|JsonResponse
    {
        if (config('app.mode') == 'demo') {
            if ($request->ajax()) {
                return response()->json([
                    'message' => translate('you_can_not_update_this_on_demo_mode'),
                    401
                ]);
            } else {
                ToastMagic::error(translate('you_can_not_update_this_on_demo_mode'));
                return back();
            }
        }

        $selectedSystems = [];
        $totalSelectedSystems = 0;
        $systemPlatforms = ['user_app', 'user_website', 'vendor_app', 'deliveryman_app', 'vendor_panel'];
        foreach ($systemPlatforms as $system) {
            $selectedSystems[$system] = $request->get($system, 0);
            $totalSelectedSystems = $request->get($system, 0) + $totalSelectedSystems;
        }

        if ($totalSelectedSystems == 0 && $request->get('maintenance_mode', 0) == 1) {
            if ($request->ajax()) {
                return response()->json([
                    'message' => translate('Please_select_minimum_one_system'),
                    'status' => 'error'
                ]);
            } else {
                ToastMagic::error(translate('Please_select_minimum_one_system'));
                return back();
            }
        }

        $maintenanceDuration = [
            'maintenance_duration' => $request['maintenance_duration'],
            'start_date' => $request['start_date'] ?? null,
            'end_date' => $request['end_date'] ?? null,
        ];
        $maintenanceMessageSetup = [
            'business_number' => $request->has('business_number') ? 1 : 0,
            'business_email' => $request->has('business_email') ? 1 : 0,
            'maintenance_message' => $request['maintenance_message'],
            'message_body' => $request['message_body']
        ];

        $maintenanceModeStatus = $request->get('maintenance_mode', 0);
        $this->businessSettingRepo->updateOrInsert(type: 'maintenance_system_setup', value: json_encode($selectedSystems));
        $this->businessSettingRepo->updateOrInsert(type: 'maintenance_mode', value: $maintenanceModeStatus);
        $this->businessSettingRepo->updateOrInsert(type: 'maintenance_duration_setup', value: json_encode($maintenanceDuration));
        $this->businessSettingRepo->updateOrInsert(type: 'maintenance_message_setup', value: json_encode($maintenanceMessageSetup));
        clearWebConfigCacheKeys();

        $businessMode = getWebConfig(name: 'business_mode');
        if ($maintenanceModeStatus == 1) {
            $maintenanceArray = [
                'status' => $maintenanceModeStatus,
                'start_date' => $request->input('start_date', null),
                'end_date' => $request->input('end_date', null),
                'selectedSystems' => $selectedSystems,
                'maintenance_duration' => $maintenanceDuration,
                'maintenance_message' => $maintenanceMessageSetup,
            ];

            Cache::put('system_maintenance_mode', $maintenanceArray, now()->addYears(1));
        } else {
            Cache::forget('system_maintenance_mode');
        }

        if ($selectedSystems['user_app']) {
            $this->businessSettingService->sendMaintenanceModeNotification(status: $maintenanceModeStatus ? 'on' : 'off', topic: 'maintenance_mode_start_user_app');
        }

        if ($businessMode == 'multi' && $selectedSystems['vendor_app']) {
            $this->businessSettingService->sendMaintenanceModeNotification(status: $maintenanceModeStatus ? 'on' : 'off', topic: 'maintenance_mode_start_vendor');
        }

        if ($selectedSystems['deliveryman_app']) {
            $this->businessSettingService->sendMaintenanceModeNotification(status: $maintenanceModeStatus ? 'on' : 'off', topic: 'maintenance_mode_start_deliveryman');
        }

        if ($request->ajax()) {
            return response()->json([
                'message' => $maintenanceModeStatus ? translate('Maintenance_mode_is_on') : translate('Maintenance_mode_is_off'),
                'status' => 'success'
            ]);
        } else {
            ToastMagic::success($maintenanceModeStatus ? translate('Maintenance_mode_is_on') : translate('Maintenance_mode_is_off'));
            return back();
        }
    }

    public function getAppSettingsView(): View
    {
        $userApp = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'user_app_version_control']);
        $userAppVersionControl = $userApp ? json_decode($userApp['value'], true) : [];
        $sellerApp = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'seller_app_version_control']);
        $sellerAppVersionControl = $sellerApp ? json_decode($sellerApp['value'], true) : [];
        $deliverymanApp = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'delivery_man_app_version_control']);
        $deliverymanAppVersionControl = $deliverymanApp ? json_decode($deliverymanApp['value'], true) : [];

        return view('admin-views.system-setup.apps-settings', compact('userAppVersionControl', 'sellerAppVersionControl', 'deliverymanAppVersionControl'));
    }

    public function updateAppSettings(Request $request): RedirectResponse
    {
        if (in_array($request['type'], ['user_app_version_control', 'seller_app_version_control', 'delivery_man_app_version_control'])) {
            $this->businessSettingRepo->updateOrInsert(type: $request['type'], value: json_encode([
                'for_android' => $request['for_android'],
                'for_ios' => $request['for_ios'],
            ]));
        }
        ToastMagic::success(translate('updated_successfully'));
        return back();
    }

    public function getAppDeepLinkView(AppLinkService $appLinks, AssociationFileWriter $writer): View
    {
        $getDeeplink = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'app_deep_link']);
        $deeplink = json_decode($getDeeplink?->value ?? '[]', true);
        $fileStatus = [
            'base_exists' => file_exists(base_path('.well-known/assetlinks.json')),
            'public_exists' => file_exists(public_path('.well-known/assetlinks.json')),
            'base_writable' => is_writable(base_path()),
            'public_writable' => is_writable(public_path()),
        ];

        // Which paths open the app, and which of them the file on disk actually claims. The two
        // drift apart the moment the published list changes in code, which is exactly what
        // happened when campaign short links became app links, so the screen shows both.
        $linkPaths = [
            'ios' => $appLinks->paths(AppLinkService::PLATFORM_IOS),
            'android' => $appLinks->paths(AppLinkService::PLATFORM_ANDROID),
        ];
        $published = $writer->published();
        // Identifiers as well as paths: the file shipped with the project carried the full path
        // list and an appID of ".", which named no app at all — comparing paths alone called that
        // up to date.
        $publishedIsStale = $published['exists'] && !$writer->isCurrent($deeplink ?? []);

        return view('admin-views.system-setup.app-deep-link', compact(
            'deeplink', 'fileStatus', 'linkPaths', 'published', 'publishedIsStale'
        ));
    }

    public function updateAppDeepLink(DeepLinkRequest $request): RedirectResponse
    {
        if (config('app.mode') == 'demo') {
            ToastMagic::error(translate('you_can_not_update_this_on_demo_mode'));
            return back();
        }
        try {
            $this->businessSettingRepo->updateOrInsert(
                type: $request['type'],
                value: json_encode([
                    'android_package_name' => $request['android_package_name'],
                    'ios_bundle_id' => $request['ios_bundle_id'],
                    'ios_team_id' => $request['ios_team_id'],
                    'android_sha256_fingerprint' => $request['android_sha256_fingerprint'],
                    'playstore_redirect_url' => $request['playstore_redirect_url'],
                    'app_store_redirect_url' => $request['app_store_redirect_url'],
                ])
            );

            $failed = array_filter(
                app(AssociationFileWriter::class)->publish($request->all()),
                static fn (array $result): bool => !$result['written'] && $result['reason'] !== 'not_configured'
            );

            if ($failed !== []) {
                ToastMagic::error(translate('the_settings_were_saved_but_the_app_association_files_could_not_be_written'));
                return back();
            }

            ToastMagic::success(translate('updated_successfully'));
        } catch (\Exception $e) {
            ToastMagic::error(translate('update_failed') . ': ' . $e->getMessage());
        }

        return back();
    }

    public function updateCookieSetting(Request $request): RedirectResponse
    {
        $this->businessSettingRepo->updateOrInsert(type: 'cookie_setting', value: json_encode([
            'status' => $request->get('status', 0),
            'cookie_text' => $request['cookie_text'],
        ]));
        ToastMagic::success(translate('cookie_settings_updated_successfully'));
        return redirect()->back();
    }

    public function getAnalyticsView(): View
    {
        $analytics = $this->analyticScriptRepo->getListWhere(dataLimit: 'all');
        $analyticsData = [];
        foreach ($analytics as $analytic) {
            $analyticsData[$analytic['type']] = $analytic;
        }
        return view('admin-views.third-party.analytics.index', compact('analyticsData'));
    }

    public function updateAnalytics(Request $request): RedirectResponse
    {
        $analyticScriptsTypes = ['meta_pixel', 'linkedin_insight', 'tiktok_tag', 'snapchat_tag', 'twitter_tag', 'pinterest_tag', 'google_tag_manager', 'google_analytics'];
        if (!in_array($request['type'], $analyticScriptsTypes)) {
            ToastMagic::error(translate('Update_failed'));
            return back();
        }

        if (empty($request['script_id']) && $request['is_active'] == 1) {
            $type = str_replace(' ', '_', ucwords(str_replace('_', ' ', $request['type'])));
            ToastMagic::error(translate('Please_ensure_you_have_filled_in_the_' . $type . '_script_ID.'));
            return back();
        }

        $this->analyticScriptRepo->updateOrInsert(params: ['type' => $request['type']], data: [
            'name' => ucwords(str_replace('_', ' ', $request['type'])),
            'type' => $request['type'],
            'script_id' => $request['script_id'],
            'is_active' => $request['is_active'] ?? 0,
        ]);

        ToastMagic::success(translate('Update_successfully'));
        return back();
    }

    public function getProductSettingsView(): View
    {
        $digitalProduct = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'digital_product']);
        $brand = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'product_brand']);
        $stockLimit = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'stock_limit']);
        return view('admin-views.business-settings.product-settings', compact('digitalProduct', 'brand', 'stockLimit'));
    }

    public function updateProductSettings(ProductSettingsUpdateRequest $request): RedirectResponse
    {
        $this->businessSettingRepo->updateOrInsert(type: 'stock_limit', value: $request->get('stock_limit', 0));
        $this->businessSettingRepo->updateOrInsert(type: 'product_brand', value: $request->get('product_brand', 0));
        $this->businessSettingRepo->updateOrInsert(type: 'digital_product', value: $request->get('digital_product', 0));
        $this->businessSettingRepo->updateOrInsert(type: 'new_product_approval', value: $request->get('new_product_approval', 0));
        $this->businessSettingRepo->updateOrInsert(type: 'product_wise_shipping_cost_approval', value: $request->get('product_wise_shipping_cost_approval', 0));

        // Product page signals. The viewer range is clamped here rather than trusted from the form,
        // so a swapped min/max cannot reach the storefront as an empty range.
        $viewersMin = max(2, (int) $request->get('product_live_viewers_min', 8));
        $viewersMax = max($viewersMin + 1, (int) $request->get('product_live_viewers_max', 60));
        $this->businessSettingRepo->updateOrInsert(type: 'product_live_viewers_status', value: $request->get('product_live_viewers_status', 0));
        $this->businessSettingRepo->updateOrInsert(type: 'product_live_viewers_min', value: $viewersMin);
        $this->businessSettingRepo->updateOrInsert(type: 'product_live_viewers_max', value: $viewersMax);
        $this->businessSettingRepo->updateOrInsert(type: 'product_authenticity_badge_status', value: $request->get('product_authenticity_badge_status', 0));
        $this->businessSettingRepo->updateOrInsert(type: 'product_authenticity_badge_text', value: strip_tags(is_string($badgeText = $request->get('product_authenticity_badge_text')) ? $badgeText : ''));
        $this->businessSettingRepo->updateOrInsert(type: 'bought_together_status', value: $request->get('bought_together_status', 0));
        $this->businessSettingRepo->updateOrInsert(type: 'bought_together_limit', value: max(2, min(12, (int) $request->get('bought_together_limit', 6))));
        $this->businessSettingRepo->updateOrInsert(type: 'bought_together_auto_fill', value: $request->get('bought_together_auto_fill', 0));

        clearWebConfigCacheKeys();
        ToastMagic::success(translate('updated_successfully'));
        return back();
    }

    public function getAnnouncementView(): View
    {
        $announcement = getWebConfig(name: 'announcement');
        return view('admin-views.business-settings.website-announcement', compact('announcement'));
    }

    public function updateAnnouncement(Request $request): RedirectResponse
    {
        $value = json_encode([
            'status' => $request['announcement_status'],
            'color' => $request['announcement_color'],
            'text_color' => $request['text_color'],
            'announcement' => $request['announcement'],
        ]);
        $this->businessSettingRepo->updateOrInsert(type: 'announcement', value: $value);
        ToastMagic::success(translate('announcement_updated_successfully'));
        return back();
    }

    public function getRefundSetupView(): View
    {
        return view('admin-views.business-settings.refund-setup');
    }

    public function updateRefundSetup(Request $request): RedirectResponse
    {
        $this->businessSettingRepo->updateOrInsert(type: 'refund_day_limit', value: $request->get('refund_day_limit', 0));
        $this->businessSettingRepo->updateOrInsert(type: 'wallet_add_refund', value: $request->get('wallet_add_refund', 0));
        ToastMagic::success(translate('Refund_config_updated_successfully'));
        return back();
    }

    private function checkCurrencySupportGateway(object|null $currencyCode): bool
    {
        $digitalPayment = getWebConfig(name: 'digital_payment');
        if (isset($digitalPayment['status']) && $digitalPayment['status']) {
            $gatewayStatus = config('get_payment_publish_status') ?? 0;
            if ($gatewayStatus) {
                $activeGateway = $this->settingRepo->getListWhere(filters: ['settings_type' => 'payment_config', 'is_active' => 1], dataLimit: 'all');
            } else {
                $activeGateway = $this->settingRepo->getListWhereIn(filters: ['settings_type' => 'payment_config', 'is_active' => 1], whereInFilters: ['key_name' => GlobalConstant::DEFAULT_PAYMENT_GATEWAYS], dataLimit: 'all');
            }
            foreach ($activeGateway?->pluck('key_name')?->toArray() ?? [] as $paymentGateway) {
                $supportedCurrencies = $this->getPaymentGatewaySupportedCurrencies(key: $paymentGateway);
                if ($supportedCurrencies && array_key_exists($currencyCode?->name, $supportedCurrencies)) {
                    return true;
                }
            }
            return false;
        }
        return true;
    }

    private function checkMinimumOneDigitalPayment(): bool
    {
        $paymentGatewayStatus = config('get_payment_publish_status') ?? 0;

        $gatewayKeys = [];
        foreach (GATEWAYS_PAYMENT_METHODS as $method) {
            $gatewayKeys[] = $method["key"];
        }
        $paymentGatewaysList = $this->settingRepo->getListWhereIn(
            filters: ['is_active' => 1],
            whereInFilters: [
                'settings_type' => ['payment_config'],
                'key_name' => $paymentGatewayStatus ? $gatewayKeys : GlobalConstant::DEFAULT_PAYMENT_GATEWAYS
            ],
            dataLimit: 'all',
        );
        return !(count($paymentGatewaysList) == 0);
    }

    private function updatePaymentOption(mixed $request): ?RedirectResponse
    {
        if ($request['digital_payment'] == 1 && !$this->checkMinimumOneDigitalPayment() && $request['offline_payment'] != 1) {
            ToastMagic::warning(translate('you_must_active_one_of_digital_payment_methods'));
            return redirect()->back();
        }

        if ($request['offline_payment'] == 1 && $this->offlinePaymentMethodRepo->getListWhere(filters: ['status' => 'active'])->count() <= 0) {
            ToastMagic::warning(translate('you_must_active_one_of_offline_payment_methods'));
            return redirect()->back();
        }

        $this->businessSettingRepo->updateOrInsert(type: 'cash_on_delivery', value: json_encode(['status' => $request->get('cash_on_delivery', 0)]));
        $this->businessSettingRepo->updateOrInsert(type: 'digital_payment', value: json_encode(['status' => $request->get('digital_payment', 0)]));
        $this->businessSettingRepo->updateOrInsert(type: 'offline_payment', value: json_encode(['status' => $request->get('offline_payment', 0)]));

        return null;
    }
}
