<?php

use App\Models\Brand;
use App\Models\BusinessSetting;
use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;

if (!function_exists('checkSetupGuideRequirements')) {
    function checkSetupGuideRequirements($panel = 'admin'): array
    {
        $steps = getSetupGuideSteps(panel: $panel);

        uasort($steps, function ($a, $b) {
            return (empty($b['checked']) ? 0 : 1) <=> (empty($a['checked']) ? 0 : 1);
        });

        $checkedCount = count(array_filter($steps, function ($step) {
            return !empty($step['checked']);
        }));

        return [
            'totalSteps' => count($steps) - $checkedCount,
            'completePercent' => $checkedCount > 0 ? floor(($checkedCount / count($steps)) * 100) : 0,
            'steps' => $steps,
        ];
    }
}

if (!function_exists('checkSetupGuideCacheKey')) {
    function checkSetupGuideCacheKey($key = '', $panel = null)
    {
        $checkSetupGuideCacheKeys = [];
        if ($panel == 'admin') {
            $checkSetupGuideCacheKeys = getWebConfig(name: 'setup_guide_requirements_for_admin') ?? [
                'general_setup' => 0,
                'shipping_method' => 0,
                'language_setup' => 0,
                'currency_setup' => 0,
                'customer_login' => 0,
                'google_map_apis' => 0,
                'notification_configuration' => 0,
                'digital_payment_setup' => 0,
                'offline_payment_setup' => 0,
                'category_setup' => Category::all()->count() > 0 ? 1 : 0,
                'brand_setup' => Brand::all()->count() > 0 ? 1 : 0,
                'inhouse_shop_setup' => 0,
                'add_new_product' => Product::all()->count() > 0 ? 1 : 0,
            ];
        } else if ($panel == 'vendor') {
            $auth = auth('seller')->check() ? auth('seller')->user() : null;
            if ($auth) {

                $cacheKey = 'cache_for_vendor_wise_products_count_' . $auth['id'];
                $cacheKeys = Cache::get(CACHE_FOR_VENDOR_WISE_PRODUCTS_COUNT, []);
                if (!in_array($cacheKey, $cacheKeys)) {
                    $cacheKeys[] = $cacheKey;
                    Cache::put(CACHE_FOR_VENDOR_WISE_PRODUCTS_COUNT, $cacheKeys, CACHE_FOR_3_HOURS);
                }

                $newProductCount = Cache::remember($cacheKey, CACHE_FOR_3_HOURS, function () use ($auth) {
                    return Product::where(['added_by' => 'seller', 'user_id' => $auth['id']])->count();
                });

                $setupGuide = session('setup_guide_requirements_for_vendor_' . $auth['id'], (array)($auth?->shop?->setup_guide ?? []));
                $checkSetupGuideCacheKeys = !empty($setupGuide) ? $setupGuide : [
                    'shop_setup' => 0,
                    'add_new_product' => $newProductCount > 0 ? 1 : 0,
                    'order_setup' => 0,
                    'withdraw_setup' => 0,
                    'payment_information' => 0,
                ];
            }
        }
        return $checkSetupGuideCacheKeys[$key] ?? false;
    }
}

