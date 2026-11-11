<?php

namespace Tests\Feature\Accounting;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\OdooPosting;
use App\Services\Accounting\Odoo\OdooOutbox;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsOdooWorld;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\Concurrency\Operations;
use Tests\Support\CreatesOperationalCostData;
use Tests\TestCase;

/**
 * The body of the Odoo concurrency worker, **run in this process**.
 *
 * ## Why this file exists, which is a failure of mine worth writing down
 *
 * `OdooPostingConcurrencyTest` needs several processes against one real database, so it skips on SQLite — and SQLite is what
 * the local suite and two of the four CI engines use. The worker body therefore had no test that ever EXECUTED it anywhere
 * except on a real MySQL or MariaDB, and the local suite reported those four tests as skipped while reading, at a glance, like
 * coverage.
 *
 * What hid in that gap was not subtle: the fake's closure read two flags it had not captured (`use ($loseTheAnswer)` while the
 * body used `$readLag` and `$dieAfterSend`), which PHP 8.3 raises as an undefined variable the moment the closure runs. Every
 * one of the four concurrency tests failed on the first real server that reached them.
 *
 * So this file runs the same operation in-process, once per flag, on whatever engine the suite is using. **It proves nothing
 * about concurrency** — one process cannot race itself, and the real guarantees are still tested where they have to be. It
 * proves the worker RUNS, which is the cheap half of the problem and the half that was missing.
 */
class OdooWorkerOperationTest extends TestCase
{
    use BuildsOdooWorld;
    use BuildsSalesChannelSchema;
    use CreatesOperationalCostData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->buildOdooSchema();
        Schema::dropIfExists('test_odoo_moves');
        Schema::create('test_odoo_moves', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 191)->index();
            $table->unsignedInteger('move_id');
            $table->unsignedInteger('created_by');
        });
        Http::preventStrayRequests();
        Cache::flush();
        // The worker processes load these in their own bootstrap; in-process they have to be loaded here, which is also a check
        // that the registration file names an operation that exists.
        require_once base_path('tests/Support/Concurrency/registrations/odoo.php');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('test_odoo_moves');
        parent::tearDown();
    }

    /**
     * @param array<string, bool> $flags
     */
    #[DataProvider('flags')]
    public function test_the_worker_runs_and_reports_rather_than_raising(array $flags, string $expectation): void
    {
        $connection = $this->odooConnection();
        $this->mapOdooComponent(CostComponent::Shipping->value);
        app(OdooOutbox::class)->enqueue($this->recordCost(CostState::Actual, '24.50', CostComponent::Shipping, '2026-11-10'), self::ODOO_COMPANY);

        $report = Operations::run('odoo.post_same_fact', ['account' => $connection->id] + $flags, 0);

        $this->assertIsArray($report, 'the worker must hand back a report, whatever happened to its request');
        $this->assertArrayHasKey('claimed', $report);
        match ($expectation) {
            'posted' => $this->assertSame(1, $report['posted'], 'a clean answer posts the entry'),
            'died' => $this->assertTrue((bool) ($report['died_after_send'] ?? false), 'a killed run says so instead of looking like a clean pass'),
            // A lost answer alone is RECOVERABLE: the create landed, and asking Odoo by the reference finds it. The row closes
            // on Odoo's word rather than on ours, which is what the reference exists for.
            'found' => $this->assertSame(1, $report['found'], 'the entry is found by its reference and the row closes on Odoo\'s word'),
            // A lost answer AND a read that is behind its own write: nobody can say whether the entry is there. The strict
            // policy the owner adopted is that only proof lifts the fence, and "Odoo holds no such entry" is not proof.
            'needs_review' => $this->assertSame(1, $report['needs_review'], 'an unaccounted-for create waits for a person'),
            default => $this->fail('unknown expectation'),
        };
    }

    /** @return array<string, array{0: array<string, bool>, 1: string}> */
    public static function flags(): array
    {
        return [
            'a clean create' => [[], 'posted'],
            'the answer is lost' => [['lose_the_answer' => true], 'found'],
            'the run is killed after the request went out' => [['die_after_send' => true], 'died'],
            // The flag whose variable was not captured, which is why it is named here.
            'Odoo read is behind its own write' => [['read_lag' => true], 'posted'],
            'a lost answer and a lagging read together' => [['lose_the_answer' => true, 'read_lag' => true], 'needs_review'],
        ];
    }

    public function test_a_create_that_reached_odoo_is_visible_in_the_fake_ledger_even_when_the_run_died(): void
    {
        // The fixture has to SHOW a duplicate rather than prevent one, so the create is recorded before the process dies. If
        // this ever stopped being true, the concurrency tests would pass by accident.
        $connection = $this->odooConnection();
        $this->mapOdooComponent(CostComponent::Shipping->value);
        app(OdooOutbox::class)->enqueue($this->recordCost(CostState::Actual, '24.50', CostComponent::Shipping, '2026-11-10'), self::ODOO_COMPANY);

        Operations::run('odoo.post_same_fact', ['account' => $connection->id, 'die_after_send' => true], 0);

        $this->assertSame(1, DB::table('test_odoo_moves')->count());
        $this->assertSame(OdooPosting::MAY_HAVE_POSTED, OdooPosting::firstOrFail()->state, 'and the fence stands, because it was written before the request');
    }
}
