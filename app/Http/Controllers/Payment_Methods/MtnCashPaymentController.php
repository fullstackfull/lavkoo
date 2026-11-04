<?php

namespace App\Http\Controllers\Payment_Methods;

use App\Http\Controllers\Controller;
use App\Models\MtnCashTerminal;
use App\Traits\Processor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use App\Models\MtnCashTransaction;
use App\Models\PaymentRequest;
use Illuminate\Support\Str;


class MtnCashPaymentController extends Controller
{
    use Processor;

    private array $configValues = [];

public function __construct()
{
    $config = $this->payment_config('mtn_cash', 'payment_config');

    if (!is_null($config)) {

        $values = $config->mode === 'test'
            ? $config->test_values
            : $config->live_values;

        if (is_array($values)) {
            $this->configValues = $values;
        } elseif (is_string($values)) {
            $this->configValues = json_decode($values, true) ?? [];
        }
    }
}

public function payment(Request $request)
{
    $validator = Validator::make($request->all(), [
        'payment_id' => 'required|uuid',
    ]);

    if ($validator->fails()) {
        abort(404);
    }

    $payment = PaymentRequest::where('id', $request->payment_id)
        ->where('payment_method', 'mtn_cash')
        ->first();

    if (!$payment) {
        abort(404);
    }

    if ((int) $payment->is_paid === 1) {
        return $this->payment_response($payment, 'success');
    }

    if (strtoupper((string) $payment->currency_code) !== 'SYP') {
        return response()->view(
            'payment-methods.mtn-cash.payment',
            [
                'payment' => $payment,
                'fatalError' => 'MTN Cash currently supports SYP payments only.',
            ],
            422
        );
    }

    $terminal = $this->getReadyTerminal();

    if (!$terminal) {
        return response()->view(
            'payment-methods.mtn-cash.payment',
            [
                'payment' => $payment,
                'fatalError' => 'MTN Cash terminal is not ready.',
            ],
            422
        );
    }

    return view('payment-methods.mtn-cash.payment', [
        'payment' => $payment,
        'fatalError' => null,
    ]);
}


public function initiate(Request $request)
{
    $validator = Validator::make($request->all(), [
        'payment_id' => 'required|uuid',
        'phone' => 'required|string|max:30',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'message' => 'يرجى إدخال رقم MTN صحيح.',
        ], 422);
    }

    $payment = PaymentRequest::where('id', $request->payment_id)
        ->where('payment_method', 'mtn_cash')
        ->first();

    if (!$payment) {
        return response()->json([
            'status' => false,
            'message' => 'طلب الدفع غير موجود.',
        ], 404);
    }

    if ((int) $payment->is_paid === 1) {
        return response()->json([
            'status' => true,
            'message' => 'تم دفع هذه العملية مسبقاً.',
            'redirect_url' => $this->getPaymentRedirectUrl($payment, 'success'),
        ]);
    }

    if (strtoupper((string) $payment->currency_code) !== 'SYP') {
        return response()->json([
            'status' => false,
            'message' => 'MTN Cash يدعم الدفع بالليرة السورية فقط.',
        ], 422);
    }

    $phone = $this->normalizePhone($request->phone);

    if (!$phone) {
        return response()->json([
            'status' => false,
            'message' => 'رقم MTN غير صحيح. مثال: 09XXXXXXXX أو 9639XXXXXXXX.',
        ], 422);
    }

    $terminal = $this->getReadyTerminal();

    if (!$terminal) {
        return response()->json([
            'status' => false,
            'message' => 'بوابة MTN Cash غير جاهزة حالياً.',
        ], 503);
    }

    /*
     * ننشئ transaction واحدة فقط لكل PaymentRequest.
     * هذا يمنع double invoices عند الضغط مرتين.
     */
    try {
        $transaction = DB::transaction(function () use ($payment, $phone) {

            $existing = MtnCashTransaction::where('payment_id', $payment->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $existing->phone = $phone;
                $existing->save();

                return $existing;
            }

            $invoice = $this->generateInvoiceNumber();

            return MtnCashTransaction::create([
                'payment_id' => $payment->id,
                'invoice' => $invoice,
                'guid' => $invoice . '-' . Str::lower(Str::random(10)),
                'phone' => $phone,
                'status' => 'created',
            ]);
        });

    } catch (\Throwable $e) {
        return response()->json([
            'status' => false,
            'message' => 'تعذر إنشاء عملية الدفع. حاول مرة أخرى.',
        ], 500);
    }

