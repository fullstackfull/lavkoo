<?php

namespace App\Http\Controllers\Admin\Order;

use App\Contracts\Repositories\AdminWalletRepositoryInterface;
use App\Contracts\Repositories\AuthorRepositoryInterface;
use App\Contracts\Repositories\OrderDetailsRewardsRepositoryInterface;
use App\Contracts\Repositories\OrderEditHistoryRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\PublishingHouseRepositoryInterface;
use App\Contracts\Repositories\VendorWalletRepositoryInterface;
use App\Contracts\Repositories\WalletTransactionRepositoryInterface;
use App\Events\AddFundToWalletEvent;
use App\Events\OrderEditReturnPaymentEvent;
use App\Events\RefundEvent;
use App\Http\Requests\Admin\RefundStatusRequest;
use App\Models\OrderEditHistory;
use App\Services\CustomerWalletService;
use App\Services\Fulfillment\FulfillmentOrderGuard;
use App\Services\OrderAddressUpdateService;
use App\Services\OrderEditReturnAmountService;
use App\Services\OrderEditService;
use App\Services\ProductService;
use App\Services\RefundStatusService;
use App\Services\RefundTransactionService;
use App\Traits\OrderEditManager;
use App\Utils\CustomerManager;
use App\Utils\Helpers;
use Carbon\Carbon;
use App\Enums\WebConfigKey;
use App\Utils\OrderManager;
use App\Exports\OrderExport;
use App\Traits\PdfGenerator;
use Exception;
use Illuminate\Http\Request;
use App\Enums\GlobalConstant;
use App\Traits\CustomerTrait;
use App\Services\OrderService;
use App\Events\OrderStatusEvent;
use App\Models\ReferralCustomer;
use App\Traits\FileManagerTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Http\RedirectResponse;
use App\Http\Controllers\BaseController;
use App\Services\DeliveryManWalletService;
use App\Repositories\DeliveryManRepository;
use App\Services\OrderStatusHistoryService;
use App\Services\DeliveryCountryCodeService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Database\Eloquent\Collection;
use App\Services\DeliveryManTransactionService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\View as PdfView;
use App\Repositories\OrderTransactionRepository;
use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Http\Requests\UploadDigitalFileAfterSellRequest;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use App\Contracts\Repositories\VendorRepositoryInterface;
use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Contracts\Repositories\OrderDetailRepositoryInterface;
use App\Contracts\Repositories\BusinessSettingRepositoryInterface;
use App\Contracts\Repositories\DeliveryZipCodeRepositoryInterface;
use App\Contracts\Repositories\ShippingAddressRepositoryInterface;
use App\Contracts\Repositories\DeliveryManWalletRepositoryInterface;
use App\Contracts\Repositories\OrderStatusHistoryRepositoryInterface;
use App\Contracts\Repositories\DeliveryCountryCodeRepositoryInterface;
use App\Contracts\Repositories\DeliveryManTransactionRepositoryInterface;
use App\Contracts\Repositories\LoyaltyPointTransactionRepositoryInterface;
use App\Contracts\Repositories\OrderExpectedDeliveryHistoryRepositoryInterface;

class OrderController extends BaseController
{
    /** Statuses a bulk action may set. Excludes the ones that need per-order input. */
    private const BULK_STATUSES = ['confirmed', 'processing', 'out_for_delivery', 'delivered', 'canceled'];

    /** Cap on one batch, so a crafted payload cannot walk the whole table. */
    private const BULK_LIMIT = 100;

    use CustomerTrait;
    use PdfGenerator;
    use OrderEditManager;
    use FileManagerTrait {
        delete as deleteFile;
        update as updateFile;
    }

