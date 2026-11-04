<?php

namespace App\Http\Controllers\Customer;

use App\Events\OrderEditDuePaymentEvent;
use App\Services\Analytics\Analytics;
use App\Models\AdminWallet;
use App\Models\Cart;
use App\Models\CustomerWallet;
use App\Models\OfflinePaymentMethod;
use App\Models\Order;
use App\Models\OrderEditHistory;
use App\Models\Seller;
use App\Models\User;
use App\Library\Payer;
use App\Traits\OrderEditManager;
use App\Utils\Convert;
use App\Utils\CustomerManager;
use App\Utils\Helpers;
use App\Traits\Payment;
use App\Models\Currency;
use App\Library\Receiver;
use App\Utils\CartManager;
use App\Utils\OrderManager;
use App\Models\CartShipping;
use App\Models\ShippingType;
use FontLib\Table\Type\name;
use Illuminate\Http\Request;
use App\Models\BusinessSetting;
use App\Models\ShippingAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Redirector;
use App\Traits\PaymentGatewayTrait;
use App\Http\Controllers\Controller;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\RedirectResponse;
use App\Library\Payment as PaymentInfo;
use Illuminate\Support\Facades\Validator;

class PaymentController extends Controller
{
    use Payment, PaymentGatewayTrait, OrderEditManager;

    public function payment(Request $request): JsonResponse|Redirector|RedirectResponse
    {
        $user = Helpers::getCustomerInformation($request);
        $orderAdditionalData = [];
        $validator = Validator::make($request->all(), [
            'payment_method' => 'required',
            'payment_platform' => 'required',
        ]);

        $response = OrderManager::checkValidationForCheckoutPages($request);
        if ($response['status'] == 0) {
            if (in_array($request['payment_request_from'], ['app'])) {
                $errorKeeper = [];
                foreach ($response['message'] as $index => $message) {
                    $errorKeeper[] = ['code' => $index, 'message' => $message];
                }
                return response()->json(['errors' => $errorKeeper], 403);
            } else {
                foreach ($response['message'] as $message) {
                    Toastr::error($message);
                }
                return $response['redirect'] ? redirect($response['redirect']) : redirect('/');
            }
        }

        $validator->sometimes('customer_id', 'required', function ($input) {
            return in_array($input->payment_request_from, ['app']);
        });
        $validator->sometimes('is_guest', 'required', function ($input) {
            return in_array($input->payment_request_from, ['app']);
        });

        if ($validator->fails()) { //api
            $errors = Helpers::validationErrorProcessor($validator);
            if (in_array($request['payment_request_from'], ['app'])) {
                return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
            } else {
                foreach ($errors as $value) {
                    Toastr::error(translate($value['message']));
                }
                return back();
            }
        }

        $cartGroupIds = CartManager::get_cart_group_ids(request: $request, type: 'checked');
        $carts = Cart::whereHas('product', function ($query) {
            return $query->active();
        })->whereIn('cart_group_id', $cartGroupIds)->where(['is_checked' => 1])->get();
        $productStockCheck = CartManager::product_stock_check($carts);
        if (!$productStockCheck && in_array($request['payment_request_from'], ['app'])) {
            return response()->json(['errors' => ['code' => 'product-stock', 'message' => 'The following items in your cart are currently out of stock']], 403);
        } elseif (!$productStockCheck) {
            Toastr::error(translate('the_following_items_in_your_cart_are_currently_out_of_stock'));
            return redirect()->route('shop-cart');
        }

        $verifyStatus = OrderManager::verifyCartListMinimumOrderAmount($request);
        if ($verifyStatus['status'] == 0 && in_array($request['payment_request_from'], ['app'])) {
            return response()->json(['errors' => ['code' => 'Check the minimum order amount requirement']], 403);
        } elseif ($verifyStatus['status'] == 0) {
            Toastr::info('Check the minimum order amount requirement');
            return redirect()->route('shop-cart');
        }

        if (in_array($request['payment_request_from'], ['app'])) {
            $shippingMethod = getWebConfig(name: 'shipping_method');
            $physicalProductExist = false;
            foreach ($carts as $cart) {
                if ($cart->product_type == 'physical') {
                    $physicalProductExist = true;
                }

                if ($shippingMethod == 'inhouse_shipping') {
                    $adminShipping = ShippingType::where('seller_id', 0)->first();
                    $getShippingType = isset($adminShipping) == true ? $adminShipping->shipping_type : 'order_wise';
                } else {
                    if ($cart->seller_is == 'admin') {
                        $adminShipping = ShippingType::where('seller_id', 0)->first();
                        $getShippingType = isset($adminShipping) == true ? $adminShipping->shipping_type : 'order_wise';
                    } else {
                        $seller_shipping = ShippingType::where('seller_id', $cart->seller_id)->first();
                        $getShippingType = isset($seller_shipping) == true ? $seller_shipping->shipping_type : 'order_wise';
                    }
                }

                if ($getShippingType == 'order_wise') {
                    $cartShipping = CartShipping::where('cart_group_id', $cart->cart_group_id)->first();
                    if (!isset($cartShipping) && $physicalProductExist) {
                        return response()->json(['errors' => ['code' => 'shipping-method', 'message' => 'Data not found']], 403);
                    }
                }
            }

            if (($user == 'offline' && $request['is_check_create_account'])) {
                $getAPIProcess = self::getRegisterNewCustomerAPIProcess($request);
                if ($getAPIProcess['status'] == 0) {
                    return response()->json(['message' => translate('Already_registered ')], 403);
                }
                $orderAdditionalData += [
                    'new_customer_info' => $getAPIProcess['data'],
                ];
            }
        }

        $redirectLink = $this->getCustomerPaymentRequest($request, $orderAdditionalData);

        if (in_array($request['payment_request_from'], ['app'])) {
            return response()->json([
                'redirect_link' => $redirectLink,
                'new_user' => isset($orderAdditionalData['new_customer_info']) && $orderAdditionalData['new_customer_info'] != null ? 1 : 0,
            ], 200);
        } else {
            return redirect($redirectLink);
        }
    }