    /*
     * أول مرة فقط: إنشاء Invoice عند MTN.
     */
    if ($transaction->status === 'created') {

        $fractional = (int) data_get(
            $terminal->terminal_settings,
            'Currency.Fractional',
            100
        );

        if ($fractional <= 0) {
            $fractional = 100;
        }

        $amountInMinimumUnits = (int) round(
            ((float) $payment->payment_amount) * $fractional
        );

        if ($amountInMinimumUnits <= 0) {
            return response()->json([
                'status' => false,
                'message' => 'قيمة عملية الدفع غير صحيحة.',
            ], 422);
        }

        $createInvoiceResponse = $this->sendSignedRequest(
            'pos_web/invoice/create',
            [
                'Amount' => $amountInMinimumUnits,
                'Invoice' => (int) $transaction->invoice,
                'TTL' => 15,
            ]
        );

        /*
         * في حال انقطع الاتصال بعد أن MTN أنشأت الفاتورة،
         * نفحص invoice/get قبل اعتبار العملية فاشلة.
         */
        if (!$createInvoiceResponse['transport_ok']) {

            $recovery = $this->getInvoiceFromMtn(
                (int) $transaction->invoice
            );

            if (!$recovery['success']) {
                $transaction->update([
                    'status' => 'create_unknown',
                    'mtn_response' => $createInvoiceResponse,
                ]);

                return response()->json([
                    'status' => false,
                    'message' => 'تعذر التأكد من إنشاء فاتورة MTN. حاول مرة أخرى بعد قليل.',
                ], 503);
            }

        } else {

            $createData = $createInvoiceResponse['data'];

            if ((int) ($createData['Errno'] ?? 0) !== 0) {

                /*
                 * قد تكون الفاتورة أنشئت فعلاً ثم ضاع الرد.
                 * نعمل invoice/get للتأكد.
                 */
                $recovery = $this->getInvoiceFromMtn(
                    (int) $transaction->invoice
                );

                if (!$recovery['success']) {
                    $transaction->update([
                        'status' => 'invoice_create_failed',
                        'mtn_response' => $createData,
                    ]);

                    return response()->json([
                        'status' => false,
                        'message' => $this->mtnErrorMessage($createData),
                    ], 422);
                }
            }
        }

        $transaction->update([
            'status' => 'invoice_created',
            'mtn_response' => $createInvoiceResponse['data'] ?? [],
        ]);
    }

    /*
     * إذا كانت مدفوعة من محاولة سابقة، لا نرسل OTP جديد.
     */
    $invoiceCheck = $this->getInvoiceFromMtn(
        (int) $transaction->invoice
    );

    if (
        $invoiceCheck['success'] &&
        (int) ($invoiceCheck['data']['Status'] ?? -1) === 9
    ) {
        return $this->finishSuccessfulPayment(
            $payment,
            $transaction,
            $invoiceCheck['data']
        );
    }

    /*
     * إرسال OTP.
     * MTN تتطلب Invoice + Phone + Guid.
     */
    $initResponse = $this->sendSignedRequest(
        'pos_web/payment_phone/initiate',
        [
            'Phone' => $phone,
            'Invoice' => (int) $transaction->invoice,
            'Guid' => $transaction->guid,
        ]
    );

    if (!$initResponse['transport_ok']) {
        return response()->json([
            'status' => false,
            'message' => 'تعذر الاتصال بـ MTN Cash. حاول مرة أخرى.',
        ], 503);
    }

    $initData = $initResponse['data'];

    $errno = (int) ($initData['Errno'] ?? 0);

    if ($errno !== 0) {

        /*
         * Errno 409 = invoice already paid.
         * لا نعتمد الخطأ نفسه، بل نتحقق من invoice/get.
         */
        if ($errno === 409) {
            $invoiceCheck = $this->getInvoiceFromMtn(
                (int) $transaction->invoice
            );

            if (
                $invoiceCheck['success'] &&
                (int) ($invoiceCheck['data']['Status'] ?? -1) === 9
            ) {
                return $this->finishSuccessfulPayment(
                    $payment,
                    $transaction,
                    $invoiceCheck['data']
                );
            }
        }

        $transaction->update([
            'status' => 'initiate_failed',
            'mtn_response' => $initData,
        ]);

        return response()->json([
            'status' => false,
            'message' => $this->mtnErrorMessage($initData),
        ], 422);
    }

    $operationNumber = $initData['OperationNumber'] ?? null;

    if (!$operationNumber) {
        $transaction->update([
            'status' => 'initiate_invalid_response',
            'mtn_response' => $initData,
        ]);

        return response()->json([
            'status' => false,
            'message' => 'استجابة MTN Cash غير مكتملة.',
        ], 502);
    }

