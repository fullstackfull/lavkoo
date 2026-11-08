<?php

namespace Tests\Support\Concurrency;

use App\Models\PaymentRequest;
use App\Services\MyFatoorah\PaymentInitiationService;
use Illuminate\Support\Facades\Http;

/**
 * What the MyFatoorah concurrency tests run in each process.
 *
 * These run in separate PHP processes against a real MySQL, which is the only way to test the thing that
 * matters here: that a row lock and a unique index really do stop two simultaneous checkouts from
 * creating two invoices for one order. SQLite in memory is private to one process, so it cannot.
 */
final class MyFatoorahOperations
{
    /**
     * Start a MyFatoorah payment for one payment request, as a web request would.
     *
     * Each process fakes its own HTTP, so no real provider is involved; what is shared and real is the
     * database. The answer each process reports is enough to tell a winner from a loser.
     *
     * @param array{payment_request: string} $args
     * @return array{ok: bool, code: string|null, invoice: string|null, attempt: string|null, created_invoice: bool}
     */
    public static function startPayment(array $args): array
    {
        $createdInvoice = false;

        Http::fake(function ($request) use (&$createdInvoice) {
            if (str_contains($request->url(), '/v3/invoices/')) {
                // Nothing in doubt exists at the provider in these tests.
                return Http::response(['IsSuccess' => false, 'Message' => 'Not Found'], 404);
            }

            // One invoice per process that gets this far: the count is what the test asserts on.
            $createdInvoice = true;

            return Http::response([
                'IsSuccess' => true,
                'Data' => [
                    'InvoiceId' => 'INV-' . getmypid(),
                    'PaymentId' => null,
                    'PaymentURL' => 'https://sa.myfatoorah.com/KSA/ie/' . getmypid(),
                    'PaymentCompleted' => false,
                ],
            ], 201);
        });

        $paymentRequest = PaymentRequest::find($args['payment_request']);

        if ($paymentRequest === null) {
            return ['ok' => false, 'code' => 'no_request', 'invoice' => null, 'attempt' => null, 'created_invoice' => false];
        }

        $result = app(PaymentInitiationService::class)->start($paymentRequest);

        return [
            'ok' => (bool) ($result['ok'] ?? false),
            'code' => $result['code'] ?? null,
            'invoice' => isset($result['attempt']) ? (string) $result['attempt']->invoice_id : null,
            'attempt' => isset($result['attempt']) ? (string) $result['attempt']->external_identifier : null,
            // True only where this process itself POSTed a create to MyFatoorah.
            'created_invoice' => $createdInvoice,
        ];
    }
}
