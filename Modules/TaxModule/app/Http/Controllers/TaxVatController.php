<?php

namespace Modules\TaxModule\app\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Modules\TaxModule\app\Exports\TaxVatExport;
use Modules\TaxModule\app\Http\Requests\TaxAddRequest;
use Modules\TaxModule\app\Http\Requests\TaxUpdateRequest;
use Modules\TaxModule\app\Models\Tax;
use Modules\TaxModule\app\Services\SystemTaxSetupService;
use Modules\TaxModule\app\Services\TaxService;
use Modules\TaxModule\app\Traits\VatTaxConfiguration;
use PhpOffice\PhpSpreadsheet\Exception;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TaxVatController extends Controller
{
    use VatTaxConfiguration;

    public function __construct(
        private readonly TaxService $taxService,
    )
    {
    }


    public function index(Request $request): Renderable
    {
        $vatTaxes = Tax::when($request->has('search'), function ($query) use ($request) {
                $keys = explode(' ', $request['search']);
                foreach ($keys as $key) {
                    $query->orWhere('name', 'LIKE', '%' . $key . '%')
                        ->orWhere('id', 'LIKE', '%' . $key . '%')
                        ->orWhere('tax_rate', 'LIKE', '%' . $key . '%');
                }
            })
            ->when($this->getCountryType() == 'single', function ($query) {
                return $query->where('is_default', true);
            })
            ->when(isset($request['country_code']), function ($query) use ($request) {
                return $query->where('country_code', $request['country_code']);
            })
            ->orderBy('id', 'desc')
            ->paginate(getWebConfig(name: 'pagination_limit'))->appends($request->all());

        $existTaxVatData = Tax::exists();

        return view('taxmodule::6valley.tax.tax_list', [
            'vatTaxes' => $vatTaxes,
            'existTaxVatData' => $existTaxVatData
        ]);
    }

    public function store(TaxAddRequest $request): RedirectResponse
    {
        Tax::insert($this->taxService->getAddTax(request: $request));
        SystemTaxSetupService::clearTaxSystemTypeCache();
        $this->showNotification('successMessage', translate('New_Tax_Added_Successfully'));
        return back();
    }

    public function update(TaxUpdateRequest $request): RedirectResponse
    {
        Tax::where('id', $request['id'])->update($this->taxService->getAddTax(request: $request));
        SystemTaxSetupService::clearTaxSystemTypeCache();
        $this->showNotification('successMessage', translate('updated_successfully'));
        return to_route('admin.vat-tax.index');
    }

    public function updateStatus(Request $request): JsonResponse|RedirectResponse
    {
        $taxVat = Tax::where('id', $request['id'])->first();
        Tax::where('id', $request['id'])->update(['is_active' => !$taxVat['is_active']]);
        SystemTaxSetupService::clearTaxSystemTypeCache();
        if ($request->ajax()) {
            return response()->json([
                'id' => $taxVat['id'],
                'status' => $taxVat['is_active'],
                'message' => translate('tax_status_updated')
            ]);
        }
        $this->showNotification('successMessage', translate('updated_successfully'));
        return to_route('admin.vat-tax.index');
    }

    /**
     * @throws Exception
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    public function export(Request $request): BinaryFileResponse
    {
        $vatTaxes = Tax::when($request->has('search'), function ($query) use ($request) {
                $keys = explode(' ', $request['search']);
                foreach ($keys as $key) {
                    $query->orWhere('name', 'LIKE', '%' . $key . '%')->orWhere('tax_rate', 'LIKE', '%' . $key . '%');
                }
            })
            ->when($this->getCountryType() == 'single', function ($query) {
                return $query->where('is_default', true);
            })
            ->when(isset($request['country_code']), function ($query) use ($request) {
                return $query->where('country_code', $request['country_code']);
            })
            ->latest()->get();

        $data = [
            'data' => $vatTaxes,
            'search' => $request['search'] ?? null,
        ];

        if ($request['type'] == 'csv') {
            return Excel::download(new TaxVatExport($data), 'TaxList.csv');
        }
        return Excel::download(new TaxVatExport($data), 'TaxList.xlsx');
    }
}