    $transaction->update([
        'phone' => $phone,
        'operation_number' => (string) $operationNumber,
        'status' => 'otp_sent',
        'mtn_response' => $initData,
    ]);

    return response()->json([
        'status' => true,
        'message' => 'تم إرسال رمز التحقق.',
    ]);
}


public function confirm(Request $request)
{
    $validator = Validator::make($request->all(), [
        'payment_id' => 'required|uuid',
        'otp' => ['required', 'regex:/^\d{6}$/'],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'message' => 'رمز التحقق يجب أن يتكون من 6 أرقام.',
        ], 422);
    }

    $payment = PaymentRequest::where('id', $request->payment_id)
        ->where('payment_method', 'mtn_cash')
        ->first();

    if (!$payment) {
        return response()->json([
            'status' => false,
            'message' => 'طلب الدفع غير موجود.',
        ], 404);
    }

    if ((int) $payment->is_paid === 1) {
        return response()->json([
            'status' => true,
            'message' => 'تم الدفع مسبقاً.',
            'redirect_url' => $this->getPaymentRedirectUrl(
                $payment,
                'success'
            ),
        ]);
    }

    $transaction = MtnCashTransaction::where(
        'payment_id',
        $payment->id
    )->first();

    if (
        !$transaction ||
        !$transaction->operation_number ||
        !$transaction->phone ||
        !$transaction->guid
    ) {
        return response()->json([
            'status' => false,
            'message' => 'يجب إرسال رمز التحقق أولاً.',
        ], 422);
    }

    /*
     * MTN sample:
     * base64_encode(hash('sha256', $otp, true))
     */
    $encodedOtp = base64_encode(
        hash('sha256', (string) $request->otp, true)
    );

    $confirmResponse = $this->sendSignedRequest(
        'pos_web/payment_phone/confirm',
        [
            'Phone' => $transaction->phone,
            'Guid' => $transaction->guid,
            'OperationNumber' => is_numeric($transaction->operation_number)
                ? (int) $transaction->operation_number
                : $transaction->operation_number,
            'Invoice' => (int) $transaction->invoice,
            'Code' => $encodedOtp,
        ]
    );

    if (!$confirmResponse['transport_ok']) {

        /*
         * مهم جداً:
         * إذا انقطع الاتصال أثناء confirm لا نقول فشل.
         * MTN نفسها تطلب invoice/get لاستعادة النتيجة.
         */
        $invoiceCheck = $this->getInvoiceFromMtn(
            (int) $transaction->invoice
        );

        if (
            $invoiceCheck['success'] &&
            (int) ($invoiceCheck['data']['Status'] ?? -1) === 9
        ) {
            return $this->finishSuccessfulPayment(
                $payment,
                $transaction,
                $invoiceCheck['data']
            );
        }

        return response()->json([
            'status' => false,
            'message' => 'تعذر التأكد من نتيجة الدفع. لم يتم اعتبار العملية مدفوعة. حاول مرة أخرى.',
        ], 503);
    }

    $confirmData = $confirmResponse['data'];

    $errno = (int) ($confirmData['Errno'] ?? 0);

    /*
     * Incorrect OTP / expired OTP وغيرها:
     * لا نشغل failure_hook لأن العميل يجب أن يبقى قادر على المحاولة.
     */
 if ($errno !== 0) {

    /*
     * لا نعتمد خطأ confirm لوحده.
     * ممكن MTN تكون نفذت الدفع فعلاً كما حصل معنا.
     */
    $invoiceCheck = $this->getInvoiceFromMtn(
        (int) $transaction->invoice
    );

    if (
        $invoiceCheck['success'] &&
        (int) ($invoiceCheck['data']['Status'] ?? -1) === 9
    ) {
        return $this->finishSuccessfulPayment(
            $payment,
            $transaction,
            $invoiceCheck['data']
        );
    }

    $transaction->update([
        'status' => 'confirm_failed',
        'mtn_response' => $confirmData,
    ]);

    return response()->json([
        'status' => false,
        'message' => $this->mtnErrorMessage($confirmData),
        'errno' => $errno,
    ], 422);
}

    /*
     * حتى لو confirm رجع نجاح،
     * لا نعتبر PaymentRequest مدفوع قبل invoice/get.
     */
    $invoiceCheck = $this->getInvoiceFromMtn(
        (int) $transaction->invoice
    );

    if (!$invoiceCheck['success']) {
        $transaction->update([
            'status' => 'verification_pending',
            'mtn_response' => $confirmData,
        ]);

        return response()->json([
            'status' => false,
            'message' => 'تم إرسال التأكيد ولكن تعذر التحقق النهائي من MTN. لم يتم تسجيل الطلب كمدفوع بعد.',
        ], 503);
    }

    $invoiceData = $invoiceCheck['data'];

    $invoiceStatus = (int) ($invoiceData['Status'] ?? -1);

    if ($invoiceStatus === 9) {
        return $this->finishSuccessfulPayment(
            $payment,
            $transaction,
            $invoiceData
        );
    }

    if ($invoiceStatus === 5 || $invoiceStatus === 1) {

        $transaction->update([
            'status' => $invoiceStatus === 5
                ? 'processing'
                : 'active',
            'mtn_response' => $invoiceData,
        ]);

        return response()->json([
            'status' => false,
            'message' => 'عملية الدفع ما زالت قيد المعالجة. يرجى الانتظار ثم المحاولة مرة أخرى.',
        ], 202);
    }

    if ($invoiceStatus === 8 || $invoiceStatus === 0) {

        $transaction->update([
            'status' => $invoiceStatus === 8
                ? 'failed'
                : 'canceled',
            'mtn_response' => $invoiceData,
        ]);

        return response()->json([
            'status' => false,
            'message' => $invoiceStatus === 8
                ? 'فشلت عملية الدفع لدى MTN Cash.'
                : 'تم إلغاء فاتورة MTN Cash.',
        ], 422);
    }

    return response()->json([
        'status' => false,
        'message' => 'حالة عملية الدفع غير مكتملة. لم يتم تسجيل العملية كمدفوعة.',
    ], 202);
}


