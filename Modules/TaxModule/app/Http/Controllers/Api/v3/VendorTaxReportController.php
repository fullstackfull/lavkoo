<?php

namespace Modules\TaxModule\app\Http\Controllers\Api\v3;

use App\Contracts\Repositories\ShopRepositoryInterface;
use App\Models\Order;
use App\Models\OrderTransaction;
use App\Models\Shop;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Modules\TaxModule\app\Http\Controllers\Vendor\Reports\VendorTaxExport;
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


    public static function apiTaxReportDateRange($request): string
    {
        if (isset($request['start_date']) && !is_null($request['start_date']) && isset($request['end_date']) && !is_null($request['end_date'])) {
            $startFormatted = \Carbon\Carbon::parse($request['start_date'])->format('m/d/Y');
            $endFormatted = \Carbon\Carbon::parse($request['end_date'])->format('m/d/Y');
            $dateRange = $startFormatted . ' - ' . $endFormatted;
        } else {
            $dateRange = now()->subDays(6)->format('m/d/Y') . ' - ' . now()->format('m/d/Y');
        }
        return $dateRange;
    }

    public function vendorWiseTaxes(Request $request)
    {
        $vendor = $request['seller'];
        $dateRange = $this->apiTaxReportDateRange(request: $request);
        list($startDate, $endDate) = explode(' - ', $dateRange);
        $startDate = Carbon::createFromFormat('m/d/Y', trim($startDate));
        $endDate = Carbon::createFromFormat('m/d/Y', trim($endDate));
        $startDate = $startDate->startOfDay();
        $endDate = $endDate->endOfDay();

        $shop = $this->shopRepo->getFirstWhere(params: ['author_type' => 'vendor', 'seller_id' => $vendor['id']]);
        $orderTransactions = OrderTransaction::with(['seller', 'shop.seller', 'orderTaxes.tax', 'order'])
            ->where(['status' => 'disburse'])
            ->whereHas('order', function ($query) {
                return $query->where(['order_status' => 'delivered', 'order_type' => 'default_type']);
            })
            ->when(isset($shop['id']), function ($query) use ($request, $shop) {
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
            ->orderBy('created_at', 'desc')
            ->get();

        $typeWiseTaxesList = [];
        foreach ($orderTransactions->pluck('orderTaxes')->flatten()->groupBy('tax_on')->sortKeys() as $type => $orderTaxItem) {
            $typeName = $type == 'basic' ? 'Order Tax' : ucwords(str_replace('_', ' ', $type));

            $taxItemArr = [
                'name' => $typeName
            ];
            foreach($orderTaxItem->groupBy('tax_name') as $taxItemKey => $orderTax) {
                $taxItemArr['data'][] = [
                    'name' => ucwords($taxItemKey),
                    'tax_rate' => $orderTax->first()->tax_rate,
                    'total_amount' => $orderTax->sum('tax_amount'),
                ];
            }
            $typeWiseTaxesList[] = $taxItemArr;
        }

        $totalOrders = count($orderTransactions);
        $totalOrderAmount = $orderTransactions->sum('order_amount');
        $totalTax = $orderTransactions->sum('tax');

        $orderTransactions = $orderTransactions->map(function ($orderTransaction) {
            $orderTaxItemData = [];
            foreach($orderTransaction?->orderTaxes?->flatten()->groupBy('tax_on')->sortKeys() as $orderTaxItemKey => $orderTaxItem) {
                $taxItemData = [
                    'group_name' => $orderTaxItemKey == 'basic' ? 'Order Tax' : ucwords(str_replace('_', ' ', $orderTaxItemKey)),
                ];
                foreach($orderTaxItem->groupBy('tax_name') as $taxItemKey => $orderTax) {
                    $taxItemData['data'][] = [
                        'name' => $taxItemKey,
                        'tax_amount' => $orderTax->sum('tax_amount'),
                    ];
                }
                $orderTaxItemData[] = $taxItemData;
            }
            $data = [
                'total_vat_amount' => $orderTransaction?->sum('tax') ?? 0,
                'all_vat_groups' => $orderTaxItemData
            ];
            $orderTransaction['vat_amount_formats'] = $data;
            return $orderTransaction;
        });

        $page = $request['offset'] ?? 1;
        $perPage = $request['limit'] ?? 10;
        $orderTransactionsList = new LengthAwarePaginator(
            $orderTransactions->forPage($page, $perPage)->values(),
            $orderTransactions->count(),
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query()
            ]
        );

        return response()->json([
            'total_tax' => $totalTax,
            'total_orders' => $totalOrders,
            'total_order_amount' => $totalOrderAmount,
            'total_size' => $orderTransactionsList->total(),
            'limit' => $request['limit'],
            'offset' => $request['offset'],
            'type_wise_taxes_list' => $typeWiseTaxesList,
            'order_transactions' => $orderTransactionsList->values(),
        ]);
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

        $orderTransactions = OrderTransaction::with(['orderTaxes.tax', 'order'])
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
        $dateRange = $request->dates ?? now()->subDays(6)->format('m/d/Y') . ' - ' . now()->format('m/d/Y');
        list($startDate, $endDate) = explode(' - ', $dateRange);
        $startDate = Carbon::createFromFormat('m/d/Y', trim($startDate));
        $endDate = Carbon::createFromFormat('m/d/Y', trim($endDate));
        $startDate = $startDate->startOfDay();
        $endDate = $endDate->endOfDay();

        $shop_id = $request->id;
        $shop = is_numeric($shop_id) ? shop::select('id', 'name', 'phone')->findOrFail($shop_id) : null;


        $vendortaxData = $this->getVendortaxData($shop->id, $startDate, $endDate);
        $summary = $vendortaxData['summary'];
        $orders = $vendortaxData['orders'];

        $orders = $orders->cursor();

        $startDate = Carbon::parse($startDate)->format('d M, Y');
        $endDate = Carbon::parse($endDate)->format('d M, Y');

        $data = [
            'orders' => $orders,
            'search' => $request->search ?? null,
            'from' => $startDate,
            'to' => $endDate,
            'summary' => $summary
        ];

        if ($request->export_type == 'excel') {
            return Excel::download(new VendorTaxExport($data), $shop->name . 's TaxExport.xlsx');
        } else if ($request->export_type == 'csv') {
            return Excel::download(new VendorTaxExport($data), $shop->name . 's TaxExport.csv');
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
