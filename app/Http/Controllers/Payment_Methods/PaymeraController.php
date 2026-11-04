<?php

namespace App\Http\Controllers\Payment_Methods;

use App\Models\PaymentRequest;
use App\Traits\Processor;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class PaymeraController extends Controller
{
    use Processor;

    private const TEST_BASE_URL = 'https://egate-t.paymera.cc';
    private const LIVE_BASE_URL = 'https://egate.paymera.cc';

    private PaymentRequest $payment;
    private ?object $values = null;
    private string $baseUrl = self::TEST_BASE_URL;

    public function __construct(PaymentRequest $payment)
    {
        $config = $this->payment_config('paymera', 'payment_config');

        if (!is_null($config) && $config->mode == 'live') {

            $this->values = json_decode($config->live_values);
            $this->baseUrl = self::LIVE_BASE_URL;

        } elseif (!is_null($config) && $config->mode == 'test') {

            $this->values = json_decode($config->test_values);
            $this->baseUrl = self::TEST_BASE_URL;
        }

        $this->payment = $payment;
    }

    /**
     * Create Paymera payment and redirect customer
     * to Paymera hosted payment page.
     */
    public function index(
        Request $request
    ): JsonResponse|Redirector|RedirectResponse|Application {

        $validator = Validator::make(
            $request->all(),
            [
                'payment_id' => 'required|uuid',
            ]
        );

        if ($validator->fails()) {

            return response()->json(
                $this->response_formatter(
                    GATEWAYS_DEFAULT_400,
                    null,
                    $this->error_processor($validator)
                ),
                400
            );
        }

        $data = $this->payment::where([
                'id' => $request['payment_id'],
            ])
            ->where('is_paid', 0)
            ->first();

        if (!isset($data)) {

            return response()->json(
                $this->response_formatter(
                    GATEWAYS_DEFAULT_204
                ),
                200
            );
        }

        if (!$this->isConfigured()) {
            return $this->paymentFailed($data);
        }

        $callbackUrl = route(
            'paymera.callback',
            [
                'payment_id' => $data->id,
            ]
        );

        $body = [
            'lang' => $this->lang(),
            'terminalId' => (string) $this->values->terminal_id,

            'amount' => (int) round(
                (float) $data->payment_amount
            ),

            'callbackURL' => $callbackUrl,
            'triggerURL' => $callbackUrl,
            'notes' => 'Payment ' . $data->id,
        ];

        try {

            $response = Http::withHeaders([
                    'username' => (string) $this->values->token,

                    'Authorization' =>
                        'Bearer ' . (string) $this->values->token,

                    'X-Secret-Key' => 'super_secret_key_2026',

                    'X-Platform' => 'deliverysyria',

                    'Accept' => 'application/json',

                    'Content-Type' => 'application/json',
                ])
                ->timeout(30)
                ->post(
                    $this->baseUrl . '/api/create-payment',
                    $body
                );

        } catch (\Throwable $e) {

            return $this->paymentFailed($data);
        }

        $json = $response->json();

        if (
            is_array($json)
            && (int) ($json['ErrorCode'] ?? -1) === 0
            && !empty($json['Data']['url'])
        ) {

            $additional = json_decode(
                $data->additional_data,
                true
            ) ?: [];

            $additional['paymera_payment_id']
                = $json['Data']['paymentId'] ?? null;

            $this->payment::where([
                'id' => $data->id,
            ])->update([
                'additional_data' => json_encode(
                    $additional
                ),
            ]);

            return redirect()->away(
                $json['Data']['url']
            );
        }

        return $this->paymentFailed($data);
    }

    /**
     * Paymera callback / trigger.
     */
    public function callback(
        Request $request
    ): Redirector|RedirectResponse|Application {



    file_put_contents(
        storage_path('logs/paymera_callback_test.txt'),
        date('Y-m-d H:i:s')
        . "\nMETHOD: " . $request->method()
        . "\nURL: " . $request->fullUrl()
        . "\nDATA: " . json_encode($request->all())
        . "\n\n",
        FILE_APPEND
    );

        $data = $this->payment::where([
            'id' => $request['payment_id'],
        ])->first();

        if (!isset($data)) {
            return redirect()->route('payment-fail');
        }

        /*
         * Callback may reach us more than once.
         */
        if ((int) $data->is_paid === 1) {

            return $this->payment_response(
                $data,
                'success'
            );
        }

        $additional = json_decode(
            $data->additional_data,
            true
        ) ?: [];

        $paymeraId =
            $additional['paymera_payment_id'] ?? null;

        if (
            empty($paymeraId)
            || !$this->isConfigured()
        ) {

            return $this->paymentFailed(
                $data,
                false
            );
        }

        /*
         * Check payment status directly with Paymera.
         */
        try {

            $response = Http::withHeaders([
                    'username' => (string) $this->values->token,

                    'Authorization' =>
                        'Bearer ' . (string) $this->values->token,

                    'X-Secret-Key' =>
                        'super_secret_key_2026',

                    'X-Platform' =>
                        'deliverysyria',

                    'Accept' =>
                        'application/json',

                    'Content-Type' =>
                        'application/json',
                ])
                ->timeout(30)
                ->get(
                    $this->baseUrl
                    . '/api/get-payment-status/'
                    . $paymeraId
                );

        } catch (\Throwable $e) {

            /*
             * Temporary Paymera/network error:
             * do not execute failure hook.
             */
            return $this->paymentFailed(
                $data,
                false
            );
        }

        $json = $response->json();

        if (
            !is_array($json)
            || (int) ($json['ErrorCode'] ?? -1) !== 0
        ) {

            return $this->paymentFailed(
                $data,
                false
            );
        }

        $status =
            $json['Data']['status'] ?? null;

        /*
         * Payment accepted.
         */
        if ($status === 'A') {

            $rrn =
                $json['Data']['rrn'] ?? null;

            /*
             * Only one callback can change 0 -> 1.
             * This prevents duplicate success_hook calls.
             */
            $affected = $this->payment::where([
                    'id' => $data->id,
                ])
                ->where('is_paid', 0)
                ->update([
                    'payment_method' => 'paymera',
                    'is_paid' => 1,
                    'transaction_id' => $rrn,
                ]);

            $paid = $this->payment::where([
                'id' => $data->id,
            ])->first();

            if (
                $affected === 1
                && $paid
                && function_exists(
                    $paid->success_hook
                )
            ) {





          try {

    file_put_contents(
        storage_path('logs/paymera_hook_test.txt'),
        "BEFORE HOOK\n"
        . "payment_id: " . $paid->id . "\n"
        . "is_paid: " . $paid->is_paid . "\n"
        . "hook: " . $paid->success_hook . "\n"
        . "additional_data: " . $paid->additional_data . "\n\n",
        FILE_APPEND
    );

    call_user_func(
        $paid->success_hook,
        $paid
    );

    file_put_contents(
        storage_path('logs/paymera_hook_test.txt'),
        "HOOK COMPLETED SUCCESSFULLY\n\n",
        FILE_APPEND
    );

} catch (\Throwable $e) {

    file_put_contents(
        storage_path('logs/paymera_hook_test.txt'),
        "HOOK ERROR\n"
        . "message: " . $e->getMessage() . "\n"
        . "file: " . $e->getFile() . "\n"
        . "line: " . $e->getLine() . "\n"
        . "trace: " . $e->getTraceAsString() . "\n\n",
        FILE_APPEND
    );
}






            }

            return $this->payment_response(
                $paid,
                'success'
            );
        }

        /*
         * P = pending.
         * Do not execute failure hook yet.
         */
        if ($status === 'P') {

            return $this->payment_response(
                $data,
                'fail'
            );
        }

        /*
         * F = failed
         * C = cancelled
         */
        return $this->paymentFailed(
            $data,
            in_array(
                $status,
                ['F', 'C'],
                true
            )
        );
    }

    private function isConfigured(): bool
    {
        return $this->values
            && !empty(
                $this->values->terminal_id
            )
            && !empty(
                $this->values->token
            );
    }

    private function lang(): string
    {
        return in_array(
            app()->getLocale(),
            ['ar', 'sy'],
            true
        )
            ? 'ar'
            : 'en';
    }

    private function paymentFailed(
        PaymentRequest $data,
        bool $fireHook = true
    ): Redirector|RedirectResponse|Application {

        if (
            $fireHook
            && function_exists(
                $data->failure_hook
            )
        ) {

            call_user_func(
                $data->failure_hook,
                $data
            );
        }

        return $this->payment_response(
            $data,
            'fail'
        );
    }
}