<?php

namespace App\Http\Controllers\Admin\ThirdParty;

use App\Contracts\Repositories\BusinessSettingRepositoryInterface;
use App\Http\Controllers\BaseController;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RecaptchaController extends BaseController
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
        $config = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'recaptcha']);
        return view('admin-views.third-party.recaptcha-index', compact('config'));
    }

    public function update(Request $request): RedirectResponse
    {
        if ($request['status'] == 1 && (empty($request['site_key']) || empty($request['secret_key']))) {
            ToastMagic::error(translate('Please_provide_the_credentials_for_active'));
            return back();
        }

        $value = json_encode(['status' => $request['status'] ?? 0, 'site_key' => $request['site_key'], 'secret_key' => $request['secret_key']]);
        $this->businessSettingRepo->updateOrInsert(type: 'recaptcha', value: $value);
        ToastMagic::success(translate('Updated_Successfully'));
        return back();
    }
}
