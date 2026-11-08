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

        // The workers are separate processes and read config from the environment, not from this one,
        // so the switches have to be real for them.
        putenv('MYFATOORAH_ENABLED=true');
        config(['myfatoorah.enabled' => true]);

        app(GatewayConfigRepository::class)->save(
            active: true,
            mode: 'test',
            secrets: ['api_key' => 'concurrency-key', 'webhook_secret' => 'concurrency-secret'],
            secretsMode: 'test',
        );
    }

    protected function tearDown(): void
    {
        putenv('MYFATOORAH_ENABLED');

        parent::tearDown();
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

        // Each worker's invoice lookup answers 404, so the attempt in doubt is closed as never created
        // and exactly one of them goes on to make the one new invoice.
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
            'success_hook' => 'myfatoorah_concurrency_noop_hook',
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
