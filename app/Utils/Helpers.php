<?php

namespace App\Utils;

use App\Models\AddFundBonusCategories;
use App\Models\OrderStatusHistory;
use App\Models\ShippingMethod;
use App\Models\Shop;
use App\Models\Admin;
use App\Models\BusinessSetting;
use App\Models\Category;
use App\Models\Color;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\NotificationMessage;
use App\Models\Order;
use App\Models\Seller;
use App\Models\Setting;
use App\Traits\CommonTrait;
use App\Models\User;
use App\Utils\CartManager;
use App\Utils\OrderManager;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Helpers
{
    use CommonTrait;

    public static function getCustomerInformation($request = null)
    {
        $user = null;
        if (auth('customer')->check()) {
            $user = auth('customer')->user();

        } elseif (is_object($request) && method_exists($request, 'user')) {
            $user = $request->user() ?? $request->user;

        } elseif (isset($request['payment_request_from']) && in_array($request['payment_request_from'], ['app']) && !isset($request->user)) {
            $user = $request['is_guest'] ? 'offline' : User::find($request['customer_id']);

        } elseif (session()->has('customer_id') && !session('is_guest')) {
            $user = User::find(session('customer_id'));

        } elseif (isset($request->user)) {
            $user = $request->user;
        } elseif (isset($request['payment_request_from']) && $request['payment_request_from'] == 'app' && isset($request->customer_id) && $request->is_guest != 1) {
            $user = User::find($request['customer_id']);
        }

        if ($user == null) {
            $user = 'offline';
        }

        return $user;
    }

    public static function default_lang()
    {
        if (strpos(url()->current(), '/api')) {
            $lang = App::getLocale();
        } elseif (session()->has('local')) {
            $lang = session('local');
        } else {
            $data = getWebConfig(name: 'language');
            $code = 'en';
            $direction = 'ltr';
            foreach ($data as $ln) {
                if (array_key_exists('default', $ln) && $ln['default']) {
                    $code = $ln['code'];
                    if (array_key_exists('direction', $ln)) {
                        $direction = $ln['direction'];
                    }
                }
            }
            session()->put('local', $code);
            Session::put('direction', $direction);
            $lang = $code;
        }
        return $lang;
    }

    public static function get_settings($object, $type)
    {
        $config = null;
        foreach ($object as $setting) {
            if ($setting['type'] == $type) {
                $config = $setting;
            }
        }
        return $config;
    }

    public static function getShippingMethods($seller_id, $type)
    {
        if ($type == 'admin') {
            return ShippingMethod::where(['status' => 1])->where(['creator_type' => 'admin'])->get();
        } else {
            return ShippingMethod::where(['status' => 1])->where(['creator_id' => $seller_id, 'creator_type' => $type])->get();
        }
    }


    public static function set_data_format($data)
    {
        // json_decode(null) and json_decode('bad') both return null, and whereIn() rejects null —
        // so ONE product with no colours took the whole listing endpoint down with a 500. A product
        // without colours has no colours, not no listing.
        $colors = is_array($data['colors']) ? $data['colors'] : (json_decode($data['colors'] ?? '') ?: []);
        $query_data = Color::whereIn('code', $colors)->pluck('name', 'code')->toArray();
        $color_process = [];
        foreach ($query_data as $key => $color) {
            $color_process[] = array(
                'name' => $color,
                'code' => $key,
            );
        }
        $colorsFormatted = [];
        foreach ($color_process as $color) {
            $colorImageName = null;
            if (isset($data['color_images_full_url']) && $data['color_images_full_url']) {
                foreach ($data['color_images_full_url'] as $image) {
                    if ($image['color'] && '#' . $image['color'] == $color['code']) {
                        $colorImageName = $image['image_name']['key'];
                    }
                }
            }
            $colorsFormatted[] = [
                'name' => $color['name'],
                'code' => $color['code'],
                'image' => $colorImageName,
            ];
        }

        $variation = [];
        $data['category_ids'] = is_array($data['category_ids']) ? $data['category_ids'] : json_decode($data['category_ids']);
//        $data['images'] = is_array($data['images']) ? $data['images'] : json_decode($data['images']);
        $data['colors'] = $colors;
//        $data['color_image'] = $colorImage;
        $data['colors_formatted'] = $colorsFormatted;
        $attributes = [];
        if ((is_array($data['attributes']) ? $data['attributes'] : json_decode($data['attributes'])) != null) {
            $attributes_arr = is_array($data['attributes']) ? $data['attributes'] : json_decode($data['attributes']);
            foreach ($attributes_arr as $attribute) {
                $attributes[] = (integer)$attribute;
            }
        }
        $data['attributes'] = $attributes;
        $data['choice_options'] = is_array($data['choice_options']) ? $data['choice_options'] : json_decode($data['choice_options']);
        // Same null trap as the colours above: a simple product stores no variations, and
        // foreach(null) is a throw, not an empty loop.
        $variation_arr = is_array($data['variation']) ? $data['variation'] : (json_decode($data['variation'] ?? '', true) ?: []);
        foreach ($variation_arr as $var) {
            $variation[] = [
                'type' => $var['type'],
                'price' => (double)$var['price'],
                'sku' => $var['sku'],
                'qty' => (integer)$var['qty'],
            ];
        }
        $data['variation'] = $variation;

        return $data;
    }


    public static function setDataFormatForJsonData($data): mixed
    {
        $colors = [0];
        if (isset($data['colors'])) {
            $colors = is_array($data['colors']) ? $data['colors'] : json_decode($data['colors']);
        }
        $queryData = Color::whereIn('code', $colors)->pluck('name', 'code')->toArray();
        $colorProcess = [];
        foreach ($queryData as $key => $color) {
            $colorProcess[] = [
                'name' => $color,
                'code' => $key,
            ];
        }

        $colorImage = isset($data['color_image']) ? (is_array($data['color_image']) ? $data['color_image'] : json_decode($data['color_image'])) : null;
        $colorsFormatted = [];
        foreach ($colorProcess as $color) {
            $colorImageName = null;
            if ($colorImage) {
                foreach ($colorImage as $image) {
                    if ($image->color && '#' . $image->color == $color['code']) {
                        $colorImageName = $image->image_name;
                    }
                }
            }
            $colorsFormatted[] = [
                'name' => $color['name'],
                'code' => $color['code'],
                'image' => $colorImageName,
            ];
        }

        $variation = [];
        $data['category_ids'] = isset($data['category_ids']) ? (is_array($data['category_ids']) ? $data['category_ids'] : json_decode($data['category_ids'])) : [];

        $data['images'] = isset($data['images']) ? (is_array($data['images']) ? $data['images'] : json_decode($data['images'])) : [];
        $data['colors'] = $colors;
        $data['color_image'] = $colorImage;
        $data['colors_formatted'] = $colorsFormatted;
        $attributes = [];
        if ((isset($data['attributes']) && is_array($data['attributes']) ? $data['attributes'] : json_decode($data['attributes'] ?? '')) != null) {
            $attributes_arr = is_array($data['attributes']) ? $data['attributes'] : json_decode($data['attributes']);
            foreach ($attributes_arr as $attribute) {
                $attributes[] = (integer)$attribute;
            }
        }
        $data['attributes'] = $attributes;
        $data['choice_options'] = isset($data['choice_options']) ? (is_array($data['choice_options']) ? $data['choice_options'] : json_decode($data['choice_options'])) : [];
        $variation_arr = isset($data['variation']) ? (is_array($data['variation']) ? $data['variation'] : json_decode($data['variation'], true)) : [];
        foreach ($variation_arr as $var) {
            $variation[] = [
                'type' => $var['type'],
                'price' => (double)$var['price'],
                'sku' => $var['sku'],
                'qty' => (integer)$var['qty'],
            ];
        }
        $data['variation'] = $variation;
        return $data;
    }

    public static function product_data_formatting($data, $multi_data = false)
    {
        if ($data) {
            $storage = [];
            if ($multi_data == true) {
                foreach ($data as $item) {
                    if ($item) {
                        $storage[] = Helpers::set_data_format($item);
                    }
                }
                $data = $storage;
            } else {
                $data = Helpers::set_data_format($data);
            }

            return $data;
        }
        return null;
    }

    public static function product_data_formatting_for_json_data($data, $multi_data = false)
    {
        if ($data) {
            $storage = [];
            if ($multi_data == true) {
                foreach ($data as $item) {
                    if ($item) {
                        $storage[] = Helpers::setDataFormatForJsonData($item);
                    }
                }
                $data = $storage;
            } else {
                $data = Helpers::setDataFormatForJsonData($data);
            }

            return $data;
        }
        return null;
    }

    public static function getDefaultPaymentGateways(): array
    {
        return [
            'ssl_commerz', 'paypal', 'stripe', 'razor_pay', 'paystack', 'senang_pay', 'paymob_accept',
            'flutterwave', 'paytm', 'paytabs', 'liqpay', 'mercadopago', 'bkash', 'paymera',
            'mtn_cash'
        ];
    }

    public static function getDefaultSMSGateways(): array
    {
        return [
            'twilio',
            'nexmo',
            '2factor',
            'msg91',
            'releans',
        ];
    }

    public static function combinations($arrays)
    {
        $result = [[]];
        foreach ($arrays as $property => $property_values) {
            $tmp = [];
            foreach ($result as $result_item) {
                foreach ($property_values as $property_value) {
                    $tmp[] = array_merge($result_item, [$property => $property_value]);
                }
            }
            $result = $tmp;
        }
        return $result;
    }

    public static function validationErrorProcessor($validator): array
    {
        $errorKeeper = [];
        foreach ($validator->errors()->getMessages() as $index => $error) {
            $errorKeeper[] = ['code' => $index, 'message' => $error[0]];
        }
        return $errorKeeper;
    }

    public static function currency_load(): void
    {
        if (session()->has('system_default_currency') && session('system_default_currency') == '') {
            session()->forget('system_default_currency');
        }
        $default = getWebConfig(name: 'system_default_currency');
        $current = \session('system_default_currency_info');
        if (session()->has('system_default_currency_info') == false || $default != $current['id']) {
            $id = getWebConfig(name: 'system_default_currency');
            $currency = Currency::find($id);
            if (!$currency) {
                $currency = Currency::find(1);
            }
            session()->put('system_default_currency_info', $currency);
            session()->put('currency_code', $currency->code);
            session()->put('currency_symbol', $currency->symbol);
            session()->put('currency_exchange_rate', $currency->exchange_rate);
            session()->forget('usd');
            session()->forget('default');
            $usd = exchangeRate(USD);
            session()->put('usd', $usd);
        }
    }

    public static function language_load()
    {
        if (\session()->has('language_settings')) {
            $language = \session('language_settings');
        } else {
            $language = BusinessSetting::where('type', 'language')->first();
            \session()->put('language_settings', $language);
        }
        return $language;
    }

    public static function tax_calculation($product, $price, $tax, $tax_type)
    {
        return ($price / 100) * $tax;
    }

    public static function getProductDiscount($product, $price): float
    {
        $discount = 0;
        if ($product['discount_type'] == 'percent') {
            $discount = ($price * $product['discount']) / 100;
        } elseif ($product['discount_type'] == 'flat') {
            $discount = $product['discount'];
        }

        return floatval($discount);
    }

    public static function module_permission_check($mod_name): bool
    {
        if (!auth('admin')->check() || !auth('admin')->user()) {
            return false;
        }

        $user_role = auth('admin')->user()?->role;
        $permission = $user_role?->module_access ?? '';
        if (isset($permission) && $user_role?->status == 1 && in_array($mod_name, (array)json_decode($permission)) == true) {
            return true;
        }

        if (auth('admin')->user()->admin_role_id == 1) {
            return true;
        }
        return false;
    }

    public static function convert_currency_to_usd($price)
    {
        $currencyModel = getWebConfig(name: 'currency_model');
        if ($currencyModel == 'multi_currency') {
            Helpers::currency_load();
            $code = session('currency_code') == null ? 'USD' : session('currency_code');
            if ($code == 'USD') {
                return $price;
            }
            $currency = Currency::where('code', $code)->first();
            $price = floatval($price) / floatval($currency->exchange_rate);

            $usdCurrency = Currency::where('code', 'USD')->first();
            $price = $usdCurrency->exchange_rate < 1 ? (floatval($price) * floatval($usdCurrency->exchange_rate)) : (floatval($price) / floatval($usdCurrency->exchange_rate));
        } else {
            $price = floatval($price);
        }
        return $price;
    }

    public static function text_variable_data_format($value, $key = null, $user_name = null, $shopName = null, $delivery_man_name = null, $time = null, $order_id = null)
    {
        $data = $value;
        if ($data) {
            $order = $order_id ? Order::find($order_id) : null;
            $data = $user_name ? str_replace("{userName}", $user_name, $data) : $data;
            $data = $shopName ? str_replace("{shopName}", $shopName, $data) : $data;
            $data = $delivery_man_name ? str_replace("{deliveryManName}", $delivery_man_name, $data) : $data;
            $data = $key == 'expected_delivery_date' ? ($order ? str_replace("{time}", $order->expected_delivery_date, $data) : $data) : ($time ? str_replace("{time}", $time, $data) : $data);
            $data = $order_id ? str_replace("{orderId}", $order_id, $data) : $data;
        }
        return $data;
    }

    public static function push_notificatoin_message($key, $user_type, $lang)
    {
        try {
            $notification_key = [
                'pending' => 'order_pending_message',
                'confirmed' => 'order_confirmation_message',
                'processing' => 'order_processing_message',
                'out_for_delivery' => 'out_for_delivery_message',
                'delivered' => 'order_delivered_message',
                'returned' => 'order_returned_message',
                'failed' => 'order_failed_message',
                'canceled' => 'order_canceled',
                'order_refunded_message' => 'order_refunded_message',
                'refund_request_canceled_message' => 'refund_request_canceled_message',
                'new_order_message' => 'new_order_message',
                'order_edit_message' => 'order_edit_message',
                'new_order_assigned_message' => 'new_order_assigned_message',
                'delivery_man_assign_by_admin_message' => 'delivery_man_assign_by_admin_message',
                'order_rescheduled_message' => 'order_rescheduled_message',
                'expected_delivery_date' => 'expected_delivery_date',
                'message_from_admin' => 'message_from_admin',
                'message_from_seller' => 'message_from_seller',
                'message_from_delivery_man' => 'message_from_delivery_man',
                'message_from_customer' => 'message_from_customer',
                'refund_request_status_changed_by_admin' => 'refund_request_status_changed_by_admin',
                'withdraw_request_status_message' => 'withdraw_request_status_message',
                'cash_collect_by_seller_message' => 'cash_collect_by_seller_message',
                'cash_collect_by_admin_message' => 'cash_collect_by_admin_message',
                'fund_added_by_admin_message' => 'fund_added_by_admin_message',
                'delivery_man_charge' => 'delivery_man_charge',
            ];
            $data = NotificationMessage::with(['translations' => function ($query) use ($lang) {
                $query->where('locale', $lang);
            }])->where(['key' => $notification_key[$key], 'user_type' => $user_type])->first() ?? ["status" => 0, "message" => "", "translations" => []];
            if ($data) {
                if ($data['status'] == 0) {
                    return 0;
                }
                return count($data->translations) > 0 ? $data->translations[0]->value : $data['message'];
            } else {
                return false;
            }
        } catch (Exception $exception) {
        }
    }


    /**
     * Device wise notification send
     */
    public static function send_push_notif_to_device($fcm_token, $data)
    {
        $key = BusinessSetting::where(['type' => 'push_notification_key'])->first()->value;
        $url = "https://fcm.googleapis.com/fcm/send";
        $header = array("authorization: key=" . $key . "",
            "content-type: application/json"
        );

        if (isset($data['order_id']) == false) {
            $data['order_id'] = null;
        }

        $postdata = '{
            "to" : "' . $fcm_token . '",
            "data" : {
                "title" :"' . $data['title'] . '",
                "body" : "' . $data['description'] . '",
                "image" : "' . $data['image'] . '",
                "order_id":"' . $data['order_id'] . '",
                "type":"' . $data['type'] . '",
                "is_read": 0
              },
              "notification" : {
                "title" :"' . $data['title'] . '",
                "body" : "' . $data['description'] . '",
                "image" : "' . $data['image'] . '",
                "order_id":"' . $data['order_id'] . '",
                "title_loc_key":"' . $data['order_id'] . '",
                "type":"' . $data['type'] . '",
                "is_read": 0,
                "icon" : "new",
                "sound" : "default"
              }
        }';

        $ch = curl_init();
        $timeout = 120;
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);

        // Get URL content
        $result = curl_exec($ch);
        // close handle to release resources
        curl_close($ch);

        return $result;
    }


    public static function get_seller_by_token($request)
    {
        $data = '';
        $success = 0;

        $token = explode(' ', $request->header('authorization'));
        if (count($token) > 1 && strlen($token[1]) > 30) {
            $seller = Seller::where(['auth_token' => $token['1']])->first();
            if (isset($seller)) {
                $data = $seller;
                $success = 1;
            }
        }

        return [
            'success' => $success,
            'data' => $data
        ];
    }


    public static function getSellerByToken($request)
    {
        $token = explode(' ', $request->header('authorization'));
        if (count($token) > 1 && strlen($token[1]) > 30) {
            return Seller::where(['auth_token' => $token['1']])->first();
        }
        return null;
    }

    public static function remove_dir($dir)
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (filetype($dir . "/" . $object) == "dir") Helpers::remove_dir($dir . "/" . $object); else unlink($dir . "/" . $object);
                }
            }
            reset($objects);
            rmdir($dir);
        }
    }

    public static function currency_code()
    {
        Helpers::currency_load();
        if (session()->has('currency_symbol')) {
            $symbol = session('currency_symbol');
            $code = Currency::where(['symbol' => $symbol])->first()->code;
        } else {
            $system_default_currency_info = session('system_default_currency_info');
            $code = $system_default_currency_info->code;
        }
        return $code;
    }

    public static function get_language_name($key)
    {
        $values = getWebConfig(name: 'language');
        foreach ($values as $value) {
            if ($value['code'] == $key) {
                $key = $value['name'];
            }
        }

        return $key;
    }

    public static function setEnvironmentValue($envKey, $envValue)
    {
        $envFile = app()->environmentFilePath();
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if (preg_match('/[^a-zA-Z0-9]/', $envValue)) {
            $formattedValue = "\"{$envValue}\"";
        } else {
            $formattedValue = $envValue;
        }

        $replacement = "{$envKey}={$formattedValue}";
        $keyFound = false;
        $newLines = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (preg_match("/^{$envKey}\s*=/i", trim($line))) {
                $newLines[] = $replacement;
                $keyFound = true;
            } else {
                $newLines[] = $line;
            }
        }

        if (!$keyFound) {
            $newLines[] = $replacement;
        }
        file_put_contents($envFile, implode(PHP_EOL, $newLines) . PHP_EOL);
        return $formattedValue;
    }

    public static function sales_commission_before_order($cart_group_id, $coupon_discount): int|string
    {
        $carts = CartManager::getCartListQuery(groupId: $cart_group_id);
        $cart_summery = OrderManager::getOrderSummaryBeforePlaceOrder($carts, $coupon_discount);
        return self::seller_sales_commission($carts[0]['seller_is'], $carts[0]['seller_id'], $cart_summery['order_total']);
    }

    public static function seller_sales_commission($seller_is, $seller_id, $order_total): int|string
    {
        $commissionAmount = 0;
        if ($seller_is == 'seller') {
            $seller = Seller::find($seller_id);
            if (isset($seller) && $seller['sales_commission_percentage'] !== null) {
                $commission = $seller['sales_commission_percentage'];
            } else {
                $commission = getWebConfig(name: 'sales_commission');
            }
            $commissionAmount = number_format(($order_total / 100) * $commission, 2);
        }
        return $commissionAmount;
    }

    public static function set_symbol($amount): string
    {
        $decimal_point_settings = getWebConfig(name: 'decimal_point_settings');
        $position = getWebConfig(name: 'currency_symbol_position');
        if (!is_null($position) && $position == 'left') {
            $string = currency_symbol() . '' . number_format($amount, (!empty($decimal_point_settings) ? $decimal_point_settings : 0));
        } else {
            $string = number_format($amount, !empty($decimal_point_settings) ? $decimal_point_settings : 0) . currency_symbol();
        }
        return $string;
    }

    public static function pagination_limit()
    {
        $pagination_limit = BusinessSetting::where('type', 'pagination_limit')->first();
        if ($pagination_limit != null) {
            return $pagination_limit->value;
        } else {
            return 25;
        }
    }

    public static function gen_mpdf($view, $file_prefix, $file_postfix)
    {
        $mpdf = new \Mpdf\Mpdf(['default_font' => 'FreeSerif', 'mode' => 'utf-8', 'format' => [190, 250]]);
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;

        $mpdf_view = $view;
        $mpdf_view = $mpdf_view->render();
        $mpdf->WriteHTML($mpdf_view);
        $mpdf->Output($file_prefix . $file_postfix . '.pdf', 'D');
    }

    public static function generate_referer_code(): string
    {
        $refCode = strtoupper(Str::random('20'));
        if (User::where('referral_code', '=', $refCode)->exists()) {
            return Helpers::generate_referer_code();
        }
        return $refCode;
    }

    public static function add_fund_to_wallet_bonus($amount)
    {
        $bonuses = AddFundBonusCategories::where('is_active', 1)
            ->whereDate('start_date_time', '<=', now())
            ->whereDate('end_date_time', '>=', now())
            ->where('min_add_money_amount', '<=', $amount)
            ->get();

        $bonuses = $bonuses->where('min_add_money_amount', $bonuses->max('min_add_money_amount'));

        foreach ($bonuses as $key => $item) {
            $item->applied_bonus_amount = $item->bonus_type == 'percentage' ? ($amount * $item->bonus_amount) / 100 : $item->bonus_amount;
            if ($item->bonus_type == 'percentage' && $item->applied_bonus_amount > $item->max_bonus_amount) {
                $item->applied_bonus_amount = $item->max_bonus_amount;
            }
        }

        return $bonuses->max('applied_bonus_amount') ?? 0;
    }

    public static function inHouseBannerUpdateFromConfig($key): void
    {
        try {
            $sourcePath = 'shop/' . $key;
            $destinationPath = 'shop/banner/' . $key;

            if (Storage::disk('public')->exists($sourcePath)) {
                Storage::disk('public')->copy($sourcePath, $destinationPath);
            }
        } catch (Exception $e) {
        }
    }

    public static function createDefaultShop()
    {
        $shop = Shop::where('author_type', 'admin')->first();
        if (!$shop) {

            $shopBanner = getWebConfig(name: 'shop_banner');
            $offerBanner = getWebConfig(name: 'offer_banner');
            $bottomBanner = getWebConfig(name: 'bottom_banner');
            $companyFavIcon = getWebConfig(name: 'company_fav_icon');


            $vacationConfig = getWebConfig(name: 'vacation_add');
            $temporaryClose = getWebConfig(name: 'temporary_close');

            if ($companyFavIcon && isset($companyFavIcon['key'])) {
                $sourcePath = 'company/' . $companyFavIcon['key'];
                $destinationPath = 'shop/' . $companyFavIcon['key'];

                if (Storage::disk('public')->exists($sourcePath)) {
                    Storage::disk('public')->copy($sourcePath, $destinationPath);
                }
            }

            if ($shopBanner && isset($shopBanner['key'])) {
                self::inHouseBannerUpdateFromConfig(key: $shopBanner['key']);
            }

            if ($offerBanner && isset($offerBanner['key'])) {
                self::inHouseBannerUpdateFromConfig(key: $offerBanner['key']);
            }

            if ($bottomBanner && isset($bottomBanner['key'])) {
                self::inHouseBannerUpdateFromConfig(key: $bottomBanner['key']);
            }

            $data = [
                'seller_id' => 0,
                'author_type' => 'admin',
                'name' => getWebConfig('company_name'),
                'slug' => Str::slug(getWebConfig('company_name')) . '-' . rand(1000, 9999),
                'contact' => getWebConfig('company_phone'),
                'address' => getWebConfig('shop_address'),
                'image' => $companyFavIcon['key'] ?? '',
                'image_storage_type' => $companyFavIcon['storage'] ?? 'public',
                'banner' => $shopBanner['key'] ?? '',
                'banner_storage_type' => $shopBanner['storage'] ?? 'public',
                'offer_banner' => $offerBanner['key'] ?? '',
                'offer_banner_storage_type' => $offerBanner['storage'] ?? 'public',
                'bottom_banner' => $bottomBanner['key'] ?? '',
                'bottom_banner_storage_type' => $bottomBanner['storage'] ?? 'public',
                'vacation_duration_type' => $vacationConfig['vacation_duration_type'] ?? 'until_change',
                'vacation_start_date' => $vacationConfig['vacation_start_date'],
                'vacation_end_date' => $vacationConfig['vacation_end_date'],
                'vacation_note' => $vacationConfig['vacation_note'] ?? '',
                'vacation_status' => $vacationConfig['status'] ?? 0,
                'temporary_close' => $temporaryClose['status'] ?? 0,
            ];
            return \App\Models\Shop::create($data);
        }
        return $shop;
    }
}


