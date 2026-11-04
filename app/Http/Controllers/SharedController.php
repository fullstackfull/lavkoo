<?php

namespace App\Http\Controllers;

use App\Http\Requests\Request;
use App\Traits\ActivationClass;
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
        $direction = 'ltr';
        $language = getWebConfig('language');
        foreach ($language as $data) {
            if ($data['code'] == $request['language_code']) {
                $direction = $data['direction'] ?? 'ltr';
            }
        }
        session()->forget('language_settings');
        Helpers::language_load();
        session()->put('local', $request['language_code']);
        Session::put('direction', $direction);
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
        $config = $this->getAddonsConfig();
        $adminPanel = $config['admin_panel'] ?? [];
        $status = ($this->is_local() || env('DEVELOPMENT_ENVIRONMENT', false)) ? 1 : ($adminPanel['active'] ?? 0);
        return $status == 1 ? redirect(url('/')) : view('installation.activation-check');
    }

    public function activationCheck(Request $request): RedirectResponse
    {
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
