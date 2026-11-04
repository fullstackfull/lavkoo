<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Repositories\VendorRepositoryInterface;
use App\Enums\ExportFileNames\Admin\Report;
use App\Utils\Helpers;
use App\Exports\OrderReportExport;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Seller;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use FontLib\Table\Type\name;
use Illuminate\Contracts\View\View as ViewResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OrderReportController extends Controller
{
    public function __construct(
        private readonly VendorRepositoryInterface $vendorRepo,
    )
    {
    }

    public function order_list(Request $request): ViewResponse
    {
        $seller_id = $request->seller_id ?? 'all';
        $date_type = $request->date_type ?? 'this_year';
        $from = $request['from'];
        $to = $request['to'];
        $search = $request['search'];
        $query_param = ['seller_id' => $seller_id, 'search' => $search, 'date_type' => $date_type, 'from' => $from, 'to' => $to];
        $sellers = Seller::where(['status' => 'approved'])->get();

        $chart_data = self::order_report_chart_filter($request);

        $orders = self::all_order_table_data_filter($request);
        $orders = $orders->latest('created_at')->paginate(Helpers::pagination_limit())->appends($query_param);

        $ongoing_order_query = Order::whereIn('order_status', ['out_for_delivery', 'processing', 'confirmed', 'pending']);
        $ongoing_order = self::order_count($request, $ongoing_order_query);

        $cancel_order_query = Order::whereIn('order_status', ['canceled', 'failed', 'returned']);
        $canceled_order = self::order_count($request, $cancel_order_query);

        $delivered_order_query = Order::where('order_status', 'delivered');
        $delivered_order = self::order_count($request, $delivered_order_query);

        $order_count = array(
            'ongoing_order' => $ongoing_order,
            'canceled_order' => $canceled_order,
            'delivered_order' => $delivered_order,
            'total_order' => $canceled_order + $ongoing_order + $delivered_order,
        );

        $due_amount_order_query = Order::whereNotIn('order_status', ['delivered', 'canceled', 'returned', 'failed'])
            ->when($seller_id != 'all', function ($query) use ($seller_id) {
                $query->when($seller_id == 'inhouse', function ($q) {
                    $q->where(['seller_id' => 1, 'seller_is' => 'admin']);
                })->when($seller_id != 'inhouse', function ($q) use ($seller_id) {
                    $q->where(['seller_id' => $seller_id, 'seller_is' => 'seller']);
                });
            });
        $due_amount = self::date_wise_common_filter($due_amount_order_query, $date_type, $from, $to)->sum('order_amount');

        $settled_amount_query = Order::where('order_status', 'delivered')
            ->when($seller_id != 'all', function ($query) use ($seller_id) {
                $query->when($seller_id == 'inhouse', function ($q) {
                    $q->where(['seller_id' => 1, 'seller_is' => 'admin']);
                })->when($seller_id != 'inhouse', function ($q) use ($seller_id) {
                    $q->where(['seller_id' => $seller_id, 'seller_is' => 'seller']);
                });
            });
        $settled_amount = self::date_wise_common_filter($settled_amount_query, $date_type, $from, $to)->sum('order_amount');

        $referral_discount_query = Order::where('order_status', 'delivered')
            ->when($seller_id != 'all', function ($query) use ($seller_id) {
                $query->when($seller_id == 'inhouse', function ($q) {
                    $q->where(['seller_id' => 1, 'seller_is' => 'admin']);
                })->when($seller_id != 'inhouse', function ($q) use ($seller_id) {
                    $q->where(['seller_id' => $seller_id, 'seller_is' => 'seller']);
                });
            });

        $totalReferralDiscount = self::date_wise_common_filter($referral_discount_query, $date_type, $from, $to)->sum('refer_and_earn_discount');
        $payment_data = self::getAdminOrderListPaymentFormattedData(request: $request);

        $chartDataOrderAmountLabel = array_values(collect(array_keys($chart_data['order_amount']))->map(function ($item) use ($request) {
            if ($request['date_type'] == 'this_month') {
                return $item . ' '. date('M');
            }
            return $item;
        })->toArray());

        return view('admin-views.report.order-index', compact('orders', 'order_count', 'payment_data', 'chart_data', 'due_amount', 'settled_amount', 'sellers', 'seller_id', 'search', 'date_type', 'from', 'to', 'totalReferralDiscount', 'chartDataOrderAmountLabel'));
    }

    public function getAdminOrderListPaymentFormattedData(object|array $request): array
    {
        $digitalPaymentQuery = Order::where(['order_status' => 'delivered'])->whereNotIn('payment_method', ['cash', 'cash_on_delivery', 'pay_by_wallet', 'offline_payment']);
        $digitalPayment = self::pie_chart_common_query($request, $digitalPaymentQuery)->sum('init_order_amount');

        $cashPaymentQuery = Order::where(['order_status' => 'delivered'])->whereIn('payment_method', ['cash', 'cash_on_delivery']);
        $cashPayment = self::pie_chart_common_query($request, $cashPaymentQuery)->sum('init_order_amount');

        $walletPaymentQuery = Order::where(['order_status' => 'delivered'])->where(['payment_method' => 'pay_by_wallet']);
        $walletPayment = self::pie_chart_common_query($request, $walletPaymentQuery)->sum('init_order_amount');

        $offlinePaymentQuery = Order::where(['order_status' => 'delivered'])->where(['payment_method' => 'offline_payment']);
        $offlinePayment = self::pie_chart_common_query($request, $offlinePaymentQuery)->sum('init_order_amount');

        $orderEditHistory = self::pie_chart_common_query($request, Order::where(['order_status' => 'delivered'])->with(['orderEditHistory']))->get();
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


    public function order_report_chart_filter($request)
    {
        $from = $request['from'];
        $to = $request['to'];
        $date_type = $request['date_type'] ?? 'this_year';

        if ($date_type == 'this_year') {
            $number = 12;
            $default_inc = 1;
            $current_start_year = date('Y-01-01');
            $current_end_year = date('Y-12-31');
            $from_year = Carbon::parse($from)->format('Y');
            return self::order_report_same_year($request, $current_start_year, $current_end_year, $from_year, $number, $default_inc);
        } elseif ($date_type == 'this_month') { //this month table
            $current_month_start = date('Y-m-01');
            $current_month_end = date('Y-m-t');
            $inc = 1;
            $month = date('m');
            $number = date('d', strtotime($current_month_end));
            return self::order_report_same_month($request, $current_month_start, $current_month_end, $month, $number, $inc);
        } elseif ($date_type == 'this_week') {
            return self::order_report_this_week($request);
        } elseif ($date_type == 'today') {
            return self::getOrderReportForToday($request);
        } elseif ($date_type == 'custom_date' && !empty($from) && !empty($to)) {
            $start_date = Carbon::parse($from)->format('Y-m-d 00:00:00');
            $end_date = Carbon::parse($to)->format('Y-m-d 23:59:59');
            $from_year = Carbon::parse($from)->format('Y');
            $from_month = Carbon::parse($from)->format('m');
            $from_day = Carbon::parse($from)->format('d');
            $to_year = Carbon::parse($to)->format('Y');
            $to_month = Carbon::parse($to)->format('m');
            $to_day = Carbon::parse($to)->format('d');

            if ($from_year != $to_year) {
                return self::order_report_different_year($request, $start_date, $end_date, $from_year, $to_year);
            } elseif ($from_month != $to_month) {
                return self::order_report_same_year($request, $start_date, $end_date, $from_year, $to_month, $from_month);
            } elseif ($from_month == $to_month) {
                return self::order_report_same_month($request, $start_date, $end_date, $from_month, $to_day, $from_day);
            }

        }
    }

    public function order_report_same_year($request, $start_date, $end_date, $from_year, $number, $default_inc)
    {
        $orders = self::order_report_chart_common_query($request, $start_date, $end_date)
            ->selectRaw('sum(order_amount) as order_amount, YEAR(created_at) year, MONTH(created_at) month')
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%M')"))
            ->latest('created_at')->get();

        for ($inc = $default_inc; $inc <= $number; $inc++) {
            $month = substr(date("F", strtotime("2023-$inc-01")), 0, 3);
            $orderAmount[$month] = 0;
            foreach ($orders as $match) {
                if ($match['month'] == $inc) {
                    $orderAmount[$month] = $match['order_amount'];
                }
            }
        }

        return [
            'order_amount' => $orderAmount ?? [],
        ];
    }

    public function order_report_same_month($request, $start_date, $end_date, $month_date, $number, $default_inc)
    {
        $year_month = date('Y-m', strtotime($start_date));
        $month = substr(date("F", strtotime("$year_month")), 0, 3);

        $orders = self::order_report_chart_common_query($request, $start_date, $end_date)
            ->selectRaw('sum(order_amount) as order_amount, YEAR(created_at) year, MONTH(created_at) month, DAY(created_at) day')
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%D')"))
            ->latest('created_at')->get();

        for ($inc = $default_inc; $inc <= $number; $inc++) {
            $order_amount[$inc] = 0;
            foreach ($orders as $match) {
                if ($match['day'] == $inc) {
                    $order_amount[$inc] = $match['order_amount'];
                }
            }
        }

        return array(
            'order_amount' => $order_amount,
        );
    }

    public function order_report_this_week($request)
    {
        $start_date = Carbon::now()->startOfWeek();
        $end_date = Carbon::now()->endOfWeek();

        $number = 6;
        $period = CarbonPeriod::create(Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek());
        $day_name = array();
        foreach ($period as $date) {
            array_push($day_name, $date->format('l'));
        }

        $orders = self::order_report_chart_common_query($request, $start_date, $end_date)
            ->select(
                DB::raw('sum(order_amount) as order_amount'),
                DB::raw("(DATE_FORMAT(created_at, '%W')) as day")
            )
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%D')"))
            ->latest('created_at')->get();

        for ($inc = 0; $inc <= $number; $inc++) {
            $order_amount[$day_name[$inc]] = 0;
            foreach ($orders as $match) {
                if ($match['day'] == $day_name[$inc]) {
                    $order_amount[$day_name[$inc]] = $match['order_amount'];
                }
            }
        }

        return array(
            'order_amount' => $order_amount,
        );
    }

    public function getOrderReportForToday($request): array
    {
        $number = 1;
        $dayName = [Carbon::today()->format('l')];

        $orders = self::order_report_chart_common_query($request, Carbon::now()->startOfDay(), Carbon::now()->endOfDay())
            ->select(
                DB::raw('sum(order_amount) as order_amount'),
                DB::raw("(DATE_FORMAT(created_at, '%W')) as day")
            )
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%D')"))
            ->latest('created_at')->get();

        for ($inc = 0; $inc < $number; $inc++) {
            $order_amount[$dayName[$inc]] = 0;
            foreach ($orders as $match) {
                if ($match['day'] == $dayName[$inc]) {
                    $order_amount[$dayName[$inc]] = $match['order_amount'];
                }
            }
        }

        return [
            'order_amount' => $order_amount ?? [],
        ];
    }

    public function order_report_different_year($request, $start_date, $end_date, $from_year, $to_year)
    {
        $orders = self::order_report_chart_common_query($request, $start_date, $end_date)
            ->selectRaw('sum(order_amount) as order_amount, YEAR(created_at) year')
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%Y')"))
            ->latest('created_at')->get();

        for ($inc = $from_year; $inc <= $to_year; $inc++) {
            $order_amount[$inc] = 0;
            foreach ($orders as $match) {
                if ($match['year'] == $inc) {
                    $order_amount[$inc] = $match['order_amount'];
                }
            }
        }

        return array(
            'order_amount' => $order_amount,
        );
    }

    public function order_report_chart_common_query($request, $start_date, $end_date)
    {
        $sellerId = $request['seller_id'] ?? 'all';
        return Order::where(['order_status' => 'delivered'])
            ->when($sellerId != 'all', function ($query) use ($sellerId) {
                $query->when($sellerId == 'inhouse', function ($q) {
                    $q->where(['seller_id' => 1, 'seller_is' => 'admin']);
                })->when($sellerId != 'inhouse', function ($q) use ($sellerId) {
                    $q->where(['seller_id' => $sellerId, 'seller_is' => 'seller']);
                });
            })
            ->whereBetween('created_at', [$start_date, $end_date]);
    }

    public function pie_chart_common_query($request, $query)
    {
        $from = $request['from'];
        $to = $request['to'];
        $seller_id = $request['seller_id'] ?? 'all';
        $date_type = $request['date_type'] ?? 'this_year';

        $query_f = $query->when($seller_id != 'all', function ($query) use ($seller_id) {
            $query->when($seller_id == 'inhouse', function ($q) {
                $q->where(['seller_id' => 1, 'seller_is' => 'admin']);
            })->when($seller_id != 'inhouse', function ($q) use ($seller_id) {
                $q->where(['seller_id' => $seller_id, 'seller_is' => 'seller']);
            });
        });

        return self::date_wise_common_filter($query_f, $date_type, $from, $to);
    }

    public function orderReportExportExcel(Request $request): BinaryFileResponse
    {
        $orders = self::all_order_table_data_filter($request)->latest('created_at')->get();
        $vendor = $request->has('seller_id') && $request['seller_id'] != 'inhouse' && $request['seller_id'] != 'all' ? ($this->vendorRepo->getFirstWhere(params: ['id' => $request['seller_id']], relations: ['shop'])) : ($request['seller_id'] ?? 'all');
        $data = [
            'orders' => $orders,
            'search' => $request['search'],
            'vendor' => $vendor,
            'from' => $request['from'],
            'to' => $request['to'],
            'dateType' => $request['date_type'] ?? 'this_year'
        ];
        return Excel::download(new OrderReportExport($data), Report::ORDER_REPORT_LIST);
    }

    public function all_order_table_data_filter($request)
    {
        $search = $request['search'];
        $from = $request['from'];
        $to = $request['to'];
        $seller_id = $request['seller_id'] ?? 'all';
        $date_type = $request['date_type'] ?? 'this_year';

        $orders_query = Order::with(['orderTransaction'])->withSum('details', 'tax')
            ->withSum('details', 'discount')
            ->when($search, function ($q) use ($search) {
                $q->orWhere('id', 'like', "%{$search}%");
            })
            ->when($seller_id != 'all', function ($query) use ($seller_id) {
                $query->when($seller_id == 'inhouse', function ($q) {
                    $q->where(['seller_id' => 1, 'seller_is' => 'admin']);
                })->when($seller_id != 'inhouse', function ($q) use ($seller_id) {
                    $q->where(['seller_id' => $seller_id, 'seller_is' => 'seller']);
                });
            });
        return self::date_wise_common_filter($orders_query, $date_type, $from, $to);
    }

    public function order_count($request, $query)
    {
        $from = $request['from'];
        $to = $request['to'];
        $seller_id = $request['seller_id'] ?? 'all';
        $date_type = $request['date_type'] ?? 'this_year';

        $query_f = $query->when($seller_id != 'all', function ($query) use ($seller_id) {
            $query->when($seller_id == 'inhouse', function ($q) {
                $q->where(['seller_id' => 1, 'seller_is' => 'admin']);
            })->when($seller_id != 'inhouse', function ($q) use ($seller_id) {
                $q->where(['seller_id' => $seller_id, 'seller_is' => 'seller']);
            });
        });

        return self::date_wise_common_filter($query_f, $date_type, $from, $to)->count();
    }

    public function date_wise_common_filter($query, $date_type, $from, $to)
    {
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

    public function exportOrderReportInPDF(Request $request)
    {
        $dateType = $request['date_type'] ?? 'this_year';

        $orders = self::all_order_table_data_filter($request)->latest('created_at')->get();
        $seller = $request->has('seller_id') && $request['seller_id'] != 'inhouse' && $request['seller_id'] != 'all' ? (Seller::with('shop')->find($request['seller_id'])->f_name) : ($request['seller_id'] ?? 'all');

        $totalOrderAmount = $orders->sum('order_amount') ?? 0;
        $totalProductDiscount = $orders->sum('details_sum_discount') ?? 0;
        $totalCouponDiscount = $orders->sum('discount_amount') ?? 0;
        $totalReferralDiscount = $orders->sum('refer_and_earn_discount') ?? 0;
        $totalTax = $orders->sum('total_tax_amount') ?? ($orders->sum('details_sum_tax') ?? 0);
        $totalOrderCommission = $orders->sum('admin_commission') ?? 0;
        $totalOrderDueAmount = $orders->sum('edit_due_amount') ?? 0;
        $totalOrderReturnAmount = $orders->sum('edit_return_amount') ?? 0;

        $totalDeliveryCharge = 0;
        $totalDeliverymanIncentive = 0;
        foreach ($orders as $order) {
            $totalDeliveryCharge += ($order->shipping_cost - ($order->extra_discount_type == 'free_shipping_over_order_amount' ? $order->extra_discount : 0));
            $totalDeliverymanIncentive += ($order->delivery_type == 'self_delivery' && $order->delivery_man_id) ? $order->deliveryman_charge : 0;
        }

        $data = [
            'orders' => $orders,
            'total_orders' => count($orders),
            'search' => $request['search'],
            'seller' => $seller,
            'type' => $request->has('seller_id') ? ($request['seller_id'] != 'inhouse' ? 'seller' : $request['seller_id']) : 'all',
            'from' => $request['from'],
            'to' => $request['to'],
            'company_name' => getWebConfig(name: 'company_name'),
            'company_email' => getWebConfig(name: 'company_email'),
            'company_phone' => getWebConfig(name: 'company_phone'),
            'company_web_logo' => getWebConfig(name: 'company_web_logo'),
            'date_type' => $request['date_type'] ?? 'this_year',
            'total_order_amount' => $totalOrderAmount,
            'total_product_discount' => $totalProductDiscount,
            'total_coupon_discount' => $totalCouponDiscount,
            'total_referral_discount' => $totalReferralDiscount,
            'tota_order_due_amount' => $totalOrderDueAmount,
            'total_order_return_amount' => $totalOrderReturnAmount,
            'total_tax' => $totalTax,
            'total_order_commission' => $totalOrderCommission,
            'total_delivery_charge' => $totalDeliveryCharge,
            'total_deliveryman_incentive' => $totalDeliverymanIncentive,
        ];

        $mpdfView = View::make('admin-views.transaction.total_orders_report_pdf', ['data' => $data]);
        Helpers::gen_mpdf($mpdfView, 'order_transaction_summary_report_', $dateType);
    }
}