if (!function_exists('updateSetupGuideCacheKey')) {
    function updateSetupGuideCacheKey($key = '', $panel = null): void
    {
        if ($panel == 'admin') {
            $checkSetupGuideCacheKeys = getWebConfig(name: 'setup_guide_requirements_for_admin') ?? [];
            $checkSetupGuideCacheKeys = empty($checkSetupGuideCacheKeys) ? [] : $checkSetupGuideCacheKeys;

            if (!isset($checkSetupGuideCacheKeys[$key]) || $checkSetupGuideCacheKeys[$key] !== true) {
                $newCacheKeys = [];
                $newCacheKeys[$key] = true;
                $checkSetupGuideCacheKeys = array_merge($checkSetupGuideCacheKeys, $newCacheKeys);
                if (BusinessSetting::where(['type' => 'setup_guide_requirements_for_admin'])->first()) {
                    BusinessSetting::where(['type' => 'setup_guide_requirements_for_admin'])->update([
                        'value' => json_encode($checkSetupGuideCacheKeys),
                    ]);
                } else {
                    BusinessSetting::create([
                        'type' => 'setup_guide_requirements_for_admin',
                        'value' => json_encode($checkSetupGuideCacheKeys),
                        'updated_at' => now()
                    ]);
                }
                cacheRemoveByType(type: 'business_settings');
            }
        } elseif ($panel == 'vendor') {
            $auth = auth('seller')->check() ? auth('seller')->user() : null;
            if ($auth) {
                $checkSetupGuideKeys = session('setup_guide_requirements_for_vendor_' . $auth['id'], (array)($auth?->shop?->setup_guide ?? []));
                if (!isset($checkSetupGuideKeys[$key]) || $checkSetupGuideKeys[$key] !== true) {
                    $newCacheKeys = [];
                    $newCacheKeys[$key] = true;
                    $newCacheKeys['add_new_product'] = checkSetupGuideCacheKey(key: 'add_new_product', panel: 'vendor');
                    $checkSetupGuideKeys = array_merge($checkSetupGuideKeys, $newCacheKeys);
                    Shop::where(['seller_id' => $auth['id']])->update([
                        'setup_guide' => json_encode($checkSetupGuideKeys),
                    ]);
                    session()->put('setup_guide_requirements_for_vendor_' . $auth['id'], $checkSetupGuideKeys);
                }
            }
        }
    }
}


