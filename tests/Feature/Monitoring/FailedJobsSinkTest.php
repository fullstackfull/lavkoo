<?php

namespace Tests\Feature\Monitoring;

use App\Services\Telemetry\SystemHealthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A queued job that has exhausted its tries is recorded, and `failed_jobs` is what records it.
 *
 * `config/queue.php` has pointed the failed-job driver at the database since long before the GCC work, and three readers expect the table:
 * `SystemHealthService::queue()`, `QueueCollector` and `FulfillmentPreflight`. No migration of this repository created it, and two test
 * classes built it by hand to get their work done -- which is the shape of a gap nobody owned.
 *
 * **What the gap actually cost, measured here rather than asserted:** the worker's attempt to RECORD a failure fails too, so the job is
 * gone with no trace anywhere. That is the severe half and the first test pins it.
 *
 * The monitoring half is smaller than it looks and is stated as it is: `SystemHealthService::snapshot()` wraps each section in a probe, so
 * a missing table makes the queue section read `['error' => 'QueryException']` -- broken, and honestly broken. It does NOT report zero
 * failures. A guess that it showed a reassuring zero would have been the more frightening story and the wrong one.
 */
class FailedJobsSinkTest extends TestCase
{
    private const MIGRATION = 'migrations/2026_12_30_100000_create_failed_jobs_table.php';

    private const PAYLOAD = '{"uuid":"a-job","displayName":"App\\\\Jobs\\\\Fulfillment\\\\RouteOrderFulfillment"}';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
        parent::tearDown();
    }

    private function migration(): object
    {
        return require database_path(self::MIGRATION);
    }

    /**
     * The depth half of the queue section, which `SystemHealthService::queue()` reads beside the failures.
     *
     * `require_once` and an explicit `new`, NOT `require`: 2022_04_19_095926_create_jobs_table.php predates anonymous migration classes
     * and declares `CreateJobsTable`, so requiring it a second time in one process is a redeclaration -- a fatal error that takes the whole
     * run down rather than failing a test. Any helper that loads a migration of that vintage has to do it this way.
     */
    private function buildTheJobsTable(): void
    {
        require_once database_path('migrations/2022_04_19_095926_create_jobs_table.php');
        (new \CreateJobsTable())->up();
    }

    /** Records a failure the way the queue worker does, through the framework's own provider rather than a hand-written insert. */
    private function recordAFailure(string $exception = 'RuntimeException: the provider refused'): void
    {
        app('queue.failer')->log('database', 'default', self::PAYLOAD, $exception);
    }

    public function test_without_the_table_a_failure_cannot_be_recorded_and_the_job_is_simply_gone(): void
    {
        $this->assertFalse(Schema::hasTable('failed_jobs'), 'the point of this test is the state the repository shipped in');

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->recordAFailure();
    }

    public function test_the_queue_section_of_the_health_page_reports_an_error_and_not_a_reassuring_zero(): void
    {
        $queue = app(SystemHealthService::class)->snapshot()['queue'];

        $this->assertArrayHasKey('error', $queue, 'a missing sink must not be reported as a healthy queue');
        $this->assertArrayNotHasKey('failed_total', $queue);
    }

    public function test_the_migration_creates_the_columns_the_configured_driver_writes(): void
    {
        $this->migration()->up();

        $this->assertTrue(Schema::hasTable('failed_jobs'));

        // The five the `database` driver writes, which is what config/queue.php resolves to by default.
        foreach (['connection', 'queue', 'payload', 'exception', 'failed_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('failed_jobs', $column), "the driver writes {$column}");
        }
    }

    public function test_with_the_table_the_failure_is_recorded_whole(): void
    {
        $this->migration()->up();

        $this->recordAFailure();

        $row = DB::table('failed_jobs')->first();
        $this->assertNotNull($row, 'the failure must survive the worker giving up');
        $this->assertSame('database', $row->connection);
        $this->assertSame('default', $row->queue);
        $this->assertStringContainsString('RouteOrderFulfillment', $row->payload, 'which job failed is the first thing anyone asks');
        $this->assertStringContainsString('the provider refused', $row->exception);
        $this->assertNotNull($row->failed_at);
    }

    public function test_the_health_page_counts_the_failure_once_the_sink_exists(): void
    {
        $this->migration()->up();
        $this->buildTheJobsTable();
        $this->recordAFailure();

        $queue = app(SystemHealthService::class)->snapshot()['queue'];

        $this->assertArrayNotHasKey('error', $queue);
        $this->assertSame(1, $queue['failed_total']);
        $this->assertSame(1, $queue['failed_24h']);
    }

    public function test_a_failure_older_than_a_day_is_in_the_total_and_outside_the_window(): void
    {
        $this->migration()->up();
        $this->buildTheJobsTable();
        $this->recordAFailure();
        DB::table('failed_jobs')->update(['failed_at' => now()->subDays(3)]);

        $queue = app(SystemHealthService::class)->snapshot()['queue'];

        $this->assertSame(1, $queue['failed_total']);
        $this->assertSame(0, $queue['failed_24h'], 'the window must read the column rather than count every row');
    }

    public function test_the_uuid_driver_records_too_so_the_operators_choice_cannot_lose_a_failure(): void
    {
        $this->migration()->up();
        config(['queue.failed.driver' => 'database-uuids']);

        app('queue.failer')->log('database', 'default', self::PAYLOAD, 'RuntimeException: refused again');

        $row = DB::table('failed_jobs')->first();
        $this->assertNotNull($row, 'QUEUE_FAILED_DRIVER is an operator setting, so both drivers Laravel offers must land somewhere');
        $this->assertNotNull($row->uuid, 'the uuid driver writes one, which is why the column is there and nullable');
    }

    public function test_a_failure_stamped_past_2038_survives_the_round_trip(): void
    {
        $this->migration()->up();
        $this->recordAFailure();
        $beyondTheTimestampRange = '2045-06-01 12:00:00';

        DB::table('failed_jobs')->update(['failed_at' => $beyondTheTimestampRange]);

        $stored = (string) DB::table('failed_jobs')->value('failed_at');
        // Teeth measured on both engines, because they disagree about whether this test means anything: swapping dateTime() for
        // timestamp() in the migration fails exactly this test on MariaDB 10.11 and changes NOTHING on SQLite, which keeps dates as text
        // and cannot tell the two column types apart. So this assertion is only load-bearing on a real engine -- which is why
        // tests/Feature/Monitoring belongs in an engine group and not on SQLite alone.
        $this->assertStringStartsWith('2045-06-01', $stored, 'failed_at is DATETIME: a TIMESTAMP column would have clamped or zeroed this');
    }

    public function test_up_can_be_run_again_after_a_half_applied_deploy(): void
    {
        $migration = $this->migration();
        $migration->up();
        $this->recordAFailure();

        $migration->up();

        $this->assertSame(1, DB::table('failed_jobs')->count(), 'a second up() must neither fail nor drop what the first recorded');
    }
}