    public function __construct(
        private readonly AuthorRepositoryInterface                       $authorRepo,
        private readonly OrderRepositoryInterface                        $orderRepo,
        private readonly CustomerRepositoryInterface                     $customerRepo,
        private readonly VendorRepositoryInterface                       $vendorRepo,
        private readonly BusinessSettingRepositoryInterface              $businessSettingRepo,
        private readonly DeliveryCountryCodeRepositoryInterface          $deliveryCountryCodeRepo,
        private readonly DeliveryZipCodeRepositoryInterface              $deliveryZipCodeRepo,
        private readonly DeliveryManRepository                           $deliveryManRepo,
        private readonly ShippingAddressRepositoryInterface              $shippingAddressRepo,
        private readonly OrderExpectedDeliveryHistoryRepositoryInterface $orderExpectedDeliveryHistoryRepo,
        private readonly OrderDetailRepositoryInterface                  $orderDetailRepo,
        private readonly DeliveryManWalletRepositoryInterface            $deliveryManWalletRepo,
        private readonly ProductRepositoryInterface                      $productRepo,
        private readonly ProductService                                  $productService,
        private readonly OrderEditService                                $orderEditService,
        private readonly PublishingHouseRepositoryInterface              $publishingHouseRepo,
        private readonly DeliveryManTransactionRepositoryInterface       $deliveryManTransactionRepo,
        private readonly OrderStatusHistoryRepositoryInterface           $orderStatusHistoryRepo,
        private readonly OrderTransactionRepository                      $orderTransactionRepo,
        private readonly LoyaltyPointTransactionRepositoryInterface      $loyaltyPointTransactionRepo,
        private readonly OrderDetailsRewardsRepositoryInterface          $orderDetailsRewardsRepo,
        private readonly AdminWalletRepositoryInterface                  $adminWalletRepo,
        private readonly VendorWalletRepositoryInterface                 $vendorWalletRepo,
        private readonly WalletTransactionRepositoryInterface            $walletTransactionRepo,
        private readonly OrderEditHistoryRepositoryInterface             $orderEditHistoryRepo,
    )
    {
    }