private function getReadyTerminal(): ?MtnCashTerminal
{
    $terminalId = trim(
        (string) ($this->configValues['terminal_id'] ?? '')
    );

    if ($terminalId === '') {
        return null;
    }

    return MtnCashTerminal::where('terminal_id', $terminalId)
        ->where('is_activated', 1)
        ->whereNotNull('private_key')
        ->first();
}


private function normalizePhone(string $phone): ?string
{
    $phone = preg_replace('/\D+/', '', $phone);

    if (!$phone) {
        return null;
    }

    /*
     * 009639xxxxxxxx → 9639xxxxxxxx
     */
    if (str_starts_with($phone, '00963')) {
        $phone = substr($phone, 2);
    }

    /*
     * 09xxxxxxxx → 9639xxxxxxxx
     */
    if (
        str_starts_with($phone, '09') &&
        strlen($phone) === 10
    ) {
        $phone = '963' . substr($phone, 1);
    }

    /*
     * 9xxxxxxxx → 9639xxxxxxxx
     */
    if (
        str_starts_with($phone, '9') &&
        strlen($phone) === 9
    ) {
        $phone = '963' . $phone;
    }

    if (!preg_match('/^9639\d{8}$/', $phone)) {
        return null;
    }

    return $phone;
}


private function generateInvoiceNumber(): int
{
    /*
     * 14 digit numeric invoice.
     * مثال: 26082915301245
     */
    return (int) (
        now()->format('ymdHis')
        . random_int(10, 99)
    );
}


private function sendSignedRequest(
    string $requestName,
    array $body
): array {

    $terminal = $this->getReadyTerminal();

    if (!$terminal) {
        return [
            'transport_ok' => false,
            'http_status' => 0,
            'data' => [
                'Error' => 'MTN terminal is not ready.',
            ],
        ];
    }

    $apiUrl = rtrim(
        trim((string) ($this->configValues['api_url'] ?? '')),
        '/'
    );

    if ($apiUrl === '') {
        return [
            'transport_ok' => false,
            'http_status' => 0,
            'data' => [
                'Error' => 'MTN API URL is missing.',
            ],
        ];
    }

    try {
        $privateKey = Crypt::decryptString(
            $terminal->private_key
        );
    } catch (\Throwable $e) {
        return [
            'transport_ok' => false,
            'http_status' => 0,
            'data' => [
                'Error' => 'Unable to decrypt private key.',
            ],
        ];
    }

    $privateResource = openssl_pkey_get_private(
        $privateKey
    );

    if (!$privateResource) {
        return [
            'transport_ok' => false,
            'http_status' => 0,
            'data' => [
                'Error' => 'Invalid private key.',
            ],
        ];
    }

    $flags =
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES;

    if (defined('JSON_UNESCAPED_LINE_TERMINATORS')) {
        $flags |= JSON_UNESCAPED_LINE_TERMINATORS;
    }

    $jsonBody = json_encode($body, $flags);

    if ($jsonBody === false) {
        return [
            'transport_ok' => false,
            'http_status' => 0,
            'data' => [
                'Error' => 'Unable to encode MTN request.',
            ],
        ];
    }

    $signature = '';

    if (!openssl_sign(
        $jsonBody,
        $signature,
        $privateResource,
        OPENSSL_ALGO_SHA256
    )) {
        return [
            'transport_ok' => false,
            'http_status' => 0,
            'data' => [
                'Error' => 'Unable to sign MTN request.',
            ],
        ];
    }

    try {

        $response = Http::withHeaders([
            'Subject' => $terminal->terminal_id,
            'Request-Name' => $requestName,
            'X-Signature' => base64_encode($signature),
            'Accept-Language' => 'en',
            'Content-Type' => 'application/json',
        ])
            ->withBody($jsonBody, 'application/json')
            ->connectTimeout(10)
            ->timeout(30)
            ->post($apiUrl);

        $data = $response->json();

        if (!is_array($data)) {
            $data = [
                'Error' => 'Invalid MTN response.',
                'Raw' => $response->body(),
            ];
        }

        return [
            'transport_ok' => true,
            'http_status' => $response->status(),
            'data' => $data,
        ];

    } catch (\Throwable $e) {

        return [
            'transport_ok' => false,
            'http_status' => 0,
            'data' => [
                'Error' => 'MTN connection failed.',
            ],
        ];
    }
}


