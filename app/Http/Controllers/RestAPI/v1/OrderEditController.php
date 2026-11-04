<?php

namespace App\Http\Controllers\RestAPI\v1;

use App\Events\DigitalProductOtpVerificationEvent;
use App\Events\RefundEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\RefundStoreRequest;
use App\Models\AdminWallet;
use App\Models\Cart;
use App\Models\Currency;
use App\Models\DigitalProductOtpVerification;
use App\Models\OfflinePaymentMethod;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\OrderDetailsRewards;
use App\Models\OrderEditHistory;
use App\Models\RefundRequest;
use App\Models\Setting;
use App\Models\ShippingAddress;
use App\Services\OrderService;
use App\Traits\CommonTrait;
use App\Traits\FileManagerTrait;
use App\Traits\OrderEditManager;
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

class OrderEditController extends Controller
{
    use CommonTrait, FileManagerTrait, OrderEditManager;

    public function __construct(
        private readonly OrderService $orderService,
    )
    {
    }

    public function duePaymentByWallet(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'payment_method' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $order = Order::with(['latestEditHistory'])->where('id', $request['order_id'])->first();
        if (!$order) {
            return response()->json(['message' => translate('Order_not_found')], 401);
        }

        if (getWebConfig('wallet_status') != 1 && $request['payment_method'] == 'wallet') {
            return response()->json(['message' => translate('wallet_is_deactivated')], 401);
        }

        $user = Helpers::getCustomerInformation($request);
        if ($user != 'offline') {
            $response = $this->payEditOrderDueByCustomerWallet(order: $order, customer: $user);
            return response()->json([
                'message' => $response['message'],
            ], ($response['status'] ? 200 : 403));
        }

        return response()->json(['message' => 'Unauthorized'], 401);
    }

    public function duePaymentByCod(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'payment_method' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $order = Order::with(['latestEditHistory'])->where('id', $request['order_id'])->first();
        if (!$order) {
            return response()->json(['message' => translate('Order_not_found')], 401);
        }


        OrderEditHistory::where('id', $order?->latestEditHistory?->id)->update([
            'order_due_payment_method' => 'cash_on_delivery',
        ]);
        if ($request['bring_change_amount']) {
            if (getWebConfig(name: 'currency_model') == 'multi_currency') {
                $currencyCode = $request->current_currency_code ?? Currency::find(getWebConfig(name: 'system_default_currency'))->code;
            } else {
                $currencyCode = Currency::find(getWebConfig(name: 'system_default_currency'))->code;
            }
            Order::where('id', $order['id'])->update([
                'bring_change_amount' => $request['bring_change_amount'] ?? 0,
                'bring_change_amount_currency' => $currencyCode,
            ]);
        }
        return response()->json(['message' => translate('payment_method_updated')], 200);
    }

    public function duePaymentByOfflinePayment(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'payment_method' => 'required',
            'order_due_payment_note' => 'nullable|string',
            'method_id' => 'required_if:payment_method,offline_payment',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $order = Order::with(['latestEditHistory'])->where('id', $request['order_id'])->first();
        if (!$order) {
            return response()->json(['message' => translate('Order_not_found')], 401);
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

        OrderEditHistory::where('id', $order?->latestEditHistory?->id)->update([
            'order_due_payment_method' => 'offline_payment',
            'order_due_payment_info' => $offlinePaymentInfo,
            'order_due_payment_note' => $request['order_due_payment_note'] ?? '',
        ]);
        return response()->json(['message' => translate('Payment_Method_Updated')], 200);
    }

    public function duePaymentByDigitalPayment(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'payment_method' => 'required',
            'current_currency_code' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $order = Order::with(['latestEditHistory'])->where('id', $request['order_id'])->first();
        if (!$order) {
            return response()->json(['message' => translate('Order_not_found')], 401);
        }

        $customer = Helpers::getCustomerInformation($request);
        $response = $this->payEditOrderDueByDigitalPayment(request: $request, order: $order, customer: $customer);

        if (!$response['status'] && isset($response['message'])) {
            return response()->json(['message' => $response['message']], 401);
        }

        if ($response['redirect_link']) {
            return response()->json(['redirect_link' => $response['redirect_link']]);
        }

        return response()->json(['message' => $response['message'] ?? 'Failed'], 403);
    }

}
