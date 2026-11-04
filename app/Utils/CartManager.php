<?php

namespace App\Utils;

use App\Services\Analytics\Analytics;
use App\Models\DigitalProductVariation;
use App\Models\ShippingMethod;
use App\Models\Cart;
use App\Models\CartShipping;
use App\Models\CategoryShippingCost;
use App\Models\Color;
use App\Models\Product;
use App\Models\ShippingType;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Modules\TaxModule\app\Traits\VatTaxManagement;

class CartManager
{
    use VatTaxManagement;

    public static function cartListSessionToDatabase($request = null): void
    {
        $user = Helpers::getCustomerInformation($request);
        if (session()->has('guest_id') || (!is_null($request) && !is_null($request->guest_id)) ) {
            $guestId = session('guest_id') ?? $request->guest_id;
            $cartList = Cart::where(['is_guest' => 1, 'customer_id' => $guestId])->get();
            foreach ($cartList as $cart) {
                $databaseCart = Cart::where([
                    'customer_id' => $user['id'],
                    'seller_id' => $cart['seller_id'],
                    'seller_is' => $cart['seller_is']
                ])->first();

                Cart::where([
                    'customer_id' => $user['id'],
                    'product_id' => $cart['product_id'],
                    'variant' => $cart['variant'],
                    'seller_id' => $cart['seller_id'],
                    'seller_is' => $cart['seller_is']
                ])->delete();

                $cart->cart_group_id = isset($databaseCart) ? $databaseCart['cart_group_id'] : str_replace('guest', $user['id'], $cart['cart_group_id']);
                $cart->customer_id = $user['id'];
                $cart->is_guest = 0;
                $cart->save();
            }
        }
    }

    public static function getCartListQuery($groupId = null, $type = null): Collection|array
    {
        ProductManager::updateProductPriceInCartList(request: request()->all());
        self::updateOrderSummaryShippingCost($groupId, $type);

        $cartItems = Cart::with(['product' => function ($query) {
                return $query->active()->with(['category' => function ($query) {
                    return $query->with(['taxVats' => function ($query) {
                        return $query->with(['tax'])->wherehas('tax', function ($query) {
                            return $query->where('is_active', 1);
                        });
                    }]);
                }, 'clearanceSale' => function ($query) {
                    return $query->active();
                }, 'taxVats' => function ($query) {
                    return $query->with(['tax'])->wherehas('tax', function ($query) {
                        return $query->where('is_active', 1);
                    });
                }]);
            }])
            ->whereHas('product', function ($query) {
                return $query->active();
            })
            ->when($groupId == null, function ($query) {
                return $query->whereIn('cart_group_id', CartManager::get_cart_group_ids());
            })
            ->when($groupId, function ($query) use ($groupId) {
                return $query->where('cart_group_id', $groupId);
            })
            ->when($type == 'checked', function ($query) {
                return $query->where(['is_checked' => 1]);
            })
            ->get();

        foreach ($cartItems as $item) {
            $item->discount = getProductPriceByType(
                product: $item->product,
                type: 'discounted_amount',
                result: 'value',
                price: $item->price
            );
        }

        return $cartItems;
    }

    public static function getCartListQueryAPI($request = null, $groupId = null, $type = null): Collection|array
    {
        ProductManager::updateProductPriceInCartList(request: $request ?? request()->all());

        $cartItems = Cart::with(['product' => function ($query) {
                return $query->active()->with(['clearanceSale' => function ($query) {
                    return $query->active();
                }]);
            }])
            ->whereHas('product', function ($query) {
                return $query->active();
            })
            ->when($groupId == null, function ($query) use ($request) {
                return $query->whereIn('cart_group_id', CartManager::get_cart_group_ids($request ?? request()->all()));
            })
            ->when($groupId, function ($query) use ($groupId) {
                return $query->where('cart_group_id', $groupId);
            })
            ->when($type == 'checked', function ($query) {
                return $query->where(['is_checked' => 1]);
            })->get();

            foreach ($cartItems as $item) {
                $item->discount = getProductPriceByType(
                    product: $item->product,
                    type: 'discounted_amount',
                    result: 'value',
                    price: $item->price
                );
            }
        return $cartItems;
    }

    public static function getCartListGroupQuery($groupId = null, $type = null): Collection|array
    {
        $cartItems =  Cart::with(['product' => function ($query) {
            return $query->active()->with(['clearanceSale' => function ($query) {
                    return $query->active();
                }]);
            }])
            ->whereHas('product', function ($query) {
                return $query->active();
            })
            ->when($groupId == null, function ($query) {
                return $query->whereIn('cart_group_id', CartManager::get_cart_group_ids());
            })
            ->when($groupId, function ($query) use ($groupId) {
                return $query->where('cart_group_id', $groupId);
            })
            ->when($type == 'checked', function ($query) {
                return $query->where(['is_checked' => 1]);
            })
            ->get();

        foreach ($cartItems as $item) {
            $item->discount = getProductPriceByType(
                product: $item->product,
                type: 'discounted_amount',
                result: 'value',
                price: $item->price
            );
        }
        return $cartItems->groupBy('cart_group_id');
    }

