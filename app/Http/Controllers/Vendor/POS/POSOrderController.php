<?php

namespace App\Http\Controllers\Vendor\POS;

use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Contracts\Repositories\DigitalProductVariationRepositoryInterface;
use App\Contracts\Repositories\OrderDetailRepositoryInterface;
use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\StorageRepositoryInterface;
use App\Contracts\Repositories\VendorRepositoryInterface;
use App\Enums\SessionKey;
use App\Enums\ViewPaths\Vendor\POSOrder;
use App\Events\DigitalProductDownloadEvent;
use App\Http\Controllers\BaseController;
use App\Services\CartService;
use App\Services\OrderDetailsService;
use App\Services\OrderService;
use App\Services\POSService;
use App\Traits\CalculatorTrait;
use App\Traits\CustomerTrait;
use App\Utils\CartManager;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Modules\TaxModule\app\Traits\VatTaxManagement;

class POSOrderController extends BaseController
{
    use CustomerTrait;
    use CalculatorTrait;
    use VatTaxManagement;


    /**
     * @param ProductRepositoryInterface $productRepo
     * @param CustomerRepositoryInterface $customerRepo
     * @param OrderRepositoryInterface $orderRepo
     * @param OrderDetailRepositoryInterface $orderDetailRepo
     * @param VendorRepositoryInterface $vendorRepo
     * @param DigitalProductVariationRepositoryInterface $digitalProductVariationRepo
     * @param StorageRepositoryInterface $storageRepo
     * @param POSService $POSService
     * @param CartService $cartService
     * @param OrderDetailsService $orderDetailsService
     * @param OrderService $orderService
     */
    public function __construct(
        private readonly ProductRepositoryInterface                 $productRepo,
        private readonly CustomerRepositoryInterface                $customerRepo,
        private readonly OrderRepositoryInterface                   $orderRepo,
        private readonly OrderDetailRepositoryInterface             $orderDetailRepo,
        private readonly VendorRepositoryInterface                  $vendorRepo,
        private readonly DigitalProductVariationRepositoryInterface $digitalProductVariationRepo,
        private readonly StorageRepositoryInterface                 $storageRepo,
        private readonly POSService                                 $POSService,
        private readonly CartService                                $cartService,
        private readonly OrderDetailsService                        $orderDetailsService,
        private readonly OrderService                               $orderService,
    )
    {
    }

    /**
     * @param Request|null $request
     * @param string|null $type
     * @return View|Collection|LengthAwarePaginator|callable|RedirectResponse|null
     * @throws Exception
     */
    public function index(?Request $request, ?string $type = null): View|Collection|LengthAwarePaginator|null|callable|RedirectResponse
    {
        return $this->getOrderDetails(id: $type);
    }

    /**
     * @param string $id
     * @return View|RedirectResponse
     * @throws Exception
     */
    public function getOrderDetails(string $id): View|RedirectResponse
    {
        $vendorId = auth('seller')->id();
        $vendor = $this->vendorRepo->getFirstWhere(params: ['id' => $vendorId]);
        $getPOSStatus = getWebConfig('seller_pos');
        if ($vendor['pos_status'] == 0 || $getPOSStatus == 0) {
            ToastMagic::warning(translate('access_denied!!'));
            return redirect()->back();
        }
        $order = $this->orderRepo->getFirstWhere(params: ['id' => $id], relations: ['details', 'shipping', 'seller']);
        return view(POSOrder::ORDER_DETAILS[VIEW], compact('order'));
    }

