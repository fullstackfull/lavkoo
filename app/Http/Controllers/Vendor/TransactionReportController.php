<?php

namespace App\Http\Controllers\Vendor;

use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Contracts\Repositories\VendorRepositoryInterface;
use App\Enums\ExportFileNames\Admin\Report;
use App\Exports\ExpenseTransactionReportExport;
use App\Exports\OrderTransactionReportExport;
use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\Order;
use App\Models\OrderTransaction;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\Admin\Reports\TransactionReportService;
use App\Utils\BackEndHelper;
use App\Utils\Helpers;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFacade;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Rap2hpoutre\FastExcel\FastExcel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TransactionReportController extends Controller
{
    public function __construct(
        private readonly VendorRepositoryInterface   $vendorRepo,
        private readonly CustomerRepositoryInterface $customerRepo,
        private readonly TransactionReportService    $transactionReportService,
    )
    {
    }

    private function getOrderTransactionListData($transactions)
    {
        $transactionsTableData = [];
        foreach ($transactions as $key => $transaction) {
            if ($transaction?->order) {
                $shopName = $transaction['seller_is'] == 'admin' ? getInHouseShopConfig(key: 'name') : ($transaction?->seller?->shop?->name ?? translate('not_found'));
                $customer = !$transaction?->order?->is_guest && $transaction?->customer ? $transaction->customer : null;
                $customerId = !$transaction?->order?->is_guest && $customer ? $customer->id : null;

                $orderDetailsSumPrice = $transaction?->orderDetails[0]?->order_details_sum_price ?? 0;
                $orderDetailsSumDiscount = $transaction?->orderDetails[0]?->order_details_sum_discount ?? 0;
                $referAndEarnDiscount = $transaction?->order?->refer_and_earn_discount ?? 0;
                $couponDiscount = isset($transaction->order->coupon) && $transaction->order->coupon->coupon_type != 'free_delivery' ? $transaction->order->discount_amount : 0;
                $discountAmount = $orderDetailsSumPrice - $orderDetailsSumDiscount - $couponDiscount - ($referAndEarnDiscount);
                $deliveryManIncentive = $transaction->order->delivery_type == 'self_delivery' && $transaction->order->delivery_man_id ? $transaction->order->deliveryman_charge : 0;

                $adminCouponDiscount = ($transaction->order->coupon_discount_bearer == 'inhouse' && $transaction->order->discount_type == 'coupon_discount') ? $transaction->order->discount_amount : 0;
                $adminShippingDiscount = ($transaction->order->free_delivery_bearer == 'admin' && $transaction->order->is_shipping_free) ? $transaction->order->extra_discount : 0;
                $adminDiscount = $adminCouponDiscount + $adminShippingDiscount + $referAndEarnDiscount;

                $sellerCouponDiscount = ($transaction->order->coupon_discount_bearer == 'seller' && $transaction->order->discount_type == 'coupon_discount') ? $transaction->order->discount_amount : 0;
                $sellerShippingDiscount = ($transaction->order->free_delivery_bearer == 'seller' && $transaction->order->is_shipping_free) ? $transaction->order->extra_discount : 0;
                $vendorDiscount = $sellerCouponDiscount + $sellerShippingDiscount;

                $adminNetIncome = 0;
                if ($transaction['seller_is'] == 'admin') {
                    $adminNetIncome += $transaction['order_amount'];
                }
                if (isset($transaction->order->deliveryMan) && $transaction->order->deliveryMan->seller_id == 0) {
                    $adminNetIncome += $transaction['delivery_charge'];
                } elseif (!isset($transaction->order->deliveryMan) && ($transaction['seller_is'] == 'seller') && ($transaction->order->shipping_responsibility == 'inhouse_shipping' || $transaction['seller_is'] == 'admin')) {
                    $adminNetIncome += $transaction->order->is_shipping_free && $transaction->order->free_delivery_bearer == 'admin' ? 0 : $transaction->order->shipping_cost;
                }

                $adminNetIncome += $transaction['admin_commission'];
                $adminNetIncome -= $transaction?->order?->refer_and_earn_discount ?? 0;

                if ((empty($transaction?->order?->delivery_type) || $transaction->order->delivery_type == 'self_delivery') && ($transaction->order->shipping_responsibility == 'inhouse_shipping' || $transaction->order->seller_is == 'admin')) {
                    $adminNetIncome -= $transaction->order->deliveryman_charge;
                }

                if ($transaction['seller_is'] == 'seller') {
                    $adminNetIncome -= $transaction->order->coupon_discount_bearer == 'inhouse' ? $adminCouponDiscount : 0;
                }

                if ($transaction->order->shipping_responsibility == 'inhouse_shipping') {
                    if (!$transaction->order->is_shipping_free) {
                        $adminNetIncome += $transaction['seller_is'] == 'admin' ? $transaction->order->shipping_cost : 0;
                    }
                    if ($transaction->order->is_shipping_free) {
                        $adminNetIncome += 0;
                    } else if (($transaction->order->coupon_discount_bearer == 'seller' && isset($transaction->order->coupon) && $transaction->order->coupon->coupon_type == 'free_delivery')) {
                        $adminNetIncome += $sellerCouponDiscount;
                    }
                } elseif ($transaction->order->shipping_responsibility == 'sellerwise_shipping' && $transaction['seller_is'] == 'seller') {
                    $adminNetIncome -= $transaction->order->free_delivery_bearer == 'admin' ? $adminShippingDiscount : 0;
                }

                $vendorNetIncome = 0;
                if ($transaction['seller_is'] == 'seller') {
                    $vendorNetIncome += $transaction['order_amount'] - $transaction['admin_commission'];
                    if (isset($transaction->order->deliveryMan) && $transaction->order->deliveryMan->seller_id != 0) {
                        $vendorNetIncome += $transaction['delivery_charge'];
                    }
                }

                if ((empty($transaction?->order?->delivery_type) || $transaction->order->delivery_type == 'self_delivery') && $transaction->order->shipping_responsibility == 'sellerwise_shipping' && $transaction->order->seller_is == 'seller') {
                    $vendorNetIncome -= $transaction->order->deliveryman_charge;
                }

                if ($transaction['seller_is'] == 'seller') {
                    $vendorNetIncome += $transaction->order->coupon_discount_bearer == 'inhouse' ? $adminCouponDiscount : 0;
                }

                if ($transaction['seller_is'] == 'seller') {
                    if ($transaction->order->shipping_responsibility == 'inhouse_shipping') {
                        $vendorNetIncome -= $transaction->order->shipping_cost;
                        $vendorNetIncome += $transaction->order->free_delivery_bearer == 'admin' && $transaction->order->is_shipping_free ? $transaction->order->shipping_cost : 0;
                    } elseif ($transaction->order->shipping_responsibility == 'sellerwise_shipping') {
                        $vendorNetIncome += $transaction->order->free_delivery_bearer == 'admin' ? $transaction->order->shipping_cost : 0;
                        $vendorNetIncome -= ($transaction->order->free_delivery_bearer == 'seller') ? $transaction->order->shipping_cost : 0;
                    }
                }

                if ($transactions instanceof \Illuminate\Pagination\LengthAwarePaginator) {
                    $firstItem = $transactions->firstItem();
                } elseif ($transactions instanceof \Illuminate\Support\Collection) {
                    $firstItem = 1; // default starting index
                } else {
                    $firstItem = 1;
                }

                $transactionsTableData[$firstItem + $key] = [
                    'order_id' => $transaction['order_id'],
                    'shop_name' => $shopName,
                    'is_guest' => $transaction?->order?->is_guest ?? 0,
                    'customer_id' => $customerId,
                    'customer_name' => $customer ? $customer?->f_name . ' ' . $customer?->l_name : ($transaction?->order?->is_guest ? translate('guest_customer') : translate('not_found')),
                    'total_product_amount' => $orderDetailsSumPrice,
                    'product_discount' => $orderDetailsSumDiscount,
                    'coupon_discount' => $transaction?->order?->discount_amount ?? 0,
                    'referral_discount' => $transaction?->order?->refer_and_earn_discount ?? 0,
                    'discounted_amount' => $discountAmount,
                    'tax' => $transaction?->tax ?? 0,
                    'shipping_charge' => $transaction?->order?->shipping_cost ?? 0,
                    'order_amount' => $transaction?->order?->order_amount ?? 0,
                    'delivered_by' => $transaction?->delivered_by ?? '',
                    'deliveryman_incentive' => $deliveryManIncentive,
                    'admin_discount' => $adminDiscount,
                    'vendor_discount' => $vendorDiscount,
                    'admin_commission' => $transaction?->admin_commission ?? 0,
                    'admin_net_income' => $adminNetIncome,
                    'vendor_net_income' => $vendorNetIncome,
                    'payment_method' => $transaction?->payment_method ?? '',
                    'payment_status' => $transaction?->status ?? '',
                    'seller_is' => $transaction->seller_is ?? '',
                    'shipping_responsibility' => $transaction?->order?->shipping_responsibility ?? '',

                    'admin_coupon_discount' => $adminCouponDiscount,
                    'seller_coupon_discount' => $sellerCouponDiscount,
                ];
            }
        }
        return $transactionsTableData;
    }

    public function order_transaction_list(Request $request): View
    {
        $search = $request['search'];
        $from = $request['from'];
        $to = $request['to'];
        $customer_id = $request['customer_id'] ?? 'all';
        $status = $request['status'] ?? 'all';
        $date_type = $request['date_type'] ?? 'this_year';
        $query_param = ['search' => $search, 'status' => $status, 'customer_id' => $customer_id, 'date_type' => $date_type, 'from' => $from, 'to' => $to];

        $customers = User::whereNotIn('id', [0])->get();

        $transactions = self::order_transaction_table_data_filter($request);
        $transactions = $transactions->latest('created_at')->paginate(Helpers::pagination_limit())->appends($query_param);

        $order_transaction_chart = self::order_transaction_chart_filter($request);

        $active_products = Product::where([
            'user_id' => auth('seller')->id(),
            'added_by' => 'seller',
            'status' => 1,
            'request_status' => 1
        ])->count();

        $inactive_products = Product::where([
            'user_id' => auth('seller')->id(),
            'added_by' => 'seller',
            'status' => 0,
            'request_status' => 1
        ])->count();

        $pending_products = Product::where([
            'user_id' => auth('seller')->id(),
            'added_by' => 'seller',
            'status' => 0,
            'request_status' => 0
        ])->count();

        $product_data = [
            'total_products' => $active_products + $inactive_products + $pending_products,
            'active_products' => $active_products,
            'inactive_products' => $inactive_products,
            'pending_products' => $pending_products,
        ];

        $payment_data = self::getVendorOrderTransactionPaymentFormattedData(request: $request);
        $transactionsTableData = $this->transactionReportService->getOrderTransactionListFormatedData($transactions);
        $chartTransactionOrderLabel = array_values(collect(array_keys($order_transaction_chart['order_amount']))->map(function ($item) use ($request) {
            if ($request['date_type'] == 'this_month') {
                return $item . ' ' . date('M');
            }
            return $item;
        })->toArray());

        return view('vendor-views.transaction.order-list', compact('transactionsTableData', 'customers', 'transactions', 'product_data', 'search',
            'order_transaction_chart', 'payment_data', 'status', 'date_type', 'from', 'to', 'customer_id', 'chartTransactionOrderLabel'));
    }

    public function getVendorOrderTransactionPaymentFormattedData(object|array $request): array
    {
        $digitalPaymentQuery = Order::whereNotIn('payment_method', ['cash', 'cash_on_delivery', 'pay_by_wallet', 'offline_payment']);
        $digitalPayment = self::order_transaction_piechart_query($request, $digitalPaymentQuery)->sum('init_order_amount');

        $cashPaymentQuery = Order::whereIn('payment_method', ['cash', 'cash_on_delivery']);
        $cashPayment = self::order_transaction_piechart_query($request, $cashPaymentQuery)->sum('init_order_amount');

        $walletPaymentQuery = Order::where(['payment_method' => 'pay_by_wallet']);
        $walletPayment = self::order_transaction_piechart_query($request, $walletPaymentQuery)->sum('init_order_amount');

        $offlinePaymentQuery = Order::where(['payment_method' => 'offline_payment']);
        $offlinePayment = self::order_transaction_piechart_query($request, $offlinePaymentQuery)->sum('init_order_amount');

        $orderEditHistory = self::order_transaction_piechart_query($request, Order::with(['orderEditHistory']))->get();
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
            'digital_payment' => $digitalPayment + $orderEditDigitalPayment,
            'cash_payment' => $cashPayment + $orderEditCashPayment,
            'wallet_payment' => $walletPayment + $orderEditWalletPayment,
            'offline_payment' => $offlinePayment + $orderEditOfflinePayment,
            'total_payment' => ($totalPayment + $editTotalPayment) - $orderEditReturnAmount,
            'return_amount' => $orderEditReturnAmount,
        ];
    }

    public function orderTransactionExportExcel(Request $request): BinaryFileResponse
    {
        $search = $request['search'];
        $from = $request['from'];
        $to = $request['to'];
        $dateType = $request['date_type'] ?? 'this_year';
        $vendorId = auth('seller')->id();
        $vendor = $this->vendorRepo->getFirstWhere(params: ['id' => $vendorId]);
        $customer = isset($request['customer_id']) && $request['customer_id'] != 'all' ? $this->customerRepo->getFirstWhere(params: ['id' => $request['customer_id']]) : 'all';

        $transactions = self::order_transaction_table_data_filter($request)->latest('created_at')->get();
        $transactions->map(function ($transaction) {
            $transaction['adminCouponDiscount'] = ($transaction->order->coupon_discount_bearer == 'inhouse' && $transaction->order->discount_type == 'coupon_discount') ? $transaction->order->discount_amount : 0;
            $transaction['adminShippingDiscount'] = ($transaction->order->free_delivery_bearer == 'admin' && $transaction->order->is_shipping_free) ? $transaction->order->extra_discount : 0;
            $transaction['vendorCouponDiscount'] = ($transaction->order->coupon_discount_bearer == 'seller' && $transaction->order->discount_type == 'coupon_discount') ? $transaction->order->discount_amount : 0;
            $transaction['vendorShippingDiscount'] = ($transaction->order->free_delivery_bearer == 'seller' && $transaction->order->is_shipping_free) ? $transaction->order->extra_discount : 0;
            $transaction['total_product_amount'] = $transaction?->orderDetails[0]?->order_details_sum_price ?? 0;

            $vendorNetIncome = 0;
            if (isset($transaction->order->deliveryMan) && $transaction->order->deliveryMan->seller_id != '0') {
                $vendorNetIncome += $transaction['delivery_charge'];
            }
            if ($transaction['seller_is'] == 'seller') {
                $vendorNetIncome += $transaction['order_amount'] + $transaction['tax'] - $transaction['admin_commission'];
            }
            if ($transaction->order->delivery_type == 'self_delivery' && $transaction->order->shipping_responsibility == 'sellerwise_shipping' && $transaction->order->seller_is == 'seller') {
                $vendorNetIncome -= $transaction->order->deliveryman_charge;
            }
            if ($transaction['seller_is'] == 'seller') {
                if ($transaction->order->shipping_responsibility == 'inhouse_shipping') {
                    $vendorNetIncome += $transaction->order->coupon_discount_bearer == 'inhouse' ? $transaction['adminCouponDiscount'] : 0;
                    $vendorNetIncome -= ($transaction->order->coupon_discount_bearer == 'seller' && isset($transaction->order->coupon) && $transaction->order->coupon->coupon_type == 'free_delivery') ? $transaction['adminCouponDiscount'] : 0;
                    $vendorNetIncome -= ($transaction->order->free_delivery_bearer == 'seller') ? $transaction['adminShippingDiscount'] : 0;

                } elseif ($transaction->order->shipping_responsibility == 'sellerwise_shipping') {
                    $vendorNetIncome += $transaction->order->coupon_discount_bearer == 'inhouse' ? $transaction['adminCouponDiscount'] : 0;
                    $vendorNetIncome += $transaction->order->free_delivery_bearer == 'admin' ? $transaction['adminShippingDiscount'] : 0;
                    $transaction['vendorShippingDiscount'] = 0;
                }
            }
            $customer = $transaction->customer;
            $transaction['vendor_net_income'] = $vendorNetIncome;
            $transaction['payment_status'] = $transaction?->status ?? '';
            $transaction['payment_method'] = $transaction?->payment_method ?? '';
            $transaction['is_guest'] =  $transaction?->order?->is_guest ?? 0;
            $transaction['delivered_by'] =  $transaction?->delivered_by ?? '';
            $transaction['customer'] = !$transaction?->order?->is_guest && $transaction?->customer ? $transaction->customer : null;
            $transaction['customer_name'] = $customer ? $customer?->f_name . ' ' . $customer?->l_name : ($transaction?->order?->is_guest ? translate('guest_customer') : translate('not_found'));
        });

        $data = [
            'data-from' => 'vendor',
            'search' => $search,
            'from' => $from,
            'to' => $to,
            'dateType' => $dateType,
            'vendor' => $vendor,
            'customer' => $customer,
            'transactions' => $transactions,
        ];
        return Excel::download(new OrderTransactionReportExport($data), Report::ORDER_TRANSACTION_REPORT_LIST);
    }

    public function order_transaction_summary_pdf(Request $request)
    {
        $company_phone = BusinessSetting::where('type', 'company_phone')->first()->value;
        $company_email = BusinessSetting::where('type', 'company_email')->first()->value;
        $company_name = BusinessSetting::where('type', 'company_name')->first()->value;
        $company_web_logo = getWebConfig('company_web_logo');

        $from = $request['from'];
        $to = $request['to'];
        $customer_id = $request['customer_id'] ?? 'all';
        $status = $request['status'] ?? 'all';
        $date_type = $request['date_type'] ?? 'this_year';

        $duration = str_replace('_', ' ', $date_type);
        if ($date_type == 'custom_date') {
            $duration = 'From ' . $from . ' To ' . $to;
        }

        $seller_info = Shop::where('seller_id', auth('seller')->id())->first()->name;
        $customer_info = 'all';
        if ($customer_id != 'all') {
            $customer = User::select()->find($customer_id);
            $customer_info = $customer->f_name . ' ' . $customer->l_name;
        }

        $transactions = self::order_transaction_table_data_filter($request)->latest('created_at')->get();

        $total_ordered_product_price = 0;
        $total_product_discount = 0;
        $total_coupon_discount = 0;
        $total_referral_discount = 0;
        $total_discounted_amount = 0;
        $total_tax = 0;
        $total_delivery_charge = 0;
        $total_order_amount = 0;
        $total_admin_discount = 0;
        $total_seller_discount = 0;
        $total_admin_commission = 0;
        $total_admin_net_income = 0;
        $total_seller_net_income = 0;
        $total_deliveryman_incentive = 0;
        foreach ($transactions as $transaction) {
            if ($transaction->order) {
                $admin_coupon_discount = ($transaction->order->coupon_discount_bearer == 'inhouse' && $transaction->order->discount_type == 'coupon_discount') ? $transaction->order->discount_amount : 0;
                $admin_shipping_discount = ($transaction->order->is_shipping_free && $transaction->order->free_delivery_bearer == 'admin') ? $transaction->order->extra_discount : 0;

                $seller_coupon_discount = ($transaction->order->coupon_discount_bearer == 'seller' && $transaction->order->discount_type == 'coupon_discount') ? $transaction->order->discount_amount : 0;
                $seller_shipping_discount = ($transaction->order->is_shipping_free && $transaction->order->free_delivery_bearer == 'seller') ? $transaction->order->extra_discount : 0;

                $total_ordered_product_price += $transaction->orderDetails[0]->order_details_sum_price;
                $total_product_discount += $transaction->orderDetails[0]->order_details_sum_discount;
                $total_coupon_discount += $transaction->order->discount_amount;
                $total_referral_discount += $transaction?->order?->refer_and_earn_discount ?? 0;
                $total_discounted_amount += $transaction->orderDetails[0]->order_details_sum_price - $transaction->orderDetails[0]->order_details_sum_discount - (isset($transaction->order->coupon) && $transaction->order->coupon->coupon_type != 'free_delivery' ? $transaction->order->discount_amount : 0);
                $total_tax += $transaction->tax;
                $total_delivery_charge += $transaction->order->shipping_cost;
                $total_order_amount += $transaction->order->order_amount;

                $total_admin_discount += $admin_coupon_discount + $admin_shipping_discount;
                $total_seller_discount += $seller_coupon_discount + $seller_shipping_discount;
                $total_admin_commission += $transaction->admin_commission;
                $total_deliveryman_incentive += ($transaction->order->shipping_responsibility == 'sellerwise_shipping' && $transaction->order->delivery_type == 'self_delivery' && $transaction->order->delivery_man_id && $transaction->order->seller_is == 'seller') ? $transaction->order->deliveryman_charge : 0;

                // seller net income calculation start
                $orderDetailsSumPrice = $transaction?->orderDetails[0]?->order_details_sum_price ?? 0;
                $orderDetailsSumDiscount = $transaction?->orderDetails[0]?->order_details_sum_discount ?? 0;
                $referAndEarnDiscount = $transaction?->order?->refer_and_earn_discount ?? 0;
                $couponDiscount = isset($transaction->order->coupon) && $transaction->order->coupon->coupon_type != 'free_delivery' ? $transaction->order->discount_amount : 0;
                $discountAmount = $orderDetailsSumPrice - $orderDetailsSumDiscount - $couponDiscount - ($referAndEarnDiscount);
                $deliveryManIncentive = $transaction->order->delivery_type == 'self_delivery' && $transaction->order->delivery_man_id ? $transaction->order->deliveryman_charge : 0;

                $adminCouponDiscount = ($transaction->order->coupon_discount_bearer == 'inhouse' && $transaction->order->discount_type == 'coupon_discount') ? $transaction->order->discount_amount : 0;
                $adminShippingDiscount = ($transaction->order->free_delivery_bearer == 'admin' && $transaction->order->is_shipping_free) ? $transaction->order->extra_discount : 0;
                $adminDiscount = $adminCouponDiscount + $adminShippingDiscount + $referAndEarnDiscount;

                $sellerCouponDiscount = ($transaction->order->coupon_discount_bearer == 'seller' && $transaction->order->discount_type == 'coupon_discount') ? $transaction->order->discount_amount : 0;
                $sellerShippingDiscount = ($transaction->order->free_delivery_bearer == 'seller' && $transaction->order->is_shipping_free) ? $transaction->order->extra_discount : 0;
                $vendorDiscount = $sellerCouponDiscount + $sellerShippingDiscount;

                $adminNetIncome = 0;
                if ($transaction['seller_is'] == 'admin') {
                    $adminNetIncome += $transaction['order_amount'];
                }
                if (isset($transaction->order->deliveryMan) && $transaction->order->deliveryMan->seller_id == 0) {
                    $adminNetIncome += $transaction['delivery_charge'];
                } elseif (!isset($transaction->order->deliveryMan) && ($transaction['seller_is'] == 'seller') && ($transaction->order->shipping_responsibility == 'inhouse_shipping' || $transaction['seller_is'] == 'admin')) {
                    $adminNetIncome += $transaction->order->is_shipping_free && $transaction->order->free_delivery_bearer == 'admin' ? 0 : $transaction->order->shipping_cost;
                }

                $adminNetIncome += $transaction['admin_commission'];
                $adminNetIncome -= $transaction?->order?->refer_and_earn_discount ?? 0;

                if ((empty($transaction?->order?->delivery_type) || $transaction->order->delivery_type == 'self_delivery') && ($transaction->order->shipping_responsibility == 'inhouse_shipping' || $transaction->order->seller_is == 'admin')) {
                    $adminNetIncome -= $transaction->order->deliveryman_charge;
                }

                if ($transaction['seller_is'] == 'seller') {
                    $adminNetIncome -= $transaction->order->coupon_discount_bearer == 'inhouse' ? $adminCouponDiscount : 0;
                }

                if ($transaction->order->shipping_responsibility == 'inhouse_shipping') {
                    if (!$transaction->order->is_shipping_free) {
                        $adminNetIncome += $transaction['seller_is'] == 'admin' ? $transaction->order->shipping_cost : 0;
                    }
                    if ($transaction->order->is_shipping_free) {
                        $adminNetIncome += 0;
                    } else if (($transaction->order->coupon_discount_bearer == 'seller' && isset($transaction->order->coupon) && $transaction->order->coupon->coupon_type == 'free_delivery')) {
                        $adminNetIncome += $sellerCouponDiscount;
                    }
                } elseif ($transaction->order->shipping_responsibility == 'sellerwise_shipping' && $transaction['seller_is'] == 'seller') {
                    $adminNetIncome -= $transaction->order->free_delivery_bearer == 'admin' ? $adminShippingDiscount : 0;
                }

                $total_admin_net_income += $adminNetIncome;

                $vendorNetIncome = 0;
                if ($transaction['seller_is'] == 'seller') {
                    $vendorNetIncome += $transaction['order_amount'] - $transaction['admin_commission'];
                    if (isset($transaction->order->deliveryMan) && $transaction->order->deliveryMan->seller_id != 0) {
                        $vendorNetIncome += $transaction['delivery_charge'];
                    }
                }

                if ((empty($transaction?->order?->delivery_type) || $transaction->order->delivery_type == 'self_delivery') && $transaction->order->shipping_responsibility == 'sellerwise_shipping' && $transaction->order->seller_is == 'seller') {
                    $vendorNetIncome -= $transaction->order->deliveryman_charge;
                }

                if ($transaction['seller_is'] == 'seller') {
                    $vendorNetIncome += $transaction->order->coupon_discount_bearer == 'inhouse' ? $adminCouponDiscount : 0;
                }

                if ($transaction['seller_is'] == 'seller') {
                    if ($transaction->order->shipping_responsibility == 'inhouse_shipping') {
                        $vendorNetIncome -= $transaction->order->shipping_cost;
                        $vendorNetIncome += $transaction->order->free_delivery_bearer == 'admin' && $transaction->order->is_shipping_free ? $transaction->order->shipping_cost : 0;
                    } elseif ($transaction->order->shipping_responsibility == 'sellerwise_shipping') {
                        $vendorNetIncome += $transaction->order->free_delivery_bearer == 'admin' ? $transaction->order->shipping_cost : 0;
                        $vendorNetIncome -= ($transaction->order->free_delivery_bearer == 'seller') ? $transaction->order->shipping_cost : 0;
                    }
                }
                $total_seller_net_income += $vendorNetIncome;
                // seller net income calculation end
            }
        }

        $in_house_orders_query = Order::where(['seller_is' => 'admin']);
        $in_house_orders = self::order_transaction_count_query($in_house_orders_query, $request)->count();

        $seller_orders_query = Order::where(['seller_is' => 'seller']);
        $seller_orders = self::order_transaction_count_query($seller_orders_query, $request)->count();
        $total_orders = $in_house_orders + $seller_orders;

        $ongoing_order_query = Order::whereIn('order_status', ['out_for_delivery', 'processing', 'confirmed', 'pending'])
            ->where(['seller_id' => auth('seller')->id(), 'seller_is' => 'seller']);
        $ongoing_order = self::date_wise_common_filter($ongoing_order_query, $date_type, $from, $to)->count();

        $cancel_order_query = Order::whereIn('order_status', ['canceled', 'failed', 'returned'])
            ->where(['seller_id' => auth('seller')->id(), 'seller_is' => 'seller']);
        $canceled_order = self::date_wise_common_filter($cancel_order_query, $date_type, $from, $to)->count();

        $completed_order_query = Order::where('order_status', 'delivered')
            ->where(['seller_id' => auth('seller')->id(), 'seller_is' => 'seller']);
        $completed_order = self::date_wise_common_filter($completed_order_query, $date_type, $from, $to)->count();

        $total_order = $canceled_order + $ongoing_order + $completed_order;

        $data = [
            'total_ordered_product_price' => $total_ordered_product_price,
            'total_product_discount' => $total_product_discount,
            'total_coupon_discount' => $total_coupon_discount,
            'total_referral_discount' => $total_referral_discount,
            'total_discounted_amount' => $total_discounted_amount,
            'total_tax' => $total_tax,
            'total_delivery_charge' => $total_delivery_charge,
            'total_order_amount' => $total_order_amount,
            'total_admin_discount' => $total_admin_discount,
            'total_vendor_discount' => $total_seller_discount,
            'total_admin_commission' => $total_admin_commission,
            'total_vendor_net_income' => $total_seller_net_income,
            'total_deliveryman_incentive' => $total_deliveryman_incentive,
            'total_orders' => $total_orders,
            'in_house_orders' => $in_house_orders,
            'seller_orders' => $seller_orders,
            'ongoing_order' => $ongoing_order,
            'canceled_order' => $canceled_order,
            'completed_order' => $completed_order,
            'total_order' => $total_order,
        ];

        $mpdf_view = ViewFacade::make('vendor-views.transaction.order_transaction_summary_report_pdf', compact('data', 'company_phone', 'company_name', 'company_email', 'company_web_logo', 'status', 'duration', 'seller_info', 'customer_info'));
        Helpers::gen_mpdf($mpdf_view, 'order_transaction_summary_report_', $date_type);
    }

    public function pdf_order_wise_transaction(Request $request)
    {
        $company_phone = BusinessSetting::where('type', 'company_phone')->first()->value;
        $company_email = BusinessSetting::where('type', 'company_email')->first()->value;
        $company_name = BusinessSetting::where('type', 'company_name')->first()->value;
        $company_web_logo = getWebConfig('company_web_logo');

        $transaction = OrderTransaction::with(['seller.shop', 'customer', 'order', 'orderDetails'])
            ->withSum('orderDetails', 'price')
            ->withSum('orderDetails', 'discount')
            ->withSum('orderDetails', 'qty')
            ->where(['order_id' => $request->order_id, 'seller_is' => 'seller', 'seller_id' => auth('seller')->id()])
            ->first();

        $mpdf_view = ViewFacade::make('vendor-views.transaction.order_wise_pdf', compact('company_phone', 'company_name', 'company_email', 'company_web_logo', 'transaction'));
        Helpers::gen_mpdf($mpdf_view, 'order_transaction_', $request->order_id);

    }

    public function order_transaction_piechart_query($request, $query)
    {
        $from = $request['from'];
        $to = $request['to'];
        $customer_id = $request['customer_id'] ?? 'all';
        $status = $request['status'] ?? 'all';
        $date_type = $request['date_type'] ?? 'this_year';

        $query_data = $query->where(['payment_status' => 'paid'])
            ->whereHas('orderTransaction', function ($query) use ($status) {
                $query->when($status != 'all', function ($query) use ($status) {
                    $query->where(['status' => $status]);
                });
            })
            ->when($customer_id != 'all', function ($query) use ($customer_id) {
                $query->where('customer_id', $customer_id);
            })
            ->where(['seller_is' => 'seller', 'seller_id' => auth('seller')->id()]);

        return self::date_wise_common_filter($query_data, $date_type, $from, $to);
    }

    public function order_transaction_table_data_filter($request)
    {
        $search = $request['search'];
        $from = $request['from'];
        $to = $request['to'];
        $customer_id = $request['customer_id'] ?? 'all';
        $status = $request['status'] ?? 'all';
        $date_type = $request['date_type'] ?? 'this_year';

        $transaction_query = OrderTransaction::with(['seller.shop', 'customer', 'order.deliveryMan'])
            ->with(['orderDetails' => function ($query) {
                $query->selectRaw("*, sum(qty*price) as order_details_sum_price, sum(discount) as order_details_sum_discount")
                    ->groupBy('order_id');
            }])
            ->when($search, function ($q) use ($search) {
                $q->orWhere('order_id', 'like', "%{$search}%")
                    ->orWhere('transaction_id', 'like', "%{$search}%");
            })
            ->when($status != 'all', function ($query) use ($status) {
                $query->where(['status' => $status]);
            })
            ->when($customer_id != 'all', function ($query) use ($customer_id) {
                $query->where('customer_id', $customer_id);
            })
            ->where(['seller_is' => 'seller', 'seller_id' => auth('seller')->id()]);
        return self::date_wise_common_filter($transaction_query, $date_type, $from, $to);
    }

    public function order_transaction_chart_filter($request)
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
            return self::order_transaction_same_year($request, $current_start_year, $current_end_year, $from_year, $number, $default_inc);
        } elseif ($date_type == 'this_month') {
            $current_month_start = date('Y-m-01');
            $current_month_end = date('Y-m-t');
            $inc = 1;
            $month = date('m');
            $number = date('d', strtotime($current_month_end));
            return self::order_transaction_same_month($request, $current_month_start, $current_month_end, $month, $number, $inc);
        } elseif ($date_type == 'this_week') {
            return self::order_transaction_this_week($request);
        } elseif ($date_type == 'today') {
            return self::getOrderTransactionForToday($request);
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
                return self::order_transaction_different_year($request, $start_date, $end_date, $from_year, $to_year);
            } elseif ($from_month != $to_month) {
                return self::order_transaction_same_year($request, $start_date, $end_date, $from_year, $to_month, $from_month);
            } elseif ($from_month == $to_month) {
                return self::order_transaction_same_month($request, $start_date, $end_date, $from_month, $to_day, $from_day);
            }
        }
    }

    public function order_transaction_same_year($request, $start_date, $end_date, $from_year, $number, $default_inc)
    {

        $orders = self::order_transaction_date_common_query($request, $start_date, $end_date)
            ->selectRaw('sum(CASE WHEN delivery_type="self_delivery" AND shipping_responsibility="sellerwise_shipping" AND seller_is="seller" THEN (order_amount - deliveryman_charge) ELSE order_amount END) as order_amount, YEAR(created_at) year, MONTH(created_at) month')
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%M')"))
            ->latest('created_at')->get();

        for ($inc = $default_inc; $inc <= $number; $inc++) {
            $month = substr(date("F", strtotime("2023-$inc-01")), 0, 3);
            $order_amount[$month] = 0;
            foreach ($orders as $match) {
                if ($match['month'] == $inc) {
                    $order_amount[$month] = $match['order_amount'];
                }
            }
        }

        return array(
            'order_amount' => $order_amount,
        );
    }

    public function order_transaction_same_month($request, $start_date, $end_date, $month_date, $number, $default_inc)
    {
        $year_month = date('Y-m', strtotime($start_date));
        $month = substr(date("F", strtotime("$year_month")), 0, 3);
        $orders = self::order_transaction_date_common_query($request, $start_date, $end_date)
            ->selectRaw('sum(CASE WHEN delivery_type="self_delivery" AND shipping_responsibility="sellerwise_shipping" AND seller_is="seller" THEN (order_amount - deliveryman_charge) ELSE order_amount END) as order_amount, YEAR(created_at) year, MONTH(created_at) month, DAY(created_at) day')
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

    public function order_transaction_this_week($request)
    {
        $start_date = Carbon::now()->startOfWeek();
        $end_date = Carbon::now()->endOfWeek();

        $number = 6;
        $period = CarbonPeriod::create(Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek());
        $day_name = array();
        foreach ($period as $date) {
            array_push($day_name, $date->format('l'));
        }

        $orders = self::order_transaction_date_common_query($request, $start_date, $end_date)
            ->select(
                DB::raw('sum(CASE WHEN delivery_type="self_delivery" AND shipping_responsibility="sellerwise_shipping" AND seller_is="seller" THEN (order_amount - deliveryman_charge) ELSE order_amount END) as order_amount'),
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

    public function getOrderTransactionForToday($request): array
    {
        $number = 1;
        $dayName = [Carbon::today()->format('l')];
        $orders = self::order_transaction_date_common_query($request, Carbon::now()->startOfDay(), Carbon::now()->endOfDay())
            ->select(
                DB::raw('sum(CASE WHEN delivery_type="self_delivery" AND shipping_responsibility="sellerwise_shipping" AND seller_is="seller" THEN (order_amount - deliveryman_charge) ELSE order_amount END) as order_amount'),
                DB::raw("(DATE_FORMAT(created_at, '%W')) as day")
            )
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%D')"))
            ->latest('created_at')->get();

        for ($inc = 0; $inc < $number; $inc++) {
            $orderAmount[$dayName[$inc]] = 0;
            foreach ($orders as $match) {
                if ($match['day'] == $dayName[$inc]) {
                    $orderAmount[$dayName[$inc]] = $match['order_amount'];
                }
            }
        }

        return [
            'order_amount' => $orderAmount ?? [],
        ];
    }

    public function order_transaction_different_year($request, $start_date, $end_date, $from_year, $to_year)
    {

        $orders = self::order_transaction_date_common_query($request, $start_date, $end_date)
            ->selectRaw('sum(CASE WHEN delivery_type="self_delivery" AND shipping_responsibility="sellerwise_shipping" AND seller_is="seller" THEN (order_amount - deliveryman_charge) ELSE order_amount END) as order_amount, YEAR(created_at) year')
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

    public function order_transaction_date_common_query($request, $start_date, $end_date)
    {
        $customer_id = $request['customer_id'] ?? 'all';
        $status = $request['status'] ?? 'all';

        return Order::with('orderTransaction')
            ->where('payment_status', 'paid')
            ->when($status != 'all', function ($query) use ($status) {
                $query->whereHas('orderTransaction', function ($query) use ($status) {
                    $query->where(['status' => $status]);
                });
            })
            ->when($customer_id != 'all', function ($query) use ($customer_id) {
                $query->where('customer_id', $customer_id);
            })
            ->where(['seller_is' => 'seller', 'seller_id' => auth('seller')->id()])
            ->whereBetween('created_at', [$start_date, $end_date]);
    }

    public function order_transaction_count_query($query, $request)
    {
        $from = $request['from'];
        $to = $request['to'];
        $customer_id = $request['customer_id'] ?? 'all';
        $status = $request['status'] ?? 'all';
        $date_type = $request['date_type'] ?? 'this_year';

        $query_data = $query->when($status != 'all', function ($query) use ($status) {
            return $query->whereHas('orderTransaction', function ($q) use ($status) {
                $q->where(['status' => $status]);
            });
        })
            ->when($customer_id != 'all', function ($query) use ($customer_id) {
                $query->where('customer_id', $customer_id);
            })
            ->where(['seller_is' => 'seller', 'seller_id' => auth('seller')->id()]);

        return self::date_wise_common_filter($query_data, $date_type, $from, $to);
    }

    public function created_date_wise_common_filter($query, $date_type, $from, $to)
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
            ->when(($date_type == 'custom_date' && !is_null($from) && !is_null($to)), function ($query) use ($from, $to) {
                return $query->whereDate('created_at', '>=', $from)
                    ->whereDate('created_at', '<=', $to);
            });
    }

    public function getExpenseTransactionList(Request $request): \Illuminate\Contracts\View\View
    {
        $search = $request['search'];
        $from = $request['from'];
        $to = $request['to'];
        $date_type = $request['date_type'] ?? 'this_year';
        $query_param = ['search' => $search, 'date_type' => $date_type, 'from' => $from, 'to' => $to];

        $expense_transaction_chart = self::expense_transaction_chart_filter($request);

        $expense_calculate_query = Order::with(['orderTransaction', 'coupon'])
            ->where([
                'order_type' => 'default_type',
                // 'coupon_discount_bearer' => 'seller',
                'order_status' => 'delivered',
                'seller_is' => 'seller',
                'seller_id' => auth('seller')->id()
            ])
            ->where(function ($query) {
                $query->whereNotIn('free_delivery_bearer', ['admin'])
                    ->orWhereNot('coupon_discount_bearer', 'inhouse');
            })
            ->where(function ($query) {
                $query->whereNotIn('coupon_code', ['0', 'NULL'])
                    ->orWhere(function ($query) {
                        $query->where([
                            'extra_discount_type' => 'free_shipping_over_order_amount',
                            'free_delivery_bearer' => 'seller'
                        ]);
                    })
                    ->orWhere('coupon_discount_bearer', 'seller');
            })
            ->whereHas('orderTransaction', function ($query) use ($search) {
                $query->where(['status' => 'disburse']);
            });

        $expense_calculate = self::date_wise_common_filter($expense_calculate_query, $date_type, $from, $to)->latest('created_at')->get();

        $total_expense = 0;
        $free_delivery = 0;
        $coupon_discount = 0;
        if ($expense_calculate) {
            foreach ($expense_calculate as $calculate) {
                $total_expense += ($calculate->coupon_discount_bearer == 'seller' ? $calculate->discount_amount : 0) + ($calculate->free_delivery_bearer == 'seller' ? $calculate->extra_discount : 0);
                if (isset($calculate->coupon->coupon_type) && $calculate->coupon_discount_bearer == 'seller' && $calculate->coupon->coupon_type == 'free_delivery') {
                    $free_delivery += $calculate->discount_amount;
                } else {
                    $coupon_discount += $calculate->coupon_discount_bearer == 'seller' ? $calculate->discount_amount : 0;
                }

                if ($calculate->is_shipping_free && $calculate->free_delivery_bearer == 'seller') {
                    $free_delivery += $calculate->extra_discount;
                }
            }
        }

        $expense_transaction_query = Order::with(['orderTransaction', 'coupon'])
            ->where([
                'order_type' => 'default_type',
                // 'coupon_discount_bearer' => 'seller',
                'order_status' => 'delivered',
                'seller_is' => 'seller',
                'seller_id' => auth('seller')->id()
            ])
            ->where(function ($query) {
                $query->whereNotIn('free_delivery_bearer', ['admin'])
                    ->orWhereNot('coupon_discount_bearer', 'inhouse');
            })
            ->where(function ($query) {
                $query->whereNotIn('coupon_code', ['0', 'NULL'])
                    ->orWhere(function ($query) {
                        $query->where([
                            'extra_discount_type' => 'free_shipping_over_order_amount',
                            'free_delivery_bearer' => 'seller'
                        ]);
                    })
                    ->orWhere('coupon_discount_bearer', 'seller');
            })
            ->whereHas('orderTransaction', function ($query) use ($search) {
                return $query->where(['status' => 'disburse'])
                    ->when($search, function ($q) use ($search) {
                        $q->where('order_id', 'like', "%{$search}%")
                            ->orWhere('transaction_id', 'like', "%{$search}%");
                    });
            });

        $expense_transactions_table = self::date_wise_common_filter($expense_transaction_query, $date_type, $from, $to);
        $expense_transactions_table = $expense_transactions_table->latest('created_at')->paginate(Helpers::pagination_limit())->appends($query_param);

        $chartDataExpenseTransactionLabel = array_values(collect(array_keys($expense_transaction_chart['discount_amount']))->map(function ($item) use ($request) {
            if ($request['date_type'] == 'this_month') {
                return $item . ' ' . date('M');
            }
            return $item;
        })->toArray());

        return view('vendor-views.transaction.expense-list', compact('expense_transactions_table', 'expense_transaction_chart',
            'search', 'from', 'to', 'date_type', 'total_expense', 'free_delivery', 'coupon_discount', 'chartDataExpenseTransactionLabel'));
    }

    public function expense_transaction_chart_filter($request)
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
            return self::expense_transaction_same_year($request, $current_start_year, $current_end_year, $from_year, $number, $default_inc);
        } elseif ($date_type == 'this_month') {
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

    public function expense_transaction_same_year($request, $start_date, $end_date, $from_year, $number, $default_inc)
    {
        $orders = self::expense_chart_common_query($request)
            ->selectRaw('sum((CASE WHEN coupon_discount_bearer="seller" THEN discount_amount ELSE 0 END) + (CASE WHEN free_delivery_bearer="seller" THEN extra_discount ELSE 0 END)) as discount_amount, YEAR(created_at) year, MONTH(created_at) month')
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%M')"))
            ->latest('created_at')->get();

        for ($inc = $default_inc; $inc <= $number; $inc++) {
            $month = substr(date("F", strtotime("2023-$inc-01")), 0, 3);
            $discount_amount[$month] = 0;
            foreach ($orders as $match) {
                if ($match['month'] == $inc) {
                    $discount_amount[$month] = $match['discount_amount'];
                }
            }
        }

        return array(
            'discount_amount' => $discount_amount ?? [],
        );
    }

    public function expense_transaction_same_month($request, $start_date, $end_date, $month_date, $number, $default_inc)
    {
        $year_month = date('Y-m', strtotime($start_date));
        $month = substr(date("F", strtotime("$year_month")), 0, 3);
        $orders = self::expense_chart_common_query($request)
            ->selectRaw('sum((CASE WHEN coupon_discount_bearer="seller" THEN discount_amount ELSE 0 END) + (CASE WHEN free_delivery_bearer="seller" THEN extra_discount ELSE 0 END)) as discount_amount, YEAR(created_at) year, MONTH(created_at) month, DAY(created_at) day')
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%D')"))
            ->latest('created_at')->get();

        for ($inc = $default_inc; $inc <= $number; $inc++) {
            $discount_amount[$inc] = 0;
            foreach ($orders as $match) {
                if ($match['day'] == $inc) {
                    $discount_amount[$inc] = $match['discount_amount'];
                }
            }
        }

        return array(
            'discount_amount' => $discount_amount,
        );
    }

    public function expense_transaction_this_week($request)
    {
        $start_date = Carbon::now()->startOfWeek();
        $end_date = Carbon::now()->endOfWeek();

        $number = 6;
        $period = CarbonPeriod::create(Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek());
        $day_name = array();
        foreach ($period as $date) {
            array_push($day_name, $date->format('l'));
        }

        $orders = self::expense_chart_common_query($request)
            ->select(
                DB::raw('sum((CASE WHEN coupon_discount_bearer="seller" THEN discount_amount ELSE 0 END) + (CASE WHEN free_delivery_bearer="seller" THEN extra_discount ELSE 0 END)) as discount_amount'),
                DB::raw("(DATE_FORMAT(created_at, '%W')) as day")
            )
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%D')"))
            ->latest('created_at')->get();

        for ($inc = 0; $inc <= $number; $inc++) {
            $discount_amount[$day_name[$inc]] = 0;
            foreach ($orders as $match) {
                if ($match['day'] == $day_name[$inc]) {
                    $discount_amount[$day_name[$inc]] = $match['discount_amount'];
                }
            }
        }

        return array(
            'discount_amount' => $discount_amount,
        );
    }

    public function getExpenseTransactionForToday($request): array
    {
        $number = 1;
        $dayName = [Carbon::today()->format('l')];

        $orders = self::expense_chart_common_query($request)
            ->select(
                DB::raw('sum((CASE WHEN coupon_discount_bearer="seller" THEN discount_amount ELSE 0 END) + (CASE WHEN free_delivery_bearer="seller" THEN extra_discount ELSE 0 END)) as discount_amount'),
                DB::raw("(DATE_FORMAT(created_at, '%W')) as day")
            )
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%D')"))
            ->latest('created_at')->get();

        for ($inc = 0; $inc < $number; $inc++) {
            $discount_amount[$dayName[$inc]] = 0;
            foreach ($orders as $match) {
                if ($match['day'] == $dayName[$inc]) {
                    $discount_amount[$dayName[$inc]] = $match['discount_amount'];
                }
            }
        }

        return [
            'discount_amount' => $discount_amount ?? [],
        ];
    }

    public function expense_transaction_different_year($request, $start_date, $end_date, $from_year, $to_year)
    {
        $orders = self::expense_chart_common_query($request)
            ->selectRaw('sum((CASE WHEN coupon_discount_bearer="seller" THEN discount_amount ELSE 0 END) + (CASE WHEN free_delivery_bearer="seller" THEN extra_discount ELSE 0 END)) as discount_amount, YEAR(created_at) year')
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%Y')"))
            ->latest('created_at')->get();

        for ($inc = $from_year; $inc <= $to_year; $inc++) {
            $discount_amount[$inc] = 0;
            foreach ($orders as $match) {
                if ($match['year'] == $inc) {
                    $discount_amount[$inc] = $match['discount_amount'];
                }
            }
        }

        return array(
            'discount_amount' => $discount_amount,
        );

    }

    public function expense_chart_common_query($request)
    {
        $from = $request['from'];
        $to = $request['to'];
        $date_type = $request['date_type'] ?? 'this_year';

        $order_query = Order::where([
            'order_type' => 'default_type',
            // 'coupon_discount_bearer' => 'seller',
            'seller_is' => 'seller',
            'seller_id' => auth('seller')->id(),
            'order_status' => 'delivered'
        ])
            ->where(function ($query) {
                $query->whereNotIn('coupon_code', ['0', 'NULL'])
                    ->orWhere(function ($query) {
                        $query->where([
                            'extra_discount_type' => 'free_shipping_over_order_amount',
                            'free_delivery_bearer' => 'seller'
                        ]);
                    });
            })
            ->whereHas('orderTransaction', function ($query) {
                $query->where(['status' => 'disburse']);
            });

        return self::date_wise_common_filter($order_query, $date_type, $from, $to);
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

    public function pdf_order_wise_expense_transaction(Request $request)
    {
        $company_phone = BusinessSetting::where('type', 'company_phone')->first()->value;
        $company_email = BusinessSetting::where('type', 'company_email')->first()->value;
        $company_name = BusinessSetting::where('type', 'company_name')->first()->value;
        $company_web_logo = getWebConfig('company_web_logo');

        $transaction = Order::with(['orderTransaction', 'coupon'])
            ->where(['id' => $request->id, 'seller_is' => 'seller', 'seller_id' => auth('seller')->id()])
            ->first();

        $mpdf_view = ViewFacade::make('vendor-views.transaction.order_wise_expense_pdf', compact('company_phone', 'company_name', 'company_email', 'company_web_logo', 'transaction'));
        Helpers::gen_mpdf($mpdf_view, 'order_expense_transaction_', $request->id);

    }

    public function expense_transaction_summary_pdf(Request $request)
    {
        $company_phone = BusinessSetting::where('type', 'company_phone')->first()->value;
        $company_email = BusinessSetting::where('type', 'company_email')->first()->value;
        $company_name = BusinessSetting::where('type', 'company_name')->first()->value;
        $company_web_logo = getWebConfig('company_web_logo');
        $shop_name = Shop::where(['seller_id' => auth('seller')->id()])->first()->name;

        $search = $request['search'];
        $from = $request['from'];
        $to = $request['to'];
        $date_type = $request['date_type'] ?? 'this_year';
        $duration = $date_type == 'custom_date' ? 'From ' . $from . ' To ' . $to : str_replace('_', ' ', $date_type);

        $expenseTransactionQuery = Order::with(['orderTransaction', 'coupon'])
            ->where([
                'order_type' => 'default_type',
                // 'coupon_discount_bearer' => 'seller',
                'order_status' => 'delivered',
                'seller_is' => 'seller',
                'seller_id' => auth('seller')->id()
            ])
            ->where(function ($query) {
                $query->whereNotIn('free_delivery_bearer', ['admin'])
                    ->orWhereNot('coupon_discount_bearer', 'inhouse');
            })
            ->where(function ($query) {
                $query->whereNotIn('coupon_code', ['0', 'NULL'])
                    ->orWhere(function ($query) {
                        $query->where([
                            'extra_discount_type' => 'free_shipping_over_order_amount',
                            'free_delivery_bearer' => 'seller'
                        ]);
                    });
            })
            ->whereHas('orderTransaction', function ($query) use ($search) {
                $query->where(['status' => 'disburse'])
                    ->when($search, function ($q) use ($search) {
                        $q->Where('order_id', 'like', "%{$search}%")
                            ->orWhere('transaction_id', 'like', "%{$search}%");
                    });
            });
        $expenseTransactions = self::date_wise_common_filter($expenseTransactionQuery, $date_type, $from, $to)->get();
        $total_expense = 0;
        $free_delivery = 0;
        $coupon_discount = 0;
        $freeOverAmountDiscount = 0;
        if ($expenseTransactions) {
            foreach ($expenseTransactions as $transaction) {
                $total_expense += ($transaction->coupon_discount_bearer == 'seller' ? $transaction->discount_amount : 0) + ($transaction->free_delivery_bearer == 'seller' ? $transaction->extra_discount : 0);
                if (isset($transaction->coupon->coupon_type) && $transaction->coupon->coupon_type == 'free_delivery') {
                    $free_delivery += $transaction->discount_amount;
                } else {
                    $coupon_discount += $transaction->coupon_discount_bearer == 'seller' ? $transaction->discount_amount : 0;
                }

                $freeOverAmountDiscount += $transaction->free_delivery_bearer == 'seller' ? $transaction->extra_discount : 0;
            }
        }

        $data = array(
            'total_expense' => $total_expense,
            'free_delivery' => $free_delivery,
            'coupon_discount' => $coupon_discount,
            'free_over_amount_discount' => $freeOverAmountDiscount,
            'company_phone' => $company_phone,
            'company_name' => $company_name,
            'company_email' => $company_email,
            'company_web_logo' => $company_web_logo,
            'duration' => $duration,
            'shop_name' => $shop_name,
        );

        $mpdf_view = ViewFacade::make('vendor-views.transaction.expense_transaction_summary_report_pdf', compact('data'));
        Helpers::gen_mpdf($mpdf_view, 'order_transaction_summary_report_', $date_type);

    }

    public function expenseTransactionExportExcel(Request $request): BinaryFileResponse
    {
        $search = $request['search'];
        $from = $request['from'];
        $to = $request['to'];
        $dateType = $request['date_type'] ?? 'this_year';
        $vendorId = auth('seller')->id();
        $vendor = $this->vendorRepo->getFirstWhere(params: ['id' => $vendorId]);
        $expense_transaction_query = Order::with(['orderTransaction', 'coupon'])
            ->where([
                // 'coupon_discount_bearer' => 'seller',
                'order_status' => 'delivered',
                'order_type' => 'default_type',
                'seller_is' => 'seller',
                'seller_id' => $vendorId
            ])
            ->where(function ($query) {
                $query->whereNotIn('free_delivery_bearer', ['admin'])
                    ->orWhereNot('coupon_discount_bearer', 'inhouse');
            })
            ->where(function ($query) {
                $query->whereNotIn('coupon_code', ['0', 'NULL'])
                    ->orWhere(function ($query) {
                        $query->where([
                            'extra_discount_type' => 'free_shipping_over_order_amount',
                            'free_delivery_bearer' => 'seller'
                        ]);
                    });
            })
            ->whereHas('orderTransaction', function ($query) use ($search) {
                $query->where(['status' => 'disburse'])
                    ->when($search, function ($q) use ($search) {
                        $q->Where('order_id', 'like', "%{$search}%")
                            ->orWhere('transaction_id', 'like', "%{$search}%");
                    });
            });
        $transactions = self::date_wise_common_filter($expense_transaction_query, $dateType, $from, $to)->latest('created_at')->get();
        $data = [
            'data-from' => 'vendor',
            'vendor' => $vendor,
            'search' => $search,
            'from' => $from,
            'to' => $to,
            'dateType' => $dateType,
            'transactions' => $transactions,
        ];
        return Excel::download(new ExpenseTransactionReportExport($data), Report::EXPENSE_TRANSACTION_REPORT_LIST);
    }
}
