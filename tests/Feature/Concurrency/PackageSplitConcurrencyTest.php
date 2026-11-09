<?php

namespace Tests\Feature\Concurrency;

use App\Models\ChannelPackageSplit;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\ChannelAccountService;
use App\Services\SalesChannels\Shipments\ChannelPackageSplits;
use App\Services\SalesChannels\Shipments\PackageSplitPlan;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\Concurrency\ConcurrentRunner;
use Tests\TestCase;

/**
 * SEVERAL PROCESSES CUTTING ONE PACKAGE, at the same instant, against a real engine.
 *
 * Not hypothetical: splits are sent by a scheduled run and can be asked for from a screen, so two runs overlap whenever a cron
 * fires while somebody presses a button. And **Trendyol documents no idempotency for any of its four split endpoints** and
 * says nothing about what a repeated identical request does.
 *
 * The expensive failure is a customer's parcel: a package cut twice becomes three or four shipments where there should be two,
 * each with its own label and its own courier charge, and the pharmacy pays for every one of them.
 *
 * Two things make that hard to prevent, and both are tested here:
 *
 *  - **the cut is asynchronous.** The package keeps reading `Created` for a while after it has been cut, so the status cannot
 *    be the guard — a worker that trusted it would cut again. `pretend_trendyol_is_slow` is that window, held open.
 *  - **the window between the SELECT and the UPDATE of a claim is microseconds.** It is made explicit rather than hoped for.
 *
 * **Trendyol is played by a table**, `test_trendyol_splits`, with no unique key on it: a second request is a second ROW, so a
 * double cut is visible as a count. A fixture that could not record one would make these tests pass for the wrong reason.
 *
 * Needs a real MySQL or MariaDB — SQLite in memory is private to one process, so there is nothing to race.
 */