    /**
     * @param Request $request
     * @return JsonResponse
     * @throws Exception
     */
    public function placeOrder(Request $request): JsonResponse
    {
        $amount = $request['amount'];
        $fulfillment = $request['fulfillment'] == 'delivery' ? 'delivery' : 'instant';
        // Cash for a delivery order is collected on delivery, so the tendered
        // amount is neither required nor recorded here — same as a web COD order.
        $paidAmount = ($request['type'] == 'cash' && $fulfillment == 'instant') ? ($request['paid_amount'] ?? 0) : null;
        $cartId = session(SessionKey::CURRENT_USER);
        $condition = $this->POSService->checkConditions(amount: $amount, paidAmount: $paidAmount);
        if ($condition == 'true') {
            return response()->json();
        }
        $userId = $this->cartService->getUserId();
        if ($userId == 0 && $fulfillment == 'delivery') {
            return response()->json(['checkProductTypeForWalkingCustomer' => true, 'message' => translate('To_place_a_delivery_order') . ',' . translate('_kindly_select_a_customer_or_fill_up_the_“Add_New_Customer”_form') . '.']);
        }
        if ($request['type'] == 'wallet' && $userId != 0) {
            $customerBalance = $this->customerRepo->getFirstWhere(params: ['id' => $userId]) ?? 0;
            if ($customerBalance['wallet_balance'] >= currencyConverter(amount: $amount)) {
                $this->createWalletTransaction(user_id: $userId, amount: floatval($amount), transaction_type: 'order_place', reference: 'order_place_in_pos');
            } else {
                ToastMagic::error(translate('need_Sufficient_Amount_Balance'));
                return response()->json();
            }
        }
        $checkProductTypeDigital = $this->cartService->checkProductTypeDigital(cartId: $cartId);
        if ($userId == 0 && $checkProductTypeDigital) {
            return response()->json(['checkProductTypeForWalkingCustomer' => true, 'message' => translate('To_order_digital_product') . ',' . translate('_kindly_fill_up_the_“Add_New_Customer”_form') . '.']);
        }

        $taxConfig = self::getTaxSystemType();
        $cart = session($cartId);

        $cartListSession = $this->cartService->filterSessionCartList(cart: session($cartId));

        if (count($cartListSession) > 0) {
            $products = $this->productRepo->getListWithScope(whereIn: ['id' => $cartListSession->pluck('id')->toArray()], relations: ['clearanceSale' => function ($query) {
                return $query->active();
            }], dataLimit: 'all');
            $totalDiscountedPrice = $this->cartService->getCartTotalDiscountPrice(session($cartId), $products, $userId);

            $orderId = 100000 + $this->orderRepo->getList()->count() + 1;
            $order = $this->orderRepo->getFirstWhere(params: ['id' => $orderId]);
            if ($order) {
                $orderId = $this->orderRepo->getList(orderBy: ['id' => 'DESC'])->first()->id + 1;
            }
            $totalTaxAmount = 0;
            foreach ($cartListSession as $item) {
                if (is_array($item)) {
                    $product = $products->firstWhere('id', $item['id']);
                    if ($product) {
                        $price = $item['price'];
                        $digitalProductVariation = $this->digitalProductVariationRepo->getFirstWhere(params: ['product_id' => $item['id'], 'variant_key' => $item['variant']], relations: ['storage']);
                        if ($product['product_type'] == 'digital' && $digitalProductVariation) {
                            $price = $digitalProductVariation['price'];

                            if ($product['digital_product_type'] == 'ready_product') {
                                $getStoragePath = $this->storageRepo->getFirstWhere(params: [
                                    'data_id' => $digitalProductVariation['id'],
                                    "data_type" => "App\Models\DigitalProductVariation",
                                ]);
                                $product['digital_file_ready'] = $digitalProductVariation['file'];
                                $product['storage_path'] = $getStoragePath ? $getStoragePath['value'] : 'public';
                            }
                        } elseif ($product['digital_product_type'] == 'ready_product' && !empty($product['digital_file_ready'])) {
                            $product['storage_path'] = $product['digital_file_ready_storage_type'] ?? 'public';
                        }

                        $cartSubTotalCalculation = $this->cartService->getCartSubtotalCalculation(
                            product: $product,
                            cartItem: $item,
                            totalDiscountedPrice: $totalDiscountedPrice,
                            cartName: $cartId,
                        );

                        $orderDetail = $this->orderDetailsService->getPOSOrderDetailsData(
                            orderId: $orderId, item: $item,
                            product: $product, price: $price, tax: $cartSubTotalCalculation['appliedTaxAmount'],
                            fulfillment: $fulfillment, paymentType: $request['type']
                        );
                        $totalTaxAmount += $cartSubTotalCalculation['appliedTaxAmount'];
                        if ($item['variant'] != null) {
                            $type = $item['variant'];
                            $variantStore = [];
                            foreach (json_decode($product['variation'], true) as $variant) {
                                if ($type == $variant['type']) {
                                    // Floor at zero: a single variant can be short even when the product
                                    // total passes, so this must not persist a negative variant qty.
                                    $variant['qty'] = max(0, $variant['qty'] - $item['quantity']);
                                }
                                $variantStore[] = $variant;
                            }
                            $this->productRepo->update(id: $product['id'], data: ['variation' => json_encode($variantStore)]);
                        }

                        if ($product['product_type'] == 'physical') {
                            // Atomic conditional decrement, floored at zero. The old non-atomic write
                            // (read current_stock, subtract, write) lost concurrent updates and could
                            // drive stock negative (two POS terminals selling the last unit both wrote 0
                            // after selling). decrement issues SET current_stock = current_stock - N so
                            // it never loses an update; the >= guard stops it going below zero, and the
                            // floor covers an in-hand sale the tracked stock could not cover.
                            $taken = \App\Models\Product::where('id', $product['id'])
                                ->where('current_stock', '>=', $item['quantity'])
                                ->decrement('current_stock', $item['quantity']);
                            if ($taken === 0) {
                                \App\Models\Product::where('id', $product['id'])->update(['current_stock' => 0]);
                            }
                            // Log the POS sale into the stock-movement history (non-throwing).
                            try {
                                app(\App\Services\Marketplace\InventoryService::class)->record(
                                    productId: $product['id'],
                                    type: \App\Models\StockMovement::TYPE_SALE,
                                    qtyChange: -(int) $item['quantity'],
                                    balanceAfter: (int) (\App\Models\Product::where('id', $product['id'])->value('current_stock') ?? 0),
                                    referenceType: 'pos_order',
                                    referenceId: $orderId,
                                    sellerId: $product['seller_id'] ?? null,
                                );
                            } catch (\Throwable $exception) {
                                \Illuminate\Support\Facades\Log::warning('POS sale stock-movement log failed for product ' . $product['id'] . ': ' . $exception->getMessage());
                            }
                        }
                        $this->orderDetailRepo->add(data: $orderDetail);

                        $appliedTaxIds = CartManager::getAppliedTaxIds(
                            product: $product,
                            taxConfig: $taxConfig
                        );

                        $appliedTaxRate = collect($appliedTaxIds)->sum('tax_rate');
                        $finalAmount = ($item['price'] - $item['discount']) * $item['quantity'];

                        foreach ($appliedTaxIds as $taxItem) {
                            \App\Utils\OrderManager::getAddOrderTaxDetails(
                                systemTaxVat: $taxConfig['SystemTaxVat'],
                                taxRate: $taxItem,
                                orderId: $orderId,
                                data: [
                                    'tax_amount' => ($cartSubTotalCalculation['appliedTaxAmount'] > 0 && $appliedTaxRate > 0) ? ($cartSubTotalCalculation['appliedTaxAmount'] * $taxItem['tax_rate']) / $appliedTaxRate : 0,
                                    'before_tax_amount' => $finalAmount,
                                    'after_tax_amount' => $finalAmount + $cartSubTotalCalculation['appliedTaxAmount'],
                                    'quantity' => $item['quantity'],
                                    'seller_id' => $product['seller_id'],
                                    'seller_type' => $product['seller_is'],
                                ]
                            );
                        }
                    }
                }
            }

            $order = $this->orderService->getPOSOrderData(
                orderId: $orderId,
                cart: $cart,
                amount: $amount,
                totalTaxAmount: $totalTaxAmount,
                paidAmount: $paidAmount ?? ($request['type'] == 'cash' ? 0 : $amount),
                paymentType: $request['type'],
                addedBy: 'seller',
                userId: $userId,
                fulfillment: $fulfillment
            );
            $this->orderRepo->add(data: $order);
            if ($checkProductTypeDigital) {
                $order = $this->orderRepo->getFirstWhere(params: ['id' => $orderId], relations: ['details.productAllStatus']);
                $data = [
                    'userName' => $order->customer->f_name,
                    'userType' => 'customer',
                    'templateName' => 'digital-product-download',
                    'order' => $order,
                    'subject' => translate('download_Digital_Product'),
                    'title' => translate('Congratulations') . '!',
                    'emailId' => $order->customer['email'],

                ];
                event(new DigitalProductDownloadEvent(email: $order->customer['email'], data: $data));
            }
            session()->forget($cartId);
            session(['last_order' => $orderId]);
            $this->cartService->getNewCartId();
            ToastMagic::success(translate('order_placed_successfully'));
        }

        return response()->json();
    }

