<?php

namespace App\Http\Controllers\Admin\Vendor;

use App\Contracts\Repositories\AdminRepositoryInterface;
use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Contracts\Repositories\DeliveryCountryCodeRepositoryInterface;
use App\Contracts\Repositories\OrderEditHistoryRepositoryInterface;
use App\Enums\GlobalConstant;
use App\Http\Requests\Admin\VendorAddRequest;
use App\Services\DeliveryCountryCodeService;
use App\Services\OrderEditService;
use App\Services\OrderService;
use App\Traits\OrderEditManager;
use Exception;
use App\Enums\WebConfigKey;
use App\Traits\CommonTrait;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Services\ShopService;
use App\Traits\PaginatorTrait;
use App\Services\VendorService;
use App\Exports\VendorListExport;
use Illuminate\Http\JsonResponse;
use App\Traits\EmailTemplateTrait;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Facades\Excel;
use App\Traits\PushNotificationTrait;
use Illuminate\Http\RedirectResponse;
use App\Exports\VendorOrderListExport;
use App\Exports\VendorWithdrawRequest;
use App\Events\VendorRegistrationEvent;
use App\Http\Controllers\BaseController;
use App\Events\WithdrawStatusUpdateEvent;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use App\Contracts\Repositories\ShopRepositoryInterface;
use App\Contracts\Repositories\OrderRepositoryInterface;
use Modules\TaxModule\app\Traits\VatTaxManagement;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use App\Contracts\Repositories\ReviewRepositoryInterface;
use App\Contracts\Repositories\VendorRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Enums\ExportFileNames\Admin\Vendor as VendorExport;
use App\Contracts\Repositories\DeliveryManRepositoryInterface;
use App\Contracts\Repositories\VendorWalletRepositoryInterface;
use App\Contracts\Repositories\DeliveryZipCodeRepositoryInterface;
use App\Contracts\Repositories\ShippingAddressRepositoryInterface;
use App\Contracts\Repositories\WithdrawRequestRepositoryInterface;
use App\Contracts\Repositories\OrderTransactionRepositoryInterface;
use App\Contracts\Repositories\StockClearanceSetupRepositoryInterface;
use App\Contracts\Repositories\StockClearanceProductRepositoryInterface;

class VendorController extends BaseController
{
    /** Statuses a bulk action may set. */
    private const BULK_STATUSES = ['approved', 'suspended', 'rejected'];

    /** Each id emails that vendor, so the cap stays low. */
    private const BULK_LIMIT = 50;

    use PaginatorTrait;
    use CommonTrait;
    use PushNotificationTrait;
    use EmailTemplateTrait;
    use VatTaxManagement;
    use OrderEditManager;

    public function __construct(
        private readonly CustomerRepositoryInterface              $customerRepo,
        private readonly VendorRepositoryInterface                $vendorRepo,
        private readonly OrderRepositoryInterface                 $orderRepo,
        private readonly ProductRepositoryInterface               $productRepo,
        private readonly ReviewRepositoryInterface                $reviewRepo,
        private readonly DeliveryManRepositoryInterface           $deliveryManRepo,
        private readonly OrderTransactionRepositoryInterface      $orderTransactionRepo,
        private readonly ShippingAddressRepositoryInterface       $shippingAddressRepo,
        private readonly DeliveryZipCodeRepositoryInterface       $deliveryZipCodeRepo,
        private readonly WithdrawRequestRepositoryInterface       $withdrawRequestRepo,
        private readonly VendorWalletRepositoryInterface          $vendorWalletRepo,
        private readonly ShopRepositoryInterface                  $shopRepo,
        private readonly VendorService                            $vendorService,
        private readonly ShopService                              $shopService,
        private readonly StockClearanceProductRepositoryInterface $stockClearanceProductRepo,
        private readonly StockClearanceSetupRepositoryInterface   $stockClearanceSetupRepo,
        private readonly AdminRepositoryInterface                 $adminRepo,
        private readonly DeliveryCountryCodeRepositoryInterface   $deliveryCountryCodeRepo,
        private readonly OrderEditService                         $orderEditService,
        private readonly OrderEditHistoryRepositoryInterface      $orderEditHistoryRepo,

    )
    {
    }

