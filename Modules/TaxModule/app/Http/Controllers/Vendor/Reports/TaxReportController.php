<?php

namespace Modules\TaxModule\app\Http\Controllers\Vendor\Reports;

use App\Contracts\Repositories\ShopRepositoryInterface;
use App\Models\OrderTransaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Modules\TaxModule\app\Exports\VendorTaxExport;
use Modules\TaxModule\app\Models\SystemTaxSetup;
use Modules\TaxModule\app\Models\Tax;
use Modules\TaxModule\app\Models\TaxAdditionalSetup;
use Modules\TaxModule\app\Services\SystemTaxSetupService;
use Modules\TaxModule\app\Services\TaxService;
use Modules\TaxModule\app\Traits\AdminTaxReportManagement;
use Modules\TaxModule\app\Traits\VatTaxConfiguration;

class TaxReportController extends Controller
{
    use VatTaxConfiguration, AdminTaxReportManagement;

    private Tax $taxVat;
    private SystemTaxSetup $systemTaxVat;

    public function __construct(
        private readonly TaxService              $taxService,
        private readonly SystemTaxSetupService   $systemTaxSetupService,
        private readonly TaxAdditionalSetup      $taxAdditionalSetup,
        private readonly ShopRepositoryInterface $shopRepo
    )
    {
    }

    public function vendorTaxReportList(Request $request)
    {
        $dateRange = $this->getTaxReportDateRange(dates: $request['dates']);

        list($startDate, $endDate) = explode(' - ', $dateRange);
        $startDate = Carbon::createFromFormat('m/d/Y', trim($startDate));
        $endDate = Carbon::createFromFormat('m/d/Y', trim($endDate));
        $startDate = $startDate->startOfDay();
        $endDate = $endDate->endOfDay();

        $seller = auth()->guard('seller')->user();

        $shop = $this->shopRepo->getFirstWhere(params: ['seller_id' => $seller['id']]);

        $orderTransactions = OrderTransaction::with(['seller', 'shop.seller', 'orderTaxes.tax', 'order'])
            ->where(['status' => 'disburse'])
            ->whereHas('order', function ($query) {
                return $query->where(['order_status' => 'delivered', 'order_type' => 'default_type']);
            })
            ->when($shop, function ($query) use ($request, $shop) {
                return $query->where('shop_id', $shop['id']);
            })
            ->when(isset($request['search']) & !empty($request['search']), function ($query) use ($request) {
                return $query->whereHas('order', function ($query) use ($request) {
                    return $query->where('id', 'like', "%{$request['search']}%");
                })->orWhereHas('orderTaxes', function ($query) use ($request) {
                    return $query->where('tax_name', 'like', "%{$request['search']}%");
                });
            })
            ->when(!empty($startDate) && !empty($endDate), function ($query) use ($startDate, $endDate) {
                return $query->whereBetween('updated_at', [$startDate, $endDate]);
            })
            ->orderBy('created_at', 'desc')
            ->get();

        $typeWiseTaxesList = [];
        foreach ($orderTransactions->pluck('orderTaxes')->flatten()->groupBy('tax_on')->sortKeys() as $type => $orderTaxItem) {
            $typeName = $type == 'basic' ? 'Order Tax' : ucwords(str_replace('_', ' ', $type));

            foreach($orderTaxItem->groupBy('tax_name') as $taxItemKey => $orderTax) {
                $typeWiseTaxesList[$typeName][] = [
                    'name' => ucwords($taxItemKey),
                    'tax_rate' => $orderTax->first()->tax_rate,
                    'total_amount' => $orderTax->sum('tax_amount'),
                ];
            }
        }


        $totalOrders = count($orderTransactions);
        $totalOrderAmount = $orderTransactions->sum('order_amount');
        $totalTax = $orderTransactions->sum('tax');

        $page = request('page', 1);
        $perPage = 10;
        $orderTransactions = new LengthAwarePaginator(
            $orderTransactions->forPage($page, $perPage)->values(),
            $orderTransactions->count(),
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query()
            ]
        );

        return view('taxmodule::6valley.vendor.vendor-tax-report', [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'shop' => $shop,
            'totalTax' => $totalTax,
            'totalOrders' => $totalOrders,
            'totalOrderAmount' => $totalOrderAmount,
            'orderTransactions' => $orderTransactions,
            'typeWiseTaxesList' => $typeWiseTaxesList,
            'search' => $request->search ?? null,
        ]);
    }

    public function vendorTaxExport(Request $request)
    {
        $dateRange = $this->getTaxReportDateRange(dates: $request['dates']);

        list($startDate, $endDate) = explode(' - ', $dateRange);
        $startDate = Carbon::createFromFormat('m/d/Y', trim($startDate));
        $endDate = Carbon::createFromFormat('m/d/Y', trim($endDate));
        $startDate = $startDate->startOfDay();
        $endDate = $endDate->endOfDay();

        $seller = auth()->guard('seller')->user();
        $shop = $this->shopRepo->getFirstWhere(params: ['seller_id' => $seller['id']]);

        $orderTransactions = OrderTransaction::with(['seller', 'shop.seller', 'orderTaxes.tax', 'order'])
            ->where(['status' => 'disburse'])
            ->whereHas('order', function ($query) {
                return $query->where(['order_status' => 'delivered', 'order_type' => 'default_type']);
            })
            ->when($shop, function ($query) use ($request, $shop) {
                return $query->where('shop_id', $shop['id']);
            })
            ->when(isset($request['search']) & !empty($request['search']), function ($query) use ($request) {
                return $query->whereHas('shop', function ($query) use ($request) {
                    return $query->where('name', 'like', "%{$request['search']}%");
                });
            })
            ->when(!empty($startDate) && !empty($endDate), function ($query) use ($startDate, $endDate) {
                return $query->whereBetween('updated_at', [$startDate, $endDate]);
            })
            ->latest('updated_at')->get();

        $totalOrders = count($orderTransactions);
        $totalOrderAmount = $orderTransactions->sum('order_amount');
        $totalTax = $orderTransactions->sum('tax');

        $data = [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'shop' => $shop,
            'totalTax' => $totalTax,
            'totalOrders' => $totalOrders,
            'totalOrderAmount' => $totalOrderAmount,
            'orderTransactions' => $orderTransactions,
            'search' => $request->search ?? null,
        ];

        if ($request->export_type == 'excel') {
            return Excel::download(new VendorTaxExport($data), $shop['name'] . 's TaxExport.xlsx');
        } else if ($request->export_type == 'csv') {
            return Excel::download(new VendorTaxExport($data), $shop['name'] . 's TaxExport.csv');
        }
    }

}
