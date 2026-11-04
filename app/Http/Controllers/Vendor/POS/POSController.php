<?php

namespace App\Http\Controllers\Vendor\POS;

use App\Contracts\Repositories\BrandRepositoryInterface;
use App\Contracts\Repositories\CategoryRepositoryInterface;
use App\Contracts\Repositories\CouponRepositoryInterface;
use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Contracts\Repositories\DeliveryZipCodeRepositoryInterface;
use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\PublishingHouseRepositoryInterface;
use App\Contracts\Repositories\ShopRepositoryInterface;
use App\Contracts\Repositories\VendorRepositoryInterface;
use App\Contracts\Repositories\AuthorRepositoryInterface;
use App\Enums\SessionKey;
use App\Enums\ViewPaths\Vendor\POS;
use App\Http\Controllers\BaseController;
use App\Services\CartService;
use App\Services\POSService;
use App\Traits\CalculatorTrait;
use App\Traits\CommonTrait;
use App\Traits\CustomerTrait;
use App\Utils\ProductManager;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use App\Services\ProductService;
use Throwable;

class POSController extends BaseController
{
    use CalculatorTrait, CommonTrait, CustomerTrait;

    /**
     * @param AuthorRepositoryInterface $authorRepo
     * @param VendorRepositoryInterface $vendorRepo
     * @param BrandRepositoryInterface $brandRepo
     * @param CategoryRepositoryInterface $categoryRepo
     * @param ProductRepositoryInterface $productRepo
     * @param CustomerRepositoryInterface $customerRepo
     * @param ShopRepositoryInterface $shopRepo
     * @param CouponRepositoryInterface $couponRepo
     * @param OrderRepositoryInterface $orderRepo
     * @param CartService $cartService
     * @param POSService $POSService
     * @param DeliveryZipCodeRepositoryInterface $deliveryZipCodeRepo
     * @param ProductService $productService
     * @param PublishingHouseRepositoryInterface $publishingHouseRepo
     */
    public function __construct(
        private readonly AuthorRepositoryInterface          $authorRepo,
        private readonly VendorRepositoryInterface          $vendorRepo,
        private readonly BrandRepositoryInterface           $brandRepo,
        private readonly CategoryRepositoryInterface        $categoryRepo,
        private readonly ProductRepositoryInterface         $productRepo,
        private readonly CustomerRepositoryInterface        $customerRepo,
        private readonly ShopRepositoryInterface            $shopRepo,
        private readonly CouponRepositoryInterface          $couponRepo,
        private readonly OrderRepositoryInterface           $orderRepo,
        private readonly CartService                        $cartService,
        private readonly POSService                         $POSService,
        private readonly DeliveryZipCodeRepositoryInterface $deliveryZipCodeRepo,
        private readonly ProductService                     $productService,
        private readonly PublishingHouseRepositoryInterface $publishingHouseRepo,
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
        $filterWhereIn = ProductManager::getSortFilterWhereInArrays(request: $request);
        $vendorId = auth('seller')->id();
        $vendor = $this->vendorRepo->getFirstWhere(params: ['id' => $vendorId]);
        $getPOSStatus = getWebConfig('seller_pos');
        if ($vendor['pos_status'] == 0 || $getPOSStatus == 0) {
            ToastMagic::warning(translate('access_denied!!'));
        }
        $productType = $request->input('product_type', 'physical');
        $shop = $this->shopRepo->getFirstWhere(params: ['id' => $vendorId]);
        $filter = ProductManager::getPosSearchFilterArray(request: $request);
        $filter['added_by'] = 'seller';
        $filter['seller_id'] = $vendorId;
        $searchValue = $request['searchValue'] ?? null;
        if (!empty($searchValue)) {
            $allSearchResults = $this->productRepo->getWebListWithScope(searchValue: $searchValue, scope: 'active', filters: $filter, whereIn: $filterWhereIn, dataLimit: getWebConfig('pagination_limit'));
            if ($allSearchResults->count() > 0) {
                $firstProductType = $allSearchResults->first()?->product_type;
                if (!$request->has('product_type')) {
                    $productType = $firstProductType;
                } else {
                    $hasResultsInRequestedType = $allSearchResults->contains('product_type', $productType);
                    if (!$hasResultsInRequestedType) {
                        $productType = $firstProductType;
                    }
                }
            }
        }
        $filter['product_type'] = $productType ?? 'physical';

        $products = $this->productRepo->getWebListWithScope(
            searchValue: $searchValue,
            scope: 'active',
            filters: $filter,
            whereIn: $filterWhereIn,
            relations: [
                'clearanceSale' => function ($query) {
                    return $query->active();
                }
            ], dataLimit: getWebConfig('pagination_limit')
        );
        $cartId = 'walk-in-customer-' . rand(10, 1000);
        $this->cartService->getNewCartSession(cartId: $cartId);
        $customers = $this->customerRepo->getListWhereNotIn(ids: [0]);
        $getCurrentCustomerData = $this->getCustomerDataFromSessionForPOS();
        $summaryData = array_merge($this->POSService->getSummaryData(), $getCurrentCustomerData);
        $cartItems = $this->getCartData(cartName: session(SessionKey::CURRENT_USER));
        $order = $this->orderRepo->getFirstWhere(params: ['id' => session(SessionKey::LAST_ORDER)]);
        $totalHoldOrder = $summaryData['totalHoldOrders'];
        $countries = getWebConfig(name: 'delivery_country_restriction') ? $this->get_delivery_country_array() : COUNTRIES;
        $zipCodes = getWebConfig(name: 'delivery_zip_code_area_restriction') ? $this->deliveryZipCodeRepo->getListWhere(dataLimit: 'all') : 0;
        $productCounts = $this->cartService->getProductCounts();
        $currentCustomerSessionInfo = session(session(SessionKey::CURRENT_USER));

        $productBrands = $this->brandRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            dataLimit: 'all'
        );

