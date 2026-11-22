<?php

namespace App\Http\Controllers\Payment_Methods;

use App\Models\PaymentRequest;
use App\Models\User;
use App\Traits\Processor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

class PaymobController extends Controller
{
    use Processor;

    private mixed $config_values = null;

    private PaymentRequest $payment;
    private User $user;
    private string $base_url;

    private array $supportedCountries = [
        'egypt' => 'https://accept.paymob.com',
        'PAK' => 'https://pakistan.paymob.com',
        'KSA' => 'https://ksa.paymob.com',
        'oman' => 'https://oman.paymob.com',
        'UAE' => 'https://uae.paymob.com',
    ];
    private string $defaultBaseUrl = 'https://accept.paymob.com';

    public function __construct(PaymentRequest $payment, User $user)
    {
        $config = $this->payment_config('paymob_accept', 'payment_config');
        if (!is_null($config) && $config->mode == 'live') {
            $this->config_values = json_decode($config->live_values, true);
        } elseif (!is_null($config) && $config->mode == 'test') {
            $this->config_values = json_decode($config->test_values, true);
        }
        $this->payment = $payment;
        $this->user = $user;

        // A configuration row whose mode is neither "live" nor "test" leaves the credential blob
        // unread, and indexing it then throws — in the CONSTRUCTOR, which Laravel runs whenever it
        // gathers this route's middleware. One malformed settings row took down `route:list` and
        // every page that enumerates routes. An unusable configuration falls back to the default
        // base URL, which is a gateway that cannot take a payment rather than a fatal error.
        $country = $this->config_values['supported_country'] ?? null;

        if ($country !== null && array_key_exists($country, $this->supportedCountries)) {
            $this->base_url = $this->supportedCountries[$country];
        } else {
            $this->base_url = $this->defaultBaseUrl;
        }
    }

    protected function cURL($url, $json)
    {
        $ch = curl_init($url);

        $headers = array();
        $headers[] = 'Content-Type: application/json';

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $output = curl_exec($ch);

        curl_close($ch);
        return json_decode($output);
    }

    /**
     * Create the payment intention at Paymob and hand back what it answered.
     *
     * The one outbound call of credit(), in a method of its own for the same reason cURL() above is
     * one: it is the seam a test needs. The URL, the two headers and the JSON body are byte for byte
     * what credit() sent when this block was inline, so nothing about the request to Paymob changed.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null what Paymob answered, decoded, or null when it answered nothing usable
     */
    protected function postIntention(string $url, array $data, string $token): ?array
    {
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Token ' . $token,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        $response = curl_exec($ch);
        curl_close($ch);

        $decoded = json_decode((string) $response, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function credit(Request $request): JsonResponse|RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'payment_id' => 'required|uuid'
        ]);