    /**
     * @param Request|null $request
     * @param string|null $type
     * @return View
     * Index function is the starting point of a controller
     */
    public function index(Request|null $request, ?string $type = null): View
    {
        $orderBy = $request['sort_by'] == 'orders_count' ? ['orders_count' => 'desc'] : ['id' => 'desc'];

        if ($request['sort_by'] == 'oldest') {
            $orderBy = ['id' => 'asc'];
        }
        if ($request['sort_by'] == 'most-favorite') {
            $orderBy = ['wishlist_count' => 'desc'];
        }
        $current_date = date('Y-m-d');
        $vendors = $this->vendorRepo->getListWhere(
            orderBy: $orderBy,
            searchValue: requestString('searchValue') ?: null,
            relations: ['orders', 'product', 'shop', 'wallet'],
            dataLimit: getWebConfig(name: WebConfigKey::PAGINATION_LIMIT)
        );
        return view('admin-views.vendor.index', compact('vendors', 'current_date'));
    }


    public function getAddView(Request $request): View
    {
        return view('admin-views.vendor.add-new-vendor');
    }

    public function add(VendorAddRequest $request): JsonResponse
    {

        $adminEmail = $this->adminRepo->getFirstWhere(['admin_role_id' => 1]);
        if ($adminEmail && isset($adminEmail['email']) && $request['email'] === $adminEmail['email']) {
            return response()->json([
                'error' => 1,
                'message' => translate('Email_already_exist_please_try_another_email'),
            ]);
        }
        $vendor = $this->vendorRepo->add(data: $this->vendorService->getAddData($request));
        $this->shopRepo->add($this->shopService->getAddShopDataForRegistration(request: $request, vendorId: $vendor['id']));
        $this->vendorWalletRepo->add($this->vendorService->getInitialWalletData(vendorId: $vendor['id']));
        $data = [
            'vendorName' => $request['f_name'],
            'status' => 'pending',
            'subject' => translate('Vendor_Registration_Successfully_Completed'),
            'title' => translate('Vendor_Registration_Successfully_Completed'),
            'userType' => 'vendor',
            'templateName' => 'registration',
        ];
        try {
            event(new VendorRegistrationEvent(email: $request['email'], data: $data));
        } catch (Exception $e) {
        }
        return response()->json(['message' => translate('vendor_added_successfully')]);
    }

    /**
     * Approve, suspend or reject many vendors at once.
     *
     * Delegates to updateStatus() per vendor. That method picks a different email
     * template for each transition — a pending vendor being approved gets
     * "registration approved", an active one being suspended gets "account
     * suspended" — and suspending also rotates the vendor's auth token so their
     * session dies. None of that may be duplicated here.
     *
     * Its RedirectResponse is discarded: this endpoint answers with JSON, and the
     * per-vendor toasts it flashes are replaced by one aggregate result.
     */
    public function bulkUpdateStatus(Request $request): JsonResponse
    {
        $ids = array_values(array_unique(array_filter((array) $request->input('ids', []))));
        // Not cast: `status[]=x` would raise the warning this application turns into a throw,
        // so the allow-list below — which is the real check — would never run.
        $status = is_string($request->input('status')) ? $request->input('status') : '';

        if (empty($ids)) {
            return response()->json(['status' => 0, 'message' => translate('select_at_least_one_vendor')], 422);
        }
        if (!in_array($status, self::BULK_STATUSES, true)) {
            return response()->json(['status' => 0, 'message' => translate('unsupported_status')], 422);
        }
        if (count($ids) > self::BULK_LIMIT) {
            return response()->json([
                'status' => 0,
                'message' => translate('select_no_more_than') . ' ' . self::BULK_LIMIT . ' ' . translate('vendors'),
            ], 422);
        }

        $updated = 0;
        $skipped = [];

        foreach ($ids as $id) {
            try {
                $this->updateStatus(new Request(['id' => $id, 'status' => $status]));
                $updated++;
            } catch (\Throwable $exception) {
                report($exception);
                $skipped[] = ['id' => $id, 'reason' => translate('could_not_be_updated')];
            }
        }

        return response()->json([
            'status' => $updated > 0 ? 1 : 0,
            'updated' => $updated,
            'skipped' => $skipped,
            'message' => $updated > 0
                ? $updated . ' ' . translate('vendors_updated')
                : translate('no_vendors_could_be_updated'),
        ]);
    }

