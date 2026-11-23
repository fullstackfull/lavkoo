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
use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\BaseController;
use App\Services\CartService;
use App\Services\Marketplace\InventoryService;
use App\Services\OrderDetailsService;
use App\Services\OrderService;
use App\Services\POSService;
use App\Traits\CalculatorTrait;
use App\Traits\CustomerTrait;
use App\Utils\CartManager;
use App\Utils\OrderManager;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
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
     * @param InventoryService $inventoryService
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
        private readonly InventoryService                           $inventoryService,
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
        // The debit itself is made inside the sale's transaction below. A sale this panel refuses for want of stock must not
        // leave the customer's wallet lighter, which is what a debit taken before the stock was checked would do.
        $payByWallet = $request['type'] == 'wallet' && $userId != 0;
        if ($payByWallet) {
            $customerBalance = $this->customerRepo->getFirstWhere(params: ['id' => $userId]) ?? 0;
            if ($customerBalance['wallet_balance'] < currencyConverter(amount: $amount)) {
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

        // Ascending product id order, so the decrement follows the same order as the lock above: the lock makes
        // concurrent sales take rows in one order, and taking them in a different order afterwards would put two
        // sales' writes back out of step. PosStockDecrementOrderTest asserts the decrements themselves ascend.
        $cartListSession = $cartListSession->sortBy(static fn (array $cartItem): int => (int) ($cartItem['id'] ?? 0))->values();

        if (count($cartListSession) > 0) {
            try {
                $orderId = OrderManager::writeOrderRetryingOnDuplicateId(function () use ($request, $cartId, $cartListSession, $cart, $taxConfig, $userId, $amount, $paidAmount, $fulfillment, $payByWallet) {
                // Every product of the sale is locked here, in ascending id order, before a line is priced or taken. See
                // InventoryService::lockProductsForSale for why the order is the point.
                $this->inventoryService->lockProductsForSale(productIds: $cartListSession->pluck('id')->all());

                // The debit comes after that lock, not before it. Two reasons, both measured: the lock is this sale's only
                // ordered lock, and taking the customer's row first put the two in an order nothing else follows; and the
                // debit's unlocked read of the customer was the transaction's first consistent read, which fixed the
                // REPEATABLE READ snapshot the order id is then checked against, so an overlapping sale's order was invisible
                // and its id was chosen again.
                if ($payByWallet) {
                    $this->createWalletTransaction(user_id: $userId, amount: floatval($amount), transaction_type: 'order_place', reference: 'order_place_in_pos');
                }

                $products = $this->productRepo->getListWithScope(filters: ['added_by' => 'seller', 'seller_id' => auth('seller')->id()], whereIn: ['id' => $cartListSession->pluck('id')->toArray()], relations: ['clearanceSale' => function ($query) {
                    return $query->active();
                }], dataLimit: 'all');
                $totalDiscountedPrice = $this->cartService->getCartTotalDiscountPrice(session($cartId), $products, $userId);

                // Counting the rows and adding one picks the SAME number for two sales that overlap, and the second insert
                // dies on the primary key: a lawful sale lost to a 500. generateNewOrderID is the web checkout's own picker,
                // written for that crash, and its read is a locking one, so the second sale waits and reads the id the first
                // one just wrote.
                $orderId = OrderManager::generateNewOrderID();
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

                            // The shelf, the variant and the movement row in one call, under the lock taken above, by the same
                            // rule the web checkout follows: a line the shelf cannot cover throws and the whole sale rolls back.
                            // The till used to floor the shortfall at zero and sell anyway, charging for units it did not have.
                            if ($product['product_type'] == 'physical') {
                                $this->inventoryService->takeForSale(
                                    productId: $product['id'],
                                    quantity: (int) $item['quantity'],
                                    variant: $item['variant'],
                                    referenceType: 'pos_order',
                                    referenceId: $orderId,
                                );
                            }
                            $this->orderDetailRepo->add(data: $orderDetail);

                            $appliedTaxIds = CartManager::getAppliedTaxIds(
                                product: $product,
                                taxConfig: $taxConfig
                            );

                            $appliedTaxRate = collect($appliedTaxIds)->sum('tax_rate');
                            $finalAmount = ($item['price'] - $item['discount']) * $item['quantity'];

                            foreach ($appliedTaxIds as $taxItem) {
                                OrderManager::getAddOrderTaxDetails(
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

                return $orderId;
            });
            } catch (InsufficientStockException $exception) {
                // The transaction has rolled back: no order, no order line, no stock taken and no wallet debit. The cart is
                // left as it was so the cashier can change the quantity and try again.
                Log::warning('POS sale refused: ' . $exception->getMessage(), ['product_id' => $exception->productId, 'requested' => $exception->requested]);
                ToastMagic::error(translate('not_enough_stock_to_place_this_order'));

                return response()->json(['stockShortfall' => true, 'message' => translate('not_enough_stock_to_place_this_order')]);
            }

            // Past the commit: everything from here has a side effect the database cannot undo.
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
            $products = $this->productRepo->getListWithScope(filters: ['added_by' => 'seller', 'seller_id' => auth('seller')->id()], whereIn: ['id' => $cartListSession->pluck('id')->toArray()], relations: ['clearanceSale' => function ($query) {
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
