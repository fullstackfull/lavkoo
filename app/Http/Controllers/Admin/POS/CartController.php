<?php

namespace App\Http\Controllers\Admin\POS;

use App\Contracts\Repositories\ColorRepositoryInterface;
use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Enums\SessionKey;
use App\Http\Controllers\BaseController;
use App\Services\CartService;
use App\Services\POSService;
use App\Traits\CalculatorTrait;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Throwable;

class CartController extends BaseController
{
    use CalculatorTrait;

    /**
     * @param ProductRepositoryInterface $productRepo
     * @param ColorRepositoryInterface $colorRepo
     * @param CustomerRepositoryInterface $customerRepo
     * @param CartService $cartService
     * @param POSService $POSService
     */
    public function __construct(
        private readonly ProductRepositoryInterface  $productRepo,
        private readonly ColorRepositoryInterface    $colorRepo,
        private readonly CustomerRepositoryInterface $customerRepo,
        private readonly CartService                 $cartService,
        private readonly POSService                  $POSService,
    )
    {
    }

    public function index(?Request $request, ?string $type = null): View|Collection|LengthAwarePaginator|null|callable|RedirectResponse
    {
        // TODO: Implement index() method.
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function getVariantPrice(Request $request): JsonResponse
    {
        $cartId = session(SessionKey::CURRENT_USER);
        $cartItems = $this->getCartData(cartName: $cartId);
        $productSubtotal = $cartItems['productSubtotal'] ?? 0;
        $product = $this->productRepo->getFirstWhere(params: ['id' => $request['id']], relations: ['digitalVariation', 'clearanceSale' => function ($query) {
            return $query->active();
        }]);
        $colorName = $this->colorRepo->getFirstWhere(['code' => $request['color']])->name ?? null;
        $data = $this->cartService->getVariantData(
            request: $request, product: $product, colorName: $colorName
        );
        $data['productSubtotal'] = setCurrencySymbol(amount: usdToDefaultCurrency(amount: $productSubtotal));
        return response()->json($data);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     * @throws Throwable
     */
    public function updateQuantity(Request $request): JsonResponse
    {
        $cartId = session(SessionKey::CURRENT_USER);
        if ($request['quantity'] > 0) {
            $product = $this->productRepo->getFirstWhere(params: ['id' => $request['key']], relations: ['clearanceSale' => function ($query) {
                return $query->active();
            }]);
            $quantity = $this->cartService->getQuantityAndUpdateTime(request: $request, product: $product);
            $cartItems = $this->getCartData(cartName: $cartId);
            if ($product['product_type'] == 'physical' && $quantity < 0) {
                return response()->json([
                    'qty' => $quantity,
                    'productSubtotal' => setCurrencySymbol(amount: usdToDefaultCurrency(amount: $cartItems['productSubtotal'])),
                    'productType' => $product['product_type'],
                    'productCounts' => $this->cartService->getProductCounts(),
                    'view' => view('admin-views.pos.partials._cart', compact('cartId', 'cartItems'))->render()
                ]);
            } else {
                return response()->json([
                    'quantityUpdate' => 1,
                    'productSubtotal' => setCurrencySymbol(amount: usdToDefaultCurrency(amount: $cartItems['productSubtotal'])),
                    'productCounts' => $this->cartService->getProductCounts(),
                    'view' => view('admin-views.pos.partials._cart', compact('cartId', 'cartItems'))->render()
                ]);
            }
        } else {
            $cartItems = $this->getCartData(cartName: $cartId);
            return response()->json([
                'upQty' => 'zeroNegative',
                'view' => view('admin-views.pos.partials._cart', compact('cartId', 'cartItems'))->render()
            ]);
        }
    }

    public function addToCart(Request $request): JsonResponse
    {
        $variations = [];
        $cartId = session(SessionKey::CURRENT_USER);
        $product = $this->productRepo->getFirstWhere(params: ['id' => $request['id']], relations: ['digitalVariation', 'clearanceSale' => function ($query) {
            return $query->active();
        }]);
        $colorName = $this->colorRepo->getFirstWhere(['code' => $request['color']])->name ?? null;
        if (isset($colorName)) {
            $variations['color'] = $colorName;
        }
        $variant = $this->cartService->makeVariation(
            request: $request,
            colorName: $colorName,
            choiceOptions: json_decode($product['choice_options'])
        );
        if ($product['product_type'] == 'digital' && $request->has('variant_key')) {
            foreach ($product['digitalVariation'] as $digitalVariation) {
                if ($digitalVariation['variant_key'] == $request['variant_key']) {
                    $variant = $digitalVariation['variant_key'];
                }
            }
        }
        foreach (json_decode($product['choice_options']) as $choice) {
            $variations[$choice->title] = $request[$choice->name];
        }
        $price = $product['unit_price'];
        $discount = getProductPriceByType(product: $product, type: 'discounted_amount', result: 'value', price: $price);
        $cartData = session($cartId);
        if ($cartId && session()->has($cartId) && count($cartData) > 0) {
            foreach ($cartData as $key => $cartItem) {
                if (is_array($cartItem) && $cartItem['id'] == $request['id'] && $cartItem['variant'] == $variant) {
                    if ($variant != null) {
                        $price = $this->cartService->getVariationPrice(variation: json_decode($product['variation']), variant: $variant);
                        $discount = getProductPriceByType(product: $product, type: 'discounted_amount', result: 'value', price: $price);
                    }
                    if ($product['product_type'] == 'digital' && $request->has('variant_key')) {
                        foreach ($product['digitalVariation'] as $digitalVariation) {
                            if ($digitalVariation['variant_key'] == $request['variant_key']) {
                                $variant = $digitalVariation['variant_key'];
                                $price = $digitalVariation['price'];
                                $discount = getProductPriceByType(product: $product, type: 'discounted_amount', result: 'value', price: $price);
                            }
                        }
                    }

                    if ($request['quantity'] <= 0 || $request['quantity_in_cart'] <= 0) {
                        $cartItems = $this->getCartData(cartName: $cartId);
                        return response()->json([
                            'data' => 'custom-error',
                            'title' => translate('Invalid_quantity'),
                            'text' => translate('Please_provide_a_valid_quantity'),
                            'view' => view('admin-views.pos.partials._cart', compact('cartId', 'cartItems'))->render()
                        ]);
                    }

                    $currentQty = $this->cartService->checkCurrentStock(variant: $variant, variation: json_decode($product['variation']), productQty: $product['current_stock'], quantity: $request['quantity_in_cart']);
                    if ($product['product_type'] == 'physical' && $currentQty < 0) {
                        $cartItems = $this->getCartData(cartName: $cartId);
                        return response()->json([
                            'data' => 0,
                            'productCounts' => $this->cartService->getProductCounts(),
                            'view' => view('admin-views.pos.partials._cart', compact('cartId', 'cartItems'))->render()
                        ]);
                    }
                    $cartItem = $this->cartService->addCartDataOnSession(
                        product: $product,
                        quantity: $request['quantity_in_cart'],
                        price: $price,
                        discount: $discount,
                        variant: $variant,
                        variations: $variations
                    );
                    unset($cartData[$key]);
                    $cartData[] = $cartItem;
                    session([$cartId => $cartData]);
                    $getCurrentCustomerData = $this->getCustomerDataFromSessionForPOS();
                    $summaryData = array_merge($this->POSService->getSummaryData(), $getCurrentCustomerData);
                    $cartItems = $this->getCartData(cartName: $cartId);
                    return response()->json([
                        'data' => 1,
                        'inCartData' => 1,
                        'productCounts' => $this->cartService->getProductCounts(),
                        'requestQuantity' => $request['quantity_in_cart'],
                        'view' => view('admin-views.pos.partials._cart', compact('summaryData', 'cartItems'))->render(),
                    ]);
                }
            }
        }
        if ($variant != null) {
            $price = $this->cartService->getVariationPrice(variation: json_decode($product['variation']), variant: $variant);
            $discount = getProductPriceByType(product: $product, type: 'discounted_amount', result: 'value', price: $price);
        }

        if ($product['product_type'] == 'digital' && $request->has('variant_key')) {
            foreach ($product['digitalVariation'] as $digitalVariation) {
                if ($digitalVariation['variant_key'] == $request['variant_key']) {
                    $variant = $digitalVariation['variant_key'];
                    $price = $digitalVariation['price'];
                    $discount = getProductPriceByType(product: $product, type: 'discounted_amount', result: 'value', price: $price);
                }
            }
        }

        $currentQty = $this->cartService->checkCurrentStock(variant: $variant, variation: json_decode($product['variation']), productQty: $product['current_stock'], quantity: $request['quantity']);
        if ($product['product_type'] == 'physical' && $currentQty < 0) {
            $cartItems = $this->getCartData(cartName: $cartId);
            return response()->json([
                'data' => 0,
                'productCounts' =>$this->cartService->getProductCounts(),
                'view' => view('admin-views.pos.partials._cart', compact('cartId', 'cartItems'))->render()
            ]);
        }
        $sessionData = $this->cartService->addCartDataOnSession(
            product: $product,
            quantity: $request['quantity'],
            price: $price,
            discount: $discount,
            variant: $variant,
            variations: $variations
        );
        $cartItems = $this->getCartData(cartName: $cartId);
        return response()->json([
            'data' => $sessionData,
            'productCounts' => $this->cartService->getProductCounts(),
            'view' => view('admin-views.pos.partials._cart', compact('cartId', 'cartItems'))->render()
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     * @throws Throwable
     */
    public function removeCart(Request $request): JsonResponse
    {
        $cartId = session(SessionKey::CURRENT_USER);
        $cart = session($cartId);
        $cartKeeper = [];
        if (session()->has($cartId) && count($cart) > 0) {
            foreach ($cart as $cartItem) {
                if (is_array($cartItem)) {
                    if ($cartItem['id'] != $request['id']) {
                        $cartKeeper[] = $cartItem;
                    } else {
                        if ($cartItem['variant'] != $request['variant']) {
                            $cartKeeper[] = $cartItem;
                        }
                    }
                }
            }
        }
        session()->put($cartId, $cartKeeper);
        $cartItems = $this->getCartData(cartName: $cartId);
        return response()->json([
            'view' => view('admin-views.pos.partials._cart', compact('cartId', 'cartItems'))->render(),
            'productCounts' => $this->cartService->getProductCounts(),
        ]);
    }

    /**
     * @return RedirectResponse
     */
    public function clearSessionCartIds(): RedirectResponse
    {
        $cartId = session(SessionKey::CURRENT_USER);
        $cart = session()->get($cartId, []);
        if (empty($cart)) {
            ToastMagic::warning(translate('Cart is already empty'));
            return redirect()->route('admin.pos.index');
        }
        session()->forget(SessionKey::CART_NAME);
        session()->forget(session(SessionKey::CURRENT_USER));
        session()->forget(SessionKey::CURRENT_USER);
        ToastMagic::success(translate('Cart has been cleared'));
        return redirect()->route('admin.pos.index');
    }

    /**
     * @return JsonResponse
     * @throws Throwable
     */
    public function getCartIds(): JsonResponse
    {
        $cartName = $this->cartService->ensureCartSession();
        $this->cartService->getCartKeeper();
        $getCurrentCustomerData = $this->getCustomerDataFromSessionForPOS();
        $summaryData = array_merge($this->POSService->getSummaryData(), $getCurrentCustomerData);
        $cartItems = $this->getCartData(cartName: $cartName);
        return response()->json([
            'view' => view('admin-views.pos.partials._cart-summary', compact('summaryData', 'cartItems'))->render(),
        ]);
    }

    /**
     * @return JsonResponse
     * @throws Throwable
     */
    public function emptyCart(): JsonResponse
    {
        $cartId = session(SessionKey::CURRENT_USER);
        session()->forget($cartId);
        $this->cartService->getNewCartSession(cartId: $cartId);
        $getCurrentCustomerData = $this->getCustomerDataFromSessionForPOS();
        $summaryData = array_merge($this->POSService->getSummaryData(), $getCurrentCustomerData);
        $cartItems = $this->getCartData(cartName: $cartId);
        return response()->json([
            'view' => view('admin-views.pos.partials._cart-summary', compact('summaryData', 'cartItems'))->render(),
        ]);
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     * @throws \Exception
     */
    public function changeCart(Request $request): RedirectResponse
    {
        $this->cartService->customerOnHoldStatus(status: true);
        session()->put(SessionKey::CURRENT_USER, $request['cart_id']);
        $this->cartService->customerOnHoldStatus(status: false);
        ToastMagic::success($request['cart_id'] . ' ' . translate('order_is_now_resumed'));
        return redirect()->route('admin.pos.index');
    }

    /**
     * @return RedirectResponse
     * @throws \Exception
     */
    public function addNewCartId(): RedirectResponse
    {
        // `session(SessionKey::CURRENT_USER)` is unset until the first POS cart exists, and
        // `session(null)` returns the SessionManager rather than null — so `count($cart)` fataled
        // and a new cart tab could never be opened. No current cart simply means an empty cart.
        $currentCart = session(SessionKey::CURRENT_USER);
        $cart = $currentCart ? session($currentCart) : null;

        $cartItems = collect(is_array($cart) ? $cart : [])->filter(function ($cartItem) {
            return is_array($cartItem);
        });

        if (!is_array($cart) || $cartItems->count() <= 0) {
            ToastMagic::error(translate('Cart_is_empty'));
        } else {
            if (count($cart) > 0) {
                ToastMagic::success(translate('this_order_is_now_on_hold'));
            }
            $this->cartService->customerOnHoldStatus(status: true);
            $this->cartService->getNewCartId();
        }
        return redirect()->route('admin.pos.index');
    }

    /**
     * @return array
     */
    protected function getCustomerDataFromSessionForPOS(): array
    {
        // Before the first POS cart exists this key is unset. Str::contains(null, ...) is false, so
        // the old code fell into the saved-customer branch and blew up on explode(null)[2] with
        // "Undefined array key 2" — the POS cart panel could not load on a fresh admin session.
        // No current cart means a walk-in customer, which is what the panel shows by default.
        $currentUserKey = session(SessionKey::CURRENT_USER);
        $savedCustomerId = $currentUserKey ? (explode('-', $currentUserKey)[2] ?? null) : null;

        if (!$currentUserKey || Str::contains($currentUserKey, 'walk-in-customer') || $savedCustomerId === null) {
            $currentCustomer = 'Walk-In Customer';
            $currentCustomerData = $this->customerRepo->getFirstWhere(params: ['id' => '0']);
        } else {
            $userId = $savedCustomerId;
            $currentCustomerData = $this->customerRepo->getFirstWhere(params: ['id' => $userId]);
            $currentCustomer = $currentCustomerData['f_name'] . ' ' . $currentCustomerData['l_name'] . ' (' . $currentCustomerData['phone'] . ')';
        }
        return [
            'currentCustomer' => $currentCustomer,
            'currentCustomerData' => $currentCustomerData
        ];
    }

    /**
     * @param string $cartName
     * @return array
     * @function getCustomerCartData ,used for process data
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
            'discountOnProduct' => $subTotalCalculation['discountOnProduct'],
            'productSubtotal' => $subTotalCalculation['productSubtotal'],
            'totalTax' => $subTotalCalculation['totalTax'],
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