    public static function get_cart_group_ids($request = null, $type = null)
    {
        $user = Helpers::getCustomerInformation(!is_null($request) ? $request : request());

        return Cart::whereHas('product', function ($query) {
                return $query->active();
            })->when($user == 'offline', function ($query) use ($request) {
                return $query->where(['customer_id' => session('guest_id') ?? (request('guest_id') ?? 0), 'is_guest' => 1]);
            })->when($user != 'offline', function ($query) use ($user) {
                return $query->where(['customer_id' => $user['id'], 'is_guest' => '0']);
            })
            ->when($type == 'checked', function ($query) {
                return $query->where(['is_checked' => 1]);
            })
            ->groupBy('cart_group_id')
            ->pluck('cart_group_id')
            ->toArray();
    }

    public static function get_shipping_cost($groupId = null, $type = null)
    {
        self::updateOrderSummaryShippingCost($groupId, $type);
        $cartShippingCost = Cart::where(['product_type' => 'physical'])
            ->whereHas('product', function ($query) {
                return $query->active();
            })->when(($groupId == null && $type != 'checked'), function ($query) {
                return $query->whereIn('cart_group_id', CartManager::get_cart_group_ids());
            })
            ->when(($groupId == null && $type == 'checked'), function ($query) {
                return $query->whereIn('cart_group_id', CartManager::get_cart_group_ids(type: 'checked'));
            })
            ->when($groupId != null, function ($query) use ($groupId) {
                return $query->where(['cart_group_id' => $groupId]);
            })
            ->when($type == 'checked', function ($query) {
                return $query->where(['is_checked' => 1]);
            })->sum('shipping_cost');

        $orderWiseShippingCostData = CartShipping::whereHas('cart', function ($query) use ($type) {
                return $query->where(['product_type' => 'physical'])->whereHas('product', function ($query) {
                    return $query->active();
                })->when($type == 'checked', function ($query) {
                    return $query->where(['is_checked' => 1]);
                });
            })->when(($groupId == null && $type != 'checked'), function ($query) {
                return $query->whereIn('cart_group_id', CartManager::get_cart_group_ids());
            })
            ->when(($groupId == null && $type == 'checked'), function ($query) {
                return $query->whereIn('cart_group_id', CartManager::get_cart_group_ids(type: 'checked'));
            })
            ->when(($groupId != null), function ($query) use ($groupId) {
                return $query->where('cart_group_id', $groupId);
            });

        if ($groupId == null) {
            $orderWiseShippingCost = $orderWiseShippingCostData->sum('shipping_cost');
        } else {
            $data = $orderWiseShippingCostData->first();
            $orderWiseShippingCost = isset($data) ? $data->shipping_cost : 0;
        }
        return ($orderWiseShippingCost + $cartShippingCost);
    }

    public static function updateOrderSummaryShippingCost($groupId = null, $type = null): void
    {
        $user = Helpers::getCustomerInformation(request());

        $cartItems = Cart::where(['product_type' => 'physical'])
            ->with(['product'])
            ->whereHas('product', function ($query) {
                return $query->active();
            })->when(($groupId == null && $type != 'checked'), function ($query) {
                return $query->whereIn('cart_group_id', CartManager::get_cart_group_ids());
            })
            ->when(($groupId == null && $type == 'checked'), function ($query) {
                return $query->whereIn('cart_group_id', CartManager::get_cart_group_ids(type: 'checked'));
            })
            ->when($groupId != null, function ($query) use ($groupId) {
                return $query->where(['cart_group_id' => $groupId]);
            })
            ->when($user == 'offline', function ($query) {
                return $query->where(['customer_id' => request('guest_id'), 'is_guest' => 1]);
            })
            ->when($user != 'offline', function ($query) use ($user) {
                return $query->where(['customer_id' => $user['id'], 'is_guest' => '0']);
            })
            ->when($type == 'checked', function ($query) {
                return $query->where(['is_checked' => 1]);
            })->get();

        self::updateProductShippingCost($cartItems);
    }

    public static function updateProductShippingCost($cartList): void
    {
        $shippingMethod = getWebConfig(name: 'shipping_method');
        $adminShipping = ShippingType::where('seller_id', 0)->first();
        $cartListGroup = $cartList?->groupBy('cart_group_id');

        foreach($cartListGroup as $cartGroupItems) {
            $shippingType = '';
            if ($shippingMethod == 'inhouse_shipping') {
                $shippingType = isset($adminShipping) ? $adminShipping->shipping_type : 'order_wise';
            } else if ($shippingMethod == 'sellerwise_shipping') {
                if ($cartGroupItems?->first()?->seller_is == 'admin') {
                    $shippingType = isset($adminShipping) ? $adminShipping->shipping_type : 'order_wise';
                } else {
                    $sellerShipping = ShippingType::where('seller_id', $cartGroupItems?->first()?->seller_id)->first();
                    $shippingType = isset($sellerShipping) ? $sellerShipping->shipping_type : 'order_wise';
                }
            }

            foreach($cartGroupItems as $cartItem) {
                if ($shippingType != 'order_wise') {
                    CartShipping::where('cart_group_id', $cartItem?->cart_group_id)->delete();

                    if ($cartItem?->product) {
                        Cart::where(['id' =>  $cartItem->id])->update([
                            'shipping_cost' => $cartItem['product_type'] == 'physical' ? CartManager::get_shipping_cost_for_product_category_wise($cartItem->product, $cartItem['quantity']) : 0,
                            'shipping_type' => $shippingType,
                        ]);
                    }
                }

                if ($shippingType == 'order_wise') {
                    Cart::where(['id' =>  $cartItem->id])->update(['shipping_cost' => 0, 'shipping_type' => $shippingType]);
                }
            }
        }
    }

