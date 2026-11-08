<?php

namespace Tests\Feature\MyFatoorah;

use App\Enums\MyFatoorah\AttemptStatus;
use App\Enums\MyFatoorah\GatewayStatus;
use App\Models\MyFatoorahPaymentAttempt;
use App\Models\PaymentRequest;
use App\Services\MyFatoorah\GatewayAvailability;
use App\Services\MyFatoorah\PaymentInitiationService;
use App\Services\MyFatoorah\PaymentSettlementService;
use App\Services\MyFatoorah\PaymentVerificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Starting a payment, and deciding whether one happened.
 *
 * The theme running through these: the server decides, from its own record of what was owed and from a
 * server-to-server answer, and nothing a browser or an app says is allowed to influence either.
 */
class PaymentFlowTest extends MyFatoorahTestCase
{
    private function initiation(): PaymentInitiationService
    {
        return app(PaymentInitiationService::class);
    }

    /** `POST /v3/payments` answering with a hosted page. */
    private function fakeCreatePayment(string $paymentUrl = 'https://sa.myfatoorah.com/KSA/ie/010203', string $invoiceId = 'INV-1'): void
    {
        Http::fake([
            '*/v3/payments' => Http::response([
                'IsSuccess' => true,
                'Message' => '',
                'ValidationErrors' => null,
                'Data' => [
                    'InvoiceId' => $invoiceId,
                    'PaymentId' => null,
                    'PaymentURL' => $paymentUrl,
                    'PaymentCompleted' => false,
                    'TransactionDetails' => null,
                ],
            ], 201),
        ]);
    }

    // ---------------------------------------------------------------- the gateway's own switches

    public function test_a_switched_off_gateway_refuses_to_start_a_payment(): void
    {
        $this->configureGateway(active: false);
        $paymentRequestId = $this->makePaymentRequest();

        $result = $this->initiation()->start(PaymentRequest::find($paymentRequestId));

        $this->assertFalse($result['ok']);
        $this->assertSame('gateway_unavailable', $result['code']);
        // Nothing was sent: Http::preventStrayRequests() would have failed the test if it had been.
        $this->assertSame(0, MyFatoorahPaymentAttempt::count());
    }

    public function test_the_environment_kill_switch_overrides_the_admin_panel(): void
    {
        $this->configureGateway();
        config(['myfatoorah.enabled' => false]);

        $status = app(GatewayAvailability::class)->check();

        $this->assertFalse($status['ready']);
        $this->assertSame(GatewayStatus::DisabledByEnvironment, $status['status']);
    }

    public function test_an_account_with_no_api_key_is_not_ready(): void
    {
        $this->configureGateway(apiKey: null, webhookSecret: 'secret');

        $status = app(GatewayAvailability::class)->check();

        $this->assertFalse($status['ready']);
        $this->assertSame(GatewayStatus::MissingApiKey, $status['status']);
    }

    public function test_an_account_with_no_webhook_secret_is_not_ready(): void
    {
        // Without a secret a notification cannot be authenticated, and an integration that accepts
        // unauthenticated payment notifications is worse than one that is switched off.
        $this->configureGateway(apiKey: 'key', webhookSecret: null);

        $status = app(GatewayAvailability::class)->check();

        $this->assertFalse($status['ready']);
        $this->assertSame(GatewayStatus::MissingWebhookSecret, $status['status']);
    }

    public function test_the_store_wide_digital_payment_switch_is_honoured(): void
    {
        $this->configureGateway();
        $this->digitalPaymentOn(false);

        $this->assertFalse(app(GatewayAvailability::class)->isReady());
    }

    // ---------------------------------------------------------------- modes

    public function test_each_mode_uses_its_own_host_and_its_own_key(): void
    {
        $repo = $this->configRepo();
        $repo->save(active: true, mode: 'test', secrets: ['api_key' => 'sandbox-key', 'webhook_secret' => 's1'], secretsMode: 'test');
        $repo->save(mode: 'test', secrets: ['api_key' => 'live-key', 'webhook_secret' => 's2'], secretsMode: 'live');

        $sandbox = $repo->forMode('test');
        $live = $repo->forMode('live');

        $this->assertSame('sandbox-key', $sandbox->apiKey);
        $this->assertSame('https://apitest.myfatoorah.com', $sandbox->baseUrl);

        $this->assertSame('live-key', $live->apiKey);
        $this->assertSame('https://api-sa.myfatoorah.com', $live->baseUrl);
    }

