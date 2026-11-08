<?php

namespace Tests\Feature\MyFatoorah;

use App\Enums\MyFatoorah\AttemptStatus;
use App\Models\MyFatoorahPaymentAttempt;
use App\Models\MyFatoorahRefund;
use App\Services\MyFatoorah\ConnectionTester;
use App\Services\MyFatoorah\GatewayConfigRepository;
use App\Services\MyFatoorah\PaymentMethodCatalog;
use App\Services\MyFatoorah\RefundService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Refunds, the method catalogue and the connection test.
 *
 * The refund rules worth stating: a MyFatoorah refund is a REQUEST their finance team executes later,
 * so "asked for" and "actually refunded" are different states and the store must not claim the second
 * when only the first has happened. And no sequence of partial refunds may add up to more than was paid.
 */
class RefundAndAdminTest extends MyFatoorahTestCase
{
    private MyFatoorahPaymentAttempt $paid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureGateway();

        $this->paid = MyFatoorahPaymentAttempt::create([
            'payment_request_id' => $this->makePaymentRequest(),
            'attempt_number' => 1,
            'external_identifier' => 'pk-paid-attempt-1',
            'expected_amount' => '150.00',
            'expected_currency' => 'SAR',
            'captured_amount' => '150.00',
            'captured_currency' => 'SAR',
            'status' => AttemptStatus::Paid->value,
            'mode' => 'test',
            'payment_id' => 'PAY-1',
            'invoice_id' => 'INV-1',
            'verified_at' => now(),
            'settled_at' => now(),
            'hook_completed_at' => now(),
        ]);
    }

    private function fakeRefundAccepted(string $refundId = 'RF-1'): void
    {
        Http::fake(['*/v3/refunds' => Http::response([
            'IsSuccess' => true,
            'Message' => 'Refund Created Successfully!',
            'Data' => [
                'Refund' => [
                    'Id' => $refundId,
                    'Reference' => '2026000012',
                    'Status' => 'Pending',
                    'InvoiceId' => 'INV-REFUND-1',
                ],
            ],
        ], 201)]);
    }

    // ---------------------------------------------------------------- refunds

    public function test_a_full_refund_is_requested_not_claimed_as_done(): void
    {
        $this->fakeRefundAccepted();

        $result = app(RefundService::class)->request($this->paid, 150.00, 'Customer returned the order');

        $this->assertTrue($result['ok']);

        $refund = $result['refund'];
        // MyFatoorah reviews refunds before executing them. Telling the customer their money is back
        // at this point would be a week early.
        $this->assertSame(MyFatoorahRefund::STATUS_PENDING, $refund->status);
        $this->assertFalse($refund->isExecuted());
        $this->assertSame('150.00', $refund->amount);
        $this->assertSame('RF-1', $refund->myfatoorah_refund_id);

        Http::assertSent(fn ($request) => $request->url() === 'https://apitest.myfatoorah.com/v3/refunds'
            && $request['PaymentId'] === 'PAY-1'
            && $request['Amount'] === 150.0
            && $request['ServiceChargeOnCustomer'] === false);
    }

    public function test_a_refund_is_only_executed_when_someone_confirms_it(): void
    {
        $this->fakeRefundAccepted();
        $refund = app(RefundService::class)->request($this->paid, 50.00)['refund'];

        $this->assertFalse($refund->isExecuted());

        app(RefundService::class)->markExecuted($refund, approvedBy: 3);

        $this->assertTrue($refund->refresh()->isExecuted());
        $this->assertNotNull($refund->executed_at);
        $this->assertSame(3, (int) $refund->approved_by);
    }

    public function test_partial_refunds_may_add_up_to_the_paid_amount_and_no_further(): void
    {
        $this->fakeRefundAccepted();
        $service = app(RefundService::class);

        $this->assertTrue($service->request($this->paid, 60.00, idempotencyKey: 'a')['ok']);
        $this->assertTrue($service->request($this->paid->refresh(), 60.00, idempotencyKey: 'b')['ok']);

        // 120 of 150 asked for. The next 40 would exceed it.
        $tooMuch = $service->request($this->paid->refresh(), 40.00, idempotencyKey: 'c');

        $this->assertFalse($tooMuch['ok']);
        $this->assertSame('exceeds_paid', $tooMuch['code']);

        // Exactly the remainder is allowed.
        $this->assertTrue($service->request($this->paid->refresh(), 30.00, idempotencyKey: 'd')['ok']);
        // Compared as a number: SUM() comes back formatted differently on different drivers.
        $this->assertEqualsWithDelta(150.00, (float) MyFatoorahRefund::query()->sum('amount'), 0.001);
    }

    public function test_pending_refund_requests_count_against_the_paid_amount(): void
    {
        // A request under review has not moved money yet, but it is going to. Ignoring it would let the
        // same payment be refunded twice while the first one waits.
        $this->fakeRefundAccepted();
        $service = app(RefundService::class);

        $service->request($this->paid, 150.00, idempotencyKey: 'first');
        $second = $service->request($this->paid->refresh(), 150.00, idempotencyKey: 'second');

        $this->assertFalse($second['ok']);
        $this->assertSame('exceeds_paid', $second['code']);
        $this->assertSame(1, MyFatoorahRefund::count());
    }

    public function test_the_same_refund_decision_submitted_twice_refunds_once(): void
    {
        $this->fakeRefundAccepted();
        $service = app(RefundService::class);

        $first = $service->request($this->paid, 25.00, idempotencyKey: 'approval-77');
        $second = $service->request($this->paid->refresh(), 25.00, idempotencyKey: 'approval-77');

        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        $this->assertSame($first['refund']->id, $second['refund']->id);
        $this->assertSame(1, MyFatoorahRefund::count());
        Http::assertSentCount(1);
    }

    public function test_a_payment_that_was_never_confirmed_cannot_be_refunded(): void
    {
        $unpaid = MyFatoorahPaymentAttempt::create([
            'payment_request_id' => $this->makePaymentRequest(),
            'attempt_number' => 1,
            'external_identifier' => 'pk-unpaid-1',
            'expected_amount' => '10.00',
            'expected_currency' => 'SAR',
            'status' => AttemptStatus::Redirected->value,
            'mode' => 'test',
        ]);

        $result = app(RefundService::class)->request($unpaid, 10.00);

        $this->assertFalse($result['ok']);
        $this->assertSame('not_paid', $result['code']);
        $this->assertSame(0, MyFatoorahRefund::count());
    }

    public function test_a_refund_that_myfatoorah_refuses_is_recorded_as_failed_and_frees_the_headroom(): void
    {
        $this->fakeInOrder([
            Http::response(['IsSuccess' => false, 'Message' => 'Refund amount exceeds the remaining balance'], 422),
            Http::response([
                'IsSuccess' => true,
                'Data' => ['Refund' => ['Id' => 'RF-2', 'Reference' => '2026000013', 'Status' => 'Pending']],
            ], 201),
        ]);

        $result = app(RefundService::class)->request($this->paid, 150.00, idempotencyKey: 'x');

        $this->assertFalse($result['ok']);
        $this->assertSame(MyFatoorahRefund::STATUS_FAILED, MyFatoorahRefund::firstOrFail()->status);

        // A refund that was refused asked for nothing, so the money is available to try again.
        $this->assertTrue(app(RefundService::class)->request($this->paid->refresh(), 150.00, idempotencyKey: 'y')['ok']);
    }

    public function test_a_refund_whose_outcome_is_unknown_stays_requested_so_it_is_not_sent_twice(): void
    {
        // The dangerous one: we do not know whether MyFatoorah accepted it. Marking it failed would let
        // an operator send a second refund for the same money.
        Http::fake(['*/v3/refunds' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);

        $result = app(RefundService::class)->request($this->paid, 150.00, idempotencyKey: 'z');

        $this->assertFalse($result['ok']);
        $this->assertSame('unknown', $result['code']);

        $refund = MyFatoorahRefund::firstOrFail();
        $this->assertSame(MyFatoorahRefund::STATUS_REQUESTED, $refund->status);

        // And it still counts against the paid amount, so a second attempt is refused.
        $this->fakeRefundAccepted();
        $again = app(RefundService::class)->request($this->paid->refresh(), 150.00, idempotencyKey: 'different');
        $this->assertFalse($again['ok']);
        $this->assertSame('exceeds_paid', $again['code']);
    }

    public function test_a_refund_does_not_return_anything_to_stock(): void
    {
        // Money coming back is not evidence that goods did. The store's returns workflow owns stock.
        $this->fakeRefundAccepted();

        app(RefundService::class)->request($this->paid, 150.00);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'stock'));
        $this->assertSame(0, DB::table('myfatoorah_refunds')->whereNotNull('order_id')->count());
    }

    // ---------------------------------------------------------------- method catalogue

    public function test_the_enabled_payment_methods_are_read_from_the_account(): void
    {
        // Not hard-coded, and not taken from the documentation's examples: mada, Apple Pay and STC Pay
        // are each enabled per account, so a fixed list would offer what the account cannot take.
        Http::fake(['*/v3/payment-methods' => Http::response([
            'IsSuccess' => true,
            'Data' => ['PaymentMethods' => [
                ['Name' => 'MADA', 'ApiName' => 'mada', 'CurrencyIso' => 'SAR', 'IsDirectPayment' => 'No', 'IsEmbeddedSupported' => 'Yes', 'LogoUrl' => 'https://portal.myfatoorah.com/mada.png', 'CommissionPercentage' => '2.00%'],
                ['Name' => 'VISA/MASTER', 'ApiName' => 'vm', 'CurrencyIso' => 'SAR', 'IsDirectPayment' => 'No', 'IsEmbeddedSupported' => 'Yes', 'LogoUrl' => 'https://portal.myfatoorah.com/vm.png'],
                ['Name' => 'Apple Pay', 'ApiName' => 'ap', 'CurrencyIso' => 'SAR', 'IsDirectPayment' => 'Yes', 'IsEmbeddedSupported' => 'Yes', 'LogoUrl' => 'https://evil.example.com/ap.png'],
                ['Name' => 'STC Pay', 'ApiName' => 'stcpay', 'CurrencyIso' => 'SAR', 'IsDirectPayment' => 'No', 'IsEmbeddedSupported' => 'No'],
            ]],
        ])]);

        $result = app(PaymentMethodCatalog::class)->refresh('test');

        $this->assertTrue($result['ok']);
        $this->assertCount(4, $result['methods']);
        $this->assertSame(['mada', 'vm', 'ap', 'stcpay'], array_column($result['methods'], 'api_name'));
        $this->assertTrue($result['methods'][2]['direct'], 'Yes/No strings are read as booleans');

        // A logo URL that is not on a MyFatoorah host is dropped rather than loaded by the admin page.
        $this->assertNull($result['methods'][2]['logo_url']);
        $this->assertSame('https://portal.myfatoorah.com/mada.png', $result['methods'][0]['logo_url']);

        // Cached on the gateway settings so a checkout never waits on this call.
        $this->assertCount(4, $this->configRepo()->get()->accountMethods);
    }

    public function test_only_the_methods_an_operator_chose_are_sent_to_the_hosted_page(): void
    {
        $this->configRepo()->save(settings: ['enabled_methods' => ['mada', 'vm']]);

        Http::fake(['*/v3/payments' => Http::response([
            'IsSuccess' => true,
            'Data' => ['InvoiceId' => 'INV-9', 'PaymentURL' => 'https://sa.myfatoorah.com/x'],
        ], 201)]);

        app(\App\Services\MyFatoorah\PaymentInitiationService::class)
            ->start(\App\Models\PaymentRequest::find($this->makePaymentRequest()));

        Http::assertSent(fn ($request) => $request['DisplayPaymentMethods'] === ['mada', 'vm']);
    }

    public function test_with_no_choice_made_every_method_the_account_allows_is_offered(): void
    {
        Http::fake(['*/v3/payments' => Http::response([
            'IsSuccess' => true,
            'Data' => ['InvoiceId' => 'INV-9', 'PaymentURL' => 'https://sa.myfatoorah.com/x'],
        ], 201)]);

        app(\App\Services\MyFatoorah\PaymentInitiationService::class)
            ->start(\App\Models\PaymentRequest::find($this->makePaymentRequest()));

        Http::assertSent(fn ($request) => !isset($request['DisplayPaymentMethods']));
    }

    // ---------------------------------------------------------------- connection test

    public function test_the_connection_test_is_a_read_and_records_its_result_per_mode(): void
    {
        Http::fake(['*/v3/payment-methods' => Http::response([
            'IsSuccess' => true,
            'Data' => ['PaymentMethods' => [['Name' => 'MADA', 'ApiName' => 'mada', 'CurrencyIso' => 'SAR']]],
        ])]);

        $result = app(ConnectionTester::class)->test('test');

        $this->assertTrue($result['ok']);

        // A read: no invoice was created, which on a live account would be a real one.
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');

        $config = $this->configRepo()->forMode('test');
        $this->assertSame('ok', $config->lastTestResult);
        $this->assertNotNull($config->lastTestAt);

        // The other mode was untouched.
        $this->assertNull($this->configRepo()->forMode('live')->lastTestResult);
    }

    public function test_the_connection_test_reports_a_rejected_key(): void
    {
        Http::fake(['*/v3/payment-methods' => Http::response([
            'IsSuccess' => false,
            'Message' => 'The token does not have the required permissions!',
        ], 401)]);

        $result = app(ConnectionTester::class)->test('test');

        $this->assertFalse($result['ok']);
        $this->assertSame('failed', $this->configRepo()->forMode('test')->lastTestResult);
    }

    public function test_the_connection_test_warns_when_the_account_cannot_settle_in_sar(): void
    {
        Http::fake(['*/v3/payment-methods' => Http::response([
            'IsSuccess' => true,
            'Data' => ['PaymentMethods' => [['Name' => 'KNET', 'ApiName' => 'kn', 'CurrencyIso' => 'KWD']]],
        ])]);

        $result = app(ConnectionTester::class)->test('test');

        $this->assertFalse($result['ok'], 'a key that works on the wrong country is still not usable');
        $this->assertStringContainsString('SAR', $result['message']);
    }

    public function test_no_outbound_call_is_made_while_the_kill_switch_is_off(): void
    {
        config(['myfatoorah.enabled' => false]);

        $result = app(ConnectionTester::class)->test('test');

        $this->assertFalse($result['ok']);
        // Http::preventStrayRequests() would fail the test if anything had been sent.
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------- registration and compatibility

    public function test_the_gateway_is_registered_switched_off_with_no_credentials(): void
    {
        // A fresh install must not start offering a payment method nobody has configured.
        DB::table('addon_settings')->where('key_name', 'fatoorah')->delete();
        $this->runMyFatoorahMigrations();

        $row = DB::table('addon_settings')->where('key_name', 'fatoorah')->first();

        $this->assertNotNull($row, 'the gateway card has a row to render');
        $this->assertSame(0, (int) $row->is_active);
        $this->assertSame('payment_config', $row->settings_type);
        $this->assertStringContainsString('MyFatoorah', (string) $row->additional_data);

        $config = $this->configRepo()->get();
        $this->assertFalse($config->hasApiKey());
        $this->assertFalse($config->activeInAdmin);
    }

    public function test_registration_does_not_disturb_an_already_configured_gateway(): void
    {
        $this->configureGateway(apiKey: 'already-here');

        // Running the migration again (a redeploy) must not wipe anything.
        $this->runMyFatoorahMigrations();

        $config = $this->configRepo()->get();
        $this->assertSame('already-here', $config->apiKey);
        $this->assertTrue($config->activeInAdmin);
    }

    public function test_the_gateway_key_stays_fatoorah_so_old_records_still_resolve(): void
    {
        // Historical payment_requests rows carry 'fatoorah', and every gateway list in the codebase
        // already used it. A second key would orphan those and show two MyFatoorah cards.
        $this->assertSame('fatoorah', GatewayConfigRepository::KEY);
        $this->assertContains('fatoorah', \App\Enums\GlobalConstant::DEFAULT_PAYMENT_GATEWAYS);
        $this->assertContains('fatoorah', \App\Utils\Helpers::getDefaultPaymentGateways());

        $this->assertSame(1, DB::table('addon_settings')->where('key_name', 'fatoorah')->count(), 'exactly one card');
        $this->assertSame(0, DB::table('addon_settings')->where('key_name', 'my_fatoorah')->count(), 'no duplicate');
    }

    public function test_a_plaintext_key_left_by_the_shared_form_is_still_usable(): void
    {
        // The shared gateway form writes what it is given, unencrypted, into both blobs. An operator who
        // used it must not be met with a decryption error.
        DB::table('addon_settings')
            ->where('key_name', 'fatoorah')
            ->update([
                'is_active' => 1,
                'mode' => 'test',
                'test_values' => json_encode(['status' => 1, 'mode' => 'test', 'api_key' => 'typed-in-the-old-form']),
            ]);

        $this->assertSame('typed-in-the-old-form', $this->configRepo()->get()->apiKey);
    }

    public function test_a_tampered_host_in_the_configuration_is_ignored(): void
    {
        // The settings row is editable by anyone with database access, and the one thing worth stealing
        // from it is where the API key gets sent.
        config(['myfatoorah.hosts.test' => 'https://evil.example.com']);

        $this->assertSame('https://apitest.myfatoorah.com', $this->configRepo()->forMode('test')->baseUrl);
    }
}