    public static function getCartListTotalAppliedDiscount($cart): float|int
    {
        $total = 0;
        if (!empty($cart)) {
            foreach ($cart as $item) {
                $discount = getProductPriceByType(product: $item['product'], type: 'discounted_amount', result: 'value', price: $item['price']);
                $productSubtotal = ($item['price'] - $discount) * $item['quantity'];
                $total += $productSubtotal;
            }
        }
        return $total;
    }

    public static function cart_grand_total($cartGroupId = null, $type = null)
    {
        if ($type == 'checked') {
            $cart = CartManager::getCartListQuery(groupId: $cartGroupId, type: 'checked');
            $shippingCost = CartManager::get_shipping_cost(groupId: $cartGroupId, type: 'checked');
        } else {
            $cart = CartManager::getCartListQuery(groupId: $cartGroupId);
            $shippingCost = CartManager::get_shipping_cost(groupId: $cartGroupId);
        }
        $total = 0;
        if (!empty($cart)) {
            foreach ($cart as $item) {
                $discount = getProductPriceByType(product: $item['product'], type: 'discounted_amount', result: 'value', price: $item['price']);
                $productSubtotal = ($item['price'] - $discount) * $item['quantity'];
                $total += $productSubtotal;
            }
            $total += $shippingCost;
        }
        return $total;
    }

    public static function getOnlyCartProductPriceGrandTotal($cartGroupId = null, $type = null): float|int
    {
        if ($type == 'checked') {
            $cart = CartManager::getCartListQuery(groupId: $cartGroupId, type: 'checked');
        } else {
            $cart = CartManager::getCartListQuery(groupId: $cartGroupId);
        }
        $total = 0;
        if (!empty($cart)) {
            foreach ($cart as $item) {
                $discount = getProductPriceByType(product: $item['product'], type: 'discounted_amount', result: 'value', price: $item['price']);
                $productSubtotal = ($item['price'] - $discount) * $item['quantity'];
                $total += $productSubtotal;
            }
        }
        return $total;
    }

    public static function getCartGrandTotalWithoutShippingCharge($cartGroupId = null, $type = null): float|int
    {
        if ($type) {
            $cart = CartManager::getCartListQuery(groupId: $cartGroupId, type: 'checked');
        } else {
            $cart = CartManager::getCartListQuery(groupId: $cartGroupId);
        }
        $total = 0;
        if (!empty($cart)) {
            foreach ($cart as $item) {
                $productSubtotal = ($item['price'] * $item['quantity']) - $item['discount'] * $item['quantity'];
                $total += $productSubtotal;
            }
        }
        return $total;
    }

    public static function cartCleanByCartGroupIds($cartGroupIDs): void
    {
        // Attribute the recovery before the rows go: this is the exact moment a reminded cart turns
        // into an order, and once the cart rows are deleted the link cannot be made again.
        self::markAbandonedCartsRecovered($cartGroupIDs);

        CartShipping::whereIn('cart_group_id', $cartGroupIDs)->delete();
        Cart::whereIn('cart_group_id', $cartGroupIDs)->where(['is_checked' => 1])->delete();

        session()->forget('coupon_code');
        session()->forget('coupon_type');
        session()->forget('coupon_bearer');
        session()->forget('coupon_discount');
        session()->forget('payment_method');
        session()->forget('shipping_method_id');
        session()->forget('billing_address_id');
        session()->forget('order_id');
        session()->forget('cart_group_id');
        session()->forget('order_note');

        cacheRemoveByType(type: 'carts');
    }

