<?php

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

if (!function_exists('translate')) {
    function translate($key = null): string|null
    {
        $local = getDefaultLanguage();
        if ($key) {
            App::setLocale($local);
            $key = getOrPutTranslateMessageValueByKey(local: $local, key: $key);
        }
        App::setLocale(getLanguageCode(country_code: $local));
        return $key;
    }

    function getOrPutTranslateMessageValueByKey(string $local, string $key): array|string|null
    {
        try {
            $translatedMessagesArray = include(base_path('resources/lang/' . $local . '/messages.php'));
            $newMessagesArray = include(base_path('resources/lang/' . $local . '/new-messages.php'));
            $key = str_replace('"', '', $key);
            $key = str_replace(' ', '_', $key);
            $processedKey = ucfirst(str_replace('_', ' ', removeSpecialCharacters($key)));

            if (!array_key_exists($key, $translatedMessagesArray) && !array_key_exists($key, $newMessagesArray)) {
                $newMessagesArray[$key] = $processedKey;

                // var_export, not string concatenation: a translation carrying a quote, a
                // backslash or a $ used to be written into a double-quoted PHP string as-is, which
                // made the file unparsable — and this file is included on EVERY translate() call,
                // so one such value took the whole site down with a fatal on every request.
                $languageFileContents = "<?php\n\nreturn [\n";
                foreach ($newMessagesArray as $languageKey => $value) {
                    $languageFileContents .= "\t" . var_export((string) $languageKey, true)
                        . ' => ' . var_export((string) $value, true) . ",\n";
                }
                $languageFileContents .= "];\n";

                $targetPath = base_path('resources/lang/' . $local . '/new-messages.php');
                file_put_contents($targetPath, $languageFileContents);

                // Two caches still hold the PRE-write copy of the file we just rewrote, and both
                // answer the next translate() call in this same request with stale data:
                //   - OPcache keeps the compiled include. The next include() of this path can hand
                //     back the array as it was before this write, so the key added here is lost on
                //     the following write.
                //   - Laravel's translator loads a language group once per request. The next call
                //     for this key takes the array_key_exists() branch below and resolves through
                //     __('new-messages.<key>') against the copy read before the write, printing the
                //     raw key — "New-messages.free_delivery" instead of "Free delivery".
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($targetPath, true);
                }
                $translator = app('translator');
                $translator->load('*', 'new-messages', $local);
                $translator->addLines(['new-messages.' . $key => $processedKey], $local);

                $message = $processedKey;
            } elseif (array_key_exists($key, $translatedMessagesArray)) {
                $message = __('messages.' . $key);
            } elseif (array_key_exists($key, $newMessagesArray)) {
                $message = __('new-messages.' . $key);
            } else {
                $message = __('messages.' . $key);
            }
        } catch (Throwable $exception) {
            // A corrupt or unreadable language file is a ParseError, which is an Error and not an
            // Exception: it used to escape this handler and fatal the request. Falling back to the
            // humanised key keeps the page up while the file is repaired.
            report($exception);
            $message = ucfirst(str_replace('_', ' ', removeSpecialCharacters(str_replace("\'", "'", $key))));
        }
        return $local == 'en' ? ucfirst($message) : $message;
    }
}

if (!function_exists('getDirectoriesByGivenPath')) {
    function getDirectoriesByGivenPath(string $path): array
    {
        $directories = [];
        $items = scandir($path);
        foreach ($items as $item) {
            if ($item == '..' || $item == '.')
                continue;
            if (is_dir($path . '/' . $item))
                $directories[] = $item;
        }
        return $directories;
    }
}


if (!function_exists('removeSpecialCharacters')) {
    function removeSpecialCharacters(string|null $text): string|null
    {
        return str_ireplace(['\'', '"', ';', '<', '>', '?', '“', '”'], ' ', preg_replace('/\s\s+/', ' ', $text));
    }
}