    function getRegisterNewCustomerAPIProcess($request)
    {
        $newCustomerRegister = [];
        $shippingAddress = ShippingAddress::where(['customer_id' => $request['guest_id'], 'is_guest' => 1, 'id' => $request->input('address_id')])->first();
        if ($request->has('address_id') && $request['address_id'] && $shippingAddress) {
            if (User::where(['email' => $shippingAddress['email']])->orWhere(['phone' => $shippingAddress['phone']])->first()) {
                return ['status' => 0];
            } else {
                $newCustomerRegister = [
                    'status' => 1,
                    'data' => self::getRegisterNewCustomer(
                        request: $request,
                        address: $shippingAddress,
                        shippingId: $request['address_id'],
                        billingId: $request->has('billing_address_id') && $request['billing_address_id'] ? $request['billing_address_id'] : null
                    )
                ];
            }
        }

        $billingAddress = ShippingAddress::where(['customer_id' => $request['guest_id'], 'is_guest' => 1, 'id' => $request->input('billing_address_id')])->first();
        if ($request['address_id'] == null && $request->has('billing_address_id') && $request['billing_address_id'] && $billingAddress) {
            if (User::where(['email' => $billingAddress['email']])->orWhere(['phone' => $billingAddress['phone']])->first()) {
                return ['status' => 0];
            } else {
                $newCustomerRegister = [
                    'status' => 1,
                    'data' => self::getRegisterNewCustomer(
                        request: $request,
                        address: $billingAddress,
                        shippingId: null,
                        billingId: $request['billing_address_id'],
                    )
                ];
            }
        }

        return $newCustomerRegister;
    }


    function getRegisterNewCustomer($request, $address, $shippingId = null, $billingId = null): array
    {
        return [
            'name' => $address['contact_person_name'],
            'f_name' => $address['contact_person_name'],
            'l_name' => '',
            'email' => $address['email'],
            'phone' => $address['phone'],
            'is_active' => 1,
            'password' => $request['password'],
            'referral_code' => Helpers::generate_referer_code(),
            'shipping_id' => $shippingId,
            'billing_id' => $billingId,
        ];
    }

