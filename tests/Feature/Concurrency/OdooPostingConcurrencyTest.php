<?php

namespace Tests\Feature\Concurrency;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\OdooPosting;
use App\Services\Accounting\Odoo\OdooOutbox;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsOdooWorld;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\Concurrency\ConcurrentRunner;
use Tests\Support\CreatesOperationalCostData;
use Tests\TestCase;

/**
 * SEVERAL PROCESSES POSTING ONE COST, at the same instant, against a real engine.
 *
 * This is not a hypothetical. The outbox is drained by a scheduled command and can be drained from a screen; two runs overlap
 * whenever a cron fires while somebody presses a button, or whenever one run takes longer than the gap to the next. And **Odoo
 * publishes no idempotency mechanism whatsoever** — no external-reference field, no uniqueness constraint, no upsert. `create`
 * creates, every single time it is called.
 *
 * So the expensive failure is specific: the customer's accountant finds the same cost twice in their books, with nothing in Odoo
 * to have prevented it and no way to delete a posted entry — a duplicate is corrected by a reversal, which is itself a third
 * entry somebody has to explain.
 *
 * **Odoo is played by a table here**, `test_odoo_moves`, which these tests create: a create INSERTS into it and a search reads
 * it, with no unique key. A second entry therefore becomes a second ROW, and the assertion is a count rather than an argument.
 *
 * Needs a real MySQL or MariaDB — SQLite in memory is private to one process, so there is nothing to race.
 */
class OdooPostingConcurrencyTest extends TestCase
{
    use BuildsOdooWorld;
    use BuildsSalesChannelSchema;
    use CreatesOperationalCostData;