private function getInvoiceFromMtn(int $invoice): array
{
    $response = $this->sendSignedRequest(
        'pos_web/invoice/get',
        [
            'Invoice' => $invoice,
        ]
    );

    if (!$response['transport_ok']) {
        return [
            'success' => false,
            'data' => $response['data'] ?? [],
        ];
    }

    $data = $response['data'] ?? [];

    if ((int) ($data['Errno'] ?? 0) !== 0) {
        return [
            'success' => false,
            'data' => $data,
        ];
    }

    /*
     * MTN invoice/get returns the invoice inside Receipt.
     */
    $invoiceData = $data['Receipt'] ?? $data;

    if (!is_array($invoiceData)) {
        return [
            'success' => false,
            'data' => $data,
        ];
    }

    if (!array_key_exists('Status', $invoiceData)) {
        return [
            'success' => false,
            'data' => $data,
        ];
    }

    return [
        'success' => true,
        'data' => $invoiceData,
        'raw' => $data,
    ];
}

private function finishSuccessfulPayment(
    PaymentRequest $payment,
    MtnCashTransaction $transaction,
    array $invoiceData
) {
    /*
     * Status 9 فقط = paid successfully.
     */
    if ((int) ($invoiceData['Status'] ?? -1) !== 9) {
        return response()->json([
            'status' => false,
            'message' => 'MTN لم تؤكد نجاح الدفع.',
        ], 422);
    }

    $mtnTransactionNumber =
        $invoiceData['Transaction']
        ?? $transaction->transaction_number
        ?? ('MTN-' . $transaction->invoice);

    /*
     * lockForUpdate يمنع تشغيل success_hook مرتين
     * إذا وصل confirm مرتين بنفس اللحظة.
     */
    $shouldRunSuccessHook = false;

    DB::transaction(function () use (
        $payment,
        $transaction,
        $invoiceData,
        $mtnTransactionNumber,
        &$shouldRunSuccessHook
    ) {

        $lockedPayment = PaymentRequest::where(
            'id',
            $payment->id
        )
            ->lockForUpdate()
            ->first();

        if (!$lockedPayment) {
            throw new \RuntimeException(
                'Payment request disappeared.'
            );
        }

        if ((int) $lockedPayment->is_paid !== 1) {

            $lockedPayment->update([
                'payment_method' => 'mtn_cash',
                'is_paid' => 1,
                'transaction_id' => (string) $mtnTransactionNumber,
            ]);

            $shouldRunSuccessHook = true;
        }

        $transaction->update([
            'transaction_number' => (string) $mtnTransactionNumber,
            'status' => 'paid',
            'mtn_response' => $invoiceData,
        ]);
    });

    $payment->refresh();

    if (
        $shouldRunSuccessHook &&
        isset($payment->success_hook) &&
        function_exists($payment->success_hook)
    ) {
        call_user_func(
            $payment->success_hook,
            $payment
        );
    }

    return response()->json([
        'status' => true,
        'message' => 'تم الدفع بنجاح.',
        'redirect_url' => $this->getPaymentRedirectUrl(
            $payment,
            'success'
        ),
    ]);
}