    public function success(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Payment succeeded'], 200);
    }

    public function fail(Request $request): JsonResponse|\Illuminate\Contracts\View\View
    {
        // The apps expect JSON here; but gateways also redirect the shopper's
        // BROWSER to this route, which used to dead-end on raw {"message":
        // "Payment failed"} with a 403. Browsers now get a page with a way
        // back into checkout.
        if ($request->expectsJson() || (session()->has('payment_mode') && session('payment_mode') == 'app')) {
            return response()->json(['message' => 'Payment failed'], 403);
        }
        return view(VIEW_FILE_NAMES['payment_failed'] ?? 'web-views.checkout.payment-failed');
    }

    public function web_payment_success(Request $request)
    {
        if ($request->flag == 'success') {
            if (session()->has('payment_mode') && session('payment_mode') == 'app') {
                return response()->json(['message' => 'Payment succeeded'], 200);
            } else {
                $data = [];
                if (!empty($request?->token)) {
                    $decoded = $request->token ? base64_decode($request->token) : '';
                    $parts = explode('&&', $decoded);
                    foreach ($parts as $part) {
                        [$key, $value] = explode('=', $part);
                        $data[$key] = $value;
                    }
                }
                $transactionReference = $data['transaction_reference'] ?? null;
                if ($transactionReference) {
                    $orderIds = Order::where(['transaction_ref' => $transactionReference])->get()->pluck('id')->toArray();
                    session(['order_success_ids' => $orderIds]);
                }

                Toastr::success(translate('Payment_success'));
                session()->forget('newCustomerRegister');
                if (auth()->guard('customer')->check()) {
                    return redirect()->route('account-oder');
                } else {
                    return redirect()->route('home');
                }
            }
        } else {
            if (session()->has('payment_mode') && session('payment_mode') == 'app') {
                return response()->json(['message' => 'Payment failed'], 403);
            } else {
                // Was: toast + homepage — the shopper lost all order context and
                // had to find their own way back to checkout.
                Toastr::error(translate('Payment_failed') . '!');
                return redirect()->route('payment-fail');
            }
        }
    }