    public function cancelOrder(Request $request): JsonResponse
    {
        session()->remove($request['cart_id']);
        $totalHoldOrders = $this->POSService->getTotalHoldOrders();
        $cartNames = $this->POSService->getCartNames();
        $cartItems = $this->getHoldOrderCalculationData(cartNames: $cartNames);
        return response()->json([
            'message' => $request['cart_id'] . ' ' . translate('order_is_cancel'),
            'status' => 'success',
            'view' => view(POSOrder::CANCEL_ORDER[VIEW], compact('cartItems', 'totalHoldOrders'))->render(),
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     * @throws \Throwable
     */
    public function getAllHoldOrdersView(Request $request): JsonResponse
    {
        $cartNames = $this->POSService->getCartNames();
        $cartItems = $this->getHoldOrderCalculationData(cartNames: $cartNames);
        $totalHoldOrders = $this->POSService->getTotalHoldOrders();
        if (!empty($request['customer'])) {
            $searchValue = strtolower($request['customer']);
            $filteredItems = collect($cartItems)->filter(function ($item) use ($searchValue) {
                return str_contains(strtolower($item['customerName']), $searchValue) !== false;
            });
            $cartItems = $filteredItems->all();
        }
        return response()->json([
            'flag' => 'inactive',
            'totalHoldOrders' => $totalHoldOrders,
            'view' => view(POSOrder::HOLD_ORDERS[VIEW], compact('totalHoldOrders', 'cartItems'))->render(),
        ]);
    }

    /**
     * @param array $cartNames
     * @return array
     */
    protected function getHoldOrderCalculationData(array $cartNames): array
    {
        $CustomerCartData = [];
        foreach ($cartNames as $cartId) {
            $CustomerData = $this->getCustomerCartData(cartName: $cartId);
            $CartItemData = $this->calculateCartItemsData(cartName: $cartId, customerCartData: $CustomerData);
            $CustomerCartData[$cartId] = array_merge($CustomerData[$cartId], $CartItemData);
        }
        return $CustomerCartData;
    }

    /**
     * @param string $cartName
     * @return array
     */
    protected function getCustomerCartData(string $cartName): array
    {
        $customerCartData = [];
        if (Str::contains($cartName, 'walk-in-customer')) {
            $currentCustomerInfo = [
                'customerName' => 'Walk-In Customer',
                'customerPhone' => "",
            ];
            $customerId = 0;
        } else {
            $customerId = explode('-', $cartName)[2];
            $currentCustomerData = $this->customerRepo->getFirstWhere(params: ['id' => $customerId]);
            $currentCustomerInfo = $this->cartService->getCustomerInfo(currentCustomerData: $currentCustomerData, customerId: $customerId);
        }
        $customerCartData[$cartName] = [
            'customerName' => $currentCustomerInfo['customerName'],
            'customerPhone' => $currentCustomerInfo['customerPhone'],
            'customerId' => $customerId,
        ];
        return $customerCartData;
    }

    protected function calculateCartItemsData(string $cartName, array $customerCartData): array
    {
        $cartItemValue = [];
        $subTotalCalculation = [
            'countItem' => 0,
            'totalQuantity' => 0,
            'totalTax' => 0,
            'subtotal' => 0,
            'discountOnProduct' => 0,
            'productSubtotal' => 0,
        ];

        $cartListSession = $this->cartService->filterSessionCartList(cart: session($cartName));

        if (count($cartListSession) > 0) {
            $products = $this->productRepo->getListWithScope(whereIn: ['id' => $cartListSession->pluck('id')->toArray()], relations: ['clearanceSale' => function ($query) {
                return $query->active();
            }], dataLimit: 'all');
            $totalDiscountedPrice = $this->cartService->getCartTotalDiscountPrice(session($cartName), $products, $customerCartData[$cartName]['customerId']);

            foreach ($cartListSession as $cartItem) {
                if (is_array($cartItem)) {
                    $product = $products->firstWhere('id', $cartItem['id']);
                    if ($product) {
                        $cartSubTotalCalculation = $this->cartService->getCartSubtotalCalculation(
                            product: $product,
                            cartItem: $cartItem,
                            totalDiscountedPrice: $totalDiscountedPrice,
                            cartName: $cartName,
                        );
                        if ($cartItem['customerId'] == $customerCartData[$cartName]['customerId']) {
                            $cartItem['productSubtotal'] = $cartSubTotalCalculation['productSubtotal'];
                            $subTotalCalculation['customerOnHold'] = $cartItem['customerOnHold'];
                            $cartItemValue[] = $cartItem;

                            $subTotalCalculation['countItem'] += $cartSubTotalCalculation['countItem'];
                            $subTotalCalculation['totalQuantity'] += $cartSubTotalCalculation['totalQuantity'];
                            $subTotalCalculation['productSubtotal'] += $cartSubTotalCalculation['productSubtotal'];
                            $subTotalCalculation['subtotal'] += $cartSubTotalCalculation['subtotal'];
                            $subTotalCalculation['discountOnProduct'] += $cartSubTotalCalculation['discountOnProduct'];
                            $subTotalCalculation['totalTax'] += $cartSubTotalCalculation['appliedTaxAmount'];
                        }
                    }
                }
            }
        }
        $totalCalculation = $this->cartService->getTotalCalculation(
            subTotalCalculation: $subTotalCalculation, cartName: $cartName
        );
        return [
            'countItem' => $subTotalCalculation['countItem'],
            'total' => $totalCalculation['total'],
            'subtotal' => $subTotalCalculation['subtotal'],
            'totalTax' => $subTotalCalculation['totalTax'],
            'discountOnProduct' => $subTotalCalculation['discountOnProduct'],
            'productSubtotal' => $subTotalCalculation['productSubtotal'],
            'cartItemValue' => $cartItemValue,
            'couponDiscount' => $totalCalculation['couponDiscount'],
            'extraDiscount' => $totalCalculation['extraDiscount'],
            'customerOnHold' => $subTotalCalculation['customerOnHold'] ?? false,
        ];
    }

}