    protected function setUp(): void
    {
        parent::setUp();
        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process.');
        }
        $this->buildSalesChannelSchema();
        $this->buildOdooSchema();
        $this->buildTheFakeLedger();
        Http::preventStrayRequests();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('test_odoo_moves');
        parent::tearDown();
    }

    public function test_one_cost_drained_by_several_processes_at_once_is_one_entry_in_odoo(): void
    {
        $connection = $this->odooConnection();
        $this->mapOdooComponent(CostComponent::Shipping->value);
        app(OdooOutbox::class)->enqueue($this->settledCost('24.50'), self::ODOO_COMPANY);

        // Five runs, one queued cost: the cron, a button, and three retries, all at the same instant.
        $reports = ConcurrentRunner::run('odoo.post_same_fact', array_fill(0, 5, ['account' => $connection->id]));

        $this->assertSame([], $this->problemsOf($reports), 'no process may fail');
        $this->assertSame(1, DB::table('test_odoo_moves')->count(), 'ONE journal entry reached Odoo, whatever five processes tried');
        $this->assertSame(1, OdooPosting::where('state', OdooPosting::POSTED)->count());
        $this->assertSame(1, $this->workersWhere($reports, static fn (array $stats): bool => ($stats['claimed'] ?? 0) > 0), 'exactly one run held the row');
    }

    public function test_a_create_whose_answer_is_lost_in_every_process_still_posts_only_once(): void
    {
        // THE WORST CASE, and the one the whole design exists for: the entry IS created and no process can see that it was.
        // One row must still reach Odoo, and the posting row must end up waiting for a person rather than being tried again.
        $connection = $this->odooConnection();
        $this->mapOdooComponent(CostComponent::Shipping->value);
        app(OdooOutbox::class)->enqueue($this->settledCost('24.50'), self::ODOO_COMPANY);

        $reports = ConcurrentRunner::run('odoo.post_same_fact', array_fill(0, 4, ['account' => $connection->id, 'lose_the_answer' => true]));

        $this->assertSame([], $this->problemsOf($reports));
        $this->assertSame(1, DB::table('test_odoo_moves')->count(), 'one entry, even though nobody could confirm it');
        $row = OdooPosting::firstOrFail();
        $this->assertSame(OdooPosting::NEEDS_REVIEW, $row->state, 'and it waits for a person rather than for another attempt');
        $this->assertNull($row->available_at, 'nothing makes it due again');
    }

    public function test_several_different_costs_drained_at_once_neither_deadlock_nor_lose_one(): void
    {
        // The other half: a full queue worked by overlapping runs. Every fact posts exactly once, and two runs inserting rows
        // they both found missing must not deadlock on the gap where they will be.
        $connection = $this->odooConnection();
        $this->mapOdooComponent(CostComponent::Shipping->value);
        foreach (range(1, 8) as $number) {
            app(OdooOutbox::class)->enqueue($this->settledCost((string) (10 + $number)), self::ODOO_COMPANY);
        }

        $reports = ConcurrentRunner::run('odoo.post_same_fact', array_fill(0, 4, ['account' => $connection->id]));

        $this->assertSame([], $this->problemsOf($reports), 'no process may fail and none may deadlock');
        $this->assertSame(8, DB::table('test_odoo_moves')->count(), 'eight entries for eight costs');
        $this->assertSame(8, DB::table('test_odoo_moves')->distinct()->count('reference'), 'and eight distinct references');
        $this->assertSame(8, OdooPosting::where('state', OdooPosting::POSTED)->count());
    }

    public function test_a_run_killed_between_the_request_and_the_answer_does_not_become_two_entries(): void
    {
        // **THE CRASH WINDOW, with real processes.** A run fences the attempt, Odoo receives `account.move.create`, and the
        // process ends before it can record anything. Its lease expires, another run takes the row over -- and Odoo answers that
        // it holds no such entry, which a read behind its own write says as readily as a database that never got the create.
        //
        // Before the fence moved ahead of the request, that row read `claimed`, and `reclaimAbandoned()` set it back to
        // `pending`: brand new, as though nothing had ever been sent. The second run would have posted the cost again.
        $connection = $this->odooConnection();
        $this->mapOdooComponent(CostComponent::Shipping->value);
        app(OdooOutbox::class)->enqueue($this->settledCost('24.50'), self::ODOO_COMPANY);

        $first = ConcurrentRunner::run('odoo.post_same_fact', array_fill(0, 4, [
            'account' => $connection->id, 'die_after_send' => true,
        ]));

        $this->assertSame([], $this->problemsOf($first), 'a killed run is reported, not a harness failure');
        $this->assertSame(1, DB::table('test_odoo_moves')->count(), 'one run sent; the others were told somebody else held the row');
        $this->assertSame(1, $this->workersWhere($first, static fn (array $r): bool => ($r['died_after_send'] ?? false) === true));
        $this->assertSame(OdooPosting::MAY_HAVE_POSTED, OdooPosting::firstOrFail()->state, 'and the row knew before the request went');

        // The dead run's lease runs out.
        OdooPosting::query()->update(['claimed_at' => now()->subMinutes((int) config('odoo.outbox.claim_minutes') + 1)]);

        // Wave two, with Odoo answering "no such entry" for one it is actually holding.
        $second = ConcurrentRunner::run('odoo.post_same_fact', array_fill(0, 4, [
            'account' => $connection->id, 'read_lag' => true,
        ]));

        $this->assertSame([], $this->problemsOf($second));
        $this->assertSame(1, DB::table('test_odoo_moves')->count(), 'STILL ONE. "No such entry" is not permission to post a cost that may already be in the books.');
        $this->assertSame(0, $this->workersWhere($second, static fn (array $r): bool => ($r['posted'] ?? 0) > 0), 'nobody created anything');
        $this->assertSame(OdooPosting::NEEDS_REVIEW, OdooPosting::firstOrFail()->state, 'it waits for a person');
    }

    // ---- fixtures -----------------------------------------------------------------------------------------------

    /**
     * Odoo's own side, as a table the worker processes share.
     *
     * No unique key on `reference`, deliberately: the fixture has to show a duplicate rather than refuse it, or the test would
     * be proving that MySQL works rather than that this code does.
     */
    private function buildTheFakeLedger(): void
    {
        Schema::dropIfExists('test_odoo_moves');
        Schema::create('test_odoo_moves', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 191)->index();
            $table->unsignedInteger('move_id');
            $table->unsignedInteger('created_by');
        });
    }

    private function settledCost(string $amount): \App\Models\OperationalCostEntry
    {
        return $this->recordCost(CostState::Actual, $amount, CostComponent::Shipping, '2026-11-10');
    }

    /**
     * @param list<array{ok: bool, result?: mixed}> $reports
     * @param callable(array<string, mixed>): bool $matches
     */
    private function workersWhere(array $reports, callable $matches): int
    {
        return count(array_filter($reports, static fn (array $report): bool => is_array($report['result'] ?? null) && $matches($report['result'])));
    }

    /**
     * @param list<array{ok: bool, error?: string, message?: string}> $reports
     * @return list<string>
     */
    private function problemsOf(array $reports): array
    {
        $problems = [];
        foreach ($reports as $index => $report) {
            if (!$report['ok']) {
                $problems[] = "worker {$index}: " . ($report['error'] ?? '') . ' ' . ($report['message'] ?? '');
            }
        }

        return $problems;
    }
}
