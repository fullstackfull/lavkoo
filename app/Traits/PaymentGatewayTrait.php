<?php

namespace App\Traits;

use App\Models\Currency;

trait PaymentGatewayTrait
{
    public function getPaymentGatewaySupportedCurrencies($key = null): array
    {
        $paymentGateway = [
            "amazon_pay" => [
                "USD" => "United States Dollar",
                "GBP" => "Pound Sterling",
                "EUR" => "Euro",
                "JPY" => "Japanese Yen",
                "AUD" => "Australian Dollar",
                "NZD" => "New Zealand Dollar",
                "CAD" => "Canadian Dollar"
            ],
            "bkash" => [
                "BDT" => "Bangladeshi Taka"
            ],
            "paymera" => [
                "SYP" => "Syrian Pound",
                "USD" => "United States Dollar"
            ],
            "mtn_cash" => [
    "SYP" => "Syrian Pound",
],
            "cashfree" => [
                "INR" => "Indian Rupee"
            ],
            "ccavenue" => [
                "INR" => "Indian Rupee"
            ],
            "esewa" => [
                "NPR" => "Nepalese Rupee"
            ],
            "fatoorah" => [
                "KWD" => "Kuwaiti Dinar",
                "SAR" => "Saudi Riyal"
            ],
            "flutterwave" => [
                "NGN" => "Nigerian Naira",
                "GHS" => "Ghanaian Cedi",
                "KES" => "Kenyan Shilling",
                "ZAR" => "South African Rand",
                "USD" => "United States Dollar",
                "EUR" => "Euro",
                "GBP" => "British Pound Sterling",
                "CAD" => "Canadian Dollar",
                "XAF" => "Central African CFA Franc",
                "CLP" => "Chilean Peso",
                "COP" => "Colombian Peso",
                "EGP" => "Egyptian Pound",
                "GNF" => "Guinean Franc",
                "MWK" => "Malawian Kwacha",
                "MAD" => "Moroccan Dirham",
                "RWF" => "Rwandan Franc",
                "SLL" => "Sierra Leonean Leone",
                "STD" => "São Tomé and Príncipe Dobra",
                "TZS" => "Tanzanian Shilling",
                "UGX" => "Ugandan Shilling",
                "XOF" => "West African CFA Franc BCEAO",
                "ZMW" => "Zambian Kwacha"
            ],
            "foloosi" => [
                "AED" => "United Arab Emirates Dirham"
            ],
            "hubtel" => [
                "GHS" => "Ghanaian Cedi"
            ],
            "hyper_pay" => [
                "AED" => "United Arab Emirates Dirham",
                "SAR" => "Saudi Riyal",
                "EGP" => "Egyptian Pound",
                "BHD" => "Bahraini Dinar",
                "KWD" => "Kuwaiti Dinar",
                "OMR" => "Omani Rial",
                "QAR" => "Qatari Riyal",
                "USD" => "United States Dollar"
            ],
            "instamojo" => [
                "INR" => "Indian Rupee"
            ],
            "iyzi_pay" => [
                "TRY" => "Turkish Lira"
            ],
            "liqpay" => [
                "UAH" => "Ukrainian Hryvnia",
                "USD" => "United States Dollar",
                "EUR" => "Euro"
            ],
            "maxicash" => [
                "PHP" => "Philippine Peso"
            ],
            "mercadopago" => [
                "ARS" => "Argentine Peso",
                "BRL" => "Brazilian Real",
                "CLP" => "Chilean Peso",
                "COP" => "Colombian Peso",
                "MXN" => "Mexican Peso",
                "PEN" => "Peruvian Sol",
                "UYU" => "Uruguayan Peso",
                "USD" => "United States Dollar"
            ],
            "momo" => [
                "VND" => "Vietnamese Dong"
            ],
            "moncash" => [
                "HTG" => "Haitian Gourde"
            ],
            "payfast" => [
                "ZAR" => "South African Rand"
            ],
            "paymob_accept" => [
                "EGP" => "Egyptian Pound",
                "USD" => "US Dollar",
                "EUR" => "Euro",
                "GBP" => "British Pound",
                "SAR" => "Saudi Riyal",
                "AED" => "UAE Dirham",
            ],
            "paypal" => [
                "USD" => "United States Dollar",
                "AUD" => "Australian Dollar",
                "BRL" => "Brazilian Real",
                "CAD" => "Canadian Dollar",
                "CNY" => "Chinese Renminbi",
                "CZK" => "Czech Koruna",
                "DKK" => "Danish Krone",
                "EUR" => "Euro",
                "HKD" => "Hong Kong Dollar",
                "HUF" => "Hungarian Forint",
                "ILS" => "Israeli New Shekel",
                "JPY" => "Japanese Yen",
                "MYR" => "Malaysian Ringgit",
                "MXN" => "Mexican Peso",
                "TWD" => "New Taiwan Dollar",
                "NZD" => "New Zealand Dollar",
                "NOK" => "Norwegian Krone",
                "PHP" => "Philippine Peso",
                "PLN" => "Polish Zloty",
                "GBP" => "Pound Sterling",
                "SGD" => "Singapore Dollar",
                "SEK" => "Swedish Krona",
                "CHF" => "Swiss Franc",
                "THB" => "Thai Baht",
            ],
            "paystack" => [
                "NGN" => "Nigerian Naira",
                "GHS" => "Ghanaian Cedi",
                "ZAR" => "South African Rand",
                "KES" => "Kenyan Shilling",
                "XOF" => "West African CFA franc",
                "EGP" => "Egyptian Pound"
            ],
            "paytabs" => [
                "AED" => "United Arab Emirates Dirham",
                "SAR" => "Saudi Riyal",
                "BHD" => "Bahraini Dinar",
                "KWD" => "Kuwaiti Dinar",
                "OMR" => "Omani Rial",
                "QAR" => "Qatari Riyal",
                "EGP" => "Egyptian Pound",
                "USD" => "United States Dollar",
                "EUR" => "Euro",
                "GBP" => "British Pound",
                "JPY" => "Japanese Yen",
                "CAD" => "Canadian Dollar",
                "AUD" => "Australian Dollar",
                "INR" => "Indian Rupee",
                "CNY" => "Chinese Yuan",
                "MXN" => "Mexican Peso",
                "RUB" => "Russian Ruble",
                "ZAR" => "South African Rand",
                "SGD" => "Singapore Dollar",
                "BRL" => "Brazilian Real",
                "JOD" => "Jordanian Dinar"
            ],
            "paytm" => [
                "INR" => "Indian Rupee"
            ],
            "phonepe" => [
                "INR" => "Indian Rupee"
            ],
            "pvit" => [
                "NGN" => "Nigerian Naira"
            ],
            "razor_pay" => [
                "USD" => "United States Dollar",
                "EUR" => "European Euro",
                "GBP" => "Pound Sterling",
                "SGD" => "Singapore Dollar",
                "AED" => "United Arab Emirates Dirham",
                "AUD" => "Australian Dollar",
                "CAD" => "Canadian Dollar",
                "CNY" => "Chinese Yuan Renminbi",
                "SEK" => "Swedish Krona",
                "NZD" => "New Zealand Dollar",
                "MXN" => "Mexican Peso",
                "RUB" => "Russian Ruble",
                "ALL" => "Albanian Lek",
                "AMD" => "Armenian Dram",
                "ARS" => "Argentine Peso",
                "AWG" => "Aruban Florin",
                "BBD" => "Barbadian Dollar",
                "BDT" => "Bangladeshi Taka",
                "BMD" => "Bermudian Dollar",
                "BND" => "Brunei Dollar",
                "BOB" => "Bolivian Boliviano",
                "BSD" => "Bahamian Dollar",
                "BWP" => "Botswana Pula",
                "BZD" => "Belize Dollar",
                "CHF" => "Swiss Franc",
                "COP" => "Colombian Peso",
                "CRC" => "Costa Rican Colon",
                "CUP" => "Cuban Peso",
                "CZK" => "Czech Koruna",
                "DKK" => "Danish Krone",
                "DOP" => "Dominican Peso",
                "DZD" => "Algerian Dinar",
                "EGP" => "Egyptian Pound",
                "ETB" => "Ethiopian Birr",
                "FJD" => "Fijian Dollar",
                "GIP" => "Gibraltar Pound",
                "GMD" => "Gambian Dalasi",
                "GTQ" => "Guatemalan Quetzal",
                "GYD" => "Guyanese Dollar",
                "HKD" => "Hong Kong Dollar",
                "HNL" => "Honduran Lempira",
                "HRK" => "Croatian Kuna",
                "HTG" => "Haitian Gourde",
                "HUF" => "Hungarian Forint",
                "IDR" => "Indonesian Rupiah",
                "ILS" => "Israeli New Shekel",
                "INR" => "Indian Rupee",
                "JMD" => "Jamaican Dollar",
                "KES" => "Kenyan Shilling",
                "KGS" => "Kyrgyzstani Som",
                "KHR" => "Cambodian Riel",
                "KYD" => "Cayman Islands Dollar",
                "KZT" => "Kazakhstani Tenge",
                "LAK" => "Lao Kip",
                "LBP" => "Lebanese Pound",
                "LKR" => "Sri Lankan Rupee",
                "LRD" => "Liberian Dollar",
                "LSL" => "Lesotho Loti",
                "MAD" => "Moroccan Dirham",
                "MDL" => "Moldovan Leu",
                "MKD" => "Macedonian Denar",
                "MMK" => "Myanmar Kyat",
                "MNT" => "Mongolian Tugrik",
                "MOP" => "Macanese Pataca",
                "MUR" => "Mauritian Rupee",
                "MVR" => "Maldivian Rufiyaa",
                "MWK" => "Malawian Kwacha",
                "MYR" => "Malaysian Ringgit",
                "NAD" => "Namibian Dollar",
                "NGN" => "Nigerian Naira",
                "NIO" => "Nicaraguan Cordoba",
                "NOK" => "Norwegian Krone",
                "NPR" => "Nepalese Rupee",
                "PEN" => "Peruvian Sol",
                "PGK" => "Papua New Guinean Kina",
                "PHP" => "Philippine Peso",
                "PKR" => "Pakistani Rupee",
                "QAR" => "Qatari Riyal",
                "SAR" => "Saudi Arabian Riyal",
                "SCR" => "Seychellois Rupee",
                "SLL" => "Sierra Leonean Leone",
                "SOS" => "Somali Shilling",
                "SSP" => "South Sudanese Pound",
                "SVC" => "Salvadoran Colón",
                "SZL" => "Swazi Lilangeni",
                "THB" => "Thai Baht",
                "TTD" => "Trinidad and Tobago Dollar",
                "TZS" => "Tanzanian Shilling",
                "UYU" => "Uruguayan Peso",
                "UZS" => "Uzbekistani Som",
                "YER" => "Yemeni Rial"
            ],
            "senang_pay" => [
                "MYR" => "Malaysian Ringgit"
            ],
            "sixcash" => [
                "BDT" => "Bangladeshi Taka"
            ],
            "ssl_commerz" => [
                "BDT" => "Bangladeshi Taka"
            ],
            "stripe" => [
                "USD" => "United States Dollar",
                "AED" => "United Arab Emirates Dirham",
                "AFN" => "Afghan Afghani",
                "ALL" => "Albanian Lek",
                "AMD" => "Armenian Dram",
                "ANG" => "Netherlands Antillean Guilder",
                "AOA" => "Angolan Kwanza",
                "ARS" => "Argentine Peso",
                "AUD" => "Australian Dollar",
                "AWG" => "Aruban Florin",
                "AZN" => "Azerbaijani Manat",
                "BAM" => "Bosnia-Herzegovina Convertible Mark",
                "BBD" => "Barbadian Dollar",
                "BDT" => "Bangladeshi Taka",
                "BGN" => "Bulgarian Lev",
                "BIF" => "Burundian Franc",
                "BMD" => "Bermudian Dollar",
                "BND" => "Brunei Dollar",
                "BOB" => "Bolivian Boliviano",
                "BRL" => "Brazilian Real",
                "BSD" => "Bahamian Dollar",
                "BWP" => "Botswana Pula",
                "BYN" => "Belarusian Ruble",
                "BZD" => "Belize Dollar",
                "CAD" => "Canadian Dollar",
                "CDF" => "Congolese Franc",
                "CHF" => "Swiss Franc",
                "CLP" => "Chilean Peso",
                "CNY" => "Chinese Yuan",
                "COP" => "Colombian Peso",
                "CRC" => "Costa Rican Colón",
                "CVE" => "Cape Verdean Escudo",
                "CZK" => "Czech Koruna",
                "DJF" => "Djiboutian Franc",
                "DKK" => "Danish Krone",
                "DOP" => "Dominican Peso",
                "DZD" => "Algerian Dinar",
                "EGP" => "Egyptian Pound",
                "ETB" => "Ethiopian Birr",
                "EUR" => "Euro",
                "FJD" => "Fijian Dollar",
                "FKP" => "Falkland Islands Pound",
                "GBP" => "Pound Sterling",
                "GEL" => "Georgian Lari",
                "GIP" => "Gibraltar Pound",
                "GMD" => "Gambian Dalasi",
                "GNF" => "Guinean Franc",
                "GTQ" => "Guatemalan Quetzal",
                "GYD" => "Guyanese Dollar",
                "HKD" => "Hong Kong Dollar",
                "HNL" => "Honduran Lempira",
                "HTG" => "Haitian Gourde",
                "HUF" => "Hungarian Forint",
                "IDR" => "Indonesian Rupiah",
                "ILS" => "Israeli New Shekel",
                "INR" => "Indian Rupee",
                "ISK" => "Icelandic Króna",
                "JMD" => "Jamaican Dollar",
                "JPY" => "Japanese Yen",
                "KES" => "Kenyan Shilling",
                "KGS" => "Kyrgyzstani Som",
                "KHR" => "Cambodian Riel",
                "KMF" => "Comorian Franc",
                "KRW" => "South Korean Won",
                "KYD" => "Cayman Islands Dollar",
                "KZT" => "Kazakhstani Tenge",
                "LAK" => "Laotian Kip",
                "LBP" => "Lebanese Pound",
                "LKR" => "Sri Lankan Rupee",
                "LRD" => "Liberian Dollar",
                "LSL" => "Lesotho Loti",
                "MAD" => "Moroccan Dirham",
                "MDL" => "Moldovan Leu",
                "MGA" => "Malagasy Ariary",
                "MKD" => "Macedonian Denar",
                "MMK" => "Myanmar Kyat",
                "MNT" => "Mongolian Tugrik",
                "MOP" => "Macanese Pataca",
                "MUR" => "Mauritian Rupee",
                "MVR" => "Maldivian Rufiyaa",
                "MWK" => "Malawian Kwacha",
                "MXN" => "Mexican Peso",
                "MYR" => "Malaysian Ringgit",
                "MZN" => "Mozambican Metical",
                "NAD" => "Namibian Dollar",
                "NGN" => "Nigerian Naira",
                "NIO" => "Nicaraguan Córdoba",
                "NOK" => "Norwegian Krone",
                "NPR" => "Nepalese Rupee",
                "NZD" => "New Zealand Dollar",
                "PAB" => "Panamanian Balboa",
                "PEN" => "Peruvian Sol",
                "PGK" => "Papua New Guinean Kina",
                "PHP" => "Philippine Peso",
                "PKR" => "Pakistani Rupee",
                "PLN" => "Polish Złoty",
                "PYG" => "Paraguayan Guaraní",
                "QAR" => "Qatari Riyal",
                "RON" => "Romanian Leu",
                "RSD" => "Serbian Dinar",
                "RUB" => "Russian Ruble",
                "RWF" => "Rwandan Franc",
                "SAR" => "Saudi Riyal",
                "SBD" => "Solomon Islands Dollar",
                "SCR" => "Seychellois Rupee",
                "SEK" => "Swedish Krona",
                "SGD" => "Singapore Dollar",
                "SHP" => "Saint Helena Pound",
                "SLE" => "Sierra Leonean Leone",
                "SOS" => "Somali Shilling",
                "SRD" => "Surinamese Dollar",
                "STD" => "São Tomé and Príncipe Dobra",
                "SZL" => "Swazi Lilangeni",
                "THB" => "Thai Baht",
                "TJS" => "Tajikistani Somoni",
                "TOP" => "Tongan Paʻanga",
                "TRY" => "Turkish Lira",
                "TTD" => "Trinidad and Tobago Dollar",
                "TWD" => "New Taiwan Dollar",
                "TZS" => "Tanzanian Shilling",
                "UAH" => "Ukrainian Hryvnia",
                "UGX" => "Ugandan Shilling",
                "UYU" => "Uruguayan Peso",
                "UZS" => "Uzbekistani Som",
                "VND" => "Vietnamese Dong",
                "VUV" => "Vanuatu Vatu",
                "WST" => "Samoan Tala",
                "XAF" => "Central African CFA Franc",
                "XCD" => "East Caribbean Dollar",
                "XCG" => "Gold Ounce (XAU/XCG pseudo)",
                "XOF" => "West African CFA Franc",
                "XPF" => "CFP Franc",
                "YER" => "Yemeni Rial",
                "ZAR" => "South African Rand",
                "ZMW" => "Zambian Kwacha"
            ],
            "swish" => [
                "SEK" => "Swedish Krona"
            ],
            "tap" => [
                "AED" => "United Arab Emirates Dirham",
                "SAR" => "Saudi Riyal",
                "BHD" => "Bahraini Dinar",
                "KWD" => "Kuwaiti Dinar",
                "OMR" => "Omani Rial",
                "QAR" => "Qatari Riyal"
            ],
            "thawani" => [
                "OMR" => "Omani Rial"
            ],
            "viva_wallet" => [
                "EUR" => "Euro"
            ],
            "worldpay" => [
                "GBP" => "Pound Sterling",
                "USD" => "United States Dollar",
                "EUR" => "Euro",
                "JPY" => "Japanese Yen"
            ],
            "xendit" => [
                "IDR" => "Indonesian Rupiah",
                "PHP" => "Philippine Peso",
                "VND" => "Vietnamese Dong",
                "THB" => "Thai Baht",
                "MYR" => "Malaysian Ringgit",
                "SGD" => "Singapore Dollar"
            ],
            "cinetpay" => [
                "XOF" => "West African CFA franc",
                "XAF" => "Central African CFA franc",
                "GNF" => "Guinean franc",
                "CDF" => "Congolese franc",
                "USD" => "United States Dollar"
            ],
        ];

        if ($key) {
            return $paymentGateway[$key] ?? [];
        }
        return $paymentGateway;
    }

    public function getPaymentGatewayCurrencyCode($key = null, $currentCurrency = null): string
    {
        $getSupportedCurrencies = $this->getPaymentGatewaySupportedCurrencies(key: $key);
        if ($currentCurrency && array_key_exists($currentCurrency, $getSupportedCurrencies) && Currency::where(['code' => $currentCurrency, 'status' => 1])->first()) {
            return $currentCurrency;
        } else if (count($getSupportedCurrencies) == 1) {
            $currencyCode = array_key_first($getSupportedCurrencies);
            if (Currency::where(['code' => $currencyCode, 'status' => 1])->first()) {
                return $currencyCode;
            }
        } else if (count($getSupportedCurrencies) > 1 && $key == 'paystack') {
            $currencyCode = Currency::whereIn('code', ['GHS', 'NGN'])->where(['status' => 1])->first();
            if ($currencyCode) {
                return $currencyCode?->code;
            }
        } else if (count($getSupportedCurrencies) > 1) {
            $currencyCodes = array_keys($getSupportedCurrencies);
            $currencyCode = Currency::whereIn('code', $currencyCodes)->where(['status' => 1])->first();
            if ($currencyCode) {
                return $currencyCode?->code;
            }
        }
        return 'USD';
    }
}