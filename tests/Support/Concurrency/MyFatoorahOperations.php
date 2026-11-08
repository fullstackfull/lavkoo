<?php

namespace Tests\Support\Concurrency;

use App\Enums\MyFatoorah\AttemptStatus;
use App\Models\MyFatoorahPaymentAttempt;
use App\Models\PaymentRequest;
use App\Services\MyFatoorah\PaymentInitiationService;
use App\Services\MyFatoorah\PaymentSettlementService;
use App\Services\MyFatoorah\PaymentVerificationService;
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
     * Verify and settle one attempt, as a webhook delivery or a returning customer would.
     *
     * Used to race two attempts of the same order against each other in separate processes: an invoice
     * we abandoned being paid late, at the same moment as the live one. What must hold is that the
     * order is created once and for the right money.
     *
     * @param array{attempt: string, payment_id: string, amount: string, external_identifier: string}  $args
     * @return array{status: string, settled: bool, reason: string}
     */
    public static function settleAttempt(array $args): array
    {
        config(['myfatoorah.enabled' => true]);

        Http::fake(fn () => Http::response([
            'IsSuccess' => true,
            'Message' => '',
            'Data' => [
                'Invoice' => [
                    'Id' => (string) ($args['invoice_id'] ?? 'INV-1'),
                    'Status' => 'PAID',
                    'ExternalIdentifier' => (string) $args['external_identifier'],
                ],
                'Transaction' => [
                    'Status' => 'SUCCESS',
                    'PaymentId' => (string) $args['payment_id'],
                    'PaymentMethod' => 'MADA',
                    'Error' => ['Code' => '', 'Message' => ''],
                ],
                'Amount' => [
                    'BaseCurrency' => 'SAR',
                    'ValueInBaseCurrency' => (string) $args['amount'],
                    'DisplayCurrency' => 'SAR',
                    'ValueInDisplayCurrency' => (string) $args['amount'],
                    'PayCurrency' => 'SAR',
                    'ValueInPayCurrency' => (string) $args['amount'],
                ],
            ],
        ]));

        $attempt = MyFatoorahPaymentAttempt::query()->where('external_identifier', $args['attempt'])->first();

        if ($attempt === null) {
            return ['status' => 'missing', 'settled' => false, 'reason' => 'no such attempt'];
        }

        $verified = app(PaymentVerificationService::class)->verify($attempt, (string) $args['payment_id']);

        if ($verified['status'] !== AttemptStatus::Paid) {
            return ['status' => $verified['status']->value, 'settled' => false, 'reason' => $verified['message']];
        }

        $settled = app(PaymentSettlementService::class)->settle($verified['attempt']);

        return [
            'status' => $verified['status']->value,
            'settled' => (bool) $settled['settled'],
            'reason' => (string) $settled['reason'],
        ];
    }

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
        /*
         * Each worker is its own PHP process that boots the application from scratch and reads config
         * from its own environment — it does not inherit the test's `config()` calls. The environment
         * switch is therefore set here rather than exported by whoever runs the suite: relying on an
         * exported variable passed locally and failed in CI, where the job does not set it, and the
         * tests reported `gateway_unavailable` instead of testing anything.
         */
        config(['myfatoorah.enabled' => true]);

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
