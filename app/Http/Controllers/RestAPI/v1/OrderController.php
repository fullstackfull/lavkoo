<?php

namespace App\Http\Controllers\RestAPI\v1;

use App\Events\DigitalProductOtpVerificationEvent;
use App\Events\RefundEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\RefundStoreRequest;
use App\Models\Cart;
use App\Models\Currency;
use App\Models\DigitalProductOtpVerification;
use App\Models\OfflinePaymentMethod;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\OrderDetailsRewards;
use App\Models\RefundRequest;
use App\Models\Setting;
use App\Models\ShippingAddress;
use App\Services\OrderService;
use App\Traits\CommonTrait;
use App\Traits\FileManagerTrait;
use App\Traits\SmsGateway;
use App\Models\User;
use App\Utils\CartManager;
use App\Utils\Convert;
use App\Utils\CustomerManager;
use App\Utils\Helpers;
use App\Utils\ImageManager;
use App\Utils\OrderManager;
use App\Utils\SMSModule;
use Carbon\Carbon;
use Carbon\CarbonInterval;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;

class OrderController extends Controller
{
    use CommonTrait, FileManagerTrait;

    public function __construct(
        private readonly OrderService $orderService,
    )
    {
    }

    public function track_by_order_id(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        // Ownership scope: this returns a full order (customer name, address, phone, items). Without a
        // scope it dumped ANY order by id to an unauthenticated caller. An authenticated customer sees
        // only their own orders; a guest must present the guest_id the order was placed under.
        $data = Order::with(['deliveryMan', 'orderStatusHistory' => function ($query) {
            return $query->latest();
        }])
            ->where('id', $request['order_id'])
            ->where(function ($query) use ($request) {
                auth('api')->check()
                    ? $query->where('customer_id', auth('api')->id())
                    : $query->where(['customer_id' => $request['guest_id'], 'is_guest' => 1]);
            })
            ->first();

        if (!$data) {
            return response()->json(['errors' => [['code' => 'order', 'message' => translate('not_found')]]], 404);
        }

        $data = json_decode(json_encode($data), true);
        return response()->json($data, 200);
    }

