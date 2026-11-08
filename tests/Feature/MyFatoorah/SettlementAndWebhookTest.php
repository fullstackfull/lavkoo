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
        // Not just "the hook ran once": each of its side effects happened once. A hook that ran twice
        // and stopped early the second time would still leave a duplicate stock movement or shipment.
        $this->assertAppliedExactlyOnce();

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
        $this->assertAppliedExactlyOnce();
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

    public function test_a_hook_that_began_is_never_re_run_unattended_however_stale_its_lease(): void
    {
        /*
         * The fencing token stops a run that outlived its lease from writing a stale result. It cannot
         * stop its side effects from having happened: by the time the lease expired the first process
         * may have taken the stock and be on its way to the order and the shipment. Re-running then
         * does that work twice, and the order-already-exists check only helps once the order exists.
         *
         * Nothing here can tell a dead process from a slow one, so a hook that has started is never
         * re-run unattended. It becomes a human-review item instead.
         */
        $this->fakePaid();
        $verified = app(PaymentVerificationService::class)->verify($this->attempt, 'PAY-1');

        // Dies after taking the stock, before the order exists — the worst moment to retry blindly.
        $GLOBALS['myfatoorah_success_throw_after_stock'] = true;
        app(PaymentSettlementService::class)->settle($verified['attempt']);
        $GLOBALS['myfatoorah_success_throw_after_stock'] = false;

        $this->assertSame(1, $this->effectCount('stock_reservation'), 'the stock was taken once');
        $this->assertSame(0, $this->effectCount('order'));
        $this->assertSame(0, $this->effectCount('shipment'));

        // A process that died without releasing its lease, long enough ago to look stale.
        $this->attempt->refresh()->forceFill(['hook_started_at' => now()->subHours(2)])->save();

        $recovered = app(PaymentSettlementService::class)->recover();

        $this->assertSame(0, $recovered['recovered'], 'recovery does not touch it');
        $this->assertSame(1, $this->effectCount('stock_reservation'), 'and the stock was not taken twice');
        $this->assertSame(0, $this->effectCount('order'));
        $this->assertSame(0, $this->effectCount('shipment'));

        // It is reported, with the stronger flag that says single execution cannot be established.
        $attention = app(ReconciliationService::class)->needsAttention();
        $this->assertSame(1, $attention['stuck']);
        $this->assertSame(1, $attention['hook_unverifiable']);
    }

    public function test_an_operator_can_force_a_re_run_once_they_have_checked_the_damage(): void
    {
        // The only route past that rule, and deliberately a manual one.
        $this->fakePaid();
        $verified = app(PaymentVerificationService::class)->verify($this->attempt, 'PAY-1');

        $GLOBALS['myfatoorah_success_throw_after_stock'] = true;
        app(PaymentSettlementService::class)->settle($verified['attempt']);
        $GLOBALS['myfatoorah_success_throw_after_stock'] = false;

        $this->attempt->refresh()->forceFill(['hook_started_at' => now()->subHours(2)])->save();

        $outcome = app(PaymentSettlementService::class)->forceRerun($this->attempt->refresh(), operatorId: 3);

        $this->assertTrue($outcome['ran']);
        $this->assertSame(1, $this->effectCount('order'));
        $this->assertSame(1, $this->effectCount('shipment'));
        $this->assertNotNull($this->attempt->refresh()->hook_completed_at);
    }

    public function test_a_wallet_top_up_whose_hook_began_is_never_credited_again(): void
    {
        /*
         * The wallet is the case with no idempotency marker at all: the real
         * `add_fund_to_wallet_success` credits a balance and leaves nothing to look the credit up by.
         * So a top-up whose hook began can never be retried automatically, however stale its lease.
         */
        $walletRequestId = $this->makePaymentRequest([
            'attribute' => 'add_fund_to_wallet',
            'payment_amount' => 50.00,
        ]);

        $walletAttempt = MyFatoorahPaymentAttempt::create([
            'payment_request_id' => $walletRequestId,
            'attempt_number' => 1,
            'external_identifier' => 'pk-wallet-1',
            'expected_amount' => '50.00',
            'expected_currency' => 'SAR',
            'captured_amount' => '50.00',
            'captured_currency' => 'SAR',
            'status' => AttemptStatus::Paid->value,
            'mode' => 'test',
            'payment_id' => 'PAY-W1',
            'verified_at' => now(),
            'settled_at' => now()->subHour(),
            // Its hook began and never finished.
            'hook_started_at' => now()->subHour(),
        ]);

        $recovered = app(PaymentSettlementService::class)->recover();

        $this->assertSame(0, $recovered['recovered']);
        $this->assertSame(0, $this->effectCount('wallet_credit'), 'the wallet was not credited again');
        $this->assertNull($walletAttempt->refresh()->hook_completed_at);
        $this->assertSame(1, app(ReconciliationService::class)->needsAttention()['hook_unverifiable']);
    }

    public function test_a_settlement_that_never_started_its_hook_is_still_recovered_automatically(): void
    {
        // The safe half. A process that died between marking the payment paid and starting the hook
        // left the hook definitely un-run, so finishing it duplicates nothing.
        $this->fakePaid();
        $verified = app(PaymentVerificationService::class)->verify($this->attempt, 'PAY-1');
        $attempt = $verified['attempt'];

        // Mark paid exactly as settle() does, then stop before the hook.
        DB::table('payment_requests')->where('id', $this->paymentRequestId)
            ->update(['is_paid' => 1, 'transaction_id' => 'PAY-1', 'payment_method' => 'fatoorah']);
        $attempt->forceFill(['settled_at' => now()->subMinutes(5), 'hook_started_at' => null])->save();

        $recovered = app(PaymentSettlementService::class)->recover();

        $this->assertSame(1, $recovered['recovered']);
        $this->assertAppliedExactlyOnce();
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

    public function test_a_hook_that_outran_its_lease_is_not_reported_as_a_clean_success(): void
    {
        /*
         * The lease stops two processes STARTING the hook together. It does not stop one from expiring
         * while its holder is still working — a hook that creates an order, decrements stock and books a
         * shipment can outlive the window on a slow day. The fencing token makes that detectable: a
         * process that finds someone else's token knows it lost the lease mid-flight, and must not
         * overwrite the winner's result or claim everything went fine.
         */
        $this->fakePaid();
        $verified = app(PaymentVerificationService::class)->verify($this->attempt, 'PAY-1');
        $attempt = $verified['attempt'];

        // Someone else takes the lease while this hook is running.
        $GLOBALS['myfatoorah_success_reentry'] = function () use ($attempt): void {
            DB::table('myfatoorah_payment_attempts')
                ->where('id', $attempt->getKey())
                ->update(['hook_lease_token' => 'taken-by-someone-else', 'hook_started_at' => now()]);
        };

        $result = app(PaymentSettlementService::class)->settle($attempt);

        unset($GLOBALS['myfatoorah_success_reentry']);

        $this->assertFalse($result['settled'], 'not reported as a clean settlement');
        $this->assertFalse($result['already'], 'nor as something already done');
        $this->assertStringContainsString('lost its lease', $result['reason']);
        $this->assertSame(1, $this->successHookCalls(), 'the hook itself did run');

        // Its own completion was not written over the other holder's state.
        $this->assertNull($attempt->refresh()->hook_completed_at);
    }

    public function test_recovery_will_not_retry_a_wallet_top_up_hook(): void
    {
        /*
         * An order hook is safe to retry: an order carrying the payment's reference proves it already
         * ran. A wallet top-up is not — `add_fund_to_wallet_success` credits the balance with no
         * reference to look for, so a retry that cannot tell whether the first run got that far would
         * credit it twice. Stuck and visible beats silently credited twice.
         */
        $walletRequestId = $this->makePaymentRequest([
            'attribute' => 'add_fund_to_wallet',
            'success_hook' => 'myfatoorah_test_success_hook',
        ]);

        $walletAttempt = MyFatoorahPaymentAttempt::create([
            'payment_request_id' => $walletRequestId,
            'attempt_number' => 1,
            'external_identifier' => 'pk-wallet-1',
            'expected_amount' => '50.00',
            'expected_currency' => 'SAR',
            'captured_amount' => '50.00',
            'captured_currency' => 'SAR',
            'status' => AttemptStatus::Paid->value,
            'mode' => 'test',
            'payment_id' => 'PAY-W1',
            'verified_at' => now(),
            // The interrupted state: money recorded, hook never finished.
            'settled_at' => now()->subHour(),
        ]);

        $recovered = app(PaymentSettlementService::class)->recover();

        $this->assertSame(0, $recovered['recovered']);
        $this->assertSame(1, $recovered['skipped'], 'left for a person');
        $this->assertSame(0, $this->successHookCalls(), 'the wallet was not credited again');
        $this->assertNull($walletAttempt->refresh()->hook_completed_at);
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
        $this->assertAppliedExactlyOnce();
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

    public function test_an_unsigned_event_of_any_type_is_refused_and_costs_nothing(): void
    {
        /*
         * The hole this closes. An event type whose signed-field order MyFatoorah does not publish used
         * to be let through unverified, on the grounds that the processor would only re-read the API
         * rather than believe the body. But that re-read is itself an outbound provider call, so anyone
         * who could reach this URL could spend our MyFatoorah rate limit and fill our tables — with no
         * signature at all.
         */
        $this->fakePaid();

        $refundEvent = [
            'Event' => ['Code' => 2, 'Name' => 'REFUND_STATUS_CHANGED', 'Reference' => 'EV-R1'],
            'Data' => ['Refund' => ['ExternalIdentifier' => 'rf-anything', 'Status' => 'Executed']],
        ];

        $this->postJson('/api/myfatoorah/webhook', $refundEvent)->assertStatus(401);
        $this->postWebhook($refundEvent, 'made-up-signature')->assertStatus(401);

        $this->assertSame(0, MyFatoorahWebhookEvent::count(), 'nothing was written');
        Http::assertNothingSent();
    }

    public function test_an_event_type_that_cannot_be_authenticated_is_refused_even_when_signed(): void
    {
        // Signed with the real secret, but for an event whose signed fields MyFatoorah does not publish.
        // We cannot compute what its signature should be, so we cannot accept it.
        $this->fakePaid();

        $payload = [
            'Event' => ['Code' => 3, 'Name' => 'BALANCE_TRANSFERRED', 'Reference' => 'EV-B1'],
            'Data' => ['Invoice' => ['Id' => 'INV-1', 'Status' => 'PAID']],
        ];

        $this->postWebhook($payload, $this->signatureFor($payload))->assertStatus(401);

        $this->assertSame(0, MyFatoorahWebhookEvent::count());
        Http::assertNothingSent();
    }

    public function test_the_unsigned_escape_hatch_does_nothing_on_a_live_account(): void
    {
        /*
         * The switch exists so a sandbox can be debugged before the portal's secure key is set up. On a
         * live account an accepted unsigned webhook is an open door to marking orders paid, so it is
         * refused however the environment is configured.
         */
        config(['myfatoorah.webhook.require_signature' => false]);
        $this->configureGateway(mode: 'live', apiKey: 'live-key', webhookSecret: 'live-secret');

        $payload = $this->webhookPayload('pk-anything-1');

        $this->postJson('/api/myfatoorah/webhook', $payload)->assertStatus(401);
        $this->assertSame(0, $this->successHookCalls());
    }

    public function test_the_unsigned_escape_hatch_works_in_sandbox(): void
    {
        // The other half: it has to actually help when debugging a sandbox, or nobody will leave it off
        // in production.
        config(['myfatoorah.webhook.require_signature' => false]);
        $this->fakePaid();

        $this->postJson('/api/myfatoorah/webhook', $this->webhookPayload($this->attempt->external_identifier))
            ->assertOk()
            ->assertJson(['status' => 'settled']);
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

    public function test_a_failed_attempt_does_not_fail_the_order_while_another_is_still_live(): void
    {
        /*
         * One payment_requests row can have several MyFatoorah attempts — a declined card, then a retry
         * — and they all share one failure hook. So "attempt 1 failed" is not "the payment failed", and
         * running the hook on the first tears down a checkout the customer is still in the middle of.
         */
        $live = MyFatoorahPaymentAttempt::create([
            'payment_request_id' => $this->paymentRequestId,
            'attempt_number' => 2,
            'external_identifier' => 'pk-the-retry-2',
            'expected_amount' => '150.00',
            'expected_currency' => 'SAR',
            'status' => AttemptStatus::Redirected->value,
            'mode' => 'test',
            'payment_url' => 'https://sa.myfatoorah.com/KSA/ie/2',
        ]);

        Http::fake(['*/v3/payments/*' => Http::response(
            $this->paymentDetails($this->attempt->external_identifier, invoiceStatus: 'PENDING', transactionStatus: 'FAILED'),
        )]);

        $this->get('/payment/myfatoorah/callback?reference=' . $this->attempt->external_identifier . '&paymentId=PAY-1')
            ->assertRedirect();

        $this->assertSame(AttemptStatus::Failed->value, $this->attempt->refresh()->status, 'this attempt did fail');
        $this->assertSame(0, $this->failureHookCalls(), 'but the order was not failed');
        $this->assertTrue($live->refresh()->statusEnum()->isInFlight(), 'the retry is untouched');
    }

    public function test_the_failure_hook_runs_once_nothing_else_is_open(): void
    {
        // The other half: when it really is the end of the road, the hook must still run.
        Http::fake(['*/v3/payments/*' => Http::response(
            $this->paymentDetails($this->attempt->external_identifier, invoiceStatus: 'PENDING', transactionStatus: 'FAILED'),
        )]);

        $this->get('/payment/myfatoorah/callback?reference=' . $this->attempt->external_identifier . '&paymentId=PAY-1')
            ->assertRedirect();

        $this->assertSame(1, $this->failureHookCalls());
    }

    public function test_a_failed_attempt_does_not_fail_an_order_another_attempt_already_paid(): void
    {
        $paid = MyFatoorahPaymentAttempt::create([
            'payment_request_id' => $this->paymentRequestId,
            'attempt_number' => 2,
            'external_identifier' => 'pk-the-winner-2',
            'expected_amount' => '150.00',
            'expected_currency' => 'SAR',
            'captured_amount' => '150.00',
            'captured_currency' => 'SAR',
            'status' => AttemptStatus::Paid->value,
            'mode' => 'test',
            'payment_id' => 'PAY-2',
            'verified_at' => now(),
            'settled_at' => now(),
            'hook_completed_at' => now(),
        ]);

        Http::fake(['*/v3/payments/*' => Http::response(
            $this->paymentDetails($this->attempt->external_identifier, invoiceStatus: 'PENDING', transactionStatus: 'FAILED'),
        )]);

        $this->get('/payment/myfatoorah/callback?reference=' . $this->attempt->external_identifier . '&paymentId=PAY-1')
            ->assertRedirect();

        $this->assertSame(0, $this->failureHookCalls());
        $this->assertSame(AttemptStatus::Paid->value, $paid->refresh()->status);
    }

    // ---------------------------------------------------------------- an abandoned invoice paid anyway

    public function test_a_late_payment_of_an_invoice_we_abandoned_is_never_settled(): void
    {
        /*
         * Cancelling an attempt cancels OUR record of it. MyFatoorah is not told, and its invoice stays
         * payable — so a customer who still has the old hosted page open can pay it minutes after the
         * basket changed. The amount on that invoice is the amount we asked for at the time, so every
         * money check would pass and the order would be released for the wrong total.
         */
        $this->attempt->forceFill([
            'status' => AttemptStatus::Cancelled->value,
            'live_slot' => null,
            'last_error_code' => 'amount_changed',
        ])->save();

        $this->fakePaid();

        $verified = app(PaymentVerificationService::class)->verify($this->attempt, 'PAY-1');

        $this->assertSame(AttemptStatus::Mismatch, $verified['status']);
        $this->assertSame('paid_after_abandonment', $this->attempt->refresh()->last_error_code);

        // And it cannot be settled even if something tries.
        $settled = app(PaymentSettlementService::class)->settle($this->attempt->refresh());

        $this->assertFalse($settled['settled']);
        $this->assertSame(0, $this->orderCount());
        $this->assertSame(0, $this->effectCount('stock_reservation'));
        $this->assertSame(0, $this->effectCount('shipment'));
        $this->assertSame(0, (int) PaymentRequest::find($this->paymentRequestId)->is_paid);
    }

    public function test_a_webhook_for_an_abandoned_invoice_creates_no_order(): void
    {
        // The same thing arriving the way it really would.
        $this->attempt->forceFill([
            'status' => AttemptStatus::Expired->value,
            'live_slot' => null,
        ])->save();

        $this->fakePaid();

        $this->postWebhook($this->webhookPayload($this->attempt->external_identifier))
            ->assertOk()
            ->assertJson(['status' => 'mismatch']);

        $this->assertSame(0, $this->successHookCalls());
        $this->assertSame(0, $this->orderCount());
        $this->assertSame(1, app(ReconciliationService::class)->needsAttention()['mismatched']);
    }

    public function test_the_old_invoice_being_paid_late_does_not_release_a_repriced_order(): void
    {
        /*
         * The whole sequence: basket 150, customer leaves the page open, basket becomes 180, a new
         * invoice is made for 180, and then the OLD page is paid. One order at most, and never for 150.
         */
        $this->attempt->forceFill([
            'status' => AttemptStatus::Cancelled->value,
            'live_slot' => null,
            'last_error_code' => 'amount_changed',
        ])->save();

        DB::table('payment_requests')->where('id', $this->paymentRequestId)->update(['payment_amount' => 180.00]);

        $fresh = MyFatoorahPaymentAttempt::create([
            'payment_request_id' => $this->paymentRequestId,
            'attempt_number' => 2,
            'external_identifier' => 'pk-repriced-2',
            'expected_amount' => '180.00',
            'expected_currency' => 'SAR',
            'status' => AttemptStatus::Redirected->value,
            'live_slot' => 1,
            'mode' => 'test',
            'payment_id' => 'PAY-2',
            'invoice_id' => 'INV-2',
            'payment_url' => 'https://sa.myfatoorah.com/KSA/ie/2',
        ]);

        // The old invoice is paid, for the old amount.
        Http::fake(function ($request) use ($fresh) {
            if (str_contains($request->url(), '/v3/payments/PAY-2')) {
                return Http::response($this->paymentDetails($fresh->external_identifier, amount: '180.00', paymentId: 'PAY-2', invoiceId: 'INV-2'));
            }

            return Http::response($this->paymentDetails($this->attempt->external_identifier, amount: '150.00'));
        });

        app(PaymentVerificationService::class)->verify($this->attempt, 'PAY-1');

        $this->assertSame(AttemptStatus::Mismatch->value, $this->attempt->refresh()->status);
        $this->assertSame(0, $this->orderCount(), 'the stale invoice released nothing');

        // The live attempt still settles, once, for the right money.
        $verifiedFresh = app(PaymentVerificationService::class)->verify($fresh, 'PAY-2');
        $this->assertSame(AttemptStatus::Paid, $verifiedFresh['status']);

        app(PaymentSettlementService::class)->settle($verifiedFresh['attempt']);

        $this->assertAppliedExactlyOnce();

        // For the new total, not the one the stale invoice carried.
        $this->assertEqualsWithDelta(180.00, (float) DB::table('orders')->value('order_amount'), 0.001);
        $this->assertSame('PAY-2', PaymentRequest::find($this->paymentRequestId)->transaction_id);
    }

    public function test_an_order_repriced_mid_payment_is_not_released_against_the_old_snapshot(): void
    {
        // Even without an abandonment marker: the attempt's snapshot is what MyFatoorah charged, and if
        // the order is now owed something else, releasing it would accept a wrong amount.
        $this->fakePaid();

        DB::table('payment_requests')->where('id', $this->paymentRequestId)->update(['payment_amount' => 180.00]);

        $verified = app(PaymentVerificationService::class)->verify($this->attempt, 'PAY-1');

        $this->assertSame(AttemptStatus::Mismatch, $verified['status']);
        $this->assertSame('request_amount_changed', $this->attempt->refresh()->last_error_code);
        $this->assertSame(0, $this->orderCount());
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