if (!function_exists('currency_symbol')) {
    function currency_symbol()
    {
        Helpers::currency_load();
        if (\session()->has('currency_symbol')) {
            $symbol = \session('currency_symbol');
        } else {
            $system_default_currency_info = \session('system_default_currency_info');
            $symbol = $system_default_currency_info->symbol;
        }
        return $symbol;
    }
}

if (!function_exists('customer_info')) {
    function customer_info()
    {
        return User::where('id', auth('customer')->id())->first();
    }
}

if (!function_exists('order_status_history')) {
    function order_status_history($order, $status)
    {
        return $order?->orderStatusHistory?->sortByDesc('created_at')?->firstWhere('status', $status)?->created_at;
    }
}

if (!function_exists('get_shop_name')) {
    function get_shop_name($seller_id)
    {
        $shop = Shop::where(['seller_id' => $seller_id])->first();
        return $shop ? $shop->name : null;
    }
}

if (!function_exists('payment_gateways')) {
    function payment_gateways()
    {
        $paymentGatewayPublishedStatus = config('get_payment_publish_status') ?? 0;

        $paymentGatewaysQuery = Setting::whereIn('settings_type', ['payment_config'])->where('is_active', 1);
        if ($paymentGatewayPublishedStatus == 1) {
            $paymentGatewaysList = $paymentGatewaysQuery->get();
        } else {
            $paymentGatewaysList = $paymentGatewaysQuery->whereIn('key_name', Helpers::getDefaultPaymentGateways())->get();
        }

        return $paymentGatewaysList;
    }
}