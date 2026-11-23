<?php

namespace App\Http\Controllers\Payment_Methods;

use App\Models\PaymentRequest;
use App\Services\Payments\GatewayConfirmations;
use App\Models\User;
use App\Services\Payments\GatewayAccountSecrets;
use App\Services\Payments\GatewayReturnVerification;
use App\Services\Payments\PaymentAccountBinding;
use App\Traits\Processor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class PaymobController extends Controller
{
    use Processor;

    /** The key this shop stores Paymob's settings and its payments under. */
    private const GATEWAY = 'paymob_accept';

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
        $config = $this->payment_config(self::GATEWAY, 'payment_config');
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
    /**
     * The intention call, made through the HTTP client the rest of this code base uses for a gateway rather than a raw curl handle.
     *
     * The handle carried no timeout of any kind, so a Paymob that accepted the connection and never answered held the customer's request
     * open for as long as PHP allowed; and it followed redirects, so a 3xx would have re-sent the Authorization header, and the secret key
     * with it, to whatever host the redirect named. Both are closed here. What the customer sees is unchanged: an answer without a client
     * secret, a refusal and a dropped connection all end in the same "information not found", exactly as `curl_exec` returning false did.
     *
     * It stays a method of its own because that is the seam PaymobSaudiBaselineTest stubs to drive the Saudi return without calling Paymob.
     */
    protected function postIntention(string $url, array $data, string $token): ?array
    {
        try {
            $response = Http::withHeaders(['Authorization' => 'Token ' . $token, 'Content-Type' => 'application/json'])
                ->connectTimeout((int) config('gcc.storefronts.payment_accounts.inquiry.connect_timeout', 10))
                ->timeout((int) config('gcc.storefronts.payment_accounts.inquiry.timeout', 30))
                ->withoutRedirecting()
                ->post($url, $data);
        } catch (ConnectionException) {
            return null;
        }

        $decoded = $response->json();

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
        $credentials = $this->credentialsFor(payment: $payment_data);
        $token = $credentials['secret_key'];

        if ($token === null || $credentials['integration_id'] === null || $credentials['public_key'] === null) {
            // Nothing to call Paymob with. Answering rather than throwing, because a half-configured gateway is an operator's mistake and not a
            // 500 for the customer; the payment row stays unpaid and the customer is returned to choose another method.
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_204), 200);
        }

        // Data for the request
        $integration_id = (int) $credentials['integration_id'];
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
            /*
             * Paymob's own unique reference for this intention, and OUR identifier for the payment.
             *
             * It was `time()`: two customers who started a payment in the same second were given the same reference, which Paymob holds unique, so
             * the second one's intention was refused for a reason neither they nor an operator could see. And `time()` names nothing, so a payment
             * paid at Paymob and never returned from could not be found there by any reference of ours -- which is the first thing a recovery sweep
             * for Paymob needs (D2-12, owner decision R-071).
             */
            'special_reference' => (string) $payment_data->id,
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

        if (!is_array($result) || !isset($result['client_secret'])) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_204), 200);
        }

        $secret_key = $result['client_secret'];
        $publicKey = $credentials['public_key'];
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
        $hmac = $data['hmac'] ?? null;
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
            // Only a scalar is part of what Paymob signs. `?id[]=1` used to be concatenated as an array, which this application's error handler turns
            // into an exception, so a crafted address answered 500 instead of simply failing to match the signature.
            if (in_array($key, $array, true) && is_scalar($element)) {
                $connectedString .= $element;
            }
        }
        $paymentId = (string) session('payment_id');
        $waiting = $paymentId === '' ? null : $this->payment::where(['id' => $paymentId])->first();
        $verification = app(GatewayReturnVerification::class);

        $signed = $verification->signatureHolds(
            algorithm: 'sha512',
            signedString: $connectedString,
            provided: is_string($hmac) ? $hmac : null,
            secret: $verification->signingSecret(
                payment: $waiting,
                name: 'hmac',
                platformSecret: fn (): ?string => $this->platformHmacSecret(),
            ),
        );

        if (!$signed) {
            // Not Paymob's return, or this shop has no secret to tell whether it is. Either way it decides nothing about the payment, so no hook runs
            // and nothing is marked failed on the strength of it.
            //
            // TWO MERGED BRANCHES DISAGREE HERE and this keeps the later one. PaymobSaudiBaselineTest wants the failure hook run, which is what the
            // platform did before per-company accounts landed and what releases the order's hold; CompanyAccountsAreDormantTest is named
            // test_an_unauthenticated_return_no_longer_runs_the_failure_hook and wants it NOT run, so that nobody who can reach this address and guess
            // a payment id can fire another customer's failure hook. Both are the owner's work and both are in main. Left as merged, awaiting the
            // owner's decision; the Saudi baseline case is the one red test it leaves.
            return $this->payment_response($waiting, 'fail');
        }

        $confirmation = $verification->confirm(payment: $waiting, gateway: self::GATEWAY, reference: (string) ($data['id'] ?? ''));

        if ($verification->confirmsWithGateway(gateway: self::GATEWAY) && !$confirmation->isPaid()) {
            // The server asked Paymob and did not get a "paid" back. A refusal is a failure; an unknown answer is neither, and guessing either way is
            // how a paid customer is told their payment failed.
            return $confirmation->isRefused() ? $this->fail($waiting) : $this->payment_response($waiting, 'fail');
        }

        // The RETURN's flag is read exactly as it always was: Paymob writes `success=true`, and only that string settles a payment.
        // isTrue() is for the gateway's own API answer, where the booleans really are booleans; applying it here as well widened the
        // return so that `TRUE` and `1` settled an order too, which PaymobSaudiBaselineTest names one by one as returns that must not.
        $success = $confirmation->isPaid() || ($data['success'] ?? null) === 'true';

        if ($success) {
            $payment_data = $this->settle(
                paymentId: $paymentId,
                transaction: (string) ($confirmation->reference ?? $data['id'] ?? ''),
                minorUnits: $confirmation->isPaid() ? $confirmation->minorUnits : ($data['amount_cents'] ?? null),
                currency: (string) ($confirmation->isPaid() ? $confirmation->currency : ($data['currency'] ?? '')),
            );

            if ($payment_data === null) {
                return $this->payment_response($waiting, 'fail');
            }
            if (function_exists($payment_data->success_hook)) {
                call_user_func($payment_data->success_hook, $payment_data);
            }
            return $this->payment_response($payment_data, 'success');
        }

        return $this->fail($waiting);
    }

    /**
     * The credentials this payment is taken with: the ones of the company's own account when the payment was stamped with one (D2-07, D2-08), else the
     * one platform-wide settings row, which is what every payment uses today and what the Saudi configuration is.
     *
     * A Kuwait payment stamped with the Kuwait account therefore goes to Paymob with the Kuwait account's secret key and integration id, and never
     * with the Saudi row's.
     *
     * @return array{secret_key: ?string, integration_id: ?string, public_key: ?string}
     */
    private function credentialsFor(PaymentRequest $payment): array
    {
        $account = app(PaymentAccountBinding::class)->accountOf(payment: $payment);

        if ($account !== null) {
            $secrets = app(GatewayAccountSecrets::class);

            return [
                'secret_key' => $secrets->read(account: $account, name: 'secret_key'),
                'integration_id' => $secrets->read(account: $account, name: 'integration_id'),
                'public_key' => $secrets->read(account: $account, name: 'public_key'),
            ];
        }

        return [
            'secret_key' => $this->platformValue(name: 'secret_key'),
            'integration_id' => $this->platformValue(name: 'integration_id'),
            'public_key' => $this->platformValue(name: 'public_key'),
        ];
    }

    /** One value of the platform settings row, or null when the row does not hold it. */
    private function platformValue(string $name): ?string
    {
        $values = $this->config_values;
        $value = is_array($values) ? ($values[$name] ?? null) : ($values->{$name} ?? null);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /** Paymob said the payment did not go through: the failure hook runs, as it always has. */
    private function fail(?PaymentRequest $payment): mixed
    {
        if ($payment !== null && function_exists($payment->failure_hook)) {
            call_user_func($payment->failure_hook, $payment);
        }

        return $this->payment_response($payment, 'fail');
    }

    /** Paymob writes its booleans as "true" in the return and as true in its API answers. */
    private function isTrue(mixed $value): bool
    {
        return $value === true || $value === 1 || (is_string($value) && strcasecmp($value, 'true') === 0);
    }

    /**
     * The HMAC secret of the one platform-wide settings row, which is what every Paymob payment uses today.
     *
     * Null rather than an exception for a row that has none: an unconfigured gateway must not throw out of a route Laravel reaches while gathering
     * middleware, and a missing secret is read as "cannot verify" by the caller, which refuses.
     */
    private function platformHmacSecret(): ?string
    {
        return $this->platformValue(name: 'hmac');
    }

    /**
     * Marks the payment this confirmation settles as paid, and answers it; null when the confirmation settles no payment.
     *
     * What Paymob signs is its own transaction, its order and the amount it took -- never the payment this shop is waiting for.
     * So the amount and the currency of the confirmation must be the payment's, one transaction of Paymob settles one payment
     * (App\Services\Payments\GatewayConfirmations), and the paid flag is set only while it is still unset: a confirmation that
     * arrives twice at once settles once.
     *
     * @param numeric-string|int|float|null $minorUnits
     */
    private function settle(string $paymentId, string $transaction, mixed $minorUnits, string $currency): ?PaymentRequest
    {
        $confirmations = app(GatewayConfirmations::class);
        $payment = $confirmations->thePaymentItSettles(gateway: self::GATEWAY, paymentId: $paymentId, reference: $transaction, minorUnits: $minorUnits, currency: $currency);

        if ($payment === null || $transaction === '') {
            return null;
        }

        return DB::transaction(function () use ($confirmations, $payment, $transaction): ?PaymentRequest {
            if (!$confirmations->hold(gateway: self::GATEWAY, reference: $transaction, paymentId: $payment->id)) {
                return null;
            }

            $settled = $this->payment::where(['id' => $payment->id, 'is_paid' => 0])
                ->update(['payment_method' => self::GATEWAY, 'is_paid' => 1, 'transaction_id' => $transaction]);

            return $settled === 1 ? $payment->refresh() : null;
        });
    }

    /**
     * Marks the payment this confirmation settles as paid, and answers it; null when the confirmation settles no payment.
     *
     * What Paymob signs is its own transaction, its order and the amount it took -- never the payment this shop is waiting for.
     * So the amount and the currency of the confirmation must be the payment's, one transaction of Paymob settles one payment
     * (App\Services\Payments\GatewayConfirmations), and the paid flag is set only while it is still unset: a confirmation that
     * arrives twice at once settles once.
     *
     * @param numeric-string|int|float|null $minorUnits
     */
}
