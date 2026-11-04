<?php

namespace App\Http\Controllers\Vendor\Order;

use App\Contracts\Repositories\AdminWalletRepositoryInterface;
use App\Contracts\Repositories\BusinessSettingRepositoryInterface;
use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Contracts\Repositories\DeliveryCountryCodeRepositoryInterface;
use App\Contracts\Repositories\DeliveryManRepositoryInterface;
use App\Contracts\Repositories\DeliveryManTransactionRepositoryInterface;
use App\Contracts\Repositories\DeliveryManWalletRepositoryInterface;
use App\Contracts\Repositories\DeliveryZipCodeRepositoryInterface;
use App\Contracts\Repositories\LoyaltyPointTransactionRepositoryInterface;
use App\Contracts\Repositories\OrderDetailRepositoryInterface;
use App\Contracts\Repositories\OrderDetailsRewardsRepositoryInterface;
use App\Contracts\Repositories\OrderEditHistoryRepositoryInterface;
use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Contracts\Repositories\OrderStatusHistoryRepositoryInterface;
use App\Contracts\Repositories\OrderTransactionRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\VendorRepositoryInterface;
use App\Contracts\Repositories\VendorWalletRepositoryInterface;
use App\Enums\GlobalConstant;
use App\Enums\WebConfigKey;
use App\Events\OrderStatusEvent;
use App\Exports\OrderExport;
use App\Http\Controllers\BaseController;
use App\Http\Requests\UploadDigitalFileAfterSellRequest;
use App\Models\OrderEditHistory;
use App\Models\ReferralCustomer;
use App\Repositories\WalletTransactionRepository;
use App\Services\CustomerWalletService;
use App\Services\DeliveryCountryCodeService;
use App\Services\DeliveryManTransactionService;
use App\Services\DeliveryManWalletService;
use App\Services\OrderEditReturnAmountService;
use App\Services\OrderEditService;
use App\Services\OrderService;
use App\Services\OrderStatusHistoryService;
use App\Traits\CustomerTrait;
use App\Traits\FileManagerTrait;
use App\Traits\OrderEditManager;
use App\Traits\PdfGenerator;
use App\Utils\CustomerManager;
use App\Utils\Helpers;
use App\Utils\OrderManager;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\View as PdfView;
use Maatwebsite\Excel\Facades\Excel;
use Rap2hpoutre\FastExcel\FastExcel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Services\Marketplace\SellerOrderBreakdownService;
use App\Services\Marketplace\SellerOrderTimelineService;

class OrderController extends BaseController
{
    use CustomerTrait;
    use PdfGenerator;
    use OrderEditManager;
    use FileManagerTrait {
        delete as deleteFile;
        update as updateFile;
    }

    public function __construct(
        private readonly OrderRepositoryInterface                   $orderRepo,
        private readonly CustomerRepositoryInterface                $customerRepo,
        private readonly VendorRepositoryInterface                  $vendorRepo,
        private readonly DeliveryManRepositoryInterface             $deliveryManRepo,
        private readonly DeliveryCountryCodeRepositoryInterface     $deliveryCountryCodeRepo,
        private readonly DeliveryZipCodeRepositoryInterface         $deliveryZipCodeRepo,
        private readonly OrderDetailRepositoryInterface             $orderDetailRepo,
        private readonly WalletTransactionRepository                $walletTransactionRepo,
        private readonly DeliveryManWalletRepositoryInterface       $deliveryManWalletRepo,
        private readonly DeliveryManTransactionRepositoryInterface  $deliveryManTransactionRepo,
        private readonly OrderStatusHistoryRepositoryInterface      $orderStatusHistoryRepo,
        private readonly OrderTransactionRepositoryInterface        $orderTransactionRepo,
        private readonly LoyaltyPointTransactionRepositoryInterface $loyaltyPointTransactionRepo,
        private readonly BusinessSettingRepositoryInterface         $businessSettingRepo,
        private readonly OrderDetailsRewardsRepositoryInterface     $orderDetailsRewardsRepo,
        private readonly OrderEditService                           $orderEditService,
        private readonly ProductRepositoryInterface                 $productRepo,
        private readonly VendorWalletRepositoryInterface            $vendorWalletRepo,
        private readonly OrderEditHistoryRepositoryInterface        $orderEditHistoryRepo,
        private readonly AdminWalletRepositoryInterface             $adminWalletRepo,
    )
    {
    }