if (!function_exists('getSetupGuideSteps')) {
    function getSetupGuideSteps($panel = 'admin'): array
    {
        $steps = [];
        if ($panel === 'admin') {
            $steps['general_setup'] = [
                'title' => translate('General_Setup'),
                'route' => route('admin.business-settings.web-config.index'),
                'checked' => checkSetupGuideCacheKey(key: 'general_setup', panel: 'admin'),
                'disabled' => checkSetupGuideCacheKey(key: 'general_setup', panel: 'admin'),
            ];

            $steps['shipping_method'] = [
                'title' => translate('Shipping_Method'),
                'route' => route('admin.business-settings.shipping-method.index'),
                'checked' => checkSetupGuideCacheKey(key: 'shipping_method', panel: 'admin'),
                'disabled' => checkSetupGuideCacheKey(key: 'shipping_method', panel: 'admin'),
            ];

            $steps['language_setup'] = [
                'title' => translate('language_setup'),
                'route' => route('admin.system-setup.language.index'),
                'checked' => checkSetupGuideCacheKey(key: 'language_setup', panel: 'admin'),
                'disabled' => checkSetupGuideCacheKey(key: 'language_setup', panel: 'admin'),
            ];

            $steps['currency_setup'] = [
                'title' => translate('Currency'),
                'route' => route('admin.system-setup.currency.view'),
                'checked' => checkSetupGuideCacheKey(key: 'currency_setup', panel: 'admin'),
                'disabled' => checkSetupGuideCacheKey(key: 'currency_setup', panel: 'admin'),
            ];

            $steps['customer_login'] = [
                'title' => translate('Customer_Login'),
                'route' => route('admin.system-setup.login-settings.customer-login-setup'),
                'checked' => checkSetupGuideCacheKey(key: 'customer_login', panel: 'admin'),
                'disabled' => checkSetupGuideCacheKey(key: 'customer_login', panel: 'admin'),
            ];

            $steps['google_map_apis'] = [
                'title' => translate('Google_Map_APIs'),
                'route' => route('admin.third-party.map-api'),
                'checked' => checkSetupGuideCacheKey(key: 'google_map_apis', panel: 'admin'),
                'disabled' => checkSetupGuideCacheKey(key: 'google_map_apis', panel: 'admin'),
            ];

            $steps['notification_configuration'] = [
                'title' => translate('Notification_Configuration'),
                'route' => route('admin.push-notification.index'),
                'checked' => checkSetupGuideCacheKey(key: 'notification_configuration', panel: 'admin'),
                'disabled' => checkSetupGuideCacheKey(key: 'notification_configuration', panel: 'admin'),
            ];

            $steps['digital_payment_setup'] = [
                'title' => translate('Digital_Payment_Setup'),
                'route' => route('admin.third-party.payment-method.index'),
                'checked' => checkSetupGuideCacheKey(key: 'digital_payment_setup', panel: 'admin'),
                'disabled' => checkSetupGuideCacheKey(key: 'digital_payment_setup', panel: 'admin'),
            ];

            $steps['offline_payment_setup'] = [
                'title' => translate('Offline_Payment_Setup'),
                'route' => route('admin.third-party.offline-payment-method.index'),
                'checked' => checkSetupGuideCacheKey(key: 'offline_payment_setup', panel: 'admin'),
                'disabled' => checkSetupGuideCacheKey(key: 'offline_payment_setup', panel: 'admin'),
            ];

            $steps['category_setup'] = [
                'title' => translate('Category_Setup'),
                'route' => route('admin.category.view'),
                'checked' => checkSetupGuideCacheKey(key: 'category_setup', panel: 'admin'),
                'disabled' => checkSetupGuideCacheKey(key: 'category_setup', panel: 'admin'),
            ];

            $steps['brand_setup'] = [
                'title' => translate('Brand_Setup'),
                'route' => route('admin.brand.add-new'),
                'checked' => checkSetupGuideCacheKey(key: 'brand_setup', panel: 'admin'),
                'disabled' => checkSetupGuideCacheKey(key: 'brand_setup', panel: 'admin'),
            ];

            $steps['inhouse_shop_setup'] = [
                'title' => translate('Inhouse_Shop'),
                'route' => route('admin.business-settings.inhouse-shop'),
                'checked' => checkSetupGuideCacheKey(key: 'inhouse_shop_setup', panel: 'admin'),
                'disabled' => checkSetupGuideCacheKey(key: 'inhouse_shop_setup', panel: 'admin'),
            ];

            $steps['add_new_product'] = [
                'title' => translate('Add_New_Product'),
                'route' => route('admin.products.add'),
                'checked' => checkSetupGuideCacheKey(key: 'add_new_product', panel: 'admin'),
                'disabled' => checkSetupGuideCacheKey(key: 'add_new_product', panel: 'admin'),
            ];
        } elseif ($panel == 'vendor') {
            $steps['shop_setup'] = [
                'title' => translate('Shop_Setup'),
                'route' => route('vendor.shop.index'),
                'checked' => checkSetupGuideCacheKey(key: 'shop_setup', panel: 'vendor'),
                'disabled' => checkSetupGuideCacheKey(key: 'shop_setup', panel: 'vendor'),
            ];

            $steps['add_new_product'] = [
                'title' => translate('Add_New_Product'),
                'route' => route('vendor.products.add'),
                'checked' => checkSetupGuideCacheKey(key: 'add_new_product', panel: 'vendor'),
                'disabled' => checkSetupGuideCacheKey(key: 'add_new_product', panel: 'vendor'),
            ];

            $steps['order_setup'] = [
                'title' => translate('Other_Setup'),
                'route' => route('vendor.shop.other-setup'),
                'checked' => checkSetupGuideCacheKey(key: 'order_setup', panel: 'vendor'),
                'disabled' => checkSetupGuideCacheKey(key: 'order_setup', panel: 'vendor'),
            ];

            $steps['payment_information'] = [
                'title' => translate('Payment_Information'),
                'route' => route('vendor.shop.payment-information.index'),
                'checked' => checkSetupGuideCacheKey(key: 'payment_information', panel: 'vendor'),
                'disabled' => checkSetupGuideCacheKey(key: 'payment_information', panel: 'vendor'),
            ];

            $steps['withdraw_setup'] = [
                'title' => translate('withdraw_setup'),
                'route' => route('vendor.business-settings.withdraw.index'),
                'checked' => checkSetupGuideCacheKey(key: 'withdraw_setup', panel: 'vendor'),
                'disabled' => checkSetupGuideCacheKey(key: 'withdraw_setup', panel: 'vendor'),
            ];
        }
        return $steps;
    }
}