    /**
     * Record that a reminded cart converted (Phase 2.4).
     *
     * Wrapped whole: abandoned-cart reporting must never be able to fail an order. If the table is
     * absent because the migration has not run yet, or anything else goes wrong, the order still
     * completes — the worst outcome is a recovery that goes unreported.
     */
    private static function markAbandonedCartsRecovered($cartGroupIDs): void
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('abandoned_cart_reminders')) {
                return;
            }

            \App\Models\AbandonedCartReminder::whereIn('cart_group_id', (array) $cartGroupIDs)
                ->whereNull('recovered_at')
                ->update([
                    'recovered_at' => now(),
                    'recovered_order_id' => session('order_id'),
                    'updated_at' => now(),
                ]);
        } catch (\Throwable) {
            // Reporting is not worth an order.
        }
    }


    public static function addToCartPhysicalProduct($request, $product, $shippingType, $sellerShippingList): array
    {
        $price = 0;
        $string = '';
        $variations = [];

        $user = Helpers::getCustomerInformation($request);
        $guestId = session('guest_id') ?? ($request->guest_id ?? 0);

        if (($product['product_type'] == 'physical') && ($product['current_stock'] < $request['quantity'])) {
            return ['status' => 0, 'message' => translate('out_of_stock!')];
        }

        if ($product['minimum_order_qty'] > $request['quantity']) {
            return ['status' => 0, 'message' => translate('Minimum_order_quantity').' '. $product['minimum_order_qty']];
        }

        if ($user == 'offline') {
            $customerId = $guestId;
            $isGuest = 1;
        } else {
            $customerId = $user['id'];
            $isGuest = 0;
        }

        if ($request->has('color')) {
            $string .= Color::where(['code' => $request['color']])->first()->name;
            $variations['color'] = $string;
        }

        $choices = [];
        foreach (json_decode($product->choice_options) as $choice) {
            $choices[$choice->name] = $request[$choice->name];
            $variations[$choice->title] = $request[$choice->name];
            if ($string != null) {
                $string .= '-' . str_replace(' ', '', $request[$choice->name]);
            } else {
                $string .= str_replace(' ', '', $request[$choice->name]);
            }
        }

        if (!($request['buy_now'])) {
            if ($request['shipping_method_exist'] && $request->has('product_variation_code') && $request['product_variation_code']) {
                $string = str_replace(' ', '', $request['product_variation_code']);
            }
        }

        $cartArray = [
            'color' => $request['color'] ?? null,
            'product_id' => $product['id'],
            'product_type' => $product['product_type'],
            'choices' => json_encode($choices),
            'variations' => json_encode($variations),
            'variant' => $string,
        ];

        if ($string != null) {
            // A variant string that matches no variation used to fall through with
            // $price unset and no stock check at all - a stale or crafted variant
            // could 500 or insert a zero-price cart row. Unmatched now refuses.
            $price = null;
            foreach (json_decode($product->variation) ?? [] as $productVariation) {
                if ($productVariation->type == $string) {
                    if ($productVariation->qty < $request['quantity']) {
                        return ['status' => 0, 'message' => translate('out_of_stock!')];
                    }
                    $price = $productVariation->price;
                    break;
                }
            }
            if ($price === null) {
                return ['status' => 0, 'message' => translate('please_select_valid_product_options')];
            }
        } else {
            $price = $product->unit_price;
        }


        $getProductDiscount = getProductPriceByType(product: $product, type: 'discounted_amount', result: 'value', price: $price);

        $cartArray += [
            'customer_id' => ($user == 'offline' ? $guestId : $user['id']),
            'product_id' => $request['id'],
            'product_type' => $product['product_type'],
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
            'shipping_cost' => $product->product_type == 'physical' ? CartManager::get_shipping_cost_for_product_category_wise($product, $request['quantity']) : 0,
            'shipping_type' => $shippingType,
            'is_guest' => ($user == 'offline' ? 1 : 0),
        ];

        $cartCheck = Cart::where(['customer_id' => $customerId, 'is_guest' => $isGuest, 'seller_id' => ($product->added_by == 'admin') ? 1 : $product->user_id, 'seller_is' => $product->added_by])->first();
        if ($cartCheck) {
            $cartArray['cart_group_id'] = $cartCheck['cart_group_id'];
        } else {
            $cartArray['cart_group_id'] = ($user == 'offline' ? 'guest' : $user['id']) . '-' . Str::random(5) . '-' . time();
        }

        $cart = Cart::where(['product_id' => $request['id'], 'customer_id' => $customerId, 'is_guest' => $isGuest, 'variant' => $string])->first();
        if ($cart) {
            $cartArray['cart_group_id'] = $cart['cart_group_id'];
            Cart::where(['id' => $cart['id']])->update($cartArray);
        } else {
            $cartID = Cart::insertGetId($cartArray);
            $cart = Cart::where(['id' => $cartID])->first();
        }

        // Analytics. Recorded here rather than in the controllers because the storefront, both API
        // versions and the two POS screens all add to the cart through this one function — an event
        // added to a controller would be missing from the other four callers the day it shipped.
        // Cart rows are written with insertGetId, which is a query-builder call and fires no model
        // event, so the hook has to be explicit.
        try {
            app(Analytics::class)->cartAdded(
                productId: (int) $cartArray['product_id'],
                quantity: (int) ($cartArray['quantity'] ?? 1),
                value: (float) ($cartArray['price'] ?? 0) * (int) ($cartArray['quantity'] ?? 1),
                vendorId: ($cartArray['seller_is'] ?? null) === 'seller' ? (int) $cartArray['seller_id'] : null,
            );
        } catch (\Throwable) {
            // Recording an event is never allowed to be the reason an item does not reach a cart.
            // The recorder already swallows its own failures; this covers the container itself,
            // which on a partial deployment can be asked for a class that is not there yet.
        }

        if ($request['buy_now'] == 1) {
            $productTotalPrice = ($price * $request['quantity']) - ($getProductDiscount * $request['quantity']);
            $verifyStatus = OrderManager::checkSingleProductMinimumOrderAmountVerify(request: $request, product: $product, totalAmount: $productTotalPrice);
            if ($verifyStatus['status'] == 0) {
                return ['status' => 0, 'message' => $verifyStatus['message']];
            }

            Cart::where(['customer_id' => ($user == 'offline' ? $guestId : $user['id']), 'is_guest' => ($user == 'offline' ? 1 : 0)])
                ->update(['is_checked' => 0]);

            Cart::where(['id' => $cart['id']])->update(['is_checked' => 1]);

            if ($product['product_type'] == 'digital') {
                return [
                    'status' => 1,
                    'redirect_to' => 'checkout',
                    'cart' => $cart,
                    'message' => translate('successfully_added') . '!',
                ];
            }

            if ($product['product_type'] == 'physical' && $shippingType == 'order_wise') {
                if ($request['shipping_method_exist'] && $request['shipping_method_id'] && count($sellerShippingList) > 0) {
                    $cart->update(['is_checked' => 1]);
                    $cartGroupIds = Cart::where(['customer_id' => ($user == 'offline' ? $guestId : $user['id']), 'is_guest' => ($user == 'offline' ? 1 : 0)])
                        ->pluck('cart_group_id');
                    if (count($cartGroupIds) > 0) {
                        CartShipping::whereIn('cart_group_id', $cartGroupIds)->delete();
                    }

                    $shipping = CartShipping::where(['cart_group_id' => $cart['cart_group_id']])->first();
                    if (!isset($shipping)) {
                        $shipping = new CartShipping();
                    }
                    $getShippingCost = ShippingMethod::find($request['shipping_method_id']);
                    if (!$getShippingCost) {
                        return ['status' => 0, 'message' => translate('Selected_shipping_method_not_found')];
                    }
                    $shipping['cart_group_id'] = $cart['cart_group_id'];
                    $shipping['shipping_method_id'] = $request['shipping_method_id'];
                    $shipping['shipping_cost'] = $getShippingCost->cost ?? 0;
                    $shipping->save();

                    $cart['free_delivery_order_amount'] = OrderManager::getFreeDeliveryOrderAmountArray($cart['cart_group_id']);

                    return [
                        'status' => 1,
                        'redirect_to' => 'checkout',
                        'cart' => $cart,
                        'cart_shipping_cost' => $getShippingCost->cost ?? 0,
                        'message' => translate('successfully_added') . '!',
                    ];
                }
                return [
                    'status' => $sellerShippingList && count($sellerShippingList) > 0 ? 2 : 0,
                    'message' => $sellerShippingList && count($sellerShippingList) > 0 ? translate('Please_select_shipping_method') : translate('Shipping_Not_Available_for_this_Shop'),
                    'shipping_method_list' => $sellerShippingList,
                ];
            } elseif ($product['product_type'] == 'physical' && ($shippingType == 'category_wise' || $shippingType == 'product_wise')) {
                $cart->update([
                    'is_checked' => 1,
                    'shipping_cost' => CartManager::get_shipping_cost_for_product_category_wise($product, $request->quantity) ?? 0,
                ]);

                $cart['free_delivery_order_amount'] = OrderManager::getFreeDeliveryOrderAmountArray($cart['cart_group_id']);

                return [
                    'status' => 1,
                    'redirect_to' => 'checkout',
                    'cart' => $cart,
                    'cart_shipping_cost' => $getShippingCost->cost ?? 0,
                    'message' => translate('successfully_added') . '!',
                ];
            }
            $cart->update(['is_checked' => 1]);
        }

        if ($product->product_type == 'physical') {
            $cart['free_delivery_order_amount'] = OrderManager::getFreeDeliveryOrderAmountArray($cart['cart_group_id']);
        }

        return [
            'status' => 1,
            'in_cart_key' => $cart['id'],
            'cart' => $cart,
            'message' => translate('successfully_added') . '!',
            'product_variant_type' => count(json_decode($product['variation'], true)) > 0 ? 'multi_variant' : 'single_variant',
        ];
    }

    public static function addToCartDigitalProduct($request, $product, $shippingType, $sellerShippingList): array
    {
        if ($product['minimum_order_qty'] > $request['quantity']) {
            return ['status' => 0, 'message' => translate('Minimum_order_quantity').' '. $product['minimum_order_qty']];
        }

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
            $customerId = $user['id'];
            $isGuest = 0;
        }


        $getProductDiscount = getProductPriceByType(product: $product, type: 'discounted_amount', result: 'value', price: $price);
        $cartArray = [
            'customer_id' => $customerId,
            'product_id' => $request['id'],
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
            'shipping_type' => $shippingType,
            'is_guest' => $isGuest,
        ];

        $cartCheck = Cart::where(['customer_id' => $customerId, 'is_guest' => $isGuest, 'seller_id' => ($product->added_by == 'admin') ? 1 : $product->user_id, 'seller_is' => $product->added_by])->first();
        if ($cartCheck) {
            $cartArray['cart_group_id'] = $cartCheck['cart_group_id'];
        } else {
            $cartArray['cart_group_id'] = ($user == 'offline' ? 'guest' : $user['id']) . '-' . Str::random(5) . '-' . time();
        }

        $cart = Cart::where(['product_id' => $request->id, 'customer_id' => $customerId, 'is_guest' => $isGuest, 'variant' => $request['variant_key']])->first();
        if ($cart) {
            Cart::where(['id' => $cart['id']])->update($cartArray);
        } else {
            $cartID = Cart::insertGetId($cartArray);
            $cart = Cart::where(['id' => $cartID])->first();
        }

        // Analytics. Recorded here rather than in the controllers because the storefront, both API
        // versions and the two POS screens all add to the cart through this one function — an event
        // added to a controller would be missing from the other four callers the day it shipped.
        // Cart rows are written with insertGetId, which is a query-builder call and fires no model
        // event, so the hook has to be explicit.
        try {
            app(Analytics::class)->cartAdded(
                productId: (int) $cartArray['product_id'],
                quantity: (int) ($cartArray['quantity'] ?? 1),
                value: (float) ($cartArray['price'] ?? 0) * (int) ($cartArray['quantity'] ?? 1),
                vendorId: ($cartArray['seller_is'] ?? null) === 'seller' ? (int) $cartArray['seller_id'] : null,
            );
        } catch (\Throwable) {
            // Recording an event is never allowed to be the reason an item does not reach a cart.
            // The recorder already swallows its own failures; this covers the container itself,
            // which on a partial deployment can be asked for a class that is not there yet.
        }

        if ($request['buy_now'] == 1) {
            $productTotalPrice = ($price * $request['quantity']) - ($getProductDiscount * $request['quantity']);
            $verifyStatus = OrderManager::checkSingleProductMinimumOrderAmountVerify(request: $request, product: $product, totalAmount: $productTotalPrice);
            if ($verifyStatus['status'] == 0) {
                return ['status' => 0, 'message' => $verifyStatus['message']];
            }

            Cart::where(['customer_id' => ($user == 'offline' ? $guestId : $user['id']), 'is_guest' => ($user == 'offline' ? 1 : 0)])
                ->update(['is_checked' => 0]);

            Cart::where(['id' => $cart['id']])->update(['is_checked' => 1]);

            if ($product['product_type'] == 'digital') {
                return [
                    'status' => 1,
                    'redirect_to' => 'checkout',
                    'cart' => $cart,
                    'message' => translate('successfully_added') . '!',
                ];
            }
        }

        return [
            'status' => 1,
            'in_cart_key' => $cart['id'],
            'cart' => $cart,
            'message' => translate('successfully_added') . '!',
            'product_variant_type' => count(json_decode($product['variation'], true)) > 0 ? 'multi_variant' : 'single_variant',
        ];
    }

    public static function add_to_cart($request, $from_api = false): array
    {
        cacheRemoveByType(type: 'carts');

        $product = Product::with(['digitalVariation', 'clearanceSale' => function ($query) {
            return $query->active();
        }])->where(['id' => $request['id']])->first();

        if($product['status'] == 0){
            return ['status' => 0, 'message' => translate('Product_is_unavailable')];
        }

        $authorType = $product['added_by'] == 'admin' ? 'inhouse' : ($product['added_by'] == 'seller' ? 'vendor' : null);
        if ($authorType === 'vendor' && isset($product->seller->shop)) {
            $isTemporaryClose = (bool) checkVendorAbility(type: $authorType, status: 'temporary_close', vendor: $product->seller->shop);
            $isVacationMode = (bool) checkVendorAbility(type: $authorType, status: 'vacation_status', vendor: $product->seller->shop);
        } else{
            $isTemporaryClose =(bool) checkVendorAbility(type: $authorType, status: 'temporary_close');
            $isVacationMode =(bool) checkVendorAbility(type: $authorType, status: 'vacation_status');
        }
        if ($isTemporaryClose || $isVacationMode) {
            return ['status' => 0, 'message' => translate('Shop_is_unavailable')];
        }
        $shippingMethod = getWebConfig(name: 'shipping_method');
        $adminShipping = ShippingType::where('seller_id', 0)->first();
        $sellerShippingList = null;
        if ($shippingMethod == 'inhouse_shipping') {
            $shippingType = isset($adminShipping) ? $adminShipping->shipping_type : 'order_wise';
            $sellerShippingList = $shippingType == 'order_wise' ? ShippingMethod::where(['status' => 1])->where(['creator_type' => 'admin'])->get() : null;
        } else {
            if ($product['added_by'] == 'admin') {
                $shippingType = isset($adminShipping) ? $adminShipping->shipping_type : 'order_wise';
                $sellerShippingList = $shippingType == 'order_wise' ? ShippingMethod::where(['status' => 1])->where(['creator_type' => 'admin'])->get() : null;
            } else {
                $sellerShipping = ShippingType::where('seller_id', $product['user_id'])->first();
                $shippingType = isset($sellerShipping) ? $sellerShipping->shipping_type : 'order_wise';
                $sellerShippingList = ShippingMethod::where(['status' => 1])->where(['creator_id' => $product['user_id'], 'creator_type' => 'seller'])->get();
            }
        }

        if ($product['product_type'] == 'digital') {
            return self::addToCartDigitalProduct($request, $product, $shippingType, $sellerShippingList);
        } else {
            return self::addToCartPhysicalProduct($request, $product, $shippingType, $sellerShippingList);
        }
    }

    public static function update_cart_qty($request): array
    {
        cacheRemoveByType(type: 'carts');

        $user = Helpers::getCustomerInformation($request);
        $guest_id = session('guest_id') ?? ($request->guest_id ?? 0);
        $status = 1;
        $qty = 0;
        $cart = Cart::where(['id' => $request->key, 'customer_id' => ($user == 'offline' ? $guest_id : $user['id'])])->first();

        if (!$cart) {
            return [
                'status' => 0,
                'qty' => $request['quantity'],
                'message' => translate('Product_not_found_in_cart'),
            ];
        }

        $product = Product::find($cart['product_id']);

        // Reject anything that is not a positive whole number BEFORE the stock checks below.
        //
        // Those checks only ask "is the requested quantity larger than stock?", which a negative
        // number passes trivially — so a quantity of -50 was stored as-is and cart_grand_total()
        // multiplied it by the unit price. Measured on a real cart: two items totalling 6,100
        // became -276,950 once one line was set to -50. The customer controls the sign, and
        // therefore the total.
        //
        // add_to_cart() was never exposed: its minimum_order_qty check (1 > -50) already refused
        // these. Only the update path was missing the guard.
        $requestedQuantity = filter_var($request->quantity, FILTER_VALIDATE_INT);
        if ($requestedQuantity === false || $requestedQuantity < 1) {
            return [
                'status' => 0,
                'qty' => $cart['quantity'],
                'message' => translate('Minimum_order_quantity') . ' ' . max(1, (int) $product?->minimum_order_qty),
            ];
        }

        // The minimum is enforced when adding to the cart; without it here, a customer could add the
        // required 5 and then edit the line down to 1.
        $minimumOrderQty = max(1, (int) ($product->minimum_order_qty ?? 1));
        if ($requestedQuantity < $minimumOrderQty) {
            return [
                'status' => 0,
                'qty' => $cart['quantity'],
                'message' => translate('Minimum_order_quantity') . ' ' . $minimumOrderQty,
            ];
        }

        // json_decode(null) returns null, and count(null) is a TypeError on PHP 8 — the same shape
        // as the product-save crash fixed in Phase 1. A product row with a null variation would
        // take down the cart page rather than update a quantity.
        $variations = json_decode($product->variation ?? '[]') ?: [];
        $count = count($variations);
        if ($count) {
            foreach ($variations as $variation) {
                if (($variation->type ?? null) == $cart['variant'] && ($variation->qty ?? 0) < $requestedQuantity) {
                    $status = 0;
                    $qty = $cart['quantity'];
                }
            }
        } else if (($product['product_type'] == 'physical') && $product['current_stock'] < $requestedQuantity) {
            $status = 0;
            $qty = $cart['quantity'];
        }

        if ($status) {
            // The validated integer, not the raw request value — otherwise "3abc" would pass the
            // checks as 3 and then be stored verbatim.
            $qty = $requestedQuantity;
            $cart['quantity'] = $requestedQuantity;
            $cart['shipping_cost'] = $product->product_type == 'physical' ? CartManager::get_shipping_cost_for_product_category_wise($product, $requestedQuantity) : 0;
        }

        $cart->save();

        if ($request['buy_now'] == 1) {
            Cart::where(['customer_id' => ($user == 'offline' ? $guest_id : $user['id']), 'is_guest' => ($user == 'offline' ? 1 : 0)])
                ->update(['is_checked' => 0]);
            Cart::where(['id' => $request->key, 'customer_id' => ($user == 'offline' ? $guest_id : $user['id'])])->update(['is_checked' => 1]);
        }

        // Analytics: recorded here rather than in the two controllers that call this, because web
        // and API both come through it and an event added to one of them would be missing from the
        // other. Only an update that actually took — a rejected quantity changed nothing.
        if ($status) {
            app(Analytics::class)->cartUpdated(
                productId: (int) $cart['product_id'],
                quantity: $qty,
                value: (float) $cart['price'] * $qty,
            );
        }

        return [
            'status' => $status,
            'qty' => $qty,
            'message' => $status == 1 ? translate('successfully_updated!') : translate('sorry_stock_is_limited')
        ];
    }

    public static function get_shipping_cost_for_product_category_wise($product, $qty)
    {
        $shippingMethod = getWebConfig(name: 'shipping_method');
        $cost = 0;

        if ($shippingMethod == 'inhouse_shipping') {
            $adminShipping = ShippingType::where('seller_id', 0)->first();
            $shippingType = isset($adminShipping) ? $adminShipping->shipping_type : 'order_wise';
        } else {
            if ($product['added_by'] == 'admin') {
                $adminShipping = ShippingType::where('seller_id', 0)->first();
                $shippingType = isset($adminShipping) ? $adminShipping->shipping_type : 'order_wise';
            } else {
                $sellerShipping = ShippingType::where('seller_id', $product['user_id'])->first();
                $shippingType = isset($sellerShipping) ? $sellerShipping->shipping_type : 'order_wise';
            }
        }

        if ($shippingType == 'category_wise') {
            $categoryID = 0;;
            foreach (json_decode($product['category_ids'] ?? '') as $ct) {
                if ($ct->position == 1) {
                    $categoryID = $ct->id;
                }
            }

            if ($shippingMethod == 'inhouse_shipping') {
                $categoryShippingCost = CategoryShippingCost::where('seller_id', 0)->where('category_id', $categoryID)->first();
            } else {
                if ($product['added_by'] == 'admin') {
                    $categoryShippingCost = CategoryShippingCost::where('seller_id', 0)->where('category_id', $categoryID)->first();
                } else {
                    $categoryShippingCost = CategoryShippingCost::where('seller_id', $product['user_id'])->where('category_id', $categoryID)->first();
                }
            }

            if (isset($categoryShippingCost->multiply_qty) && $categoryShippingCost->multiply_qty == 1) {
                $cost = $qty * $categoryShippingCost->cost;
            } else {
                $cost = $categoryShippingCost->cost ?? 0;
            }

        } else if ($shippingType == 'product_wise') {
            if ($product['multiply_qty'] == 1) {
                $cost = $qty * $product['shipping_cost'];
            } else {
                $cost = $product['shipping_cost'];
            }
        }

        return $cost;
    }

    public static function getShippingCostSavedForFreeDelivery($groupId = null, $type = null)
    {
        $costSaved = 0;

        $cartGroup = Cart::where(['product_type' => 'physical'])
            ->whereHas('product', function ($query) {
                return $query->active();
            })->when($groupId != null, function ($query) use ($groupId) {
                return $query->where('cart_group_id', $groupId);
            })
            ->when(($groupId == null && $type != 'checked'), function ($query) {
                return $query->whereIn('cart_group_id', CartManager::get_cart_group_ids());
            })
            ->when(($groupId == null && $type == 'checked'), function ($query) {
                return $query->whereIn('cart_group_id', CartManager::get_cart_group_ids(type: 'checked'));
            })
            ->when($type == 'checked', function ($query) {
                return $query->where(['is_checked' => 1]);
            })->get()->groupBy('cart_group_id');

        foreach ($cartGroup as $cart) {
            if ($cart->count() > 0) {
                $freeDeliveryCheck = OrderManager::getFreeDeliveryOrderAmountArray($cart[0]->cart_group_id);
                $costSaved += $freeDeliveryCheck['shipping_cost_saved'];
            }
        }

        return $costSaved;
    }

    public static function product_stock_check($carts): bool
    {
        $status = true;

        foreach ($carts as $cart) {
            if ($cart->product) {
                $product = $cart->product;

                // json_decode(null) returns null and count(null) is a TypeError on PHP 8. This runs
                // immediately before an order is placed, so a product row with a null variation
                // did not block the checkout — it crashed it, leaving the customer on a 500 at the
                // last step with no way through.
                $variations = json_decode($product->variation ?? '[]') ?: [];

                if (count($variations)) {
                    foreach ($variations as $variation) {
                        if (($variation->type ?? null) == $cart['variant'] && ($variation->qty ?? 0) < $cart->quantity) {
                            $status = false;
                        }
                    }
                } else if (($product['product_type'] == 'physical') && $product['current_stock'] < $cart->quantity) {
                    $status = false;
                }
            } else {
                $status = false;
            }
        }
        return $status;
    }

    public static function getAppliedTaxIds($product = null, $taxConfig = []): array
    {
        if (!isset($taxConfig['is_included']) || $taxConfig['is_included']) {
            return [];
        }
        $taxType = $taxConfig['SystemTaxVatType'] ?? 'order_wise';

        if ($taxType == 'product_wise') {
            $taxIds = $product['taxVats']?->pluck('tax')->toArray() ?? [];
        } elseif ($taxType == 'category_wise') {
            $taxIds = $product?->category?->taxVats?->pluck('tax')->toArray() ?? [];
        } else {
            if (is_array($taxConfig['taxVats'])) {
                $taxIds = collect($taxConfig['taxVats'])->whereIn('id', $taxConfig['SystemTaxVat']['tax_ids'])->toArray();
            } else {
                $taxIds = $taxConfig['taxVats']->whereIn('id', $taxConfig['SystemTaxVat']['tax_ids'])->toArray();
            }
        }

        return $taxIds ?? [];
    }

    public static function getAppliedShippingTaxIds($taxConfig = []): array
    {
        if (!isset($taxConfig['is_included']) || $taxConfig['is_included']) {
            return [];
        }

        return collect($taxConfig['SystemTaxVat']['additionalData'])->toArray();
    }

    public static function getAppliedTaxAmount($product = null, $taxConfig = [], $totalDiscountedPrice = 0, $appliedDiscountedAmount = 0): float|int
    {
        if (!isset($taxConfig['is_included']) || $taxConfig['is_included']) {
            return 0;
        }
        $taxPercent = 0;
        $taxType = $taxConfig['SystemTaxVatType'] ?? 'order_wise';

        if ($taxType == 'product_wise') {
            if (isset($product['taxVats']) && count($product['taxVats']) > 0) {
                foreach ($product['taxVats'] as $taxVat) {
                    if ($taxVat?->tax?->tax_rate) {
                        $taxPercent += $taxVat?->tax?->tax_rate;
                    }
                }
            }
        } elseif ($taxType == 'category_wise') {
            if (isset($product['category']['taxVats']) && count($product['category']['taxVats']) > 0) {
                foreach ($product['category']['taxVats'] as $taxVat) {
                    if ($taxVat?->tax?->tax_rate) {
                        $taxPercent += $taxVat?->tax?->tax_rate;
                    }
                }
            }
        } else {
            if (is_array($taxConfig['taxVats'])) {
                $taxPercent = collect($taxConfig['taxVats'])->whereIn('id', $taxConfig['SystemTaxVat']['tax_ids'])->sum('tax_rate');
            } else {
                $taxPercent = $taxConfig['taxVats']->whereIn('id', $taxConfig['SystemTaxVat']['tax_ids'])->sum('tax_rate');
            }
        }

        return (($totalDiscountedPrice - $appliedDiscountedAmount) * $taxPercent) / 100;
    }

    public static function getAppliedShippingTaxAmount($taxConfig = [], $totalShippingCost = 0, $totalDiscountedPrice = 0, $applicableShippingAmount = 0): float|int
    {
        if (!isset($taxConfig['is_included']) || $taxConfig['is_included']) {
            return 0;
        }

        $allTaxIds = collect($taxConfig['SystemTaxVat']['additionalData'])
            ->pluck('tax_ids')->flatten()->toArray();

        if (is_array($taxConfig['taxVats'])) {
            $taxRate = collect($taxConfig['taxVats'])->whereIn('id', $allTaxIds)->sum('tax_rate');
        } else {
            $taxRate = $taxConfig['taxVats']->whereIn('id', $allTaxIds)->sum('tax_rate');
        }

        if ($totalShippingCost <= 0 || $applicableShippingAmount <= 0) {
            return 0;
        }

        return ((($totalShippingCost * $totalDiscountedPrice) / $applicableShippingAmount) * $taxRate) / 100;
    }

    public static function getCartListTaxAmount(array $data  = [], ?string $cartGroupId = null): array
    {
        $vendorWiseCartList = OrderManager::processOrderGenerateData(data: [
            'coupon_code' => $data['coupon_code'] ?? (session('coupon_code') ?? ''),
            'requestObj' => $data['requestObj'] ?? null,
        ]);

        $totalTaxAmount = 0;
        $totalShippingTaxAmount = 0;
        foreach ($vendorWiseCartList as $vendorWiseCart) {
            foreach ($vendorWiseCart['applied_tax_cart_list'] as $cartItem) {
                $totalTaxAmount += $cartItem['applied_tax_amount'];
                $totalShippingTaxAmount += $cartItem['applied_shipping_cost_tax'];
            }
        }

        return [
            'item_tax' => $totalTaxAmount,
            'shipping_cost_tax' => $totalShippingTaxAmount,
        ];
    }
}