    /**
     * @param Request|null $request
     * @return View Index function is the starting point of a controller
     * Index function is the starting point of a controller
     */
    /**
     * @param Request|null $request
     * @param string|null $type
     * @return View|Collection|LengthAwarePaginator|callable|RedirectResponse|null
     */
    public function index(?Request $request, ?string $type = null): View|Collection|LengthAwarePaginator|null|callable|RedirectResponse
    {
        $seller = auth('seller')->user();
        $vendorId = $seller['id'];
        $searchValue = $request['searchValue'];
        $filter = $request['filter'];
        $dateType = $request['date_type'];
        $from = $request['from'];
        $to = $request['to'];
        $status = $request['status'];
        $deliveryManId = $request['delivery_man_id'];
        $this->orderRepo->updateWhere(params: ['seller_id' => $vendorId, 'checked' => 0], data: ['checked' => 1]);
        $sellerPos = getWebConfig(name: 'seller_pos');

        $paymentPaidStatus = $request['payment_status'] ?? [];
        $orderStatus = $request['order_current_status'] ?? [];

        $relation = ['customer', 'shipping', 'shippingAddress', 'deliveryMan', 'billingAddress'];
        $filters = [
            'order_status' => $status == 'all' ? $orderStatus : $status,
            'order_type' => $request['filter'],
            'date_type' => $dateType,
            'from' => $request['from'],
            'to' => $request['to'],
            'delivery_man_id' => $request['delivery_man_id'],
            'customer_id' => $request['customer_id'],
            'seller_id' => $vendorId,
            'seller_is' => 'seller',
        ];
        $orderAmountSettlement = $request->input('order_amount_settlement', []);

        if (!empty($orderAmountSettlement)) {
            $filters['has_order_edit_settlement'] = $orderAmountSettlement;
        }
        $filterWhereIn['payment_status'] = $paymentPaidStatus;
        if ($status == 'all') {
            $filterWhereIn['order_status'] = $orderStatus;
        }

        $orderTypes = $request['order_types'] ?? [];
        if (!empty($orderTypes)) {
            $filterWhereIn['order_type'] = $orderTypes;
        }

        $allOrders = $this->orderRepo->getListWhereIn(orderBy: ['id' => 'desc'], searchValue: $searchValue, filters: $filters, whereIn: $filterWhereIn, relations: $relation, dataLimit: 'all');
        $statusCounts = $this->orderRepo->getStatusCounts(
            filters: $filters,
            whereIn: $filterWhereIn,
        );

        $allOrdersInfo = [
            'pending_order' => $statusCounts['pending'] ?? 0,
            'confirmed_order' => $statusCounts['confirmed'] ?? 0,
            'processing_order' => $statusCounts['processing'] ?? 0,
            'out_for_delivery_order' => $statusCounts['out_for_delivery'] ?? 0,
            'delivered_order' => $statusCounts['delivered'] ?? 0,
            'canceled_order' => $statusCounts['canceled'] ?? 0,
            'returned_order' => $statusCounts['returned'] ?? 0,
            'failed_order' => $statusCounts['failed'] ?? 0,
        ];


        $orders = $this->orderRepo->getListWhereIn(orderBy: ['id' => 'desc'], searchValue: $searchValue, filters: $filters, whereIn: $filterWhereIn, relations: $relation, dataLimit: getWebConfig(name: WebConfigKey::PAGINATION_LIMIT));
        $sellers = $this->vendorRepo->getByStatusExcept(status: 'pending', relations: ['shop']);

        $customer = "all";
        if (isset($request['customer_id']) && $request['customer_id'] != 'all' && !is_null($request->customer_id) && $request->has('customer_id')) {
            $customer = $this->customerRepo->getFirstWhere(params: ['id' => $request['customer_id']]);
        }

        $vendorId = $request['seller_id'];
        $customerId = $request['customer_id'];
        $customers = $this->customerRepo->getCustomerNameList(request: $request, dataLimit: 'all')->toArray();
        array_unshift($customers, ['id' => 'all', 'text' => translate('All_Customer')]);
        return view('vendor-views.order.list', compact(
            'orders',
            'searchValue',
            'from', 'to',
            'filter',
            'sellers',
            'customers',
            'vendorId',
            'customerId',
            'dateType',
            'searchValue',
            'status',
            'seller',
            'customer',
            'sellerPos',
            'deliveryManId',
            'allOrdersInfo',
            'paymentPaidStatus',
            'orderStatus',
            'orderTypes',
        ));
    }

