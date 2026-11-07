<?php

namespace Tests\Feature\Concurrency;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Concurrency\ConcurrentRunner;
use Tests\TestCase;

/** The harness itself: processes really run at the same time against the same database, and what each one did is reported. Needs a real MySQL or MariaDB. */
class ConcurrencyHarnessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process.');
        }
        Schema::dropIfExists('concurrency_probe');
        Schema::create('concurrency_probe', function (Blueprint $table): void {
            $table->id();
            $table->string('k')->unique();
            $table->unsignedInteger('writer');
        });
    }

    protected function tearDown(): void
    {
        if (ConcurrentRunner::available()) {
            Schema::dropIfExists('concurrency_probe');
        }
        parent::tearDown();
    }

    public function test_processes_inserting_the_same_unique_key_at_once_give_exactly_one_winner(): void
    {
        $reports = ConcurrentRunner::run('probe_insert', array_fill(0, 6, ['key' => 'same', 'hold_microseconds' => 80000]));

        $results = array_column($reports, 'result');
        $this->assertCount(6, $reports);
        $this->assertSame(1, count(array_keys($results, 'inserted', true)));
        $this->assertSame(5, count(array_keys($results, 'duplicate', true)));
        $this->assertSame(1, DB::table('concurrency_probe')->count());
        $this->assertCount(6, array_unique(array_column($reports, 'pid')), 'six different processes');
        $this->assertTrue(ConcurrentRunner::overlapped($reports), 'the processes ran at the same time');
    }

    public function test_an_operation_that_fails_is_reported_and_does_not_hide_the_others(): void
    {
        $reports = ConcurrentRunner::run('no_such_operation', [['x' => 1], ['x' => 2]]);

        $this->assertFalse($reports[0]['ok']);
        $this->assertSame(\InvalidArgumentException::class, $reports[0]['error']);
        $this->assertCount(2, $reports);
    }
}
