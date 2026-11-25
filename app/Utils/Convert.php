<?php

namespace App\Utils;

use App\Models\Currency;
use App\Services\Payments\WhatAPaymentIsWorth;

class Convert
{
    public static function usd($amount): float|int|null
    {
        $currency_model = getWebConfig(name: 'currency_model');
        if ($currency_model == 'multi_currency') {
            $default = Currency::find(getWebConfig(name: 'system_default_currency'));
            $usd = Currency::where('code', 'USD')->first()->exchange_rate ?? 1;
            $rate = $default['exchange_rate'] / $usd;
            $value = floatval($amount) / floatval($rate);
        } else {
            $value = floatval($amount);
        }

        return $value;
    }

    /**
     * A payment a gateway took, in the unit this platform stores money in.
     *
     * The arithmetic lives in App\Services\Payments\WhatAPaymentIsWorth, which holds BOTH readings of it: the one
     * this function has always used -- which for a non-USD default currency answers in that default currency and is
     * therefore `default_rate` times too large, so a 100 riyal wallet top-up stores 100 and reads back as 375 -- and
     * the correction, the exact inverse of the conversion that charged the gateway. The setting ships as today's
     * reading, so nothing here moves until the owner says so: docs/gcc/OPEN-FINANCIAL-DECISIONS.md section 4.
     */
    public static function usdPaymentModule($amount, $currency): float|int|null
    {
        return WhatAPaymentIsWorth::storedUnitsFor(amount: $amount, currency: $currency);
    }

    public static function default($amount): float|int
    {
        $currency_model = getWebConfig(name: 'currency_model');
        if ($currency_model == 'multi_currency') {
            $default = Currency::find(getWebConfig(name: 'system_default_currency'));
            $usd = Currency::where('code', 'USD')->first()->exchange_rate ?? 1;
            $rate = $default['exchange_rate'] / $usd;
            $value = floatval($amount) * floatval($rate);
        } else {
            $value = floatval($amount);
        }
        return round($value, 2);
    }

    public static function bdtTousd($amount): float|int
    {
        $currency_model = getWebConfig(name: 'currency_model');
        if ($currency_model == 'multi_currency') {
            $bdt = Currency::where(['code' => 'BDT'])->first()->exchange_rate ?? 1;
            $usd = Currency::where('code', 'USD')->first()->exchange_rate ?? 1;
            $rate = $bdt / $usd;
            $value = floatval($amount) / floatval($rate);
        } else {
            $value = floatval($amount);
        }

        return $value;
    }

    public static function usdTobdt($amount)
    {
        $currency_model = getWebConfig(name: 'currency_model');
        if ($currency_model == 'multi_currency') {
            $bdt = Currency::where(['code' => 'BDT'])->first()->exchange_rate ?? 1;
            $usd = Currency::where('code', 'USD')->first()->exchange_rate ?? 1;
            $rate = $usd / $bdt;
            $value = floatval($amount) / floatval($rate);
        } else {
            $value = floatval($amount);
        }

        return $value;
    }

    public static function usdTomyr($amount)
    {
        $currency_model = getWebConfig(name: 'currency_model');
        if ($currency_model == 'multi_currency') {
            $myr = Currency::where(['code' => 'MYR'])->first()->exchange_rate ?? 1;
            $usd = Currency::where('code', 'USD')->first()->exchange_rate ?? 1;
            $rate = $usd / $myr;
            $value = floatval($amount) / floatval($rate);
        } else {
            $value = floatval($amount);
        }

        return $value;
    }

    public static function usdTozar($amount): float|int
    {
        $currency_model = getWebConfig(name: 'currency_model');
        if ($currency_model == 'multi_currency') {
            $zar = Currency::where(['code' => 'ZAR'])->first()->exchange_rate ?? 1;
            $usd = Currency::where('code', 'USD')->first()->exchange_rate ?? 1;
            $rate = $usd / $zar;
            $value = floatval($amount) / floatval($rate);
        } else {
            $value = floatval($amount);
        }

        return $value;
    }

    public static function usdToinr($amount): float|int
    {
        $currency_model = getWebConfig(name: 'currency_model');
        if ($currency_model == 'multi_currency') {
            $inr = Currency::where(['code' => 'INR'])->first()->exchange_rate ?? 1;
            $usd = Currency::where('code', 'USD')->first()->exchange_rate ?? 1;
            $rate = $usd / $inr;
            $value = floatval($amount) / floatval($rate);
        } else {
            $value = floatval($amount);
        }

        return $value;
    }

    public static function usdToegp($amount): float|int
    {
        $currency_model = getWebConfig(name: 'currency_model');
        if ($currency_model == 'multi_currency') {
            $egp = Currency::where(['code' => 'EGP'])->first()->exchange_rate ?? 1;
            $usd = Currency::where('code', 'USD')->first()->exchange_rate ?? 1;
            $rate = $usd / $egp;
            $value = floatval($amount) / floatval($rate);
        } else {
            $value = floatval($amount);
        }

        return $value;
    }
}