    /**
     * @param Request|null $request
     * @param string $type
     * @return View|Collection|LengthAwarePaginator|callable|RedirectResponse|JsonResponse|null Index function is the starting point of a controller
     * Index function is the starting point of a controller
     */
    public function index(Request|null $request, $type = 'all'): View|Collection|LengthAwarePaginator|null|callable|RedirectResponse|JsonResponse
    {
        $status = $type;
        $searchValue = $request['searchValue'];

        $filter = $request['filter'];
        $from = $request['from'];
        $to = $request['to'];

        $this->orderRepo->updateWhere(params: ['checked' => 0], data: ['checked' => 1]);

        $vendorId = $request['seller_id'] == '0' ? 1 : $request['seller_id'];
        if ($request['seller_id'] == null) {
            $vendorIs = 'all';
        } elseif ($request['seller_id'] == 'all') {
            $vendorIs = $request['seller_id'];
        } elseif ($request['seller_id'] == '0') {
            $vendorIs = 'admin';
        } else {
            $vendorIs = 'seller';
        }

        $dateType = $request['date_type'];
        $paymentPaidStatus = $request['payment_status'] ?? [];
        $orderStatus = $request['order_current_status'] ?? [];

        $filters = [
            'order_status' => $status === 'all' ? $orderStatus : $status,
            'filter' => $request['filter'] ?? 'all',
            'date_type' => $request['date_type'],
            'from' => $request['from'],
            'to' => $request['to'],
            'delivery_man_id' => $request['delivery_man_id'],
            'customer_id' => $request['customer_id'],
            'seller_id' => $vendorId,
            'seller_is' => $vendorIs,
        ];
        $orderAmountSettlement = $request->input('order_amount_settlement', []);

        if (!empty($orderAmountSettlement)) {
            $filters['has_order_edit_settlement'] = $orderAmountSettlement;
        }

        $filterWhereIn = [];
        $filterWhereIn['payment_status'] = $paymentPaidStatus;
        if ($status === 'all') {
            $filterWhereIn['order_status'] = $orderStatus;
        }

        $orderTypes = $request['order_types'] ?? [];
        if (!empty($orderTypes)) {
            $filterWhereIn['order_type'] = $orderTypes;
        }

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

        $orders = $this->orderRepo->getListWhereIn(
            orderBy: ['id' => 'desc'],
            searchValue: $searchValue,
            filters: $filters,
            whereIn: $filterWhereIn,
            relations: ['details', 'customer', 'seller.shop', 'orderEditHistory'],
            dataLimit: getWebConfig(name: WebConfigKey::PAGINATION_LIMIT)
        );

        $sellers = $this->vendorRepo->getByStatusExcept(
            status: 'pending',
            relations: ['shop'],
            paginateBy: 999999
        );

        $customer = 'all';
        $customerId = $request['customer_id'];

        if (isset($customerId) && $customerId !== 'all' && !is_null($customerId)) {
            $customer = $this->customerRepo->getFirstWhere(params: ['id' => $customerId]);
        }
        $customers = $this->customerRepo->getCustomerNameList(request: $request, dataLimit: 'all')->toArray();
        array_unshift($customers, ['id' => 'all', 'text' => translate('All_Customer')]);
        $vendorId = $request['seller_id'];

        if (request()->ajax() && !request()->hasHeader('X-PJAX')) {
            return response()->json(['orders' => $orders]);
        }

        return view('admin-views.order.list', compact(
            'orders',
            'searchValue',
            'from',
            'to',
            'status',
            'filter',
            'sellers',
            'customer',
            'vendorId',
            'customerId',
            'dateType',
            'allOrdersInfo',
            'paymentPaidStatus',
            'orderStatus',
            'customers',
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
            $order = $this->orderRepo->getFirstWhere(params: ['id' => $validated['order_id']], relations: ['latestEditHistory']);
            if ($validated['amount'] !== $order['edit_return_amount']) {
                ToastMagic::error(translate('Return amount must be equal to return amount'));
                return redirect()->back();
            }
            DB::beginTransaction();
            if ($validated['order_return_payment_method'] == "wallet" && $order['is_guest'] != 1) {
                if (getWebConfig(name: 'wallet_status') != 1) {
                    ToastMagic::error(translate('Amount_returned_currently_not_possible_to_wallet'));
                    return redirect()->back();
                }
                CustomerManager::create_wallet_transaction($order['customer_id'], $order['edit_return_amount'], 'return_order_amount_by_admin', 'add_wallet_amount', ['payment_method' => 'wallet']);
            }

            $adminWallet = $this->adminWalletRepo->getFirstWhere(params: ['admin_id' => 1]);
            $this->adminWalletRepo->updateWhere(
                params: ['admin_id' => $order['seller_id']],
                data: ['pending_amount' => $adminWallet['pending_amount'] - $order['edit_return_amount']]
            );

            $data = $orderEditReturnAmountService->getReturnAmountData($validated, $order['edit_return_amount']);
            $data += [
                'edit_by' => 'admin',
                'edited_user_id' => auth('admin')->id(),
                'edited_user_name' => auth('admin')->user()?->name,
            ];

            $this->orderEditHistoryRepo->updateWhere(params: ['id' => $order?->latestEditHistory['id']], data: $data);
            $this->orderRepo->updateWhere(params: ['id' => $validated['order_id']], data: [
                'order_amount' => ($order['order_amount'] - $order['edit_return_amount']),
                'edit_return_amount' => 0
            ]);
            DB::commit();

            if (!$order['is_guest']) {
                $orderEditNotificationEvent[] = [
                    'notification' => true,
                    'notificationData' => (object)[
                        'key' => 'order_edit_return_amount_message',
                        'type' => 'customer',
                        'order' => $order,
                    ],
                ];

                foreach ($orderEditNotificationEvent as $orderEditDuePaymentEvent) {
                    if (!empty($orderEditDuePaymentEvent)) {
                        event(new OrderEditReturnPaymentEvent(notification: $orderEditDuePaymentEvent['notificationData']));
                    }
                }
            }

            ToastMagic::success(translate('Amount_returned_successfully'));
            return redirect()->back();

        } catch (\Throwable $exception) {
            DB::rollBack();
            ToastMagic::error(translate('Failed_to_return_amount_') . $exception->getMessage());
            return redirect()->back();
        }

    }

    public function orderDueAmountSwitchToCOD(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'order_due_amount' => 'required',
        ]);
        $order = $this->orderRepo->getFirstWhere(params: ['id' => $validated['order_id']]);
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
            'edit_by' => 'admin',
            'edited_user_id' => auth('admin')->id(),
            'edited_user_name' => auth('admin')->user()->name,
            'order_due_payment_method' => 'cash_on_delivery',
            'order_due_payment_note' => 'Switched to COD by admin',
        ]);
        ToastMagic::success(translate('Switched_to_COD_successfully'));
        return redirect()->back();
    }

    public function orderDueAmountMarkAsPaid(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
        ]);
        $order = $this->orderRepo->getFirstWhere(['id' => $validated['order_id']], relations: ['latestEditHistory']);
        if (!$order) {
            ToastMagic::error(translate('Order_not_found'));
            return back();
        }
        if ($order->payment_status === 'paid' && ($order?->latestEditHistory && $order?->latestEditHistory?->order_due_payment_status == 'paid')) {
            ToastMagic::error(translate('Order_already_paid'));
            return back();
        }
        try {
            DB::transaction(function () use ($order, $validated) {
                $order->update([
                    'order_amount' => $order['order_amount'] + $order['edit_due_amount'],
                    'payment_status' => 'paid',
                    'edit_due_amount' => 0,
                ]);

                if ($order?->latestEditHistory) {
                    $this->orderEditHistoryRepo->updateWhere(params: ['id' => $order?->latestEditHistory?->id], data: [
                        'order_due_payment_status' => 'paid',
                        'order_due_payment_method' => $order?->latestEditHistory?->order_due_payment_method,
                        'order_due_transaction_ref' => '',
                        'order_due_payment_note' => 'Marked as paid by admin',
                    ]);
                }
            });
            ToastMagic::success(translate('Mark_as_paid_successfully'));
        } catch (\Throwable $e) {
            ToastMagic::error($e->getMessage());
        }
        return redirect()->back();
    }

    public function exportList(Request $request, $status): BinaryFileResponse|RedirectResponse
    {
        $vendorId = $request['seller_id'] == '0' ? 1 : $request['seller_id'];
        if ($request['seller_id'] == null) {
            $vendorIs = 'all';
        } elseif ($request['seller_id'] == 'all') {
            $vendorIs = $request['seller_id'];
        } elseif ($request['seller_id'] == '0') {
            $vendorIs = 'admin';
        } else {
            $vendorIs = 'seller';
        }

        $filters = [
            'order_status' => $status,
            'filter' => $request['filter'] ?? 'all',
            'date_type' => $request['date_type'],
            'from' => $request['from'],
            'to' => $request['to'],
            'delivery_man_id' => $request['delivery_man_id'],
            'customer_id' => $request['customer_id'],
            'seller_id' => $vendorId,
            'seller_is' => $vendorIs,
        ];

        $dateType = $request['date_type'];
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
        $orderAmountSettlement = $request->input('order_amount_settlement', []);

        if (!empty($orderAmountSettlement)) {
            $filters['has_order_edit_settlement'] = $orderAmountSettlement;
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
        $seller = [];
        if ($request['seller_id'] != 'all' && $request->has('seller_id') && $request->seller_id != 0) {
            $seller = $this->vendorRepo->getFirstWhere(['id' => $request['seller_id']]);
        }
        $customer = [];
        if ($request['customer_id'] != 'all' && $request->has('customer_id')) {
            $customer = $this->customerRepo->getFirstWhere(['id' => $request['customer_id']]);
        }

        $data = [
            'data-from' => 'admin',
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

    public function getView(string|int $id, DeliveryCountryCodeService $service, OrderService $orderService): View|RedirectResponse
    {
        $countryRestrictStatus = getWebConfig(name: 'delivery_country_restriction');
        $zipRestrictStatus = getWebConfig(name: 'delivery_zip_code_area_restriction');
        $deliveryCountry = $this->deliveryCountryCodeRepo->getList(dataLimit: 'all');
        $countries = $countryRestrictStatus ? $service->getDeliveryCountryArray(deliveryCountryCodes: $deliveryCountry) : GlobalConstant::COUNTRIES;
        $zipCodes = $zipRestrictStatus ? $this->deliveryZipCodeRepo->getList(dataLimit: 'all') : 0;
        $companyName = getWebConfig(name: 'company_name');
        $companyWebLogo = getWebConfig(name: 'company_web_logo');

        $order = $this->orderRepo->getFirstWhere(
            params: ['id' => $id],
            relations: [
                'details' => function ($query) {
                    $query->with(['product', 'productAllStatus', 'storage']);
                },
                'latestEditHistory',
                'verificationImages',
                'shipping',
                'seller.shop',
                'offlinePayments',
                'deliveryMan',
                'orderEditHistory' => fn($q) => $q->orderBy('id', 'desc'),
                'orderStatusHistory' => function ($query) {
                    $query->orderBy('created_at', 'desc');
                }
            ]
        );

        if (!$order) {
            ToastMagic::error(translate('Order_not_found'));
            return redirect()->route('admin.orders.list', ['status' => 'all']);
        }

        if ($order['init_order_amount'] <= 0) {
            $this->orderRepo->updateWhere(
                params: ['id' => $id],
                data: ['init_order_amount' => $order['order_amount']]
            );
        }

        $physicalProduct = false;
        foreach ($order->details ?? [] as $orderDetail) {
            $orderDetailProduct = json_decode($orderDetail?->product_details, true);
            if (
                (isset($orderDetail?->product?->product_type) && $orderDetail->product->product_type === 'physical') ||
                (isset($orderDetailProduct['product_type']) && $orderDetailProduct['product_type'] === 'physical')
            ) {
                $physicalProduct = true;
                break;
            }
        }
        $whereNotIn = ['order_group_id' => ['def-order-group'], 'id' => [$order['id']]];
        $linkedOrders = $this->orderRepo->getListWhereNotIn(
            filters: ['order_group_id' => $order['order_group_id']],
            whereNotIn: $whereNotIn,
            dataLimit: 'all'
        );

        $totalDelivered = $this->orderRepo->getListWhereCount(filters: [
            'seller_id' => $order['seller_id'],
            'order_status' => 'delivered',
            'order_type' => 'default_type',
        ]);
        $sellerId = ($order['seller_is'] === 'seller' && $order['shipping_responsibility'] === 'sellerwise_shipping') ? $order['seller_id'] : 0;

        $deliveryMen = $this->deliveryManRepo->getListWhere(filters: ['is_active' => 1, 'seller_id' => $sellerId], dataLimit: 'all');
        $isOrderOnlyDigital = $orderService->getCheckIsOrderOnlyDigital(order: $order);
        $previousOrder = $this->orderRepo->getPreviousFirstOrderWhere(id: $id);
        $nextOrder = $this->orderRepo->getNextFirstOrderWhere(id: $id);
        $relations = [
            'brand',
            'category',
            'seller.shop',
        ];
        $allProductsList = $this->productRepo->getListWhere(
            filters: ['added_by' => 'in_house'],
            relations: $relations,
            dataLimit: 10
        );
        $isOrderEditable = $this->orderEditService->checkIsOrderEditable(order: $order, type: 'admin');
        Session::forget($this->orderEditService->getOrderEditSessionKey(orderId: $order['id']));

        $orderProductsSession = $this->orderEditService->getOrderEditSession(order: $order);
        $editOrderSummary = $this->generateEditOrderSummary(order: $order, editedOrder: ($orderProductsSession['product_list'] ?? []), data: [
            'edit_by' => 'admin',
            'edited_user_id' => auth()->guard('admin')->id(),
            'edited_user_name' => auth()->guard('admin')->user()->name,
        ]);

        $orderEditPaymentHistory = $this->orderEditHistoryRepo->getListWhere(
            filters: ['order_id' => $order['id']],
            dataLimit: 'all'
        );
        $orderCount = $order['order_type'] === 'default_type'
            ? $this->orderRepo->getListWhereCount(filters: ['customer_id' => $order['customer_id']])
            : $this->orderRepo->getListWhereCount(filters: ['customer_id' => $order['customer_id'], 'order_type' => 'POS']);

        $view = $order['order_type'] === 'default_type' ? 'admin-views.order.order-details' : 'admin-views.pos.order.order-details';

        return view($view, compact(
            'order', 'linkedOrders', 'deliveryMen', 'totalDelivered',
            'companyName', 'companyWebLogo', 'physicalProduct',
            'countryRestrictStatus', 'zipRestrictStatus', 'countries', 'zipCodes',
            'orderCount', 'isOrderOnlyDigital', 'previousOrder', 'nextOrder',
            'allProductsList', 'isOrderEditable', 'orderProductsSession',
            'editOrderSummary', 'orderEditPaymentHistory'
        ));
    }

    public function generateInvoice(string|int $id)
    {
        $companyPhone = getWebConfig(name: 'company_phone');
        $companyEmail = getWebConfig(name: 'company_email');
        $companyName = getWebConfig(name: 'company_name');
        $companyWebLogo = getWebConfig(name: 'company_web_logo');
        $order = $this->orderRepo->getFirstWhere(params: ['id' => $id], relations: ['seller', 'shipping', 'details']);
        $vendor = $this->vendorRepo->getFirstWhere(params: ['id' => $order['details']->first()->seller_id]);
        $invoiceSettings = getWebConfig(name: 'invoice_settings');
        if (is_null(getWebConfig(name: 'company_web_logo_png'))) {
            $logo = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'company_web_logo'])?->value ?? '';
            $this->businessSettingRepo->updateOrInsert(type: 'company_web_logo_png', value: is_array($logo) ? json_encode($logo) : $logo);
        }
        $mpdfView = PdfView::make('admin-views.order.invoice',
            compact('order', 'vendor', 'companyPhone', 'companyEmail', 'companyName', 'companyWebLogo', 'invoiceSettings')
        );
        $this->generatePdf(view: $mpdfView, filePrefix: 'order_invoice_', filePostfix: $order['id'], pdfType: 'invoice');
    }

    public function updateStatus(
        Request                       $request,
        DeliveryManTransactionService $deliveryManTransactionService,
        DeliveryManWalletService      $deliveryManWalletService,
        OrderStatusHistoryService     $orderStatusHistoryService,
    ): JsonResponse
    {
        $order = $this->orderRepo->getFirstWhere(params: ['id' => $request['id']], relations: ['customer', 'seller.shop', 'deliveryMan', 'latestEditHistory']);

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
        if ($order['edit_due_amount'] > 0 && $order?->latestEditHistory?->order_due_payment_method == 'cash_on_delivery' && $order?->latestEditHistory?->order_due_payment_status == 'unpaid' && $request['order_status'] == 'delivered') {
            return response()->json([
                'status' => 0,
                'message' => translate('Please mark as paid before delivered this order.'),
            ]);
        }

        if ($request['order_status'] == 'delivered') {
            foreach ($order['details'] as $orderDetail) {
                $productDetails = json_decode($orderDetail?->product_details ?? '', true);
                if (
                    $productDetails['product_type'] == 'digital' &&
                    (isset($productDetails['digital_product_type']) && $productDetails['digital_product_type'] == 'ready_after_sell') &&
                    is_null($orderDetail['digital_file_after_sell'])
                ) {
                    return response()->json([
                        'status' => 0,
                        'message' => translate('Please_upload_the_digital_product_files_first'),
                    ]);
                }
            }
        }
        // Delivered means every provider shipment of this order is delivered. An order with no provider
        // fulfillment (every existing order) is never blocked here.
        $blockedReason = app(FulfillmentOrderGuard::class)->statusChangeBlockedReason(orderId: (int) $order['id'], newStatus: (string) $request['order_status']);
        if ($blockedReason) {
            return response()->json([
                'status' => 0,
                'message' => $blockedReason,
            ]);
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
        if ($order['seller_is'] == 'seller') {
            if ($request['order_status'] == 'canceled') {
                event(new OrderStatusEvent(key: 'canceled', type: 'seller', order: $order));
            } elseif ($request['order_status'] == 'delivered') {
                event(new OrderStatusEvent(key: 'delivered', type: 'seller', order: $order));
            }
        }

        $loyaltyPointStatus = getWebConfig(name: 'loyalty_point_status');
        $loyaltyPointEachOrder = getWebConfig(name: 'loyalty_point_for_each_order');
        $loyaltyPointEachOrder = !is_null($loyaltyPointEachOrder) ? $loyaltyPointEachOrder : $loyaltyPointStatus;
        $orderDetailsRewards = $this->orderDetailsRewardsRepo->getFirstWhere(params: ['order_id' => $order['id'], 'reward_type' => 'loyalty_point']);

        if ($orderDetailsRewards && $orderDetailsRewards['reward_delivered'] != 1 && $orderDetailsRewards['reward_amount'] > 0 && $loyaltyPointStatus == 1 && $loyaltyPointEachOrder == 1 && !$order['is_guest'] && $request['order_status'] == 'delivered') {
            $this->loyaltyPointTransactionRepo->addLoyaltyPointTransaction(userId: $order['customer_id'], reference: $order['id'], amount: usdToDefaultCurrency(amount: $order['order_amount'] - $order['shipping_cost']), transactionType: 'order_place');
            $this->orderDetailsRewardsRepo->update(id: $orderDetailsRewards['id'], data: ['reward_delivered' => 1]);
        }

        OrderManager::generateReferBonusForFirstOrder(orderId: $order['id']);

        if ($order['delivery_man_id'] && $request->order_status == 'delivered') {
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
                $deliveryManTransactionData = $deliveryManTransactionService->getDeliveryManTransactionData(amount: $order['deliveryman_charge'], addedBy: 'admin', id: $order['delivery_man_id'], transactionType: 'deliveryman_charge');
                $this->deliveryManTransactionRepo->add($deliveryManTransactionData);
            }
        }

        $orderStatusHistoryData = $orderStatusHistoryService->getOrderHistoryData(orderId: $request['id'], userId: 0, userType: 'admin', status: $request['order_status']);
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
            $this->orderRepo->manageWalletOnOrderStatusChange(order: $order, receivedBy: 'admin');
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

    /**
     * Apply one status change to many orders.
     *
     * Delegates to updateStatus() per order rather than reimplementing it. That
     * method carries every guard (deleted customer, unconfirmed offline payment,
     * outstanding due or return amount, missing digital files) and every side
     * effect (stock, status history, customer/seller/deliveryman events, loyalty
     * points, deliveryman wallet, vendor settlement). A second copy of that would
     * drift, and the drift would be silent money and stock errors.
     *
     * Partial success is the normal outcome: an order that fails its guards is
     * skipped and reported by number, so the operator learns which ones need
     * attention instead of the whole batch failing.
     */
    public function bulkUpdateStatus(
        Request                       $request,
        DeliveryManTransactionService $deliveryManTransactionService,
        DeliveryManWalletService      $deliveryManWalletService,
        OrderStatusHistoryService     $orderStatusHistoryService,
    ): JsonResponse
    {
        $ids = array_values(array_unique(array_filter((array) $request->input('ids', []))));
        // Not cast: `order_status[]=x` would throw before the allow-list below could refuse it.
        $status = is_string($request->input('order_status')) ? $request->input('order_status') : '';

        if (empty($ids)) {
            return response()->json(['status' => 0, 'message' => translate('select_at_least_one_order')], 422);
        }
        if (!in_array($status, self::BULK_STATUSES, true)) {
            return response()->json(['status' => 0, 'message' => translate('unsupported_order_status')], 422);
        }
        if (count($ids) > self::BULK_LIMIT) {
            return response()->json([
                'status' => 0,
                'message' => translate('select_no_more_than') . ' ' . self::BULK_LIMIT . ' ' . translate('orders'),
            ], 422);
        }

        $updated = 0;
        $skipped = [];

        foreach ($ids as $id) {
            $single = new Request(['id' => $id, 'order_status' => $status]);
            $single->setUserResolver($request->getUserResolver());

            $result = $this->updateStatus(
                $single,
                $deliveryManTransactionService,
                $deliveryManWalletService,
                $orderStatusHistoryService,
            );
            $payload = $result->getData(true);

            if (($payload['status'] ?? 0) === 1) {
                $updated++;
            } else {
                $skipped[] = ['id' => $id, 'reason' => $payload['message'] ?? translate('could_not_be_updated')];
            }
        }

        return response()->json([
            'status' => $updated > 0 ? 1 : 0,
            'updated' => $updated,
            'skipped' => $skipped,
            'message' => $updated > 0
                ? $updated . ' ' . translate('orders_updated')
                : translate('no_orders_could_be_updated'),
        ]);
    }

    public function updateAddress(Request $request, OrderAddressUpdateService $addressUpdateService): RedirectResponse
    {
        $order = $this->orderRepo->getFirstWhere(params: ['id' => $request['order_id']], relations: ['seller.shop', 'deliveryMan']);
        if (!$order) {
            ToastMagic::error(translate('Order_not_found'));
            return back();
        }

        try {
            $addressUpdateService->update(order: $order, addressType: $request['address_type'], input: $request->all());
        } catch (ValidationException $exception) {
            ToastMagic::error($exception->validator->errors());
            return back();
        }

        if ($order->seller_is == 'seller') {
            OrderStatusEvent::dispatch('order_edit_message', 'seller', $order);
        }

        if ($order->delivery_type == 'self_delivery' && $order->delivery_man_id) {
            OrderStatusEvent::dispatch('order_edit_message', 'delivery_man', $order);
        }

        ToastMagic::success(translate('successfully_updated'));
        return back();
    }

    public function updateDeliverInfo(Request $request): RedirectResponse
    {
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

        $orderData = $this->orderRepo->getFirstWhere(params: ['id' => $order_id]);
        $order = [
            'seller_is' => $orderData->seller_is,
            'delivery_man_id' => $delivery_man_id,
            'delivery_type' => 'self_delivery',
            'delivery_service_name' => null,
            'third_party_delivery_tracking_id' => null,
        ];

        if ($orderData['delivery_man_id'] != $delivery_man_id) {
            $order['deliveryman_assigned_at'] = Carbon::now();
        }

        $this->orderRepo->update(id: $order_id, data: $order);

        $order = $this->orderRepo->getFirstWhere(params: ['id' => $order_id], relations: ['seller.shop', 'deliveryMan']);

        event(new OrderStatusEvent(key: 'new_order_assigned_message', type: 'delivery_man', order: $order));

        /** For Seller Product Send Notification */
        if ($order['seller_is'] == 'seller') {
            event(new OrderStatusEvent(key: 'delivery_man_assign_by_admin_message', type: 'seller', order: $order));
        }
        /** end */

        return response()->json(['status' => true], 200);
    }

    public function updateAmountDate(Request $request): JsonResponse
    {
        $userId = 0;
        $status = $this->orderRepo->updateAmountDate(request: $request, userId: $userId, userType: 'admin');
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

    public function getCustomers(Request $request): JsonResponse
    {
        $allCustomer = ['id' => 'all', 'text' => 'All Customer'];
        $customers = $this->customerRepo->getCustomerNameList(request: $request)->toArray();
        array_unshift($customers, $allCustomer);

        return response()->json($customers);
    }

    public function updatePaymentStatus(Request $request): JsonResponse
    {
        $order = $this->orderRepo->getFirstWhere(params: ['id' => $request['id']]);

        if ($order['is_guest'] == '0' && !isset($order['customer'])) {
            return response()->json([
                'status' => 0,
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

    public function filterInHouseOrder(): RedirectResponse
    {
        if (session()->has('show_inhouse_orders') && session('show_inhouse_orders') == 1) {
            session()->put('show_inhouse_orders', 0);
        } else {
            session()->put('show_inhouse_orders', 1);
        }
        return back();
    }

    public function uploadDigitalFileAfterSell(UploadDigitalFileAfterSellRequest $request): RedirectResponse
    {
        $orderDetails = $this->orderDetailRepo->getFirstWhere(['id' => $request['order_id']]);
        $digitalFileAfterSell = $this->updateFile(dir: 'product/digital-product/', oldImage: $orderDetails['digital_file_after_sell'], format: $request['digital_file_after_sell']->getClientOriginalExtension(), image: $request->file('digital_file_after_sell'), fileType: 'file');

        if ($this->orderDetailRepo->update(id: $orderDetails['id'], data: ['digital_file_after_sell' => $digitalFileAfterSell])) {
            ToastMagic::success(translate('digital_file_upload_successfully'));
        } else {
            ToastMagic::error(translate('digital_file_upload_failed'));
        }
        return back();
    }

    public function getSearchEditOrderProductsView(Request $request): JsonResponse
    {
        $searchValue = $request['searchValue'] ?? null;
        $products = $this->productRepo->getListWithScope(
            orderBy: ['id' => 'desc'],
            searchValue: $searchValue,
            scope: "active",
            filters: ['added_by' => 'in_house'],
            relations: ['brand', 'category', 'seller.shop'],
            dataLimit: 'all');

        return response()->json([
            'count' => $products->count(),
            'result' => view('admin-views.order.partials._search-product', compact('products'))->render(),
        ]);
    }

    public function getEditOrderProductModalView(Request $request): JsonResponse
    {
        $productSubtotal = $cartItems['productSubtotal'] ?? 0;
        $product = $this->productRepo->getFirstWhereWithCount(
            params: ['id' => $request['product_id']],
            withCount: ['reviews'],
            relations: ['brand', 'category', 'rating', 'tags', 'digitalVariation', 'clearanceSale' => function ($query) {
                return $query->active();
            }],
        );
        $productAuthorIds = $this->productService->getProductAuthorsInfo(product: $product)['ids'];
        $digitalProductAuthors = $this->authorRepo->getListWhere(dataLimit: 'all');
        $productPublishingHouseIds = $this->productService->getProductPublishingHouseInfo(product: $product)['ids'];
        $publishingHouseRepo = $this->publishingHouseRepo->getListWhere(dataLimit: 'all');
        return response()->json([
            'htmlView' => view('admin-views.order.partials._quick-view', compact('product', 'digitalProductAuthors', 'productAuthorIds', 'productPublishingHouseIds', 'publishingHouseRepo', 'productSubtotal'))->render(),
        ]);
    }

}