    public function getCustomerPaymentRequest(Request $request, $orderAdditionalData = []): mixed
    {
        $additionalData = [
            'business_name' => getWebConfig(name: 'company_name'),
            'business_logo' => getStorageImages(path: getWebConfig('company_web_logo'), type: 'shop'),
            'payment_mode' => $request->has('payment_platform') ? $request['payment_platform'] : 'web',
        ];

        $user = Helpers::getCustomerInformation($request);

        $getGuestId = $request['is_guest'] ? $request['guest_id'] : (session('guest_id') ?? 0);
        $isGuestUser = ($user == 'offline') ? 1 : 0;
        $getCustomerID = null;
        $isGuestUserInOrder = $isGuestUser;
        if ($user == 'offline' && session('newCustomerRegister')) {
            $additionalData['new_customer_info'] = session('newCustomerRegister') ?? null;
            $additionalData['customer_id'] = $getGuestId;
            $additionalData['address_id'] = session('newCustomerRegister')['address_id'] ?? null;
            $additionalData['billing_address_id'] = session('newCustomerRegister')['billing_address_id'] ?? null;
            $getCustomerID = $getGuestId;
            $isGuestUserInOrder = 0;
        } elseif ($user == 'offline' && !session('newCustomerRegister') && isset($orderAdditionalData['new_customer_info'])) {
            $additionalData['new_customer_info'] = $orderAdditionalData['new_customer_info'];
            $getCustomerID = $getGuestId;
            $isGuestUserInOrder = $isGuestUser;
        } elseif ($user != 'offline') {
            $getCustomerID = 0;
            $isGuestUserInOrder = 0;
        }

        $additionalData['is_guest'] = $isGuestUser;
        if (in_array($request['payment_request_from'], ['app'])) {
            $additionalData['customer_id'] = $request['customer_id'];
            $additionalData['guest_id'] = $request['guest_id'];
            $additionalData['is_guest'] = $request['is_guest'];
            $additionalData['order_note'] = $request['order_note'];
            $additionalData['address_id'] = $request['address_id'];
            $additionalData['billing_address_id'] = $request['billing_address_id'];
            $additionalData['coupon_code'] = $request['coupon_code'];
            $additionalData['coupon_discount'] = $request['coupon_discount'];
            $additionalData['payment_request_from'] = $request['payment_request_from'];
        } else {
            $additionalData['customer_id'] = $user != 'offline' ? $user->id : $getCustomerID;
            $additionalData['order_note'] = session('order_note') ?? null;
            $additionalData['address_id'] = session('address_id') ?? 0;
            $additionalData['billing_address_id'] = session('billing_address_id') ?? 0;

            $additionalData['coupon_code'] = session('coupon_code') ?? null;
            $additionalData['coupon_discount'] = session('coupon_discount') ?? 0;
            $additionalData['payment_request_from'] = $request['payment_mode'] ?? 'web';
        }
        $additionalData['new_customer_id'] = $getCustomerID;
        $additionalData['is_guest_in_order'] = $isGuestUserInOrder;

        if (in_array($request['payment_request_from'], ['app'])) {
            $couponCode = $request['coupon_code'] ?? '';
        } else {
            $couponCode = session()->has('coupon_code') ? session('coupon_code') : '';
        }

        $customer = Helpers::getCustomerInformation($request);
        $vendorWiseCartList = OrderManager::processOrderGenerateData(data: [
            'coupon_code' => $couponCode,
            'requestObj' => $request,
        ]);
        $paymentAmount = collect($vendorWiseCartList)->sum('order_amount_with_tax');

        if ($customer == 'offline') {
            $address = ShippingAddress::where(['customer_id' => $request['customer_id'], 'is_guest' => 1])->latest()->first();
            if ($address) {
                $payer = new Payer(
                    $address->contact_person_name,
                    $address->email,
                    $address->phone,
                    ''
                );
            } else {
                $payer = new Payer(
                    'Contact person name',
                    '',
                    '',
                    ''
                );
            }
        } else {
            $payer = new Payer(
                $customer['f_name'] . ' ' . $customer['l_name'],
                $customer['email'],
                $customer['phone'],
                ''
            );
            if (empty($customer['phone'])) {
                Toastr::error(translate('please_update_your_phone_number'));
                return route('checkout-payment');
            }
        }

        $currency_model = getWebConfig(name: 'currency_model');
        if ($currency_model == 'multi_currency') {
            $currentCurrency = $request['current_currency_code'] ?? session('currency_code');
            $currency_code = $this->getPaymentGatewayCurrencyCode(key: $request['payment_method'], currentCurrency: $currentCurrency);
            $paymentAmount = usdToAnotherCurrencyConverter(currencyCode: $currency_code, amount: $paymentAmount);
        } else {
            $default = getWebConfig(name: 'system_default_currency');
            $currency_code = Currency::find($default)->code;
        }

        $paymentInfo = new PaymentInfo(
            success_hook: 'digital_payment_success',
            failure_hook: 'digital_payment_fail',
            currency_code: $currency_code,
            payment_method: $request['payment_method'],
            payment_platform: $request['payment_platform'],
            payer_id: $customer == 'offline' ? $request['customer_id'] : $customer['id'],
            receiver_id: '100',
            additional_data: $additionalData,
            payment_amount: $paymentAmount,
            external_redirect_link: $request['payment_platform'] == 'web' ? $request['external_redirect_link'] : null,
            attribute: 'order',
            attribute_id: idate("U")
        );

        $receiverInfo = new Receiver('receiver_name', 'example.png');

        // The shopper has been handed to the gateway. Recorded here because it is the last moment
        // the application knows about a payment that may never come back: without it, someone who
        // abandoned at the gateway is invisible and there is no abandonment rate to compute — the
        // event was in the catalogue, mapped in the recorder and charted, and nothing emitted it.
        app(Analytics::class)->paymentAttempted(
            gateway: (string) $request['payment_method'],
            outcome: 'started',
            amount: (float) $paymentAmount,
        );

        return $this->generate_link($payer, $paymentInfo, $receiverInfo);
    }

