<?php

namespace App\Http\Controllers\Payment_Methods;

use App\Models\PaymentRequest;
use App\Models\User;
use App\Traits\Processor;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Razorpay\Api\Api;

class RazorPayController extends Controller
{
    use Processor;

    private PaymentRequest $payment;
    private $user;

    public function __construct(PaymentRequest $payment, User $user)
    {
        $config = $this->payment_config('razor_pay', 'payment_config');
        $razor = false;
        if (!is_null($config) && $config->mode == 'live') {
            $razor = json_decode($config->live_values);
        } elseif (!is_null($config) && $config->mode == 'test') {
            $razor = json_decode($config->test_values);
        }

        if ($razor) {
            $config = array(
                'api_key' => $razor->api_key,
                'api_secret' => $razor->api_secret
            );
            Config::set('razor_config', $config);
        }

        $this->payment = $payment;
        $this->user = $user;
    }

    public function index(Request $request): View|Factory|JsonResponse|Application
    {
        $validator = Validator::make($request->all(), [
            'payment_id' => 'required|uuid'
        ]);

        if ($validator->fails()) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_400, null, $this->error_processor($validator)), 400);
        }

        $data = $this->payment::where(['id' => $request['payment_id']])->where(['is_paid' => 0])->first();
        if (!isset($data)) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_204), 200);
        }
        $payer = json_decode($data['payer_information']);

        if ($data['additional_data'] != null) {
            $business = json_decode($data['additional_data']);
            $business_name = $business->business_name ?? "my_business";
            $business_logo = $business->business_logo ?? url('/');
        } else {
            $business_name = "my_business";
            $business_logo = url('/');
        }

        return view('payment.razor-pay', compact('data', 'payer', 'business_logo', 'business_name'));
    }

    public function payment(Request $request): JsonResponse|Redirector|RedirectResponse|Application
    {
        $input = $request->all();
        $paymentRecord = $this->payment::where(['id' => $request['payment_id']])->first();

        // Same exposure as callback(): razorpay_payment_id and payment_id are both caller-supplied,
        // and nothing checked that they belonged together, that the amounts matched, or that the
        // record was still unpaid. One genuine ₹1 payment could settle any order, repeatedly.
        if (!$paymentRecord || !$this->verifiedRazorpayPayment($input, $paymentRecord)) {
            return $this->payment_response($paymentRecord, 'fail');
        }

        $api = new Api(config('razor_config.api_key'), config('razor_config.api_secret'));
        $payment = $api->payment->fetch($input['razorpay_payment_id']);

        if (count($input) && !empty($input['razorpay_payment_id'])) {
            // Capture only what has not been captured already; re-capturing an authorised payment
            // throws, which would surface to the customer as a failure on a payment they made.
            if (($payment['status'] ?? '') === 'authorized') {
                $payment->capture(['amount' => $payment['amount']]);
            }

            $this->payment::where(['id' => $request['payment_id']])->update([
                'payment_method' => 'razor_pay',
                'is_paid' => 1,
                'transaction_id' => $input['razorpay_payment_id'],
            ]);
            $data = $this->payment::where(['id' => $request['payment_id']])->first();
            if (isset($data) && function_exists($data->success_hook)) {
                call_user_func($data->success_hook, $data);
            }
            return $this->payment_response($data, 'success');
        }
        $payment_data = $this->payment::where(['id' => $request['payment_id']])->first();
        if (isset($payment_data) && function_exists($payment_data->failure_hook)) {
            call_user_func($payment_data->failure_hook, $payment_data);
        }
        return $this->payment_response($payment_data, 'fail');
    }

    public function callback(Request $request): JsonResponse|Redirector|RedirectResponse|Application
    {
        $input = $request->all();
        $payment_data = $this->payment::where(['id' => $request->payment_request_id])->first();

        // Previously this method NEVER CONTACTED RAZORPAY. It marked an order paid from request
        // parameters alone, so anyone able to reach the route — any logged-in customer, the CSRF
        // token comes free with any page — could POST their own pending order id with an arbitrary
        // razorpay_payment_id and receive the goods without paying anything at all. It also had no
        // is_paid guard, so the same call worked repeatedly.
        if (!$payment_data || !$this->verifiedRazorpayPayment($input, $payment_data)) {
            return $this->payment_response($payment_data, 'fail');
        }

        if (count($input) && !empty($input['razorpay_payment_id'])) {
            if (function_exists($payment_data->success_hook)) {
                $payment_data->payment_method = 'razor_pay';
                $payment_data->is_paid = 1;
                $payment_data->transaction_id = $input['razorpay_payment_id'];
                $payment_data->save();
                call_user_func($payment_data->success_hook, $payment_data);
                return $this->payment_response($payment_data, 'success');
            }
        }
        return $this->payment_response($payment_data, 'fail');
    }

    /**
     * Confirm a Razorpay payment is real, is for THIS payment record, and has not already settled
     * it.
     *
     * Both `razorpay_payment_id` and the local payment id arrive as request parameters, so nothing
     * about the pairing can be trusted. Four independent checks, each closing a different attack:
     *
     *  1. Already paid — refuse. Without this a single genuine payment can be replayed to settle
     *     the same record repeatedly, and a captured payment can be reused across orders.
     *  2. Signature — Razorpay signs (order_id|payment_id) with the API secret on redirect. A valid
     *     signature proves the pairing came from Razorpay rather than from the caller. When the
     *     redirect carries no signature the remaining checks still apply, so in-flight checkouts
     *     are not broken by deploying this.
     *  3. The payment is actually captured or authorised at Razorpay — not merely a real id.
     *  4. Amount and currency match what this record expects, in minor units. This alone defeats
     *     paying ₹1 and settling a ₹10,000 order.
     *
     * Fails closed: any exception means "not verified".
     */
    private function verifiedRazorpayPayment(array $input, PaymentRequest $payment): bool
    {
        if ((int) $payment->is_paid === 1) {
            return false;
        }

        $paymentId = $input['razorpay_payment_id'] ?? null;
        if (empty($paymentId)) {
            return false;
        }

        try {
            $api = new Api(config('razor_config.api_key'), config('razor_config.api_secret'));

            if (!empty($input['razorpay_signature']) && !empty($input['razorpay_order_id'])) {
                $api->utility->verifyPaymentSignature([
                    'razorpay_order_id'   => $input['razorpay_order_id'],
                    'razorpay_payment_id' => $paymentId,
                    'razorpay_signature'  => $input['razorpay_signature'],
                ]); // throws on mismatch
            }

            $remote = $api->payment->fetch($paymentId);

            if (!in_array($remote['status'] ?? '', ['captured', 'authorized'], true)) {
                return false;
            }

            $expectedMinor = (int) round(((float) $payment->payment_amount) * 100);
            if ((int) ($remote['amount'] ?? 0) !== $expectedMinor) {
                return false;
            }

            $remoteCurrency = strtolower((string) ($remote['currency'] ?? ''));
            $localCurrency = strtolower((string) ($payment->currency_code ?? ''));
            if ($remoteCurrency !== '' && $localCurrency !== '' && $remoteCurrency !== $localCurrency) {
                return false;
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function cancel(Request $request): JsonResponse|Redirector|RedirectResponse|Application
    {
        $payment_data = $this->payment::where(['id' => $request['payment_id']])->first();
        return $this->payment_response($payment_data, 'fail');
    }

    public function createOrder(Request $request): JsonResponse|Redirector|RedirectResponse|Application
    {
        $request->validate([
            'payment_request_id' => 'required|uuid',
        ]);

        // The amount and currency now come from the STORED payment record, never from the request.
        // They used to be read straight out of the request body, so a caller could ask for a
        // Razorpay order of ₹1 against a ₹10,000 checkout, pay it for real, and arrive at the
        // callback holding a genuine payment for the wrong amount.
        $paymentRecord = $this->payment::where(['id' => $request['payment_request_id']])
            ->where(['is_paid' => 0])
            ->first();

        if (!$paymentRecord) {
            return response()->json(['status' => false, 'message' => translate('payment_request_not_found')], 404);
        }

        try {
            $api = new Api(config('razor_config.api_key'), config('razor_config.api_secret'));

            $razorpayOrder = $api->order->create([
                'receipt' => 'order_' . uniqid(),
                'amount' => (int) round(((float) $paymentRecord->payment_amount) * 100),
                'currency' => $paymentRecord->currency_code,
                'payment_capture' => 1,
                // Binds the Razorpay order to this record, so a later payment can be traced back to
                // the checkout it was created for.
                'notes' => ['payment_request_id' => (string) $paymentRecord->id],
            ]);

            return response()->json([
                'status' => true,
                'payment_request_id' => $request['payment_request_id'],
                'order_id' => $razorpayOrder['id'],
                'amount' => $razorpayOrder['amount'],
                'currency' => $razorpayOrder['currency']
            ]);
        } catch (\Exception $exception) {
            return response()->json([
                'status' => false,
                'message' => $exception->getMessage()
            ]);
        }
    }

    public function verifyPayment(Request $request): JsonResponse|Redirector|RedirectResponse|Application
    {
        $api = new Api(config('razor_config.api_key'), config('razor_config.api_secret'));

        // Verify payment signature
        $api->utility->verifyPaymentSignature([
            'razorpay_order_id' => $request['order_id'],
            'razorpay_payment_id' => $request['payment_id'],
            'razorpay_signature' => $request['signature']
        ]);

        // Fetch payment details using payment_id
        $payment = $api->payment->fetch($request['payment_id']);

        if ($payment && isset($payment['status']) && $payment['status'] == 'captured') {
            $this->payment::where(['id' => $request['payment_request_id']])->update([
                'payment_method' => 'razor_pay',
                'is_paid' => 1,
                'transaction_id' => $request['payment_id'],
            ]);
            $data = $this->payment::where(['id' => $request['payment_request_id']])->first();
            if (isset($data) && function_exists($data->success_hook)) {
                call_user_func($data->success_hook, $data);
            }
            return $this->payment_response($data, 'success');
        }
        $paymentData = $this->payment::where(['id' => $request['payment_request_id']])->first();
        if (isset($paymentData) && function_exists($paymentData->failure_hook)) {
            call_user_func($paymentData->failure_hook, $paymentData);
        }
        return $this->payment_response($paymentData, 'fail');
    }
}
