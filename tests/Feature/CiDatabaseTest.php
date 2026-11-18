<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proves a CI job is testing against the database it claims to. The MySQL job exists because SQLite cannot vouch
 * for unique keys, locking, collation, JSON or decimal behaviour; if a configuration slip silently ran it on
 * SQLite it would be green and worthless.
 */
class CiDatabaseTest extends TestCase
{
    public function test_the_suite_runs_on_the_database_the_ci_job_declares(): void
    {
        $expected = getenv('EXPECT_DB_DRIVER');
        if ($expected === false || $expected === '') {
            $this->markTestSkipped('EXPECT_DB_DRIVER is only set by CI; local runs use in-memory SQLite.');
        }

        $this->assertSame($expected, DB::connection()->getDriverName());

        if ($expected === 'mysql') {
            // The job names the engine it runs on (EXPECT_DB_VERSION, a pattern without delimiters: MySQL 8 or the MariaDB the pilot server runs); without it MySQL 8 is expected, as before.
            $declared = (string) getenv('EXPECT_DB_VERSION');
            $this->assertMatchesRegularExpression('/' . ($declared !== '' ? $declared : '^8\.') . '/', (string) DB::selectOne('select version() as version')->version);
            $this->assertStringContainsString('test', (string) DB::connection()->getDatabaseName());
        }
    }
}