        if ($validator->fails()) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_400, null, $this->error_processor($validator)), 400);
        }

        $payment_data = $this->payment::where(['id' => $request['payment_id']])->where(['is_paid' => 0])->first();
        if (!isset($payment_data)) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_204), 200);
        }

        session()->put('payment_id', $payment_data->id);

        if ($payment_data['additional_data'] != null) {
            $business = json_decode($payment_data['additional_data']);
            $business_name = $business->business_name ?? "my_business";
        } else {
            $business_name = "my_business";
        }

        $payer = json_decode($payment_data['payer_information']);
        $url = $this->base_url . '/v1/intention/';
        $config = $this->config_values;
        $token = $config['secret_key']; //secret key

        // Data for the request
        $integration_id = (int)$config['integration_id'];
        $data = [
            'amount' => round($payment_data->payment_amount * 100),
            'currency' => $payment_data->currency_code,
            'payment_methods' => [$integration_id], //integration id will be integer
            'items' => [
                [
                    'name' => 'payable amount',
                    'amount' => round($payment_data->payment_amount * 100),
                    'description' => 'payable amount',
                    'quantity' => 1,
                ]
            ],
            'billing_data' => [
                "apartment" => "N/A",
                "email" => !empty($payer->email) ? $payer->email : 'test@gmail.com',
                "floor" => "N/A",
                "first_name" => !empty($payer->name) ? $payer->name : "rashed",
                "street" => "N/A",
                "building" => "N/A",
                "phone_number" => !empty($payer->phone) ? $payer->phone : "0182780000000",
                "shipping_method" => "PKG",
                "postal_code" => "N/A",
                "city" => "N/A",
                "country" => "N/A",
                "last_name" => !empty($payer->name) ? $payer->name : "rashed",
                "state" => "N/A",
            ],
            'special_reference' => time(),
            'customer' => [
                'first_name' => !empty($payer->name) ? $payer->name : "rashed",
                'last_name' => !empty($payer->name) ? $payer->name : "rashed",
                'email' => !empty($payer->email) ? $payer->email : 'test@gmail.com',
                'extras' => [
                    're' => '22',
                ],
            ],
            'extras' => [
                'ee' => 22,
            ],
            "redirection_url" => route('paymob.callback'),
        ];

        $result = $this->postIntention(url: $url, data: $data, token: (string) $token);

        if (!isset($result['client_secret'])) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_204), 200);
        }
        $secret_key = $result['client_secret'];
        $publicKey = $config['public_key'];
        $urlRedirect = $this->base_url . "/unifiedcheckout/?publicKey=$publicKey&clientSecret=$secret_key";
        return redirect()->to($urlRedirect);

    }

    public function createOrder($token, $payment_data, $business_name)
    {
        $items[] = [
            'name' => $business_name,
            'amount_cents' => round($payment_data->payment_amount * 100),
            'description' => 'payment ID :' . $payment_data->id,
            'quantity' => 1
        ];

        $data = [
            "auth_token" => $token,
            "delivery_needed" => "false",
            "amount_cents" => round($payment_data->payment_amount * 100),
            "currency" => $payment_data->currency_code,
            "items" => $items,

        ];

        return $this->cURL(
            $this->base_url . '/api/ecommerce/orders',
            $data
        );
    }

    public function getPaymentToken($order, $token, $payment_data, $payer)
    {
        $value = $payment_data->payment_amount;
        $billingData = [
            "apartment" => "N/A",
            "email" => !empty($payer->email) ? $payer->email : 'test@gmail.com',
            "floor" => "N/A",
            "first_name" => !empty($payer->name) ? $payer->name : "rashed",
            "street" => "N/A",
            "building" => "N/A",
            "phone_number" => !empty($payer->phone) ? $payer->phone : "0182780000000",
            "shipping_method" => "PKG",
            "postal_code" => "N/A",
            "city" => "N/A",
            "country" => "N/A",
            "last_name" => !empty($payer->name) ? $payer->name : "rashed",
            "state" => "N/A",
        ];

        $data = [
            "auth_token" => $token,
            "amount_cents" => round($value * 100),
            "expiration" => 3600,
            "order_id" => $order->id,
            "billing_data" => $billingData,
            "currency" => $payment_data->currency_code,
            "integration_id" => is_array($this->config_values) ? $this->config_values['integration_id'] : $this->config_values->integration_id
        ];

        $response = $this->cURL(
            $this->base_url . '/api/acceptance/payment_keys',
            $data
        );

        return $response->token;
    }

    public function callback(Request $request)
    {
        $data = $request->all();
        ksort($data);
        /*
         * A return that carries no signature at all used to index a key that was not there, which on
         * PHP 8 is a thrown error and a 500 — a malformed call answering a server error instead of a
         * refusal. Absent reads as empty, and empty is refused below with everything else.
         */
        $hmac = is_scalar($data['hmac'] ?? null) ? (string) $data['hmac'] : '';
        $array = [
            'amount_cents',
            'created_at',
            'currency',
            'error_occured',
            'has_parent_transaction',
            'id',
            'integration_id',
            'is_3d_secure',
            'is_auth',
            'is_capture',
            'is_refunded',
            'is_standalone_payment',
            'is_voided',
            'order',
            'owner',
            'pending',
            'source_data_pan',
            'source_data_sub_type',
            'source_data_type',
            'success',
        ];
        $connectedString = '';
        foreach ($data as $key => $element) {
            if (in_array($key, $array)) {
                $connectedString .= $element;
            }
        }
        $secret = is_array($this->config_values) ? $this->config_values['hmac'] : $this->config_values->hmac;
        $expected = hash_hmac('sha512', $connectedString, (string) $secret);

        /*
         * hash_equals, not `==`: a signature is compared in constant time, so how long the comparison takes
         * says nothing about how much of the signature was right. And `success` is read
         * with the same care as the signature — a return that omits it is refused rather than raising
         * an error. Both halves must hold, and an empty signature can never satisfy the first.
         */
        $isAuthentic = $hmac !== '' && hash_equals($expected, $hmac);

        if ($isAuthentic && ($data['success'] ?? null) === "true") {

            $this->payment::where(['id' => session('payment_id')])->update([
                'payment_method' => 'paymob_accept',
                'is_paid' => 1,
                'transaction_id' => session('payment_id'),
            ]);

            $payment_data = $this->payment::where(['id' => session('payment_id')])->first();

            if (isset($payment_data) && function_exists($payment_data->success_hook)) {
                call_user_func($payment_data->success_hook, $payment_data);
            }
            return $this->payment_response($payment_data, 'success');
        }
        $payment_data = $this->payment::where(['id' => session('payment_id')])->first();
        if (isset($payment_data) && function_exists($payment_data->failure_hook)) {
            call_user_func($payment_data->failure_hook, $payment_data);
        }
        return $this->payment_response($payment_data, 'fail');
    }
}