private function getPaymentRedirectUrl(
    PaymentRequest $payment,
    string $flag
): string {

    $response = $this->payment_response(
        $payment,
        $flag
    );

    if (
        $response instanceof
        \Illuminate\Http\RedirectResponse
    ) {
        return $response->getTargetUrl();
    }

    return route(
        $flag === 'success'
            ? 'payment-success'
            : 'payment-fail'
    );
}


private function mtnErrorMessage(array $data): string
{
    $errno = (int) ($data['Errno'] ?? 0);

    return match ($errno) {

        302 =>
            'طرفية MTN Cash محظورة. يرجى التواصل مع MTN.',

        403 =>
            'محفظة العميل محظورة مؤقتاً.',

        404 =>
            'رقم الهاتف غير مرتبط بمحفظة MTN Cash صالحة.',

        409 =>
            'تم دفع هذه الفاتورة مسبقاً.',

        603 =>
            'فاتورة MTN Cash مغلقة.',

        609 =>
            'تم إلغاء الفاتورة.',

        660 =>
            'تم استنفاد محاولات إدخال رمز التحقق.',

        661 =>
            'انتهت صلاحية رمز التحقق. أعد إرسال الرمز.',

        662 =>
            'رمز التحقق غير صحيح.',

        default =>
            !empty($data['Error'])
                ? 'MTN Cash: ' . $data['Error']
                : 'تعذر إتمام العملية عبر MTN Cash.',
    };
}







public function checkInvoice(Request $request)
{
    $invoice = (int) $request->query('invoice');

    if (!$invoice) {
        return response()->json([
            'status' => false,
            'message' => 'Invoice is required.',
        ], 422);
    }

    return response()->json(
        $this->getInvoiceFromMtn($invoice)
    );
}



public function activate()
{
    $terminalId = trim($this->configValues['terminal_id'] ?? '');
    $activationCode = trim($this->configValues['activation_code'] ?? '');
    $serial = trim($this->configValues['serial'] ?? '');
    $apiUrl = rtrim(trim($this->configValues['api_url'] ?? ''), '/');

    if (
        $terminalId === '' ||
        $activationCode === '' ||
        strlen($activationCode) !== 8 ||
        $serial === '' ||
        $apiUrl === ''
    ) {
        return response()->json([
            'status' => false,
            'message' => 'MTN configuration is incomplete.',
        ], 422);
    }

    $terminal = MtnCashTerminal::where('terminal_id', $terminalId)->first();

    if (!$terminal) {
        return response()->json([
            'status' => false,
            'message' => 'MTN terminal record not found.',
        ], 404);
    }

    if ($terminal->is_activated) {
        return response()->json([
            'status' => false,
            'message' => 'MTN terminal is already activated.',
        ], 409);
    }

    try {
        $privateKey = Crypt::decryptString($terminal->private_key);
        $publicKey = Crypt::decryptString($terminal->public_key);
    } catch (\Throwable $e) {
        return response()->json([
            'status' => false,
            'message' => 'Unable to read stored RSA keys.',
        ], 500);
    }

    $body = [
        'Secret' => $activationCode,
        'Key' => $publicKey,
        'Serial' => $serial,
    ];

    $flags =
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES;

    if (defined('JSON_UNESCAPED_LINE_TERMINATORS')) {
        $flags |= JSON_UNESCAPED_LINE_TERMINATORS;
    }

    $jsonBody = json_encode($body, $flags);

    $privateResource = openssl_pkey_get_private($privateKey);

    if (!$privateResource) {
        return response()->json([
            'status' => false,
            'message' => 'Invalid MTN private key.',
        ], 500);
    }

    $signature = '';

    if (!openssl_sign(
        $jsonBody,
        $signature,
        $privateResource,
        OPENSSL_ALGO_SHA256
    )) {
        return response()->json([
            'status' => false,
            'message' => 'Unable to sign MTN activation request.',
        ], 500);
    }

    try {
        $response = Http::withHeaders([
            'Subject' => $terminalId,
            'Request-Name' => 'pos_web/pos/activate',
            'X-Signature' => base64_encode($signature),
            'Accept-Language' => 'en',
            'Content-Type' => 'application/json',
        ])
            ->withBody($jsonBody, 'application/json')
            ->timeout(30)
            ->post($apiUrl);

    } catch (\Throwable $e) {
        return response()->json([
            'status' => false,
            'message' => 'Unable to connect to MTN.',
            'error' => $e->getMessage(),
        ], 500);
    }

    $responseData = $response->json();

    if (!is_array($responseData)) {
        return response()->json([
            'status' => false,
            'message' => 'Invalid response received from MTN.',
            'http_status' => $response->status(),
            'raw_response' => $response->body(),
        ], 502);
    }

    $errno = (int)($responseData['Errno'] ?? 0);

    $success =
        $response->successful() &&
        $errno === 0 &&
        isset($responseData['Settings']);

    if (!$success) {
        return response()->json([
            'status' => false,
            'message' => 'MTN terminal activation failed.',
            'http_status' => $response->status(),
            'mtn_response' => $responseData,
        ], 422);
    }

    $terminal->update([
        'is_activated' => true,
        'activated_at' => now(),
        'terminal_settings' => $responseData['Settings'],
    ]);

    return response()->json([
        'status' => true,
        'message' => 'MTN terminal activated successfully.',
        'is_activated' => true,
        'settings' => $responseData['Settings'],
    ]);
}


