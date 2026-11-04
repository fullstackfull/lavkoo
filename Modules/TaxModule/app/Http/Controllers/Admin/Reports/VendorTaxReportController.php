<?php

namespace Modules\TaxModule\app\Http\Controllers\Admin\Reports;

use App\Contracts\Repositories\ShopRepositoryInterface;
use App\Models\Order;
use App\Models\OrderTransaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Modules\TaxModule\app\Exports\VendorTaxExport;
use Modules\TaxModule\app\Exports\VendorWiseTaxExport;
use Modules\TaxModule\app\Models\SystemTaxSetup;
use Modules\TaxModule\app\Models\Tax;
use Modules\TaxModule\app\Models\TaxAdditionalSetup;
use Modules\TaxModule\app\Services\SystemTaxSetupService;
use Modules\TaxModule\app\Services\TaxService;
use Modules\TaxModule\app\Traits\AdminTaxReportManagement;
use Modules\TaxModule\app\Traits\VatTaxConfiguration;

class VendorTaxReportController extends Controller
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
        DB::statement("SET sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''));");
    }

    public function getVendorWiseTaxes(Request $request)
    {
        $dateRange = $this->getTaxReportDateRange(dates: $request['dates']);

        list($startDate, $endDate) = explode(' - ', $dateRange);
        $startDate = Carbon::createFromFormat('m/d/Y', trim($startDate));
        $endDate = Carbon::createFromFormat('m/d/Y', trim($endDate));
        $startDate = $startDate->startOfDay();
        $endDate = $endDate->endOfDay();

        return OrderTransaction::with(['seller', 'shop.seller', 'orderTaxes.tax', 'order'])
            ->where(['status' => 'disburse'])
            ->whereHas('order', function ($query) {
                return $query->where('order_status', 'delivered');
            })
            ->when(isset($request['shop_id']) & $request['shop_id'] !== 'all', function ($query) use ($request) {
                return $query->where('shop_id', $request['shop_id']);
            })
            ->when(isset($request['search']) & !empty($request['search']), function ($query) use ($request) {
                return $query->whereHas('shop', function ($query) use ($request) {
                    return $query->where('name', 'like', "%{$request['search']}%")
                        ->orWhere('contact', 'like', "%{$request['search']}%");
                })->orWhereHas('orderTaxes', function ($query) use ($request) {
                    return $query->where('tax_name', 'like', "%{$request['search']}%");
                });
            })
            ->when(!empty($startDate) && !empty($endDate), function ($query) use ($startDate, $endDate) {
                return $query->whereBetween('updated_at', [$startDate, $endDate]);
            })
            ->latest('updated_at')->get();
    }

    public function vendorWiseTaxes(Request $request)
    {
        $dateRange = $this->getTaxReportDateRange(dates: $request['dates']);
        list($startDate, $endDate) = explode(' - ', $dateRange);
        $startDate = Carbon::createFromFormat('m/d/Y', trim($startDate));
        $endDate = Carbon::createFromFormat('m/d/Y', trim($endDate));
        $startDate = $startDate->startOfDay();
        $endDate = $endDate->endOfDay();

        $shops = $this->shopRepo->getListWithScope(scope: 'active', filters: ['author_type' => 'vendor'], dataLimit: 'all');
        $inhouseShop = getInHouseShopConfig();
        $shops = $shops->prepend($inhouseShop);

        $orderTransactions = self::getVendorWiseTaxes(request: $request);
        $totalOrders = count($orderTransactions);
        $totalOrderAmount = $orderTransactions->sum('order_amount');
        $totalTax = $orderTransactions->sum('tax');

        $shopTaxListCollection = $orderTransactions->groupBy('shop_id');
        $page = request('page', 1);
        $perPage = 10;
        $shopTaxList = new LengthAwarePaginator(
            $shopTaxListCollection->forPage($page, $perPage)->values(),
            $shopTaxListCollection->count(),
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query()
            ]
        );

        return view('taxmodule::6valley.report.vendor-tax-report', [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'shops' => $shops,
            'shopTaxList' => $shopTaxList,
            'totalTax' => $totalTax,
            'totalOrders' => $totalOrders,
            'totalOrderAmount' => $totalOrderAmount,
            'orderTransactions' => $orderTransactions,
            'search' => $request->search ?? null,
        ]);
    }


    public function vendorWiseTaxExport(Request $request)
    {
        $dateRange = $this->getTaxReportDateRange(dates: $request['dates']);
        list($startDate, $endDate) = explode(' - ', $dateRange);
        $startDate = Carbon::createFromFormat('m/d/Y', trim($startDate));
        $endDate = Carbon::createFromFormat('m/d/Y', trim($endDate));
        $startDate = $startDate->startOfDay();
        $endDate = $endDate->endOfDay();

        $shops = $this->shopRepo->getListWithScope(scope: 'active', filters: ['author_type' => 'vendor'], dataLimit: 'all');
        $inhouseShop = getInHouseShopConfig();
        $shops = $shops->prepend($inhouseShop);

        $orderTransactions = self::getVendorWiseTaxes(request: $request);
        $totalOrders = count($orderTransactions);
        $totalOrderAmount = $orderTransactions->sum('order_amount');
        $totalTax = $orderTransactions->sum('tax');

        $data = [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'shops' => $shops,
            'shopTaxList' => $orderTransactions->groupBy('shop_id'),
            'totalTax' => $totalTax,
            'totalOrders' => $totalOrders,
            'totalOrderAmount' => $totalOrderAmount,
            'orderTransactions' => $orderTransactions,
            'auth_type' => 'admin'
        ];

        if ($request->export_type == 'excel') {
            return Excel::download(new VendorWiseTaxExport($data), 'VendorWiseTaxExport.xlsx');
        } else if ($request->export_type == 'csv') {
            return Excel::download(new VendorWiseTaxExport($data), 'VendorWiseTaxExport.csv');
        }
    }

    public function vendorTax(Request $request)
    {
        $dateRange = $this->getTaxReportDateRange(dates: $request['dates']);

        list($startDate, $endDate) = explode(' - ', $dateRange);
        $startDate = Carbon::createFromFormat('m/d/Y', trim($startDate));
        $endDate = Carbon::createFromFormat('m/d/Y', trim($endDate));
        $startDate = $startDate->startOfDay();
        $endDate = $endDate->endOfDay();

        $shop = $this->shopRepo->getFirstWhere(params: ['id' => $request['shop_id']]);

        $orderTransactions = OrderTransaction::with(['seller', 'shop.seller', 'orderTaxes.tax', 'order'])
            ->where(['status' => 'disburse'])
            ->whereHas('order', function ($query) {
                return $query->where(['order_status' => 'delivered', 'order_type' => 'default_type']);
            })
            ->when(isset($request['shop_id']) & $request['shop_id'] !== 'all', function ($query) use ($request) {
                return $query->where('shop_id', $request['shop_id']);
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
            ->latest('updated_at')->get();

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

        return view('taxmodule::6valley.report.vendor-tax-report-details', [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'shop' => $shop,
            'totalTax' => $totalTax,
            'totalOrders' => $totalOrders,
            'totalOrderAmount' => $totalOrderAmount,
            'orderTransactions' => $orderTransactions,
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

        $shop = $this->shopRepo->getFirstWhere(params: ['id' => $request['shop_id']]);

        $orderTransactions = OrderTransaction::with(['seller', 'shop.seller', 'orderTaxes.tax', 'order'])
            ->where(['status' => 'disburse'])
            ->whereHas('order', function ($query) {
                return $query->where(['order_status' => 'delivered', 'order_type' => 'default_type']);
            })
            ->when(isset($request['shop_id']) & $request['shop_id'] !== 'all', function ($query) use ($request) {
                return $query->where('shop_id', $request['shop_id']);
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

        $fileName = $request['shop_id'] !== 'all' ? $shop['name'] . 's TaxExport' : 'All-Vendor-TaxExport';
        if ($request->export_type == 'excel') {
            return Excel::download(new VendorTaxExport($data), $fileName . '.xlsx');
        } else if ($request->export_type == 'csv') {
            return Excel::download(new VendorTaxExport($data), $fileName . '.csv');
        }
    }

    private function getVendortaxData($shop_id, $startDate, $endDate)
    {
        $summary = DB::table('orders')
            ->where('shop_id', $shop_id)
            ->whereIn('order_status', ['delivered', 'refund_requested', 'refund_request_canceled'])
            ->when($startDate && $endDate, fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
            ->selectRaw('COUNT(*) as total_orders, SUM(order_amount) as total_order_amount, SUM(total_tax_amount) as total_tax')
            ->first();

        $orders = Order::with([
            'orderTaxes' => function (MorphMany $query) {
                $query->where('order_type', Order::class)
                    ->select('id', 'order_id', 'tax_name', 'tax_amount', 'tax_on', 'tax_type');
            }
        ])
            ->where('shop_id', $shop_id)
            ->whereIn('order_status', ['delivered', 'refund_requested', 'refund_request_canceled'])
            ->when($startDate && $endDate, fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
            ->select(['id', 'order_amount', 'total_tax_amount', 'order_type', 'created_at'])
            ->latest('created_at');

        return ['summary' => $summary, 'orders' => $orders];
    }

}
