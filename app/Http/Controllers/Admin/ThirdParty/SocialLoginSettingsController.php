<?php

namespace App\Http\Controllers\Admin\ThirdParty;

use App\Contracts\Repositories\BusinessSettingRepositoryInterface;
use App\Http\Controllers\BaseController;
use App\Services\SocialLoginService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SocialLoginSettingsController extends BaseController
{

    public function __construct(
        private readonly BusinessSettingRepositoryInterface $businessSettingRepo,
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
        $data = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'social_login']);
        $apple = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'apple_login']);
        return view('admin-views.third-party.social-login.view', compact('data', 'apple'));
    }

    public function update($service, Request $request, SocialLoginService $socialLoginService): RedirectResponse
    {
        if ($request->hasFile('service_file')) {
            $extension = strtolower($request->file('service_file')->getClientOriginalExtension());
            if ($extension !== 'p8') {
                ToastMagic::error(translate('file_must_be_of_type_.p8'));
                return back()->withInput();
            }
        }
        $socialLogin = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'social_login']);
        $credentialArray = $socialLoginService->getUpdateData(request: $request, socialLogin: $socialLogin, service: $service);
        $this->businessSettingRepo->updateWhere(params: ['type' => 'social_login'], data: ['value' => $credentialArray]);
        cacheRemoveByType(type: 'login_setups');
        ToastMagic::success(translate($service . '_credentials_updated'));
        return back();
    }

    public function updateAppleLogin($service, Request $request, SocialLoginService $socialLoginService): RedirectResponse
    {
        $appleLogin = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'apple_login']);
        $credentialArray = $socialLoginService->getAppleData(request: $request, appleLogin: $appleLogin, service: $service);
        $this->businessSettingRepo->updateWhere(params: ['type' => 'apple_login'], data: ['value' => $credentialArray]);
        cacheRemoveByType(type: 'login_setups');
        ToastMagic::success(translate('credential_updated_' . $service));
        return back();
    }

}