    public function updateStatus(Request $request): RedirectResponse
    {
        $vendor = $this->vendorRepo->getFirstWhere(params: ['id' => $request['id']]);
        $this->vendorRepo->update(id: $request['id'], data: ['status' => $request['status']]);
        if ($request['status'] == "approved") {
            ToastMagic::success(translate('Vendor_has_been_approved_successfully'));
        } else if ($request['status'] == "rejected") {
            ToastMagic::info(translate('Vendor_has_been_rejected_successfully'));
        } else if ($request['status'] == "suspended") {
            $this->vendorRepo->update(id: $request['id'], data: ['auth_token' => Str::random(80)]);
            ToastMagic::info(translate('Vendor_has_been_suspended_successfully'));
        }
        if ($vendor['status'] == 'pending') {
            if ($request['status'] == "approved") {
                $data = [
                    'vendorName' => $vendor['f_name'],
                    'status' => 'approved',
                    'subject' => translate('Vendor_Registration_Approved'),
                    'title' => translate('Vendor_Registration_Approved'),
                    'userType' => 'vendor',
                    'templateName' => 'registration-approved',
                ];
            } elseif ($request['status'] == "rejected") {
                $data = [
                    'vendorName' => $vendor['f_name'],
                    'status' => 'denied',
                    'subject' => translate('Vendor_Registration_Denied'),
                    'title' => translate('Vendor_Registration_Denied'),
                    'userType' => 'vendor',
                    'templateName' => 'registration-denied',
                ];
            }
        } else {
            if ($request['status'] == "suspended") {
                $data = [
                    'vendorName' => $vendor['f_name'],
                    'status' => 'suspended',
                    'subject' => translate('Account_Suspended'),
                    'title' => translate('Account_Suspended'),
                    'userType' => 'vendor',
                    'templateName' => 'account-suspended',
                ];
            } else {
                $data = [
                    'vendorName' => $vendor['f_name'],
                    'status' => 'approved',
                    'subject' => translate('Account_Activate'),
                    'title' => translate('Account_Activate'),
                    'userType' => 'vendor',
                    'templateName' => 'account-activation',
                ];
            }
        }
        event(new VendorRegistrationEvent(email: $vendor['email'], data: $data));
        return back();
    }

    public function exportList(Request $request): BinaryFileResponse
    {
        $vendors = $this->vendorRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            searchValue: requestString('searchValue') ?: null,
            relations: ['orders', 'product'],
            dataLimit: 'all'
        );

