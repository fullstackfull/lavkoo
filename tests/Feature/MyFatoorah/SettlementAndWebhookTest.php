<?php

namespace Tests\Feature\MyFatoorah;

use App\Enums\MyFatoorah\AttemptStatus;
use App\Models\MyFatoorahPaymentAttempt;
use App\Models\MyFatoorahWebhookEvent;
use App\Models\PaymentRequest;
use App\Services\MyFatoorah\PaymentInitiationService;
use App\Services\MyFatoorah\PaymentSettlementService;
use App\Services\MyFatoorah\PaymentVerificationService;
use App\Services\MyFatoorah\ReconciliationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * The money-safety properties: a confirmed payment creates exactly one order, however many times, from
 * however many directions, and in whatever order the news arrives.
 *
 * These are the tests this integration exists for. The gateway has four independent ways to learn that
 * a payment succeeded — the customer's redirect, a webhook (retried up to five times), the app polling,
 * and the reconciliation sweep — and MyFatoorah's own documentation warns that two events may arrive
 * for one transaction. Any of those arriving twice must not produce a second order, a second stock
 * deduction or a second shipment.
 */
class SettlementAndWebhookTest extends MyFatoorahTestCase
{
    private string $paymentRequestId;

    private MyFatoorahPaymentAttempt $attempt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureGateway();

        Http::fake(['*/v3/payments' => Http::response([
            'IsSuccess' => true,
            'Data' => ['InvoiceId' => 'INV-1', 'PaymentId' => null, 'PaymentURL' => 'https://sa.myfatoorah.com/KSA/ie/1'],
        ], 201)]);

