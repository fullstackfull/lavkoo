<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Contracts\Repositories\BusinessSettingRepositoryInterface;
use App\Enums\ViewPaths\Admin\InvoiceSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InvoiceSettingRequest;
use App\Services\BusinessSettingService;
use App\Traits\FileManagerTrait;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class InvoiceSettingsController extends Controller
{
    use FileManagerTrait {
        delete as deleteFile;
        update as updateFile;
    }

    public function __construct(
        private readonly BusinessSettingRepositoryInterface $businessSettingRepo,
        private readonly BusinessSettingService             $businessSettingService,
    )
    {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $invoiceSettings = getWebConfig(name: 'invoice_settings');
        if (!$invoiceSettings) {
            $invoiceSettings = $this->businessSettingService->getInvoiceSettingsData(request: null, imageArray: ['image_name' => '', 'storage' => 'public']);
            $this->businessSettingRepo->updateOrInsert(type: 'invoice_settings', value: json_encode($invoiceSettings));
            $invoiceSettings = getWebConfig(name: 'invoice_settings');
        }
        return view('admin-views.business-settings.invoice-settings.index', compact('invoiceSettings'));
    }

    public function update(InvoiceSettingRequest $request)
    {
        if ($request['business_identity_status'] && empty($request['business_identity_value'])) {
            ToastMagic::error(translate('business_identity_value_required'), translate('Cannot_enable_business_identity_status_without_identity_value'));
            return redirect()->back();
        }

        $invoiceSettings = getWebConfig(name: 'invoice_settings');

        if (isset($invoiceSettings['image']) && $request->has('image')) {
            $image = $this->updateFile(dir: 'company/', oldImage: (is_array($invoiceSettings['image']) ? $invoiceSettings['image']['image_name'] : $invoiceSettings['image']), format: 'webp', image: $request->file('image'));
        } else {
            $image = ($request->has('image') ? $this->upload(dir: 'company/', format: 'webp', image: $request->file('image')) : (isset($invoiceSettings['image']['image_name']) ? $invoiceSettings['image']['image_name'] : ''));
        }
        $imageArray = [
            'image_name' => $image,
            'storage' => config('filesystems.disks.default') ?? 'public'
        ];

        $value = $this->businessSettingService->getInvoiceSettingsData(request: $request, imageArray: $imageArray);
        $this->businessSettingRepo->updateOrInsert(type: 'invoice_settings', value: json_encode($value));
        clearWebConfigCacheKeys();

        ToastMagic::success(translate('Invoice_settings_updated_successfully'));
        return redirect()->back();
    }
}
