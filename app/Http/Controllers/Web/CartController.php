<?php

namespace App\Http\Controllers\Web;


use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\RestockProductCustomerRepositoryInterface;
use App\Contracts\Repositories\RestockProductRepositoryInterface;
use App\Events\RequestProductRestockEvent;
use App\Models\BusinessSetting;
use App\Models\CartShipping;
use App\Models\DigitalProductVariation;
use App\Models\RestockProductCustomer;
use App\Models\Shop;
use App\Models\Wishlist;
use App\Services\RestockProductService;
use App\Utils\CustomerManager;
use App\Utils\Helpers;
use App\Http\Controllers\Controller;
use App\Services\Analytics\Analytics;
use App\Models\Cart;
use App\Models\Color;
use App\Models\OrderDetail;
use App\Models\Product;
use App\Utils\CartManager;
use App\Utils\OrderManager;
use App\Utils\ProductManager;
use Brian2694\Toastr\Facades\Toastr;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CartController extends Controller
{
    public function __construct(
        private readonly OrderDetail                               $order_details,
        private readonly Product                                   $product,
        private readonly Wishlist                                  $wishlist,
        private readonly RestockProductService                     $restockProductService,
        private readonly RestockProductRepositoryInterface         $restockProductRepo,
        private readonly ProductRepositoryInterface                $productRepo,
        private readonly RestockProductCustomerRepositoryInterface $restockProductCustomerRepo,
    )
    {
    }

    public function getVariantPrice(Request $request): array
    {
        // This endpoint fires on every option click. It used to null-deref on a
        // missing product id, an unknown colour code, or NULL choice_options -
        // and the storefront's error handler is silent, so shoppers just saw a
        // stale price. Bad input now fails loudly instead of 500ing quietly.
        $request->validate(['id' => 'required|integer']);

        $string = '';
        $quantity = 0;
        $price = 0;
        $unit_price = 0;
        $discount = 0;
        $discountedUnitPrice = 0;
        $color_name = '';
        $requestQuantity = $request['quantity'];
        $product = Product::with(['digitalVariation', 'clearanceSale' => function ($query) {
            return $query->active();
        }])->where(['id' => $request['id']])->first();
        abort_if(!$product, 404);
        $productVariationCode = $request['product_variation_code'];

        if ($request->has('color')) {
            $string = Color::where('code', $request['color'])->first()?->name ?? '';
        }

        foreach (json_decode($product->choice_options) ?? [] as $choice) {
            if ($string != null) {
                $string .= '-' . str_replace(' ', '', $request[$choice->name]);
            } else {
                $string .= str_replace(' ', '', $request[$choice->name]);
            }
        }

        $requestQuantity = $productVariationCode != $string ? $product['minimum_order_qty'] : $request['quantity'];
        $inCartExistStatus = 0;
        $inCartExistKey = null;
        $getCartList = CartManager::getCartListQuery();
        foreach ($getCartList as $cartItem) {
            if ($cartItem['product_id'] == $product['id'] && $cartItem['variant'] == $string) {
                $inCartExistStatus = 1;
                $inCartExistKey = $cartItem['id'];
                $requestQuantity = $productVariationCode == $string ? $request['quantity'] : $cartItem['quantity'];
            }

            if ($product['product_type'] == 'digital' && $request['variant_key'] && $cartItem['variant'] == $request['variant_key']) {
                $inCartExistStatus = 1;
                $inCartExistKey = $cartItem['id'];
                $requestQuantity = $productVariationCode == $request['variant_key'] ? $request['quantity'] : $cartItem['quantity'];
            }
        }


        if ($string != null) {
            // Decoded once; the old loop re-decoded the variation JSON six
            // times per matching iteration.
            foreach (json_decode($product->variation) ?? [] as $productVariation) {
                if ($productVariation->type == $string) {
                    $discount = getProductPriceByType(product: $product, type: 'discounted_amount', result: 'value', price: $productVariation->price);
                    $price = $productVariation->price - $discount;
                    $discountedUnitPrice = $productVariation->price - $discount;
                    $unit_price = $productVariation->price;
                    $quantity = $productVariation->qty;
                }
            }
        } else {
            $discount = getProductPriceByType(product: $product, type: 'discounted_amount', result: 'value', price: $product->unit_price);
            $price = $product->unit_price - $discount;
            $discountedUnitPrice = $product->unit_price - $discount;
            $unit_price = $product->unit_price;
            $quantity = $product->current_stock;
        }

        $digitalVariation = DigitalProductVariation::where(['product_id' => $product['id'], 'variant_key' => $request['variant_key']])->first();
        if ($product['product_type'] == 'digital' && $digitalVariation) {
            $discount = getProductPriceByType(product: $product, type: 'discounted_amount', result: 'value', price: $digitalVariation['price']);
            $price = $digitalVariation['price'] - $discount;
            $discountedUnitPrice = $digitalVariation['price'] - $discount;
            $unit_price = $digitalVariation['price'];
            $quantity = $digitalVariation['price'];

            foreach ($getCartList as $cartItem) {
                if ($product['product_type'] == 'digital' && $cartItem['variant'] == $request['variant_key']) {
                    $string = $cartItem['variant'];
                }
            }
        }

        $deliveryInfo = [];
        $stock_limit = getWebConfig(name: 'stock_limit');

        if ($request->has('color')) {
            $color_name = Color::where(['code' => $request->color])->first()?->name ?? '';
        }

        $restockRequestStatus = 0;
        if (auth('customer')->check()) {
            $restockRequestStatus = (int)($this->restockProductRepo->getListWhere(filters: [
                    'customer_id' => auth('customer')->id(),
                    'product_id' => $product['id'],
                    'variant' => $string,
                ])->count() > 0);
        }

        $discountType = getProductPriceByType(product: $product, type: 'discount_type', result: 'string');

        return [
            'price' => webCurrencyConverter($price * $requestQuantity),
            'discount' => $discountType == 'flat' ? webCurrencyConverter($discount) : getProductPriceByType(product: $product, type: 'discount', result: 'value') . '%',
            'discount_type' => $discountType,
            'discount_amount' => $discount,
            'quantity' => $product['product_type'] == 'physical' ? $quantity : 100,
            'delivery_cost' => isset($deliveryInfo['delivery_cost']) ? webCurrencyConverter($deliveryInfo['delivery_cost']) : 0,
            'unit_price' => webCurrencyConverter($price), //fashion theme
            'total_unit_price' => webCurrencyConverter($unit_price), //fashion theme
            'discounted_unit_price' => webCurrencyConverter($discountedUnitPrice), //fashion theme
            'color_name' => $color_name,
            'stock_limit' => $stock_limit,

            'in_cart_status' => $inCartExistStatus,
            'in_cart_quantity' => $requestQuantity,
            'in_cart_key' => $inCartExistKey,
            'variation_code' => $string,
            'product_type' => $product['product_type'],
            'restock_request_status' => $restockRequestStatus,
        ];
    }


    public function addToCart(Request $request): JsonResponse|RedirectResponse
    {
        $cart = CartManager::add_to_cart($request);
        if ($cart['status'] == 2) {
            $cart['shippingMethodHtmlView'] = view(VIEW_FILE_NAMES['product_shipping_method_modal_view_partials'], [
                'shipping_method_list' => $cart['shipping_method_list'],
                'productData' => $request->all(),
            ])->render();
        }
        session()->forget('coupon_code');
        session()->forget('coupon_type');
        session()->forget('coupon_bearer');
        session()->forget('coupon_discount');
        session()->forget('coupon_seller_id');

        if (isset($cart['redirect_to']) && $cart['redirect_to'] == 'checkout') {
            $cart['redirect_to_url'] = route('checkout-details');
            return request()->ajax() ? response()->json($cart) : redirect()->route('checkout-details');
        }

        if (!request()->ajax() && $cart['status'] == 0) {
            Toastr::warning($cart['message']);
            return back();
        }
        return response()->json($cart);

    }

    public function addAllToCartFromWishtList(Request $request): JsonResponse
    {
        foreach ($request->items['data'] as $wishlist) {
            $product = $wishlist['product_full_info'];
            $cartData = [
                'id' => $wishlist['product_id'],
                'quantity' => $product['minimum_order_qty'] ?? 1,
                'product_variation_code' => $wishlist['product_id'],
                'key' => null
            ];
            $miniRequest = new Request($cartData);
            CartManager::add_to_cart($miniRequest);
        }
        return response()->json([
            'success' => true,
            'message' => translate('All wishlist items have been added to cart')
        ]);
    }


    public function updateNavCart(): JsonResponse
    {
        return response()->json(['data' => view(VIEW_FILE_NAMES['products_cart_partials'])->render(), 'mobile_nav' => view(VIEW_FILE_NAMES['products_mobile_nav_partials'])->render()]);
    }

    /**
     * For theme fashion floating nav
     */
    public function update_floating_nav(): JsonResponse
    {
        return response()->json(['floating_nav' => view(VIEW_FILE_NAMES['products_floating_nav_partials'])->render()]);
    }

    /**
     * removes from Cart
     */
    public function removeFromCart(Request $request): JsonResponse
    {
        $user = Helpers::getCustomerInformation();

        $owner = ($user == 'offline' ? session('guest_id') : auth('customer')->id());
        // Read before the delete, because after it there is nothing left to say what was removed.
        $removed = Cart::where(['id' => $request->key, 'customer_id' => $owner])->first(['product_id', 'quantity', 'price']);

        Cart::where(['id' => $request->key, 'customer_id' => $owner])->delete();

        if ($removed !== null) {
            app(Analytics::class)->cartRemoved(
                productId: (int) $removed->product_id,
                quantity: (int) $removed->quantity,
                value: (float) $removed->price * (int) $removed->quantity,
            );
        }

        session()->forget('coupon_code');
        session()->forget('coupon_type');
        session()->forget('coupon_bearer');
        session()->forget('coupon_discount');
        session()->forget('coupon_seller_id');
        session()->forget('shipping_method_id');
        session()->forget('order_note');

        $cart = Cart::where(['customer_id' => ($user == 'offline' ? session('guest_id') : auth('customer')->id())])->select(['id', 'variant'])->get();

        return response()->json([
            'data' => view(VIEW_FILE_NAMES['products_cart_details_partials'], compact('request'))->render(),
            'message' => translate('Item_has_been_removed_from_cart'),
            'cartList' => $cart,
        ]);
    }

    //updated the quantity for a cart item
    public function updateQuantity(Request $request)
    {
        $response = CartManager::update_cart_qty($request);

        session()->forget('coupon_code');
        session()->forget('coupon_type');
        session()->forget('coupon_bearer');
        session()->forget('coupon_discount');
        session()->forget('coupon_seller_id');

        if ($response['status'] == 0) {
            return response()->json($response);
        }
        return response()->json(view(VIEW_FILE_NAMES['products_cart_details_partials'], compact('request'))->render());
    }

    // Updated the quantity for a cart item
    public function updateQuantity_guest(Request $request): JsonResponse
    {
        $sub_total = 0;
        $user = Helpers::getCustomerInformation($request);
        $response = CartManager::update_cart_qty($request);
        $cart = CartManager::getCartListQuery();
        session()->forget('coupon_code');
        session()->forget('coupon_type');
        session()->forget('coupon_bearer');
        session()->forget('coupon_discount');
        session()->forget('coupon_seller_id');

        // Scoped to the requester: an unscoped find() would read a row belonging to somebody else
        // whenever a client sends an arbitrary key, even though update_cart_qty refuses the write.
        $product = Cart::where([
            'id' => $request['key'],
            'customer_id' => ($user == 'offline' ? (session('guest_id') ?? $request['guest_id']) : $user['id']),
        ])->first();

        if (!$product) {
            return response()->json([
                'status' => 0,
                'qty' => $request['quantity'],
                'message' => translate('Product_not_found_in_cart'),
            ]);
        }

        $quantity_price = webCurrencyConverter($product['price'] * (int)$product['quantity']);
        $discount_price = webCurrencyConverter(($product['price'] - $product['discount']) * (int)$product['quantity']);
        $total_discount = 0;
        foreach ($cart as $cartItem) {
            $sub_total += ($cartItem['price'] - $cartItem['discount']) * $cartItem['quantity'];
            $total_discount += $cartItem['discount'] * $cartItem['quantity'];
        }
        $total_price = webCurrencyConverter($sub_total);
        $total_discount_price = webCurrencyConverter($total_discount);

        if ($response['status'] == 0) {
            return response()->json([
                'status' => $response['status'],
                'message' => $response['message'],
                'qty' => $response['status'] == 0 ? $response['qty'] : $request->quantity,
            ]);
        }
        /** for default theme nav cart ,showing free delivery amount */
        // getCartListQuery() drops lines whose product is no longer active, so the collection can be
        // empty here even though the row was just updated — $cart[0] then fataled the whole response.
        // getFreeDeliveryOrderAmountArray() already treats a null group as "no free delivery", so
        // passing it through keeps the exact response shape the storefront JS reads.
        $free_delivery_status = OrderManager::getFreeDeliveryOrderAmountArray($cart->first()?->cart_group_id);

        return response()->json([
            'status' => $response['status'],
            'message' => translate('successfully_updated!'),
            'qty' => $response['status'] == 0 ? $response['qty'] : $request->quantity,
            'total_price' => $total_price,
            'discount_price' => $discount_price,
            'quantity_price' => $quantity_price,
            'total_discount_price' => $total_discount_price,
            'free_delivery_status' => $free_delivery_status,
            'in_cart_key' => $product['id'],
        ]);
    }

    public function orderAgain(Request $request): JsonResponse
    {
        $orderDetails = OrderDetail::with('order')->where('order_id', $request['order_id'])->first();

        $vendorType = $orderDetails?->order?->seller_is == "seller" ? 'vendor' : 'inhouse';
        $vendorInfo = $orderDetails?->order?->seller?->shop ?? null;

        $temporaryClose = checkVendorAbility(type: $vendorType, status: 'temporary_close', vendor: $vendorInfo);
        $vacationStatus = checkVendorAbility(type: $vendorType, status: 'vacation_status', vendor: $vendorInfo);

        if ($temporaryClose || $vacationStatus) {
            $message = translate('this_shop_is_temporary_closed_or_on_vacation.') . ' ' . translate('you_cannot_add_product_to_cart_from_this_shop_for_now');
            if (auth('customer')->check()) {
                return response()->json(['status' => 0, 'message' => $message], 200);
            } else {
                return response()->json(['message' => $message], 403);
            }
        }

        $data = OrderManager::generateOrderAgain($request);
        $orderProductCount = $data['order_product_count'];
        $addToCartCount = $data['add_to_cart_count'];
        $failedAddToCartCount = $data['failedAddToCartCount'];
        $message = $failedAddToCartCount == 0 ? translate('added_to_cart_successfully!') : translate('Some_items_were_not_added_to_your_cart_because_they_are_currently_unavailable_for_purchase.!');

        if ($orderProductCount == $addToCartCount) {
            session()->forget('coupon_code');
            session()->forget('coupon_type');
            session()->forget('coupon_bearer');
            session()->forget('coupon_discount');
            session()->forget('coupon_seller_id');
            session()->forget('shipping_method_id');
            session()->forget('order_note');

            if (auth('customer')->check()) {
                return response()->json([
                    'status' => 1,
                    'redirect_url' => route('shop-cart'),
                    'message' => $message,
                    'errors' => $data['errorMessages'] ?? []
                ], 200);
            } else {
                return response()->json(['message' => $message], 200);
            }
        } elseif ($addToCartCount > 0) {
            if (auth('customer')->check()) {
                return response()->json([
                    'status' => 1,
                    'redirect_url' => route('shop-cart'),
                    'message' => $message,
                    'errors' => $data['errorMessages'] ?? []
                ], 200);
            } else {
                return response()->json(['message' => $message], 200);
            }
        } else {
            if (auth('customer')->check()) {
                return response()->json([
                    'status' => 0,
                    'message' => translate('all_items_were_not_added_to_cart_as_they_are_currently_unavailable_for_purchase!')
                ], 200);
            } else {
                return response()->json([
                    'message' => translate('all_items_were_not_added_to_cart_as_they_are_currently_unavailable_for_purchase!')
                ], 403);
            }
        }
    }

    function addToCartPhysicalProduct($request, $product)
    {
        $user = Helpers::getCustomerInformation($request);
        $str = '';
        $variations = [];
        $price = 0;
        $discount = 0;
        if ($request->has('color')) {
            $str = Color::where('code', $request['color'])->first()->name;
            $variations['color'] = $str;
        }

        //Gets all the choice values of customer choice option and generate a string like Black-S-Cotton
        $choices = [];
        foreach (json_decode($product->choice_options) as $key => $choice) {
            $choices[$choice->name] = $request[$choice->name];
            $variations[$choice->title] = $request[$choice->name];
            if ($str != null) {
                $str .= '-' . str_replace(' ', '', $request[$choice->name]);
            } else {
                $str .= str_replace(' ', '', $request[$choice->name]);
            }
        }

        if ($str != null) {
            $count = count(json_decode($product->variation));
            for ($i = 0; $i < $count; $i++) {
                if (json_decode($product->variation)[$i]->type == $str) {
                    $discount = Helpers::getProductDiscount($product, json_decode($product->variation)[$i]->price);
                    $price = json_decode($product->variation)[$i]->price - $discount;
                    $quantity = json_decode($product->variation)[$i]->qty;
                }
            }
        } else {
            $discount = Helpers::getProductDiscount($product, $product->unit_price);
            $price = $product->unit_price - $discount;
            $quantity = $product->current_stock;
        }

        $cart = Cart::where([
            'product_id' => $request['product_id'],
            'customer_id' => $user == 'offline' ? session('guest_id') : $user->id,
            'is_guest' => $user == 'offline' ? 1 : '0',
            'variant' => $str
        ])->first();

        if (isset($cart) == false) {
            $cart = Cart::find($request->id);
            $cart['color'] = $request->has('color') ? $request['color'] : null;
            $cart['choices'] = json_encode($choices);

            $cart['variations'] = json_encode($variations);
            $cart['variant'] = $str;

            //Check the string and decreases quantity for the stock
            if ($str != null) {
                $count = count(json_decode($product->variation));
                for ($i = 0; $i < $count; $i++) {
                    if (json_decode($product->variation)[$i]->type == $str) {
                        $price = json_decode($product->variation)[$i]->price;
                        if (json_decode($product->variation)[$i]->qty < $request['quantity']) {
                            return [
                                'status' => 0,
                                'message' => translate('out_of_stock') . '!'
                            ];
                        }
                    }
                }
            } else {
                $price = $product->unit_price;
            }

            $cart['price'] = $price;
            $cart['discount'] = $discount;
            $cart['quantity'] = $request['quantity'];
            $cart->save();

            return [
                'status' => 1,
                'message' => translate('successfully_added') . '!',
                'price' => webCurrencyConverter($price),
                'discount' => webCurrencyConverter($discount * $request['quantity']),
                'data' => view(VIEW_FILE_NAMES['products_cart_details_partials'], compact('request'))->render()
            ];
        } else {
            return [
                'status' => 0,
                'message' => translate('already_added') . '!'
            ];
        }
    }

    function addToCartDigitalProduct($request, $product): array
    {
        $price = $product->unit_price;
        $digitalVariation = DigitalProductVariation::where(['product_id' => $product['id'], 'variant_key' => $request['variant_key']])->first();
        if ($request['variant_key'] && $digitalVariation) {
            $price = $digitalVariation['price'];
        }
        $user = Helpers::getCustomerInformation($request);
        $guestId = session('guest_id') ?? ($request->guest_id ?? 0);

        if ($user == 'offline') {
            $customerId = $guestId;
            $isGuest = 1;
        } else {
            $customerId = $user->id;
            $isGuest = 0;
        }

        $getProductDiscount = Helpers::getProductDiscount($product, $price);
        $cartArray = [
            'customer_id' => $customerId,
            'product_id' => $product['id'],
            'product_type' => $product['product_type'],
            'digital_product_type' => $product['digital_product_type'],
            'choices' => json_encode([]),
            'variations' => json_encode([]),
            'variant' => $request['variant_key'],
            'quantity' => $request['quantity'],
            'price' => $price,
            'discount' => $getProductDiscount,
            'is_checked' => 1,
            'slug' => $product['slug'],
            'name' => $product['name'],
            'thumbnail' => $product['thumbnail'],
            'seller_id' => ($product->added_by == 'admin') ? 1 : $product->user_id,
            'seller_is' => $product['added_by'],
            'created_at' => now(),
            'updated_at' => now(),
            'shop_info' => $product->added_by == 'admin' ? getInHouseShopConfig(key: 'name') : Shop::where(['seller_id' => $product->user_id])->first()->name,
            'shipping_cost' => $product['product_type'] == 'physical' ? CartManager::get_shipping_cost_for_product_category_wise($product, $request['quantity']) : 0,
            'is_guest' => $isGuest,
        ];

        $cart = Cart::where([
            'id' => $request['id'],
            'product_id' => $request['product_id'],
            'customer_id' => $user == 'offline' ? session('guest_id') : $user->id,
            'is_guest' => $user == 'offline' ? 1 : '0',
            'variant' => $request['current_variant_key']
        ])->first();

        if (isset($cart)) {
            Cart::where(['id' => $cart['id']])->update($cartArray);
            return [
                'status' => 1,
                'message' => translate('successfully_update!'),
                'price' => webCurrencyConverter($price),
                'discount' => webCurrencyConverter($getProductDiscount),
                'data' => view(VIEW_FILE_NAMES['products_cart_details_partials'], compact('request'))->render()
            ];
        } else {
            Cart::insertGetId($cartArray);
            return [
                'status' => 1,
                'message' => translate('successfully_added') . '!',
                'price' => webCurrencyConverter($price),
                'discount' => webCurrencyConverter($getProductDiscount),
                'data' => view(VIEW_FILE_NAMES['products_cart_details_partials'], compact('request'))->render()
            ];
        }
    }

    function update_variation(Request $request)
    {
        $product = Product::where(['id' => $request['product_id']])->first();
        if ($product['product_type'] == 'digital') {
            return self::addToCartDigitalProduct($request, $product);
        } else {
            return self::addToCartPhysicalProduct($request, $product);
        }
    }

    public function remove_all_cart()
    {
        $user = Helpers::getCustomerInformation();

        Cart::where([
            'customer_id' => ($user == 'offline' ? session('guest_id') : auth('customer')->id()),
            'is_guest' => ($user == 'offline' ? 1 : '0'),
        ])->delete();
        return redirect()->back();
    }


    public function updateCheckedCartItems(Request $request): JsonResponse
    {
        $user = Helpers::getCustomerInformation();
        Cart::where([
            'customer_id' => ($user == 'offline' ? session('guest_id') : auth('customer')->id()),
            'is_guest' => ($user == 'offline' ? 1 : '0'),
        ])->update(['is_checked' => 0]);

        if ($request['ids']) {
            Cart::where([
                'customer_id' => ($user == 'offline' ? session('guest_id') : auth('customer')->id()),
                'is_guest' => ($user == 'offline' ? 1 : '0'),
            ])->whereIn('id', $request['ids'])->update(['is_checked' => 1]);
        }

        $cartGroupIds = Cart::where([
            'customer_id' => ($user == 'offline' ? session('guest_id') : auth('customer')->id()),
            'is_guest' => ($user == 'offline' ? 1 : '0'),
        ])->pluck('cart_group_id');

        if (count($cartGroupIds) > 0) {
            CartShipping::whereIn('cart_group_id', $cartGroupIds)->delete();
        }

        session()->forget('coupon_code');
        session()->forget('coupon_type');
        session()->forget('coupon_bearer');
        session()->forget('coupon_discount');

        return response()->json([
            'message' => translate('Successfully_Update'),
            'htmlView' => view(VIEW_FILE_NAMES['products_cart_details_partials'], compact('request'))->render()
        ], 200);
    }

    public function addProductRestockRequest(Request $request): JsonResponse
    {
        $user = Helpers::getCustomerInformation();
        $product = $this->productRepo->getWebFirstWhereActive(params: ['id' => $request['id']]);
        $restockRequest = $this->restockProductRepo->updateOrCreate(params: ['product_id' => $request['id'], 'variant' => $request['product_variation_code']], value: [
            'product_id' => $request['id'],
            'variant' => $request['product_variation_code'],
        ]);
        $data = $this->restockProductService->getProductRestockRequestAddData(request: $request, restockRequest: $restockRequest);
        $checkRequest = $this->restockProductCustomerRepo->getFirstWhere(params: $data);
        if ($checkRequest) {
            return response()->json([
                'status' => 'warning',
                'message' => translate('Already_Requested'),
            ], 200);
        }

        $this->restockProductCustomerRepo->updateOrCreate(params: $data, value: $data);
        $this->restockProductRepo->updateByParams(params: ['id' => $restockRequest['id']], data: ['updated_at' => Carbon::now()]);
        CustomerManager::updateCustomerSessionData(userId: auth('customer')->id());

        // event(new RequestProductRestockEvent(key: 'message_from_customer', product: $product, restockRequest: $restockRequest));

        return response()->json([
            'status' => 'success',
            'message' => translate('Request_sent_successfully'),
            'fcm_topic' => getRestockProductFCMTopic(restockRequest: $restockRequest)
        ], 200);
    }
}