        $this->paymentRequestId = $this->makePaymentRequest();
        app(PaymentInitiationService::class)->start(PaymentRequest::find($this->paymentRequestId));
        $this->attempt = MyFatoorahPaymentAttempt::firstOrFail();
    }

    /**
     * MyFatoorah answers "paid" for this attempt whenever asked.
     *
     * The invoice lookup is stubbed too, because reconciliation needs it: an attempt nobody ever told
     * us about has no payment id, so the invoice is asked who paid it before the normal
     * payment-details call can run.
     */
    private function fakePaid(string $amount = '150.00'): void
    {
        Http::fake([
            '*/v3/invoices/*' => Http::response([
                'IsSuccess' => true,
                'Data' => ['Transaction' => ['PaymentId' => 'PAY-1', 'Status' => 'SUCCESS']],
            ]),
            '*/v3/payments/*' => Http::response($this->paymentDetails($this->attempt->external_identifier, amount: $amount)),
        ]);
    }

    private function postWebhook(array $payload, ?string $signature = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(
            '/api/myfatoorah/webhook',
            $payload,
            ['MyFatoorah-Signature' => $signature ?? $this->signatureFor($payload)],
        );
    }

    private function orderCount(): int
    {
        return (int) DB::table('orders')->count();
    }

    // ---------------------------------------------------------------- exactly once

    public function test_a_confirmed_payment_creates_exactly_one_order(): void
    {
        $this->fakePaid();

        $verified = app(PaymentVerificationService::class)->verify($this->attempt, 'PAY-1');
        $result = app(PaymentSettlementService::class)->settle($verified['attempt']);

        $this->assertTrue($result['settled']);
        $this->assertSame(1, $this->successHookCalls());
        $this->assertSame(1, $this->orderCount());

        // The shared ledger is what the rest of the application reads.
        $paymentRequest = PaymentRequest::find($this->paymentRequestId);
        $this->assertSame(1, (int) $paymentRequest->is_paid);
        $this->assertSame('PAY-1', $paymentRequest->transaction_id);
        $this->assertSame('fatoorah', $paymentRequest->payment_method);

        $this->assertNotNull($this->attempt->refresh()->settled_at);
        $this->assertNotNull($this->attempt->hook_completed_at);
    }

    public function test_settling_the_same_payment_five_times_creates_one_order(): void
    {
        $this->fakePaid();
        $verified = app(PaymentVerificationService::class)->verify($this->attempt, 'PAY-1');

        for ($i = 0; $i < 5; $i++) {
            app(PaymentSettlementService::class)->settle($verified['attempt']->refresh());
        }

        $this->assertSame(1, $this->successHookCalls());
        $this->assertSame(1, $this->orderCount());
    }

    public function test_an_unverified_attempt_cannot_be_settled(): void
    {
        // The only route to Paid is PaymentVerificationService. Anything else must be refused, or a
        // forged callback would be enough to release an order.
        $result = app(PaymentSettlementService::class)->settle($this->attempt);

        $this->assertFalse($result['settled']);
        $this->assertSame(0, $this->successHookCalls());
        $this->assertSame(0, (int) PaymentRequest::find($this->paymentRequestId)->is_paid);
    }

    public function test_an_attempt_cannot_settle_a_payment_request_another_attempt_already_paid(): void
    {
        // Two invoices exist for one order (the customer retried) and both get paid. The second must
        // not create a second order; it must be flagged as a possible double charge.
        $this->fakePaid();
        $verified = app(PaymentVerificationService::class)->verify($this->attempt, 'PAY-1');
        app(PaymentSettlementService::class)->settle($verified['attempt']);
        $this->assertSame(1, $this->orderCount());

        $second = MyFatoorahPaymentAttempt::create([
            'payment_request_id' => $this->paymentRequestId,
            'attempt_number' => 2,
            'external_identifier' => 'pk-second-attempt-2',
            'expected_amount' => '150.00',
            'expected_currency' => 'SAR',
            'status' => AttemptStatus::Paid->value,
            'mode' => 'test',
            'payment_id' => 'PAY-2',
            'captured_amount' => '150.00',
            'captured_currency' => 'SAR',
            'verified_at' => now(),
        ]);

        $result = app(PaymentSettlementService::class)->settle($second);

        $this->assertFalse($result['settled']);
        $this->assertSame(1, $this->successHookCalls());
        $this->assertSame(1, $this->orderCount());
        // Money arrived twice: that is a refund decision for a human, not something to discard.
        $this->assertSame(AttemptStatus::Mismatch->value, $second->refresh()->status);
        $this->assertSame('already_paid_elsewhere', $second->last_error_code);
    }

    // ---------------------------------------------------------------- interrupted settlement

    public function test_a_settlement_interrupted_after_the_money_was_recorded_is_recoverable(): void
    {
        // The worst state available: the payment is marked paid and the process died before the order
        // was created. It has to be findable and finishable, or the customer has paid for nothing.
        $this->fakePaid();
        $verified = app(PaymentVerificationService::class)->verify($this->attempt, 'PAY-1');

        $GLOBALS['myfatoorah_success_should_throw'] = true;
        $result = app(PaymentSettlementService::class)->settle($verified['attempt']);

        $this->assertFalse($result['settled']);
        $this->assertSame(0, $this->orderCount());

        $stuck = $this->attempt->refresh();
        $this->assertNotNull($stuck->settled_at, 'the money is recorded');
        $this->assertNull($stuck->hook_completed_at, 'but the order was never created');
        $this->assertNotNull($stuck->hook_error);

        // The sweep reports it as needing a person.
        $this->assertSame(1, app(ReconciliationService::class)->needsAttention()['stuck']);

        // The lease is released on failure, so recovery can take it straight away.
        $this->assertNull($stuck->hook_started_at);

        // And recovery finishes it once the underlying problem is gone.
        $GLOBALS['myfatoorah_success_should_throw'] = false;
        $recovered = app(PaymentSettlementService::class)->recover();

        $this->assertSame(1, $recovered['recovered']);
        $this->assertSame(1, $this->orderCount());
        $this->assertNotNull($this->attempt->refresh()->hook_completed_at);
    }

    public function test_a_second_caller_arriving_mid_hook_does_not_run_the_hook_as_well(): void
    {
        /*
         * The race `settled_at` alone did not close.
         *
         * `settled_at` guarantees one caller marks the payment paid. It does not stop a SECOND caller
         * running the success hook while the first is still inside it — and the "an order already
         * carries this reference" check cannot help there, because the first caller has not created
         * that order yet. The hook does a lot before it does (stock, courier), so the window is wide.
         *
         * Reproduced by settling from inside the hook itself: at that moment the first settlement is
         * mid-flight, exactly as a concurrent delivery would find it.
         */
        $this->fakePaid();
        $verified = app(PaymentVerificationService::class)->verify($this->attempt, 'PAY-1');

        $attempt = $verified['attempt'];
        $reentered = null;

        $GLOBALS['myfatoorah_success_reentry'] = function () use ($attempt, &$reentered): void {
            // A webhook landing while the first caller is between "marked paid" and "order created".
            $reentered = app(PaymentSettlementService::class)->settle($attempt->fresh());
        };

        $result = app(PaymentSettlementService::class)->settle($attempt);

        unset($GLOBALS['myfatoorah_success_reentry']);

        $this->assertTrue($result['settled']);
        $this->assertNotNull($reentered, 'the re-entrant settle really ran');
        $this->assertFalse($reentered['settled'], 'the second caller must not run the hook');

        $this->assertSame(1, $this->successHookCalls(), 'the hook ran once');
        $this->assertSame(1, $this->orderCount(), 'one order');
    }

    public function test_a_hook_lease_left_behind_by_a_killed_process_does_not_block_recovery_for_ever(): void
    {
        // The other half of the lease: a process killed mid-hook must not hold the order hostage.
        $this->fakePaid();
        $verified = app(PaymentVerificationService::class)->verify($this->attempt, 'PAY-1');

        $GLOBALS['myfatoorah_success_should_throw'] = true;
        app(PaymentSettlementService::class)->settle($verified['attempt']);
        $GLOBALS['myfatoorah_success_should_throw'] = false;

        // Simulate a process that died without releasing the lease, long enough ago to be stale.
        $this->attempt->refresh()->forceFill(['hook_started_at' => now()->subHours(2)])->save();

        $recovered = app(PaymentSettlementService::class)->recover();

        $this->assertSame(1, $recovered['recovered']);
        $this->assertSame(1, $this->orderCount());
        $this->assertNull($this->attempt->refresh()->hook_started_at, 'the lease is released');
    }

    public function test_recovery_does_not_create_a_second_order_when_the_first_one_already_exists(): void
    {
        // The dangerous half-failure: the hook created the order and THEN threw. Re-running it must not
        // create another. The check is made against the order table, not against a flag, which is why
        // it survives a process that died before it could record anything.
        $this->fakePaid();
        $verified = app(PaymentVerificationService::class)->verify($this->attempt, 'PAY-1');
        app(PaymentSettlementService::class)->settle($verified['attempt']);

        $this->assertSame(1, $this->orderCount());

        // Put it back into the interrupted state, as if the recording had been lost.
        $this->attempt->refresh()->forceFill([
            'hook_completed_at' => null,
            'hook_started_at' => null,
            'hook_error' => 'pretend crash',
        ])->save();

        $recovered = app(PaymentSettlementService::class)->recover();

        $this->assertSame(1, $recovered['recovered']);
        $this->assertSame(1, $this->orderCount(), 'no second order');
        $this->assertSame(1, $this->successHookCalls(), 'the hook was not run again');
    }

    public function test_recovery_gives_up_rather_than_retrying_a_hook_forever(): void
    {
        $this->fakePaid();
        $verified = app(PaymentVerificationService::class)->verify($this->attempt, 'PAY-1');

        $GLOBALS['myfatoorah_success_should_throw'] = true;
        app(PaymentSettlementService::class)->settle($verified['attempt']);

        for ($i = 0; $i < 8; $i++) {
            app(PaymentSettlementService::class)->recover(maxHookAttempts: 3);
        }

        // Capped: repeating a hook that keeps throwing cannot fix it and each run risks partial side
        // effects, so it is left for an operator instead.
        $this->assertLessThanOrEqual(3, (int) $this->attempt->refresh()->hook_attempts);
        $this->assertSame(0, $this->orderCount());
    }

    // ---------------------------------------------------------------- webhooks

    public function test_a_webhook_confirms_and_settles_a_payment(): void
    {
        $this->fakePaid();

        $response = $this->postWebhook($this->webhookPayload($this->attempt->external_identifier));

        $response->assertOk()->assertJson(['success' => true, 'status' => 'settled']);
        $this->assertSame(1, $this->successHookCalls());
        $this->assertSame(1, $this->orderCount());
        $this->assertSame(AttemptStatus::Paid->value, $this->attempt->refresh()->status);
    }

    public function test_the_same_webhook_ten_times_creates_one_order(): void
    {
        // MyFatoorah retries up to five times and may send two events for one transaction. This is the
        // property the unique dedupe key exists to guarantee.
        $this->fakePaid();
        $payload = $this->webhookPayload($this->attempt->external_identifier);
        $signature = $this->signatureFor($payload);

        for ($i = 0; $i < 10; $i++) {
            $this->postWebhook($payload, $signature)->assertOk();
        }

        $this->assertSame(1, $this->successHookCalls());
        $this->assertSame(1, $this->orderCount());
        $this->assertSame(1, MyFatoorahWebhookEvent::count(), 'nine deliveries were recognised as repeats');
    }

    public function test_a_webhook_arriving_before_the_customer_returns_settles_once_and_the_redirect_agrees(): void
    {
        $this->fakePaid();

        $this->postWebhook($this->webhookPayload($this->attempt->external_identifier))->assertOk();
        $this->assertSame(1, $this->orderCount());

        // Now the customer finally gets back. They must see success, and nothing must happen twice.
        $this->get('/payment/myfatoorah/callback?reference=' . $this->attempt->external_identifier . '&paymentId=PAY-1')
            ->assertRedirect();

        $this->assertSame(1, $this->successHookCalls());
        $this->assertSame(1, $this->orderCount());
    }

    public function test_a_webhook_arriving_after_the_customer_returns_changes_nothing(): void
    {
        $this->fakePaid();

        $this->get('/payment/myfatoorah/callback?reference=' . $this->attempt->external_identifier . '&paymentId=PAY-1')
            ->assertRedirect();
        $this->assertSame(1, $this->orderCount());

        $this->postWebhook($this->webhookPayload($this->attempt->external_identifier))
            ->assertOk()
            ->assertJson(['status' => 'already_settled']);

        $this->assertSame(1, $this->successHookCalls());
        $this->assertSame(1, $this->orderCount());
    }

    public function test_a_stale_failure_webhook_does_not_undo_a_confirmed_payment(): void
    {
        $this->fakePaid();
        $this->postWebhook($this->webhookPayload($this->attempt->external_identifier))->assertOk();
        $this->assertSame(AttemptStatus::Paid->value, $this->attempt->refresh()->status);

        // A late FAILED for the same invoice describes an earlier try. MyFatoorah's own guidance: if any
        // event for a transaction says SUCCESS, that is the answer.
        $stale = $this->webhookPayload(
            $this->attempt->external_identifier,
            invoiceStatus: 'PENDING',
            transactionStatus: 'FAILED',
            eventReference: 'EV-STALE',
        );

        $this->postWebhook($stale)->assertOk()->assertJson(['status' => 'already_settled']);

        $this->assertSame(AttemptStatus::Paid->value, $this->attempt->refresh()->status);
        $this->assertSame(1, (int) PaymentRequest::find($this->paymentRequestId)->is_paid);
        $this->assertSame(1, $this->orderCount());
        $this->assertSame(0, $this->failureHookCalls());
    }

    public function test_a_webhook_with_no_signature_is_refused(): void
    {
        $this->fakePaid();

        $this->postJson('/api/myfatoorah/webhook', $this->webhookPayload($this->attempt->external_identifier))
            ->assertStatus(401);

        $this->assertSame(0, $this->successHookCalls());
        $this->assertSame(0, MyFatoorahWebhookEvent::count());
    }

    public function test_a_webhook_with_a_wrong_signature_is_refused(): void
    {
        $this->fakePaid();

        $this->postWebhook($this->webhookPayload($this->attempt->external_identifier), 'not-the-right-signature')
            ->assertStatus(401);

        $this->assertSame(0, $this->successHookCalls());
        $this->assertSame(0, $this->orderCount());
    }

    public function test_a_tampered_webhook_is_refused(): void
    {
        // Signed for one payload, delivered with another: the classic replay-with-edits attack. The
        // amount is not even in the signed set, which is exactly why a verified webhook still only
        // triggers a server-to-server read.
        $this->fakePaid();

        $genuine = $this->webhookPayload($this->attempt->external_identifier);
        $signature = $this->signatureFor($genuine);

        $tampered = $genuine;
        $tampered['Data']['Invoice']['ExternalIdentifier'] = 'pk-someone-elses-order-1';

        $this->postWebhook($tampered, $signature)->assertStatus(401);

        $this->assertSame(0, $this->successHookCalls());
    }

    public function test_a_webhook_for_an_unknown_payment_is_accepted_and_ignored(): void
    {
        // Another installation may share the merchant account. Answering 200 stops pointless retries;
        // doing nothing is the correct response.
        $payload = $this->webhookPayload('pk-not-ours-1', paymentId: 'PAY-THEIRS', invoiceId: 'INV-THEIRS');

        $this->postWebhook($payload)->assertOk()->assertJson(['status' => 'no_attempt']);

        $this->assertSame(0, $this->successHookCalls());
        $this->assertSame(0, $this->orderCount());
    }

    public function test_a_webhook_naming_an_unknown_reference_is_not_matched_by_its_invoice_id(): void
    {
        // The reference is ours and is the authoritative link. One that matches nothing means the event
        // is not about a payment of ours, even when its invoice id happens to collide with one of
        // ours — which is exactly how the wrong order would get settled.
        $this->fakePaid();

        $payload = $this->webhookPayload('pk-not-ours-1', invoiceId: (string) $this->attempt->invoice_id);

        $this->postWebhook($payload)->assertOk()->assertJson(['status' => 'no_attempt']);

        $this->assertSame(0, $this->successHookCalls());
        $this->assertSame(0, $this->orderCount());
        $this->assertSame(AttemptStatus::Redirected->value, $this->attempt->refresh()->status);
    }

    public function test_a_webhook_whose_payment_cannot_be_confirmed_does_not_settle_or_fail_it(): void
    {
        // The signature is genuine but MyFatoorah is unreachable for the verifying read. Neither hook
        // may run: this is the case where a network blip would otherwise cancel a paid order.
        Http::fake(['*/v3/payments/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);

        $this->postWebhook($this->webhookPayload($this->attempt->external_identifier))
            ->assertOk()
            ->assertJson(['status' => 'pending']);

        $this->assertSame(0, $this->successHookCalls());
        $this->assertSame(0, $this->failureHookCalls());
        $this->assertTrue($this->attempt->refresh()->statusEnum()->isInFlight());
    }

    public function test_a_webhook_that_reports_a_different_amount_is_not_settled(): void
    {
        // MyFatoorah says paid, but for the wrong money. Flagged, never settled.
        $this->fakePaid(amount: '5.00');

        $this->postWebhook($this->webhookPayload($this->attempt->external_identifier))
            ->assertOk()
            ->assertJson(['status' => 'mismatch']);

        $this->assertSame(0, $this->successHookCalls());
        $this->assertSame(0, $this->orderCount());
        $this->assertSame(AttemptStatus::Mismatch->value, $this->attempt->refresh()->status);
        $this->assertSame(1, app(ReconciliationService::class)->needsAttention()['mismatched']);
    }

    public function test_the_webhook_body_is_not_stored(): void
    {
        // A payload carries a cardholder name and a masked card number, and there is no reason to keep
        // either. A digest proves two deliveries were identical without retaining their contents.
        $this->fakePaid();
        $payload = $this->webhookPayload($this->attempt->external_identifier);

        $this->postWebhook($payload)->assertOk();

        $event = MyFatoorahWebhookEvent::firstOrFail();
        $stored = json_encode($event->getAttributes());

        $this->assertStringNotContainsString('TEST CARD', (string) $stored);
        $this->assertStringNotContainsString('440647', (string) $stored);
        $this->assertSame(64, strlen((string) $event->payload_digest));
    }

    public function test_an_oversized_webhook_body_is_refused(): void
    {
        config(['myfatoorah.webhook.max_body_bytes' => 64]);
        $this->fakePaid();

        $this->postWebhook($this->webhookPayload($this->attempt->external_identifier))->assertStatus(413);
    }

    // ---------------------------------------------------------------- reconciliation

    public function test_reconciliation_recovers_a_payment_nothing_told_us_about(): void
    {
        // Neither the redirect nor the webhook arrived — the customer closed the tab and the webhook
        // was given up on. Without the sweep this customer is charged and has no order.
        $this->fakePaid();

        $tally = app(ReconciliationService::class)->sweep();

        $this->assertSame(1, $tally['checked']);
        $this->assertSame(1, $tally['settled']);
        $this->assertSame(1, $this->successHookCalls());
        $this->assertSame(1, $this->orderCount());
    }

    public function test_reconciliation_after_a_webhook_already_settled_does_nothing_more(): void
    {
        $this->fakePaid();
        $this->postWebhook($this->webhookPayload($this->attempt->external_identifier))->assertOk();

        app(ReconciliationService::class)->sweep();

        $this->assertSame(1, $this->successHookCalls());
        $this->assertSame(1, $this->orderCount());
    }

    public function test_reconciliation_leaves_a_pending_payment_alone(): void
    {
        Http::fake([
            '*/v3/invoices/*' => Http::response([
                'IsSuccess' => true,
                'Data' => ['Transaction' => ['PaymentId' => 'PAY-1', 'Status' => 'INPROGRESS']],
            ]),
            '*/v3/payments/*' => Http::response(
                $this->paymentDetails($this->attempt->external_identifier, invoiceStatus: 'PENDING', transactionStatus: 'INPROGRESS'),
            ),
        ]);

        $tally = app(ReconciliationService::class)->sweep();

        $this->assertSame(1, $tally['pending']);
        $this->assertSame(0, $this->successHookCalls());
        $this->assertSame(0, $this->failureHookCalls());
    }

    public function test_reconciliation_recovers_an_attempt_it_had_no_payment_id_for(): void
    {
        // The case the sweep exists for: neither the redirect nor the webhook ever arrived, so nothing
        // told us MyFatoorah's id for the money. The invoice we created is the only thread to pull.
        $this->assertNull($this->attempt->payment_id);

        $this->fakePaid();

        $tally = app(ReconciliationService::class)->sweep();

        $this->assertSame(1, $tally['settled']);
        $this->assertSame('PAY-1', $this->attempt->refresh()->payment_id, 'the id is remembered for later sweeps');
        $this->assertSame(1, $this->orderCount());

        // The id was only used to look the payment up; the real verification still ran.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v3/payments/PAY-1'));
    }

    public function test_an_unavailable_invoice_lookup_leaves_the_payment_for_a_person(): void
    {
        // The invoice endpoints are not among the paths MyFatoorah documents literally. If an account
        // does not answer them, unattended recovery of this case cannot work — and that has to be
        // visible rather than looking like "nothing to do".
        Http::fake([
            '*/v3/invoices/*' => Http::response(['IsSuccess' => false, 'Message' => 'Not Found'], 404),
            '*/v3/payments/*' => Http::response($this->paymentDetails($this->attempt->external_identifier)),
        ]);

        $tally = app(ReconciliationService::class)->sweep();

        $this->assertSame(1, $tally['checked']);
        $this->assertSame(0, $tally['settled']);
        $this->assertSame(1, $tally['unknown']);

        // Nothing was invented, nothing was settled, and the reason is recorded.
        $this->assertSame(0, $this->orderCount());
        $this->assertSame('no_payment_id', $this->attempt->refresh()->last_error_code);
        $this->assertTrue($this->attempt->statusEnum()->isInFlight());
    }

    public function test_reconciliation_does_nothing_while_the_kill_switch_is_off_and_says_so(): void
    {
        config(['myfatoorah.enabled' => false, 'myfatoorah.verify_when_disabled' => false]);

        $tally = app(ReconciliationService::class)->sweep();

        $this->assertNotNull($tally['skipped']);
        $this->assertSame(0, $tally['checked']);
    }

    public function test_the_reconcile_command_runs_and_reports(): void
    {
        $this->fakePaid();

        $this->artisan('myfatoorah:reconcile')->assertSuccessful();

        $this->assertSame(1, $this->orderCount());
    }

    // ---------------------------------------------------------------- the browser callback

    public function test_the_callback_does_not_trust_the_url_it_was_given(): void
    {
        // The callback carries no status and nothing signed. It is a browser navigation, so anyone can
        // type it; it may only ever prompt a server-to-server check.
        Http::fake(['*/v3/payments/*' => Http::response(
            $this->paymentDetails($this->attempt->external_identifier, invoiceStatus: 'PENDING', transactionStatus: 'INPROGRESS'),
        )]);

        $response = $this->get('/payment/myfatoorah/callback?reference=' . $this->attempt->external_identifier . '&paymentId=PAY-1');

        // Pending, so neither success nor failure is claimed.
        $response->assertRedirect(route('myfatoorah.pending', ['reference' => $this->attempt->external_identifier]));
        $this->assertSame(0, $this->successHookCalls());
        $this->assertSame(0, $this->failureHookCalls());
    }

    public function test_a_callback_with_an_unknown_reference_is_rejected(): void
    {
        $this->get('/payment/myfatoorah/callback?reference=pk-made-up-1&paymentId=PAY-9')
            ->assertRedirect(route('payment-fail'));

        $this->assertSame(0, $this->successHookCalls());
    }

    public function test_a_definite_failure_at_the_callback_runs_the_failure_hook_once(): void
    {
        Http::fake(['*/v3/payments/*' => Http::response(
            $this->paymentDetails($this->attempt->external_identifier, invoiceStatus: 'PENDING', transactionStatus: 'FAILED'),
        )]);

        $this->get('/payment/myfatoorah/callback?reference=' . $this->attempt->external_identifier . '&paymentId=PAY-1')
            ->assertRedirect();

        $this->assertSame(1, $this->failureHookCalls());
        $this->assertSame(0, $this->successHookCalls());
        $this->assertSame(0, (int) PaymentRequest::find($this->paymentRequestId)->is_paid);
    }

    public function test_a_stale_failure_callback_cannot_tear_down_a_paid_order(): void
    {
        // Attempt 1 failed, attempt 2 paid. A late callback for attempt 1 must not run the failure hook
        // against an order that has been paid for.
        $this->fakePaid();
        $verified = app(PaymentVerificationService::class)->verify($this->attempt, 'PAY-1');
        app(PaymentSettlementService::class)->settle($verified['attempt']);
        $this->assertSame(1, (int) PaymentRequest::find($this->paymentRequestId)->is_paid);

        $failed = MyFatoorahPaymentAttempt::create([
            'payment_request_id' => $this->paymentRequestId,
            'attempt_number' => 2,
            'external_identifier' => 'pk-earlier-try-2',
            'expected_amount' => '150.00',
            'expected_currency' => 'SAR',
            'status' => AttemptStatus::Redirected->value,
            'mode' => 'test',
        ]);

        // One routing stub, not a second Http::fake() array. Laravel MERGES stub sets and the first
        // match wins, so re-registering the payment-details pattern here would leave the earlier
        // "attempt 1 is PAID" stub answering attempt 2's call — and the test would be exercising a
        // reference mismatch instead of the stale failure it is about.
        //
        // (Line comments, not a block comment: a URL pattern ending in a star followed by a slash
        // would close a block comment early.)
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/v3/payments/PAY-OLD')) {
                return Http::response(
                    $this->paymentDetails('pk-earlier-try-2', invoiceStatus: 'PENDING', transactionStatus: 'FAILED'),
                );
            }

            return Http::response($this->paymentDetails($this->attempt->external_identifier));
        });

        $this->get('/payment/myfatoorah/callback?reference=' . $failed->external_identifier . '&paymentId=PAY-OLD')
            ->assertRedirect();

        $this->assertSame(0, $this->failureHookCalls(), 'the failure hook is not run for an order that is paid');
        $this->assertSame(1, (int) PaymentRequest::find($this->paymentRequestId)->is_paid);
        $this->assertSame(1, $this->orderCount());
    }

    // ---------------------------------------------------------------- the app's status endpoint

    public function test_the_app_status_endpoint_reports_paid_only_once_the_order_exists(): void
    {
        $this->fakePaid();

        $response = $this->getJson('/api/v1/payment/myfatoorah/status?reference=' . $this->attempt->external_identifier);

        $response->assertOk()->assertJson([
            'status' => 'paid',
            'paid' => true,
            'is_final' => true,
            'currency' => 'SAR',
        ]);

        $this->assertSame(1, $this->orderCount());
        $this->assertNotEmpty($response->json('order_ids'));
    }

    public function test_the_app_status_endpoint_reports_pending_rather_than_failed_when_unknown(): void
    {
        // The whole reason this endpoint exists: the app must never show "payment failed" to someone
        // whose money may have moved.
        Http::fake(['*/v3/payments/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);

        $this->getJson('/api/v1/payment/myfatoorah/status?reference=' . $this->attempt->external_identifier)
            ->assertOk()
            ->assertJson(['status' => 'pending', 'paid' => false, 'is_final' => false]);
    }

    public function test_the_app_status_endpoint_reports_a_definite_failure_as_final(): void
    {
        Http::fake([
            '*/v3/invoices/*' => Http::response([
                'IsSuccess' => true,
                'Data' => ['Transaction' => ['PaymentId' => 'PAY-1', 'Status' => 'FAILED']],
            ]),
            '*/v3/payments/*' => Http::response(
                $this->paymentDetails($this->attempt->external_identifier, invoiceStatus: 'PENDING', transactionStatus: 'FAILED'),
            ),
        ]);

        $this->getJson('/api/v1/payment/myfatoorah/status?reference=' . $this->attempt->external_identifier)
            ->assertOk()
            ->assertJson(['status' => 'failed', 'paid' => false, 'is_final' => true]);
    }

    public function test_the_app_status_endpoint_never_leaks_a_secret_or_card_data(): void
    {
        $this->fakePaid();

        $body = (string) $this->getJson('/api/v1/payment/myfatoorah/status?reference=' . $this->attempt->external_identifier)
            ->getContent();

        foreach (['test-api-key', 'test-webhook-secret', 'TEST CARD', '440647', 'apitest.myfatoorah.com'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }

    public function test_the_status_endpoint_does_not_ask_myfatoorah_on_every_poll(): void
    {
        // The endpoint needs no login, so without a floor a flood of requests for one reference becomes
        // the same flood at MyFatoorah. Inside the floor the stored status is returned, which is the
        // right answer anyway — a webhook may have just updated it.
        config(['myfatoorah.min_seconds_between_status_checks' => 60]);

        // A payment id is already known, as it is once the callback or a webhook has been seen. Keeps
        // this test about the floor rather than about the invoice lookup.
        $this->attempt->forceFill(['payment_id' => 'PAY-1'])->save();

        Http::fake(['*/v3/payments/*' => Http::response(
            $this->paymentDetails($this->attempt->external_identifier, invoiceStatus: 'PENDING', transactionStatus: 'INPROGRESS'),
        )]);

        $url = '/api/v1/payment/myfatoorah/status?reference=' . $this->attempt->external_identifier;

        // The first poll always goes through: a customer who has just returned is never made to wait.
        $this->getJson($url)->assertOk();
        $afterFirst = Http::recorded()->count();
        $this->assertGreaterThan(0, $afterFirst, 'the first poll asks MyFatoorah');

        // The next few are answered from what we already know.
        $this->getJson($url)->assertOk()->assertJson(['status' => 'pending']);
        $this->getJson($url)->assertOk()->assertJson(['status' => 'pending']);

        $this->assertSame($afterFirst, Http::recorded()->count(), 'no further provider calls');
    }

    public function test_the_floor_does_not_stop_a_resolved_payment_being_reported(): void
    {
        // The floor must only skip the provider READ. A payment that has since been confirmed by a
        // webhook is still reported as paid immediately.
        config(['myfatoorah.min_seconds_between_status_checks' => 3600]);

        $this->fakePaid();
        $this->postWebhook($this->webhookPayload($this->attempt->external_identifier))->assertOk();

        $this->getJson('/api/v1/payment/myfatoorah/status?reference=' . $this->attempt->external_identifier)
            ->assertOk()
            ->assertJson(['status' => 'paid', 'paid' => true, 'is_final' => true]);
    }

    public function test_an_unknown_reference_is_a_flat_not_found(): void
    {
        // Same answer as "belongs to someone else", so the endpoint cannot be used to probe for valid
        // references.
        $this->getJson('/api/v1/payment/myfatoorah/status?reference=pk-made-up-1')->assertStatus(404);
    }

    public function test_a_customer_cannot_read_another_customers_payment(): void
    {
        $otherRequestId = $this->makePaymentRequest(['payer_id' => '999', 'additional_data' => json_encode(['customer_id' => 999])]);

        $otherAttempt = MyFatoorahPaymentAttempt::create([
            'payment_request_id' => $otherRequestId,
            'attempt_number' => 1,
            'external_identifier' => 'pk-someone-else-1',
            'expected_amount' => '99.00',
            'expected_currency' => 'SAR',
            'status' => AttemptStatus::Paid->value,
            'mode' => 'test',
        ]);

        $customer = new \App\Models\User();
        $customer->id = 7;

        // The `customer` session guard, not Passport's `api` guard: building that one needs OAuth keys
        // this test environment has no reason to hold. The controller resolves either, and treats a
        // guard it cannot build as "nobody is signed in" rather than failing the request.
        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/payment/myfatoorah/status?reference=' . $otherAttempt->external_identifier)
            ->assertStatus(404);
    }
}
