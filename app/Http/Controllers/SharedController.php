<?php

namespace App\Http\Controllers;

use App\Http\Requests\Request;
use App\Traits\ActivationClass;
use App\Services\Theme\LocalisedSettings;
use App\Utils\Helpers;
use App\Models\BusinessSetting;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;

class SharedController extends Controller
{
    use ActivationClass;

    public function changeLanguage(Request $request): JsonResponse
    {
        $languageCode = $request['language_code'];
        $selectedLanguage = is_string($languageCode) ? collect(getWebConfig('language') ?? [])->firstWhere('code', $languageCode) : null;
        if (!$selectedLanguage) {
            return response()->json(['message' => translate('the_language_code_is_not_valid')], 422);
        }

        // And the MARKET has to present it, which this never asked (I1-16). The list above is the INSTALLATION'S languages, so a visitor
        // on a storefront whose operator listed Arabic only could still switch it to English and read an English page from a market that
        // does not offer English. The rule lives in one place because the API's `lang` header had the same hole.
        // Null means this market names no list -- every market today and every market while storefronts are off -- and then this is the
        // switch it has always been.
        $offered = LocalisedSettings::localeOfferedToVisitor(requested: (string) $languageCode);
        if ($offered !== null && $offered !== $languageCode) {
            return response()->json(['message' => translate('this_market_does_not_present_that_language')], 422);
        }

        session()->forget('language_settings');
        Helpers::language_load();
        session()->put('local', $languageCode);
        Session::put('direction', $selectedLanguage['direction'] ?? 'ltr');
        Artisan::call('cache:clear');
        return response()->json(['message' => translate('language_change_successfully') . '.']);
    }

    public function storeRecaptchaResponse(Request $request): JsonResponse
    {
        $response = $request->get('g_recaptcha_response', null);
        if ($response) {
            session()->put('g-recaptcha-response', $response);
        }
        return response()->json(['recaptcha' => $response]);
    }

    public function getActivationCheckView(Request $request): View|RedirectResponse
    {
        return $this->isAdminPanelActivated() ? redirect(url('/')) : view('installation.activation-check');
    }

    /** The activation form and the request it posts have nothing to do on a panel that is activated already. */
    private function isAdminPanelActivated(): bool
    {
        if ($this->is_local() || env('DEVELOPMENT_ENVIRONMENT', false)) {
            return true;
        }

        return ($this->getAddonsConfig()['admin_panel']['active'] ?? 0) == 1;
    }

    public function activationCheck(Request $request): RedirectResponse
    {
        if ($this->isAdminPanelActivated()) {
            return redirect(url('/'));
        }

        $response = $this->getRequestConfig(
            username: $request['username'],
            purchaseKey: $request['purchase_key'],
            softwareType: $request->get('software_type', base64_decode('cHJvZHVjdA=='))
        );
        $this->updateActivationConfig(app: 'admin_panel', response: $response);

        if (!empty($response['errors'])) {
            foreach ($response['errors'] as $error) {
                $message = is_array($error) ? ($error[0] ?? 'Unknown error') : $error;
                ToastMagic::error($message);
            }
        }
        return redirect(url('/'));
    }


}
