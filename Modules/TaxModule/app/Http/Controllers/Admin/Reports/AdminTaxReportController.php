<?php

namespace Modules\TaxModule\app\Http\Controllers\Admin\Reports;

use App\Models\OrderTransaction;
use Carbon\Carbon;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Modules\TaxModule\app\Exports\AdminTaxReportDetailsExport;
use Modules\TaxModule\app\Exports\AdminTaxReportExport;
use Modules\TaxModule\app\Models\SystemTaxSetup;
use Modules\TaxModule\app\Models\Tax;
use Modules\TaxModule\app\Models\TaxAdditionalSetup;
use Modules\TaxModule\app\Services\SystemTaxSetupService;
use Modules\TaxModule\app\Services\TaxService;
use Modules\TaxModule\app\Traits\AdminTaxReportManagement;
use Modules\TaxModule\app\Traits\VatTaxConfiguration;
use PhpOffice\PhpSpreadsheet\Exception;

class AdminTaxReportController extends Controller
{
    use VatTaxConfiguration, AdminTaxReportManagement;

    private Tax $taxVat;
    private SystemTaxSetup $systemTaxVat;

    public function __construct(
        private readonly TaxService            $taxService,
        private readonly SystemTaxSetupService $systemTaxSetupService,
        private readonly TaxAdditionalSetup    $taxAdditionalSetup
    )
    {
    }

    public static function getReportList(object|array $request, $startDate, $endDate)
    {
        $taxRates = self::getTaxRates($request);
        $taxOnDeliveryChargeCommission = $taxRates['tax_on_delivery_charge_commission'];
        $taxOnOrderCommission = $taxRates['tax_on_order_commission'];

        $reportList = [];
        if (!empty($request['date_range_type']) && !empty($request['calculate_tax_on'])) {
            $combinedResults = self::getOrderTaxes($request, $taxOnDeliveryChargeCommission, $taxOnOrderCommission, $startDate, $endDate);
            foreach ($combinedResults as $resultKey => $result) {
                $taxRateIds = [];
                if ($request['calculate_tax_on'] == 'all_source') {
                    $taxRateIds = $taxRates['tax_on_all_source'];
                } else if ($request['calculate_tax_on'] == 'individual_source') {
                    if ($resultKey == 'admin_commission') {
                        $taxRateIds = $taxRates['tax_on_order_commission'];
                    } elseif ($resultKey == 'delivery_charge') {
                        $taxRateIds = $taxRates['tax_on_delivery_charge_commission'];
                    }
                }

                $taxes = [];

                foreach ($taxRateIds as $taxItem) {
                    $taxes[] = [
                        'name' => $taxItem['name'],
                        'tax_rate' => $taxItem['tax_rate'],
                        'applicable_amount' => ($taxItem['tax_rate'] * $result['amount']) / 100,
                    ];
                }

                $reportList[] = [
                    'type' => $resultKey,
                    'amount' => $result['amount'],
                    'total_tax_percentage' => collect($taxRateIds)?->sum('tax_rate'),
                    'total_tax_amount' => collect($taxes)?->sum('applicable_amount'),
                    'taxes' => $taxes,
                    'transactions' => $result['transactions'] ?? []
                ];
            }
        }
        return $reportList;
    }

    public function getTaxReport(Request $request)
    {
        if (isset($request['calculate_tax_on']) && !isset($request['date_range_type'])) {
            ToastMagic::error(translate('Please_select_a_date_range_type.'));
            return redirect()->route('admin.report.get-tax-report');
        }

        $dateRange = $this->getTaxReportDateRange(type: $request['date_range_type'], dates: $request['dates']);

        list($startDate, $endDate) = explode(' - ', $dateRange);
        $startDate = Carbon::createFromFormat('m/d/Y', trim($startDate));
        $endDate = Carbon::createFromFormat('m/d/Y', trim($endDate));
        $startDate = $startDate->startOfDay();
        $endDate = $endDate->endOfDay();

        $taxRates = $this->getTaxRates($request);
        $taxOnDeliveryChargeCommission = $taxRates['tax_on_delivery_charge_commission'];
        $taxOnOrderCommission = $taxRates['tax_on_order_commission'];

        $reportList = self::getReportList(request: $request, startDate: $startDate, endDate: $endDate);

        $totalBase = collect($reportList)->sum('amount');
        $totalTax = collect($reportList)->sum('total_tax_amount');

        $selectedTax = [
            'tax_on_delivery_charge_commission' => !isset($request->tax_rate) ? $taxOnDeliveryChargeCommission?->select('id', 'tax_rate', 'name')?->toArray() : [],
            'tax_on_order_commission' => !isset($request->tax_rate) ? $taxOnOrderCommission?->select('id', 'tax_rate', 'name')?->toArray() : [],
            'tax_rate' => Tax::all(),
        ];

        return view('taxmodule::6valley.report.admin-tax-report', [
            'date_range_type' => $request['date_range_type'],
            'startDate' => $startDate,
            'endDate' => $endDate,
            'reportList' => $reportList,
            'totalBase' => $totalBase,
            'totalTax' => $totalTax,
            'selectedTax' => $selectedTax,
            'calculate_tax_on' => $request['calculate_tax_on'],
        ]);
    }