public function authTest()
{
    $terminalId = trim($this->configValues['terminal_id'] ?? '');
    $apiUrl = rtrim(trim($this->configValues['api_url'] ?? ''), '/');

    $terminal = MtnCashTerminal::where('terminal_id', $terminalId)->first();

    if (!$terminal || empty($terminal->private_key)) {
        return response()->json([
            'status' => false,
            'message' => 'MTN terminal/private key not found.',
        ], 422);
    }

    try {
        $privateKey = Crypt::decryptString($terminal->private_key);
    } catch (\Throwable $e) {
        return response()->json([
            'status' => false,
            'message' => 'Unable to decrypt MTN private key.',
        ], 500);
    }

    // رقم Invoice للاختبار فقط
    $body = [
        'Invoice' => 999999999999,
    ];

    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    if (defined('JSON_UNESCAPED_LINE_TERMINATORS')) {
        $flags |= JSON_UNESCAPED_LINE_TERMINATORS;
    }

    $jsonBody = json_encode($body, $flags);

    $privateResource = openssl_pkey_get_private($privateKey);

    $signature = '';

    if (!$privateResource || !openssl_sign(
        $jsonBody,
        $signature,
        $privateResource,
        OPENSSL_ALGO_SHA256
    )) {
        return response()->json([
            'status' => false,
            'message' => 'Unable to sign MTN request.',
        ], 500);
    }

    try {
        $response = Http::withHeaders([
            'Subject' => $terminalId,
            'Request-Name' => 'pos_web/invoice/get',
            'X-Signature' => base64_encode($signature),
            'Accept-Language' => 'en',
            'Content-Type' => 'application/json',
        ])
            ->withBody($jsonBody, 'application/json')
            ->timeout(30)
            ->post($apiUrl);

    } catch (\Throwable $e) {
        return response()->json([
            'status' => false,
            'message' => 'Connection to MTN failed.',
            'error' => $e->getMessage(),
        ], 500);
    }

    return response()->json([
        'http_status' => $response->status(),
        'mtn_response' => $response->json() ?? $response->body(),
    ]);
}