    public function customer_add_to_fund_request(Request $request): JsonResponse|Redirector|RedirectResponse
    {
        if (getWebConfig(name: 'add_funds_to_wallet') != 1) {
            if (in_array($request['payment_request_from'], ['app'])) {
                return response()->json(['message' => 'Add funds to wallet is deactivated'], 403);
            }
            Toastr::error(translate('add_funds_to_wallet_is_deactivated'));
            return back();
        }

        $validator = Validator::make($request->all(), [
            'amount' => 'required',
            'payment_method' => 'required',
            'payment_platform' => 'required',
        ]);

        if ($validator->fails()) {
            $errors = Helpers::validationErrorProcessor($validator);
            if (in_array($request->payment_request_from, ['app'])) {
                return response()->json(['errors' => $errors]);
            } else {
                foreach ($errors as $value) {
                    Toastr::error(translate($value['message']));
                }
                return back();
            }
        }

        $paymentAmount = $request['amount'];
        $currency_model = getWebConfig(name: 'currency_model');
        if ($currency_model == 'multi_currency') {
            $currentCurrency = $request['current_currency_code'] ?? session('currency_code');
            $currency_code = $this->getPaymentGatewayCurrencyCode(key: $request['payment_method'], currentCurrency: $currentCurrency);
            $paymentAmount = usdToAnotherCurrencyConverter(currencyCode: $currency_code, amount: Convert::usdPaymentModule($request['amount'], $currentCurrency));
        } else {
            $default = getWebConfig(name: 'system_default_currency');
            $currency_code = Currency::find($default)->code;
            $currentCurrency = $currency_code;
        }

        $minimumAddFundAmount = getWebConfig(name: 'minimum_add_fund_amount') ?? 0;
        $maximumAddFundAmount = getWebConfig(name: 'maximum_add_fund_amount') ?? 0;

        if (!(Convert::usdPaymentModule($request['amount'], $currentCurrency) >= Convert::usdPaymentModule($minimumAddFundAmount, 'USD')) || !(Convert::usdPaymentModule($request['amount'], $currentCurrency) <= Convert::usdPaymentModule($maximumAddFundAmount, 'USD'))) {
            $errors = [
                'minimum_amount' => $minimumAddFundAmount ?? 0,
                'maximum_amount' => $maximumAddFundAmount ?? 1000,
            ];
            if (in_array($request->payment_request_from, ['app'])) {
                return response()->json($errors, 202);
            } else {
                Toastr::error(translate('the_amount_needs_to_be_between') . ' ' . webCurrencyConverter($minimumAddFundAmount) . ' - ' . webCurrencyConverter($maximumAddFundAmount));
                return back();
            }
        }

        $additional_data = [
            'business_name' => BusinessSetting::where(['type' => 'company_name'])->first()->value,
            'business_logo' => getWebConfig('company_web_logo')['path'],
            'payment_mode' => $request->has('payment_platform') ? $request->payment_platform : 'web',
        ];

        $customer = Helpers::getCustomerInformation($request);

        if (in_array($request->payment_request_from, ['app'])) {
            $additional_data['customer_id'] = $customer->id;
            $additional_data['payment_request_from'] = $request->payment_request_from;
        }

        $payer = new Payer(
            $customer->f_name . ' ' . $customer->l_name,
            $customer['email'],
            $customer->phone,
            ''
        );

        $payment_info = new PaymentInfo(
            success_hook: 'add_fund_to_wallet_success',
            failure_hook: 'add_fund_to_wallet_fail',
            currency_code: $currency_code,
            payment_method: $request->payment_method,
            payment_platform: $request->payment_platform,
            payer_id: $customer->id,
            receiver_id: '100',
            additional_data: $additional_data,
            payment_amount: $paymentAmount,
            external_redirect_link: $request->payment_platform == 'web' ? $request->external_redirect_link : null,
            attribute: 'add_funds_to_wallet',
            attribute_id: idate("U")
        );

        $receiver_info = new Receiver('receiver_name', 'example.png');

        $redirect_link = Payment::generate_link($payer, $payment_info, $receiver_info);

        if (in_array($request['payment_request_from'], ['app'])) {
            return response()->json(['redirect_link' => $redirect_link], 200);
        } else {
            return redirect($redirect_link);
        }
    }