    public function track_order_details_history(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required'
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }
        $orderId = $request['order_id'];
        // Ownership scope, mirroring track_by_order_id: authenticated customer -> own orders; guest ->
        // orders under their guest_id. Without it any id returned another order's status timeline.
        $order = Order::where('id', $orderId)
            ->where(function ($query) use ($request) {
                auth('api')->check()
                    ? $query->where('customer_id', auth('api')->id())
                    : $query->where(['customer_id' => $request['guest_id'], 'is_guest' => 1]);
            })
            ->first();
        if (!$order) {
            return response()->json(['errors' => [['code' => 'order', 'message' => translate('not_found')]]], 404);
        }
        $isOrderOnlyDigital = $this->orderService->getCheckIsOrderOnlyDigital(order: $order);
        $getTrackOrderHistory = OrderManager::getTrackOrderStatusHistory(orderId: $orderId, isOrderOnlyDigital: $isOrderOnlyDigital);
        return response()->json($getTrackOrderHistory, 200);

    }

    public function order_cancel(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }
        // Scope to the authenticated customer (route is auth:api): only the order's owner may cancel it,
        // otherwise any caller could enumerate sequential ids and cancel/restock arbitrary orders.
        $order = Order::where(['id' => $request->order_id, 'customer_id' => auth('api')->id()])->first();
        if (!$order) {
            return response()->json(['message' => translate('order_not_found')], 404);
        }

        if ($order['payment_method'] == 'cash_on_delivery' && $order['order_status'] == 'pending') {
            OrderManager::getStockUpdateOnOrderStatusChange($order, 'canceled');
            Order::where(['id' => $request->order_id])->update([
                'order_status' => 'canceled'
            ]);

            return response()->json(translate('order_canceled_successfully'), 200);
        }

        return response()->json(['message' => translate('status_not_changeable_now')], 403);
    }

    public function place_order(Request $request): JsonResponse
    {
        $user = Helpers::getCustomerInformation($request);
        $newCustomerRegister = null;
        $cartGroupIds = CartManager::get_cart_group_ids(request: $request, type: 'checked');
        $carts = Cart::whereHas('product', function ($query) {
            return $query->active();
        })->with('product')->whereIn('cart_group_id', $cartGroupIds)->where(['is_checked' => 1])->get();

        $productStockCheck = CartManager::product_stock_check($carts);
        if (!$productStockCheck) {
            return response()->json(['message' => translate('The_following_items_in_your_cart_are_currently_out_of_stock')], 403);
        }

        $verifyStatus = OrderManager::verifyCartListMinimumOrderAmount($request);
        if ($verifyStatus['status'] == 0) {
            return response()->json(['message' => translate('Check_minimum_order_amount_requirement')], 403);
        }

        if ($user == 'offline' && $request->has('address_id') && $request['address_id']) {
            $shippingAddress = ShippingAddress::where(['customer_id' => $request['guest_id'], 'is_guest' => 1, 'id' => $request->input('address_id')])->first();
            if ($request['is_check_create_account'] && $shippingAddress) {
                if (User::where(['email' => $shippingAddress['email']])->orWhere(['phone' => $shippingAddress['phone']])->first()) {
                    return response()->json(['message' => translate('Already_registered ')], 403);
                } else {
                    $newCustomerRegister = self::addNewCustomer(request: $request, address: $shippingAddress);
                }
            }
        }

        $physicalProduct = false;
        foreach ($carts as $cart) {
            if ($cart->product_type == 'physical') {
                $physicalProduct = true;
            }
        }

        if ($physicalProduct) {
            $zipRestrictStatus = getWebConfig(name: 'delivery_zip_code_area_restriction');
            $countryRestrictStatus = getWebConfig(name: 'delivery_country_restriction');

            if ($request->has('billing_address_id') && $request['billing_address_id']) {
                if ($user == 'offline') {
                    $billingAddress = ShippingAddress::where(['customer_id' => $request['guest_id'], 'is_guest' => 1, 'id' => $request->input('billing_address_id')])->first();
                    if ($request['is_check_create_account'] && $billingAddress && $request['address_id'] == null) {
                        if (User::where(['email' => $billingAddress['email']])->orWhere(['phone' => $billingAddress['phone']])->first()) {
                            return response()->json(['message' => translate('Already_registered ')], 403);
                        } else {
                            $newCustomerRegister = self::addNewCustomer(request: $request, address: $billingAddress);
                        }
                    }
                } else {
                    $billingAddress = ShippingAddress::where(['customer_id' => $user->id, 'is_guest' => '0', 'id' => $request->input('billing_address_id')])->first();
                }

                if (!$billingAddress) {
                    return response()->json(['message' => translate('address_not_found')], 403);
                } elseif ($countryRestrictStatus && !self::delivery_country_exist_check($billingAddress->country)) {
                    return response()->json(['message' => translate('Delivery_unavailable_for_this_country')], 403);
                } elseif ($zipRestrictStatus && !self::delivery_zipcode_exist_check($billingAddress->zip)) {
                    return response()->json(['message' => translate('Delivery_unavailable_for_this_zip_code_area')], 403);
                }
            }
        }

        $currency_model = getWebConfig(name: 'currency_model');
        if ($currency_model == 'multi_currency') {
            $currencyCode = $request->current_currency_code ?? Currency::find(getWebConfig(name: 'system_default_currency'))->code;
        } else {
            $currencyCode = Currency::find(getWebConfig(name: 'system_default_currency'))->code;
        }

        $orderIds = OrderManager::generateOrder(data: [
            'is_guest' => $user == 'offline' ? 1 : 0,
            'guest_id' => $request['guest_id'],
            'customer_id' => $user == 'offline' ? $request['guest_id'] : $user['id'],
            'order_status' => 'pending',
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'unpaid',
            'transaction_ref' => '',
            'address_id' => $request['address_id'],
            'billing_address_id' => $request['billing_address_id'],
            'coupon_code' => $request['coupon_code'],
            'newCustomerRegister' => $newCustomerRegister,
            'bring_change_amount' => $request['bring_change_amount'] ?? 0,
            'bring_change_amount_currency' => $currencyCode,
            'requestObj' => $request,
            'order_note' => $request['order_note'] ?? '',
        ]);


        // generateOrder() returns an empty list when it refused the order inside its transaction:
        // stock ran out between the check above and the write. Nothing was created and no stock was
        // taken. Reported with the same 403 + message shape this endpoint already uses for the
        // pre-order stock check, so existing app builds handle it without a change.
        if (empty($orderIds)) {
            return response()->json(['message' => translate('The_following_items_in_your_cart_are_currently_out_of_stock')], 403);
        }

        return response()->json([
            'order_ids' => $orderIds,
            'new_user' => (bool)$newCustomerRegister
        ], 200);
    }

    function addNewCustomer($request, $address): User
    {
        return User::create([
            'name' => $address['contact_person_name'],
            'f_name' => $address['contact_person_name'],
            'l_name' => '',
            'email' => $address['email'],
            'phone' => $address['phone'],
            'is_active' => 1,
            'password' => bcrypt($request['password']),
            'referral_code' => Helpers::generate_referer_code(),
        ]);
    }

    public function placeOrderByOfflinePayment(Request $request): JsonResponse
    {
        $user = Helpers::getCustomerInformation($request);
        $newCustomerRegister = null;
        $cartGroupIds = CartManager::get_cart_group_ids(request: $request, type: 'checked');
        $carts = Cart::whereHas('product', function ($query) {
            return $query->active();
        })->with('product')->whereIn('cart_group_id', $cartGroupIds)->where(['is_checked' => 1])->get();

        $productStockCheck = CartManager::product_stock_check($carts);
        if (!$productStockCheck) {
            return response()->json(['message' => 'The following items in your cart are currently out of stock'], 403);
        }

        $verifyStatus = OrderManager::verifyCartListMinimumOrderAmount($request);
        if ($verifyStatus['status'] == 0) {
            return response()->json(['message' => 'Check minimum order amount requirement'], 403);
        }

        if ($user == 'offline' && $request->has('address_id') && $request['address_id']) {
            $shippingAddress = ShippingAddress::where(['customer_id' => $request['guest_id'], 'is_guest' => 1, 'id' => $request->input('address_id')])->first();
            if ($request['is_check_create_account'] && $shippingAddress) {
                if (User::where(['email' => $shippingAddress['email']])->orWhere(['phone' => $shippingAddress['phone']])->first()) {
                    return response()->json(['message' => translate('Already_registered ')], 403);
                } else {
                    $newCustomerRegister = self::addNewCustomer(request: $request, address: $shippingAddress);
                }
            }
        }

        $physicalProductExist = false;
        foreach ($carts as $cart) {
            if ($cart->product_type == 'physical') {
                $physicalProductExist = true;
            }
        }

        if ($physicalProductExist) {
            $zipRestrictStatus = getWebConfig(name: 'delivery_zip_code_area_restriction');
            $countryRestrictStatus = getWebConfig(name: 'delivery_country_restriction');

            if ($request->has('billing_address_id') && $request['billing_address_id']) {
                if ($user == 'offline') {
                    $billingAddress = ShippingAddress::where(['customer_id' => $request['guest_id'], 'is_guest' => 1, 'id' => $request->input('billing_address_id')])->first();
                    if ($request['is_check_create_account'] && $billingAddress && $request['address_id'] == null) {
                        if (User::where(['email' => $billingAddress['email']])->orWhere(['phone' => $billingAddress['phone']])->first()) {
                            return response()->json(['message' => translate('Already_registered ')], 403);
                        } else {
                            $newCustomerRegister = self::addNewCustomer(request: $request, address: $billingAddress);
                        }
                    }
                } else {
                    $billingAddress = ShippingAddress::where(['customer_id' => $user->id, 'is_guest' => '0', 'id' => $request->input('billing_address_id')])->first();
                }

                if (!$billingAddress) {
                    return response()->json(['message' => translate('address_not_found')], 200);
                } elseif ($countryRestrictStatus && !self::delivery_country_exist_check($billingAddress->country)) {
                    return response()->json(['message' => translate('Delivery_unavailable_for_this_country')], 403);

                } elseif ($zipRestrictStatus && !self::delivery_zipcode_exist_check($billingAddress->zip)) {
                    return response()->json(['message' => translate('Delivery_unavailable_for_this_zip_code_area')], 403);
                }
            }
        }

        $offlinePaymentInfo = [];
        $method = OfflinePaymentMethod::where(['id' => $request['method_id'], 'status' => 1])->first();

        if (isset($method)) {
            $fields = array_column($method->method_informations, 'customer_input');
            $values = (array)json_decode(base64_decode($request['method_informations']));
            $offlinePaymentInfo['method_id'] = $request['method_id'];
            $offlinePaymentInfo['method_name'] = $method->method_name;
            foreach ($fields as $field) {
                if (key_exists($field, $values)) {
                    $offlinePaymentInfo[$field] = $values[$field];
                }
            }
        }

        $orderIds = OrderManager::generateOrder(data: [
            'is_guest' => $user == 'offline' ? 1 : 0,
            'guest_id' => $request['guest_id'],
            'customer_id' => $user == 'offline' ? $request['guest_id'] : $user['id'],
            'order_status' => 'pending',
            'payment_method' => 'offline_payment',
            'payment_status' => 'unpaid',
            'transaction_ref' => '',
            'address_id' => $request['address_id'],
            'billing_address_id' => $request['billing_address_id'],
            'newCustomerRegister' => $newCustomerRegister,
            'offline_payment_info' => $offlinePaymentInfo,
            'payment_note' => $request['payment_note'],
            'order_note' => $request['order_note'],
            'coupon_code' => $request['coupon_code'],
            'requestObj' => $request,
        ]);

        // Same refusal signal as the other order endpoints: an empty list means the order was
        // rolled back because stock ran out between the check and the write.
        if (empty($orderIds)) {
            return response()->json(['message' => translate('The_following_items_in_your_cart_are_currently_out_of_stock')], 403);
        }

        return response()->json([
            'messages' => translate('order_placed_successfully'),
            'new_user' => (bool)$newCustomerRegister,
            'order_ids' => $orderIds,
        ], 200);
    }

    public function placeOrderByWallet(Request $request): JsonResponse
    {
        $cartGroupIds = CartManager::get_cart_group_ids(request: $request, type: 'checked');
        $carts = Cart::whereHas('product', function ($query) {
            return $query->active();
        })->with('product')->whereIn('cart_group_id', $cartGroupIds)->where(['is_checked' => 1])->get();

        $product_stock = CartManager::product_stock_check($carts);
        if (!$product_stock) {
            return response()->json(['message' => 'The following items in your cart are currently out of stock'], 403);
        }

        $verifyStatus = OrderManager::verifyCartListMinimumOrderAmount($request);
        if ($verifyStatus['status'] == 0) {
            return response()->json(['message' => 'Check minimum order amount requirement'], 403);
        }

        $vendorWiseCartList = OrderManager::processOrderGenerateData(data: [
            'coupon_code' => $request['coupon_code'] ?? '',
            'requestObj' => $request,
        ]);
        $paymentAmount = collect($vendorWiseCartList)->sum('order_amount_with_tax');

        $user = Helpers::getCustomerInformation($request);
        if ($paymentAmount > $user->wallet_balance) {
            return response()->json(['message' => 'inefficient balance in your wallet to pay for this order'], 403);
        } else {
            $physical_product = false;
            foreach ($carts as $cart) {
                if ($cart->product_type == 'physical') {
                    $physical_product = true;
                }
            }

            if ($physical_product) {
                $zip_restrict_status = getWebConfig(name: 'delivery_zip_code_area_restriction');
                $country_restrict_status = getWebConfig(name: 'delivery_country_restriction');

                if ($request->has('billing_address_id') && $request['billing_address_id']) {
                    $shipping_address = ShippingAddress::where(['customer_id' => $request->user()->id, 'id' => $request->input('billing_address_id')])->first();

                    if (!$shipping_address) {
                        return response()->json(['message' => translate('address_not_found')], 403);
                    } elseif ($country_restrict_status && !self::delivery_country_exist_check($shipping_address->country)) {
                        return response()->json(['message' => translate('Delivery_unavailable_for_this_country')], 403);

                    } elseif ($zip_restrict_status && !self::delivery_zipcode_exist_check($shipping_address->zip)) {
                        return response()->json(['message' => translate('Delivery_unavailable_for_this_zip_code_area')], 403);
                    }
                }
            }

            $orderIds = OrderManager::generateOrder(data: [
                'is_guest' => 0,
                'guest_id' => 0,
                'customer_id' => $user['id'],
                'order_status' => 'confirmed',
                'payment_method' => 'pay_by_wallet',
                'payment_status' => 'paid',
                'transaction_ref' => '',
                'address_id' => $request['address_id'],
                'billing_address_id' => $request['billing_address_id'],
                'payment_note' => $request['payment_note'],
                'order_note' => $request['order_note'],
                'coupon_code' => $request['coupon_code'],
                'requestObj' => $request,
            ]);

            // Guarded before the wallet is touched: debiting for an order that was refused
            // would take the customer's balance and leave them with nothing to show for it.
            if (empty($orderIds)) {
                return response()->json(['message' => translate('The_following_items_in_your_cart_are_currently_out_of_stock')], 403);
            }

            CustomerManager::create_wallet_transaction($user->id, Convert::default($paymentAmount), 'order_place', 'order payment');
            return response()->json([
                'messages' => translate('order_placed_successfully'),
                'order_ids' => $orderIds,
            ], 200);
        }
    }

    public function refund_request(Request $request): JsonResponse
    {
        $order_details = OrderDetail::find($request->order_details_id);

        $user = $request->user();
        // Ownership: the order line must belong to the requesting customer.
        if (!$order_details || !Order::where('id', $order_details->order_id)->where('customer_id', $user->id)->exists()) {
            return response()->json(['errors' => [['code' => 'order-details', 'message' => translate('not_found')]]], 404);
        }
        $loyaltyPointStatus = getWebConfig(name: 'loyalty_point_status');
        if ($loyaltyPointStatus == 1) {
            $loyaltyPoint = CustomerManager::countLoyaltyPointForAmount($request->order_details_id);
            if (($user->loyalty_point ?? 0) < $loyaltyPoint) {
                return response()->json(['message' => translate('you_have_not_sufficient_loyalty_point_to_refund_this_order')], 202);
            }
        }

        if ($order_details->delivery_status == 'delivered') {
            $order = Order::find($order_details->order_id);
            $total_product_price = 0;
            $data = [];
            foreach ($order->details as $key => $or_d) {
                $total_product_price += ($or_d->qty * $or_d->price) + $or_d->tax - $or_d->discount;
            }

            $subtotal = ($order_details->price * $order_details->qty) - $order_details->discount + $order_details->tax;
            $coupon_discount = ($order->discount_amount * $subtotal) / $total_product_price;

            $refundInfo = OrderManager::getRefundDetailsForSingleOrderDetails(orderDetailsId: $request['order_details_id']);

            $data['product_price'] = $order_details->price;
            $data['quntity'] = $order_details->qty;
            $data['product_total_discount'] = $order_details->discount;
            $data['product_total_tax'] = $order_details->tax;
            $data['subtotal'] = $subtotal;
            $data['coupon_discount'] = $coupon_discount;
            $data['refund_amount'] = $refundInfo['total_refundable_amount'];
            $data['referral_discount'] = $refundInfo['referral_discount'];

            $expired = false;
            $already_requested = false;
            if ($order_details->refund_request != 0) {
                $already_requested = true;
            }
            // The return window is per-category now (spec item 10). CategoryGovernanceService resolves
            // the category's window, falling back to the global refund_day_limit when the category
            // sets none — so this is identical to the old behaviour for every ungoverned category,
            // and honours a stricter (or more generous) window where one is configured.
            $refundCategoryId = $order_details->product_details ? (json_decode($order_details->product_details, true)['category_id'] ?? null) : null;
            if (!app(\App\Services\Marketplace\CategoryGovernanceService::class)
                    ->isWithinReturnWindow($refundCategoryId, $order_details?->refund_started_at)) {
                $expired = true;
            }
            return response()->json(['already_requested' => $already_requested, 'expired' => $expired, 'refund' => $data], 200);
        } else {
            return response()->json(['message' => translate('You_can_request_for_refund_after_order_delivered')], 200);
        }
    }

    public function store_refund(RefundStoreRequest $request): JsonResponse
    {
        $orderDetails = OrderDetail::find($request->order_details_id);
        $user = $request->user();

        // Ownership: a customer may only refund a line of their OWN order.
        if (!$orderDetails || !Order::where('id', $orderDetails->order_id)->where('customer_id', $user->id)->exists()) {
            return response()->json(['errors' => [['code' => 'order-details', 'message' => translate('not_found')]]], 404);
        }

        $orderDetailsReward = OrderDetailsRewards::where('order_details_id', $request->order_details_id)
            ->where('reward_type', '!=', 'loyalty_point')
            ->first();

        if ($orderDetailsReward && $user->loyalty_point < $orderDetailsReward['reward_amount']) {
            return response()->json(
                translate('you have not sufficient loyalty point to refund this order!!'),
                200
            );
        }

        if ($orderDetails->refund_request != 0) {
            return response()->json(
                translate('already_applied_for_refund_request!!'),
                302
            );
        }

        $refund_request = new RefundRequest();
        $refund_request->order_details_id = $request->order_details_id;
        $refund_request->customer_id = $user->id;
        $refund_request->status = 'pending';
        $refund_request->amount = OrderManager::getRefundDetailsForSingleOrderDetails(orderDetailsId: $orderDetails->id)['total_refundable_amount'];
        $refund_request->product_id = $orderDetails->product_id;
        $refund_request->order_id = $orderDetails->order_id;
        $refund_request->refund_reason = $request->refund_reason;

        if ($request->hasFile('images')) {
            $images = [];

            foreach ($request->file('images') as $img) {
                $images[] = [
                    'image_name' => ImageManager::upload('refund/', 'webp', $img),
                    'storage' => getWebConfig(name: 'storage_connection_type') ?? 'public',
                ];
            }
            $refund_request->images = $images;
        }

        $refund_request->save();
        $orderDetails->update(['refund_request' => 1]);
        $order = Order::find($orderDetails->order_id);

        event(new RefundEvent(
            status: 'refund_request',
            order: $order,
            refund: $refund_request,
            orderDetails: $orderDetails
        ));

        return response()->json(
            translate('refunded_request_updated_successfully!!'),
            200
        );
    }

    public function refund_details(Request $request): JsonResponse
    {
        $orderDetails = OrderDetail::find($request->id);
        // Ownership: the order line (price/qty/discount/tax) must belong to the requesting customer.
        if (!$orderDetails || !Order::where('id', $orderDetails->order_id)->where('customer_id', $request->user()->id)->exists()) {
            return response()->json(['errors' => [['code' => 'order-details', 'message' => translate('not_found')]]], 404);
        }
        $refund = RefundRequest::where('customer_id', $request->user()->id)
            ->with(['refundStatus'])
            ->where('order_details_id', $orderDetails->id)->get();

        $order = Order::find($orderDetails->order_id);

        $total_product_price = 0;
        $data = [];
        foreach ($order->details as $key => $or_d) {
            $total_product_price += ($or_d->qty * $or_d->price) + $or_d->tax - $or_d->discount;
        }

        $subtotal = ($orderDetails->price * $orderDetails->qty) - $orderDetails->discount + $orderDetails->tax;
        $coupon_discount = ($order->discount_amount * $subtotal) / $total_product_price;

        $data['product_price'] = $orderDetails->price;
        $data['quntity'] = $orderDetails->qty;
        $data['product_total_discount'] = $orderDetails->discount;
        $data['product_total_tax'] = $orderDetails->tax;
        $data['subtotal'] = $subtotal;
        $data['coupon_discount'] = $coupon_discount;
        $data['refund_amount'] = OrderManager::getRefundDetailsForSingleOrderDetails(orderDetailsId: $orderDetails['id'])['total_refundable_amount'];
        $data['refund_request'] = $refund;
        $data['order_place_date'] = $order->created_at;
        $data['referral_discount'] = $order?->refer_and_earn_discount ?? 0;

        return response()->json($data, 200);
    }

    public function digital_product_download($id, Request $request): JsonResponse
    {
        $user = Helpers::getCustomerInformation($request);
        $order_details_data = OrderDetail::with('order.customer')->find($id);

        if ($order_details_data) {
            if ($order_details_data->order->payment_status !== "paid") {
                return response()->json([
                    'status' => 0,
                    'message' => translate('Payment_must_be_confirmed_first') . ' !!',
                ]);
            };

            if ($order_details_data->order->is_guest) {
                $customer_email = $order_details_data->order->shipping_address_data ? $order_details_data->order->shipping_address_data->email : ($order_details_data->order->billing_address_data ? $order_details_data->order->billing_address_data->email : '');

                $customer_phone = $order_details_data->order->shipping_address_data ? $order_details_data->order->shipping_address_data->phone : ($order_details_data->order->billing_address_data ? $order_details_data->order->billing_address_data->phone : '');

                $customer_data = ['email' => $customer_email, 'phone' => $customer_phone];
                return self::digital_product_download_process($order_details_data, $customer_data);
            } else {
                if ($user != 'offline' && $user->id == $order_details_data->order->customer->id) {
                    $file_name = '';
                    if ($order_details_data->product->digital_product_type == 'ready_product' && $order_details_data->product->digital_file_ready) {
                        $file_path = asset('storage/app/public/product/digital-product/' . $order_details_data->product->digital_file_ready);
                        $file_name = $order_details_data->product->digital_file_ready;
                    } else {
                        $file_path = asset('storage/app/public/product/digital-product/' . $order_details_data->digital_file_after_sell);
                        $file_name = $order_details_data->digital_file_after_sell;
                    }

                    if (File::exists(base_path('storage/app/public/product/digital-product/' . $file_name))) {
                        return \response()->download($file_path);
                    } else {
                        return response()->json([
                            'status' => 0,
                            'message' => translate('file_not_found'),
                        ]);
                    }
                } else {
                    $customer_data = ['email' => $order_details_data->order->customer->email ?? '', 'phone' => $order_details_data->order->customer->phone ?? ''];
                    return self::digital_product_download_process($order_details_data, $customer_data);
                }
            }
        } else {
            return response()->json(['message' => translate('order_Not_Found')], 403);
        }
    }

    public function digital_product_download_process($order_details_data, $customer): JsonResponse
    {
        $status = 2;
        $emailServices_smtp = getWebConfig(name: 'mail_config');
        if ($emailServices_smtp['status'] == 0) {
            $emailServices_smtp = getWebConfig(name: 'mail_config_sendgrid');
        }

        $paymentPublishedStatus = config('get_payment_publish_status') ?? 0;

        if ($paymentPublishedStatus == 1) {
            $smsConfigStatus = Setting::where(['settings_type' => 'sms_config', 'is_active' => 1])->count() > 0 ? 1 : 0;
        } else {
            $smsConfigStatus = Setting::where(['settings_type' => 'sms_config', 'is_active' => 1])->whereIn('key_name', Helpers::getDefaultSMSGateways())->count() > 0 ? 1 : 0;
        }

        if ($emailServices_smtp['status'] || $smsConfigStatus) {
            $token = rand(1000, 9999);
            if ($customer['email'] == '' && $customer['phone'] == '') {
                return response()->json([
                    'status' => $status,
                    'file_path' => '',
                    'view' => view(VIEW_FILE_NAMES['digital_product_order_otp_verify_failed'])->render(),
                ]);
            }

            $verification_data = DigitalProductOtpVerification::where('identity', $customer['email'])->orWhere('identity', $customer['phone'])->where('order_details_id', $order_details_data->id)->latest()->first();
            $otp_interval_time = getWebConfig(name: 'otp_resend_time') ?? 1; //second

            if (isset($verification_data) && Carbon::parse($verification_data->created_at)->diffInSeconds() < $otp_interval_time) {
                $time_count_in_second = $otp_interval_time - Carbon::parse($verification_data->created_at)->diffInSeconds();
                return response()->json([
                    'status' => 0,
                    'email_config_status' => $emailServices_smtp['status'],
                    'sms_config_status' => $smsConfigStatus,
                    'time_count_in_second' => $time_count_in_second,
                ]);
            } else {
                $verify_data = [
                    'order_details_id' => $order_details_data->id,
                    'token' => $token,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                DigitalProductOtpVerification::updateOrInsert(['identity' => $customer['email'], 'order_details_id' => $order_details_data->id], $verify_data);
                DigitalProductOtpVerification::updateOrInsert(['identity' => $customer['phone'], 'order_details_id' => $order_details_data->id], $verify_data);

                $reset_data = DigitalProductOtpVerification::where('identity', $customer['email'])->orWhere('identity', $customer['phone'])->where('order_details_id', $order_details_data->id)->latest()->first();
                $otp_resend_time = getWebConfig(name: 'otp_resend_time') > 0 ? getWebConfig(name: 'otp_resend_time') : 0;
                $token_time = Carbon::parse($reset_data->created_at);
                $convert_time = $token_time->addSeconds((int)$otp_resend_time);
                $time_count_in_second = $convert_time > Carbon::now() ? Carbon::now()->diffInSeconds($convert_time) : 0;
                $mail_status = 0;

                if ($emailServices_smtp['status'] == 1) {
                    try {
                        $data = [
                            'userName' => $customer['f_name'],
                            'userType' => 'customer',
                            'templateName' => 'digital-product-otp',
                            'subject' => translate('verification_Code'),
                            'title' => translate('verification_Code') . '!',
                            'verificationCode' => $token,
                        ];
                        event(new DigitalProductOtpVerificationEvent(email: $customer['email'], data: $data));
                        $mail_status = 1;
                    } catch (\Exception $exception) {
                    }
                }

                $response = SMSModule::sendCentralizedSMS($customer['phone'], $token);

                $sms_status = ($response == "not_found" || $smsConfigStatus == 0) ? 0 : 1;
                if ($mail_status || $sms_status) {
                    return response()->json([
                        'status' => 1,
                        'email_config_status' => $emailServices_smtp['status'],
                        'sms_config_status' => $smsConfigStatus,
                        'email_sent_status' => $mail_status,
                        'sms_sent_status' => $sms_status,
                        'time_count_in_second' => $time_count_in_second,
                    ], 200);
                } else {
                    return response()->json([
                        'status' => 0,
                        'email_config_status' => $emailServices_smtp['status'],
                        'sms_config_status' => $smsConfigStatus,
                        'email_sent_status' => $mail_status,
                        'sms_sent_status' => $sms_status,
                        'time_count_in_second' => $time_count_in_second,
                    ], 403);
                }
            }
        } else {
            return response()->json([
                'status' => 0,
                'email_config_status' => $emailServices_smtp['status'],
                'sms_config_status' => $smsConfigStatus,
                'email_config_status' => $emailServices_smtp['status'],
                'sms_config_status' => $smsConfigStatus,
            ], 403);
        }

    }

    public function digital_product_download_otp_verify(Request $request)
    {
        $verification = DigitalProductOtpVerification::where(['token' => $request->otp, 'order_details_id' => $request->order_details_id])->first();
        $order_details_data = OrderDetail::with('order.customer')->find($request->order_details_id);

        if ($verification) {
            if ($order_details_data) {
                if ($order_details_data->product->digital_product_type == 'ready_product' && $order_details_data->product->digital_file_ready) {
                    $file_path = storage_path('app/public/product/digital-product/' . $order_details_data->product->digital_file_ready);
                    $file_name = $order_details_data->product->digital_file_ready;
                } else if ($order_details_data->digital_file_after_sell) {
                    $file_path = storage_path('app/public/product/digital-product/' . $order_details_data->digital_file_after_sell);
                    $file_name = $order_details_data->digital_file_after_sell;
                }
            }

            if ($request->has('action') && $request->action == "download") {
                DigitalProductOtpVerification::where(['token' => $request->otp, 'order_details_id' => $request->order_details_id])->delete();
            }

            if (isset($file_name) && File::exists(base_path('storage/app/public/product/digital-product/' . $file_name))) {
                return \response()->download($file_path);
            } else {
                return response()->json([
                    'status' => 0,
                    'message' => translate('file_not_found'),
                ]);
            }

        } else {
            return response()->json([
                'message' => translate('The_OTP_is_incorrect'),
            ], 403);
        }
    }

    public function digital_product_download_otp_resend(Request $request)
    {
        $token_info = DigitalProductOtpVerification::where(['order_details_id' => $request->order_details_id])->first();
        $otp_interval_time = getWebConfig(name: 'otp_resend_time') ?? 1; //minute
        if (isset($token_info) && Carbon::parse($token_info->created_at)->diffInSeconds() < $otp_interval_time) {
            $time_count_in_second = $otp_interval_time - Carbon::parse($token_info->created_at)->diffInSeconds();

            return response()->json([
                'status' => 0,
                'time_count_in_second' => $time_count_in_second,
                'message' => 'Please try again after ' . CarbonInterval::seconds($time_count_in_second)->cascade()->forHumans()
            ]);
        } else {
            $guest_email = '';
            $guest_phone = '';
            $token = rand(1000, 9999);

            $order_details_data = OrderDetail::with('order.customer')->find($request->order_details_id);

            try {
                if ($order_details_data->order->shipping_address_data) {
                    $guest_name = $order_details_data->order->shipping_address_data ? $order_details_data->order->shipping_address_data->contact_person_name : null;
                    $guest_email = $order_details_data->order->shipping_address_data ? $order_details_data->order->shipping_address_data->email : null;
                    $guest_phone = $order_details_data->order->shipping_address_data ? $order_details_data->order->shipping_address_data->phone : null;
                } else {
                    $guest_name = $order_details_data->order->billing_address_data ? $order_details_data->order->billing_address_data->contact_person_name : null;
                    $guest_email = $order_details_data->order->billing_address_data ? $order_details_data->order->billing_address_data->email : null;
                    $guest_phone = $order_details_data->order->billing_address_data ? $order_details_data->order->billing_address_data->phone : null;
                }
            } catch (\Throwable $th) {

            }

            $emailServices_smtp = getWebConfig(name: 'mail_config');
            if ($emailServices_smtp['status'] == 0) {
                $emailServices_smtp = getWebConfig(name: 'mail_config_sendgrid');
            }
            if ($emailServices_smtp['status'] == 1) {
                try {
                    $data = [
                        'userName' => $guest_name,
                        'userType' => 'customer',
                        'templateName' => 'digital-product-otp',
                        'subject' => translate('verification_Code'),
                        'title' => translate('verification_Code') . '!',
                        'verificationCode' => $token,
                    ];
                    event(new DigitalProductOtpVerificationEvent(email: $guest_email, data: $data));
                    $mail_status = 1;
                } catch (\Exception $exception) {
                    $mail_status = 0;
                }
            } else {
                $mail_status = 0;
            }

            $response = SMSModule::sendCentralizedSMS($guest_phone, $token);

            $sms_status = $response == "not_found" ? 0 : 1;
            if ($mail_status || $sms_status) {
                $verify_data = [
                    'order_details_id' => $order_details_data->id,
                    'token' => $token,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                DigitalProductOtpVerification::updateOrInsert(['identity' => $guest_email, 'order_details_id' => $order_details_data->id], $verify_data);
                DigitalProductOtpVerification::updateOrInsert(['identity' => $guest_phone, 'order_details_id' => $order_details_data->id], $verify_data);
            }

            return response()->json([
                'mail_status' => $mail_status,
                'sms_status' => $sms_status,
                'status' => ($mail_status || $sms_status) ? 1 : 0,
                'new_time' => $otp_interval_time,
                'message' => 'OTP sent successfully',
            ]);

        }
    }

    public function order_again(Request $request): JsonResponse
    {
        $orderData = OrderManager::generateOrderAgain($request);
        $addToCartCount = $orderData['add_to_cart_count'];

        if ($orderData['order_product_count'] == $addToCartCount) {
            return response()->json(['message' => 'Added to cart successfully'], 200);
        } elseif ($addToCartCount > 0) {
            $message = $orderData['failedAddToCartCount'] == 0 ? translate('added_to_cart_successfully!') : translate('Some_items_were_not_added_to_your_cart_because_they_are_currently_unavailable_for_purchase.!');
            return response()->json(['message' => $message], 200);
        }

        return response()->json(['message' => 'All items were not added to cart as they are currently unavailable for purchase'], 403);
    }

    public function offline_payment_method_list(Request $request): JsonResponse
    {
        $data = OfflinePaymentMethod::where('status', 1)->get();
        return response()->json(['offline_methods' => $data], 200);
    }

    public function track_order(Request $request): JsonResponse
    {
        $user = Helpers::getCustomerInformation($request);

        $orderExist = null;
        $order = Order::with('shippingAddress', 'billingAddress', 'details', 'latestEditHistory')
            ->where(['id' => $request['order_id'], 'order_type' => 'default_type'])
            ->first();

        if ($user != 'offline') {
            if ($order && $order->is_guest) {
                $shippingAddress = (array)($order['shipping_address_data'] ?? []);
                $billingAddress = (array)($order['billing_address_data'] ?? []);
                if ($order?->shippingAddress && $order?->shippingAddress->phone == $request['phone_number']) {
                    $orderExist = $order;
                } else if ($shippingAddress['phone'] == $request['phone_number']) {
                    $orderExist = $order;
                } else if ($order && $order?->billingAddress && $order?->billingAddress->phone == $request['phone_number']) {
                    $orderExist = $order;
                } else if ($billingAddress['phone'] == $request['phone_number']) {
                    $orderExist = $order;
                }
            } elseif ($user->phone == $request['phone_number']) {
                $orderExist = Order::where(['id' => $request['order_id'], 'order_type' => 'default_type', 'customer_id' => auth('customer')->id()])
                    ->whereHas('details', function ($query) {
                        return $query;
                    })->first();
            }

            if ($request['from_order_details'] == 1) {
                $orderExist = Order::where(['id' => $request['order_id'], 'order_type' => 'default_type'])->whereHas('details', function ($query) {
                    $query->where('customer_id', auth('customer')->id());
                })->first();
            }

        } else {
            $user_id = User::where('phone', $request['phone_number'])->first();
            if ($order && $order->is_guest) {
                $shippingAddress = (array)($order['shipping_address_data'] ?? []);
                $billingAddress = (array)($order['billing_address_data'] ?? []);
                if ($order?->shippingAddress && $order?->shippingAddress->phone == $request['phone_number']) {
                    $orderExist = $order;
                } else if ($shippingAddress['phone'] == $request['phone_number']) {
                    $orderExist = $order;
                } else if ($order?->billingAddress && $order?->billingAddress->phone == $request['phone_number']) {
                    $orderExist = $order;
                } else if ($billingAddress['phone'] == $request['phone_number']) {
                    $orderExist = $order;
                }
            } elseif ($user_id) {
                $orderExist = Order::where(['customer_id' => $user_id->id, 'id' => $request['order_id'], 'order_type' => 'default_type'])->whereHas('details', function ($query) {
                    return $query;
                })->first();
            } else {
                return response()->json(['message' => 'Invalid Phone Number'], 403);
            }
        }

        if (isset($orderExist)) {
            $details = OrderDetail::with(['order.deliveryMan', 'order.latestEditHistory', 'order.orderEditHistory', 'verificationImages', 'seller.shop', 'product', 'latestEditHistory'])
                ->where(['order_id' => $orderExist['id']])
                ->get();

            $details->map(function ($query) {
                $query['variation'] = is_array($query['variation']) ? $query['variation'] : json_decode($query['variation'], true);
                $product = is_array($query['product_details']) ? $query['product_details'] : json_decode($query['product_details'], true);
                if ($product['product_type'] == 'digital' && $product['digital_product_type'] == 'ready_product' && $product['digital_file_ready']) {
                    $checkFilePath = storageLink('product/digital-product', $product['digital_file_ready'], ($product['storage_path'] ?? 'public'));
                    $product['digital_file_ready_full_url'] = $checkFilePath;
                }

                if (!isset($product['thumbnail_full_url']) && isset($query?->product?->thumbnail_full_url)) {
                    $product['thumbnail_full_url'] = $query?->product?->thumbnail_full_url;
                }

                $query['product_details'] = Helpers::product_data_formatting($product);
                return $query;
            });

            return response()->json($details, 200);
        }

        return response()->json(['message' => 'Invalid Order Id or Phone Number'], 403);
    }
}
