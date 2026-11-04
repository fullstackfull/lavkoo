<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Contracts\Repositories\AuthorRepositoryInterface;
use App\Contracts\Repositories\BusinessSettingRepositoryInterface;
use App\Contracts\Repositories\ColorRepositoryInterface;
use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Contracts\Repositories\DeliveryCountryCodeRepositoryInterface;
use App\Contracts\Repositories\DeliveryManTransactionRepositoryInterface;
use App\Contracts\Repositories\DeliveryManWalletRepositoryInterface;
use App\Contracts\Repositories\DeliveryZipCodeRepositoryInterface;
use App\Contracts\Repositories\LoyaltyPointTransactionRepositoryInterface;
use App\Contracts\Repositories\OrderDetailRepositoryInterface;
use App\Contracts\Repositories\OrderDetailsRewardsRepositoryInterface;
use App\Contracts\Repositories\OrderEditHistoryRepositoryInterface;
use App\Contracts\Repositories\OrderExpectedDeliveryHistoryRepositoryInterface;
use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Contracts\Repositories\OrderStatusHistoryRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\PublishingHouseRepositoryInterface;
use App\Contracts\Repositories\ShippingAddressRepositoryInterface;
use App\Contracts\Repositories\VendorRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Models\OrderEditHistory;
use App\Repositories\DeliveryManRepository;
use App\Repositories\OrderTransactionRepository;
use App\Repositories\WalletTransactionRepository;
use App\Services\CartService;
use App\Services\OrderEditService;
use App\Services\ProductService;
use App\Traits\OrderEditManager;
use App\Utils\Convert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderEditController extends Controller
{
    use OrderEditManager;

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
        private readonly OrderEditHistoryRepositoryInterface             $orderEditHistoryRepo,
    )
    {
    }

    public function checkEditOrderValidation(Request $request): JsonResponse
    {
        $seller = $request->seller;
        $order = $this->orderRepo->getFirstWhere(params: ['id' => $request['order_id']], relations: [
            'details.productAllStatus', 'verificationImages', 'shipping', 'seller.shop', 'offlinePayments', 'deliveryMan'
        ]);

        $colors = $this->colorRepo->getListWhereIn(whereIn: ['code' => (collect($request['products'])?->pluck('color')?->toArray() ?? [])]);
        $orderCombination = $this->orderEditService->getFormatAPIProductsForEditOrder(request: $request, order: $order, colors: $colors);
        $editOrderSummary = $this->generateEditOrderSummary(request: $request, order: $order, editedOrder: ($orderCombination['product_list'] ?? []), data: [
            'edit_by' => 'seller',
            'edited_user_id' => $seller['id'],
            'edited_user_name' => $seller['f_name'] . ' ' . $seller['l_name'],
        ]);

        return response()->json($editOrderSummary);
    }

    public function submitEditOrder(Request $request): JsonResponse
    {
        $seller = $request->seller;
        $order = $this->orderRepo->getFirstWhere(params: ['id' => $request['order_id']], relations: [
            'details.productAllStatus', 'verificationImages', 'shipping', 'seller.shop', 'offlinePayments', 'deliveryMan'
        ]);

        $colors = $this->colorRepo->getListWhereIn(whereIn: ['code' => (collect($request['products'])?->pluck('color')?->toArray() ?? [])]);

        $orderCombination = $this->orderEditService->getFormatAPIProductsForEditOrder(request: $request, order: $order, colors: $colors);

        if ($orderCombination['status'] == 'success') {
            $result = $this->generateEditOrder(request: $request, order: $order, editedOrder: ($orderCombination['product_list'] ?? []), data: [
                'edit_by' => 'seller',
                'edited_user_id' => $seller['id'],
                'edited_user_name' => $seller['f_name'] . ' ' . $seller['l_name'],
                'order_request_from' => 'app'
            ]);

            return response()->json([
                'status' => $result['status'],
                'message' => $result['message'],
                'request' => $request->all(),
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => translate('Order_Edit_Failed'),
            'errors' => $orderCombination['errors'],
            'request' => $request->all(),
        ], 403);
    }

    public function assignOrderInCOD(Request $request): JsonResponse
    {
        OrderEditHistory::where('order_id', $request['order_id'])->latest()->update([
            'order_due_payment_method' => "cash_on_delivery"
        ]);
        return response()->json([
            'status' => 'success',
            'message' => translate('Switched_to_COD_successfully'),
        ]);
    }

}