public function importOriginalKeys()
{
    $terminalId = trim($this->configValues['terminal_id'] ?? '');

    if ($terminalId === '') {
        return response()->json([
            'status' => false,
            'message' => 'Terminal ID missing.',
        ], 422);
    }

    $privateKeyPath = storage_path('app/mtn/private.pem');
    $publicKeyPath = storage_path('app/mtn/public.pem');

    if (!file_exists($privateKeyPath) || !file_exists($publicKeyPath)) {
        return response()->json([
            'status' => false,
            'message' => 'Key files not found.',
        ], 404);
    }

    $privateKey = trim(file_get_contents($privateKeyPath));
    $publicKeyPem = trim(file_get_contents($publicKeyPath));

    if (!openssl_pkey_get_private($privateKey)) {
        return response()->json([
            'status' => false,
            'message' => 'Invalid private key.',
        ], 422);
    }

    $publicKeyBase64 = preg_replace(
        '/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\r|\n/',
        '',
        $publicKeyPem
    );

    MtnCashTerminal::updateOrCreate(
        ['terminal_id' => $terminalId],
        [
            'private_key' => Crypt::encryptString($privateKey),
            'public_key' => Crypt::encryptString($publicKeyBase64),
            'is_activated' => false,
            'activated_at' => null,
        ]
    );

    return response()->json([
        'status' => true,
        'message' => 'Original MTN keys imported successfully.',
        'terminal_id' => $terminalId,
    ]);
}

    public function generateKeys(Request $request)
    {
        $validator = Validator::make([
            'terminal_id' => $this->configValues['terminal_id'] ?? null,
        ], [
            'terminal_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'MTN Terminal ID is not configured.',
            ], 422);
        }

        $terminalId = trim($this->configValues['terminal_id']);

        $existingTerminal = MtnCashTerminal::where('terminal_id', $terminalId)->first();

        if ($existingTerminal && !empty($existingTerminal->private_key)) {
            return response()->json([
                'status' => false,
                'message' => 'RSA keys already exist for this terminal. Existing keys were not changed.',
            ], 409);
        }

        $keyConfig = [
            'private_key_bits' => 1024,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $resource = openssl_pkey_new($keyConfig);

        if ($resource === false) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to generate RSA key pair.',
            ], 500);
        }

        $privateKey = '';

        if (!openssl_pkey_export($resource, $privateKey)) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to export RSA private key.',
            ], 500);
        }

        $details = openssl_pkey_get_details($resource);

        if (!$details || empty($details['key'])) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to extract RSA public key.',
            ], 500);
        }

        $publicKeyPem = $details['key'];

        $publicKeyBase64 = str_replace(
            [
                '-----BEGIN PUBLIC KEY-----',
                '-----END PUBLIC KEY-----',
                "\r",
                "\n",
            ],
            '',
            $publicKeyPem
        );

        DB::transaction(function () use (
            $terminalId,
            $privateKey,
            $publicKeyBase64
        ) {
            MtnCashTerminal::updateOrCreate(
                [
                    'terminal_id' => $terminalId,
                ],
                [
                    'private_key' => Crypt::encryptString($privateKey),
                    'public_key' => Crypt::encryptString($publicKeyBase64),
                    'is_activated' => false,
                    'activated_at' => null,
                ]
            );
        });

        return response()->json([
            'status' => true,
            'message' => 'MTN RSA keys generated and stored successfully.',
            'terminal_id' => $terminalId,
            'is_activated' => false,
        ]);
    }







public function activationPrecheck()
{
    $terminalId = trim($this->configValues['terminal_id'] ?? '');
    $activationCode = trim($this->configValues['activation_code'] ?? '');
    $serial = trim($this->configValues['serial'] ?? '');
    $apiUrl = trim($this->configValues['api_url'] ?? '');

    if ($terminalId === '') {
        return response()->json([
            'status' => false,
            'message' => 'Terminal ID is missing.',
        ], 422);
    }

    if ($activationCode === '' || strlen($activationCode) !== 8) {
        return response()->json([
            'status' => false,
            'message' => 'Activation Code must be exactly 8 characters.',
        ], 422);
    }

    if ($serial === '') {
        return response()->json([
            'status' => false,
            'message' => 'Serial is missing.',
        ], 422);
    }

    if ($apiUrl === '') {
        return response()->json([
            'status' => false,
            'message' => 'API URL is missing.',
        ], 422);
    }

    $terminal = MtnCashTerminal::where('terminal_id', $terminalId)->first();

    if (!$terminal) {
        return response()->json([
            'status' => false,
            'message' => 'MTN terminal record was not found.',
        ], 404);
    }

    if (empty($terminal->private_key) || empty($terminal->public_key)) {
        return response()->json([
            'status' => false,
            'message' => 'RSA keys are missing.',
        ], 422);
    }

    try {
        $privateKey = Crypt::decryptString($terminal->private_key);
        $publicKey = Crypt::decryptString($terminal->public_key);
    } catch (\Throwable $e) {
        return response()->json([
            'status' => false,
            'message' => 'Unable to decrypt stored RSA keys.',
        ], 500);
    }

    $privateResource = openssl_pkey_get_private($privateKey);

    if (!$privateResource) {
        return response()->json([
            'status' => false,
            'message' => 'Stored private key is invalid.',
        ], 500);
    }

    $body = [
        'Secret' => $activationCode,
        'Key' => $publicKey,
        'Serial' => $serial,
    ];

    $jsonBody = json_encode(
        $body,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    $signature = '';

    $signed = openssl_sign(
        $jsonBody,
        $signature,
        $privateResource,
        OPENSSL_ALGO_SHA256
    );

    if (!$signed) {
        return response()->json([
            'status' => false,
            'message' => 'RSA signature test failed.',
        ], 500);
    }

    return response()->json([
        'status' => true,
        'message' => 'MTN activation pre-check passed. No request was sent to MTN.',
        'terminal_id' => $terminalId,
        'serial' => $serial,
        'api_url' => $apiUrl,
        'public_key_length' => strlen($publicKey),
        'signature_length' => strlen(base64_encode($signature)),
        'already_activated' => (bool) $terminal->is_activated,
    ]);
}





}