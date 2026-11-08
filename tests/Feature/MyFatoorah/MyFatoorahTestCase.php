<?php

namespace Tests\Feature\MyFatoorah;

use App\Services\MyFatoorah\GatewayConfigRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsMyFatoorahSchema;
use Tests\TestCase;

/**
 * Base for the MyFatoorah tests: a clean in-memory database with the tables this feature touches, the
 * gateway switched on and configured, and the real network forbidden.
 *
 * `Http::preventStrayRequests()` is the important one. These tests assert what the integration does
 * with MyFatoorah's answers, and a test that silently reached the real sandbox would be both flaky and
 * a real API call from CI. Any request a test has not explicitly faked fails the test.
 *
 * The success and failure hooks are global functions in this codebase (the shared payment ledger stores
 * their NAME and calls them with call_user_func), so the tests declare their own and count the calls.
 * Counting is the point: "exactly once" is the property most of these tests exist to prove.
 */
abstract class MyFatoorahTestCase extends TestCase
{
    // Deliberately NOT RefreshDatabase: that runs `migrate:fresh`, and this application's migrations
    // assume the installed SQL dump is already in place (see CLAUDE.md — never migrate an empty
    // database). The schema is built here instead, the same way the fulfillment tests do it. Each test
    // gets a brand new in-memory SQLite database because the application is rebuilt per test.
    use BuildsMyFatoorahSchema;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->buildMyFatoorahSchema();

        // Global-namespace stand-ins for the hooks the payment ledger calls by name. See the file for
        // why they cannot live in this namespace.
        require_once __DIR__ . '/../../Support/myfatoorah_test_hooks.php';

        Http::preventStrayRequests();

        config([
            'myfatoorah.enabled' => true,
            'myfatoorah.currency' => 'SAR',
            'myfatoorah.webhook.require_signature' => true,
            // No sleeping in tests: the client's backoff and the sweep's politeness pause would
            // otherwise add real seconds to the suite.
            'myfatoorah.http.max_retries' => 0,
            'myfatoorah.http.retry_base_ms' => 1,
            'myfatoorah.reconciliation.sleep_ms_between' => 0,
            'myfatoorah.reconciliation.min_seconds_between' => 0,
        ]);

        $this->sarIsActive();
        $this->digitalPaymentOn();

        $GLOBALS['myfatoorah_success_calls'] = [];
        $GLOBALS['myfatoorah_failure_calls'] = [];
        $GLOBALS['myfatoorah_success_should_throw'] = false;

        app()->forgetInstance(GatewayConfigRepository::class);
    }

    /** Save credentials for one or both modes, and switch the gateway on. */
    protected function configureGateway(
        string $mode = 'test',
        ?string $apiKey = 'test-api-key',
        ?string $webhookSecret = 'test-webhook-secret',
        bool $active = true,
    ): void {
        $repo = app(GatewayConfigRepository::class);

        $repo->save(
            active: $active,
            mode: $mode,
            secrets: ['api_key' => $apiKey, 'webhook_secret' => $webhookSecret],
            secretsMode: $mode,
        );

        $repo->forget();
    }

    protected function configRepo(): GatewayConfigRepository
    {
        $repo = app(GatewayConfigRepository::class);
        $repo->forget();

        return $repo;
    }

    /** How many times the success hook has run. The number most of these tests are about. */
    protected function successHookCalls(): int
    {
        return count($GLOBALS['myfatoorah_success_calls'] ?? []);
    }

    protected function failureHookCalls(): int
    {
        return count($GLOBALS['myfatoorah_failure_calls'] ?? []);
    }

    /**
     * Answer a series of calls to the same URL pattern differently, in order.
     *
     * Needed because `Http::fake([...])` MERGES stub sets and the FIRST registered match wins — so
     * calling it twice for the same pattern leaves the original stub answering, and a test that meant
     * to change the provider's reply silently keeps the old one. A closure is registered once and
     * decides per call instead.
     *
     * @param array<int, \Illuminate\Http\Client\Response> $responses one per call, the last repeating
     */
    protected function fakeInOrder(array $responses): void
    {
        $call = 0;

        Http::fake(function () use (&$call, $responses) {
            $response = $responses[$call] ?? $responses[count($responses) - 1];
            $call++;

            return $response;
        });
    }

    /** A payment-details body for `GET /v3/payments/{id}`, in MyFatoorah's v3 shape. */
    protected function paymentDetails(
        string $externalIdentifier,
        string $invoiceStatus = 'PAID',
        string $transactionStatus = 'SUCCESS',
        string $amount = '150.00',
        string $currency = 'SAR',
        string $paymentId = 'PAY-1',
        string $invoiceId = 'INV-1',
        array $overrides = [],
    ): array {
        return array_replace_recursive([
            'IsSuccess' => true,
            'Message' => '',
            'ValidationErrors' => null,
            'Data' => [
                'Invoice' => [
                    'Id' => $invoiceId,
                    'Status' => $invoiceStatus,
                    'Reference' => '2026000001',
                    'ExternalIdentifier' => $externalIdentifier,
                    'UserDefinedField' => null,
                ],
                'Transaction' => [
                    'Id' => 'TXN-1',
                    'Status' => $transactionStatus,
                    'PaymentMethod' => 'MADA',
                    'PaymentId' => $paymentId,
                    'ReferenceId' => 'REF-1',
                    'TrackId' => 'TRK-1',
                    'AuthorizationId' => 'AUTH-1',
                    'Error' => ['Code' => '', 'Message' => '', 'ResponseCode' => '00'],
                    'Card' => ['Brand' => 'MADA', 'Number' => '440647xxxxxx1234', 'NameOnCard' => 'TEST CARD'],
                ],
                'Customer' => ['Name' => 'Test Customer', 'Mobile' => '0551234567', 'Email' => 'c@example.com'],
                'Amount' => [
                    'BaseCurrency' => $currency,
                    'ValueInBaseCurrency' => $amount,
                    'DisplayCurrency' => $currency,
                    'ValueInDisplayCurrency' => $amount,
                    'PayCurrency' => $currency,
                    'ValueInPayCurrency' => $amount,
                    'ServiceCharge' => '0.00',
                    'ReceivableAmount' => $amount,
                ],
                'Suppliers' => [],
            ],
        ], $overrides);
    }

    /** A PAYMENT_STATUS_CHANGED webhook envelope. */
    protected function webhookPayload(
        string $externalIdentifier,
        string $invoiceStatus = 'PAID',
        string $transactionStatus = 'SUCCESS',
        string $paymentId = 'PAY-1',
        string $invoiceId = 'INV-1',
        string $eventReference = 'EV-1',
    ): array {
        return [
            'Event' => [
                'Code' => 1,
                'Name' => 'PAYMENT_STATUS_CHANGED',
                'CountryIsoCode' => 'SAU',
                'CreationDate' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                'Reference' => $eventReference,
            ],
            'Data' => [
                'Invoice' => [
                    'Id' => $invoiceId,
                    'Status' => $invoiceStatus,
                    'ExternalIdentifier' => $externalIdentifier,
                ],
                'Transaction' => [
                    'Status' => $transactionStatus,
                    'PaymentId' => $paymentId,
                ],
            ],
        ];
    }

    /**
     * The signature MyFatoorah would send for a payload: HMAC-SHA256 of the documented five-field
     * string, keyed with the secret as raw binary, base64-encoded.
     */
    protected function signatureFor(array $payload, string $secret = 'test-webhook-secret'): string
    {
        return app(\App\Services\MyFatoorah\WebhookSignatureVerifier::class)
            ->expectedSignature($payload, 1, $secret);
    }
}
