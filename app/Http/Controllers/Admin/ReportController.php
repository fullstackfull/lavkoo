<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ExportFileNames\Admin\Report;
use App\Exports\AdminEarningReportExport;
use App\Exports\VendorEarningReportExport;
use App\Services\Admin\Reports\EarningReportsService;
use App\Utils\Helpers;
use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\Order;
use App\Models\OrderTransaction;
use App\Models\Product;
use App\Models\RefundTransaction;
use App\Models\Seller;
use App\Models\SellerWallet;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Contracts\View\View as PageView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{

    public function __construct(
        private readonly EarningReportsService $earningReportsService,
    )
    {
    }

    public function admin_earning(Request $request): PageView
    {
        $from = $request['from'];
        $to = $request['to'];
        $dateType = $request['date_type'] ?? 'this_year';

        $filterData = self::earningReportCommonFilter('admin', $dateType, $from, $to);
        $inhouseEarningResult = $this->getInhouseEarningFormattedData($request, $filterData);
        $earningData = $inhouseEarningResult['statistics'];

        $totalEarningStatisticsLabel = array_values(collect(array_keys($earningData['total_earning_statistics']))->map(function ($item) use ($request) {
            if ($request['date_type'] == 'this_month') {
                return $item . ' ' . date('M');
            }
            return $item;
        })->toArray());

        return view('admin-views.report.admin-earning', [
            'earning_data' => $earningData,
            'inhouse_earn' => collect($inhouseEarningResult['formatted_data']),
            'from' => $from,
            'to' => $to,
            'date_type' => $dateType,
            'payment_data' => $this->getAdminEarningPaymentFormattedData(request: $request),
            'totalEarningStatisticsLabel' => $totalEarningStatisticsLabel,
        ]);
    }

    public function getInhouseEarningFormattedData($request, $filter_data): array
    {
        $inhouseEarn = $filter_data['earn_from_order'];
        $shippingEarn = $filter_data['shipping_earn'];
        $deliverymanIncentive = $filter_data['deliveryman_incentive'];
        $adminCommissionEarn = $filter_data['commission'];
        $refundGiven = $filter_data['refund_given'];
        $discountGiven = $filter_data['discount_given'];
        $totalTax = $filter_data['total_tax'];
        $adminBearerFreeShipping = $filter_data['admin_bearer_free_shipping'];

        $totalInhouseEarning = 0;
        $totalCommission = 0;
        $totalShippingEarn = 0;
        $totalDeliverymanIncentive = 0;
        $totalDiscountGiven = 0;
        $totalRefundGiven = 0;
        $totalTaxFinal = 0;
        $totalAdminBearerFreeShipping = 0;
        $totalEarningStatistics = [];
        $totalCommissionStatistics = [];
        $inhouseEarningFormatedArray = [];


        foreach ($inhouseEarn as $key => $earning) {
            $totalInhouseEarning += $earning;
            $totalAdminBearerFreeShipping += $adminBearerFreeShipping[$key];
            $totalShippingEarn += $shippingEarn[$key];
            $totalDeliverymanIncentive += $shippingEarn[$key];
            $totalCommission += $adminCommissionEarn[$key];
            $totalDiscountGiven += $discountGiven[$key];
            $totalTaxFinal += $totalTax[$key];
            $totalRefundGiven += $refundGiven[$key];

            $totalEarningFormated = $earning + $adminCommissionEarn[$key] + $shippingEarn[$key] + $totalTax[$key] - $discountGiven[$key] - $refundGiven[$key] - $deliverymanIncentive[$key];

            $totalCommissionStatistics[$key] = $adminCommissionEarn[$key];
            $totalEarningStatistics[$key] = $totalEarningFormated;

            $inhouseEarningFormatedArray[] = [
                'duration' => $key,
                'in_house_earning' => $earning,
                'commission_earning' => $adminCommissionEarn[$key],
                'earn_from_shipping' => $shippingEarn[$key],
                'deliveryman_incentive' => $deliverymanIncentive[$key],
                'discount_given' => $discountGiven[$key],
                'vat_tax' => $totalTax[$key],
                'refund_given' => $refundGiven[$key],
                'total_earning' => $totalEarningFormated,
            ];
        }

        $totalInHouseProducts = self::earning_common_query($request, Product::where(['added_by' => 'admin']))->count();
        $totalStores = self::earning_common_query($request, Seller::where(['status' => 'approved']))->count();

        return [
            'formatted_data' => $inhouseEarningFormatedArray,
            'statistics' => [
                'total_inhouse_earning' => $totalInhouseEarning,
                'total_admin_bearer_free_shipping' => $totalAdminBearerFreeShipping,
                'total_commission' => $totalCommission,
                'total_shipping_earn' => $totalShippingEarn,
                'total_deliveryman_incentive' => $totalDeliverymanIncentive,
                'total_discount_given' => $totalDiscountGiven,
                'total_refund_given' => $totalRefundGiven,
                'total_tax' => $totalTaxFinal,
                'total_earning_statistics' => $totalEarningStatistics,
                'total_commission_statistics' => $totalCommissionStatistics,
                'total_in_house_products' => $totalInHouseProducts,
                'total_stores' => $totalStores,
            ]
        ];
    }

    public function getAdminEarningPaymentFormattedData(object|array $request): array
    {
        $digitalPaymentQuery = Order::where(['order_status' => 'delivered'])->whereNotIn('payment_method', ['cash', 'cash_on_delivery', 'pay_by_wallet', 'offline_payment']);
        $digitalPayment = self::earning_common_query($request, $digitalPaymentQuery)->sum('init_order_amount');

        $cashPaymentQuery = Order::where(['order_status' => 'delivered'])->whereIn('payment_method', ['cash', 'cash_on_delivery']);
        $cashPayment = self::earning_common_query($request, $cashPaymentQuery)->sum('init_order_amount');

        $walletPaymentQuery = Order::where(['order_status' => 'delivered'])->where(['payment_method' => 'pay_by_wallet']);
        $walletPayment = self::earning_common_query($request, $walletPaymentQuery)->sum('init_order_amount');

        $offlinePaymentQuery = Order::where(['order_status' => 'delivered'])->where(['payment_method' => 'offline_payment']);
        $offlinePayment = self::earning_common_query($request, $offlinePaymentQuery)->sum('init_order_amount');

        $orderEditHistory = self::earning_common_query($request, Order::where(['order_status' => 'delivered'])->with(['orderEditHistory']))->get();
        $orderEditDigitalPayment = 0;
        $orderEditCashPayment = 0;
        $orderEditWalletPayment = 0;
        $orderEditOfflinePayment = 0;
        $orderEditReturnAmount = 0;

        foreach ($orderEditHistory as $editHistory) {

            $orderEditDigitalPayment += $editHistory?->orderEditHistory?->filter(function ($item) use ($request) {
                if ($item?->order_due_payment_method != null && $item?->order_due_payment_status == 'paid' && !in_array($item->order_due_payment_method, ['cash_on_delivery', 'wallet', 'offline_payment'])) {
                    return $item;
                }
                return null;
            })->sum('order_due_amount');

            $orderEditCashPayment += $editHistory?->orderEditHistory?->filter(function ($item) use ($request) {
                if ($item?->order_due_payment_method == 'cash_on_delivery' && $item?->order_due_payment_status == 'paid') {
                    return $item;
                }
                return null;
            })->sum('order_due_amount');

            $orderEditWalletPayment += $editHistory?->orderEditHistory?->filter(function ($item) use ($request) {
                if ($item?->order_due_payment_method == 'wallet' && $item?->order_due_payment_status == 'paid') {
                    return $item;
                }
                return null;
            })->sum('order_due_amount');

            $orderEditOfflinePayment += $editHistory?->orderEditHistory?->filter(function ($item) use ($request) {
                if ($item?->order_due_payment_method == 'offline_payment' && $item?->order_due_payment_status == 'paid') {
                    return $item;
                }
                return null;
            })->sum('order_due_amount');

            $orderEditReturnAmount += $editHistory?->orderEditHistory?->filter(function ($item) use ($request) {
                if ($item?->order_return_payment_status == 'returned') {
                    return $item;
                }
                return null;
            })->sum('order_return_amount');
        }

        $editTotalPayment = $orderEditCashPayment + $orderEditWalletPayment + $orderEditDigitalPayment + $orderEditOfflinePayment;
        $totalPayment = $cashPayment + $walletPayment + $digitalPayment + $offlinePayment;

        return [
            'cash_payment' => $cashPayment + $orderEditCashPayment,
            'wallet_payment' => $walletPayment + $orderEditWalletPayment,
            'offline_payment' => $offlinePayment + $orderEditOfflinePayment,
            'digital_payment' => $digitalPayment + $orderEditDigitalPayment,
            'total_payment' => ($totalPayment + $editTotalPayment) - $orderEditReturnAmount,
            'return_amount' => $orderEditReturnAmount,
        ];
    }

    public function exportAdminEarning(Request $request): BinaryFileResponse
    {
        $from = $request['from'];
        $to = $request['to'];
        $dateType = $request['date_type'] ?? 'this_year';
        $filterData = self::earningReportCommonFilter('admin', $dateType, $from, $to);
        $getEarningFormatedData = $this->getInhouseEarningFormattedData($request, $filterData);
        $data = [
            'from' => $from,
            'to' => $to,
            'dateType' => $dateType,
            'inhouseEarn' => $getEarningFormatedData['formatted_data'],
        ];
        return Excel::download(new AdminEarningReportExport($data), Report::ADMIN_EARNING_REPORT);
    }

    public function earningReportCommonFilter($type, $dateType, $from, $to)
    {
        if ($dateType == 'today') {
            return self::getEarningDataForToday(type: $type);
        } elseif ($dateType == 'this_week') {
            return self::earningReportThisWeek($type);
        } elseif ($dateType == 'this_month') {
            $currentMonthStart = date('Y-m-01');
            $currentMonthEnd = date('Y-m-t');
            $inc = 1;
            $number = date('d', strtotime($currentMonthEnd));

            return self::earningReportSameMonth($type, $currentMonthStart, $currentMonthEnd, $number, $inc);
        } elseif ($dateType == 'this_year') {
            $number = 12;
            $defaultInc = 1;
            $currentStartYear = date('Y-01-01');
            $currentEndYear = date('Y-12-31');
            $fromYear = Carbon::parse($from)->format('Y');

            return self::earningReportSameYear($type, $currentStartYear, $currentEndYear, $fromYear, $number, $defaultInc);
        } elseif ($dateType == 'custom_date' && !empty($from) && !empty($to)) {
            $startDate = Carbon::parse($from)->format('Y-m-d 00:00:00');
            $endDate = Carbon::parse($to)->format('Y-m-d 23:59:59');
            $fromYear = Carbon::parse($from)->format('Y');
            $fromMonth = Carbon::parse($from)->format('m');
            $fromDay = Carbon::parse($from)->format('d');
            $toYear = Carbon::parse($to)->format('Y');
            $toMonth = Carbon::parse($to)->format('m');
            $toDay = Carbon::parse($to)->format('d');

            if ($fromYear != $toYear) {
                return self::earningReportDifferentYear($type, $startDate, $endDate, $fromYear, $toYear);
            } elseif ($fromMonth != $toMonth) {
                return self::earningReportSameYear($type, $startDate, $endDate, $fromYear, $toMonth, $fromMonth);
            } elseif ($fromMonth == $toMonth) {
                return self::earningReportSameMonth($type, $startDate, $endDate, $toDay, $fromDay);
            }
        }
    }

    public function getEarningDataForToday($type): array
    {
        $number = 1;
        $dayName = [Carbon::today()->format('l')];

        $earnFromOrders = Order::where(['order_status' => 'delivered', 'seller_is' => $type])
            ->whereBetween('created_at', [Carbon::now()->startOfDay(), Carbon::now()->endOfDay()])
            ->selectRaw("*, DATE_FORMAT(created_at, '%W') as day")
            ->latest('created_at')->get();

        $earnFromOrder = $this->earningReportsService->getEarnFromOrderForEarningReport(
            query: $earnFromOrders,
            userType: $type,
            dataType: 'today',
            incrementNumber: 1,
        );

        $commissions = Order::where(['seller_is' => 'seller', 'order_status' => 'delivered'])
            ->whereBetween('created_at', [Carbon::now()->startOfDay(), Carbon::now()->endOfDay()])
            ->select(
                DB::raw('sum(admin_commission) as commission'),
                DB::raw("(DATE_FORMAT(created_at, '%W')) as day")
            )
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%D')"))
            ->latest('created_at')->get();

        $commission = $this->earningReportsService->getCommissionFromOrderForEarningReport(
            query: $commissions,
            userType: $type,
            dataType: 'today',
            incrementNumber: 1,
        );

        $shippingEarns = Order::where(['order_type' => 'default_type', 'order_status' => 'delivered'])
            ->whereBetween('created_at', [Carbon::now()->startOfDay(), Carbon::now()->endOfDay()])
            ->orderBy('created_at', 'desc')
            ->selectRaw("*, DATE_FORMAT(created_at, '%W') as day")
            ->get();

        $shippingEarn = $this->earningReportsService->getShippingEarnFromOrderForEarningReport(
            query: $shippingEarns,
            userType: $type,
            dataType: 'today',
            incrementNumber: 1,
        );

        $deliverymanIncentives = Order::whereHas('deliveryMan', function ($query) use ($type) {
            $query->when($type == 'admin', function ($query) {
                $query->where('seller_id', '0');
            })
                ->when($type == 'seller', function ($query) {
                    $query->where('seller_id', '!=', '0');
                });
        })
            ->select(
                DB::raw('sum(CASE WHEN delivery_type="self_delivery" AND shipping_responsibility=' . ($type == 'seller' ? '"sellerwise_shipping" AND seller_is="seller"' : '"inhouse_shipping" OR seller_is="admin"') . ' THEN deliveryman_charge ELSE 0 END) as deliveryman_incentive'),
                DB::raw("(DATE_FORMAT(created_at, '%W')) as day")
            )
            ->where(['order_type' => 'default_type', 'order_status' => 'delivered'])
            ->whereBetween('created_at', [Carbon::now()->startOfDay(), Carbon::now()->endOfDay()])
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%D')"))
            ->latest('created_at')->get();

        $deliverymanIncentive = $this->earningReportsService->getDeliverymanIncentiveFromOrderForEarningReport(
            query: $deliverymanIncentives,
            userType: $type,
            dataType: 'today',
            incrementNumber: 1,
        );

        $adminBearerFreeShippingData = Order::where(['order_type' => 'default_type', 'order_status' => 'delivered'])
            ->whereBetween('created_at', [Carbon::now()->startOfDay(), Carbon::now()->endOfDay()])
            ->select(
                DB::raw('sum(CASE WHEN is_shipping_free=1 AND free_delivery_bearer="admin" THEN extra_discount ELSE 0 END) as free_shipping_admin_bearer'),
                DB::raw("(DATE_FORMAT(created_at, '%W')) as day")
            )
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%D')"))
            ->latest('created_at')->get();

        $adminBearerFreeShipping = [];
        for ($increment = 0; $increment < $number; $increment++) {
            $adminBearerFreeShipping[$dayName[$increment]] = 0;
            foreach ($adminBearerFreeShippingData as $match) {
                if ($match['day'] == $dayName[$increment]) {
                    $adminBearerFreeShipping[$dayName[$increment]] = $match['free_shipping_admin_bearer'];
                }
            }
        }

        $discountGivenQuery = Order::where(['order_type' => 'default_type', 'order_status' => 'delivered'])
            ->whereBetween('created_at', [Carbon::now()->startOfDay(), Carbon::now()->endOfDay()])
            ->selectRaw("*, DATE_FORMAT(created_at, '%W') as day")
            ->latest('created_at')->get();

        $discountGiven = $this->earningReportsService->getDiscountGivenFromOrderForEarningReport(
            query: $discountGivenQuery,
            userType: $type,
            dataType: 'today',
            incrementNumber: 1,
        );

        $taxes = OrderTransaction::where(['status' => 'disburse', 'seller_is' => $type])
            ->whereBetween('created_at', [Carbon::now()->startOfDay(), Carbon::now()->endOfDay()])
            ->select(
                DB::raw('sum(tax) as total_tax'),
                DB::raw("(DATE_FORMAT(created_at, '%W')) as day")
            )
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%D')"))
            ->latest('created_at')->get();

        $totalTax = $this->earningReportsService->getVatTAxFromOrderForEarningReport(
            query: $taxes,
            userType: $type,
            dataType: 'today',
            incrementNumber: 1,
        );


        $refunds = RefundTransaction::where(['payment_status' => 'paid', 'paid_by' => $type])
            ->whereHas('order')
            ->whereBetween('created_at', [Carbon::now()->startOfDay(), Carbon::now()->endOfDay()])
            ->select(
                DB::raw('sum(amount) as refund_amount'),
                DB::raw("(DATE_FORMAT(created_at, '%W')) as day")
            )
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%D')"))
            ->latest('created_at')->get();

        $refundGiven = $this->earningReportsService->getRefundGivenFromOrderForEarningReport(
            query: $refunds,
            userType: $type,
            dataType: 'today',
            incrementNumber: 1,
        );

        return [
            'earn_from_order' => $earnFromOrder,
            'shipping_earn' => $shippingEarn,
            'deliveryman_incentive' => $deliverymanIncentive,
            'commission' => $commission,
            'discount_given' => $discountGiven,
            'total_tax' => $totalTax,
            'refund_given' => $refundGiven,
            'admin_bearer_free_shipping' => $adminBearerFreeShipping,
        ];
    }

    public function earning_common_query($request, $query)
    {
        $from = $request['from'];
        $to = $request['to'];
        $date_type = $request['date_type'] ?? 'this_year';

        return $query->when(($date_type == 'this_year'), function ($query) {
            return $query->whereYear('created_at', date('Y'));
        })
            ->when(($date_type == 'this_month'), function ($query) {
                return $query->whereMonth('created_at', date('m'))
                    ->whereYear('created_at', date('Y'));
            })
            ->when(($date_type == 'this_week'), function ($query) {
                return $query->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]);
            })
            ->when(($date_type == 'today'), function ($query) {
                return $query->whereBetween('created_at', [Carbon::now()->startOfDay(), Carbon::now()->endOfDay()]);
            })
            ->when(($date_type == 'custom_date' && !is_null($from) && !is_null($to)), function ($query) use ($from, $to) {
                return $query->whereDate('created_at', '>=', $from)
                    ->whereDate('created_at', '<=', $to);
            });
    }

    public function earningReportSameMonth($type, $start_date, $end_date, $number, $defaultInc)
    {
        $earnFromOrders = Order::where(['order_status' => 'delivered', 'seller_is' => $type])
            ->whereDate('created_at', '>=', $start_date)
            ->whereDate('created_at', '<=', $end_date)
            ->selectRaw("*, DATE_FORMAT(created_at, '%d') as day")
            ->latest('created_at')->get();

        $earnFromOrder = $this->earningReportsService->getEarnFromOrderForEarningReport(
            query: $earnFromOrders,
            userType: $type,
            dataType: 'this_month',
            startDate: $start_date,
            endDate: $end_date,
            incrementNumber: $number,
            defaultIncrement: (int)$defaultInc
        );

        $commissions = Order::where(['seller_is' => 'seller', 'order_status' => 'delivered'])
            ->whereDate('created_at', '>=', $start_date)
            ->whereDate('created_at', '<=', $end_date)
            ->selectRaw('sum(admin_commission) as commission, YEAR(created_at) year, MONTH(created_at) month, DAY(created_at) day')
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%D')"))
            ->latest('created_at')->get();

        $commission = $this->earningReportsService->getCommissionFromOrderForEarningReport(
            query: $commissions,
            userType: $type,
            dataType: 'this_month',
            startDate: $start_date,
            endDate: $end_date,
            incrementNumber: $number,
            defaultIncrement: (int)$defaultInc
        );

        $shippingEarns = Order::where(['order_type' => 'default_type', 'order_status' => 'delivered'])
            ->whereDate('created_at', '>=', $start_date)
            ->whereDate('created_at', '<=', $end_date)
            ->orderBy('created_at', 'desc')
            ->selectRaw("*, DATE_FORMAT(created_at, '%d') as day")
            ->get();

        $shippingEarn = $this->earningReportsService->getShippingEarnFromOrderForEarningReport(
            query: $shippingEarns,
            userType: $type,
            dataType: 'this_month',
            startDate: $start_date,
            endDate: $end_date,
            incrementNumber: $number,
            defaultIncrement: (int)$defaultInc
        );

        $deliverymanIncentives = Order::whereHas('deliveryMan', function ($query) use ($type) {
            $query->when($type == 'admin', function ($query) {
                $query->where('seller_id', '0');
            })->when($type == 'seller', function ($query) {
                $query->where('seller_id', '!=', '0');
            });
        })
            ->selectRaw('sum(CASE WHEN delivery_type="self_delivery" AND shipping_responsibility=' . ($type == 'seller' ? '"sellerwise_shipping" AND seller_is="seller"' : '"inhouse_shipping" OR seller_is="admin"') . ' THEN deliveryman_charge ELSE 0 END) as deliveryman_incentive, YEAR(created_at) year, MONTH(created_at) month, DAY(created_at) day')
            ->where(['order_type' => 'default_type', 'order_status' => 'delivered'])
            ->whereDate('created_at', '>=', $start_date)
            ->whereDate('created_at', '<=', $end_date)
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%D')"))
            ->latest('created_at')->get();

        $deliverymanIncentive = $this->earningReportsService->getDeliverymanIncentiveFromOrderForEarningReport(
            query: $deliverymanIncentives,
            userType: $type,
            dataType: 'this_month',
            startDate: $start_date,
            endDate: $end_date,
            incrementNumber: $number,
            defaultIncrement: (int)$defaultInc
        );

        $adminBearerFreeShippingData = Order::where(['order_type' => 'default_type', 'order_status' => 'delivered'])
            ->whereDate('created_at', '>=', $start_date)
            ->whereDate('created_at', '<=', $end_date)
            ->selectRaw('sum(CASE WHEN is_shipping_free=1 AND free_delivery_bearer="admin" THEN extra_discount ELSE 0 END) as free_shipping_admin_bearer, YEAR(created_at) year, MONTH(created_at) month, DAY(created_at) day')
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%D')"))
            ->latest('created_at')->get();

        $adminBearerFreeShipping = [];
        for ($inc = (int)$defaultInc; $inc <= $number; $inc++) {
            $adminBearerFreeShipping[$inc] = 0;
            foreach ($adminBearerFreeShippingData as $match) {
                if ($match['day'] == $inc) {
                    $adminBearerFreeShipping[$inc] = $match['free_shipping_admin_bearer'];
                }
            }
        }

        $discountGivenQuery = Order::where(['order_type' => 'default_type', 'order_status' => 'delivered'])
            ->whereDate('created_at', '>=', $start_date)
            ->whereDate('created_at', '<=', $end_date)
            ->selectRaw("*, DATE_FORMAT(created_at, '%d') as day")
            ->latest('created_at')->get();

        $discountGiven = $this->earningReportsService->getDiscountGivenFromOrderForEarningReport(
            query: $discountGivenQuery,
            userType: $type,
            dataType: 'this_month',
            startDate: $start_date,
            endDate: $end_date,
            incrementNumber: $number,
            defaultIncrement: (int)$defaultInc
        );

        $taxes = OrderTransaction::where(['seller_is' => $type, 'status' => 'disburse'])
            ->whereDate('created_at', '>=', $start_date)
            ->whereDate('created_at', '<=', $end_date)
            ->selectRaw('sum(tax) as total_tax, YEAR(created_at) year, MONTH(created_at) month, DAY(created_at) day')
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%D')"))
            ->latest('created_at')->get();

        $totalTax = $this->earningReportsService->getVatTAxFromOrderForEarningReport(
            query: $taxes,
            userType: $type,
            dataType: 'this_month',
            startDate: $start_date,
            endDate: $end_date,
            incrementNumber: $number,
            defaultIncrement: (int)$defaultInc
        );

        $refunds = RefundTransaction::where(['payment_status' => 'paid', 'paid_by' => $type])
            ->whereHas('order')
            ->whereDate('created_at', '>=', $start_date)
            ->whereDate('created_at', '<=', $end_date)
            ->selectRaw('sum(amount) as refund_amount, YEAR(created_at) year, MONTH(created_at) month, DAY(created_at) day')
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%D')"))
            ->latest('created_at')->get();

        $refundGiven = $this->earningReportsService->getRefundGivenFromOrderForEarningReport(
            query: $refunds,
            userType: $type,
            dataType: 'this_month',
            startDate: $start_date,
            endDate: $end_date,
            incrementNumber: $number,
            defaultIncrement: (int)$defaultInc
        );

        return [
            'earn_from_order' => $earnFromOrder,
            'admin_bearer_free_shipping' => $adminBearerFreeShipping,
            'shipping_earn' => $shippingEarn,
            'deliveryman_incentive' => $deliverymanIncentive,
            'commission' => $commission,
            'discount_given' => $discountGiven,
            'total_tax' => $totalTax,
            'refund_given' => $refundGiven,
        ];
    }

    public function earningReportThisWeek($type)
    {
        $number = 6;
        $period = CarbonPeriod::create(Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek());
        $dayName = [];
        foreach ($period as $date) {
            $dayName[] = $date->format('l');
        }

        $earnFromOrders = Order::where(['order_status' => 'delivered', 'seller_is' => $type])
            ->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])
            ->selectRaw("*, ((DAYOFWEEK(created_at) + 5) % 7) as day")
            ->latest('created_at')->get();

        $earnFromOrder = $this->earningReportsService->getEarnFromOrderForEarningReport(
            query: $earnFromOrders,
            userType: $type,
            dataType: 'this_week',
            incrementNumber: $number,
            defaultIncrement: 0
        );

        $commissions = Order::where(['seller_is' => 'seller', 'order_status' => 'delivered'])
            ->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])
            ->select(
                DB::raw('sum(admin_commission) as commission'),
                DB::raw("(DATE_FORMAT(created_at, '%W')) as day")
            )
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%D')"))
            ->latest('created_at')->get();

        $commission = $this->earningReportsService->getCommissionFromOrderForEarningReport(
            query: $commissions,
            userType: $type,
            dataType: 'this_week',
            incrementNumber: $number,
            defaultIncrement: 0
        );

        $shippingEarns = Order::where(['order_type' => 'default_type', 'order_status' => 'delivered'])
            ->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])
            ->orderBy('created_at', 'desc')
            ->selectRaw("*, ((DAYOFWEEK(created_at) + 5) % 7) as day")
            ->get();

        $shippingEarn = $this->earningReportsService->getShippingEarnFromOrderForEarningReport(
            query: $shippingEarns,
            userType: $type,
            dataType: 'this_week',
            incrementNumber: $number,
            defaultIncrement: 0
        );

        $deliverymanIncentives = Order::whereHas('deliveryMan', function ($query) use ($type) {
            $query->when($type == 'admin', function ($query) {
                $query->where('seller_id', '0');
            })->when($type == 'seller', function ($query) {
                $query->where('seller_id', '!=', '0');
            });
        })
            ->select(
                DB::raw('sum(CASE WHEN delivery_type="self_delivery" AND shipping_responsibility=' . ($type == 'seller' ? '"sellerwise_shipping" AND seller_is="seller"' : '"inhouse_shipping" OR seller_is="admin"') . ' THEN deliveryman_charge ELSE 0 END) as deliveryman_incentive'),
                DB::raw("(DATE_FORMAT(created_at, '%W')) as day")
            )
            ->where(['order_type' => 'default_type', 'order_status' => 'delivered'])
            ->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%D')"))
            ->latest('created_at')->get();

        $deliverymanIncentive = $this->earningReportsService->getDeliverymanIncentiveFromOrderForEarningReport(
            query: $deliverymanIncentives,
            userType: $type,
            dataType: 'this_week',
            incrementNumber: $number,
            defaultIncrement: 0
        );

        $adminBearerFreeShippingData = Order::where(['order_type' => 'default_type', 'order_status' => 'delivered'])
            ->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])
            ->select(
                DB::raw('sum(CASE WHEN is_shipping_free=1 AND free_delivery_bearer="admin" THEN extra_discount ELSE 0 END) as free_shipping_admin_bearer'),
                DB::raw("(DATE_FORMAT(created_at, '%W')) as day")
            )
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%D')"))
            ->latest('created_at')->get();

        $adminBearerFreeShipping = [];
        for ($inc = 0; $inc <= $number; $inc++) {
            $adminBearerFreeShipping[$dayName[$inc]] = 0;
            foreach ($adminBearerFreeShippingData as $match) {
                if ($match['day'] == $dayName[$inc]) {
                    $adminBearerFreeShipping[$dayName[$inc]] = $match['free_shipping_admin_bearer'];
                }
            }
        }

        $discountGivenQuery = Order::where(['order_type' => 'default_type', 'order_status' => 'delivered'])
            ->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])
            ->selectRaw("*, ((DAYOFWEEK(created_at) + 5) % 7) as day")
            ->latest('created_at')->get();

        $discountGiven = $this->earningReportsService->getDiscountGivenFromOrderForEarningReport(
            query: $discountGivenQuery,
            userType: $type,
            dataType: 'this_week',
            incrementNumber: $number,
            defaultIncrement: 0
        );

        $taxes = OrderTransaction::where(['status' => 'disburse', 'seller_is' => $type])
            ->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])
            ->select(
                DB::raw('sum(tax) as total_tax'),
                DB::raw("(DATE_FORMAT(created_at, '%W')) as day")
            )
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%D')"))
            ->latest('created_at')->get();

        $totalTax = $this->earningReportsService->getVatTAxFromOrderForEarningReport(
            query: $taxes,
            userType: $type,
            dataType: 'this_week',
            incrementNumber: $number,
            defaultIncrement: 0
        );

        $refunds = RefundTransaction::where(['payment_status' => 'paid', 'paid_by' => $type])
            ->whereHas('order')
            ->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])
            ->select(
                DB::raw('sum(amount) as refund_amount'),
                DB::raw("(DATE_FORMAT(created_at, '%W')) as day")
            )
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%D')"))
            ->latest('created_at')->get();

        $refundGiven = $this->earningReportsService->getRefundGivenFromOrderForEarningReport(
            query: $refunds,
            userType: $type,
            dataType: 'this_week',
            incrementNumber: $number,
            defaultIncrement: 0
        );

        return [
            'earn_from_order' => $earnFromOrder,
            'shipping_earn' => $shippingEarn,
            'deliveryman_incentive' => $deliverymanIncentive,
            'commission' => $commission,
            'discount_given' => $discountGiven,
            'total_tax' => $totalTax,
            'refund_given' => $refundGiven,
            'admin_bearer_free_shipping' => $adminBearerFreeShipping,
        ];
    }

    public function earningReportSameYear($type, $start_date, $end_date, $from_year, $number, $defaultInc)
    {
        $earnFromOrders = Order::where(['order_status' => 'delivered', 'seller_is' => $type])
            ->whereDate('created_at', '>=', $start_date)
            ->whereDate('created_at', '<=', $end_date)
            ->selectRaw("*, DATE_FORMAT(created_at, '%m') as month")
            ->latest('created_at')->get();

        $earnFromOrder = $this->earningReportsService->getEarnFromOrderForEarningReport(
            query: $earnFromOrders,
            userType: $type,
            dataType: 'this_year',
            startDate: $start_date,
            endDate: $end_date,
            incrementNumber: $number,
            defaultIncrement: $defaultInc
        );

        $commissions = Order::where(['seller_is' => 'seller', 'order_status' => 'delivered'])
            ->whereDate('created_at', '>=', $start_date)
            ->whereDate('created_at', '<=', $end_date)
            ->selectRaw('sum(admin_commission) as commission, YEAR(created_at) year, MONTH(created_at) month')
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%M')"))
            ->latest('created_at')->get();

        $commission = $this->earningReportsService->getCommissionFromOrderForEarningReport(
            query: $commissions,
            userType: $type,
            dataType: 'this_year',
            startDate: $start_date,
            endDate: $end_date,
            incrementNumber: $number,
            defaultIncrement: $defaultInc
        );

        $shippingEarns = Order::where(['order_type' => 'default_type', 'order_status' => 'delivered'])
            ->whereDate('created_at', '>=', $start_date)
            ->whereDate('created_at', '<=', $end_date)
            ->orderBy('created_at', 'desc')
            ->selectRaw("*, DATE_FORMAT(created_at, '%m') as month")
            ->get();

        $shippingEarn = $this->earningReportsService->getShippingEarnFromOrderForEarningReport(
            query: $shippingEarns,
            userType: $type,
            dataType: 'this_year',
            startDate: $start_date,
            endDate: $end_date,
            incrementNumber: $number,
            defaultIncrement: $defaultInc
        );

        $deliverymanIncentives = Order::whereHas('deliveryMan', function ($query) use ($type) {
            $query->when($type == 'admin', function ($query) {
                $query->where('seller_id', '0');
            })->when($type == 'seller', function ($query) {
                $query->where('seller_id', '!=', '0');
            });
        })
            ->selectRaw('sum(CASE WHEN delivery_type="self_delivery" AND shipping_responsibility=' . ($type == 'seller' ? '"sellerwise_shipping" AND seller_is="seller"' : '"inhouse_shipping" OR seller_is="admin"') . ' THEN deliveryman_charge ELSE 0 END) as deliveryman_incentive, YEAR(created_at) year, MONTH(created_at) month')
            ->where(['order_type' => 'default_type', 'order_status' => 'delivered'])
            ->whereDate('created_at', '>=', $start_date)
            ->whereDate('created_at', '<=', $end_date)
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%M')"))
            ->latest('created_at')->get();

        $deliverymanIncentive = $this->earningReportsService->getCommissionFromOrderForEarningReport(
            query: $deliverymanIncentives,
            userType: $type,
            dataType: 'this_year',
            startDate: $start_date,
            endDate: $end_date,
            incrementNumber: $number,
            defaultIncrement: $defaultInc
        );

        $adminBearerFreeShippingData = Order::where(['order_type' => 'default_type', 'order_status' => 'delivered'])
            ->whereDate('created_at', '>=', $start_date)
            ->whereDate('created_at', '<=', $end_date)
            ->selectRaw('sum(CASE WHEN is_shipping_free=1 AND free_delivery_bearer="admin" THEN extra_discount ELSE 0 END) as free_shipping_admin_bearer, YEAR(created_at) year, MONTH(created_at) month')
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%M')"))
            ->latest('created_at')->get();

        for ($inc = $defaultInc; $inc <= $number; $inc++) {
            $month = substr(date("F", strtotime("2023-$inc-01")), 0, 3);
            $adminBearerFreeShipping[$month] = 0;
            foreach ($adminBearerFreeShippingData as $match) {
                if ($match['month'] == $inc) {
                    $adminBearerFreeShipping[$month] = $match['free_shipping_admin_bearer'];
                }
            }
        }

        $discountGivenQuery = Order::where(['order_type' => 'default_type', 'order_status' => 'delivered'])
            ->whereDate('created_at', '>=', $start_date)
            ->whereDate('created_at', '<=', $end_date)
            ->selectRaw("*, DATE_FORMAT(created_at, '%m') as month")
            ->latest('created_at')->get();

        $discountGiven = $this->earningReportsService->getDiscountGivenFromOrderForEarningReport(
            query: $discountGivenQuery,
            userType: $type,
            dataType: 'this_year',
            startDate: $start_date,
            endDate: $end_date,
            incrementNumber: $number,
            defaultIncrement: $defaultInc
        );

        $taxes = OrderTransaction::where(['status' => 'disburse', 'seller_is' => $type])
            ->whereDate('created_at', '>=', $start_date)
            ->whereDate('created_at', '<=', $end_date)
            ->selectRaw('sum(tax) as total_tax, YEAR(created_at) year, MONTH(created_at) month')
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%M')"))
            ->latest('created_at')->get();

        $totalTax = $this->earningReportsService->getVatTAxFromOrderForEarningReport(
            query: $taxes,
            userType: $type,
            dataType: 'this_year',
            startDate: $start_date,
            endDate: $end_date,
            incrementNumber: $number,
            defaultIncrement: $defaultInc
        );

        $refunds = RefundTransaction::where(['payment_status' => 'paid', 'paid_by' => $type])
            ->whereHas('order')
            ->whereDate('created_at', '>=', $start_date)
            ->whereDate('created_at', '<=', $end_date)
            ->selectRaw('sum(amount) as refund_amount, YEAR(created_at) year, MONTH(created_at) month')
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%M')"))
            ->latest('created_at')->get();

        $refundGiven = $this->earningReportsService->getRefundGivenFromOrderForEarningReport(
            query: $refunds,
            userType: $type,
            dataType: 'this_year',
            startDate: $start_date,
            endDate: $end_date,
            incrementNumber: $number,
            defaultIncrement: $defaultInc
        );

        return [
            'earn_from_order' => $earnFromOrder,
            'shipping_earn' => $shippingEarn,
            'deliveryman_incentive' => $deliverymanIncentive,
            'commission' => $commission,
            'discount_given' => $discountGiven,
            'total_tax' => $totalTax,
            'refund_given' => $refundGiven,
            'admin_bearer_free_shipping' => $adminBearerFreeShipping ?? [],
        ];
    }

    public function earningReportDifferentYear($type, $start_date, $end_date, $from_year, $to_year)
    {
        $earnFromOrders = Order::where(['order_status' => 'delivered', 'seller_is' => $type])
            ->whereDate('created_at', '>=', $start_date)
            ->whereDate('created_at', '<=', $end_date)
            ->selectRaw("*, DATE_FORMAT(created_at, '%Y') as year")
            ->latest('created_at')->get();

        $earnFromOrder = $this->earningReportsService->getEarnFromOrderForEarningReport(
            query: $earnFromOrders,
            userType: $type,
            dataType: 'custom_date',
            startDate: $start_date,
            endDate: $end_date,
            incrementNumber: $to_year,
            defaultIncrement: $from_year
        );

        $commissions = Order::where(['seller_is' => 'seller', 'order_status' => 'delivered'])
            ->whereDate('created_at', '>=', $start_date)
            ->whereDate('created_at', '<=', $end_date)
            ->selectRaw('sum(admin_commission) as commission, YEAR(created_at) year')
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%Y')"))
            ->latest('created_at')->get();

        $commission = $this->earningReportsService->getCommissionFromOrderForEarningReport(
            query: $commissions,
            userType: $type,
            dataType: 'custom_date',
            startDate: $start_date,
            endDate: $end_date,
            incrementNumber: $to_year,
            defaultIncrement: $from_year
        );

        $shippingEarns = Order::where(['order_type' => 'default_type', 'order_status' => 'delivered'])
            ->whereDate('created_at', '>=', $start_date)
            ->whereDate('created_at', '<=', $end_date)
            ->orderBy('created_at', 'desc')
            ->selectRaw("*, DATE_FORMAT(created_at, '%Y') as year")
            ->get();

        $shippingEarn = $this->earningReportsService->getShippingEarnFromOrderForEarningReport(
            query: $shippingEarns,
            userType: $type,
            dataType: 'custom_date',
            startDate: $start_date,
            endDate: $end_date,
            incrementNumber: $to_year,
            defaultIncrement: $from_year
        );

        $deliverymanIncentives = Order::whereHas('deliveryMan', function ($query) use ($type) {
            $query->when($type == 'admin', function ($query) {
                $query->where('seller_id', '0');
            })->when($type == 'seller', function ($query) {
                $query->where('seller_id', '!=', '0');
            });
        })
            ->where(['order_type' => 'default_type', 'order_status' => 'delivered', 'is_shipping_free' => '0'])
            ->whereDate('created_at', '>=', $start_date)
            ->whereDate('created_at', '<=', $end_date)
            ->selectRaw('sum(CASE WHEN delivery_type="self_delivery" AND shipping_responsibility=' . ($type == 'seller' ? '"sellerwise_shipping" AND seller_is="seller"' : '"inhouse_shipping" OR seller_is="admin"') . ' THEN deliveryman_charge ELSE 0 END) as deliveryman_incentive, YEAR(created_at) year')
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%Y')"))
            ->latest('created_at')->get();

        $deliverymanIncentive = $this->earningReportsService->getCommissionFromOrderForEarningReport(
            query: $deliverymanIncentives,
            userType: $type,
            dataType: 'custom_date',
            startDate: $start_date,
            endDate: $end_date,
            incrementNumber: $to_year,
            defaultIncrement: $from_year
        );

        //admin bearer free shipping
        $adminBearerFreeShippingData = Order::where(['order_type' => 'default_type', 'order_status' => 'delivered'])
            ->whereDate('created_at', '>=', $start_date)
            ->whereDate('created_at', '<=', $end_date)
            ->selectRaw('sum(CASE WHEN is_shipping_free=1 AND free_delivery_bearer="admin" THEN extra_discount ELSE 0 END) as free_shipping_admin_bearer, YEAR(created_at) year')
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%Y')"))
            ->latest('created_at')->get();

        $adminBearerFreeShipping = [];
        for ($inc = $from_year; $inc <= $to_year; $inc++) {
            $adminBearerFreeShipping[$inc] = 0;
            foreach ($adminBearerFreeShippingData as $match) {
                if ($match['year'] == $inc) {
                    $adminBearerFreeShipping[$inc] = $match['free_shipping_admin_bearer'];
                }
            }
        }

        //discount_given
        $discountGivenQuery = Order::where(['order_type' => 'default_type', 'order_status' => 'delivered'])
            ->whereDate('created_at', '>=', $start_date)
            ->whereDate('created_at', '<=', $end_date)
            ->selectRaw("*, DATE_FORMAT(created_at, '%Y') as year")
            ->latest('created_at')->get();

        $discountGiven = $this->earningReportsService->getDiscountGivenFromOrderForEarningReport(
            query: $discountGivenQuery,
            userType: $type,
            dataType: 'custom_date',
            startDate: $start_date,
            endDate: $end_date,
            incrementNumber: $to_year,
            defaultIncrement: $from_year
        );

        $taxes = OrderTransaction::where(['status' => 'disburse', 'seller_is' => $type])
            ->whereDate('created_at', '>=', $start_date)
            ->whereDate('created_at', '<=', $end_date)
            ->selectRaw('sum(tax) as total_tax, YEAR(created_at) year')
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%Y')"))
            ->latest('created_at')->get();

        $totalTax = $this->earningReportsService->getVatTAxFromOrderForEarningReport(
            query: $taxes,
            userType: $type,
            dataType: 'custom_date',
            startDate: $start_date,
            endDate: $end_date,
            incrementNumber: $to_year,
            defaultIncrement: $from_year
        );

        $refunds = RefundTransaction::where(['payment_status' => 'paid', 'paid_by' => $type])
            ->whereHas('order')
            ->whereDate('created_at', '>=', $start_date)
            ->whereDate('created_at', '<=', $end_date)
            ->selectRaw('sum(amount) as refund_amount, YEAR(created_at) year')
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%Y')"))
            ->latest('created_at')->get();

        $refundGiven = $this->earningReportsService->getRefundGivenFromOrderForEarningReport(
            query: $refunds,
            userType: $type,
            dataType: 'custom_date',
            startDate: $start_date,
            endDate: $end_date,
            incrementNumber: $to_year,
            defaultIncrement: $from_year
        );

        return [
            'earn_from_order' => $earnFromOrder,
            'shipping_earn' => $shippingEarn,
            'deliveryman_incentive' => $deliverymanIncentive,
            'commission' => $commission,
            'discount_given' => $discountGiven,
            'total_tax' => $totalTax,
            'refund_given' => $refundGiven,
            'admin_bearer_free_shipping' => $adminBearerFreeShipping,
        ];
    }

    public function admin_earning_duration_download_pdf(Request $request)
    {
        $earning_data = $request->except('_token');
        $company_phone = BusinessSetting::where('type', 'company_phone')->first()->value;
        $company_email = BusinessSetting::where('type', 'company_email')->first()->value;
        $company_name = BusinessSetting::where('type', 'company_name')->first()->value;
        $company_web_logo = BusinessSetting::where('type', 'company_web_logo')->first()->value;


        $mpdf_view = View::make('admin-views.report.admin-earning-duration-wise-pdf', compact('earning_data', 'company_name', 'company_email', 'company_phone', 'company_web_logo'));
        Helpers::gen_mpdf($mpdf_view, 'admin_earning_', $earning_data['duration']);
    }

    public function vendorEarning(Request $request): PageView
    {
        $from = $request['from'];
        $to = $request['to'];
        $date_type = $request['date_type'] ?? 'this_year';

        $total_seller_query = Seller::where(['status' => 'approved']);
        $total_seller = self::earning_common_query($request, $total_seller_query)->count();

        $all_product_query = Product::where(['added_by' => 'seller']);
        $all_product = self::earning_common_query($request, $all_product_query)->count();

        $rejected_product_query = Product::where(['added_by' => 'seller', 'request_status' => 2]);
        $rejected_product = self::earning_common_query($request, $rejected_product_query)->count();

        $pending_product_query = Product::where(['added_by' => 'seller', 'request_status' => 0]);
        $pending_product = self::earning_common_query($request, $pending_product_query)->count();

        $active_product_query = Product::where(['added_by' => 'seller', 'status' => 1, 'request_status' => 1]);
        $active_product = self::earning_common_query($request, $active_product_query)->count();

        $data = [
            'total_seller' => $total_seller,
            'all_product' => $all_product,
            'rejected_product' => $rejected_product,
            'pending_product' => $pending_product,
            'active_product' => $active_product,
        ];

        $payments = SellerWallet::selectRaw('sum(total_earning) as total_earning, sum(pending_withdraw) as pending_withdraw, sum(withdrawn) as withdrawn')->first();
        $withdrawable_balance = $payments->total_earning - $payments->pending_withdraw;

        $payment_data = [
            'wallet_amount' => $payments->total_earning,
            'withdrawable_balance' => $withdrawable_balance,
            'pending_withdraw' => $payments->pending_withdraw,
            'already_withdrawn' => $payments->withdrawn,
        ];

        $filterDataChart = self::earningReportCommonFilter('seller', $date_type, $from, $to);
        $sellerEarnChart = $filterDataChart['earn_from_order'];
        $shippingEarnChart = $filterDataChart['shipping_earn'];
        $deliverymanIncentive = $filterDataChart['deliveryman_incentive'];
        $commissionGivenChart = $filterDataChart['commission'];
        $discountGivenChart = $filterDataChart['discount_given'];
        $totalTaxChart = $filterDataChart['total_tax'];
        $refundGivenChart = $filterDataChart['refund_given'];
        $adminBearerFreeShipping = $filterDataChart['admin_bearer_free_shipping'];

        $chartEarningStatistics = [];
        foreach ($sellerEarnChart as $key => $earning) {
            $chartEarningStatistics[$key] = $earning + $shippingEarnChart[$key] + $totalTaxChart[$key] - $discountGivenChart[$key] - $commissionGivenChart[$key] - $refundGivenChart[$key] - $deliverymanIncentive[$key];
        }

        $filterDataTable = self::seller_earning_common_filter_table($date_type, $from, $to);
        $sellerEarningFormattedData = $this->getSellerEarningFormattedData($filterDataTable);

        $totalEarning = $sellerEarningFormattedData['totalEarning'];

        $sellerEarnTable = $sellerEarningFormattedData['sellerEarnFormattedData'];

        $chartEarningStatisticsLabel = array_values(collect(array_keys($chartEarningStatistics))->map(function ($item) use ($request) {
            if ($request['date_type'] == 'this_month') {
                return $item . ' ' . date('M');
            }
            return $item;
        })->toArray());

        return view('admin-views.report.seller-earning', [
            'data' => $data,
            'payment_data' => $payment_data,
            'seller_earn_table' => $sellerEarnTable,
            'total_earning' => $totalEarning,
            'chart_earning_statistics' => $chartEarningStatistics,
            'from' => $from,
            'to' => $to,
            'date_type' => $date_type,
            'chartEarningStatisticsLabel' => $chartEarningStatisticsLabel,
        ]);
    }

    public function getSellerEarningFormattedData($filterData)
    {
        $total_seller_earning = 0;
        $total_commission = 0;
        $total_shipping_earn = 0;
        $total_deliveryman_incentive = 0;
        $total_discount_given = 0;
        $total_refund_given = 0;
        $total_tax = 0;
        $sellerEarnFormattedData = [];

        $seller_earn_table = $filterData['seller_earn_table'];
        $commission_given_table = $filterData['commission_given_table'];
        $shipping_earn_table = $filterData['shipping_earn_table'];
        $deliveryman_incentive_table = $filterData['deliveryman_incentive'];
        $discount_given_table = $filterData['discount_given_table'];
        $discount_given_bearer_admin_table = $filterData['discount_given_bearer_admin_table'];
        $total_tax_table = $filterData['total_tax_table'];
        $total_refund_table = $filterData['total_refund_table'];

        foreach ($seller_earn_table as $key => $seller_earn) {
            $shipping_earn = $shipping_earn_table[$key]['amount'] ?? 0;
            $deliveryman_incentive = $deliveryman_incentive_table[$key]['amount'] ?? 0;
            $commission_given = $commission_given_table[$key]['amount'] ?? 0;
            $discount_given = $discount_given_table[$key]['amount'] ?? 0;
            $tax = $total_tax_table[$key]['amount'] ?? 0;
            $refund = $total_refund_table[$key]['amount'] ?? 0;

            $total_earning = $seller_earn['amount'] + $shipping_earn + $tax - $discount_given - $refund - $commission_given - $deliveryman_incentive;

            $total_seller_earning += $seller_earn['amount'];
            $total_commission += $commission_given;
            $total_shipping_earn += $shipping_earn;
            $total_deliveryman_incentive += $deliveryman_incentive;
            $total_discount_given += $discount_given;
            $total_tax += $tax;
            $total_refund_given += $refund;

            $sellerEarnFormattedData[] = [
                'vendor_id' => isset($seller_earn['seller_id']) ? $seller_earn['seller_id'] : "",
                'vendor_info' => isset($seller_earn['seller_id']) && isset($seller_earn['name']) ? $seller_earn['name'] : 'vendor_not_found',
                'earn_from_order' => $seller_earn['amount'],
                'earn_from_shipping' => $shipping_earn,
                'deliveryman_incentive' => $deliveryman_incentive,
                'commission_given' => $commission_given,
                'discount_given' => $discount_given,
                'tax_collected' => $tax,
                'refund_given' => $refund,
                'total_earning' => $total_earning,
            ];
        }

        return [
            'sellerEarnFormattedData' => $sellerEarnFormattedData,
            'totalEarning' => $total_seller_earning + $total_shipping_earn + $total_tax - $total_discount_given - $total_commission - $total_refund_given - $total_deliveryman_incentive
        ];
    }

    public function exportVendorEarning(Request $request): BinaryFileResponse
    {
        $from = $request['from'];
        $to = $request['to'];
        $dateType = $request['date_type'] ?? 'this_year';
        $filterData = self::seller_earning_common_filter_table($dateType, $from, $to);
        $seller_earning_formatted_data = $this->getSellerEarningFormattedData($filterData);

        $data = [
            'from' => $from,
            'to' => $to,
            'dateType' => $dateType,
            'vendorEarnTable' => $seller_earning_formatted_data['sellerEarnFormattedData'],
        ];
        return Excel::download(new VendorEarningReportExport($data), Report::VENDOR_EARNING_REPORT);
    }

    public function seller_earning_common_filter_table($date_type, $from, $to)
    {
        if ($date_type == 'this_year') {
            $start_date = date('Y-01-01');
            $end_date = date('Y-12-31');
            return self::seller_earning_query_table($start_date, $end_date);
        } elseif ($date_type == 'this_month') {
            $current_month_start = date('Y-m-01');
            $current_month_end = date('Y-m-t');
            return self::seller_earning_query_table($current_month_start, $current_month_end);
        } elseif ($date_type == 'this_week') {
            return self::seller_earning_query_table(Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek());
        } elseif ($date_type == 'today') {
            return self::seller_earning_query_table(Carbon::now()->startOfDay(), Carbon::now()->endOfDay());
        } elseif ($date_type == 'custom_date' && !empty($from) && !empty($to)) {
            $start_date_custom = Carbon::parse($from)->format('Y-m-d 00:00:00');
            $end_date_custom = Carbon::parse($to)->format('Y-m-d 23:59:59');
            return self::seller_earning_query_table($start_date_custom, $end_date_custom);
        }
    }

    /**
     *   seller earning query for table
     */
    public function seller_earning_query_table($start_date, $end_date)
    {
        $orders = Order::where(['order_status' => 'delivered', 'seller_is' => 'seller'])
            ->whereBetween('created_at', [$start_date, $end_date])
            ->orderBy('created_at', 'desc')
            ->get();

        $seller_earn_table = [];
        $commission_given_table = [];

        foreach ($orders as $order) {
            $sellerId = $order->seller_id;

            if (!isset($seller_earn_table[$sellerId])) {
                $seller = Seller::find($sellerId);
                $seller_earn_table[$sellerId] = [
                    'seller_id' => $sellerId,
                    'name' => $seller ? $seller->f_name . ' ' . $seller->l_name : '',
                    'amount' => 0
                ];
                $commission_given_table[$sellerId] = [
                    'name' => $seller ? $seller->f_name . ' ' . $seller->l_name : '',
                    'amount' => 0
                ];
            }

            $seller_earn_table[$sellerId]['amount'] += $this->earningReportsService->getEarnFormOrderAmount(order: $order, type: 'seller');
            $commission_given_table[$sellerId]['amount'] += $order->admin_commission;
        }

        //discount_given_bearer_admin
        $discount_given_bearer_admin = Order::where(['coupon_discount_bearer' => 'inhouse', 'discount_type' => 'coupon_discount', 'order_status' => 'delivered'])
            ->whereBetween('created_at', [$start_date, $end_date])
            ->selectRaw('sum(discount_amount) as discount_amount, seller_id, YEAR(created_at) year, MONTH(created_at) month')
            ->groupBy('seller_id')
            ->latest('created_at')->get();

        $discount_given_bearer_admin_table = array();
        foreach ($discount_given_bearer_admin as $data) {
            $seller = Seller::find($data->seller_id);
            $discount_given_bearer_admin_table[$data->seller_id] = array(
                'name' => !empty($seller) ? $seller->f_name . ' ' . $seller->l_name : '',
                'amount' => $data->discount_amount
            );
        }

        //shipping earn
        $shipping_earns = Order::where([
            'order_type' => 'default_type',
            'order_status' => 'delivered',
            'seller_is' => 'seller',
        ])
            ->whereBetween('created_at', [$start_date, $end_date])
            ->latest('created_at')->get();

        $shippingEarnTable = array();
        foreach ($shipping_earns as $order) {
            if ($order->shipping_responsibility == 'sellerwise_shipping') {
                if (!isset($shippingEarnTable[$sellerId])) {
                    $seller = Seller::find($sellerId);
                    $shippingEarnTable[$sellerId] = [
                        'name' => $seller ? $seller->f_name . ' ' . $seller->l_name : '',
                        'amount' => 0
                    ];
                }

                if ($order->is_shipping_free == 0) {
                    $shippingEarnTable[$sellerId]['amount'] += $order->shipping_cost;
                } elseif ($order->is_shipping_free == 1) {
                    $shippingEarnTable[$sellerId]['amount'] += $order->extra_discount;
                }
            }
        }

        //deliveryman incentive
        $deliveryman_incentives = Order::where([
            'order_type' => 'default_type',
            'order_status' => 'delivered',
            'seller_is' => 'seller',
        ])
            ->whereBetween('created_at', [$start_date, $end_date])
            ->selectRaw('sum(CASE WHEN delivery_type="self_delivery" AND shipping_responsibility="sellerwise_shipping" THEN deliveryman_charge ELSE 0 END) as deliveryman_incentive, seller_id, YEAR(created_at) year, MONTH(created_at) month')
            ->groupBy('seller_id')
            ->latest('created_at')->get();

        $deliveryman_incentive = [];
        foreach ($deliveryman_incentives as $data) {
            $seller = Seller::find($data->seller_id);
            $deliveryman_incentive[$data->seller_id] = array(
                'name' => !empty($seller) ? $seller->f_name . ' ' . $seller->l_name : '',
                'amount' => $data->deliveryman_incentive
            );
        }

        //discount_given
        $discountsGivenOrders = Order::where('order_status', 'delivered')
            ->whereHas('seller', function ($query) {
                return $query;
            })
            ->where(['seller_is' => 'seller'])
            ->whereBetween('created_at', [$start_date, $end_date])
            ->latest('created_at')->get()->groupBy('seller_id');

        $discount_given_table = [];
        foreach ($discountsGivenOrders as $ordersGroup) {
            $sellerId = $ordersGroup->first()?->seller_id;
            $sellerFirstName = $ordersGroup->first()?->seller?->f_name;
            $sellerLastName = $ordersGroup->first()?->seller?->l_name;
            $sellerName = ($sellerFirstName . ' ' . $sellerLastName) ?? '';

            $discountAmount = 0;
            foreach ($ordersGroup as $order) {
                if ($order->discount_type === 'coupon_discount' && $order->coupon_discount_bearer === 'seller') {
                    $discountAmount += $order->discount_amount;
                }
                if ((int)$order->is_shipping_free === 1 && $order->free_delivery_bearer === 'seller') {
                    $discountAmount += $order->extra_discount;
                }
            }

            $discount_given_table[$sellerId] = [
                'name' => $sellerName,
                'amount' => $discountAmount,
            ];
        }

        //vat/tax
        $taxes = OrderTransaction::where(['seller_is' => 'seller', 'status' => 'disburse'])
            ->whereBetween('created_at', [$start_date, $end_date])
            ->selectRaw('sum(tax) as total_tax, seller_id, YEAR(created_at) year, MONTH(created_at) month')
            ->groupBy('seller_id')
            ->latest('created_at')->get();

        $total_tax_table = array();
        foreach ($taxes as $data) {
            $seller = Seller::find($data->seller_id);
            $total_tax_table[$data->seller_id] = array(
                'name' => !empty($seller) ? $seller->f_name . ' ' . $seller->l_name : '',
                'amount' => $data->total_tax
            );
        }

        //refund given
        $refunds = RefundTransaction::where(['payment_status' => 'paid', 'paid_by' => 'seller'])
            ->whereHas('order')
            ->whereBetween('created_at', [$start_date, $end_date])
            ->selectRaw('sum(amount) as refund_amount, payer_id, YEAR(created_at) year')
            ->groupBy('payer_id')
            ->latest('created_at')->get();

        $total_refund_table = array();
        foreach ($refunds as $data) {
            $seller = Seller::find($data->payer_id);
            $total_refund_table[$data->payer_id] = array(
                'name' => !empty($seller) ? $seller->f_name . ' ' . $seller->l_name : '',
                'amount' => $data->refund_amount
            );
        }

        foreach ($total_refund_table as $key => $data) {
            if (!array_key_exists($key, $seller_earn_table)) {
                $seller_earn_table[$key] = array(
                    'name' => $data['name'],
                    'amount' => 0,
                );
            }
        }

        return [
            'seller_earn_table' => $seller_earn_table,
            'commission_given_table' => $commission_given_table,
            'shipping_earn_table' => $shippingEarnTable,
            'deliveryman_incentive' => $deliveryman_incentive,
            'discount_given_table' => $discount_given_table,
            'discount_given_bearer_admin_table' => $discount_given_bearer_admin_table,
            'total_tax_table' => $total_tax_table,
            'total_refund_table' => $total_refund_table,
        ];
    }

    public function set_date(Request $request)
    {
        $from = $request['from'];
        $to = $request['to'];

        session()->put('from_date', $from);
        session()->put('to_date', $to);

        $previousUrl = strtok(url()->previous(), '?');
        return redirect()->to($previousUrl . '?' . http_build_query(['from_date' => $request['from'], 'to_date' => $request['to']]))->with(['from' => $from, 'to' => $to]);
    }
}
