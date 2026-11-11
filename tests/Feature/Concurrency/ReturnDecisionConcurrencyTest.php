<?php

namespace Tests\Feature\Concurrency;

use App\Models\ChannelReturnDecision;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\ChannelAccountService;
use App\Services\SalesChannels\Support\ChannelOperations;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\Concurrency\ConcurrentRunner;
use Tests\TestCase;

/**
 * SEVERAL PROCESSES DECIDING ONE CUSTOMER'S RETURN, at the same instant, against a real engine.
 *
 * This is not a hypothetical: the decisions are sent by a scheduled run and can be sent from a screen, so two runs overlap
 * whenever a cron fires while somebody presses a button. And **Trendyol documents no idempotency for either decision** — no
 * client-supplied key, no rejection of a repeated request, and nothing about what a second identical call does.
 *
 * So the expensive failure is specific and it is somebody's money: the customer is refunded twice, or refunded AND refused,
 * and nothing at Trendyol would connect the two calls because approve and reject are different endpoints.
 *
 * **Trendyol is played by a table here**, `test_trendyol_decisions`, with no unique key on it: a second request becomes a
 * second ROW. The assertion is therefore a count and not an argument, and a fixture that could not record a double send would
 * make these tests pass for the wrong reason.
 *
 * Needs a real MySQL or MariaDB — SQLite in memory is private to one process, so there is nothing to race.
 */
class ReturnDecisionConcurrencyTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private const SELLER = '123456';

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process.');
        }
        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        Cache::flush();
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        $this->buildTheFakeTrendyol();
        $this->account = app(ChannelAccountService::class)->create(
            ['channel' => 'trendyol', 'name' => 'Trendyol KSA', 'external_account_id' => self::SELLER, 'currency' => 'SAR', 'settings' => ['storefront_code' => 'SA', 'integration_name' => 'PharmacyHub']],
            ['api_key' => 'key-abc-123', 'api_secret' => 'secret-xyz-789'],
            adminId: 1,
        );
        $this->account->forceFill([
            'enabled' => true, 'write_enabled' => true, 'dry_run' => false,
            'capabilities' => ['connection_test', 'read_only_sync', 'order_import'],
            'capability_approvals' => [ChannelOperations::SCOPE_RETURN_DECISIONS => ['by' => 1, 'at' => '2026-11-17T00:00:00Z']],
        ])->save();
        $this->account = $this->account->fresh();
    }

    protected function tearDown(): void
    {
        if (ConcurrentRunner::available()) {
            Schema::dropIfExists('test_trendyol_decisions');
        }
        parent::tearDown();
    }

    public function test_one_return_item_decided_by_several_processes_at_once_is_sent_once(): void
    {
        // Four runs, one item: the cron, a button, and two retries, all at the same instant.
        $reports = ConcurrentRunner::run('returns.decide_and_send', array_fill(0, 4, [
            'account' => $this->account->id, 'claim' => 'CLAIM-RACE', 'item' => 'ITEM-A', 'decision' => ChannelReturnDecision::APPROVE,
        ]));

        $this->assertSame([], $this->problemsOf($reports));
        $this->assertSame(1, DB::table('test_trendyol_decisions')->count(), 'ONE request reached Trendyol, whatever four processes tried');
        $this->assertSame(1, ChannelReturnDecision::count(), 'and one decision was recorded');
        $this->assertSame(ChannelReturnDecision::DONE, ChannelReturnDecision::firstOrFail()->state);
        $this->assertSame(1, $this->workersWhere($reports, static fn (array $r): bool => ($r['decided'] ?? 0) === 1), 'exactly one process recorded the decision');
        $this->assertTrue(ConcurrentRunner::overlapped($reports), 'the processes really ran at the same time');
    }

    public function test_an_approval_and_a_rejection_of_the_same_item_at_once_send_only_the_first(): void
    {
        // The worst of the two: refunded AND refused for one unit. The endpoints differ, so nothing at Trendyol would notice.
        $reports = ConcurrentRunner::run('returns.decide_and_send', [
            ['account' => $this->account->id, 'claim' => 'CLAIM-BOTH', 'item' => 'ITEM-A', 'decision' => ChannelReturnDecision::APPROVE],
            ['account' => $this->account->id, 'claim' => 'CLAIM-BOTH', 'item' => 'ITEM-A', 'decision' => ChannelReturnDecision::REJECT, 'reason' => 51, 'description' => 'the seal is broken'],
        ]);

        $this->assertSame([], $this->problemsOf($reports));
        $this->assertSame(1, ChannelReturnDecision::count(), 'one decision, not one of each');
        $this->assertSame(1, DB::table('test_trendyol_decisions')->count(), 'and ONE request: the customer is never both refunded and refused');
        // Whichever won, the row and the request must agree about it -- a row saying "approve" while a rejection was sent would
        // be the same failure wearing a different face.
        $row = ChannelReturnDecision::firstOrFail();
        $this->assertSame($row->decision, (string) DB::table('test_trendyol_decisions')->value('kind'));
    }

    public function test_a_late_worker_cannot_send_a_decision_another_worker_has_finished(): void
    {
        // **THE INTERLEAVING THE REVIEW NAMED.** A worker reads the sendable ids, and before it gets to its claim another
        // worker claims the same row, sends it, settles it to `done` and clears the claim. The late worker's claim condition
        // then matched a FINISHED row, its fence accepted it, and the customer was refunded a second time.
        //
        // The window between the SELECT and the UPDATE is microseconds, so it is made explicit here: one worker holds its stale
        // list while the other completes.
        app(\App\Services\SalesChannels\Returns\ChannelReturnDecisions::class)->decide(
            $this->account, 'CLAIM-STALE', ['ITEM-A'], ChannelReturnDecision::APPROVE, adminId: 1,
        );
        $this->assertSame(ChannelReturnDecision::PENDING, ChannelReturnDecision::firstOrFail()->state);

        $reports = ConcurrentRunner::run('returns.decide_and_send', [
            // The one that finishes it.
            ['account' => $this->account->id, 'claim' => 'CLAIM-STALE', 'item' => 'ITEM-A', 'decision' => ChannelReturnDecision::APPROVE, 'decide' => false],
        ]);
        $this->assertSame([], $this->problemsOf($reports));
        $this->assertSame(ChannelReturnDecision::DONE, ChannelReturnDecision::firstOrFail()->state);

        // ... and now the late one, whose list was read while the row was still pending.
        $late = ConcurrentRunner::run('returns.send_with_a_stale_id_list', [
            ['account' => $this->account->id, 'hold_microseconds' => 1000],
        ]);

        $this->assertSame([], $this->problemsOf($late));
        $this->assertSame(0, (int) ($late[0]['result']['sent'] ?? -1), 'the late worker fenced nothing');
        $this->assertSame(1, DB::table('test_trendyol_decisions')->count(), 'and exactly one request reached Trendyol, ever');
        $this->assertSame(ChannelReturnDecision::DONE, ChannelReturnDecision::firstOrFail()->state, 'the finished decision was not reopened');
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------

    /** Trendyol, played by a table with NO unique key: a second request is a second row, so a double send is visible. */
    private function buildTheFakeTrendyol(): void
    {
        Schema::dropIfExists('test_trendyol_decisions');
        Schema::create('test_trendyol_decisions', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16);
            $table->text('body')->nullable();
            $table->unsignedInteger('created_by');
        });
    }

    /** @param list<array{ok: bool, error?: string, message?: string}> $reports */
    private function problemsOf(array $reports): array
    {
        $problems = [];
        foreach ($reports as $index => $report) {
            if (!($report['ok'] ?? false)) {
                $problems[] = "worker {$index}: " . (string) ($report['error'] ?? '?') . ' ' . (string) ($report['message'] ?? '');
            }
        }

        return $problems;
    }

    /**
     * @param list<array{ok: bool, result?: mixed}> $reports
     * @param callable(array<string, mixed>): bool $matches
     */
    private function workersWhere(array $reports, callable $matches): int
    {
        $count = 0;
        foreach ($reports as $report) {
            if (($report['ok'] ?? false) && is_array($report['result'] ?? null) && $matches($report['result'])) {
                $count++;
            }
        }

        return $count;
    }
}
