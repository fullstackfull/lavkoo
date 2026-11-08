<?php

namespace Tests\Feature\Concurrency;

use App\Models\MyFatoorahPaymentAttempt;
use App\Services\MyFatoorah\GatewayConfigRepository;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsMyFatoorahSchema;
use Tests\Support\Concurrency\ConcurrentRunner;
use Tests\TestCase;

/**
 * Two checkouts for one order, at the same instant, against a real MySQL.
 *
 * The protection being tested is a row lock on `payment_requests` plus a unique index on
 * `external_identifier`, and neither can be tested in the in-memory SQLite the rest of the suite uses:
 * that database is private to a single process, so "two processes at once" is not expressible in it. The
 * processes here really do overlap, and the harness proves they did.
 *
 * What must hold: however many requests arrive together, ONE invoice is created at MyFatoorah. A second
 * invoice is a second thing the customer can pay.
 */
class MyFatoorahConcurrencyTest extends TestCase
{
    use BuildsMyFatoorahSchema;

    protected function setUp(): void
    {
        parent::setUp();

        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process.');
        }

        $this->buildMyFatoorahSchema();
        $this->sarIsActive();
        $this->digitalPaymentOn();

        // Also loaded by every worker through the operations registration file; needed here because this
        // process asserts on what the hooks did.
        require_once __DIR__ . '/../../Support/myfatoorah_test_hooks.php';

        // This process only. Each worker is a separate PHP process that boots the application itself
        // and switches the gateway on for its own run (see MyFatoorahOperations::startPayment), so
        // nothing here has to be exported into the environment for them.
        config(['myfatoorah.enabled' => true]);