        $active = $vendors->where('status', 'approved')->count();
        $inactive = $vendors->where('status', '!=', 'approved')->count();
        $data = [
            'vendors' => $vendors,
            'search' => $request['searchValue'],
            'active' => $active,
            'inactive' => $inactive,
        ];
        return Excel::download(new VendorListExport($data), 'Seller-list.xlsx');
    }

    public function exportOrderList(Request $request, $vendorId): BinaryFileResponse
    {
        $shop = $this->shopRepo->getFirstWhere(params: ['seller_id' => $vendorId]);
        $orders = $this->orderRepo->getListWhere(orderBy: ['id' => 'desc'], searchValue: requestString('searchValue') ?: null, filters: ['seller_id' => $vendorId, 'seller_is' => 'seller'], dataLimit: 'all');
        $statusArray = [
            'pending' => 0,
            'confirmed' => 0,
            'processing' => 0,
            'out_for_delivery' => 0,
            'delivered' => 0,
            'returned' => 0,
            'failed' => 0,
            'canceled' => 0,
        ];
        $orders?->map(function ($order) use (&$statusArray) { // Pass by reference using &
            if (isset($statusArray[$order->order_status])) {
                $statusArray[$order->order_status]++;
            }
        });
        $data = [
            'shop' => $shop,
            'statusArray' => $statusArray,
            'orders' => $orders,
        ];
        return Excel::download(new VendorOrderListExport($data), 'Order-List.xlsx');
    }

    public function updateSalesCommission(Request $request, $id): RedirectResponse
    {

        if ($request['status'] == 1 && $request['commission'] == null) {
            ToastMagic::error(translate('you_did_not_set_commission_percentage_field'));
            return back();
        }
        $this->vendorRepo->update(id: $id, data: ['sales_commission_percentage' => $request['commission_status'] == 1 ? $request['commission'] : null]);
        ToastMagic::success(translate('Commission_percentage_for_this_seller_has_been_updated'));
        return back();
    }

    public function getOrderDetailsView(string|int $id, DeliveryCountryCodeService $service, OrderService $orderService): View|RedirectResponse
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
                'details.productAllStatus',
                'latestEditHistory',
                'verificationImages',
                'shipping',
                'seller.shop',
                'offlinePayments',
                'deliveryMan',
                'orderEditHistory' => fn($q) => $q->orderBy('id', 'desc'),
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
            dataLimit: 'all'
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

    public function getView(Request $request, $id, $tab = null): View|RedirectResponse
    {
        $taxData = $this->getTaxSystemType();
        $productWiseTax = $taxData['productWiseTax'] && !$taxData['is_included'];
        $seller = $this->vendorRepo->getFirstWhere(
            params: ['id' => $id, 'withCount' => ['product', 'orders' => function ($query) use ($id) {
                $query->where(['seller_id' => $id, 'seller_is' => ($id == 0 ? 'admin' : 'seller')]);
            }]],
            relations: ['orders', 'product' => function ($query) {
                return $query->with(['taxVats' => function ($query) {
                    return $query->with(['tax'])->wherehas('tax', function ($query) {
                        return $query->where('is_active', 1);
                    });
                }]);
            }]
        );

        if (!$seller) {
            return redirect()->route('admin.vendors.vendor-list');
        }
        $seller?->product?->map(function ($product) {
            $product['rating'] = $product?->reviews->pluck('rating')->sum();
            $product['rating_count'] = $product->reviews->count();
            $product['single_rating_5'] = 0;
            $product['single_rating_4'] = 0;
            $product['single_rating_3'] = 0;
            $product['single_rating_2'] = 0;
            $product['single_rating_1'] = 0;
            foreach ($product->reviews as $review) {
                $rating = $review->rating;
                if ($rating > 0) {
                    match ($rating) {
                        5 => $product->single_rating_5++,
                        4 => $product->single_rating_4++,
                        3 => $product->single_rating_3++,
                        2 => $product->single_rating_2++,
                        1 => $product->single_rating_1++,
                    };
                }
            }
        });
        $seller['single_rating_5'] = $seller?->product->pluck('single_rating_5')->sum();
        $seller['single_rating_4'] = $seller?->product->pluck('single_rating_4')->sum();
        $seller['single_rating_3'] = $seller?->product->pluck('single_rating_3')->sum();
        $seller['single_rating_2'] = $seller?->product->pluck('single_rating_2')->sum();
        $seller['single_rating_1'] = $seller?->product->pluck('single_rating_1')->sum();
        $seller['total_rating'] = $seller?->product->pluck('rating')->sum();
        $seller['rating_count'] = $seller->product->pluck('rating_count')->sum();
        $seller['average_rating'] = $seller['total_rating'] / ($seller['rating_count'] == 0 ? 1 : $seller['rating_count']);
        $seller['average_rating'] = $seller['total_rating'] / ($seller['rating_count'] == 0 ? 1 : $seller['rating_count']);

        if (!isset($seller)) {
            ToastMagic::error(translate('vendor_not_found_It_may_be_deleted'));
            return back();
        }

        if ($tab == 'order') {
            return $this->getOrderListTabView(request: $request, seller: $seller);
        } else if ($tab == 'product') {
            return $this->getProductListTabView(request: $request, seller: $seller);
        } else if ($tab == 'setting') {
            return $this->getSettingListTabView(request: $request, seller: $seller, id: $id);
        } else if ($tab == 'transaction') {
            return $this->getTransactionListTabView(request: $request, seller: $seller);
        } else if ($tab == 'review') {
            return $this->getReviewListTabView(request: $request, seller: $seller);
        } else if ($tab == 'clearance_sale') {
            return $this->getClearanceSaleTabView(request: $request, seller: $seller);
        }

        return view('admin-views.vendor.view', [
            'seller' => $seller,
            'productWiseTax' => $productWiseTax,
            'current_date' => date('Y-m-d'),
        ]);
    }

    public function getOrderListTabView(Request $request, $seller): View
    {
        $orders = $this->orderRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            searchValue: requestString('searchValue') ?: null,
            filters: ['seller_id' => $seller['id'], 'seller_is' => 'seller', 'order_type' => 'default_type', 'order_status' => $request['order_status']],
            relations: ['details', 'customer', 'seller.shop', 'orderEditHistory'],
            dataLimit: getWebConfig(name: WebConfigKey::PAGINATION_LIMIT),
        );

        $dateType = $request['date_type'];
        $paymentPaidStatus = $request['payment_status'] ?? [];
        $orderStatus = $request['order_current_status'] ?? [];

        $filters = [
            'filter' => $request['filter'] ?? 'all',
            'date_type' => $request['date_type'],
            'from' => $request['from'],
            'to' => $request['to'],
            'delivery_man_id' => $request['delivery_man_id'],
            'customer_id' => $request['customer_id'],
            'seller_id' => $seller['id'],
            'seller_is' => 'seller',
        ];
        $orderAmountSettlement = $request->input('order_amount_settlement', []);

        if (!empty($orderAmountSettlement)) {
            $filters['has_order_edit_settlement'] = $orderAmountSettlement;
        }

        $filterWhereIn = [];
        $filterWhereIn['order_type'] = ['default_type'];
        $filterWhereIn['payment_status'] = $paymentPaidStatus;
        $filterWhereIn['order_status'] = $orderStatus;

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

        return view('admin-views.vendor.view.order', compact('seller', 'orders', 'allOrdersInfo'));
    }

    public function getProductListTabView(Request $request, $seller): View
    {
        $taxData = $this->getTaxSystemType();
        $productWiseTax = $taxData['productWiseTax'] && !$taxData['is_included'];

        $products = $this->productRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            searchValue: requestString('searchValue') ?: null,
            filters: ['seller_id' => $seller['id'], 'added_by' => 'seller'],
            relations: ['translations'],
            dataLimit: getWebConfig(name: WebConfigKey::PAGINATION_LIMIT)
        );
        return view('admin-views.vendor.view.product', [
            'seller' => $seller,
            'products' => $products,
            'productWiseTax' => $productWiseTax,
        ]);
    }

    public function getSettingListTabView(Request $request, $seller, $id): View
    {
        return view('admin-views.vendor.view.setting', compact('seller'));
    }

    public function updateSetting(Request $request, $id): RedirectResponse
    {
        if ($request->has('commission')) {
            request()->validate([
                'commission' => 'required|numeric|min:1',
            ]);
            if ($request['commission_status'] == 1 && $request['commission'] == null) {
                ToastMagic::error(translate('you_did_not_set_commission_percentage_field.'));
            } else {
                $this->vendorRepo->update(id: $id, data: ['sales_commission_percentage' => $request['commission_status'] == 1 ? $request['commission'] : null]);
                ToastMagic::success(translate('commission_percentage_for_this_vendor_has_been_updated.'));
            }
        }
        if ($request->has('gst')) {
            if ($request['gst_status'] == 1 && $request['gst'] == null) {
                ToastMagic::error(translate('you_did_not_set_GST_number_field.'));
            } else {
                $this->vendorRepo->update(id: $id, data: ['gst' => $request['gst_status'] == 1 ? $request['gst'] : null]);
                ToastMagic::success(translate('GST_number_for_this_vendor_has_been_updated.'));
            }
        }
        if ($request->has('seller_pos_update')) {
            $this->vendorRepo->update(id: $id, data: ['pos_status' => $request->get('seller_pos', 0)]);
            ToastMagic::success(translate('vendor_pos_permission_updated'));
        }
        return redirect()->back();
    }

    public function getTransactionListTabView(Request $request, $seller): View
    {
        $filters = [
            'seller_is' => 'seller',
            'seller_id' => $seller['id'],
            'status' => $request['status'] ?? 'all',
            'customer_id' => $request['customer_id'] ?? null,
        ];
        $transactions = $this->orderTransactionRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            searchValue: requestString('searchValue') ?: null,
            filters: $filters,
            relations: ['order.customer'],
            dataLimit: getWebConfig(name: WebConfigKey::PAGINATION_LIMIT),
        );

        $customers = $this->customerRepo->getCustomerNameList(request: $request, skipIds: [0], dataLimit: 'all')->toArray();
        array_unshift($customers, ['id' => 'all', 'text' => translate('All_Customer')]);

        return view('admin-views.vendor.view.transaction', compact('seller', 'transactions', 'customers'));
    }

    public function getReviewListTabView(Request $request, $seller): View
    {
        if ($request->has('searchValue')) {
            $product_id = $this->productRepo->getListWhere(
                searchValue: requestString('searchValue') ?: null,
                filters: ['added_by' => 'seller', 'seller_id' => $seller['id']],
                dataLimit: 'all')->pluck('id')->toArray();
            $filtersBy = [
                'product_id' => !empty($product_id) ? $product_id : [0],
            ];

            $reviews = $this->reviewRepo->getListWhereIn(
                orderBy: ['id' => 'desc'],
                filters: ['added_by' => 'seller', 'product_user_id' => $seller['id']],
                whereInFilters: $filtersBy,
                relations: ['product'],
                nullFields: ['delivery_man_id'],
                dataLimit: getWebConfig(name: 'pagination_limit'));
        } else {
            $reviews = $this->reviewRepo->getListWhereIn(
                orderBy: ['id' => 'desc'],
                filters: ['added_by' => 'seller', 'product_user_id' => $seller['id']],
                relations: ['product'],
                dataLimit: getWebConfig(name: 'pagination_limit'));
        }
        return view('admin-views.vendor.view.review', [
            'seller' => $seller,
            'reviews' => $reviews,
        ]);
    }

    public function getClearanceSaleTabView(Request $request, $seller): View
    {
        $searchValue = $request['searchValue'] ?? null;
        $clearanceConfig = $this->stockClearanceSetupRepo->getFirstWhere(params: ['setup_by' => 'vendor', 'user_id' => $seller->id]);
        $stockClearanceProduct = $this->stockClearanceProductRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            searchValue: $searchValue,
            filters: ['added_by' => 'vendor', 'user_id' => $seller->id],
            relations: ['product']
        );
        return view('admin-views.vendor.view.clearance_sale', [
            'seller' => $seller,
            'stockClearanceProduct' => $stockClearanceProduct,
            'clearanceConfig' => $clearanceConfig,
        ]);
    }

    public function getWithdrawView($withdrawId, $vendorId): View|RedirectResponse
    {
        $withdrawRequest = $this->withdrawRequestRepo->getFirstWhere(params: ['id' => $withdrawId], relations: ['seller']);
        if ($withdrawRequest) {
            $withdrawalMethod = is_array($withdrawRequest['withdrawal_method_fields']) ? $withdrawRequest['withdrawal_method_fields'] : json_decode($withdrawRequest['withdrawal_method_fields']);
            $direction = session('direction');
            return view('admin-views.vendor.withdraw-view', compact('withdrawRequest', 'withdrawalMethod', 'direction'));
        }
        ToastMagic::error(translate('withdraw_request_not_found'));
        return back();
    }

    public function getWithdrawListView(Request $request): View
    {
        $withdrawRequests = $this->withdrawRequestRepo->getListWhereNull(
            orderBy: ['id' => 'desc'],
            searchValue: requestString('searchValue') ?: null,
            filters: ['approved' => $request['approved']],
            nullFilters: ['delivery_man_id'],
            relations: ['seller','seller.shop'],
            dataLimit: getWebConfig(name: 'pagination_limit')
        );
        return view('admin-views.vendor.withdraw', compact('withdrawRequests'));
    }

    public function exportWithdrawList(Request $request): BinaryFileResponse
    {
        $withdrawRequests = $this->withdrawRequestRepo->getListWhereNull(
            orderBy: ['id' => 'desc'],
            filters: ['approved' => $request['approved']],
            nullFilters: ['delivery_man_id'],
            relations: ['seller'],
            dataLimit: 'all'
        );

        $withdrawRequests->map(function ($query) {
            $query->shop_name = isset($query->seller) ? $query->seller->shop->name : '';
            $query->shop_phone = isset($query->seller) ? $query->seller->shop->contact : '';
            $query->shop_address = isset($query->seller) ? $query->seller->shop->address : '';
            $query->shop_email = isset($query->seller) ? $query->seller->email : '';
            $query->withdrawal_amount = setCurrencySymbol(amount: usdToDefaultCurrency(amount: $query->amount), currencyCode: getCurrencyCode(type: 'default'));
            $query->status = $query->approved == 0 ? 'Pending' : ($query->approved == 1 ? 'Approved' : 'Denied');
            $query->note = $query->transaction_note;
            $query->withdraw_method_name = isset($query->withdraw_method) ? $query->withdraw_method->method_name : '';
            if (!empty($query->withdrawal_method_fields)) {
                $withdrawal_method_fields = is_array($query->withdrawal_method_fields) ? $query->withdrawal_method_fields : json_decode($query->withdrawal_method_fields);
                foreach ($withdrawal_method_fields as $key => $field) {
                    $query[$key] = $field;
                }
            }
        });

        $pending = $withdrawRequests->where('approved', 0)->count();
        $approved = $withdrawRequests->where('approved', 1)->count();
        $denied = $withdrawRequests->where('approved', 2)->count();

        return Excel::download(new VendorWithdrawRequest([
            'data-from' => 'admin',
            'withdraw_request' => $withdrawRequests,
            'filter' => session('withdraw_status_filter'),
            'pending' => $pending,
            'approved' => $approved,
            'denied' => $denied,
        ]), 'Vendor-Withdraw-Request.xlsx'
        );
    }


    public function withdrawStatus(Request $request, $id): RedirectResponse
    {
        $withdrawData = [
            'approved' => $request['approved'],
            'transaction_note' => $request['note'],
        ];

        $withdraw = $this->withdrawRequestRepo->getFirstWhere(params: ['id' => $id], relations: ['seller']);

        // State guard: only a still-pending request may be decided. Without this, re-posting approved=1
        // for an already-approved request re-increments 'withdrawn' and re-decrements 'pending_withdraw'
        // every time, corrupting the seller's wallet — and would double-bridge into the ledger below.
        if ((int) ($withdraw->approved ?? 0) !== 0) {
            ToastMagic::warning(translate('this_withdraw_request_has_already_been_processed'));
            return redirect()->route('admin.vendors.withdraw_list');
        }

        if (isset($withdraw->seller->cm_firebase_token) && $withdraw->seller->cm_firebase_token) {
            event(new WithdrawStatusUpdateEvent(key: 'withdraw_request_status_message', type: 'seller', lang: $withdraw->deliveryMan?->app_language ?? getDefaultLanguage(), status: $request['approved'], fcmToken: $withdraw->seller?->cm_firebase_token));
        }

        if ($request['approved'] == 1) {
            $this->vendorWalletRepo->getFirstWhere(params: ['seller_id' => $withdraw['seller_id']])->increment('withdrawn', $withdraw['amount']);
            $this->vendorWalletRepo->getFirstWhere(params: ['seller_id' => $withdraw['seller_id']])->decrement('pending_withdraw', $withdraw['amount']);

            // Consolidation bridge: mirror the legacy payout into the vendor ledger so the ledger is the
            // single record of ALL money that has left, and its withdrawable() accounts for legacy
            // withdrawals too — closing the double-withdraw gap between the two channels. The legacy
            // amount is stored in USD; the ledger is in base currency, so convert. Idempotent on the
            // withdraw-request id (VendorLedger::record dedupes on entry+reference). This can only lower
            // ledger withdrawable, never raise it, so it can never enable an over-payment.
            app(\App\Services\Marketplace\VendorLedger::class)->record(
                sellerId: $withdraw['seller_id'],
                entryType: \App\Models\VendorLedgerEntry::TYPE_PAYOUT,
                debit: (float) usdToDefaultCurrency($withdraw['amount']),
                status: \App\Models\VendorLedgerEntry::STATUS_PAID,
                referenceType: 'legacy_withdraw',
                referenceId: $id,
                description: 'Legacy withdraw request #' . $id . ' paid',
            );

            $this->withdrawRequestRepo->update(id: $id, data: $withdrawData);
            ToastMagic::success(translate('Vendor_Payment_has_been_approved_successfully'));
            return redirect()->route('admin.vendors.withdraw_list');
        }

        $this->vendorWalletRepo->getFirstWhere(params: ['seller_id' => $withdraw['seller_id']])->increment('total_earning', $withdraw['amount']);
        $this->vendorWalletRepo->getFirstWhere(params: ['seller_id' => $withdraw['seller_id']])->decrement('pending_withdraw', $withdraw['amount']);
        $this->withdrawRequestRepo->update(id: $id, data: $withdrawData);

        ToastMagic::info(translate('Vendor_Payment_request_has_been_Denied_successfully'));
        return redirect()->route('admin.vendors.withdraw_list');

    }


    public function loadMoreStores(Request $request): JsonResponse
    {
        $oldShops = $request['filter_shop_ids'] ? json_decode($request['filter_shop_ids']) : [];
        $page = $request->input('page', 1);
        $filterShops = $this->shopRepo->getListWhere(
            orderBy: ['author_type' => 'asc'],
            dataLimit: 8,
            offset: $page);

        $visibleLimit = $filterShops->perPage();
        $totalShops = $filterShops->total();
        $hiddenCount = $totalShops - ($page * $visibleLimit);

        return response()->json([
            'html' => view('admin-views.partials.product-filters._filter-shops', [
                'filterShops' => $filterShops,
                'oldShops' => $oldShops,
            ])->render(),
            'visibleLimit' => $visibleLimit,
            'hiddenCount' => max(0, $hiddenCount),
            'totalShops' => $totalShops,
        ]);
    }
