<?php

namespace App\Http\Controllers\Payment_Methods;

use App\Models\PaymentRequest;
use App\Traits\Processor;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Stripe\Checkout\Session;
use Stripe\Stripe;

class StripePaymentController extends Controller
{
    use Processor;

    private $config_values = null;
    private PaymentRequest $payment;

    public function __construct(PaymentRequest $payment)
    {
        $config = $this->payment_config('stripe', 'payment_config');
        if (!is_null($config) && $config->mode == 'live') {
            $this->config_values = json_decode($config->live_values);
        } elseif (!is_null($config) && $config->mode == 'test') {
            $this->config_values = json_decode($config->test_values);
        }
        $this->payment = $payment;
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
        $config = $this->config_values;

        return view('payment.stripe', compact('data', 'config'));
    }

    public function payment_process_3d(Request $request): JsonResponse
    {
        $data = $this->payment::where(['id' => $request['payment_id']])->where(['is_paid' => 0])->first();
        if (!isset($data)) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_204), 200);
        }
        $payment_amount = $data['payment_amount'];

        Stripe::setApiKey($this->config_values->api_key);
        header('Content-Type: application/json');
        $currency_code = $data->currency_code;

        if ($data['additional_data'] != null) {
            $business = json_decode($data['additional_data']);
            $business_name = $business->business_name ?? "my_business";
            $business_logo = $business->business_logo ??  url('/');
        } else {
            $business_name = "my_business";
            $business_logo = url('/');
        }

        $checkout_session = Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => $currency_code ?? 'usd',
                    'unit_amount' => round($payment_amount, 2) * 100,
                    'product_data' => [
                        'name' => $business_name,
                        'images' => [$business_logo],
                    ],
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            // Bind the Stripe session to THIS payment record. success() verifies this back, so a
            // session cannot be used to settle a different (or another customer's) payment.
            'metadata' => [
                'payment_id' => (string) $data->id,
            ],
            'success_url' => url('/') . '/payment/stripe/success?payment_session_id={CHECKOUT_SESSION_ID}&payment_id=' . $data->id,
            'cancel_url' => url()->previous(),
        ]);

        return response()->json(['id' => $checkout_session->id]);
    }

    public function success(Request $request)
    {
        Stripe::setApiKey($this->config_values->api_key);
        $session = Session::retrieve($request->get('payment_session_id'));

        $payment = $this->payment::where(['id' => $request['payment_id']])->first();

        // Idempotency: a re-opened success URL (or a retry) must not run the success hook twice,
        // which would duplicate order/stock/wallet side effects.
        if (isset($payment) && $payment->is_paid) {
            return $this->payment_response($payment, 'success');
        }

        if (isset($payment) && $session->payment_status == 'paid' && $session->status == 'complete'
            && $this->sessionBelongsToPayment($session, $payment)) {

            $this->payment::where(['id' => $request['payment_id']])->update([
                'payment_method' => 'stripe',
                'is_paid' => 1,
                'transaction_id' => $session->payment_intent,
            ]);

            $data = $this->payment::where(['id' => $request['payment_id']])->first();

            if (isset($data) && function_exists($data->success_hook)) {
                call_user_func($data->success_hook, $data);
            }

            return $this->payment_response($data,'success');
        }
        $payment_data = $this->payment::where(['id' => $request['payment_id']])->first();
        if (isset($payment_data) && function_exists($payment_data->failure_hook)) {
            call_user_func($payment_data->failure_hook, $payment_data);
        }
        return $this->payment_response($payment_data,'fail');
    }

    /**
     * Verify the completed Stripe session actually belongs to the payment record being settled.
     *
     * Without this, both `payment_session_id` and `payment_id` are attacker-supplied query
     * parameters: one genuinely-paid session could be replayed to mark ANY other (cheaper or
     * unrelated) payment as paid, and reused indefinitely. Two independent checks:
     *
     *  1. metadata.payment_id — set when the session is created, so a session is bound to exactly
     *     one payment record. (Absent on sessions created before this fix; the amount check below
     *     still protects those, so in-flight checkouts are not broken.)
     *  2. amount_total — the captured amount must match what this payment expects, in the same
     *     currency. This alone defeats settling an expensive order with a cheap session.
     */
    private function sessionBelongsToPayment(object $session, object $payment): bool
    {
        $metadataPaymentId = $session->metadata->payment_id ?? null;
        if ($metadataPaymentId !== null && (string) $metadataPaymentId !== (string) $payment->id) {
            return false;
        }

        $expectedMinor = (int) round(((float) $payment->payment_amount) * 100);
        $paidMinor = (int) ($session->amount_total ?? 0);
        if ($paidMinor !== $expectedMinor) {
            return false;
        }

        $sessionCurrency = strtolower((string) ($session->currency ?? ''));
        $paymentCurrency = strtolower((string) ($payment->currency_code ?? ''));
        if ($sessionCurrency !== '' && $paymentCurrency !== '' && $sessionCurrency !== $paymentCurrency) {
            return false;
        }

        return true;
    }
}
