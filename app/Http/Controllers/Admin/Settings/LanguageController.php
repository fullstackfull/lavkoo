<?php

namespace App\Http\Controllers\Admin\Settings;

use Illuminate\Http\Request;
use App\Services\LanguageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\File;
use Illuminate\Http\RedirectResponse;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\LanguageRequest;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use App\Contracts\Repositories\BusinessSettingRepositoryInterface;

class LanguageController extends BaseController
{

    public function __construct(
        private readonly BusinessSettingRepositoryInterface $businessSettingRepo,
        private readonly LanguageService                    $languageService,
    )
    {
    }

    /**
     * @param Request|null $request
     * @param string|null $type
     * @return View Index function is the starting point of a controller
     * Index function is the starting point of a controller
     */
    public function index(Request|null $request, ?string $type = null): View
    {
        $languageList = getWebConfig(name: 'language');
        if (request()->has('search') && !empty(request()->input('search'))) {
            $searchTerm = strtolower(request()->input('search'));
            $languageList = array_filter($languageList, function ($language) use ($searchTerm) {
                return str_contains(strtolower($language['name']), $searchTerm) ||
                    str_contains(strtolower($language['code']), $searchTerm);
            });
            $languageList = array_values($languageList);
        }

        $languageCodes = [];
        $languageFlags = File::files(base_path('public/assets/front-end/img/flags'));
        $countries = collect(COUNTRIES);
        foreach ($languageFlags as $languageFlag) {
            if (in_array(strtoupper(pathinfo($languageFlag)['filename']), $countries->pluck('code')->toArray())) {
                $countryInfo = $countries->where('code', strtoupper(pathinfo($languageFlag)['filename']))->first();
                $languageCodes[] = [
                    'name' => $countryInfo['name'],
                    'iso2' => $countryInfo['code'],
                    'language_code' => strtolower(pathinfo($languageFlag)['filename']),
                    'flag' => dynamicAsset(path: 'public/assets/front-end/img/flags/'.pathinfo($languageFlag)['filename'].'.png'),
                ];
            }

        }

        $languageLocaleList = $this->getAllLocalesWithScriptFlag(countries: $languageCodes);

        return view('admin-views.system-setup.language.index', compact('languageList', 'languageLocaleList'));
    }

    public function getAllLocalesWithScriptFlag(array|object $countries): array
    {
        $locales = getLanguageCodeList();

        $formatedLocales = [];
        $existCountriesISO = [];

        foreach ($countries as $country) {
            foreach ($locales as $locale) {
                $parts = explode('-', $locale);
                if (strtoupper($country['iso2']) === strtoupper(end($parts))) {
                    if (count($parts) === 3 && in_array($parts[1], ['Cyrl', 'Latn'])) {
                        foreach (['Cyrl', 'Latn'] as $localeScript) {
                            $countryNewObj = $country;
                            $countryNewObj['locale'] = strtolower($country['iso2'] . '-' . $localeScript);
                            $countryNewObj['script'] = $localeScript;
                            $formatedLocales[strtolower($countryNewObj['iso2'] . '-' . $localeScript)] = $countryNewObj;
                        }
                    } else {
                        $country['locale'] = strtolower($country['iso2']);
                        $formatedLocales[strtolower($country['iso2'])] = $country;
                    }
                    $existCountriesISO[] = strtolower($country['iso2']);
                }
            }

            if (!in_array(strtolower($country['iso2']), $existCountriesISO)) {
                $country['locale'] = strtolower($country['iso2']);
                $formatedLocales[strtolower($country['iso2'])] = $country;
            }
        }

        return $formatedLocales;
    }

