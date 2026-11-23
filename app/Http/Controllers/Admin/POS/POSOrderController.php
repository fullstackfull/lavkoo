<?php

namespace App\Http\Controllers\Admin\POS;

use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Contracts\Repositories\DigitalProductVariationRepositoryInterface;
use App\Contracts\Repositories\OrderDetailRepositoryInterface;
use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\StorageRepositoryInterface;
use App\Contracts\Repositories\VendorRepositoryInterface;
use App\Enums\SessionKey;
use App\Events\DigitalProductDownloadEvent;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\WalletDebitFailedException;
use App\Http\Controllers\BaseController;
use App\Services\CartService;
use App\Services\Marketplace\InventoryService;
use App\Services\OrderDetailsService;
use App\Services\OrderService;
use App\Services\POSService;
use App\Services\Storefront\OneSellingCompanyPerOrder;
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
use InvalidArgumentException;
use Modules\TaxModule\app\Traits\VatTaxManagement;
use Throwable;

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
        $vendorId = auth('seller')->id();
        $vendor = $this->vendorRepo->getFirstWhere(params: ['id' => $vendorId]);
        $getPOSStatus = getWebConfig('seller_pos');
        if ($vendor['pos_status'] == 0 || $getPOSStatus == 0) {
            ToastMagic::warning(translate('access_denied!!'));
            return redirect()->back();
        }
        $order = $this->orderRepo->getFirstWhere(params: ['id' => $type], relations: ['details', 'shipping', 'seller']);
        return view('admin-views.pos.order.order-details', compact('order'));
    }

    /**
     * @param Request $request
     * @return JsonResponse
     * @throws Exception
     */
    public function placeOrder(Request $request): JsonResponse
    {
        $fulfillment = $request['fulfillment'] == 'delivery' ? 'delivery' : 'instant';
        // Cash for a delivery order is collected on delivery, so the tendered
        // amount is neither required nor recorded here — same as a web COD order.
        $paidAmount = ($request['type'] == 'cash' && $fulfillment == 'instant') ? ($request['paid_amount'] ?? 0) : null;
        $cartId = session(SessionKey::CURRENT_USER);
        // The posted `amount` decides none of the money below any more; $saleAmount does. It is still what the
        // tendered cash is measured against, because that comparison is between two figures the panel itself produced
        // — the total it displayed and the cash the cashier says was handed over — and the panel's cart summary still
        // adds its lines up in floats. Measuring the cash against the server's figure instead would refuse a cashier
        // who tendered exactly what the panel showed.
        $condition = $this->POSService->checkConditions(amount: $request['amount'], paidAmount: $paidAmount);
        if ($condition == 'true') {
            return response()->json();
        }
        $userId = $this->cartService->getUserId();
        if ($userId == 0 && $fulfillment == 'delivery') {
            return response()->json(['checkProductTypeForWalkingCustomer' => true, 'message' => translate('To_place_a_delivery_order') . ',' . translate('_kindly_select_a_customer_or_fill_up_the_“Add_New_Customer”_form') . '.']);
        }
        $checkProductTypeDigital = $this->cartService->checkProductTypeDigital(cartId: $cartId);
        if ($userId == 0 && $checkProductTypeDigital) {
            return response()->json(['checkProductTypeForWalkingCustomer' => true, 'message' => translate('To_order_digital_product') . ',' . translate('_kindly_fill_up_the_“Add_New_Customer”_form') . '.']);
        }
        // What the sale costs is the server's own figure: worked out from the cart the server holds and the prices in
        // the database, and added up in whole minor units. App\Services\Pos\PosSaleTotal says why neither half of
        // that is optional — `amount` was a figure the browser posted, and the panel's own addition loses a minor unit
        // to binary floats on ordinary carts.
        //
        // It is settled HERE, before the transaction, because the balance a wallet sale is refused on and the money it
        // then takes have to be the same figure, and that refusal happens outside the transaction. This locks nothing
        // and writes nothing, so it changes neither which rows the transaction locks nor the order it does its work
        // in: the ordered product lock is still the first thing inside it, the debit still comes after that lock, and
        // the order id is still chosen inside it after the lock. It reads the product rows once more, unlocked; the
        // read inside the transaction stays where it is, after the lock, so the rows the sale is WRITTEN from are
        // still the locked ones.
        //
        // The one refusal PosSaleTotal can raise — a line that cannot be counted in 64-bit integers — is caught here
        // rather than left to throw. Neither panel's place-order $.post defines an `error:` callback (reviewer B, F6),
        // so an exception out of this action shows the cashier a stopped spinner and nothing else. That is a
        // pre-existing hole this lane does not widen: every refusal it adds answers in a shape the panel displays.
        $cartProducts = $this->getCartProductsForPricing(cartId: $cartId);
        try {
            $saleTotal = $this->cartService->getPosSaleTotal(
                cartName: $cartId,
                customerId: $userId,
                products: $cartProducts,
            );
        } catch (InvalidArgumentException $exception) {
            Log::warning('POS sale refused: ' . $exception->getMessage(), ['cart' => $cartId]);
            ToastMagic::error(translate('You cannot place an order with an amount of zero. Please enter a valid amount.'));

            return response()->json();
        }
        $saleAmount = $saleTotal->amount();

        // `count($cartListSession) > 0` below counts cart LINES, not lines this till can sell, and the sale's own loop
        // skips a line whose product is no longer in the catalogue. So a cart whose every line names a deleted product
        // wrote an order row with NO lines and charged for it — the posted figure before this branch, and nothing at
        // all after it, with the tendered cash still recorded, which left paid_amount ABOVE order_amount and had the
        // readiness probe count the row as overpaid (reviewer B, F8). The seller app's till was given a refusal for
        // exactly this; these two were not. It is the same key and the same answer shape, so the panel reloads and
        // shows the toast. It cannot pre-empt the `stockShortfall` answer the base's own test pins for a line of fewer
        // than one unit: that line's product EXISTS, so it is priced and counted here and refused where it belongs, by
        // InventoryService::takeForSale.
        if ($saleTotal->lineCount() === 0) {
            ToastMagic::error(translate('a_sale_needs_at_least_one_product'));
            return response()->json();
        }

        // A counter sale is a sale: one order may name one country and one selling company (D1-02). The products the
        // till already loaded to price the cart are judged here, before the stock is taken and before the order and
        // its lines are written, so a till holding two countries' products sells neither of them on one receipt. It
        // sits above the wallet block on purpose: nothing has been written or charged at this point, so a refusal
        // leaves the balance exactly where it was.
        if (OneSellingCompanyPerOrder::productsSpanMoreThanOne(products: $cartProducts)) {
            ToastMagic::error(translate('Product_is_unavailable'));
            return response()->json();
        }

        // The debit itself is made inside the sale's transaction below. A sale this panel refuses for want of stock must not
        // leave the customer's wallet lighter, which is what a debit taken before the stock was checked would do.
        $payByWallet = $request['type'] == 'wallet' && $userId != 0;
        if ($payByWallet) {
            $customerBalance = $this->customerRepo->getFirstWhere(params: ['id' => $userId]) ?? 0;
            // A sale that does not cost a positive amount cannot be paid for out of a wallet, and the balance
            // comparison alone does not say so: `$balance < -100` is false of every balance, so a cart discounted past
            // its own lines would pass it and then CREDIT the customer — createWalletTransaction treats `order_place`
            // as a debit, and a debit of a negative amount adds to the balance. The panel's own discount route already
            // refuses such a cart (POSController::updateDiscount answers 'amount_low' when the total would go below
            // zero), so this is the second line of that defence rather than the first. It is deliberately NOT applied
            // to the sale as a whole: a line of
            // fewer than one unit also comes to less than nothing, and that is refused where it belongs, by
            // InventoryService::takeForSale, with the `stockShortfall` answer the panel reads.
            if ($saleAmount <= 0) {
                ToastMagic::error(translate('You cannot place an order with an amount of zero. Please enter a valid amount.'));
                return response()->json();
            }
            if ($customerBalance['wallet_balance'] < currencyConverter(amount: $saleAmount)) {
                ToastMagic::error(translate('need_Sufficient_Amount_Balance'));
                return response()->json();
            }
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
                $orderId = OrderManager::writeOrderRetryingOnDuplicateId(function () use ($request, $cartId, $cartListSession, $cart, $taxConfig, $userId, $saleTotal, $saleAmount, $paidAmount, $fulfillment, $payByWallet) {
                // Every product of the sale is locked here, in ascending id order, before a line is priced or taken. See
                // InventoryService::lockProductsForSale for why the order is the point.
                $this->inventoryService->lockProductsForSale(productIds: $cartListSession->pluck('id')->all());

                // The debit comes after that lock, not before it. Two reasons, both measured: the lock is this sale's only
                // ordered lock, and taking the customer's row first put the two in an order nothing else follows; and the
                // debit's unlocked read of the customer was the transaction's first consistent read, which fixed the
                // REPEATABLE READ snapshot the order id is then checked against, so an overlapping sale's order was invisible
                // and its id was chosen again.
                if ($payByWallet) {
                    // The answer is checked, because this call is what MAKES the sale paid and it has two ways of
                    // doing nothing and saying so: it returns false outright when the `wallet_status` setting is off,
                    // and false again if its own save throws. Both answers were discarded, so the till wrote an order
                    // marked payment_status 'paid' with no wallet_transactions row and the balance untouched —
                    // measured at this branch's base and at its head alike (reviewer A, F6). Throwing here rolls the
                    // whole sale back the way an InsufficientStockException already does, which is the only honest
                    // answer: a wallet sale that took nothing is not a sale.
                    if (!$this->createWalletTransaction(user_id: $userId, amount: $saleAmount, transaction_type: 'order_place', reference: 'order_place_in_pos')) {
                        throw new WalletDebitFailedException(userId: $userId, amount: $saleAmount);
                    }
                }

                $products = $this->productRepo->getListWithScope(whereIn: ['id' => $cartListSession->pluck('id')->toArray()], relations: ['clearanceSale' => function ($query) {
                    return $query->active();
                }], dataLimit: 'all');
                $totalDiscountedPrice = $this->cartService->getCartTotalDiscountPrice(session($cartId), $products, $userId);

                // Counting the rows and adding one picks the SAME number for two sales that overlap, and the second insert
                // dies on the primary key: a lawful sale lost to a 500. generateNewOrderID is the web checkout's own picker,
                // written for that crash, and its read is a locking one, so the second sale waits and reads the id the first
                // one just wrote.
                $orderId = OrderManager::generateNewOrderID();
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
                    amount: $saleAmount,
                    // The sale's own integer tax, not the float accumulated beside it: an order charged in whole
                    // minor units recorded a tax that was not one (13.563 on a two-decimal store), so the row did
                    // not add up to itself. Reviewer A's F4 and reviewer B's F3.
                    totalTaxAmount: $saleTotal->taxAmount(),
                    paidAmount: $paidAmount ?? ($request['type'] == 'cash' ? 0 : $saleAmount),
                    paymentType: $request['type'],
                    addedBy: 'admin',
                    userId: $userId,
                    fulfillment: $fulfillment
                );
                $this->orderRepo->add(data: $order);

                return $orderId;
            });
            } catch (WalletDebitFailedException $exception) {
                // The transaction has rolled back: no order, no order line, no stock taken, and the balance is what it
                // was. The refusal uses the same answer shape and the same message as the balance refusal above, so the
                // panel reloads and shows the toast rather than the operator seeing a 500 with no message at all.
                Log::warning('POS wallet sale refused: ' . $exception->getMessage(), ['user_id' => $exception->userId]);
                ToastMagic::error(translate('need_Sufficient_Amount_Balance'));

                return response()->json();
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
                    'userName' => $order?->customer?->f_name ?? "",
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

    /**
     * @throws Throwable
     */
    public function cancelOrder(Request $request): JsonResponse
    {
        session()->remove($request['cart_id']);
        $totalHoldOrders = $this->POSService->getTotalHoldOrders();
        $cartNames = $this->POSService->getCartNames();
        $cartItems = $this->getHoldOrderCalculationData(cartNames: $cartNames);
        return response()->json([
            'message' => $request['cart_id'] . ' ' . translate('order_is_cancel'),
            'status' => 'success',
            'view' => view('admin-views.pos.partials._view-hold-orders', compact('totalHoldOrders', 'cartItems'))->render(),
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     * @throws Throwable
     */
    public function getAllHoldOrdersView(Request $request): JsonResponse
    {
        $totalHoldOrders = $this->POSService->getTotalHoldOrders();
        $cartNames = $this->POSService->getCartNames();
        $cartItems = $this->getHoldOrderCalculationData(cartNames: $cartNames);
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
            'view' => view('admin-views.pos.partials._view-hold-orders', compact('totalHoldOrders', 'cartItems'))->render(),
        ]);
    }

    /**
     * The products of a cart, carrying the clearance sale the pricing helpers read off them.
     *
     * The same read the sale's own transaction makes, so the figure the sale is priced at before the transaction and
     * the rows it is written from inside one are priced by the same rule.
     */
    private function getCartProductsForPricing(string $cartId): Collection|LengthAwarePaginator
    {
        return $this->productRepo->getListWithScope(
            whereIn: ['id' => $this->cartService->filterSessionCartList(cart: session($cartId))->pluck('id')->toArray()],
            relations: ['clearanceSale' => function ($query) {
                return $query->active();
            }],
            dataLimit: 'all',
        );
    }

    /**
     * @return array
     */
    protected function getCustomerDataFromSessionForPOS(): array
    {
        if (Str::contains(session(SessionKey::CURRENT_USER), 'walk-in-customer')) {
            $currentCustomer = 'Walk-In Customer';
            $currentCustomerData = $this->customerRepo->getFirstWhere(params: ['id' => '0']);
        } else {
            $userId = explode('-', session(SessionKey::CURRENT_USER))[2];
            $currentCustomerData = $this->customerRepo->getFirstWhere(params: ['id' => $userId]);
            $currentCustomer = $currentCustomerData['f_name'] . ' ' . $currentCustomerData['l_name'] . ' (' . $currentCustomerData['phone'] . ')';
        }
        return [
            'currentCustomer' => $currentCustomer,
            'currentCustomerData' => $currentCustomerData
        ];
    }


    /**
     * @param array $cartNames
     * @return array
     */
    protected function getHoldOrderCalculationData(array $cartNames): array
    {
        $cartData = [];
        foreach ($cartNames as $cartName) {
            $customerCartData = $this->getCustomerCartData(cartName: $cartName);
            $CartItemData = $this->calculateCartItemsData(cartName: $cartName, customerCartData: $customerCartData);
            $cartData[$cartName] = array_merge($customerCartData[$cartName], $CartItemData);
        }
        return $cartData;
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

    protected function getCartData(string $cartName): array
    {
        $customerCartData = $this->getCustomerCartData(cartName: $cartName);
        $cartItemData = $this->calculateCartItemsData(cartName: $cartName, customerCartData: $customerCartData);
        return array_merge($customerCartData[$cartName], $cartItemData);
    }
}