if (!function_exists('getDefaultLanguage')) {
    function getDefaultLanguage(): string
    {
        if (strpos(url()->current(), '/api')) {
            $lang = App::getLocale();
        } elseif (session()->has('local')) {
            $lang = session('local');
        } else {
            $data = getWebConfig('language');
            $code = 'en';
            $direction = 'ltr';
            // Guard: when the `language` business setting is missing (fresh or partially configured
            // install) getWebConfig() returns null, and foreach(null) fatals — which would break
            // every translate() call, i.e. the whole UI. Fall back to the defaults set above.
            foreach ((is_iterable($data) ? $data : []) as $ln) {
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
}

if (!function_exists('getLanguageName')) {
    function getLanguageName(string $key): string
    {
        $values = getWebConfig('language');
        foreach ($values as $value) {
            if ($value['code'] == $key) {
                $key = $value['name'];
            }
        }
        return $key;
    }
}

if (!function_exists('getLanguageCode')) {
    function getLanguageCodeList(): array
    {
        return [
            'af-ZA',
            'am-ET',
            'ar-AE',
            'ar-BH',
            'ar-DZ',
            'ar-EG',
            'ar-IQ',
            'ar-JO',
            'ar-KW',
            'ar-LB',
            'ar-LY',
            'ar-MA',
            'ar-OM',
            'ar-QA',
            'ar-SA',
            'ar-SY',
            'ar-TN',
            'ar-YE',
            'az-Cyrl-AZ',
            'az-Latn-AZ',
            'be-BY',
            'bg-BG',
            'bn-BD',
            'bs-Cyrl-BA',
            'bs-Latn-BA',
            'ca-AD',
            'cs-CZ',
            'da-DK',
            'de-AT',
            'de-CH',
            'de-DE',
            'de-LI',
            'de-LU',
            'dv-MV',
            'el-GR',
            'en-AU',
            'en-BZ',
            'en-CA',
            'en-GB',
            'en-IE',
            'en-JM',
            'en-MY',
            'en-NZ',
            'en-SG',
            'en-TT',
            'en-US',
            'en-ZA',
            'en-ZW',
            'es-AR',
            'es-BO',
            'es-CL',
            'es-CO',
            'es-CR',
            'es-DO',
            'es-EC',
            'es-ES',
            'es-GT',
            'es-HN',
            'es-MX',
            'es-NI',
            'es-PA',
            'es-PE',
            'es-PR',
            'es-PY',
            'es-SV',
            'es-US',
            'es-UY',
            'es-VE',
            'et-EE',
            'fa-IR',
            'fi-FI',
            'fil-PH',
            'fo-FO',
            'fr-BE',
            'fr-CA',
            'fr-CH',
            'fr-FR',
            'fr-LU',
            'fr-MC',
            'he-IL',
            'hi-IN',
            'hr-BA',
            'hr-HR',
            'hu-HU',
            'hy-AM',
            'id-ID',
            'ig-NG',
            'is-IS',
            'it-CH',
            'it-IT',
            'ja-JP',
            'ka-GE',
            'kk-KZ',
            'kl-GL',
            'km-KH',
            'ko-KR',
            'ky-KG',
            'lb-LU',
            'lo-LA',
            'lt-LT',
            'lv-LV',
            'mi-NZ',
            'mk-MK',
            'mn-MN',
            'ms-BN',
            'ms-MY',
            'mt-MT',
            'nb-NO',
            'ne-NP',
            'nl-BE',
            'nl-NL',
            'pl-PL',
            'prs-AF',
            'ps-AF',
            'pt-BR',
            'pt-PT',
            'ro-RO',
            'ru-RU',
            'rw-RW',
            'sv-SE',
            'si-LK',
            'sk-SK',
            'sl-SI',
            'sq-AL',
            'sr-Cyrl-BA',
            'sr-Cyrl-CS',
            'sr-Cyrl-ME',
            'sr-Cyrl-RS',
            'sr-Latn-BA',
            'sr-Latn-CS',
            'sr-Latn-ME',
            'sr-Latn-RS',
            'sw-KE',
            'tg-Cyrl-TJ',
            'th-TH',
            'tk-TM',
            'tr-TR',
            'uk-UA',
            'ur-PK',
            'uz-Cyrl-UZ',
            'uz-Latn-UZ',
            'vi-VN',
            'wo-SN',
            'yo-NG',
            'zh-CN',
            'zh-HK',
            'zh-MO',
            'zh-SG',
            'zh-TW',

            // Newly added missing locales
            'en-AG',
            'en-AI',
            'pt-AO',
            'en-AS',
            'nl-AW',
            'sv-AX',
            'en-BB',
            'fr-BF',
            'rn-BI',
            'fr-BJ',
            'en-BM',
            'dz-BT',
            'en-BW',
            'en-CC',
            'fr-CD',
            'fr-CF',
            'fr-CG',
            'fr-CI',
            'en-CK',
            'fr-CM',
            'es-CU',
            'pt-CV',
            'en-CX',
            'el-CY',
            'fr-DJ',
            'en-DM',
            'ar-EH',
            'ti-ER',
            'en-FJ',
            'en-FK',
            'en-FM',
            'fr-GA',
            'en-GD',
            'fr-GF',
            'en-GH',
            'en-GI',
            'en-GM',
            'fr-GN',
            'fr-GP',
            'es-GQ',
            'en-GU',
            'pt-GW',
            'en-GY',
            'en-HM',
            'ht-HT',
            'en-IO',
            'en-KI',
            'ar-KM',
            'en-KN',
            'ko-KP',
            'en-KY',
            'en-LC',
            'en-LR',
            'en-LS',
            'ro-MD',
            'fr-MG',
            'en-MH',
            'bm-ML',
            'my-MM',
            'en-MP',
            'fr-MQ',
            'ar-MR',
            'en-MS',
            'en-MU',
            'en-MW',
            'pt-MZ',
            'en-NA',
            'fr-NC',
            'fr-NE',
            'en-NF',
            'en-NR',
            'en-NU',
            'fr-PF',
            'en-PG',
            'fr-PM',
            'en-PN',
            'ar-PS',
            'en-PW',
            'en-SB',
            'fr-SC',
            'ar-SD',
            'en-SH',
            'no-SJ',
            'it-SM',
            'fr-SN',
            'so-SO',
            'nl-SR',
            'pt-ST',
            'en-SZ',
            'en-TC',
            'fr-TD',
            'fr-TF',
            'fr-TG',
            'en-TK',
            'pt-TL',
            'en-TO',
            'en-TV',
            'sw-TZ',
            'en-UG',
            'en-UM',
            'la-VA',
            'en-VC',
            'en-VG',
            'en-VI',
            'bi-VU',
            'fr-WF',
            'sm-WS',
            'fr-YT',
            'en-ZM',
            'en-ZW'
        ];
    }
}

if (!function_exists('getLanguageCode')) {
    function getLanguageCode(string $country_code): string
    {
        $scripts = [];
        if (Str::contains($country_code, '-')) {
            $countryCodeArr = explode('-', $country_code);
            $country_code = $countryCodeArr[0];
        }
        $locales = getLanguageCodeList();
        foreach ($locales as $locale) {
            $localeRegion = explode('-', $locale);
            if (strtoupper($country_code) === strtoupper(end($localeRegion))) {
                if (Str::contains($country_code, '-')) {
                    return $localeRegion[0] . '-' . end($scripts);
                }
                return $localeRegion[0];
            }
        }
        return "en";
    }
}

if (!function_exists('autoTranslatorBatchedMode')) {
    function autoTranslatorBatchedMode(string $text, string $sourceLang, string $targetLang): string
    {
        $apiTargetLang = ($targetLang === 'sr-Latn') ? 'sr' : $targetLang;

        $url = "https://translate.googleapis.com/translate_a/single?client=gtx&ie=UTF-8&oe=UTF-8&dt=t"
            . "&sl={$sourceLang}&tl={$apiTargetLang}&q=" . urlencode($text);

//        $cleanText = urlencode(str_replace('_', ' ', $text));
//        $url = "https://translate.googleapis.com/translate_a/single?client=gtx&ie=UTF-8&oe=UTF-8&dt=t&sl="
//            . $sourceLang . "&tl=" . $apiTargetLang . "&q=" . $cleanText;

        $response = @file_get_contents($url);
        if ($response === false) {
            return '';
        }

        $data = json_decode($response, true);
        if (!isset($data[0])) {
            return '';
        }

        $translated = '';
        foreach ($data[0] as $item) {
            $translated .= $item[0];
        }

        $translated = str_replace('\n', "\n", $translated);
        if (needsTransliteration($targetLang)) {
            $translated = transliterateCyrillicToLatin($translated, $targetLang);
        }
        return trim($translated);
    }
}

if (!function_exists('autoTranslator')) {
    function autoTranslator(string $text, string $sourceLang, string $targetLang): array|string
    {
        // Map sr-Latn to sr for translation
        $apiTargetLang = ($targetLang === 'sr-Latn') ? 'sr' : $targetLang;

        $cleanText = urlencode(str_replace('_', ' ', $text));
        $url = "https://translate.googleapis.com/translate_a/single?client=gtx&ie=UTF-8&oe=UTF-8&dt=t&sl="
            . $sourceLang . "&tl=" . $apiTargetLang . "&q=" . $cleanText;

        $response = file_get_contents($url);
        $data = json_decode($response);

        $translated = $data[0][0][0] ?? '';

        // If target was Serbian Latin, transliterate from Cyrillic
        if (needsTransliteration($targetLang)) {
            $translated = transliterateCyrillicToLatin($translated, $targetLang);
        }

        return str_replace('_', ' ', $translated);
    }

    function needsTransliteration(string $targetLang): bool
    {
        $languagesRequiringTransliteration = [
            'rs-Latn', // Serbian (Latin, Google only returns Cyrillic)
            'ba-Latn', // Bosnian Latin may sometimes return Cyrillic
            'az-Latn', // Edge cases
            'uz-Latn',
        ];

        return in_array($targetLang, $languagesRequiringTransliteration);
    }

    function transliterateCyrillicToLatin(string $text, string $langCode): string
    {
        $map = [];

        if (in_array($langCode, ['sr-Latn', 'ba-Latn'])) {
            // Serbian and Bosnian
            $map = [
                'Љ' => 'Lj', 'Њ' => 'Nj', 'Џ' => 'Dž', 'љ' => 'lj', 'њ' => 'nj', 'џ' => 'dž',
                'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D', 'Ђ' => 'Đ', 'Е' => 'E', 'Ж' => 'Ž', 'З' => 'Z',
                'И' => 'I', 'Ј' => 'J', 'К' => 'K', 'Л' => 'L', 'М' => 'M', 'Н' => 'N', 'О' => 'O', 'П' => 'P',
                'Р' => 'R', 'С' => 'S', 'Т' => 'T', 'Ћ' => 'Ć', 'У' => 'U', 'Ф' => 'F', 'Х' => 'H', 'Ц' => 'C', 'Ч' => 'Č', 'Ш' => 'Š',
                'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'ђ' => 'đ', 'е' => 'e', 'ж' => 'ž', 'з' => 'z',
                'и' => 'i', 'ј' => 'j', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p',
                'р' => 'r', 'с' => 's', 'т' => 't', 'ћ' => 'ć', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'č', 'ш' => 'š',
            ];
        } elseif ($langCode === 'uz-Latn') {
            // Uzbek (Cyrillic to Latin)
            $map = [
                'А' => 'A', 'Б' => 'B', 'Д' => 'D', 'Э' => 'E', 'Ф' => 'F', 'Г' => 'G', 'Ҳ' => 'H', 'И' => 'I', 'Ж' => 'J',
                'К' => 'K', 'Л' => 'L', 'М' => 'M', 'Н' => 'N', 'О' => 'O', 'П' => 'P', 'Қ' => 'Q', 'Р' => 'R', 'С' => 'S',
                'Ш' => 'Sh', 'Т' => 'T', 'У' => 'U', 'В' => 'V', 'Х' => 'X', 'Й' => 'Y', 'З' => 'Z', 'Ч' => 'Ch', 'Ў' => 'O‘',
                'Ю' => 'Yu', 'Я' => 'Ya', 'Ё' => 'Yo', 'Ц' => 'Ts', 'Ъ' => '', 'Ь' => '', 'Е' => 'Ye', 'НГ' => 'Ng',
                'а' => 'a', 'б' => 'b', 'д' => 'd', 'э' => 'e', 'ф' => 'f', 'г' => 'g', 'ҳ' => 'h', 'и' => 'i', 'ж' => 'j',
                'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'қ' => 'q', 'р' => 'r', 'с' => 's',
                'ш' => 'sh', 'т' => 't', 'у' => 'u', 'в' => 'v', 'х' => 'x', 'й' => 'y', 'з' => 'z', 'ч' => 'ch', 'ў' => 'o‘',
                'ю' => 'yu', 'я' => 'ya', 'ё' => 'yo', 'ц' => 'ts', 'ъ' => '', 'ь' => '', 'е' => 'ye', 'нг' => 'ng',
            ];
        } elseif ($langCode === 'az-Latn') {
            // Azerbaijani (Cyrillic to Latin)
            $map = [
                'А' => 'A', 'Б' => 'B', 'С' => 'S', 'Ч' => 'Ç', 'Д' => 'D', 'Ә' => 'Ə', 'Е' => 'E', 'Ф' => 'F',
                'Г' => 'Q', 'Һ' => 'H', 'Ы' => 'I', 'Ж' => 'J', 'К' => 'K', 'Л' => 'L', 'М' => 'M', 'Н' => 'N',
                'О' => 'O', 'Ө' => 'Ö', 'П' => 'P', 'Р' => 'R', 'Ш' => 'Ş', 'Т' => 'T', 'У' => 'U', 'Ү' => 'Ü',
                'В' => 'V', 'Й' => 'Y', 'З' => 'Z',
                'а' => 'a', 'б' => 'b', 'с' => 's', 'ч' => 'ç', 'д' => 'd', 'ә' => 'ə', 'е' => 'e', 'ф' => 'f',
                'г' => 'q', 'һ' => 'h', 'ы' => 'ı', 'ж' => 'j', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
                'о' => 'o', 'ө' => 'ö', 'п' => 'p', 'р' => 'r', 'ш' => 'ş', 'т' => 't', 'у' => 'u', 'ү' => 'ü',
                'в' => 'v', 'й' => 'y', 'з' => 'z',
            ];
        }

        return strtr($text, $map);
    }
}