    public function test_secrets_are_encrypted_at_rest_and_never_rendered_back(): void
    {
        $this->configureGateway(apiKey: 'super-secret-key', webhookSecret: 'super-secret-hook');

        $stored = (string) DB::table('addon_settings')->where('key_name', 'fatoorah')->value('test_values');

        // A database dump must not hand anyone a usable key.
        $this->assertStringNotContainsString('super-secret-key', $stored);
        $this->assertStringNotContainsString('super-secret-hook', $stored);

        // ...and it is still readable by the application.
        $this->assertSame('super-secret-key', $this->configRepo()->get()->apiKey);

        // The display shape says only whether a secret exists.
        $display = $this->configRepo()->get()->toDisplayArray();
        $this->assertTrue($display['has_api_key']);
        $this->assertSame('super-secret-key', $this->configRepo()->get()->apiKey);
        $this->assertArrayNotHasKey('api_key', $display);
        $this->assertStringNotContainsString('super-secret', json_encode($display));
    }

    public function test_a_blank_field_on_save_keeps_the_stored_key(): void
    {
        // The form cannot show the operator what is saved, so it posts blank when they did not retype
        // it. Treating that as "clear the key" would silently break a live gateway.
        $this->configureGateway(apiKey: 'original-key');

        $this->configRepo()->save(settings: ['gateway_title' => 'Renamed'], secrets: ['api_key' => ''], secretsMode: 'test');

        $this->assertSame('original-key', $this->configRepo()->get()->apiKey);
        $this->assertSame('Renamed', $this->configRepo()->get()->title);
    }

    // ---------------------------------------------------------------- creating a payment

    public function test_a_payment_is_created_from_the_amount_on_the_server(): void
    {
        $this->configureGateway();
        $this->fakeCreatePayment();
        $paymentRequestId = $this->makePaymentRequest(['payment_amount' => 275.50]);

        $result = $this->initiation()->start(PaymentRequest::find($paymentRequestId));

        $this->assertTrue($result['ok']);
        $this->assertSame('https://sa.myfatoorah.com/KSA/ie/010203', $result['payment_url']);

        $attempt = MyFatoorahPaymentAttempt::firstOrFail();
        $this->assertSame('275.50', $attempt->expected_amount);
        $this->assertSame('SAR', $attempt->expected_currency);
        $this->assertSame(AttemptStatus::Redirected->value, $attempt->status);
        $this->assertSame('INV-1', $attempt->invoice_id);

        // What went to MyFatoorah: our amount, our currency, our reference, and a Bearer token.
        Http::assertSent(function ($request) use ($attempt): bool {
            return $request->url() === 'https://apitest.myfatoorah.com/v3/payments'
                && $request['Order']['Amount'] === 275.5
                && $request['Order']['Currency'] === 'SAR'
                && $request['Order']['ExternalIdentifier'] === $attempt->external_identifier
                && $request->hasHeader('Authorization', 'Bearer test-api-key');
        });
    }

    public function test_a_currency_other_than_sar_is_refused(): void
    {
        $this->configureGateway();
        $paymentRequestId = $this->makePaymentRequest(['currency_code' => 'KWD']);

        $result = $this->initiation()->start(PaymentRequest::find($paymentRequestId));

        $this->assertFalse($result['ok']);
        $this->assertSame('currency_mismatch', $result['code']);
    }

    public function test_a_payment_that_is_already_paid_cannot_be_started_again(): void
    {
        $this->configureGateway();
        $paymentRequestId = $this->makePaymentRequest(['is_paid' => true]);

        $result = $this->initiation()->start(PaymentRequest::find($paymentRequestId));

        $this->assertFalse($result['ok']);
        $this->assertSame('already_paid', $result['code']);
    }

    public function test_pressing_pay_twice_reuses_one_invoice_instead_of_creating_two(): void
    {
        $this->configureGateway();
        $this->fakeCreatePayment();
        $paymentRequest = PaymentRequest::find($this->makePaymentRequest());

        $first = $this->initiation()->start($paymentRequest);
        $second = $this->initiation()->start($paymentRequest->refresh());

        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        $this->assertSame($first['payment_url'], $second['payment_url']);

        // One attempt, and one call to MyFatoorah. A second invoice is a second thing the customer
        // could pay.
        $this->assertSame(1, MyFatoorahPaymentAttempt::count());
        Http::assertSentCount(1);
    }

