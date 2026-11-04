<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\VendorRegistrationReasonRequest;
use App\Repositories\VendorRegistrationReasonRepository;
use App\Services\VendorRegistrationSettingService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class VendorRegistrationReasonController extends BaseController
{
    public function __construct(

        private readonly VendorRegistrationReasonRepository $vendorRegistrationReasonRepo,
        private readonly VendorRegistrationSettingService $vendorRegistrationSettingService,
    )
    {
    }
    public function index(?Request $request, ?string $type = null): View|Collection|LengthAwarePaginator|null|callable|RedirectResponse
    {
        // TODO: Implement index() method.
    }
    public function add(VendorRegistrationReasonRequest $request): RedirectResponse
    {
        $this->vendorRegistrationReasonRepo->add($this->vendorRegistrationSettingService->getVendorRegistrationReasonData(request:$request));
        ToastMagic::success(translate('vendor_registration_reason_added_successfully'));
        return redirect()->back();
    }
    public function getUpdateView(Request $request):JsonResponse
    {
        $vendorRegistrationReason = $this->vendorRegistrationReasonRepo->getFirstWhere(params: ['id'=>$request['id']]);
        return response()->json(['view'=>view('admin-views.business-settings.vendor-registration-setting.partial.update-reason-modal', compact('vendorRegistrationReason'))->render()]);
    }
    public function update(VendorRegistrationReasonRequest $request): RedirectResponse
    {
        $this->vendorRegistrationReasonRepo->update(id:$request['id'],data:$this->vendorRegistrationSettingService->getVendorRegistrationReasonData(request:$request));
        ToastMagic::success(translate('vendor_registration_reason_update_successfully'));
        return redirect()->back();
    }

    public function delete(Request $request): RedirectResponse
    {
        $this->vendorRegistrationReasonRepo->delete(params: ['id' => $request['id']]);
        ToastMagic::success(translate('vendor_registration_reason_deleted_successfully'));
        return redirect()->back();
    }

    public function updateStatus(Request $request): JsonResponse
    {
        $vendorReason = $this->vendorRegistrationReasonRepo->getFirstWhere(params: ['id'=>$request->id]);
        $this->vendorRegistrationReasonRepo->update(id:$request['id'], data: ['status' => $vendorReason['status'] ? 0:1]);
        return response()->json([ 'message' => translate('vendor_registration_reason_status_changed_successfully')]);
    }
}