        $productCategories = $this->categoryRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            filters: ['position' => 0],
            relations: ['childes.childes'],
            dataLimit: 'all'
        );

        return view('vendor-views.pos.index', compact(
            'products',
            'cartId',
            'customers',
            'shop',
            'searchValue',
            'summaryData',
            'cartItems',
            'order',
            'totalHoldOrder',
            'countries',
            'zipCodes',
            'productType',
            'currentCustomerSessionInfo',
            'productCounts',
            'productBrands',
            'productCategories',
        ));
    }

    /**
     * @param Request $request
     * @return JsonResponse
     * @throws Throwable
     */
    public function changeCustomer(Request $request): JsonResponse
    {
        $cartId = ($request['user_id'] != 0 ? 'saved-customer-' . $request['user_id'] : 'walk-in-customer-' . rand(10, 1000));
        $this->POSService->UpdateSessionWhenCustomerChange(cartId: $cartId);
        $getCurrentCustomerData = $this->getCustomerDataFromSessionForPOS();
        $summaryData = array_merge($this->POSService->getSummaryData(), $getCurrentCustomerData);
        $cartItems = $this->getCartData(cartName: $cartId);
        return response()->json([
            'view' => view('vendor-views.pos.partials._vendor-pos-customer-info', compact('summaryData', 'cartItems'))->render(),
            'cart_view' => view('admin-views.pos.partials._cart', compact('cartId', 'cartItems'))->render()
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     * @throws Throwable
     */
    public function updateDiscount(Request $request): JsonResponse
    {
        $cartId = session(SessionKey::CURRENT_USER);
        if ($request['type'] == 'percent' && ($request['discount'] < 0 || $request['discount'] > 100)) {
            $cartItems = $this->getCartData(cartName: $cartId);
            $text = $request['discount'] > 0 ? 'Extra_discount_can_not_be_less_than_0_percent' :
                'Extra_discount_can_not_be_more_than_100_percent';
            ToastMagic::error(translate($text));
            return response()->json([
                'extraDiscount' => "amount_low",
                'view' => view(POS::CART[VIEW], compact('cartId', 'cartItems'))->render()
            ]);
        }
        $cart = session($cartId, collect());
        if ($cart) {
            $totalProductPrice = 0;
            $productDiscount = 0;
            $couponDiscount = $cart['coupon_discount'] ?? 0;

            foreach ($cart as $item) {
                if (is_array($item)) {
                    $product = $this->productRepo->getFirstWhere(params: ['id' => $item['id']], relations: ['clearanceSale' => function ($query) {
                        return $query->active();
                    }]);
                    $totalProductPrice += $item['price'] * $item['quantity'];
                    $productDiscount += $item['discount'] * $item['quantity'];
                }
            }
            if ($request['type'] == 'percent') {
                $extraDiscount = (($totalProductPrice - $productDiscount - $couponDiscount) / 100) * $request['discount'];
            } else {
                $extraDiscount = currencyConverter(amount: $request['discount']);
            }
            $total = $totalProductPrice - $productDiscount - $couponDiscount - $extraDiscount;
            if ($total < 0) {
                $cartItems = $this->getCartData(cartName: $cartId);
                return response()->json([
                    'extraDiscount' => "amount_low",
                    'view' => view(POS::CART[VIEW], compact('cartId', 'cartItems'))->render()
                ]);
            } else {
                $cart['ext_discount'] = $request['type'] == 'percent' ? $request['discount'] : currencyConverter(amount: $request['discount']);
                $cart['ext_discount_type'] = $request['type'];
                session()->put($cartId, $cart);
                $cartItems = $this->getCartData(cartName: $cartId);
                return response()->json([
                    'extraDiscountAmount' => $extraDiscount ?? 0,
                    'extraDiscount' => "success",
                    'view' => view(POS::CART[VIEW], compact('cartId', 'cartItems'))->render()
                ]);
            }
        } else {
            $cartItems = $this->getCartData(cartName: $cartId);
            return response()->json([
                'extraDiscount' => "empty",
                'view' => view(POS::CART[VIEW], compact('cartId', 'cartItems'))->render()
            ]);
        }
    }

    /**
     * @param Request $request
     * @return JsonResponse
     * @throws Throwable
     */
    public function getCouponDiscount(Request $request): JsonResponse
    {
        $cartId = session(SessionKey::CURRENT_USER);
        $userId = $this->cartService->getUserId();
        if ($userId != 0) {
            $usedCoupon = $this->orderRepo->getListWhere(filters: ['customer_type' => 'customer', 'coupon_code' => $request['coupon_code'], 'customer_id' => $userId])->count();
            $coupon = $this->couponRepo->getFirstWhereFilters(
                filters: [
                    'code' => $request['coupon_code'],
                    'coupon_bearer' => 'seller',
                    'limit' => $usedCoupon,
                    'start_date' => now(),
                    'expire_date' => now(),
                    'status' => 1
                ]
            );

        } else {
            $coupon = $this->couponRepo->getFirstWhereFilters(
                filters: [
                    'code' => $request['coupon_code'],
                    'coupon_bearer' => 'seller',
                    'start_date' => now(),
                    'expire_date' => now(),
                    'status' => 1
                ]
            );
        }
        if (!$coupon || $coupon['coupon_type'] == 'free_delivery' || $coupon['coupon_type'] == 'first_order') {
            $cartItems = $this->getCartData(cartName: $cartId);
            return response()->json([
                'coupon' => 'coupon_invalid',
                'view' => view(POS::CART[VIEW], compact('cartId', 'cartItems'))->render()
            ]);
        }

        $carts = session($cartId);
        $totalProductPrice = 0;
        $productDiscount = 0;

        if (($coupon['seller_id'] == '0' || $coupon['seller_id'] == auth('seller')->id()) && ($coupon['customer_id'] == '0' || $coupon['customer_id'] == $userId)) {
            if ($carts != null) {
                foreach ($carts as $cart) {
                    if (is_array($cart)) {
                        $product = $this->productRepo->getFirstWhere(params: ['id' => $cart['id']], relations: ['clearanceSale' => function ($query) {
                            return $query->active();
                        }]);
                        $totalProductPrice += $cart['price'] * $cart['quantity'];
                        $productDiscount += $cart['discount'] * $cart['quantity'];
                    }
                }
                if ($totalProductPrice >= $coupon['min_purchase']) {
                    $calculation = $this->POSService->getCouponCalculation(coupon: $coupon, totalProductPrice: $totalProductPrice, productDiscount: $productDiscount);
                    $couponDiscount = $calculation['discount'];

                    $extraDiscount = 0;
                    if (isset($carts['ext_discount_type']) && isset($carts['ext_discount'])) {
                        if ($carts['ext_discount_type'] == 'percent') {
                            $extraDiscount = (($totalProductPrice - $productDiscount - $couponDiscount) / 100) * $carts['ext_discount'];
                        } else {
                            $extraDiscount = $carts['ext_discount'];
                        }
                    }

                    $total = $totalProductPrice - $productDiscount - $couponDiscount - $extraDiscount;
                    if ($total < 0) {
                        $cartItems = $this->getCartData(cartName: $cartId);
                        return response()->json([
                            'coupon' => "amount_low",
                            'view' => view(POS::CART[VIEW], compact('cartId', 'cartItems'))->render()
                        ]);
                    }

                    $this->POSService->putCouponDataOnSession(
                        cartId: $cartId,
                        discount: $couponDiscount,
                        couponTitle: $coupon['title'],
                        couponBearer: $coupon['coupon_bearer'],
                        couponCode: $request['coupon_code']
                    );
                    $cartItems = $this->getCartData(cartName: $cartId);
                    return response()->json([
                        'coupon' => 'success',
                        'view' => view(POS::CART[VIEW], compact('cartId', 'cartItems'))->render()
                    ]);
                }
            } else {
                $cartItems = $this->getCartData(cartName: $cartId);
                return response()->json([
                    'coupon' => 'cart_empty',
                    'view' => view(POS::CART[VIEW], compact('cartId', 'cartItems'))->render()
                ]);
            }
        }
        $cartItems = $this->getCartData(cartName: $cartId);
        return response()->json([
            'coupon' => 'coupon_invalid',
            'view' => view(POS::CART[VIEW], compact('cartId', 'cartItems'))->render()
        ]);
    }

    public function getQuickView(Request $request): JsonResponse
    {
        $product = $this->productRepo->getFirstWhereWithCount(
            params: ['id' => $request['product_id']],
            withCount: ['reviews'],
            relations: ['brand', 'category', 'rating', 'tags', 'digitalVariation', 'digitalProductAuthors.author', 'clearanceSale' => function ($query) {
                return $query->active();
            }],
        );
        $cartId = $this->cartService->ensureCartSession();
        $cartItems = $this->getCartData(cartName: $cartId);
        $productSubtotal = $cartItems['productSubtotal'] ?? 0;
        $productAuthorIds = $this->productService->getProductAuthorsInfo(product: $product)['ids'];
        $digitalProductAuthors = $this->authorRepo->getListWhere(dataLimit: 'all');
        $productPublishingHouseIds = $this->productService->getProductPublishingHouseInfo(product: $product)['ids'];
        $publishingHouseRepo = $this->publishingHouseRepo->getListWhere(dataLimit: 'all');
        return response()->json([
            'success' => 1,
            'view' => view(POS::QUICK_VIEW[VIEW], compact('product', 'digitalProductAuthors', 'productAuthorIds', 'productPublishingHouseIds', 'publishingHouseRepo','productSubtotal'))->render(),
        ]);
    }

    /**
     * @return array
     */
    protected function getCustomerDataFromSessionForPOS(): array
    {
        if (Str::contains(session(SessionKey::CURRENT_USER), 'walk-in-customer')) {
            $currentCustomerInfo = ['customerName' => 'Walk-In Customer'];
            $currentCustomerData = $this->customerRepo->getFirstWhere(params: ['id' => '0']);
        } else {
            $userId = explode('-', session(SessionKey::CURRENT_USER))[2];
            $currentCustomerData = $this->customerRepo->getFirstWhere(params: ['id' => $userId]);
            $currentCustomerInfo = $this->cartService->getCustomerInfo(currentCustomerData: $currentCustomerData, customerId: $userId);
        }
        return [
            'currentCustomer' => $currentCustomerInfo['customerName'],
            'currentCustomerData' => $currentCustomerData
        ];
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
            'subtotal' => 0,
            'discountOnProduct' => 0,
            'productSubtotal' => 0,
            'totalTax' => 0,
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
                            $cartItemValue[] = $cartItem;
                            $subTotalCalculation['customerOnHold'] = $cartItem['customerOnHold'];

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
            'customerOnHold' => $subTotalCalculation['customerOnHold'] ?? false,
            'couponDiscount' => $totalCalculation['couponDiscount'],
            'extraDiscount' => $totalCalculation['extraDiscount'],
        ];
    }

    protected function getCartData(string $cartName): array
    {
        $customerCartData = $this->getCustomerCartData(cartName: $cartName);
        $cartItemData = $this->calculateCartItemsData(cartName: $cartName, customerCartData: $customerCartData);
        return array_merge($customerCartData[$cartName], $cartItemData);
    }

    public function getSearchedProductsView(Request $request): JsonResponse
    {

        $products = $this->productRepo->getListWithScope(
            scope: 'active',
            filters: [
                'added_by' => 'seller',
                'seller_id' => auth('seller')->id(),
                'keywords' => $request['name'],
                'search_from' => 'pos',
                'status' => 1
            ],
            relations: ['clearanceSale' => function ($query) {
                return $query->active();
            }],
            dataLimit: 'all'
        );

        $data = [
            'count' => $products->count(),
            'result' => view(POS::SEARCH[VIEW], compact('products'))->render()
        ];
        if ($products->count() > 0) {
            $data += ['id' => $products[0]->id];
        }

        return response()->json($data);
    }
}