    public function test_a_basket_that_changed_abandons_the_old_attempt_rather_than_reusing_it(): void
    {
        $this->configureGateway();
        $this->fakeCreatePayment();
        $paymentRequest = PaymentRequest::find($this->makePaymentRequest(['payment_amount' => 100.00]));

        $this->initiation()->start($paymentRequest);

        // The order was edited while the customer was at the gateway.
        DB::table('payment_requests')->where('id', $paymentRequest->id)->update(['payment_amount' => 180.00]);

        $second = $this->initiation()->start(PaymentRequest::find($paymentRequest->id));

        $this->assertTrue($second['ok']);
        $this->assertSame(2, MyFatoorahPaymentAttempt::count());

        // The first attempt is for the wrong money and must never be settleable against this order.
        $first = MyFatoorahPaymentAttempt::where('attempt_number', 1)->firstOrFail();
        $this->assertSame(AttemptStatus::Cancelled->value, $first->status);
        $this->assertSame('amount_changed', $first->last_error_code);

        $this->assertSame('180.00', MyFatoorahPaymentAttempt::where('attempt_number', 2)->value('expected_amount'));
    }

    public function test_a_timeout_while_creating_leaves_the_attempt_open_for_reconciliation(): void
    {
        // A timeout does not say whether an invoice was created. Closing the attempt would hide a real
        // invoice from the sweep; re-sending the POST could create a second one. So it stays open.
        $this->configureGateway();
        Http::fake(['*/v3/payments' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out')]);

        $result = $this->initiation()->start(PaymentRequest::find($this->makePaymentRequest()));

        $this->assertFalse($result['ok']);
        $attempt = MyFatoorahPaymentAttempt::firstOrFail();
        $this->assertSame(AttemptStatus::Created->value, $attempt->status);
        $this->assertTrue($attempt->statusEnum()->isInFlight());
    }

    public function test_only_one_payment_may_be_live_for_an_order_at_a_time(): void
    {
        /*
         * The rule the database enforces, stated here in one process so the fast suite documents it too:
         * while an attempt is live — anything short of a terminal state — a second payment for the same
         * order is refused. The unique index on (payment_request_id, live_slot) is what makes it true
         * under real concurrency; see tests/Feature/Concurrency/MyFatoorahConcurrencyTest.php.
         */
        $this->configureGateway();
        $this->fakeCreatePayment();
        $paymentRequest = PaymentRequest::find($this->makePaymentRequest());

        $first = $this->initiation()->start($paymentRequest);
        $this->assertTrue($first['ok']);

        $attempt = MyFatoorahPaymentAttempt::firstOrFail();
        $this->assertSame(1, (int) $attempt->live_slot, 'the live attempt holds the order\'s slot');

        // A pending payment is still live: the bank has not finished, so the order must not start another.
        $attempt->forceFill(['status' => AttemptStatus::Pending->value, 'payment_url' => null])->save();

        $second = $this->initiation()->start($paymentRequest->refresh());

        $this->assertFalse($second['ok']);
        $this->assertSame(1, MyFatoorahPaymentAttempt::count(), 'no second invoice');

        // Once it is terminal the slot is free and the customer may try again.
        $attempt->forceFill(['status' => AttemptStatus::Failed->value, 'live_slot' => null])->save();
        $this->fakeCreatePayment(invoiceId: 'INV-2');

        $third = $this->initiation()->start($paymentRequest->refresh());

        $this->assertTrue($third['ok']);
        $this->assertSame(2, MyFatoorahPaymentAttempt::count());
    }

    public function test_an_attempt_in_doubt_blocks_a_second_invoice_until_it_is_resolved(): void
    {
        /*
         * The race the review caught. A create call that timed out leaves an attempt with no payment
         * URL and no invoice id, and we cannot know whether MyFatoorah made an invoice for it. The old
         * code skipped such an attempt — `findReusableAttempt()` requires a payment URL — and went
         * straight on to create a second one.
         */
        $this->configureGateway();
        Http::fake(['*/v3/payments' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out')]);

        $paymentRequest = PaymentRequest::find($this->makePaymentRequest());
        $this->initiation()->start($paymentRequest);

        $inDoubt = MyFatoorahPaymentAttempt::firstOrFail();
        $this->assertSame(AttemptStatus::Created->value, $inDoubt->status);
        $this->assertNull($inDoubt->payment_url);

        // MyFatoorah cannot be reached to settle the question either.
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('still down'));

        $second = $this->initiation()->start($paymentRequest->refresh());

        $this->assertFalse($second['ok']);
        $this->assertSame('attempt_in_doubt', $second['code']);
        $this->assertSame(1, MyFatoorahPaymentAttempt::count(), 'no second invoice was created');
    }

    public function test_a_404_from_an_unproven_invoice_lookup_is_not_proof_that_nothing_was_created(): void
    {
        /*
         * MyFatoorah publishes no literal path for its invoice lookups. On an account that does not
         * serve them — or does not permit them — every lookup 404s, and that is indistinguishable from a
         * genuinely absent invoice. Reading it as "nothing was created" would authorise a second invoice
         * for an order that may already have one, so it is refused until the endpoint has been seen to
         * work at least once.
         */
        $this->configureGateway();

        $createAttempted = 0;

        Http::fake(function ($request) use (&$createAttempted) {
            if (str_contains($request->url(), '/v3/invoices/')) {
                return Http::response(['IsSuccess' => false, 'Message' => 'Not Found'], 404);
            }

            $createAttempted++;

            throw new \Illuminate\Http\Client\ConnectionException('timed out');
        });

        $paymentRequest = PaymentRequest::find($this->makePaymentRequest());
        $this->initiation()->start($paymentRequest);

        $inDoubt = MyFatoorahPaymentAttempt::firstOrFail();
        $inDoubt->forceFill(['created_at' => now()->subHour()])->save();
        $this->assertSame(1, $createAttempted);

        $second = $this->initiation()->start($paymentRequest->refresh());

        $this->assertFalse($second['ok']);
        $this->assertSame('attempt_in_doubt', $second['code']);
        $this->assertSame(1, $createAttempted, 'no second invoice was attempted');
        $this->assertSame(1, MyFatoorahPaymentAttempt::count());

        // Still in doubt, still holding the order's live slot, and the reason is recorded for an operator.
        $inDoubt->refresh();
        $this->assertSame(AttemptStatus::Created->value, $inDoubt->status);
        $this->assertSame(1, (int) $inDoubt->live_slot);
        $this->assertSame('lookup_unproven', $inDoubt->last_error_code);

        // And it is counted as needing a person.
        $this->assertSame(1, app(\App\Services\MyFatoorah\ReconciliationService::class)->needsAttention()['in_doubt']);
    }

    public function test_an_operator_can_release_an_attempt_after_checking_the_portal(): void
    {
        // The escape hatch for the case above. A person can answer what the API will not, and nothing
        // automatic reaches this.
        $this->configureGateway();

        // One closure for the whole test: Http::fake() merges stub sets and the first match wins, so a
        // later stub for the same URL would never be reached.
        $providerIsDown = true;

        Http::fake(function () use (&$providerIsDown) {
            if ($providerIsDown) {
                throw new \Illuminate\Http\Client\ConnectionException('down');
            }

            return Http::response([
                'IsSuccess' => true,
                'Data' => ['InvoiceId' => 'INV-AFTER-RELEASE', 'PaymentURL' => 'https://sa.myfatoorah.com/KSA/ie/9'],
            ], 201);
        });

        $paymentRequest = PaymentRequest::find($this->makePaymentRequest());
        $this->initiation()->start($paymentRequest);
        $inDoubt = MyFatoorahPaymentAttempt::firstOrFail();

        $this->assertSame(1, (int) $inDoubt->live_slot);

        // What the admin action does, once the operator has read the portal.
        $inDoubt->forceFill([
            'status' => AttemptStatus::Failed->value,
            'live_slot' => null,
            'last_error_code' => 'never_created_confirmed',
        ])->save();

        // With the slot released the customer can pay again.
        $providerIsDown = false;
        $again = $this->initiation()->start($paymentRequest->refresh());

        $this->assertTrue($again['ok']);
        $this->assertSame(2, MyFatoorahPaymentAttempt::count());
    }

    public function test_a_404_is_proof_once_the_invoice_lookup_has_been_seen_to_work(): void
    {
        // The other half: on an account that does serve the lookup, a 404 really does mean the invoice
        // is not there, and the customer is not left stuck.
        $this->configureGateway();
        $this->configRepo()->markInvoiceLookupProven('test');
        $this->assertTrue($this->configRepo()->get()->invoiceLookupIsProven());

        // One routing closure for the whole test: Http::fake() MERGES stub sets and the first match
        // wins, so a later array stub for the same URL would never be reached.
        $createShouldFail = true;

        Http::fake(function ($request) use (&$createShouldFail) {
            if (str_contains($request->url(), '/v3/invoices/')) {
                return Http::response(['IsSuccess' => false, 'Message' => 'Not Found'], 404);
            }

            if ($createShouldFail) {
                throw new \Illuminate\Http\Client\ConnectionException('timed out');
            }

            return Http::response([
                'IsSuccess' => true,
                'Data' => ['InvoiceId' => 'INV-2', 'PaymentURL' => 'https://sa.myfatoorah.com/KSA/ie/2'],
            ], 201);
        });

        $paymentRequest = PaymentRequest::find($this->makePaymentRequest());
        $this->initiation()->start($paymentRequest);
        $first = MyFatoorahPaymentAttempt::firstOrFail();

        // Past the grace window, so MyFatoorah's answer about it can be believed. A fresh attempt is
        // deliberately untouchable: its create call could still be running.
        $first->forceFill(['created_at' => now()->subHour()])->save();

        $createShouldFail = false;
        $second = $this->initiation()->start($paymentRequest->refresh());

        $this->assertTrue($second['ok']);
        $this->assertSame(AttemptStatus::Failed->value, $first->refresh()->status);
        $this->assertSame('never_created', $first->last_error_code);
        $this->assertSame(2, MyFatoorahPaymentAttempt::count());
    }

    public function test_an_attempt_in_doubt_that_turns_out_to_be_paid_stops_a_second_payment(): void
    {
        // The dangerous case: the create call's response was lost but the invoice existed and the
        // customer paid it. Creating a second invoice here would invite a double charge.
        $this->configureGateway();
        Http::fake(['*/v3/payments' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out')]);

        $paymentRequest = PaymentRequest::find($this->makePaymentRequest());
        $this->initiation()->start($paymentRequest);
        $first = MyFatoorahPaymentAttempt::firstOrFail();
        $first->forceFill(['created_at' => now()->subHour()])->save();

        Http::fake(function ($request) use ($first) {
            if (str_contains($request->url(), '/v3/invoices/')) {
                return Http::response([
                    'IsSuccess' => true,
                    'Data' => ['Transaction' => ['PaymentId' => 'PAY-LOST', 'Status' => 'SUCCESS']],
                ]);
            }

            return Http::response($this->paymentDetails($first->external_identifier, paymentId: 'PAY-LOST'));
        });

        $second = $this->initiation()->start($paymentRequest->refresh());

        $this->assertFalse($second['ok']);
        $this->assertSame('already_paid', $second['code']);
        $this->assertSame(AttemptStatus::Paid->value, $first->refresh()->status);
        $this->assertSame(1, MyFatoorahPaymentAttempt::count(), 'no second invoice');
    }

    public function test_one_order_cannot_mint_invoices_without_limit(): void
    {
        // A provider that keeps failing after returning a URL would otherwise let a customer pressing
        // "pay" create a real invoice every time.
        config(['myfatoorah.max_attempts_per_payment' => 3]);
        $this->configureGateway();
        $paymentRequest = PaymentRequest::find($this->makePaymentRequest());

        for ($i = 1; $i <= 3; $i++) {
            $this->fakeCreatePayment(paymentUrl: 'https://sa.myfatoorah.com/KSA/ie/' . $i, invoiceId: 'INV-' . $i);
            $result = $this->initiation()->start($paymentRequest->refresh());
            $this->assertTrue($result['ok'], "attempt {$i} should be allowed");

            // The customer abandons it. A terminal status releases the order's live slot, which is what
            // lets the next attempt start at all.
            MyFatoorahPaymentAttempt::query()->where('attempt_number', $i)
                ->update(['status' => AttemptStatus::Cancelled->value, 'live_slot' => null]);
        }

        $this->fakeCreatePayment();
        $fourth = $this->initiation()->start($paymentRequest->refresh());

        $this->assertFalse($fourth['ok']);
        $this->assertSame('too_many_attempts', $fourth['code']);
        $this->assertSame(3, MyFatoorahPaymentAttempt::count());
    }

    public function test_a_definite_refusal_while_creating_closes_the_attempt(): void
    {
        $this->configureGateway();
        Http::fake([
            '*/v3/payments' => Http::response([
                'IsSuccess' => false,
                'Message' => 'Invalid currency',
                'ValidationErrors' => [['Name' => 'Order.Currency', 'Error' => 'not enabled']],
            ], 422),
        ]);

        $result = $this->initiation()->start(PaymentRequest::find($this->makePaymentRequest()));

        $this->assertFalse($result['ok']);
        $this->assertSame(AttemptStatus::Failed->value, MyFatoorahPaymentAttempt::firstOrFail()->status);
    }

    public function test_a_payment_url_that_is_not_a_myfatoorah_host_is_refused(): void
    {
        // The customer must never be redirected somewhere else on the strength of an API response.
        $this->configureGateway();
        $this->fakeCreatePayment(paymentUrl: 'https://evil.example.com/collect-cards');

        $result = $this->initiation()->start(PaymentRequest::find($this->makePaymentRequest()));

        $this->assertFalse($result['ok']);
        $this->assertSame('bad_payment_url', $result['code']);
        $this->assertSame(AttemptStatus::Failed->value, MyFatoorahPaymentAttempt::firstOrFail()->status);
    }

    // ---------------------------------------------------------------- verifying a payment

    private function anAttempt(array $overrides = []): MyFatoorahPaymentAttempt
    {
        $this->configureGateway();
        $this->fakeCreatePayment();
        $this->initiation()->start(PaymentRequest::find($this->makePaymentRequest($overrides['request'] ?? [])));

        // The create-payment stub stays registered. It is keyed on `*/v3/payments`, which does not
        // match `/v3/payments/{id}`, so each test's own payment-details stub is the one that answers.
        // (A bare Http::fake() here would register a catch-all that wins over every later stub.)
        return MyFatoorahPaymentAttempt::firstOrFail();
    }

    public function test_a_paid_payment_for_the_right_amount_is_confirmed(): void
    {
        $attempt = $this->anAttempt();

        Http::fake(['*/v3/payments/*' => Http::response($this->paymentDetails($attempt->external_identifier))]);

        $result = app(PaymentVerificationService::class)->verify($attempt, 'PAY-1');

        $this->assertSame(AttemptStatus::Paid, $result['status']);
        $this->assertFalse($result['unknown']);

        $attempt->refresh();
        $this->assertSame('150.00', $attempt->captured_amount);
        $this->assertSame('SAR', $attempt->captured_currency);
        $this->assertSame('PAY-1', $attempt->payment_id);
        $this->assertNotNull($attempt->verified_at);
    }

    public function test_a_payment_for_a_different_amount_is_flagged_and_never_paid(): void
    {
        // The money is real but it is not what was owed, so the order must not be released and the
        // payment must not be written off either. This is the case that silently loses money.
        $attempt = $this->anAttempt();

        Http::fake(['*/v3/payments/*' => Http::response(
            $this->paymentDetails($attempt->external_identifier, amount: '1.00'),
        )]);

        $result = app(PaymentVerificationService::class)->verify($attempt, 'PAY-1');

        $this->assertSame(AttemptStatus::Mismatch, $result['status']);
        $this->assertSame('amount_mismatch', $attempt->refresh()->last_error_code);
        $this->assertSame('1.00', $attempt->captured_amount);
    }

    public function test_a_payment_in_a_different_currency_is_flagged_and_never_paid(): void
    {
        $attempt = $this->anAttempt();

        Http::fake(['*/v3/payments/*' => Http::response(
            $this->paymentDetails($attempt->external_identifier, currency: 'KWD'),
        )]);

        $result = app(PaymentVerificationService::class)->verify($attempt, 'PAY-1');

        $this->assertSame(AttemptStatus::Mismatch, $result['status']);
        $this->assertSame('currency_mismatch', $attempt->refresh()->last_error_code);
    }

    public function test_a_payment_belonging_to_another_order_cannot_be_claimed(): void
    {
        // Feeding us somebody else's paymentId is how one order would be paid with another's money.
        $attempt = $this->anAttempt();

        Http::fake(['*/v3/payments/*' => Http::response(
            $this->paymentDetails('pk-somebodyelse-1'),
        )]);

        $result = app(PaymentVerificationService::class)->verify($attempt, 'PAY-1');

        $this->assertSame(AttemptStatus::Mismatch, $result['status']);
        $this->assertSame('reference_mismatch', $attempt->refresh()->last_error_code);
    }

    public function test_a_payment_without_our_reference_is_never_accepted(): void
    {
        /*
         * The hole this closes: the ownership check used to run only `if ($externalIdentifier !== '')`,
         * so a response that carried no reference at all skipped it entirely and went straight on to the
         * amount comparison. A matching amount is not proof of ownership — two customers checking out
         * the same basket produce two invoices for the identical amount in the identical currency, so
         * either one's payment would have settled the other's order.
         */
        $attempt = $this->anAttempt();

        Http::fake(['*/v3/payments/*' => Http::response(
            $this->paymentDetails($attempt->external_identifier, overrides: [
                'Data' => ['Invoice' => ['ExternalIdentifier' => null]],
            ]),
        )]);

        $result = app(PaymentVerificationService::class)->verify($attempt, 'PAY-1');

        $this->assertSame(AttemptStatus::Mismatch, $result['status']);
        $this->assertSame('reference_missing', $attempt->refresh()->last_error_code);
        $this->assertNotSame(AttemptStatus::Paid->value, $attempt->status);
    }

    public function test_an_empty_reference_is_treated_the_same_as_a_missing_one(): void
    {
        $attempt = $this->anAttempt();

        Http::fake(['*/v3/payments/*' => Http::response(
            $this->paymentDetails($attempt->external_identifier, overrides: [
                'Data' => ['Invoice' => ['ExternalIdentifier' => '']],
            ]),
        )]);

        $result = app(PaymentVerificationService::class)->verify($attempt, 'PAY-1');

        $this->assertSame(AttemptStatus::Mismatch, $result['status']);
        $this->assertSame('reference_missing', $attempt->refresh()->last_error_code);
    }

    public function test_a_payment_reported_against_a_different_invoice_is_not_accepted(): void
    {
        // A second, independent tie. The reference can be right while the response describes another
        // invoice — a reused or stale reference at the provider's end — and the invoice id we recorded
        // when we created the payment catches it.
        $attempt = $this->anAttempt();
        $this->assertSame('INV-1', $attempt->invoice_id, 'the invoice id was recorded at creation');

        Http::fake(['*/v3/payments/*' => Http::response(
            $this->paymentDetails($attempt->external_identifier, invoiceId: 'INV-SOMEBODY-ELSE'),
        )]);

        $result = app(PaymentVerificationService::class)->verify($attempt, 'PAY-1');

        $this->assertSame(AttemptStatus::Mismatch, $result['status']);
        $this->assertSame('invoice_mismatch', $attempt->refresh()->last_error_code);
    }

    public function test_ownership_is_checked_before_the_amount_is_even_read(): void
    {
        // Order matters: a response with no reference must be refused on that ground, not accepted
        // because the money happened to line up.
        $attempt = $this->anAttempt();

        Http::fake(['*/v3/payments/*' => Http::response(
            $this->paymentDetails($attempt->external_identifier, amount: '150.00', overrides: [
                'Data' => ['Invoice' => ['ExternalIdentifier' => null]],
            ]),
        )]);

        app(PaymentVerificationService::class)->verify($attempt, 'PAY-1');

        $this->assertSame('reference_missing', $attempt->refresh()->last_error_code);
        $this->assertNull($attempt->captured_amount, 'the amount was never accepted as captured');
    }

    public function test_a_pending_payment_stays_pending_and_runs_no_hook(): void
    {
        $attempt = $this->anAttempt();

        Http::fake(['*/v3/payments/*' => Http::response(
            $this->paymentDetails($attempt->external_identifier, invoiceStatus: 'PENDING', transactionStatus: 'INPROGRESS'),
        )]);

        $result = app(PaymentVerificationService::class)->verify($attempt, 'PAY-1');

        $this->assertSame(AttemptStatus::Pending, $result['status']);
        // A real answer, not an unknown one: MyFatoorah said it is still working.
        $this->assertFalse($result['unknown']);
        $this->assertSame(0, $this->successHookCalls());
        $this->assertSame(0, $this->failureHookCalls());
    }

    public function test_a_failed_payment_is_reported_as_failed(): void
    {
        $attempt = $this->anAttempt();

        Http::fake(['*/v3/payments/*' => Http::response(
            $this->paymentDetails(
                $attempt->external_identifier,
                invoiceStatus: 'PENDING',
                transactionStatus: 'FAILED',
                overrides: ['Data' => ['Transaction' => ['Error' => ['Code' => 'MF002', 'Message' => 'Issuer declined']]]],
            ),
        )]);

        $result = app(PaymentVerificationService::class)->verify($attempt, 'PAY-1');

        $this->assertSame(AttemptStatus::Failed, $result['status']);
        $this->assertSame('MF002', $attempt->refresh()->last_error_code);
    }

    public function test_a_network_failure_is_unknown_and_never_a_failure(): void
    {
        // The single most important distinction in this integration. A timeout that is read as "not
        // paid" runs the failure hook and tears down an order the customer has paid for.
        $attempt = $this->anAttempt();

        Http::fake(['*/v3/payments/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out')]);

        $result = app(PaymentVerificationService::class)->verify($attempt, 'PAY-1');

        $this->assertTrue($result['unknown']);
        $this->assertNotSame(AttemptStatus::Failed, $result['status']);
        $this->assertSame(0, $this->failureHookCalls());
        $this->assertTrue($attempt->refresh()->statusEnum()->isInFlight());
    }

    public function test_a_rejected_api_key_is_unknown_not_a_failed_payment(): void
    {
        $attempt = $this->anAttempt();

        Http::fake(['*/v3/payments/*' => Http::response(['IsSuccess' => false, 'Message' => 'The token does not have the required permissions!'], 401)]);

        $result = app(PaymentVerificationService::class)->verify($attempt, 'PAY-1');

        $this->assertTrue($result['unknown']);
        $this->assertSame(0, $this->failureHookCalls());
    }

    public function test_a_confirmed_payment_cannot_be_downgraded_by_a_later_notice(): void
    {
        $attempt = $this->anAttempt();

        Http::fake(['*/v3/payments/*' => Http::response($this->paymentDetails($attempt->external_identifier))]);
        app(PaymentVerificationService::class)->verify($attempt, 'PAY-1');
        $this->assertSame(AttemptStatus::Paid->value, $attempt->refresh()->status);

        // A stale FAILED arriving afterwards describes an earlier try at the same invoice.
        Http::fake(['*/v3/payments/*' => Http::response(
            $this->paymentDetails($attempt->external_identifier, invoiceStatus: 'PENDING', transactionStatus: 'FAILED'),
        )]);

        $result = app(PaymentVerificationService::class)->verify($attempt->refresh(), 'PAY-1');

        $this->assertSame(AttemptStatus::Paid, $result['status']);
        $this->assertSame(AttemptStatus::Paid->value, $attempt->refresh()->status);
    }

    public function test_an_attempt_is_verified_against_the_mode_that_created_it(): void
    {
        // Someone flipping the switch to live must not cause a sandbox payment to be checked against
        // the production account, where its id does not exist.
        $repo = $this->configRepo();
        $repo->save(active: true, mode: 'test', secrets: ['api_key' => 'sandbox-key', 'webhook_secret' => 's'], secretsMode: 'test');
        $repo->save(mode: 'test', secrets: ['api_key' => 'live-key', 'webhook_secret' => 's'], secretsMode: 'live');

        $this->fakeCreatePayment();
        $this->initiation()->start(PaymentRequest::find($this->makePaymentRequest()));
        $attempt = MyFatoorahPaymentAttempt::firstOrFail();
        $this->assertSame('test', $attempt->mode);

        // The operator goes live.
        $repo->save(mode: 'live');

        Http::fake(['*/v3/payments/*' => Http::response($this->paymentDetails($attempt->external_identifier))]);
        app(PaymentVerificationService::class)->verify($attempt, 'PAY-1');

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://apitest.myfatoorah.com/')
            && $request->hasHeader('Authorization', 'Bearer sandbox-key'));
    }

    public function test_verification_still_works_after_the_gateway_is_switched_off(): void
    {
        // Disabling the gateway must stop NEW payments without stranding money already taken.
        $attempt = $this->anAttempt();
        $this->configRepo()->save(active: false);

        $this->assertFalse(app(GatewayAvailability::class)->isReady());

        Http::fake(['*/v3/payments/*' => Http::response($this->paymentDetails($attempt->external_identifier))]);
        $result = app(PaymentVerificationService::class)->verify($attempt, 'PAY-1');

        $this->assertSame(AttemptStatus::Paid, $result['status']);

        $settled = app(PaymentSettlementService::class)->settle($result['attempt']);
        $this->assertTrue($settled['settled']);
        $this->assertSame(1, $this->successHookCalls());
    }
}
