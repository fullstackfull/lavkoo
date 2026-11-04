<?php

namespace App\Utils;

use App\Models\BusinessSetting;
use App\Models\Currency;

class BackEndHelper
{
    public static function currency_to_usd($amount): float|int
    {
        $currency_model = getWebConfig(name: 'currency_model');
        if ($currency_model == 'multi_currency') {
            $default = Currency::find(BusinessSetting::where(['type' => 'system_default_currency'])->first()->value);
            $usd = Currency::where('code', 'USD')->first()->exchange_rate;
            $rate = $default['exchange_rate'] / $usd;
            $value = floatval($amount) / floatval($rate);
        } else {
            $value = floatval($amount);
        }

        return $value;
    }

    public static function usd_to_currency($amount = 0): float
    {
        $currency_model = getWebConfig(name: 'currency_model');
        if ($currency_model == 'multi_currency') {

            if (session()->has('default')) {
                $default = session('default');
            } else {
                $default = Currency::find(getWebConfig(name: 'system_default_currency'))->exchange_rate;
                session()->put('default', $default);
            }

            if (session()->has('usd')) {
                $usd = session('usd');
            } else {
                $usd = Currency::where('code', 'USD')->first()->exchange_rate;
                session()->put('usd', $usd);
            }

            $rate = $default / $usd;
            $value = floatval($amount) * floatval($rate);
        } else {
            $value = floatval($amount);
        }

        return round($value, 2);
    }

    public static function set_symbol($amount = 0): string
    {
        $decimal_point_settings = getWebConfig(name: 'decimal_point_settings');
        $position = getWebConfig(name: 'currency_symbol_position') ?? 'left';
        if ($position == 'left') {
            return currency_symbol() . '' . number_format($amount, (!empty($decimal_point_settings) ? $decimal_point_settings: 0));
        }
        return number_format($amount, !empty($decimal_point_settings) ? $decimal_point_settings: 0) . currency_symbol();
    }

    public static function currency_code()
    {
        $currency = Currency::where('id', getWebConfig(name: 'system_default_currency'))->first();
        return $currency->code;
    }

    public static function order_status($status): string
    {
        return match ($status) {
            "pending" => "Pending",
            "confirmed" => "Confirmed",
            "processing" => "Packaging",
            "out_for_delivery" => "Out for Delivery",
            "delivered" => "Delivered",
            "returned" => "Returned",
            "failed" => "Failed to Deliver",
            "canceled" => "Canceled",
            default => '',
        };
    }
}