    public function customerOrderEditPayDueAmount(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'payment_method' => 'required',
            'payment_platform' => 'required',
            'order_due_payment_note' => 'nullable|string',
            'method_id' => 'required_if:payment_method,offline_payment',
            'payment_note' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            $errors = Helpers::validationErrorProcessor($validator);
            if ($request->payment_request_from === 'app') {
                return response()->json(['errors' => $errors]);
            }
            foreach ($errors as $error) {
                Toastr::error(translate($error['message']));
            }
            return back();
        }

        $validated = $validator->validated();
        $order = Order::find($validated['order_id']);
        if (!$order) {
            Toastr::error(translate('Order_not_found'));
            return back();
        }

        $orderEditHistory = OrderEditHistory::where('order_id', $validated['order_id'])->latest('created_at')->first();
        $customer = Helpers::getCustomerInformation($request);

        if ($validated['payment_method'] === 'wallet' && $customer != 'offline') {
            if (getWebConfig('wallet_status') != 1 && $request['payment_method'] == 'wallet') {
                Toastr::error(translate('wallet_is_deactivated'));
                return back();
            }

            $response = $this->payEditOrderDueByCustomerWallet(order: $order, customer: $customer);
            if ($response['status']) {
                OrderManager::sendPushNotificationAfterDuePayment(order: $order);
                Toastr::success($response['message']);
            } else {
                Toastr::error($response['message']);
            }
            return back();
        }

        if ($validated['payment_method'] === 'offline_payment') {
            $offlinePaymentInfo = [];
            $method = OfflinePaymentMethod::where(['id' => $validated['method_id'], 'status' => 1])->first();

            if (isset($method)) {
                $fields = array_column($method->method_informations, 'customer_input');
                $values = $request->all();

                $offlinePaymentInfo['method_id'] = $request['method_id'];
                $offlinePaymentInfo['method_name'] = $method->method_name;
                $offlinePaymentInfo['payment_note'] = $validated['payment_note'] ?? '';
                foreach ($fields as $field) {
                    if (key_exists($field, $values)) {
                        $offlinePaymentInfo[$field] = $values[$field];
                    }
                }
            }
            $orderEditHistory->update([
                'order_due_payment_method' => 'offline_payment',
                'order_due_payment_info' => $offlinePaymentInfo,
            ]);
            OrderManager::sendPushNotificationAfterDuePayment(order: $order);
            Toastr::success(translate('payment_successful'));
            return back();
        }

        if ($validated['payment_method'] === 'cash_on_delivery') {
            if ($request->filled('bring_change_amount_input')) {
                $order->bring_change_amount = $request['bring_change_amount_input'];
                if (getWebConfig('currency_model') === 'multi_currency') {
                    $currentCurrency = $request->current_currency_code ?? session('currency_code');
                    $order->bring_change_amount_currency = $this->getPaymentGatewayCurrencyCode('cash_on_delivery', $currentCurrency);
                } else {
                    $defaultCurrency = Currency::find(getWebConfig('system_default_currency'))->code;
                    $order->bring_change_amount_currency = $defaultCurrency;
                }
                $order->save();
            }
            $orderEditHistory->update(['order_due_payment_method' => 'cash_on_delivery']);
            OrderManager::sendPushNotificationAfterDuePayment(order: $order);
            Toastr::success(translate('payment_method_updated'));
            return back();
        }

        $response = $this->payEditOrderDueByDigitalPayment(request: $request, order: $order, customer: $customer);
        if ($response['status'] && isset($response['message'])) {
            Toastr::success($response['message']);
        } elseif (!$response['status'] && isset($response['message'])) {
            Toastr::error($response['message']);
        }

        if ($response['redirect_link']) {
            return $request->payment_request_from === 'app' ? response()->json(['redirect_link' => $response['redirect_link']]) : redirect($response['redirect_link']);
        }
        return back();
    }


}