public function destroy(int $id): RedirectResponse
{
    $seller = $this->vendorRepo->getFirstWhere(params: ['id' => $id]);

    if (!$seller) {
        ToastMagic::error(translate('Vendor_not_found'));
        return back();
    }

    // حماية: منع حذف البائع الرئيسي (In-house / Admin)
    if ($id == 0) {
        ToastMagic::error(translate('Cannot_delete_inhouse_seller'));
        return back();
    }

    try {
        // 1. التحقق من الحالة: إذا كان البائع نشطاً (approved)، نقوم بتعليقه أولاً
        if ($seller->status === 'approved') {
            // تحديث الحالة إلى معلق
            $this->vendorRepo->update(id: $id, data: ['status' => 'suspended']);
            
            // تدوير رمز المصادقة (auth_token) لمنع البائع من الدخول فوراً (نفس منطق دالة updateStatus)
            $this->vendorRepo->update(id: $id, data: ['auth_token' => \Illuminate\Support\Str::random(80)]);
        }

        // 2. تنفيذ الحذف الناعم للبائع (سواء كان معلقاً الآن أو معلقاً أصلاً)
        $sellerModel = \App\Models\Seller::find($id);
        if ($sellerModel) {
            $sellerModel->delete(); // هذا يضع تاريخاً في deleted_at ويخفيه من القائمة الرئيسية
        }
        
        ToastMagic::success(translate('Vendor_deleted_successfully'));
        
    } catch (\Exception $e) {
        report($e);
        ToastMagic::error(translate('Failed_to_delete_vendor') . ': ' . $e->getMessage());
    }

    return redirect()->route('admin.vendors.vendor-list');
}

}