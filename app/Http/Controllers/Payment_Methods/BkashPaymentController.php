<?php

namespace App\Http\Controllers\Payment_Methods;

use App\Models\PaymentRequest;
use App\Models\User;
use App\Traits\Processor;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BkashPaymentController extends Controller
{
    use Processor;

    private $config_values = null;
    private $base_url;
    private $app_key;
    private $app_secret;
    private $username;
    private $password;
    private PaymentRequest $payment;
    private $user;

    public function __construct(PaymentRequest $payment,User $user)
    {
        $config = $this->payment_config('bkash', 'payment_config');
        if (!is_null($config) && $config->mode == 'live') {
            $this->config_values = json_decode($config->live_values);
        } elseif (!is_null($config) && $config->mode == 'test') {
            $this->config_values = json_decode($config->test_values);
        }

        // A configuration row whose mode is neither "live" nor "test" leaves the credential blob
        // unread, and reading a property off it then throws — in the CONSTRUCTOR, which Laravel runs
        // whenever it gathers this route's middleware. So one malformed settings row took down
        // `route:list`, every page that enumerates routes, and this gateway's own endpoints. Guarded
        // rather than assumed: an unusable configuration is a gateway that cannot take a payment,
        // which is what the readiness check reports, not a fatal error.
        if ($config && $this->config_values) {
            $this->app_key = $this->config_values->app_key;
            $this->app_secret = $this->config_values->app_secret;
            $this->username = $this->config_values->username;
            $this->password = $this->config_values->password;
            $this->base_url = ($config->mode == 'live') ? 'https://tokenized.pay.bka.sh/v1.2.0-beta' : 'https://tokenized.sandbox.bka.sh/v1.2.0-beta';
        }

        $this->payment = $payment;
        $this->user = $user;
    }

    public function getToken()
    {
        $post_token = array(
            'app_key' => $this->app_key,
            'app_secret' => $this->app_secret
        );

        $url = curl_init($this->base_url . '/tokenized/checkout/token/grant');
        $post_token_json = json_encode($post_token);
        $header = array(
            'Content-Type:application/json',
            'username:' . $this->username,
            'password:' . $this->password
        );

        curl_setopt($url, CURLOPT_HTTPHEADER, $header);
        curl_setopt($url, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($url, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($url, CURLOPT_POSTFIELDS, $post_token_json);
        curl_setopt($url, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($url, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

        $resultdata = curl_exec($url);
        curl_close($url);

        $response = json_decode($resultdata, true);

        if (array_key_exists('msg', $response)) {
            return $response;
        }

        return $response;
    }

    public function make_tokenize_payment(Request $request)
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

        $response = self::getToken();
        $auth = $response['id_token'];
        session()->put('token', $auth);
        $callbackURL = route('bkash.callback', ['payment_id' => $request['payment_id'], 'token' => $auth]);

        $requestbody = array(
            'mode' => '0011',
            'amount' => round($data->payment_amount, 2),
            'currency' => 'BDT',
            'intent' => 'sale',
            'payerReference' => !empty($payer->phone) ? $payer->phone : getWebConfig(name: 'company_phone'),
            'merchantInvoiceNumber' => 'invoice_' . Str::random('15'),
            'callbackURL' => $callbackURL
        );

        $url = curl_init($this->base_url . '/tokenized/checkout/create');
        $requestbodyJson = json_encode($requestbody);

        $header = array(
            'Content-Type:application/json',
            'Authorization:' . $auth,
            'X-APP-Key:' . $this->app_key
        );

        curl_setopt($url, CURLOPT_HTTPHEADER, $header);
        curl_setopt($url, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($url, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($url, CURLOPT_POSTFIELDS, $requestbodyJson);
        curl_setopt($url, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($url, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        $resultdata = curl_exec($url);
        curl_close($url);

        $obj = json_decode($resultdata);
        return redirect()->away($obj->{'bkashURL'});
    }

    public function callback(Request $request)
    {
        $paymentID = $_GET['paymentID'];
        $auth = $_GET['token'];
        $request_body = array(
            'paymentID' => $paymentID
        );
        $url = curl_init($this->base_url . '/tokenized/checkout/execute');

        $request_body_json = json_encode($request_body);

        $header = array(
            'Content-Type:application/json',
            'Authorization:' . $auth,
            'X-APP-Key:' . $this->app_key
        );

        curl_setopt($url, CURLOPT_HTTPHEADER, $header);
        curl_setopt($url, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($url, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($url, CURLOPT_POSTFIELDS, $request_body_json);
        curl_setopt($url, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($url, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        $resultdata = curl_exec($url);
        curl_close($url);
        $obj = json_decode($resultdata);

        $paymentRecord = $this->payment::where(['id' => $request['payment_id']])->first();

        // A successful execute proves the bKash payment is real; it does NOT prove it belongs to
        // this order. paymentID and payment_id are both caller-supplied, so without the checks
        // below a genuine small payment could settle any other, more expensive order — and with no
        // is_paid guard, the same one could be replayed indefinitely.
        if ($obj->statusCode == '0000' && $paymentRecord && $this->executedPaymentMatches($obj, $paymentRecord)) {

            $this->payment::where(['id' => $request['payment_id']])->update([
                'payment_method' => 'bkash',
                'is_paid' => 1,
                'transaction_id' => $obj->trxID ?? null,
            ]);

            $data = $this->payment::where(['id' => $request['payment_id']])->first();

            if (isset($data) && function_exists($data->success_hook)) {
                call_user_func($data->success_hook, $data);
            }

            return $this->payment_response($data,'success');
        } else {
            $payment_data = $this->payment::where(['id' => $request['payment_id']])->first();
            if (isset($payment_data) && function_exists($payment_data->failure_hook)) {
                call_user_func($payment_data->failure_hook, $payment_data);
            }
            return $this->payment_response($payment_data,'fail');
        }
    }

    /**
     * Confirm an executed bKash payment is for THIS payment record and has not already settled it.
     *
     * bKash's execute response carries the amount and currency actually paid, which is what makes
     * this checkable at all: statusCode 0000 only says "a payment happened", not "the right payment
     * happened for the right order".
     *
     * Fails closed — a response missing the amount is treated as unverified rather than trusted.
     */
    private function executedPaymentMatches(object $executed, object $payment): bool
    {
        if ((int) $payment->is_paid === 1) {
            return false; // already settled; refuse the replay
        }

        if (!isset($executed->amount)) {
            return false;
        }

        // Compared in minor units so float representation cannot make 99.999999 equal 100.
        $paidMinor = (int) round(((float) $executed->amount) * 100);
        $expectedMinor = (int) round(((float) $payment->payment_amount) * 100);
        if ($paidMinor !== $expectedMinor) {
            return false;
        }

        $paidCurrency = strtolower((string) ($executed->currency ?? ''));
        $expectedCurrency = strtolower((string) ($payment->currency_code ?? ''));
        if ($paidCurrency !== '' && $expectedCurrency !== '' && $paidCurrency !== $expectedCurrency) {
            return false;
        }

        return true;
    }
}