    public function add(LanguageRequest $request, LanguageService $languageService): RedirectResponse
    {
        // The theme's per-field localisation memoises the live language list; a language change
        // must reach it now, not at the next worker restart.
        \App\Services\Theme\LocalisedSettings::forget();
        $language = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'language']);
        $dataArray = $languageService->getAddData(request: $request, language: $language);
        $this->businessSettingRepo->updateOrInsert(type: 'language', value: $dataArray['languages']);
        $this->businessSettingRepo->updateOrInsert(type: 'pnc_language', value: json_encode($dataArray['codes']));
        clearWebConfigCacheKeys();
        updateSetupGuideCacheKey(key: 'language_setup', panel: 'admin');
        ToastMagic::success(translate('Language_Added'));
        return back();
    }

    public function updateStatus(Request $request, LanguageService $languageService): JsonResponse
    {
        // The theme's per-field localisation memoises the live language list; a language change
        // must reach it now, not at the next worker restart.
        \App\Services\Theme\LocalisedSettings::forget();
        $language = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'language']);
        $languageArray = $languageService->getStatusData(request: $request, language: $language);
        clearWebConfigCacheKeys();
        $this->businessSettingRepo->updateOrInsert(type: 'language', value: $languageArray);
        updateSetupGuideCacheKey(key: 'language_setup', panel: 'admin');
        return response()->json([
            'status' => 1,
            'message' => translate('Language_Status_Updated'),
        ]);
    }

    public function updateDefaultStatus(Request $request, LanguageService $languageService): RedirectResponse
    {
        // The theme's per-field localisation memoises the live language list; a language change
        // must reach it now, not at the next worker restart.
        \App\Services\Theme\LocalisedSettings::forget();
        $language = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'language']);
        $languageArray = $languageService->getDefaultData(request: $request, language: $language);
        $this->businessSettingRepo->updateOrInsert(type: 'language', value: $languageArray);
        clearWebConfigCacheKeys();
        ToastMagic::success(translate('Default_Language_Changed'));
        return back();
    }

    public function update(LanguageRequest $request, LanguageService $languageService): RedirectResponse
    {
        // The theme's per-field localisation memoises the live language list; a language change
        // must reach it now, not at the next worker restart.
        \App\Services\Theme\LocalisedSettings::forget();
        $language = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'language']);
        $languageArray = $languageService->getUpdateData(request: $request, language: $language);
        $this->businessSettingRepo->updateOrInsert(type: 'language', value: $languageArray);
        clearWebConfigCacheKeys();
        updateSetupGuideCacheKey(key: 'language_setup', panel: 'admin');
        ToastMagic::success(translate('Language_updated'));
        return back();
    }

    public function getTranslateView($lang): View
    {
        $languages = getWebConfig(name: 'language');
        $language = collect($languages)->firstWhere('code', $lang);
        $languageName = $language['name'] ?? '';
        $totalMessages = count(include(base_path('resources/lang/' . $lang . '/new-messages.php')));
        $messageGroup = 200;
        return view('admin-views.system-setup.language.translate', compact('lang', 'languageName', 'totalMessages', 'messageGroup'));
    }

    public function getTranslateList($lang, LanguageService $languageService): JsonResponse
    {
        $data = $languageService->getTranslateList(language: $lang);
        return response()->json($data);
    }

    public function deleteTranslateKey(Request $request, $lang): void
    {
        $fullData = include(base_path('resources/lang/' . $lang . '/messages.php'));
        unset($fullData[$request['key']]);
        $string = "<?php return " . var_export($fullData, true) . ";";
        file_put_contents(base_path('resources/lang/' . $lang . '/messages.php'), $string);
    }

    public function updateTranslate(Request $request, $lang): JsonResponse
    {
        $translatedMessagesArray = include(base_path('resources/lang/' . $lang . '/messages.php'));
        $newMessagesArray = include(base_path('resources/lang/' . $lang . '/new-messages.php'));
        $textKey = $request->has('key') && !empty($request['key']) ? base64_decode($request['key']) : '';
        $dataFiltered = [];

        if (array_key_exists($textKey, $translatedMessagesArray)) {
            foreach ($translatedMessagesArray as $key => $data) {
                $dataFiltered[removeSpecialCharacters(text: $key)] = $data;
            }
            $dataFiltered[base64_decode($request['key'])] = removeSpecialCharacters($request['value']);
            $this->languageService->updateAdvancedSearchKeyWords($lang, $textKey, removeSpecialCharacters($request['value']));
            $string = "<?php return " . var_export($dataFiltered, true) . ";";
            file_put_contents(base_path('resources/lang/' . $lang . '/messages.php'), $string);
        } elseif (array_key_exists($textKey, $newMessagesArray)) {
            foreach ($newMessagesArray as $key => $data) {
                $dataFiltered[removeSpecialCharacters(text: $key)] = $data;
            }
            $dataFiltered[base64_decode($request['key'])] = removeSpecialCharacters($request['value']);
            $this->languageService->updateAdvancedSearchKeyWords($lang, $textKey, removeSpecialCharacters($request['value']));
            $string = "<?php return " . var_export($dataFiltered, true) . ";";
            file_put_contents(base_path('resources/lang/' . $lang . '/new-messages.php'), $string);
        }
        return response()->json(['message' => translate('Message_Updated')]);
    }

    public function getAutoTranslate(Request $request, $lang): JsonResponse
    {
        $translatedMessagesArray = include(base_path('resources/lang/' . $lang . '/messages.php'));
        $newMessagesArray = include(base_path('resources/lang/' . $lang . '/new-messages.php'));
        $textKey = $request->has('key') && !empty($request['key']) ? base64_decode($request['key']) : '';
        $dataFiltered = [];

        if ($textKey) {
            $langCode = getLanguageCode($lang);
            if (array_key_exists($textKey, $translatedMessagesArray)) {

                foreach ($translatedMessagesArray as $key => $data) {
                    $dataFiltered[removeSpecialCharacters(text: $key)] = $data;
                }

                $translated = autoTranslator($textKey, 'en', $langCode);
                $this->languageService->updateAdvancedSearchKeyWords($lang,$textKey, removeSpecialCharacters($translated));
                $dataFiltered[$textKey] = removeSpecialCharacters($translated);

                $string = "<?php return " . var_export($dataFiltered, true) . ";";
                file_put_contents(base_path('resources/lang/' . $lang . '/messages.php'), $string);
                return response()->json(['translated_data' => $translated]);
            } elseif (array_key_exists($textKey, $newMessagesArray)) {
                foreach ($newMessagesArray as $key => $data) {
                    $dataFiltered[removeSpecialCharacters(text: $key)] = $data;
                }

                $translated = autoTranslator($textKey, 'en', $langCode);
                $this->languageService->updateAdvancedSearchKeyWords($lang, $textKey, removeSpecialCharacters($translated));
                $dataFiltered[$textKey] = removeSpecialCharacters($translated);

                $string = "<?php return " . var_export($dataFiltered, true) . ";";
                file_put_contents(base_path('resources/lang/' . $lang . '/new-messages.php'), $string);
                return response()->json(['translated_data' => $translated]);
            }
        }
        return response()->json(['message' => 'empty_data']);
    }

    public function getAutoTranslateAllMessages(Request $request, $lang, LanguageService $languageService): JsonResponse|RedirectResponse
    {
        if (config('app.mode') == 'demo') {
            ToastMagic::info(translate('This_option_is_disabled_for_demo'));
            return back();
        }

        $response = $languageService->getAllMessagesTranslateProcess(language: $lang, count: 200);
        return response()->json([
            'status' => $response['status'],
            'message' => $response['message'],
            'due_message' => $response['due_message'],
            'translate_success_message' => $response['translateCountSuccess'] > 0 ? translate('your') . ' ' . $response['translateCountSuccess'] . ' ' . translate('messages_successfully_translated') : translate('all_messages_are_in_translated'),
        ]);
    }

    public function delete($lang, LanguageService $languageService): RedirectResponse
    {
        $language = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'language']);
        $languageArray = $languageService->getLangDelete(language: $language, code: $lang);
        $this->businessSettingRepo->updateOrInsert(type: 'language', value: $languageArray);

        $languages = array();
        $pncLanguage = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'pnc_language']);
        foreach (json_decode($pncLanguage['value'], true) as $key => $data) {
            if ($data != $lang) {
                $languages[] = $data;
            }
        }
        if (in_array('en', $languages)) {
            unset($languages[array_search('en', $languages)]);
        }
        array_unshift($languages, 'en');
        $this->businessSettingRepo->updateOrInsert(type: 'pnc_language', value: json_encode($languages));

        //language json file delete
        $filePath = public_path('json/admin/lang/' . $lang . '.json');
        if (file_exists($filePath)) {
            @unlink($filePath);
        }

        clearWebConfigCacheKeys();
        ToastMagic::success(translate('Removed_Successfully'));
        return back();
    }

}