class PackageSplitConcurrencyTest extends TestCase
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
            'capabilities' => ['connection_test', 'read_only_sync', 'order_import', 'fulfillment_dispatch'],
            'capability_approvals' => ['fulfillment_dispatch' => ['by' => 1, 'at' => '2026-11-18T00:00:00Z']],
        ])->save();
        $this->account = $this->account->fresh();
    }

    protected function tearDown(): void
    {
        if (ConcurrentRunner::available()) {
            Schema::dropIfExists('test_trendyol_splits');
        }
        parent::tearDown();
    }

    public function test_one_package_asked_to_split_by_several_processes_at_once_is_cut_once(): void
    {
        // Four runs, one package: the cron, a button, and two retries, at the same instant.
        $reports = ConcurrentRunner::run('splits.request_and_send', array_fill(0, 4, [
            'account' => $this->account->id, 'order' => 'ORDER-1', 'package' => 'PKG-1', 'lines' => [11],
        ]));

        $this->assertSame([], $this->problemsOf($reports));
        $this->assertSame(1, DB::table('test_trendyol_splits')->count(), 'ONE request reached Trendyol, whatever four processes tried');
        $this->assertSame(1, ChannelPackageSplit::count(), 'and one split was recorded');
        $this->assertSame(1, $this->workersWhere($reports, static fn (array $r): bool => ($r['requested'] ?? 0) === 1), 'exactly one process recorded it');
        $this->assertTrue(ConcurrentRunner::overlapped($reports), 'the processes really ran at the same time');
    }

    public function test_two_different_cuts_of_one_package_asked_at_once_send_only_the_first(): void
    {
        // Worse than a repeat: two DIFFERENT plans. Both landing would put line 11 in one new package and line 12 in another,
        // on top of whatever Trendyol made for the remainder -- and the second plan was written about a package id that the
        // first cut had already invalidated.
        $reports = ConcurrentRunner::run('splits.request_and_send', [
            ['account' => $this->account->id, 'order' => 'ORDER-1', 'package' => 'PKG-1', 'lines' => [11]],
            ['account' => $this->account->id, 'order' => 'ORDER-1', 'package' => 'PKG-1', 'lines' => [12]],
        ]);

        $this->assertSame([], $this->problemsOf($reports));
        $this->assertSame(1, ChannelPackageSplit::count(), 'one split, not one of each');
        $this->assertSame(1, DB::table('test_trendyol_splits')->count(), 'and ONE request');
        // Whichever won, the row and the request must agree about it: a row describing one cut while another was sent would be
        // the same failure wearing a different face.
        $row = ChannelPackageSplit::firstOrFail();
        $this->assertStringContainsString((string) $row->plan['orderLineIds'][0], (string) DB::table('test_trendyol_splits')->value('body'));
    }

    public function test_a_package_that_has_not_caught_up_yet_is_not_cut_a_second_time(): void
    {
        // **THE ASYNCHRONY, HELD OPEN.** Trendyol has taken the cut but the order still describes the package as `Created`.
        // This is the state that breaks any design using the package's own status as the guard: every later worker reads
        // "nothing happened here" and cuts again.
        $reports = ConcurrentRunner::run('splits.request_and_send', array_fill(0, 3, [
            'account' => $this->account->id, 'order' => 'ORDER-1', 'package' => 'PKG-1', 'lines' => [11],
            'pretend_trendyol_is_slow' => true,
        ]));
        $this->assertSame([], $this->problemsOf($reports));
        $this->assertSame(1, DB::table('test_trendyol_splits')->count());

        // And again later, with the package still unchanged, which is where a status-based guard fails for the second time.
        //
        // The wait is cleared rather than travelled through: each worker is its own PROCESS with its own clock, so a
        // `travelTo` here would move nobody's time but this test's, and the rows would simply not be due. Clearing
        // `available_at` is the same thing without pretending the processes share a clock.
        for ($pass = 0; $pass < 3; $pass++) {
            ChannelPackageSplit::query()->update(['available_at' => null]);
            $later = ConcurrentRunner::run('splits.request_and_send', [[
                'account' => $this->account->id, 'order' => 'ORDER-1', 'package' => 'PKG-1', 'lines' => [11],
                'pretend_trendyol_is_slow' => true, 'request' => false,
            ]]);
            $this->assertSame([], $this->problemsOf($later));
            $this->assertSame(1, (int) ($later[0]['result']['checked'] ?? 0), 'the row really was picked up and asked about');
        }

        $this->assertSame(1, DB::table('test_trendyol_splits')->count(), 'still one cut: the row decided, not the status');
        $this->assertSame(ChannelPackageSplit::MAY_HAVE_SENT, ChannelPackageSplit::firstOrFail()->state);
    }

    public function test_a_late_worker_cannot_send_a_split_another_worker_has_finished(): void
    {
        // A worker reads the sendable ids; before it reaches its claim another worker claims the same row, sends it and clears
        // the claim. Without the state in the claim condition the late worker's claim matches, its fence accepts, and the
        // package is cut twice.
        app(ChannelPackageSplits::class)->request($this->account, 'ORDER-1', 'PKG-1', PackageSplitPlan::ofLines([11]), adminId: 1);
        $this->assertSame(ChannelPackageSplit::PENDING, ChannelPackageSplit::firstOrFail()->state);

        $first = ConcurrentRunner::run('splits.request_and_send', [
            ['account' => $this->account->id, 'order' => 'ORDER-1', 'package' => 'PKG-1', 'request' => false],
        ]);
        $this->assertSame([], $this->problemsOf($first));
        $this->assertSame(1, DB::table('test_trendyol_splits')->count());

        // ... and now the late one, whose list was read while the row was still pending.
        $late = ConcurrentRunner::run('splits.send_with_a_stale_id_list', [
            ['account' => $this->account->id, 'hold_microseconds' => 1000],
        ]);

        $this->assertSame([], $this->problemsOf($late));
        $this->assertSame(0, (int) ($late[0]['result']['sent'] ?? -1), 'the late worker fenced nothing');
        $this->assertSame(1, DB::table('test_trendyol_splits')->count(), 'and exactly one request reached Trendyol, ever');
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------

    /** Trendyol, played by a table with NO unique key: a second request is a second row, so a double cut is visible. */
    private function buildTheFakeTrendyol(): void
    {
        Schema::dropIfExists('test_trendyol_splits');
        Schema::create('test_trendyol_splits', function (Blueprint $table): void {
            $table->id();
            $table->string('package', 120);
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