    public static function getOrderTaxes($request, $tax_on_delivery_charge_commission, $tax_on_order_commission, $startDate, $endDate)
    {
        $taxIds = array_unique(array_merge($tax_on_delivery_charge_commission->pluck('id')->toArray(), $tax_on_order_commission->pluck('id')->toArray()));

        $orderTransactions = OrderTransaction::with(['orderTaxes.tax', 'order'])
            ->where(['status' => 'disburse'])
            ->whereHas('order', function ($query) {
                return $query->where(['order_status' => 'delivered', 'order_type' => 'default_type']);
            })
            ->where(function ($query) {
                return $query->orWhere(['delivered_by' => 'admin']);
            })
            ->when(isset($request['search']) & !empty($request['search']), function ($query) use ($request) {
                return $query->where('transaction_id', 'like', "%{$request['search']}%")
                ->orWhereHas('order', function ($query) use ($request) {
                    return $query->where('id', 'like', "%{$request['search']}%");
                })->orWhereHas('orderTaxes', function ($query) use ($request) {
                    return $query->where('tax_name', 'like', "%{$request['search']}%");
                });
            })
            ->when(!empty($startDate) && !empty($endDate), function ($query) use ($startDate, $endDate) {
                return $query->whereBetween('updated_at', [$startDate, $endDate]);
            })
            ->latest('updated_at')->get();

        $adminCommission = 0;
        $deliveryCharge = 0;
        $adminTransaction = [];
        $deliveryTransaction = [];
        foreach ($orderTransactions as $orderTransaction) {
            if ($orderTransaction['admin_commission'] > 0) {
                $adminTransaction[] = $orderTransaction;
                $adminCommission += $orderTransaction['admin_commission'];
            }
            if (($orderTransaction['seller_is'] == 'admin' || $orderTransaction['order']['shipping_responsibility'] == 'inhouse_shipping') && $orderTransaction['delivery_charge'] > 0) {
                $deliveryTransaction[] = $orderTransaction;
                $deliveryCharge += $orderTransaction['delivery_charge'];
            }
        }

        return [
            'admin_commission' => [
                'amount' => $adminCommission,
                'transactions' => $adminTransaction,
            ],
            'delivery_charge' => [
                'amount' => $deliveryCharge,
                'transactions' => $deliveryTransaction,
            ],
        ];
    }

    public function getTaxDetails(Request $request)
    {
        $dateRange = $this->getTaxReportDateRange(type: $request['date_range_type'], dates: $request['dates']);
        list($startDate, $endDate) = explode(' - ', $dateRange);
        $startDate = Carbon::createFromFormat('m/d/Y', trim($startDate));
        $endDate = Carbon::createFromFormat('m/d/Y', trim($endDate));
        $startDate = $startDate->startOfDay();
        $endDate = $endDate->endOfDay();

        $getTaxRates = $this->getTaxRates($request);
        $reportList = self::getReportList(request: $request, startDate: $startDate, endDate: $endDate);
        $baseData = collect($reportList)->firstWhere('type', $request->source);
        $totalAmount = 0;
        if ($request->source == 'admin_commission') {
            $totalAmount = collect($baseData['transactions'])->sum('admin_commission');
            $taxRates = $getTaxRates['tax_on_order_commission'];
        } else if ($request->source == 'delivery_charge') {
            $totalAmount = collect($baseData['transactions'])->sum('delivery_charge');
            $taxRates = $getTaxRates['tax_on_delivery_charge_commission'];
        } else {
            $taxRates = $getTaxRates['tax_on_all_source'];
        }

        $transactionCollection = collect($baseData['transactions']);
        $page = request('page', 1);
        $perPage = 10;
        $transactions = new LengthAwarePaginator(
            $transactionCollection->forPage($page, $perPage)->values(),
            $transactionCollection->count(),
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query()
            ]
        );