    public function orderReturnAmountToCustomer(Request $request, CustomerWalletService $customerWalletService, OrderEditReturnAmountService $orderEditReturnAmountService): RedirectResponse
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'amount' => 'required|numeric|min:1',
            'order_return_payment_method' => 'required|string|in:wallet,manually',
            'order_return_payment_note' => 'required|string|max:255',
        ]);
        try {
            // Scope to the signed-in seller's own order — without it a seller could pass another
            // vendor's order id and debit THAT vendor's wallet (cross-vendor forced disbursement).
            $order = $this->orderRepo->getFirstWhere(params: ['id' => $validated['order_id'], 'seller_id' => auth('seller')->id(), 'seller_is' => 'seller'], relations: ['latestEditHistory']);
            if (!$order) {
                ToastMagic::error(translate('Order_not_found'));
                return redirect()->back();
            }
            $customer = Helpers::getCustomerInformation($request);
            if ($validated['amount'] !== $order['edit_return_amount']) {
                ToastMagic::error(translate('Return amount must be equal to return amount'));
                return redirect()->back();
            }
            DB::beginTransaction();
            if ($validated['order_return_payment_method'] == "wallet" && $customer != 'offline') {
                if ($order['seller_is'] == 'admin') {
                    $adminWallet = $this->adminWalletRepo->getFirstWhere(params: ['admin_id' => $order['seller_id']]);
                    $this->adminWalletRepo->updateWhere(params: ['admin_id' => $order['seller_id']], data: ['inhouse_earning' => $adminWallet['inhouse_earning'] - $order['edit_return_amount']]);
                } else {
                    $sellerWallet = $this->vendorWalletRepo->getFirstWhere(params: ['seller_id' => $order['seller_id']]);
                    $this->vendorWalletRepo->updateWhere(params: ['seller_id' => $order['seller_id']], data: ['total_earning' => $sellerWallet['total_earning'] - $order['edit_return_amount']]);
                }
                CustomerManager::create_wallet_transaction($customer['id'], $order['edit_return_amount'], 'return_order_amount_by_admin', 'add_wallet_amount', ['payment_method' => 'wallet']);
            }

            $data = $orderEditReturnAmountService->getReturnAmountData($validated, $order['edit_return_amount']);
            $data += [
                'edit_by' => 'seller',
                'edited_user_id' => auth('seller')->id(),
                'edited_user_name' => auth('seller')->user()?->f_name . ' ' . auth('seller')->user()?->l_name,
            ];

            $this->orderEditHistoryRepo->updateWhere(params: ['id' => $order?->latestEditHistory['id']], data: $data);
            $this->orderRepo->updateWhere(params: ['id' => $validated['order_id']], data: [
                'order_amount' => ($order['order_amount'] - $order['edit_return_amount']),
                'edit_return_amount' => 0
            ]);

            DB::commit();
            $customer = $this->customerRepo->getFirstWhere(params: ['id' => $order['customer_id']]);
            $customerWalletService->sendPushNotificationMessage(request: $request, customer: $customer);
            ToastMagic::success(translate('Amount_returned_successfully'));
            return redirect()->back();
        } catch (\Throwable $exception) {
            DB::rollBack();
            ToastMagic::error(translate('Failed_to_return_amount_') . $exception->getMessage());
            return redirect()->back();
        }

    }

    public function orderDueAmountMarkAsPaid(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
        ]);

        $order = $this->orderRepo->getFirstWhere(['id' => $validated['order_id'], 'seller_id' => auth('seller')->id(), 'seller_is' => 'seller']);
        if (!$order) {
            ToastMagic::error(translate('Order_not_found'));
            return back();
        }
        if ($order->payment_status === 'paid') {
            ToastMagic::error(translate('Order_already_paid'));
            return back();
        }
        try {
            DB::transaction(function () use ($order, $validated) {
                $order->update([
                    'payment_status' => 'paid',
                    'edit_due_amount' => 0,
                ]);
                $this->orderEditHistoryRepo->add([
                    'order_id' => $validated['order_id'],
                    'edit_by' => 'seller',
                    'edited_user_name' => auth()->guard('seller')->user()->f_name . ' ' . auth()->guard('seller')->user()->l_name,
                    'edited_user_id' => auth('seller')->id(),
                    'order_amount' => $order['order_amount'],
                    'order_due_amount' => 0,
                    'order_due_payment_status' => 'paid',
                    'order_due_payment_method' => 'marked_as_paid',
                    'order_due_transaction_ref' => '',
                    'order_due_payment_note' => 'Marked as paid by seller',
                    'order_return_amount' => '',
                    'order_return_payment_status' => '',
                    'order_return_payment_method' => '',
                ]);
            });
            ToastMagic::success(translate('Mark_as_paid_successfully'));
        } catch (\Throwable $e) {
            ToastMagic::error($e->getMessage());
        }
        return redirect()->back();
    }

    public function exportList(Request $request, $status): BinaryFileResponse|RedirectResponse
    {
        $vendorId = auth('seller')->id();
        $filters = [
            'order_status' => $status,
            'filter' => $request['filter'] ?? 'all',
            'date_type' => $request['date_type'],
            'from' => $request['from'],
            'to' => $request['to'],
            'delivery_man_id' => $request['delivery_man_id'],
            'customer_id' => $request['customer_id'],
            'seller_id' => $vendorId,
            'seller_is' => 'seller',
        ];
        $orderAmountSettlement = $request->input('order_amount_settlement', []);

        if (!empty($orderAmountSettlement)) {
            $filters['has_order_edit_settlement'] = $orderAmountSettlement;
        }
        $paymentPaidStatus = $request['payment_status'] ?? [];
        $orderStatus = $request['order_current_status'] ?? [];

        $filterWhereIn['payment_status'] = $paymentPaidStatus;
        if ($status == 'all') {
            $filterWhereIn['order_status'] = $orderStatus;
        }

        $orderTypes = $request['order_types'] ?? [];
        if (!empty($orderTypes)) {
            $filterWhereIn['order_type'] = $orderTypes;
            $filters['order_type'] = $orderTypes;
        }

        $orders = $this->orderRepo->getListWhereIn(orderBy: ['id' => 'desc'], searchValue: requestString('searchValue') ?: null, filters: $filters, whereIn: $filterWhereIn, relations: ['customer', 'seller.shop'], dataLimit: 'all');

        /** order status count  */
        $status_array = [
            'pending' => 0,
            'confirmed' => 0,
            'processing' => 0,
            'out_for_delivery' => 0,
            'delivered' => 0,
            'returned' => 0,
            'failed' => 0,
            'canceled' => 0,
        ];
        $orders?->map(function ($order) use (&$status_array) { // Pass by reference using &
            if (isset($status_array[$order->order_status])) {
                $status_array[$order->order_status]++;
            }
            $order?->orderDetails?->map(function ($details) use ($order) {
                $order['total_qty'] += $details->qty;
                $order['total_price'] += $details->qty * $details->price + ($details->tax_model == 'include' ? $details->qty * $details->tax : 0);
                $order['total_discount'] += $details->discount;
                $order['total_tax'] += $details->tax_model == 'exclude' ? $details->tax : 0;
            });

        });
        /** order status count  */

        /** date */
        $date_type = $request->date_type ?? '';
        $from = match ($date_type) {
            'this_year' => date('Y-01-01'),
            'this_month' => date('Y-m-01'),
            'this_week' => Carbon::now()->subDays(7)->startOfWeek()->format('Y-m-d'),
            default => $request['from'] ?? '',
        };
        $to = match ($date_type) {
            'this_year' => date('Y-12-31'),
            'this_month' => date('Y-m-t'),
            'this_week' => Carbon::now()->startOfWeek()->format('Y-m-d'),
            default => $request['to'] ?? '',
        };
        /** end  */
        $seller = $this->vendorRepo->getFirstWhere(['id' => $vendorId]);
        $customer = [];
        if ($request['customer_id'] != 'all' && $request->has('customer_id')) {
            $customer = $this->customerRepo->getFirstWhere(['id' => $request['customer_id']]);
        }

        $data = [
            'data-from' => 'vendor',
            'orders' => $orders,
            'order_status' => $status,
            'seller' => $seller,
            'customer' => $customer,
            'status_array' => $status_array,
            'searchValue' => $request['searchValue'],
            'order_type' => $request['filter'] ?? 'all',
            'from' => $from,
            'to' => $to,
            'date_type' => $date_type,
            'defaultCurrencyCode' => getCurrencyCode(),
        ];

        return Excel::download(new OrderExport($data), 'Orders.xlsx');
    }

    public function getCustomers(Request $request): JsonResponse
    {
        $allCustomer = ['id' => 'all', 'text' => 'All Customer'];
        $customers = $this->customerRepo->getCustomerNameList(request: $request)->toArray();
        array_unshift($customers, $allCustomer);

        return response()->json($customers);
    }

    public function generateInvoice(string|int $id): void
    {
        $companyPhone = getWebConfig(name: 'company_phone');
        $companyEmail = getWebConfig(name: 'company_email');
        $companyName = getWebConfig(name: 'company_name');
        $companyWebLogo = getWebConfig(name: 'company_web_logo');
        $vendorId = auth('seller')->id();
        $vendor = $this->vendorRepo->getFirstWhere(params: ['id' => $vendorId])['gst'];

        $params = ['id' => $id, 'seller_id' => $vendorId, 'seller_is' => 'seller'];
        $relations = ['details', 'customer', 'shipping', 'seller'];
        $order = $this->orderRepo->getFirstWhere(params: $params, relations: $relations);
        $invoiceSettings = getWebConfig(name: 'invoice_settings');
        $mpdf_view = PdfView::make('vendor-views.order.invoice',
            compact('order', 'vendor', 'companyPhone', 'companyEmail', 'companyName', 'companyWebLogo', 'invoiceSettings')
        );
        $this->generatePdf(view: $mpdf_view, filePrefix: 'order_invoice_', filePostfix: $order['id'], pdfType: 'invoice');
    }

    public function getView(string|int $id, DeliveryCountryCodeService $service, OrderService $orderService): View|RedirectResponse
    {
        $vendorId = auth('seller')->id();
        $params = ['id' => $id, 'seller_id' => $vendorId, 'seller_is' => 'seller'];
        $relations = ['seller.shop', 'deliveryMan', 'verificationImages', 'details' => function ($query) {
            $query->with(['product', 'productAllStatus', 'storage']);
        }, 'latestEditHistory', 'customer', 'shipping', 'offlinePayments', 'orderEditHistory' => function ($query) {
            return $query->orderBy('id', 'desc');
        }, 'orderStatusHistory' => function ($query) {
            $query->orderBy('created_at', 'desc');
        }];
        $order = $this->orderRepo->getFirstWhere(params: $params, relations: $relations);
        if (!$order) {
            ToastMagic::error(translate('Order_not_found'));
            return back();
        }

        if ($order['init_order_amount'] <= 0) {
            $this->orderRepo->updateWhere(params: ['id' => $id], data: ['init_order_amount' => $order['order_amount']]);
        }

        $countryRestrictStatus = getWebConfig(name: 'delivery_country_restriction');
        $zipRestrictStatus = getWebConfig(name: 'delivery_zip_code_area_restriction');
        $deliveryCountry = $this->deliveryCountryCodeRepo->getList(dataLimit: 'all');
        $countries = $countryRestrictStatus ? $service->getDeliveryCountryArray(deliveryCountryCodes: $deliveryCountry) : GlobalConstant::COUNTRIES;
        $zipCodes = $zipRestrictStatus ? $this->deliveryZipCodeRepo->getList(dataLimit: 'all') : 0;

        $physicalProduct = false;
        if (isset($order->details)) {
            foreach ($order->details as $orderDetail) {
                $orderDetailProduct = json_decode($orderDetail?->product_details, true);
                if ($orderDetailProduct && isset($orderDetailProduct['product_type']) && $orderDetailProduct['product_type'] == 'physical') {
                    $physicalProduct = true;
                }
            }
        }

        $whereNotIn = [
            'order_group_id' => ['def-order-group'],
            'id' => [$order['id']],
        ];
        $linkedOrders = $this->orderRepo->getListWhereNotIn(filters: ['order_group_id' => $order['order_group_id']], whereNotIn: $whereNotIn, dataLimit: 'all');
        $totalDelivered = $this->orderRepo->getListWhere(filters: ['seller_id' => $order['seller_id'], 'order_status' => 'delivered', 'order_type' => 'default_type'], dataLimit: 'all')->count();

        $sellerId = 0;
        if ($order['shipping_responsibility'] == 'sellerwise_shipping') {
            $sellerId = $order['seller_id'];
        }
        $filters = [
            'is_active' => 1,
            'seller_id' => $sellerId,
        ];
        $deliveryMen = $this->deliveryManRepo->getListWhere(filters: $filters, dataLimit: 'all');
        $isOrderOnlyDigital = $orderService->getCheckIsOrderOnlyDigital(order: $order);
        $previousOrder = $this->orderRepo->getPreviousFirstOrderWhere(id: $id, params: ['seller_id' => $vendorId, 'seller_is' => 'seller']);
        $nextOrder = $this->orderRepo->getNextFirstOrderWhere(id: $id, params: ['seller_id' => $vendorId, 'seller_is' => 'seller']);
        $allProductsList = $this->productRepo->getListWhere(filters: ['added_by' => 'in_house'], relations: [
            'brand',
            'category',
            'seller.shop',
        ], dataLimit: 10);
        $isOrderEditable = $this->orderEditService->checkIsOrderEditable(order: $order, type: 'vendor');
        Session::forget($this->orderEditService->getOrderEditSessionKey(orderId: $order['id']));
        $totalDelivered = $this->orderRepo->getListWhereCount(filters: [
            'seller_id'    => $order['seller_id'],
            'order_status' => 'delivered',
            'order_type'   => 'default_type',
        ]);
        $orderProductsSession = $this->orderEditService->getOrderEditSession(order: $order);
        $editOrderSummary = $this->generateEditOrderSummary(order: $order, editedOrder: ($orderProductsSession['product_list'] ?? []), data: [
            'edit_by' => 'admin',
            'edited_user_id' => auth()->guard('seller')->id(),
            'edited_user_name' => auth()->guard('seller')->user()->f_name . ' ' . auth()->guard('seller')->user()->l_name,
        ]);

        $orderEditPaymentHistory = $this->orderEditHistoryRepo->getListWhere(filters: ['order_id' => $order['id']], dataLimit: 'all');

        // The same two services the seller app reads. Keeping the panel on its own arithmetic is how
        // the two end up quoting a seller different margins for the same order.
        $sellerId = auth('seller')->id();
        $sellerEarning = app(SellerOrderBreakdownService::class)->breakdownFor(orderId: $order['id'], sellerId: $sellerId);
        $sellerTimeline = app(SellerOrderTimelineService::class)->timelineFor(orderId: $order['id'], sellerId: $sellerId)
            ?? ['events' => [], 'sla' => null];

        if ($order['order_type'] == 'default_type') {
            $orderCount = $this->orderRepo->getListWhereCount(filters: ['customer_id' => $order['customer_id']]);
            return view('vendor-views.order.order-details', compact('order', 'linkedOrders', 'sellerEarning', 'sellerTimeline',
                'deliveryMen', 'totalDelivered', 'physicalProduct', 'isOrderOnlyDigital',
                'countryRestrictStatus','totalDelivered', 'zipRestrictStatus', 'countries', 'zipCodes', 'orderCount', 'previousOrder', 'nextOrder', 'allProductsList', 'isOrderEditable', 'orderProductsSession', 'editOrderSummary', 'orderEditPaymentHistory'));
        } else {
            $orderCount = $this->orderRepo->getListWhereCount(filters: ['customer_id' => $order['customer_id'], 'order_type' => 'POS']);
            return view('vendor-views.pos.order.order-details', compact('order', 'orderCount','totalDelivered', 'previousOrder', 'nextOrder', 'isOrderEditable', 'editOrderSummary', 'deliveryMen', 'physicalProduct'));
        }
    }

    public function orderDueAmountSwitchToCOD(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'order_due_amount' => 'required',
        ]);

        $order = $this->orderRepo->getFirstWhere(params: ['id' => $validated['order_id'], 'seller_id' => auth('seller')->id(), 'seller_is' => 'seller']);
        if (!$order) {
            ToastMagic::error(translate('Order_not_found'));
            return redirect()->back();
        }
        if ($validated['order_due_amount'] != $order['edit_due_amount']) {
            ToastMagic::error(translate('Due_amount_must_be_equal_to_due_amount'));
            return redirect()->back();
        }
        $history = OrderEditHistory::where('order_id', $validated['order_id'])->latest('id')->first();
        if (!$history) {
            ToastMagic::error(translate('No_edit_history_found'));
            return back();
        }
        $history->update([
            'edit_by' => 'seller',
            'edited_user_name' => auth()->guard('seller')->user()->f_name . ' ' . auth()->guard('seller')->user()->l_name,
            'edited_user_id' => auth('seller')->id(),
            'order_due_payment_method' => 'cash_on_delivery',
            'order_due_payment_note' => 'Switched to COD by seller',
        ]);
        ToastMagic::success(translate('Switched_to_COD_successfully'));
        return redirect()->back();

    }

    public function updateStatus(
        Request                       $request,
        DeliveryManTransactionService $deliveryManTransactionService,
        DeliveryManWalletService      $deliveryManWalletService,
        OrderStatusHistoryService     $orderStatusHistoryService,
    ): JsonResponse
    {
        // Scope to the signed-in seller's own order — an unscoped lookup let a seller force another
        // vendor's order to any status (triggering that vendor's stock decrement, wallet and loyalty).
        $order = $this->orderRepo->getFirstWhere(params: ['id' => $request['id'], 'seller_id' => auth('seller')->id(), 'seller_is' => 'seller'], relations: ['customer', 'seller.shop', 'deliveryMan', 'latestEditHistory']);
        if (!$order) {
            return response()->json(['status' => false, 'message' => translate('Order_not_found')], 404);
        }

        if (!$order['is_guest'] && !isset($order['customer'])) {
            return response()->json([
                'status' => 0,
                'message' => translate('account_has_been_deleted_you_can_not_change_the_status'),
            ]);
        }

        if ($order['payment_method'] == 'offline_payment' && $order['payment_status'] == 'unpaid') {
            return response()->json([
                'status' => 0,
                'message' => translate('Please confirm the offline payment information before changing the order status.'),
            ]);
        }

        if ($order['payment_method'] !== 'cash_on_delivery' && $order['edit_due_amount'] > 0 && $order?->latestEditHistory?->order_due_payment_method !== 'cash_on_delivery' && $order?->latestEditHistory?->order_due_payment_status == 'unpaid') {
            return response()->json([
                'status' => 0,
                'message' => translate('Please confirm the due payment has been paid before changing the order status.'),
            ]);
        }

        if ($order['payment_method'] !== 'cash_on_delivery' && $order['edit_return_amount'] > 0 && $order?->latestEditHistory?->order_due_payment_method !== 'cash_on_delivery' && $order?->latestEditHistory?->order_return_payment_status == 'pending') {
            return response()->json([
                'status' => 0,
                'message' => translate('Please return the amount first before changing the order status.'),
            ]);
        }

        if ($order['payment_method'] != 'cash_on_delivery' && $request['order_status'] == 'delivered' && $order['payment_status'] != 'paid') {
            return response()->json([
                'status' => 0,
                'message' => translate('Please_update_the_payment_status_first'),
            ]);
        }

        if ($order['edit_due_amount'] > 0 && $order?->latestEditHistory?->order_due_payment_method == 'cash_on_delivery' && $order?->latestEditHistory?->order_due_payment_status == 'unpaid' && $order['shipping_responsibility'] == 'inhouse_shipping' && $request['order_status'] == 'delivered') {
            return response()->json([
                'status' => 0,
                'message' => translate('Please mark as paid before delivered this order.'),
            ]);
        }

        if ($order['order_status'] == 'delivered') {
            return response()->json(['status' => 0, 'message' => translate('order_is_already_delivered.')], 200);
        }

        if ($request['order_status'] == 'delivered') {
            foreach ($order['details'] as $orderDetail) {
                $productDetails = json_decode($orderDetail?->product_details ?? '', true);
                if (
                    $productDetails['product_type'] == 'digital' &&
                    (isset($productDetails['digital_product_type']) && $productDetails['digital_product_type'] == 'ready_after_sell') &&
                    is_null($orderDetail['digital_file_after_sell'])
                ) {
                    if ($request->ajax()) {
                        return response()->json([
                            'status' => 0,
                            'message' => translate('Please_upload_the_digital_product_files_first'),
                        ]);
                    }
                }
            }
        }

        $this->orderRepo->updateStockOnOrderStatusChange($request['id'], $request['order_status']);
        $this->orderRepo->update(id: $request['id'], data: ['order_status' => $request['order_status']]);
        // A wallet-paid POS sale was debited at the counter; cancelling the order is
        // the only path that returns that money. Reference-keyed so a repeated cancel
        // can never credit twice.
        if ($request['order_status'] == 'canceled'
            && $order['order_type'] == 'POS'
            && $order['payment_method'] == 'wallet'
            && $order['customer_id'] != 0
            && !$this->walletTransactionRepo->getFirstWhere(params: ['reference' => 'pos_order_cancel_' . $order['id'], 'transaction_type' => 'order_refund'])
        ) {
            $this->createWalletTransaction(
                user_id: $order['customer_id'],
                amount: usdToDefaultCurrency(amount: $order['order_amount']),
                transaction_type: 'order_refund',
                reference: 'pos_order_cancel_' . $order['id']
            );
        }
        if ($request['order_status'] == 'delivered') {
            $this->orderRepo->update(id: $request['id'], data: ['payment_status' => 'paid', 'is_pause' => 0]);
            $this->orderDetailRepo->updateWhere(params: ['order_id' => $order['id']], data: ['delivery_status' => $request['order_status'], 'payment_status' => 'paid']);
            $this->orderDetailRepo->updateWhere(params: ['order_id' => $order['id'], 'refund_started_at' => null], data: ['refund_started_at' => now()]);
        }
        event(new OrderStatusEvent(key: $request['order_status'], type: 'customer', order: $order));
        if ($request['order_status'] == 'canceled') {
            event(new OrderStatusEvent(key: 'canceled', type: 'delivery_man', order: $order));
        }

        $walletStatus = getWebConfig(name: 'wallet_status');
        $loyaltyPointStatus = getWebConfig(name: 'loyalty_point_status');
        $loyaltyPointEachOrder = getWebConfig(name: 'loyalty_point_for_each_order');
        $loyaltyPointEachOrder = !is_null($loyaltyPointEachOrder) ? $loyaltyPointEachOrder : $loyaltyPointStatus;
        $orderDetailsRewards = $this->orderDetailsRewardsRepo->getFirstWhere(params: ['order_id' => $order['id'], 'reward_type' => 'loyalty_point']);

        if ($orderDetailsRewards && $orderDetailsRewards['reward_delivered'] != 1 && $orderDetailsRewards['reward_amount'] > 0 && $walletStatus == 1 && $loyaltyPointStatus == 1 && $loyaltyPointEachOrder == 1 && !$order['is_guest'] && $request['order_status'] == 'delivered' && $order['seller_id'] != null) {
            $this->loyaltyPointTransactionRepo->addLoyaltyPointTransaction(userId: $order['customer_id'], reference: $order['id'], amount: usdToDefaultCurrency(amount: $order['order_amount'] - $order['shipping_cost']), transactionType: 'order_place');
            $this->orderDetailsRewardsRepo->update(id: $orderDetailsRewards['id'], data: ['reward_delivered' => 1]);
        }

        $refEarningStatus = getWebConfig(name: 'ref_earning_status') ?? 0;
        $refEarningExchangeRate = getWebConfig(name: 'ref_earning_exchange_rate') ?? 0;

        if (!$order['is_guest'] && $refEarningStatus == 1 && $request['order_status'] == 'delivered') {

            $customer = $this->customerRepo->getFirstWhere(params: ['id' => $order['customer_id']]);
            $isFirstOrder = $this->orderRepo->getListWhereCount(filters: ['customer_id' => $order['customer_id'], 'order_status' => 'delivered', 'payment_status' => 'paid']);
            $referredByUser = $this->customerRepo->getFirstWhere(params: ['id' => $order['customer_id']]);

            if ($isFirstOrder == 1 && isset($customer->referred_by) && isset($referredByUser)) {
                $this->walletTransactionRepo->addWalletTransaction(
                    user_id: $referredByUser['id'],
                    amount: floatval($refEarningExchangeRate),
                    transactionType: 'add_fund_by_admin',
                    reference: 'earned_by_referral');
            }
        }

        if ($order['delivery_man_id'] && $request['order_status'] == 'delivered') {
            $deliverymanWallet = $this->deliveryManWalletRepo->getFirstWhere(params: ['delivery_man_id' => $order['delivery_man_id']]);
            $cashInHand = $order['payment_method'] == 'cash_on_delivery' ? $order['order_amount'] : 0;

            if (empty($deliverymanWallet)) {
                $deliverymanWalletData = $deliveryManWalletService->getDeliveryManData(id: $order['delivery_man_id'], deliverymanCharge: $order['deliveryman_charge'], cashInHand: $cashInHand);
                $this->deliveryManWalletRepo->add(data: $deliverymanWalletData);
            } else {
                $deliverymanWalletData = [
                    'current_balance' => $deliverymanWallet['current_balance'] + $order['deliveryman_charge'] ?? 0,
                    'cash_in_hand' => $deliverymanWallet['cash_in_hand'] + $cashInHand ?? 0,
                ];

                $this->deliveryManWalletRepo->updateWhere(params: ['delivery_man_id' => $order['delivery_man_id']], data: $deliverymanWalletData);
            }

            if ($order['deliveryman_charge'] && $request['order_status'] == 'delivered') {
                $deliveryManTransactionData = $deliveryManTransactionService->getDeliveryManTransactionData(amount: $order['deliveryman_charge'], addedBy: 'seller', id: $order['delivery_man_id'], transactionType: 'deliveryman_charge');
                $this->deliveryManTransactionRepo->add($deliveryManTransactionData);
            }
        }

        $orderStatusHistoryData = $orderStatusHistoryService->getOrderHistoryData(orderId: $request['id'], userId: auth('seller')->id(), userType: 'seller', status: $request['order_status']);
        $this->orderStatusHistoryRepo->add($orderStatusHistoryData);
        OrderManager::removeOldStatusHistory(orderId: $request['id'], orderStatus: $request['order_status']);
        $transaction = $this->orderTransactionRepo->getFirstWhere(params: ['order_id' => $order['id']]);
        if (isset($transaction) && $transaction['status'] == 'disburse') {
            return response()->json([
                'status' => 1,
                'message' => translate('status_change_successfully'),
            ]);
        }

        if ($request['order_status'] == 'delivered' && $order['seller_id'] != null) {
            $this->orderRepo->manageWalletOnOrderStatusChange(order: $order, receivedBy: 'seller');
        }
        if ($request['order_status'] == 'delivered') {
            $referredUser = ReferralCustomer::where('user_id', $order?->customer?->id)->first();
            if ($referredUser?->delivered_notify != 1) {
                event(new OrderStatusEvent(key: 'your_referred_customer_order_has_been_delivered', type: 'promoter', order: $order));
                ReferralCustomer::where('user_id', $order?->customer?->id)->update(['delivered_notify' => 1]);
            }
        }
        return response()->json([
            'status' => 1,
            'message' => translate('status_change_successfully'),
        ]);
    }

    public function updateAddress(Request $request): RedirectResponse
    {
        // Scope to the signed-in seller's own order — an unscoped lookup let a seller rewrite another
        // vendor's order shipping/billing address.
        $order = $this->orderRepo->getFirstWhere(params: ['id' => $request['order_id'], 'seller_id' => auth('seller')->id(), 'seller_is' => 'seller'], relations: ['deliveryMan']);
        if (!$order) {
            ToastMagic::error(translate('Order_not_found'));
            return back();
        }
        $shippingAddressData = json_decode(json_encode($order['shipping_address_data']), true);
        $billingAddressData = json_decode(json_encode($order['billing_address_data']), true);
        $commonAddressData = [
            'contact_person_name' => $request['name'],
            'phone' => $request['phone_number'],
            'country' => $request['country'],
            'city' => $request['city'],
            'zip' => $request['zip'],
            'address' => $request['address'],
            'latitude' => $request['latitude'],
            'longitude' => $request['longitude'],
            'updated_at' => now(),
        ];

        if ($request['address_type'] == 'shipping') {
            $shippingAddressData = array_merge($shippingAddressData, $commonAddressData);
        } elseif ($request['address_type'] == 'billing') {
            $billingAddressData = array_merge($billingAddressData, $commonAddressData);
        }

        $updateData = [];
        if ($request['address_type'] == 'shipping') {
            $updateData['shipping_address_data'] = json_encode($shippingAddressData);
        } elseif ($request['address_type'] == 'billing') {
            $updateData['billing_address_data'] = json_encode($billingAddressData);
        }

        if (!empty($updateData)) {
            $this->orderRepo->update(id: $request['order_id'], data: $updateData);
        }

        if ($order->delivery_type == 'self_delivery' && $order->delivery_man_id) {
            OrderStatusEvent::dispatch('order_edit_message', 'delivery_man', $order);
        }

        ToastMagic::success(translate('successfully_updated'));
        return back();
    }

    public function updatePaymentStatus(Request $request): JsonResponse
    {
        // Scope to the signed-in seller's own order — an unscoped lookup let a seller flip another
        // vendor's order payment status.
        $order = $this->orderRepo->getFirstWhere(params: ['id' => $request['id'], 'seller_id' => auth('seller')->id(), 'seller_is' => 'seller']);
        if (!$order) {
            return response()->json(['status' => 0, 'message' => translate('Order_not_found')], 404);
        }

        if ($order['payment_status'] == 'paid') {
            return response()->json([
                'status' => 0,
                'message' => translate('when_payment_status_paid_then_you_can_not_change_payment_status_paid_to_unpaid.'),
            ]);
        }

        if ($order['is_guest'] == '0' && !isset($order['customer'])) {
            return response()->json([
                'status' => 0,
                'customer_status' => 0,
                'message' => translate('account_has_been_deleted_you_can_not_change_the_status'),
            ]);
        }

        if ($order['payment_method'] == 'offline_payment' && $order['payment_status'] == 'unpaid') {
            return response()->json([
                'status' => 0,
                'message' => translate('Please confirm the offline payment information before editing this order.'),
            ]);
        }

        $this->orderRepo->update(id: $request['id'], data: ['payment_status' => $request['payment_status']]);
        return response()->json([
            'status' => 1,
            'message' => translate('status_change_successfully')
        ]);
    }

    public function updateDeliverInfo(Request $request): RedirectResponse
    {
        // Confirm the order belongs to the signed-in seller before mutating its delivery info.
        $order = $this->orderRepo->getFirstWhere(params: ['id' => $request['order_id'], 'seller_id' => auth('seller')->id(), 'seller_is' => 'seller']);
        if (!$order) {
            ToastMagic::error(translate('Order_not_found'));
            return back();
        }
        $updateData = [
            'delivery_type' => 'third_party_delivery',
            'delivery_service_name' => $request['delivery_service_name'],
            'third_party_delivery_tracking_id' => $request['third_party_delivery_tracking_id'],
            'delivery_man_id' => null,
            'deliveryman_charge' => 0,
            'expected_delivery_date' => null,
        ];
        $this->orderRepo->update(id: $request['order_id'], data: $updateData);

        ToastMagic::success(translate('updated_successfully'));
        return back();
    }

    public function addDeliveryMan(string|int $order_id, string|int $delivery_man_id): JsonResponse
    {
        if ($delivery_man_id == 0) {
            return response()->json([], 401);
        }

        $order = $this->orderRepo->getFirstWhere(params: ['id' => $order_id]);
        if ($order['order_status'] == 'delivered') {
            return response()->json(['status' => false], 403);
        }
        $orderData = [
            'delivery_man_id' => $delivery_man_id,
            'delivery_type' => 'self_delivery',
            'delivery_service_name' => null,
            'third_party_delivery_tracking_id' => null,
        ];

        if ($order['delivery_man_id'] != $delivery_man_id) {
            $orderData['deliveryman_assigned_at'] = Carbon::now();
        }
        $params = ['seller_id' => auth('seller')->id(), 'id' => $order_id];
        $this->orderRepo->updateWhere(params: $params, data: $orderData);

        $order = $this->orderRepo->getFirstWhere(params: ['id' => $order_id], relations: ['deliveryMan']);
        event(new OrderStatusEvent(key: 'new_order_assigned_message', type: 'delivery_man', order: $order));

        return response()->json(['status' => true], 200);
    }

    public function updateAmountDate(Request $request): JsonResponse
    {
        $userId = auth('seller')->id();
        $status = $this->orderRepo->updateAmountDate(request: $request, userId: $userId, userType: 'seller');
        $order = $this->orderRepo->getFirstWhere(params: ['id' => $request['order_id']], relations: ['customer', 'deliveryMan']);

        $fieldName = $request['field_name'];
        $message = '';
        if ($fieldName == 'expected_delivery_date') {
            OrderStatusEvent::dispatch('expected_delivery_date', 'delivery_man', $order);
            $message = translate("expected_delivery_date_added_successfully");
        } elseif ($fieldName == 'deliveryman_charge') {
            OrderStatusEvent::dispatch('delivery_man_charge', 'delivery_man', $order);
            $message = translate("deliveryman_charge_added_successfully");
        }

        return response()->json(['status' => $status, 'message' => $message], $status ? 200 : 403);
    }

    public function uploadDigitalFileAfterSell(UploadDigitalFileAfterSellRequest $request): RedirectResponse
    {
        // Scope to the seller's own order line — otherwise a seller could overwrite the delivered digital
        // file on any order_detail by supplying another vendor's id.
        $orderDetails = $this->orderDetailRepo->getFirstWhere(['id' => $request['order_id'], 'seller_id' => auth('seller')->id()]);
        if (!$orderDetails) {
            ToastMagic::error(translate('access_denied'));
            return back();
        }
        $digitalFileAfterSell = $this->updateFile(dir: 'product/digital-product/', oldImage: $orderDetails['digital_file_after_sell'], format: $request['digital_file_after_sell']->getClientOriginalExtension(), image: $request->file('digital_file_after_sell'), fileType: 'file');
        if ($this->orderDetailRepo->update(id: $orderDetails['id'], data: ['digital_file_after_sell' => $digitalFileAfterSell])) {
            ToastMagic::success(translate('digital_file_upload_successfully'));
        } else {
            ToastMagic::error(translate('digital_file_upload_failed'));
        }
        return back();
    }


}
