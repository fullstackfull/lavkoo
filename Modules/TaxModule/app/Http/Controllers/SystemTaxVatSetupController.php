<?php

namespace Modules\TaxModule\app\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Modules\TaxModule\app\Http\Requests\SystemTaxSetupUpdateRequest;
use Modules\TaxModule\app\Models\SystemTaxSetup;
use Modules\TaxModule\app\Models\Tax;
use Modules\TaxModule\app\Models\TaxAdditionalSetup;
use Modules\TaxModule\app\Services\SystemTaxSetupService;
use Modules\TaxModule\app\Services\TaxService;
use Modules\TaxModule\app\Traits\VatTaxConfiguration;

class SystemTaxVatSetupController extends Controller
{
    use VatTaxConfiguration;

    private Tax $taxVat;
    private SystemTaxSetup $systemTaxVat;

    public function __construct(
        private readonly TaxService $taxService,
        private readonly SystemTaxSetupService $systemTaxSetupService,
        private readonly TaxAdditionalSetup $taxAdditionalSetup
    )
    {
    }

    public function index(Request $request): Renderable
    {
        $tax_payer = 'vendor';
        $systemTaxVat = SystemTaxSetup::with('additionalData')
            ->where('tax_payer', $tax_payer)
            ->first();

        if (!$systemTaxVat) {
            $systemTaxVat = self::getAddInitSystemVatTax();
        }

        $taxVats = Tax::where('is_active', 1)
            ->when($this->getCountryType() == 'single', function ($query) {
                return $query->where('is_default', true);
            })
            ->when(isset($request['country_code']), function ($query) use ($request) {
                return $query->where('country_code', $request['country_code']);
            })
            ->latest()->get();

        $country_code = null;
        $systemData = $this->getProjectWiseSystemData();

        return view('taxmodule::6valley.tax.system_tax_setup', [
            'taxVats' => $taxVats,
            'systemTaxVat' => $systemTaxVat,
            'country_code' => $country_code,
            'systemData' => $systemData,
            'tax_payer' => $tax_payer
        ]);
    }


    public function systemTaxVatStore(SystemTaxSetupUpdateRequest $request): RedirectResponse
    {
        $data = $this->systemTaxSetupService->getSystemTaxSetupData(request: $request);
        SystemTaxSetup::where('id', $request['id'])->update($data);
        $systemTaxVat = SystemTaxSetup::where('id', $request['id'])->first();

        foreach ($this->getProjectWiseSystemData($systemTaxVat->tax_payer == 'vendor' ? 'additional_tax' : 'additional_tax_' . $systemTaxVat->tax_payer) ?? [] as $item) {
            $taxOnAdditionalData = $this->taxAdditionalSetup->where('system_tax_setup_id', $systemTaxVat->id)->where('name', $item)->firstOrNew();
            $taxOnAdditionalData->name = $item;
            $taxOnAdditionalData->system_tax_setup_id = $systemTaxVat->id;
            $taxOnAdditionalData->is_active = isset($request->additional_status[$item]) && array_key_exists($item, $request->additional_status) && !empty($request->additional[$item]) ? 1 : 0;
            $taxOnAdditionalData->tax_ids = $request->additional[$item] ?? [];
            $taxOnAdditionalData->save();
        }

        \Modules\TaxModule\app\Services\SystemTaxSetupService::clearTaxSystemTypeCache();
        $this->showNotification('successMessage', translate('Tax_Settings_Updated_Successfully'));
        return back();
    }


    public function vendorStatus(Request $request): JsonResponse|RedirectResponse
    {
        $taxPayer = $request['tax_payer'] ?? 'vendor';
        if ($request['id'] == null) {
            $systemTaxVat = SystemTaxSetup::when($this->getCountryType() == 'single', function ($query) {
                $query->where('is_default', true);
            }, function ($query) use ($request) {
                $query->where('country_code', $request['country_code']);
            })
            ->where('tax_payer', $taxPayer)
            ->first();
        } else {
            $systemTaxVat = SystemTaxSetup::find($request['id']);
        }

        if (!$systemTaxVat) {
            $systemTaxVat = new $this->systemTaxVat;
            $systemTaxVat->is_default = true;
            $systemTaxVat->is_included = true;
            if ($this->getCountryType() !== 'single') {
                $systemTaxVat->country_code = $request['country_code'] ?? $systemTaxVat?->country_code;
                $systemTaxVat->is_default = false;
            }
            $systemTaxVat->tax_payer = $taxPayer;
            $systemTaxVat->tax_type = $request['tax_type'] ?? 'order_wise';
        }
        $systemTaxVat->is_active = !$systemTaxVat->is_active;
        $systemTaxVat->save();

        if ($request->ajax()) {
            return response()->json([
                'id' => $systemTaxVat->id,
                'status' => $systemTaxVat->is_active,
                'message' => translate('vendor_tax_status_updated')
            ]);
        }

        \Modules\TaxModule\app\Services\SystemTaxSetupService::clearTaxSystemTypeCache();
        $this->showNotification('successMessage', translate('vendor_tax_status_updated'));
        return back();
    }
}
