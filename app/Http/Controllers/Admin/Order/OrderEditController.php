<?php

namespace App\Http\Controllers\Admin\Order;

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

    public function getSearchEditOrderProductsView(Request $request): JsonResponse
    {
        $orderId = $request['order_id'] ?? null;
        $searchValue = $request['searchValue'] ?? null;

        $order = $this->orderRepo->getFirstWhere(params: ['id' => $request['order_id']]);

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

        $products->map(function($product) use ($order) {
            $currentStock = max(0, $product->current_stock);
            $checkDetailsQty = collect($order?->details)?->where('product_id', $product['id'])?->sum('qty') ?? 0;
            if ($checkDetailsQty > 0) {
                $currentStock += $checkDetailsQty;
            }
            $product->current_stock = $currentStock;
             return $product;
        });

        return response()->json([
            'count' => $products->count(),
            'result' => view('admin-views.order.partials._search-product', compact('products', 'orderId'))->render(),
        ]);
    }

    public function getEditOrderProductModalView(Request $request): JsonResponse
    {
        $productSubtotal = $cartItems['productSubtotal'] ?? 0;
        $order = $this->orderRepo->getFirstWhere(params: ['id' => $request['order_id']]);
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
            'htmlView' => view('admin-views.order.partials._quick-view', [
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
        $order = $this->orderRepo->getFirstWhere(params: ['id' => $request['order_id']]);
        $product = $this->productRepo->getFirstWhereWithCount(
            params: ['id' => $request['product_id']],
            withCount: ['reviews'],
            relations: ['brand', 'category', 'rating', 'tags', 'digitalVariation', 'clearanceSale' => function ($query) {
                return $query->active();
            }],
        );

        $colorName = $this->colorRepo->getFirstWhere(['code' => $request['color']])?->name ?? null;

        $productSubtotal = $cartItems['productSubtotal'] ?? 0;
        $productAuthorIds = $this->productService->getProductAuthorsInfo(product: $product)['ids'];
        $digitalProductAuthors = $this->authorRepo->getListWhere(dataLimit: 'all');
        $productPublishingHouseIds = $this->productService->getProductPublishingHouseInfo(product: $product)['ids'];
        $publishingHouseRepo = $this->publishingHouseRepo->getListWhere(dataLimit: 'all');
        $currentVariation = $this->orderEditService->getProductCurrentStockInfo(
            request: $request,
            order: $order,
            product: $product,
            colorName: $colorName
        );

        $orderSession = $this->orderEditService->getOrderEditSession(order: $order);
        $editOrderSummary = $this->generateEditOrderSummary(request: $request, order: $order, editedOrder: ($orderSession['product_list'] ?? []), data: [
            'edit_by' => 'admin',
            'edited_user_id' => auth()->guard('admin')->id(),
            'edited_user_name' => auth()->guard('admin')->user()->name,
        ]);

        return response()->json([
            'currentVariation' => $currentVariation,
            'product_quick_view_details' => view('admin-views.order.partials._quick-view-details', [
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
            'product_list_view' => view("admin-views.order.partials.offcanvas._edit-order-products-list", [
                'order' => $order,
                'orderProductsSession' => $orderSession,
            ])->render(),
            'edit_order_total_amount' => setCurrencySymbol(amount: usdToDefaultCurrency(amount: $editOrderSummary['order_amount']), currencyCode: getCurrencyCode()),
        ]);
    }

    public function addEditOrderProduct(Request $request): JsonResponse
    {
        $order = $this->orderRepo->getFirstWhere(params: ['id' => $request['order_id']]);
        $color = $this->colorRepo->getFirstWhere(params: ['code' => $request['color']]);
        $result = $this->orderEditService->addOrUpdateProductInOrderSession(request: $request, order: $order, color: $color);

        $orderSession = $this->orderEditService->getOrderEditSession(order: $order);
        $editOrderSummary = $this->generateEditOrderSummary(request: $request, order: $order, editedOrder: ($orderSession['product_list'] ?? []), data: [
            'edit_by' => 'admin',
            'edited_user_id' => auth()->guard('admin')->id(),
            'edited_user_name' => auth()->guard('admin')->user()->name,
        ]);

        return response()->json([
            'status' => $result['status'],
            'message' => $result['message'],
            'submit_button_text' => translate("Update_to_cart"),
            'product_list_view' => view("admin-views.order.partials.offcanvas._edit-order-products-list", [
                'order' => $order,
                'orderProductsSession' => $orderSession,
            ])->render(),
            'edit_order_total_amount' => setCurrencySymbol(amount: usdToDefaultCurrency(amount: $editOrderSummary['order_amount']), currencyCode: getCurrencyCode()),
        ]);
    }

    public function removeEditOrderProduct(Request $request): JsonResponse
    {
        $order = $this->orderRepo->getFirstWhere(params: ['id' => $request['order_id']]);
        $result = $this->orderEditService->removeProductInOrderSession(request: $request, order: $order);

        $orderSession = $this->orderEditService->getOrderEditSession(order: $order);
        $editOrderSummary = $this->generateEditOrderSummary(request: $request, order: $order, editedOrder: ($orderSession['product_list'] ?? []), data: [
            'edit_by' => 'admin',
            'edited_user_id' => auth()->guard('admin')->id(),
            'edited_user_name' => auth()->guard('admin')->user()->name,
        ]);

        return response()->json([
            'status' => $result['status'],
            'message' => $result['message'],
            'product_list_view' => view("admin-views.order.partials.offcanvas._edit-order-products-list", [
                'order' => $order,
                'orderProductsSession' => $orderSession,
            ])->render(),
            'edit_order_total_amount' => setCurrencySymbol(amount: usdToDefaultCurrency(amount: $editOrderSummary['order_amount']), currencyCode: getCurrencyCode()),
        ]);
    }

    public function updateEditOrderProductList(Request $request): JsonResponse
    {
        $order = $this->orderRepo->getFirstWhere(params: ['id' => $request['order_id']], relations:['details']);
        $result = $this->orderEditService->updateProductListInOrderSession(request: $request, order: $order);

        $orderSession = $this->orderEditService->getOrderEditSession(order: $order);
        $editOrderSummary = $this->generateEditOrderSummary(request: $request, order: $order, editedOrder: ($orderSession['product_list'] ?? []), data: [
            'edit_by' => 'admin',
            'edited_user_id' => auth()->guard('admin')->id(),
            'edited_user_name' => auth()->guard('admin')->user()->name,
        ]);

        return response()->json([
            'status' => $result['status'],
            'message' => $result['message'],
            'product_list_view' => view("admin-views.order.partials.offcanvas._edit-order-products-list", [
                'order' => $order,
                'orderProductsSession' => $orderSession,
            ])->render(),
            'edit_order_total_amount' => setCurrencySymbol(amount: usdToDefaultCurrency(amount: $editOrderSummary['order_amount']), currencyCode: getCurrencyCode()),
        ]);
    }


    public function generateEditOrderByProductList(Request $request): JsonResponse
    {
        $order = $this->orderRepo->getFirstWhere(params: ['id' => $request['order_id']], relations: [
            'details.productAllStatus', 'verificationImages', 'shipping', 'seller.shop', 'offlinePayments', 'deliveryMan'
        ]);

        $orderSession = $this->orderEditService->getOrderEditSession(order: $order);
        $result = $this->generateEditOrder(request: $request, order: $order, editedOrder: ($orderSession['product_list'] ?? []), data: [
            'edit_by' => 'admin',
            'edited_user_id' => auth()->guard('admin')->id(),
            'edited_user_name' => auth()->guard('admin')->user()->name,
            'order_request_from' => 'panel'
        ]);

        return response()->json([
            'status' => $result['status'],
            'message' => $result['message'],
            'redirect_url' => route('admin.orders.details', ['id' => $order['id']]),
        ]);
    }
}
