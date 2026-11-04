<?php

namespace App\Http\Controllers\Vendor\Order;

use App\Contracts\Repositories\AuthorRepositoryInterface;
use App\Contracts\Repositories\ColorRepositoryInterface;
use App\Contracts\Repositories\OrderDetailsRewardsRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\PublishingHouseRepositoryInterface;
use App\Services\CartService;
use App\Services\OrderEditService;
use App\Services\ProductService;
use App\Traits\OrderEditManager;
use Carbon\Carbon;
use App\Enums\WebConfigKey;
use App\Utils\OrderManager;
use App\Exports\OrderExport;
use App\Traits\PdfGenerator;
use Illuminate\Http\Request;
use App\Enums\GlobalConstant;
use App\Traits\CustomerTrait;
use App\Services\OrderService;
use App\Events\OrderStatusEvent;
use App\Models\ReferralCustomer;
use App\Traits\FileManagerTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\View\View;
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
use Illuminate\Support\Facades\View as PdfView;
use App\Repositories\OrderTransactionRepository;
use App\Repositories\WalletTransactionRepository;
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

class OrderEditController extends BaseController
{
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
        private readonly ColorRepositoryInterface                        $colorRepo,
        private readonly VendorRepositoryInterface                       $vendorRepo,
        private readonly BusinessSettingRepositoryInterface              $businessSettingRepo,
        private readonly DeliveryCountryCodeRepositoryInterface          $deliveryCountryCodeRepo,
        private readonly DeliveryZipCodeRepositoryInterface              $deliveryZipCodeRepo,
        private readonly DeliveryManRepository                           $deliveryManRepo,
        private readonly ShippingAddressRepositoryInterface              $shippingAddressRepo,
        private readonly OrderExpectedDeliveryHistoryRepositoryInterface $orderExpectedDeliveryHistoryRepo,
        private readonly OrderDetailRepositoryInterface                  $orderDetailRepo,
        private readonly WalletTransactionRepository                     $walletTransactionRepo,
        private readonly DeliveryManWalletRepositoryInterface            $deliveryManWalletRepo,
        private readonly ProductRepositoryInterface                      $productRepo,
        private readonly CartService                                     $cartService,
        private readonly ProductService                                  $productService,
        private readonly OrderEditService                                $orderEditService,
        private readonly PublishingHouseRepositoryInterface              $publishingHouseRepo,
        private readonly DeliveryManTransactionRepositoryInterface       $deliveryManTransactionRepo,
        private readonly OrderStatusHistoryRepositoryInterface           $orderStatusHistoryRepo,
        private readonly OrderTransactionRepository                      $orderTransactionRepo,
        private readonly LoyaltyPointTransactionRepositoryInterface      $loyaltyPointTransactionRepo,
        private readonly OrderDetailsRewardsRepositoryInterface          $orderDetailsRewardsRepo,

    )
    {
    }

    public function index(?Request $request, ?string $type = null): View|Collection|LengthAwarePaginator|null|callable|RedirectResponse|JsonResponse
    {
        // TODO: Implement index() method.
    }

    /**
     * Fetch an order that belongs to the authenticated seller, or abort 404.
     *
     * Every edit-order action keys off a request order_id. Without this scope one seller could load,
     * price, add/remove products on, and regenerate another seller's order simply by passing its id.
     * Vendor orders carry seller_is='seller' and the vendor's own seller_id, so that pair is the
     * ownership predicate — the same one the rest of the vendor order controller already uses.
     */
    private function sellerOwnedOrderOrFail(int|string|null $orderId, array $relations = [])
    {
        $order = $this->orderRepo->getFirstWhere(
            params: ['id' => $orderId, 'seller_id' => auth('seller')->id(), 'seller_is' => 'seller'],
            relations: $relations,
        );
        abort_if(!$order, 404);

        return $order;
    }

    public function getSearchEditOrderProductsView(Request $request): JsonResponse
    {
        $orderId = $request['order_id'] ?? null;
        $searchValue = $request['searchValue'] ?? null;

        $order = $this->sellerOwnedOrderOrFail($request['order_id']);

        $filters = [
            'added_by' => $order['seller_is'] == 'admin' ? 'in_house' : $order['seller_is'],
            'seller_id' => $order['seller_is'] == 'admin' ? '' : $order['seller_id'],
        ];

        $products = $this->productRepo->getListWithScope(
            orderBy: ['id' => 'desc'],
            searchValue: $searchValue,
            scope: "active",
            filters: $filters,
            relations: ['brand', 'category', 'seller.shop'],
            dataLimit: 'all');

        return response()->json([
            'count' => $products->count(),
            'result' => view('vendor-views.order.partials._search-product', compact('products', 'orderId'))->render(),
        ]);
    }

    public function getEditOrderProductModalView(Request $request): JsonResponse
    {
        $productSubtotal = $cartItems['productSubtotal'] ?? 0;
        $order = $this->sellerOwnedOrderOrFail($request['order_id']);
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
        $currentVariation = $this->orderEditService->getProductCurrentStockInfo(request: $request, order: $order, product: $product);
        $variantRequest = $request->all();
        $variantRequest['quantity'] = $currentVariation['current_quantity'] ?? 1;

        return response()->json([
            'htmlView' => view('vendor-views.order.partials._quick-view', [
                'orderId' => $request['order_id'],
                'product' => $product,
                'digitalProductAuthors' => $digitalProductAuthors,
                'productAuthorIds' => $productAuthorIds,
                'publishingHouseRepo' => $publishingHouseRepo,
                'productPublishingHouseIds' => $productPublishingHouseIds,
                'productSubtotal' => $productSubtotal,
                'variantRequest' => $variantRequest,
                'currentVariation' => $currentVariation,
            ])->render(),
        ]);
    }

    public function checkProductVariantPrice(Request $request): JsonResponse
    {
        $variantRequest = $request->all();
        $order = $this->sellerOwnedOrderOrFail($request['order_id']);
        $product = $this->productRepo->getFirstWhereWithCount(
            params: ['id' => $request['product_id']],
            withCount: ['reviews'],
            relations: ['brand', 'category', 'rating', 'tags', 'digitalVariation', 'clearanceSale' => function ($query) {
                return $query->active();
            }],
        );

        $colorName = $this->colorRepo->getFirstWhere(['code' => $request['color']])->name ?? null;

        $productSubtotal = $cartItems['productSubtotal'] ?? 0;
        $productAuthorIds = $this->productService->getProductAuthorsInfo(product: $product)['ids'];
        $digitalProductAuthors = $this->authorRepo->getListWhere(dataLimit: 'all');
        $productPublishingHouseIds = $this->productService->getProductPublishingHouseInfo(product: $product)['ids'];
        $currentVariation = $this->orderEditService->getProductCurrentStockInfo(
            request: $request,
            order: $order,
            product: $product,
            colorName: $colorName
        );
        $publishingHouseRepo = $this->publishingHouseRepo->getListWhere(dataLimit: 'all');

        $orderSession = $this->orderEditService->getOrderEditSession(order: $order);
        $editOrderSummary = $this->generateEditOrderSummary(request: $request, order: $order, editedOrder: ($orderSession['product_list'] ?? []), data: [
            'edit_by' => 'seller',
            'edited_user_id' => auth()->guard('seller')->id(),
            'edited_user_name' => auth()->guard('seller')->user()->f_name .' '. auth()->guard('seller')->user()->l_name,
        ]);

        return response()->json([
            'currentVariation' => $currentVariation,
            'product_quick_view_details' => view('vendor-views.order.partials._quick-view-details', [
                'orderId' => $request['order_id'],
                'product' => $product,
                'digitalProductAuthors' => $digitalProductAuthors,
                'productAuthorIds' => $productAuthorIds,
                'publishingHouseRepo' => $publishingHouseRepo,
                'productPublishingHouseIds' => $productPublishingHouseIds,
                'productSubtotal' => $productSubtotal,
                'variantRequest' => $variantRequest,
                'currentVariation' => $currentVariation,
            ])->render(),
            'product_list_view' => view("vendor-views.order.partials.offcanvas._edit-order-products-list", [
                'order' => $order,
                'orderProductsSession' => $orderSession,
            ])->render(),
            'edit_order_total_amount' => setCurrencySymbol(amount: usdToDefaultCurrency(amount: $editOrderSummary['order_amount']), currencyCode: getCurrencyCode()),
        ]);
    }

    public function addEditOrderProduct(Request $request): JsonResponse
    {
        $order = $this->sellerOwnedOrderOrFail($request['order_id']);
        $color = $this->colorRepo->getFirstWhere(params: ['code' => $request['color']]);
        $result = $this->orderEditService->addOrUpdateProductInOrderSession(request: $request, order: $order, color: $color);

        $orderSession = $this->orderEditService->getOrderEditSession(order: $order);
        $editOrderSummary = $this->generateEditOrderSummary(request: $request, order: $order, editedOrder: ($orderSession['product_list'] ?? []), data: [
            'edit_by' => 'seller',
            'edited_user_id' => auth()->guard('seller')->id(),
            'edited_user_name' => auth()->guard('seller')->user()->f_name .' '. auth()->guard('seller')->user()->l_name,
        ]);

        return response()->json([
            'status' => $result['status'],
            'message' => $result['message'],
            'product_list_view' => view("vendor-views.order.partials.offcanvas._edit-order-products-list", [
                'order' => $order,
                'orderProductsSession' => $orderSession,
            ])->render(),
            'edit_order_total_amount' => setCurrencySymbol(amount: usdToDefaultCurrency(amount: $editOrderSummary['order_amount']), currencyCode: getCurrencyCode()),
        ]);
    }

    public function removeEditOrderProduct(Request $request): JsonResponse
    {
        $order = $this->sellerOwnedOrderOrFail($request['order_id']);
        $result = $this->orderEditService->removeProductInOrderSession(request: $request, order: $order);

        $orderSession = $this->orderEditService->getOrderEditSession(order: $order);
        $editOrderSummary = $this->generateEditOrderSummary(request: $request, order: $order, editedOrder: ($orderSession['product_list'] ?? []), data: [
            'edit_by' => 'seller',
            'edited_user_id' => auth()->guard('seller')->id(),
            'edited_user_name' => auth()->guard('seller')->user()->f_name .' '. auth()->guard('seller')->user()->l_name,
        ]);

        return response()->json([
            'status' => $result['status'],
            'message' => $result['message'],
            'product_list_view' => view("vendor-views.order.partials.offcanvas._edit-order-products-list", [
                'order' => $order,
                'orderProductsSession' => $orderSession,
            ])->render(),
            'edit_order_total_amount' => setCurrencySymbol(amount: usdToDefaultCurrency(amount: $editOrderSummary['order_amount']), currencyCode: getCurrencyCode()),
        ]);
    }

    public function updateEditOrderProductList(Request $request): JsonResponse
    {
        $order = $this->sellerOwnedOrderOrFail($request['order_id']);
        $result = $this->orderEditService->updateProductListInOrderSession(request: $request, order: $order);

        $orderSession = $this->orderEditService->getOrderEditSession(order: $order);
        $editOrderSummary = $this->generateEditOrderSummary(request: $request, order: $order, editedOrder: ($orderSession['product_list'] ?? []), data: [
            'edit_by' => 'seller',
            'edited_user_id' => auth()->guard('seller')->id(),
            'edited_user_name' => auth()->guard('seller')->user()->f_name .' '. auth()->guard('seller')->user()->l_name,
        ]);

        return response()->json([
            'status' => $result['status'],
            'message' => $result['message'],
            'product_list_view' => view("vendor-views.order.partials.offcanvas._edit-order-products-list", [
                'order' => $order,
                'orderProductsSession' => $orderSession,
            ])->render(),
            'edit_order_total_amount' => setCurrencySymbol(amount: usdToDefaultCurrency(amount: $editOrderSummary['order_amount']), currencyCode: getCurrencyCode()),
        ]);
    }

    public function generateEditOrderByProductList(Request $request): JsonResponse
    {
        $order = $this->sellerOwnedOrderOrFail($request['order_id'], relations: [
            'details.productAllStatus', 'verificationImages', 'shipping', 'seller.shop', 'offlinePayments', 'deliveryMan'
        ]);
        $orderSession = $this->orderEditService->getOrderEditSession(order: $order);

        $data = [
            'edit_by' => 'seller',
            'edited_user_id' => auth()->guard('seller')->id(),
            'edited_user_name' => auth()->guard('seller')->user()->f_name .' '. auth()->guard('seller')->user()->l_name,
            'order_request_from' => 'panel'
        ];
        $result = $this->generateEditOrder(request: $request, order: $order, editedOrder: ($orderSession['product_list'] ?? []), data: $data);

        return response()->json([
            'status' => $result['status'],
            'message' => $result['message'],
            'redirect_url' => route('vendor.orders.details', ['id' => $order['id']]),
        ]);
    }
}