        app(GatewayConfigRepository::class)->save(
            active: true,
            mode: 'test',
            secrets: ['api_key' => 'concurrency-key', 'webhook_secret' => 'concurrency-secret'],
            secretsMode: 'test',
        );
    }

    public function test_six_simultaneous_checkouts_create_exactly_one_invoice(): void
    {
        $paymentRequestId = $this->makePaymentRequest();

        $reports = ConcurrentRunner::run(
            'myfatoorah.start_payment',
            array_fill(0, 6, ['payment_request' => $paymentRequestId]),
        );

        $this->assertCount(6, $reports);
        $this->assertCount(6, array_unique(array_column($reports, 'pid')), 'six different processes');
        $this->assertTrue(ConcurrentRunner::overlapped($reports), 'the processes ran at the same time');

        foreach ($reports as $index => $report) {
            $this->assertTrue($report['ok'], "process {$index} failed: " . ($report['message'] ?? ''));
        }

        $results = array_column($reports, 'result');

        // The one that matters: one POST to MyFatoorah, so one invoice exists.
        $created = array_filter($results, static fn (array $r): bool => $r['created_invoice'] === true);
        $this->assertCount(1, $created, 'exactly one process created an invoice');

        // And one attempt row, so there is nothing for a second payment to attach to.
        $this->assertSame(1, MyFatoorahPaymentAttempt::query()->where('payment_request_id', $paymentRequestId)->count());

        // Everyone who did not create it either got the winner's payment page or was told to wait. What
        // nobody may get is a second invoice of their own.
        $invoices = array_unique(array_filter(array_column($results, 'invoice')));
        $this->assertLessThanOrEqual(1, count($invoices), 'at most one invoice id across all six answers');

        foreach ($results as $index => $result) {
            if ($result['ok']) {
                continue;
            }

            $this->assertContains(
                $result['code'],
                ['attempt_in_doubt', 'attempt_conflict', 'already_paid'],
                "process {$index} was refused for an unexpected reason: " . (string) $result['code'],
            );
        }
    }

    public function test_simultaneous_checkouts_for_different_orders_do_not_block_each_other(): void
    {
        // The lock is per payment request, so two customers must not queue behind one another.
        $first = $this->makePaymentRequest();
        $second = $this->makePaymentRequest();

        $reports = ConcurrentRunner::run('myfatoorah.start_payment', [
            ['payment_request' => $first],
            ['payment_request' => $second],
        ]);

        foreach ($reports as $index => $report) {
            $this->assertTrue($report['ok'], "process {$index} failed: " . ($report['message'] ?? ''));
            $this->assertTrue($report['result']['ok'], "process {$index} was refused: " . (string) $report['result']['code']);
            $this->assertTrue($report['result']['created_invoice'], "process {$index} should have created its own invoice");
        }

        $this->assertSame(1, MyFatoorahPaymentAttempt::query()->where('payment_request_id', $first)->count());
        $this->assertSame(1, MyFatoorahPaymentAttempt::query()->where('payment_request_id', $second)->count());
    }

    public function test_an_attempt_left_in_doubt_stops_every_simultaneous_retry_creating_its_own_invoice(): void
    {
        // The state a timed-out create leaves behind: no payment URL, so nothing to send the customer
        // back to, and we cannot know whether an invoice exists. Six retries arriving together must not
        // turn that into six more invoices.
        $paymentRequestId = $this->makePaymentRequest();

        $inDoubt = MyFatoorahPaymentAttempt::create([
            'payment_request_id' => $paymentRequestId,
            'attempt_number' => 1,
            'external_identifier' => 'pk-in-doubt-1',
            'expected_amount' => '150.00',
            'expected_currency' => 'SAR',
            'status' => 'created',
            // Holding the order's one live slot, as a real attempt in doubt does.
            'live_slot' => 1,
            'mode' => 'test',
        ]);

        // Older than the grace window, so the workers are allowed to conclude something about it. A
        // fresh one is deliberately untouchable — its create call could still be running.
        $inDoubt->forceFill(['created_at' => now()->subHour()])->save();

        $reports = ConcurrentRunner::run(
            'myfatoorah.start_payment',
            array_fill(0, 6, ['payment_request' => $paymentRequestId]),
        );

        $results = array_column($reports, 'result');

        /*
         * Every worker's invoice lookup answers 404, and this account has never been seen to serve that
         * lookup — so a 404 from it proves nothing. None of them may conclude the invoice was never
         * created, and none may make a second one.
         */
        $created = array_filter($results, static fn (array $r): bool => $r['created_invoice'] === true);
        $this->assertCount(0, $created, 'no new invoice while the question is open');

        $this->assertSame(
            1,
            MyFatoorahPaymentAttempt::query()->where('payment_request_id', $paymentRequestId)->count(),
            'only the attempt that was already in doubt',
        );

        $this->assertSame('lookup_unproven', (string) $inDoubt->refresh()->last_error_code);
        $this->assertSame(1, (int) $inDoubt->live_slot, 'it still holds the order\'s live slot');
    }

    public function test_once_the_invoice_lookup_is_proven_simultaneous_retries_create_exactly_one_replacement(): void
    {
        // The other half: on an account that does serve the lookup, a 404 really is "no invoice", the
        // dead attempt is closed, and exactly one of six simultaneous retries makes the replacement.
        $paymentRequestId = $this->makePaymentRequest();

        $inDoubt = MyFatoorahPaymentAttempt::create([
            'payment_request_id' => $paymentRequestId,
            'attempt_number' => 1,
            'external_identifier' => 'pk-in-doubt-proven-1',
            'expected_amount' => '150.00',
            'expected_currency' => 'SAR',
            'status' => 'created',
            'live_slot' => 1,
            'mode' => 'test',
        ]);
        $inDoubt->forceFill(['created_at' => now()->subHour()])->save();

        // Recorded in the shared settings row, so every worker sees it.
        app(GatewayConfigRepository::class)->markInvoiceLookupProven('test');

        $reports = ConcurrentRunner::run(
            'myfatoorah.start_payment',
            array_fill(0, 6, ['payment_request' => $paymentRequestId]),
        );

        $results = array_column($reports, 'result');
        $created = array_filter($results, static fn (array $r): bool => $r['created_invoice'] === true);

        $this->assertCount(1, $created, 'exactly one new invoice');
        $this->assertSame(
            2,
            MyFatoorahPaymentAttempt::query()->where('payment_request_id', $paymentRequestId)->count(),
            'the attempt in doubt, plus its one replacement',
        );

        // The live slot is held by the replacement and by nothing else.
        $this->assertSame(
            1,
            MyFatoorahPaymentAttempt::query()
                ->where('payment_request_id', $paymentRequestId)
                ->whereNotNull('live_slot')
                ->count(),
        );
    }

    public function test_a_stale_invoice_and_the_live_one_paid_at_once_create_one_order_for_the_right_money(): void
    {
        /*
         * Case 3 under real concurrency. The basket was 150, the customer left that page open, the
         * basket became 180 and a new invoice was made for it. Then BOTH are paid at the same instant —
         * the stale page and the live one.
         *
         * What must hold: one order, for 180, and the stale payment recorded as needing a refund
         * decision rather than quietly releasing anything.
         */
        $paymentRequestId = $this->makePaymentRequest(['payment_amount' => 180.00]);

        $stale = MyFatoorahPaymentAttempt::create([
            'payment_request_id' => $paymentRequestId,
            'attempt_number' => 1,
            'external_identifier' => 'pk-stale-1',
            'expected_amount' => '150.00',
            'expected_currency' => 'SAR',
            // Abandoned when the basket changed. MyFatoorah was never told, so its invoice is payable.
            'status' => 'cancelled',
            'live_slot' => null,
            'last_error_code' => 'amount_changed',
            'mode' => 'test',
            'invoice_id' => 'INV-STALE',
        ]);

        $live = MyFatoorahPaymentAttempt::create([
            'payment_request_id' => $paymentRequestId,
            'attempt_number' => 2,
            'external_identifier' => 'pk-live-2',
            'expected_amount' => '180.00',
            'expected_currency' => 'SAR',
            'status' => 'redirected',
            'live_slot' => 1,
            'mode' => 'test',
            'invoice_id' => 'INV-LIVE',
            'payment_url' => 'https://sa.myfatoorah.com/KSA/ie/live',
        ]);

        $reports = ConcurrentRunner::run('myfatoorah.settle_attempt', [
            ['attempt' => $stale->external_identifier, 'external_identifier' => $stale->external_identifier, 'payment_id' => 'PAY-STALE', 'invoice_id' => 'INV-STALE', 'amount' => '150.00'],
            ['attempt' => $live->external_identifier, 'external_identifier' => $live->external_identifier, 'payment_id' => 'PAY-LIVE', 'invoice_id' => 'INV-LIVE', 'amount' => '180.00'],
        ]);

        foreach ($reports as $index => $report) {
            $this->assertTrue($report['ok'], "process {$index} failed: " . ($report['message'] ?? ''));
        }

        $results = array_column($reports, 'result');
        $settled = array_filter($results, static fn (array $r): bool => $r['settled'] === true);

        $this->assertCount(1, $settled, 'exactly one of the two settled');

        // The stale one is flagged, not settled.
        $this->assertSame('mismatch', $stale->refresh()->status);
        $this->assertSame('paid_after_abandonment', $stale->last_error_code);

        // One order, for the money the order is actually owed.
        $orders = DB::table('orders')->where('transaction_ref', 'PAY-LIVE')->get();
        $this->assertCount(1, $orders);
        $this->assertSame(0, DB::table('orders')->where('transaction_ref', 'PAY-STALE')->count());
        $this->assertEqualsWithDelta(180.00, (float) $orders->first()->order_amount, 0.001);

        $paymentRequest = DB::table('payment_requests')->where('id', $paymentRequestId)->first();
        $this->assertSame(1, (int) $paymentRequest->is_paid);
        $this->assertSame('PAY-LIVE', $paymentRequest->transaction_id);
    }

    public function test_six_simultaneous_deliveries_of_one_payment_create_one_order(): void
    {
        // The settlement claim and the hook lease, under real processes rather than a re-entrant call.
        $paymentRequestId = $this->makePaymentRequest();

        $attempt = MyFatoorahPaymentAttempt::create([
            'payment_request_id' => $paymentRequestId,
            'attempt_number' => 1,
            'external_identifier' => 'pk-one-payment-1',
            'expected_amount' => '150.00',
            'expected_currency' => 'SAR',
            'status' => 'redirected',
            'live_slot' => 1,
            'mode' => 'test',
            'invoice_id' => 'INV-1',
            'payment_url' => 'https://sa.myfatoorah.com/KSA/ie/1',
        ]);

        $reports = ConcurrentRunner::run('myfatoorah.settle_attempt', array_fill(0, 6, [
            'attempt' => $attempt->external_identifier,
            'external_identifier' => $attempt->external_identifier,
            'payment_id' => 'PAY-1',
            'invoice_id' => 'INV-1',
            'amount' => '150.00',
        ]));

        $this->assertTrue(ConcurrentRunner::overlapped($reports), 'the processes ran at the same time');

        $settled = array_filter(array_column($reports, 'result'), static fn (array $r): bool => $r['settled'] === true);
        $this->assertCount(1, $settled, 'exactly one process applied the payment');

        $this->assertSame(1, DB::table('orders')->where('transaction_ref', 'PAY-1')->count(), 'one order');
        $this->assertSame('paid', $attempt->refresh()->status);
        $this->assertNotNull($attempt->hook_completed_at);
    }

    /** @return string the payment_requests id */
    private function makePaymentRequest(array $overrides = []): string
    {
        $id = (string) \Illuminate\Support\Str::uuid();

        DB::table('payment_requests')->insert(array_merge([
            'id' => $id,
            'payer_id' => '7',
            'receiver_id' => '100',
            'payment_amount' => 150.00,
            'currency_code' => 'SAR',
            'payment_method' => 'fatoorah',
            'success_hook' => 'myfatoorah_concurrency_order_hook',
            'failure_hook' => 'myfatoorah_concurrency_noop_hook',
            'additional_data' => json_encode(['customer_id' => 7]),
            'payer_information' => json_encode(['name' => 'Test Customer', 'email' => 'c@example.com', 'phone' => '0551234567']),
            'receiver_information' => json_encode(['name' => 'receiver']),
            'attribute' => 'order',
            'attribute_id' => '1',
            'payment_platform' => 'web',
            'is_paid' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }
}
