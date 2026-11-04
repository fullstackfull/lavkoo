<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Contracts\Repositories\BusinessSettingRepositoryInterface;
use App\Contracts\Repositories\HelpTopicRepositoryInterface;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\VendorBusinessProcessRequest;
use App\Http\Requests\Admin\VendorRegistrationHeaderRequest;
use App\Http\Requests\DownloadAppUpdateSectionRequest;
use App\Http\Requests\SellWithUsUpdateSectionRequest;
use App\Repositories\VendorRegistrationReasonRepository;
use App\Services\VendorRegistrationSettingService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class VendorRegistrationSettingController extends BaseController
{
    public function __construct(
        private readonly BusinessSettingRepositoryInterface $businessSettingRepo,
        private readonly VendorRegistrationSettingService   $vendorRegistrationSettingService,
        private readonly VendorRegistrationReasonRepository $vendorRegistrationReasonRepo,
        private readonly HelpTopicRepositoryInterface       $helpTopicRepo

    )
    {
    }

    public function index(?Request $request, ?string $type = null): View|Collection|LengthAwarePaginator|null|callable|RedirectResponse
    {
        $vendorRegistrationHeader = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'vendor_registration_header'])['value']);
        return view('admin-views.business-settings.vendor-registration-setting.header', compact('vendorRegistrationHeader'));
    }

    public function getSellWithUsView(Request $request): View
    {
        $vendorRegistrationReasons = $this->vendorRegistrationReasonRepo->getListWhere(orderBy: ['id' => 'desc'], searchValue: requestString('searchValue') ?: null, dataLimit: 10);
        $sellWithUs = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'vendor_registration_sell_with_us'])['value']);
        return view('admin-views.business-settings.vendor-registration-setting.with-us', compact('sellWithUs', 'vendorRegistrationReasons'));
    }

    public function getBusinessProcessView(): View
    {
        $businessProcess = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'business_process_main_section'])['value']);
        $businessProcessStep = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'business_process_step'])['value']);
        return view('admin-views.business-settings.vendor-registration-setting.business-process', compact('businessProcess', 'businessProcessStep'));
    }

    public function getDownloadAppView(): View
    {
        $downloadVendorApp = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'download_vendor_app'])?->value ?? "");
        return view('admin-views.business-settings.vendor-registration-setting.download-app', compact('downloadVendorApp'));
    }

    public function getFAQView(Request $request): View
    {
        $helps = $this->helpTopicRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            searchValue: requestString('searchValue') ?: null,
            filters: ['type' => 'vendor_registration'],
            dataLimit: 10);
        return view('admin-views.business-settings.vendor-registration-setting.faq', compact('helps'));
    }

    public function updateHeaderSection(VendorRegistrationHeaderRequest $request): RedirectResponse
    {
        $vendorRegistrationHeader = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'vendor_registration_header'])['value']);
        $this->businessSettingRepo->updateOrInsert(type: 'vendor_registration_header',
            value: $this->vendorRegistrationSettingService->getHeaderAndSellWithUsUpdateData(request: $request, image: $vendorRegistrationHeader->image ?? null));
        ToastMagic::success(translate('updated_successfully'));
        return redirect()->back();
    }

    public function updateSellWithUsSection(SellWithUsUpdateSectionRequest $request): RedirectResponse
    {
        $sellWithUs = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'vendor_registration_sell_with_us'])['value']);
        $this->businessSettingRepo->updateOrInsert(type: 'vendor_registration_sell_with_us',
            value: $this->vendorRegistrationSettingService->getHeaderAndSellWithUsUpdateData(request: $request, image: $sellWithUs->image ?? null));
        ToastMagic::success(translate('updated_successfully'));
        return redirect()->back();
    }

    public function updateBusinessProcess(VendorBusinessProcessRequest $request): RedirectResponse
    {
        $this->businessSettingRepo->updateOrInsert(
            type: 'business_process_main_section',
            value: $this->vendorRegistrationSettingService->getBusinessProcessUpdateData(request: $request));
        $businessProcessStep = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'business_process_step'])['value']);
        $this->businessSettingRepo->updateOrInsert(type: 'business_process_step',
            value: $this->vendorRegistrationSettingService->getBusinessProcessStepUpdateData(request: $request, businessProcessStep: $businessProcessStep));
        ToastMagic::success(translate('updated_successfully'));
        return redirect()->back();
    }

    public function updateDownloadAppSection(DownloadAppUpdateSectionRequest $request): RedirectResponse
    {
        $downloadVendorApp = json_decode($this->businessSettingRepo->getFirstWhere(params: ['type' => 'download_vendor_app'])?->value ?? '', true);

        $this->businessSettingRepo->updateOrInsert(type: 'download_vendor_app',
            value: json_encode($this->vendorRegistrationSettingService->getDownloadVendorAppUpdateData(request: $request, data: $downloadVendorApp ?? null)));
        ToastMagic::success(translate('updated_successfully'));
        return redirect()->back();
    }

}
