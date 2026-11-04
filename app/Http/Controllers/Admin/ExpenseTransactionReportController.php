<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Contracts\Repositories\VendorRepositoryInterface;
use App\Enums\ExportFileNames\Admin\Report;
use App\Exports\ExpenseTransactionReportExport;
use App\Exports\OrderTransactionReportExport;
use App\Utils\Helpers;
use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\Order;
use App\Models\OrderTransaction;
use App\Models\Product;
use App\Models\Seller;
use App\Models\Shop;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Contracts\View\View as ViewResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExpenseTransactionReportController extends Controller
{
    public function __construct(
        private readonly VendorRepositoryInterface   $vendorRepo,
        private readonly CustomerRepositoryInterface $customerRepo,
    )
    {
    }

    private static function getExpenseTransactionTable($request, $query): LengthAwarePaginator
    {
        $page = $request['page'] ?? 1;
        $perPage = 20;
        $paged = $query->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $paged,
            $query->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    private static function getExpenseTransactionSummary($query): array
    {
        $freeDeliveryAmount = 0;
        $freeDeliveryOverAmount = 0;
        $couponDiscountAmount = 0;
        $referralDiscountAmount = 0;

        if ($query) {
            foreach ($query as $calculate) {
                $referralDiscountAmount += $calculate->refer_and_earn_discount;
                if (isset($calculate->coupon->coupon_type) && $calculate->coupon_discount_bearer == 'inhouse' && $calculate->coupon->coupon_type == 'free_delivery') {
                    $freeDeliveryAmount += $calculate->discount_amount;
                }

                if ($calculate->coupon_discount_bearer == 'inhouse' || ($calculate->coupon_discount_bearer == 'seller' && $calculate->seller_is == 'admin')) {
                    $couponDiscountAmount += $calculate->discount_amount;
                }

                if ($calculate->is_shipping_free && $calculate->free_delivery_bearer == 'admin') {
                    $freeDeliveryOverAmount += $calculate->extra_discount;
                }
            }
        }

        return [
            'total_expense' => $freeDeliveryAmount + $couponDiscountAmount + $referralDiscountAmount + $freeDeliveryOverAmount,
            'total_free_delivery' => $freeDeliveryAmount,
            'total_free_delivery_over_amount' => $freeDeliveryOverAmount,
            'total_coupon_discount' => $couponDiscountAmount,
            'total_referral_discount' => $referralDiscountAmount,
        ];
    }

    private static function getBaseQueryForExpenseTransactionQuery(Request $request)
    {
        return Order::with(['orderTransaction', 'coupon'])
            ->where([
                'order_type' => 'default_type',
                'order_status' => 'delivered'
            ])
            ->where(function ($query) {
                return $query->where('coupon_discount_bearer', 'inhouse')
                    ->orWhere('free_delivery_bearer', 'admin');
            })
            ->where(function ($query) {
                return $query->whereNotIn('coupon_code', ['0', 'NULL'])
                    ->orWhere(function ($query) {
                        return $query->where([
                            'extra_discount_type' => 'free_shipping_over_order_amount',
                            'free_delivery_bearer' => 'admin'
                        ]);
                    })->orWhere('refer_and_earn_discount', '>', 0);
            })
            ->when(!empty($request['search']), function ($query) use ($request) {
                $searchKeyword = $request['search'];
                return $query->where(function ($query) use ($searchKeyword) {
                    return $query->where('id', 'like', "%{$searchKeyword}%")
                        ->orWhereHas('orderTransaction', function ($query) use ($searchKeyword) {
                            return $query->where('transaction_id', 'like', "%{$searchKeyword}%");
                        });
                });
            })
            ->latest('created_at');
    }

    public function getExpenseChartCommonQuery($request)
    {
        $baseQuery = self::getBaseQueryForExpenseTransactionQuery($request);
        return self::getFormatDateWiseQueryFilter(
            query: $baseQuery,
            dateType: ($request['date_type'] ?? 'this_year'),
            from: $request['from'],
            to: $request['to']
        );
    }

    public function getFormatDateWiseQueryFilter($query, $dateType, $from, $to)
    {
        return $query->when(($dateType == 'this_year'), function ($query) {
                return $query->whereYear('created_at', date('Y'));
            })
            ->when(($dateType == 'this_month'), function ($query) {
                return $query->whereMonth('created_at', date('m'))
                    ->whereYear('created_at', date('Y'));
            })
            ->when(($dateType == 'this_week'), function ($query) {
                return $query->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]);
            })
            ->when(($dateType == 'today'), function ($query) {
                return $query->whereBetween('created_at', [Carbon::now()->startOfDay(), Carbon::now()->endOfDay()]);
            })
            ->when(($dateType == 'custom_date' && !is_null($from) && !is_null($to)), function ($query) use ($from, $to) {
                return $query->whereDate('created_at', '>=', $from)
                    ->whereDate('created_at', '<=', $to);
            });
    }

    public function getExpenseTransactionList(Request $request): ViewResponse
    {
        $search = $request['search'];
        $from = $request['from'];
        $to = $request['to'];
        $dateType = $request['date_type'] ?? 'this_year';

        $baseQuery = self::getBaseQueryForExpenseTransactionQuery(request: $request);
        $baseQuery = self::getFormatDateWiseQueryFilter(query: $baseQuery, dateType: $dateType, from: $from, to: $to);

        $expenseTransactionsTable = self::getExpenseTransactionTable(request: $request, query: $baseQuery->get());

        $expenseTransactionSummary = self::getExpenseTransactionSummary(query: $baseQuery->get());

        $expenseTransactionChart = self::getExpenseTransactionChartFilter($request);

        return view('admin-views.transaction.expense-list', [
            'expenseTransactionsTable' => $expenseTransactionsTable,
            'expenseTransactionChart' => $expenseTransactionChart,
            'expenseTransactionSummary' => $expenseTransactionSummary,
            'search' => $search,
            'from' => $from,
            'to' => $to,
            'date_type' => $dateType,
        ]);
    }

    public function generateOrderWiseExpenseTransactionPdf(Request $request)
    {
        $company_phone = BusinessSetting::where('type', 'company_phone')->first()->value;
        $company_email = BusinessSetting::where('type', 'company_email')->first()->value;
        $company_name = BusinessSetting::where('type', 'company_name')->first()->value;
        $company_web_logo = getWebConfig('company_web_logo');

        $transaction = Order::with(['orderTransaction', 'coupon'])->where('id', $request['id'])->first();
        $mpdf_view = View::make('admin-views.transaction.order_wise_expense_pdf', compact('company_phone', 'company_name', 'company_email', 'company_web_logo', 'transaction'));
        Helpers::gen_mpdf($mpdf_view, 'expense_transaction_', $request['id']);
    }

    public function expenseTransactionExportExcel(Request $request): BinaryFileResponse
    {
        $search = $request['search'];
        $from = $request['from'];
        $to = $request['to'];
        $dateType = $request['date_type'] ?? 'this_year';

        $baseQuery = self::getBaseQueryForExpenseTransactionQuery(request: $request);
        $transactions = self::getFormatDateWiseQueryFilter(query: $baseQuery, dateType: $dateType, from: $from, to: $to)->get();

        $data = [
            'search' => $search,
            'from' => $from,
            'to' => $to,
            'dateType' => $dateType,
            'transactions' => $transactions,
        ];
        return Excel::download(new ExpenseTransactionReportExport($data), Report::EXPENSE_TRANSACTION_REPORT_LIST);
    }

    public function generateExpenseTransactionSummaryPDF(Request $request)
    {
        $company_phone = BusinessSetting::where('type', 'company_phone')->first()->value;
        $company_email = BusinessSetting::where('type', 'company_email')->first()->value;
        $company_name = BusinessSetting::where('type', 'company_name')->first()->value;
        $company_web_logo = getWebConfig('company_web_logo');

        $search = $request['search'];
        $from = $request['from'];
        $to = $request['to'];
        $date_type = $request['date_type'] ?? 'this_year';

        $duration = str_replace('_', ' ', $date_type);
        if ($date_type == 'custom_date') {
            $duration = 'From ' . $from . ' To ' . $to;
        }

        $baseQuery = self::getBaseQueryForExpenseTransactionQuery(request: $request);
        $baseQuery = self::getFormatDateWiseQueryFilter(query: $baseQuery, dateType: $date_type, from: $from, to: $to)->get();
        $expenseTransactionSummary = self::getExpenseTransactionSummary(query: $baseQuery);

        $data = [
            'total_expense' => $expenseTransactionSummary['total_expense'],
            'free_delivery' => $expenseTransactionSummary['total_free_delivery'],
            'coupon_discount' => $expenseTransactionSummary['total_coupon_discount'],
            'referral_Discount' => $expenseTransactionSummary['total_referral_discount'],
            'free_over_amount_discount' => $expenseTransactionSummary['total_free_delivery_over_amount'],
            'company_phone' => $company_phone,
            'company_name' => $company_name,
            'company_email' => $company_email,
            'company_web_logo' => $company_web_logo,
            'duration' => $duration,
        ];

        $mpdf_view = View::make('admin-views.transaction.expense_transaction_summary_report_pdf', compact('data'));
        Helpers::gen_mpdf($mpdf_view, 'expense_transaction_summary_report_', $date_type);
    }

    public function getExpenseTransactionChartFilter($request)
    {
        $from = $request['from'];
        $to = $request['to'];
        $date_type = $request['date_type'] ?? 'this_year';

        if ($date_type == 'this_year') { //this year table
            $number = 12;
            $default_inc = 1;
            $currentStartYear = date('Y-01-01');
            $currentEndYear = date('Y-12-31');
            $from_year = Carbon::parse($from)->format('Y');
            return self::expense_transaction_same_year($request, $currentStartYear, $currentEndYear, $from_year, $number, $default_inc);
        } elseif ($date_type == 'this_month') { //this month table
            $current_month_start = date('Y-m-01');
            $current_month_end = date('Y-m-t');
            $inc = 1;
            $month = date('m');
            $number = date('d', strtotime($current_month_end));
            return self::expense_transaction_same_month($request, $current_month_start, $current_month_end, $month, $number, $inc);
        } elseif ($date_type == 'this_week') {
            return self::expense_transaction_this_week($request);
        } elseif ($date_type == 'today') {
            return self::getExpenseTransactionForToday($request);
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
                return self::expense_transaction_different_year($request, $start_date, $end_date, $from_year, $to_year);
            } elseif ($from_month != $to_month) {
                return self::expense_transaction_same_year($request, $start_date, $end_date, $from_year, $to_month, $from_month);
            } elseif ($from_month == $to_month) {
                return self::expense_transaction_same_month($request, $start_date, $end_date, $from_month, $to_day, $from_day);
            }
        }
    }

    public function expense_transaction_same_month($request, $start_date, $end_date, $month_date, $number, $default_inc)
    {
        $year_month = date('Y-m', strtotime($start_date));
        $month = substr(date("F", strtotime("$year_month")), 0, 3);
        $orders = self::getExpenseChartCommonQuery($request)
            ->selectRaw("*, DATE_FORMAT(created_at, '%d') as day")
            ->latest('created_at')->get();

        $discountAmount = [];
        for ($inc = $default_inc; $inc <= $number; $inc++) {
            $discountAmount[$inc] = 0;
            foreach ($orders as $match) {
                if ($match['day'] == $inc) {
                    if ($match->is_shipping_free && $match->free_delivery_bearer == 'admin') {
                        $discountAmount[$inc] += $match->extra_discount; // freeDeliveryDiscount
                    }
                    $discountAmount[$inc] += ($match->coupon_discount_bearer == 'inhouse' ? $match->discount_amount : 0); // couponDiscount
                    $discountAmount[$inc] += $match['refer_and_earn_discount']; // referralDiscount
                }
            }
        }

        return array(
            'discount_amount' => $discountAmount,
        );
    }

    public function expense_transaction_this_week($request)
    {
        $number = 6;
        $period = CarbonPeriod::create(Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek());
        $day_name = array();
        foreach ($period as $date) {
            $day_name[] = $date->format('l');
        }

        $orders = self::getExpenseChartCommonQuery($request)
            ->selectRaw("*, ((DAYOFWEEK(created_at) + 5) % 7) as day")
            ->latest('created_at')->get();

        $discountAmount = [];
        for ($inc = 0; $inc <= $number; $inc++) {
            $discountAmount[$day_name[$inc]] = 0;
            foreach ($orders as $match) {
                if ($match['day'] == $inc) {
                    if ($match->is_shipping_free && $match->free_delivery_bearer == 'admin') {
                        $discountAmount[$day_name[$inc]] += $match->extra_discount; // freeDeliveryDiscount
                    }
                    $discountAmount[$day_name[$inc]] += ($match->coupon_discount_bearer == 'inhouse' ? $match->discount_amount : 0); // couponDiscount
                    $discountAmount[$day_name[$inc]] += $match['refer_and_earn_discount']; // referralDiscount
                }
            }
        }

        return array(
            'discount_amount' => $discountAmount,
        );
    }

    public function getExpenseTransactionForToday($request): array
    {
        $number = 1;
        $dayName = [Carbon::today()->format('l')];
        $orders = self::getExpenseChartCommonQuery($request)
            ->selectRaw("*, DATE_FORMAT(created_at, '%W') as day")
            ->latest('created_at')->get();

        for ($inc = 0; $inc < $number; $inc++) {
            $discountAmount[$dayName[$inc]] = 0;
            foreach ($orders as $match) {
                if ($match['day'] == $dayName[$inc]) {
                    if ($match->is_shipping_free && $match->free_delivery_bearer == 'admin') {
                        $discountAmount[$dayName[$inc]] += $match->extra_discount; // freeDeliveryDiscount
                    }
                    $discountAmount[$dayName[$inc]] += ($match->coupon_discount_bearer == 'inhouse' ? $match->discount_amount : 0); // couponDiscount
                    $discountAmount[$dayName[$inc]] += $match['refer_and_earn_discount']; // referralDiscount
                }
            }
        }

        return [
            'discount_amount' => $discountAmount ?? [],
        ];
    }

    public function expense_transaction_same_year($request, $start_date, $end_date, $from_year, $number, $default_inc)
    {
        $orders = self::getExpenseChartCommonQuery($request)
            ->selectRaw("*, DATE_FORMAT(created_at, '%m') as month")
            ->latest('created_at')->get();

        $discountAmount = [];
        for ($inc = $default_inc; $inc <= $number; $inc++) {
            $month = date("F", strtotime("2023-$inc-01"));
            $discountAmount[$month] = 0;
            foreach ($orders as $match) {
                if ((int)$match['month'] == $inc) {
                    if ($match->is_shipping_free && $match->free_delivery_bearer == 'admin') {
                        $discountAmount[$month] += $match->extra_discount; // freeDeliveryDiscount
                    }
                    $discountAmount[$month] += ($match['seller_is'] == 'admin' || $match->coupon_discount_bearer == 'inhouse') ? $match->discount_amount : 0; // couponDiscount
                    $discountAmount[$month] += $match['refer_and_earn_discount']; // referralDiscount
                }
            }
        }

        return [
            'discount_amount' => $discountAmount
        ];
    }

    public function expense_transaction_different_year($request, $start_date, $end_date, $from_year, $to_year): array
    {
        $orders = self::getExpenseChartCommonQuery($request)
            ->selectRaw("*, DATE_FORMAT(created_at, '%Y') as year")
            ->latest('created_at')->get();

        $discountAmount = [];
        for ($inc = $from_year; $inc <= $to_year; $inc++) {
            $discountAmount[$inc] = 0;
            foreach ($orders as $match) {
                if ($match['year'] == $inc) {
                    if ($match->is_shipping_free && $match->free_delivery_bearer == 'admin') {
                        $discountAmount[$inc] += $match->extra_discount; // freeDeliveryDiscount
                    }
                    $discountAmount[$inc] += ($match->coupon_discount_bearer == 'inhouse' ? $match->discount_amount : 0); // couponDiscount
                    $discountAmount[$inc] += $match['refer_and_earn_discount']; // referralDiscount
                }
            }
        }

        return [
            'discount_amount' => $discountAmount
        ];

    }
}