        return view('taxmodule::6valley.report.admin-tax-report-details', [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'date_range_type' => $request['date_range_type'],
            'calculate_tax_on' => $baseData['total_tax_percentage'],
            'totalTaxAmount' => $baseData['total_tax_amount'],
            'totalOrderAmount' => collect($baseData['transactions'])->sum('order_amount'),
            'totalAmount' => $totalAmount,
            'totalOrderCount' => count($baseData['transactions']),
            'total_tax_rate' => $baseData['total_tax_percentage'],
            'taxSource' => $baseData['type'],
            'transactions' => $transactions,
            'taxRates' => $taxRates
        ]);
    }

    /**
     * @throws Exception
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    public function adminTaxDetailsExport(Request $request)
    {
        $dateRange = $this->getTaxReportDateRange(type: $request['date_range_type'], dates: $request['dates']);

        list($startDate, $endDate) = explode(' - ', $dateRange);
        $startDate = Carbon::createFromFormat('m/d/Y', trim($startDate));
        $endDate = Carbon::createFromFormat('m/d/Y', trim($endDate));
        $startDate = $startDate->startOfDay();
        $endDate = $endDate->endOfDay();

        $getTaxRates = $this->getTaxRates($request);
        $reportList = self::getReportList(request: $request, startDate: $startDate, endDate: $endDate);
        $baseData = collect($reportList)->firstWhere('type', $request->source);

        $totalAmount = 0;
        if ($request->source == 'admin_commission') {
            $totalAmount = collect($baseData['transactions'])->sum('admin_commission');
            $taxRates = $getTaxRates['tax_on_order_commission'];
        } else if ($request->source == 'delivery_charge') {
            $totalAmount = collect($baseData['transactions'])->sum('delivery_charge');
            $taxRates = $getTaxRates['tax_on_delivery_charge_commission'];
        } else {
            $taxRates = $getTaxRates['tax_on_all_source'];
        }

        $transactions = collect($baseData['transactions']);

        $data = [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'date_range_type' => $request['date_range_type'],
            'calculate_tax_on' => $baseData['total_tax_percentage'],
            'totalTaxAmount' => $baseData['total_tax_amount'],
            'totalOrderAmount' => collect($baseData['transactions'])->sum('order_amount'),
            'totalAmount' => $totalAmount,
            'totalOrderCount' => count($baseData['transactions']),
            'total_tax_rate' => $baseData['total_tax_percentage'],
            'taxSource' => $baseData['type'],
            'transactions' => $transactions,
            'taxRates' => $taxRates
        ];

        if ($request->export_type == 'excel') {
            return Excel::download(new AdminTaxReportDetailsExport($data), $baseData['type'] . ' ' . 'TaxExport.xlsx');
        } else if ($request->export_type == 'csv') {
            return Excel::download(new AdminTaxReportDetailsExport($data), $baseData['type'] . ' ' . 'TaxExport.csv');
        }

        return Excel::download(new AdminTaxReportDetailsExport($data), $baseData['type'] . ' ' . 'TaxExport.xlsx');
    }

    /**
     * @throws Exception
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    public function adminTaxReportExport(Request $request)
    {
        $dateRange = $this->getTaxReportDateRange(type: $request['date_range_type'], dates: $request['dates']);

        list($startDate, $endDate) = explode(' - ', $dateRange);
        $startDate = Carbon::createFromFormat('m/d/Y', trim($startDate));
        $endDate = Carbon::createFromFormat('m/d/Y', trim($endDate));
        $startDate = $startDate->startOfDay();
        $endDate = $endDate->endOfDay();

        $reportList = self::getReportList(request: $request, startDate: $startDate, endDate: $endDate);
        $totalBase = collect($reportList)->sum('amount');
        $totalTax = collect($reportList)->sum('total_tax_amount');

        $startDate = Carbon::parse($startDate)->toIso8601String();
        $endDate = Carbon::parse($endDate)->toIso8601String();
        $data = [
            'taxData' => $reportList,
            'search' => $request->search ?? null,
            'from' => $startDate,
            'to' => $endDate,
            'total_tax_amount' => $totalTax,
            'total_amount' => $totalBase,
        ];

        if ($request->export_type == 'excel') {
            return Excel::download(new AdminTaxReportExport($data), 'AdminTaxExport.xlsx');
        } else if ($request->export_type == 'csv') {
            return Excel::download(new AdminTaxReportExport($data), 'AdminTaxExport.csv');
        }
        return Excel::download(new AdminTaxReportExport($data), 'AdminTaxExport.xlsx');
    }
}
