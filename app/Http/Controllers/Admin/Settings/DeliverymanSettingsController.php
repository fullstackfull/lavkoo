<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Contracts\Repositories\BusinessSettingRepositoryInterface;
use App\Http\Controllers\BaseController;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DeliverymanSettingsController extends BaseController
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
        $data = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'upload_picture_on_delivery']);
        return view('admin-views.business-settings.delivery-man-settings.index', compact('data'));
    }

    public function update(Request $request): RedirectResponse
    {
        $this->businessSettingRepo->updateOrInsert(type: 'deliveryman_forgot_password_method', value: $request->get('deliveryman_forgot_password_method', 'phone'));
        clearWebConfigCacheKeys();
        ToastMagic::success(translate('Updated_successfully'));
        return redirect()->back();
    }
    public function uploadPicture(Request $request): RedirectResponse
    {
        $this->businessSettingRepo->updateOrInsert(type: 'upload_picture_on_delivery', value: $request->get('upload_picture_on_delivery', 0));
        clearWebConfigCacheKeys();
        ToastMagic::success(translate('Updated_successfully'));
        return redirect()->back();
    }

}
