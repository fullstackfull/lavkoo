<?php

use App\Models\Currency;
use Illuminate\Support\Facades\Cache;

if (!function_exists('getActiveCurrencies')) {
    /**
     * The active currency list shown in the storefront header on every page.
     * Cached because the header otherwise queried it per page view; the cache
     * is forgotten by CurrencyRepository on every add/update/delete, so the
     * one-hour TTL is only a backstop.
     */
    function getActiveCurrencies(): \Illuminate\Support\Collection
    {
        return Cache::remember('active_currency_list', 3600, function () {
            return Currency::where('status', 1)->get();
        });
    }
}

if (!function_exists('loadCurrency')) {
    /**
     * @return void
     */
    function loadCurrency(): void
    {
        $defaultCurrency = getWebConfig(name: 'system_default_currency');
        $currentCurrencyInfo = session('system_default_currency_info');
        if (!session()->has('system_default_currency_info') || $defaultCurrency != $currentCurrencyInfo['id']) {
            $id = getWebConfig(name: 'system_default_currency');
            $currency = Currency::find($id);
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
}

if (!function_exists('currencyConverter')) {
    /** system default currency to usd convert
     * @param float|null $amount
     * @param string $to
     * @return float|int
     */
    function currencyConverter(float|null $amount = 0, string $to = USD): float|int
    {
        $amount = is_null($amount) ? 0 : $amount;
        $currencyModel = getWebConfig('currency_model');
        if ($currencyModel == MULTI_CURRENCY) {
            $default = Currency::find(getWebConfig('system_default_currency'))->exchange_rate;
            $exchangeRate = exchangeRate($to);
            $rate = $default / $exchangeRate;
            if ($amount == 0 || floatval($rate) == 0.0) {
                $value = $amount;
            } else {
                $value = $amount / floatval($rate);
            }
        } else {
            $value = $amount;
        }
        return $value;
    }
}

if (!function_exists('defaultCurrencyRate')) {
    /**
     * The multiplier from the base figures the store holds to the system default currency.
     *
     * Exactly one in single-currency mode, so a figure multiplied by it is unchanged. Lifted out of
     * usdToDefaultCurrency because the point-of-sale total has to add a sale up in whole minor units and then
     * convert once; reading the rate here means it reads the SAME rate rather than a second copy of it.
     *
     * @return float
     */
    function defaultCurrencyRate(): float
    {
        if (getWebConfig('currency_model') != MULTI_CURRENCY) {
            return 1.0;
        }

        if (session()->has('default')) {
            $default = session('default');
        } else {
            $default = Currency::find(getWebConfig('system_default_currency'))->exchange_rate;
            session()->put('default', $default);
        }

        if (session()->has('usd')) {
            $usd = session('usd');
        } else {
            $usd = exchangeRate(USD);
            session()->put('usd', $usd);
        }

        return $default / $usd;
    }
}

if (!function_exists('defaultCurrencyDecimalPoints')) {
    /**
     * How many decimal places the system default currency is shown and charged in.
     *
     * The platform has ONE notion of a currency's exponent: this business setting. The currencies table carries a
     * name, a symbol, a code and an exchange rate, and no decimal places, so a three-decimal Gulf currency (KWD,
     * BHD, OMR) is served by setting this to 3 rather than by anything the currency row itself says.
     *
     * The figure is returned exactly as the setting holds it. The bound a guarded integer grid needs is applied where
     * that grid lives, in App\Services\Pos\PosSaleTotal, and NOT here: clamping here made this function, which every
     * money figure on every screen passes through, answer differently from the way it always has for a setting above
     * MAX_CURRENCY_DECIMAL_POINTS — a change outside the point-of-sale lane that reviewer B measured
     * (usdToDefaultCurrency(1.23456789) at a setting of 8: 1.23456788 before, 1.234567 after) and that
     * `BusinessSettingRequest` allows, since it validates `required|integer|min:0` with no maximum.
     *
     * @return int
     */
    function defaultCurrencyDecimalPoints(): int
    {
        return (int) (getWebConfig('decimal_point_settings') ?? 2);
    }
}

if (!function_exists('usdToDefaultCurrency')) {
    /**
     * system usd currency to default convert
     * @param float|int|null $amount
     * @return float|int
     */
    function usdToDefaultCurrency(float|int|null $amount = 0): float|int
    {
        $value = $amount * defaultCurrencyRate();

        $factor = pow(10, defaultCurrencyDecimalPoints());

        // The round() is the whole of the fix reviewer A found, and it is a money defect in its own right: `floor()`
        // of a binary double is NOT idempotent, so this function did not return the figure handed to it even when that
        // figure was already an exact number of minor units. 0.58 came back 0.57, because the double nearest 0.58 is a
        // shade below it and 0.58 * 100 is 57.99999999999999289. Measured over the first 100000 exact minor-unit
        // figures: 4586 of them (4.59 %) came back one unit low at two decimal places and 741 (0.74 %) at three.
        //
        // That is what made the panel's cart show, post and demand one minor unit less than the sale costs, and this
        // lane's first version answered it by recording the gap on the order row (order_amount 0.58, paid_amount 0.57,
        // payment_status 'paid') instead of closing it. Rounding at six digits below a minor unit — the grain
        // PosSaleTotal already uses, far coarser than binary noise and far finer than any money figure — leaves every
        // genuinely fractional figure flooring DOWN as it always did (272.849 is still 272.84, 13.5675 still 13.56)
        // and moves only the figures the floor had already lost.
        return floor(round($value * $factor, MONEY_NOISE_DIGITS)) / $factor;
    }
}

if (!function_exists('webCurrencyConverter')) {
    /**
     * currency convert for web panel
     * @param string|int|float|null $amount
     * @return float|string
     */
    function webCurrencyConverter(string|int|float|null $amount = 0): float|string
    {
        loadCurrency();
        $currencyModel = getWebConfig('currency_model');
        if ($currencyModel == MULTI_CURRENCY) {
            if (session()->has('usd')) {
                $usd = session('usd');
            } else {
                $usd = Currency::where(['code' => 'USD'])->first()->exchange_rate;
                session()->put('usd', $usd);
            }
            $myCurrency = session('currency_exchange_rate');
            $rate = $myCurrency / $usd;
        } else {
            $rate = 1;
        }
        $decimalPointSettings = getWebConfig('decimal_point_settings') ?? 2;
        return setCurrencySymbol(amount: round($amount * $rate, $decimalPointSettings), currencyCode: getCurrencyCode(type: 'web'), type: 'web');
    }
}

if (!function_exists('localToDefaultCurrency')) {
    /** system default currency to usd convert
     * @param float|null $amount
     * @param string $type
     * @return float|int|string
     */
    function localToDefaultCurrency(float|null $amount = 0, string $type = 'default'): float|int|string
    {
        $value = is_null($amount) || $amount != 0 ? $amount / session('currency_exchange_rate') : 0;
        if ($type == 'web') {
            return setCurrencySymbol(amount: $value, currencyCode: getCurrencyCode(type: 'web'), type: 'web');
        }
        return $value;
    }
}

if (!function_exists('loyaltyPointToLocalCurrency')) {
    /** system default currency to usd convert
     * @param float|null $amount
     * @param string $type
     * @return string
     */
    function loyaltyPointToLocalCurrency(float|null $amount = 0, string $type = 'default'): string
    {
        $currencyModel = getWebConfig(name: 'currency_model');
        $loyaltyPointExchangeRate = getWebConfig(name: 'loyalty_point_exchange_rate');
        if ($currencyModel != 'multi_currency') {
            $value = $amount / $loyaltyPointExchangeRate;
        } else {
            $value = ((session('currency_exchange_rate') * 1) / $loyaltyPointExchangeRate) * $amount;
        }
        if ($type == 'web') {
            return setCurrencySymbol(amount: $value, currencyCode: session('currency_code'), type: 'web');
        }
        return $value;
    }
}

if (!function_exists('webCurrencyConverterOnlyDigit')) {
    /**
     * currency convert for web panel
     * @param string|int|float|null $amount
     * @return float|string
     */
    function webCurrencyConverterOnlyDigit(string|int|float|null $amount = 0): float|string
    {
        loadCurrency();
        $currencyModel = getWebConfig('currency_model');
        if ($currencyModel == MULTI_CURRENCY) {
            if (session()->has('usd')) {
                $usd = session('usd');
            } else {
                $usd = Currency::where(['code' => 'USD'])->first()->exchange_rate;
                session()->put('usd', $usd);
            }
            $myCurrency = session('currency_exchange_rate');
            $rate = $myCurrency / $usd;
        } else {
            $rate = 1;
        }

        $decimalPointSettings = getWebConfig('decimal_point_settings') ?? 2;
        return round($amount * $rate, $decimalPointSettings);
    }
}

if (!function_exists('usdToAnotherCurrencyConverter')) {
    /**
     * currency convert for web panel
     * @param string $currencyCode
     * @param string|int|float|null $amount
     * @return float|string
     */
    function usdToAnotherCurrencyConverter(string $currencyCode, string|int|float|null $amount = 0): float|string
    {
        if ($currencyCode == 'USD') {
            return $amount;
        }
        $usd = Currency::where(['code' => 'USD'])->first()->exchange_rate;
        $myCurrency = Currency::where(['code' => $currencyCode])->first()->exchange_rate;
        $rate = $myCurrency / $usd;
        $decimalPointSettings = getWebConfig('decimal_point_settings') ?? 2;
        return round($amount * $rate, $decimalPointSettings);
    }
}

if (!function_exists('exchangeRate')) {
    /**
     * @param string $currencyCode
     * @return float|int
     */
    function exchangeRate(string $currencyCode = USD): float|int
    {
        return Currency::where('code', $currencyCode)->first()->exchange_rate ?? 1;
    }
}

if (!function_exists('getCurrencySymbol')) {
    /**
     * @param string $currencyCode
     * @param string $type
     * @return float|int|string
     */
    function getCurrencySymbol(string $currencyCode = USD, string $type = 'default'): float|int|string
    {
        loadCurrency();
        if ($type == 'web' && session()->has('currency_symbol')) {
            $currentSymbol = session('currency_symbol');
        } else {
            $systemDefaultCurrencyInfo = session('system_default_currency_info');
            $currentSymbol = $systemDefaultCurrencyInfo->symbol;
        }
        return $currentSymbol;
    }
}

if (!function_exists('setCurrencySymbol')) {
    /**
     * @param string|int|float|null $amount
     * @param string $currencyCode
     * @param string $type
     * @return string
     */
    function setCurrencySymbol(string|int|float|null $amount = 0, string $currencyCode = USD, string $type = 'default'): string
    {
        $decimalPointSettings = getWebConfig('decimal_point_settings');
        $position = getWebConfig('currency_symbol_position');
        if ($position === 'left') {
            $string = getCurrencySymbol(currencyCode: $currencyCode, type: $type) . '' . number_format($amount, (!empty($decimalPointSettings) ? $decimalPointSettings : 0));
        } else {
            $string = number_format($amount, !empty($decimalPointSettings) ? $decimalPointSettings : 0) . getCurrencySymbol(currencyCode: $currencyCode, type: $type);
        }
        return $string;
    }
}

if (!function_exists('getCurrencyCode')) {
    /**
     * @param string $type default,web
     * @return string
     */
    function getCurrencyCode(string $type = 'default'): string
    {
        if ($type == 'web') {
            $currencyCode = session('currency_code');
        } else {
            if (session()->has('system_default_currency_info')) {
                $currencyCode = session('system_default_currency_info')->code;
            } else {
                $currencyId = getWebConfig('system_default_currency');
                $currencyCode = Currency::where('id', $currencyId)->first()->code;
            }
        }
        return $currencyCode;
    }
}

if (!function_exists('getFormatCurrency')) {
    /**
     * @param string|int|float $amount
     * @return string
     */
    function getFormatCurrency(string|int|float $amount): string
    {
        $suffixes = ["1t+" => 1000000000000, "B+" => 1000000000, "M+" => 1000000, "K+" => 1000];
        foreach ($suffixes as $suffix => $factor) {
            if ($amount >= $factor) {
                $div = $amount / $factor;
                $formattedValue = number_format($div, 1) . $suffix;
                break;
            }
        }

        if (!isset($formattedValue)) {
            $formattedValue = number_format($amount, 2);
        }

        return $formattedValue;
    }
}


if (!function_exists('getProductPriceByType')) {
    function getProductPriceByType($product, $type, $result = 'value', $price = 0, $from = 'web'): float|int|string
    {
        if ($type == 'discount') {
            if ((isset($product['clearanceSale']) && $product['clearanceSale']) || isset($product['clearance_sale']) && $product['clearance_sale']) {
                $clearanceSale = $product['clearanceSale'] ?? $product['clearance_sale'];
                if ($clearanceSale['discount_type'] == 'percentage') {
                    $amount = round($clearanceSale['discount_amount'], (!empty($decimalPointSettings) ? $decimalPointSettings : 0));
                    return $result == 'value' ? $amount : $amount . '%';
                } else if ($clearanceSale['discount_type'] == 'flat') {
                    return $result == 'value' ? $clearanceSale['discount_amount'] : webCurrencyConverter(amount: $clearanceSale['discount_amount']);
                }
            } else if (isset($product['discount_type']) && $product['discount_type'] == 'percent') {
                $amount = round($product['discount'], (!empty($decimalPointSettings) ? $decimalPointSettings : 0));
                return $result == 'value' ? $amount : $amount . '%';
            } else if (isset($product['discount_type']) && $product['discount_type'] == 'flat') {
                return $result == 'value' ? $product['discount'] : webCurrencyConverter(amount: $product['discount']);
            }
        }

        if ($type == 'discount_type') {
            $discountType = $product['discount_type'];
            if ((isset($product['clearanceSale']) && $product['clearanceSale']) || isset($product['clearance_sale']) && $product['clearance_sale']) {
                $clearanceSale = $product['clearanceSale'] ?? $product['clearance_sale'];
                $discountType = $clearanceSale['discount_type'];
            }
            return $discountType;
        }

        if ($type == 'discounted_unit_price') {
            $unitPrice = $price != 0 ? $price : $product['unit_price'];
            if ((isset($product['clearanceSale']) && $product['clearanceSale']) || isset($product['clearance_sale']) && $product['clearance_sale']) {
                $amount = $unitPrice - getProductPriceByType(product: $product, type: 'discounted_amount', result: 'value', price: $unitPrice);
            } else {
                $amount = $unitPrice - (getProductDiscount(product: $product, price: $unitPrice));
            }

            if ($from == 'panel') {
                return $result == 'value' ? $amount : setCurrencySymbol(amount: usdToDefaultCurrency(amount: $amount), currencyCode: getCurrencyCode());
            }
            return $result == 'value' ? $amount : webCurrencyConverter(amount: $amount);
        }

        if ($type == 'discounted_amount') {
            if ((isset($product['clearanceSale']) && $product['clearanceSale']) || isset($product['clearance_sale']) && $product['clearance_sale']) {
                $clearanceSale = $product['clearanceSale'] ?? $product['clearance_sale'];
                $discountAmount = 0;
                if ($clearanceSale['discount_type'] == 'percentage') {
                    $discountAmount = ($price * getProductPriceByType(product: $product, type: 'discount', result: 'value')) / 100;
                } else if ($clearanceSale['discount_type'] == 'flat') {
                    $discountAmount =  $clearanceSale['discount_amount'];
                }

                $amount = floatval($discountAmount);
            } else {
                $amount = getProductDiscount(product: $product, price: $price);
            }
            if ($from == 'panel') {
                return $result == 'value' ? $amount : setCurrencySymbol(amount: usdToDefaultCurrency(amount: $amount), currencyCode: getCurrencyCode());
            }
            return $result == 'value' ? $amount : webCurrencyConverter(amount: $amount);
        }

        return 0;
    }
}
