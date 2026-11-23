<?php

namespace Tests\Unit\Ci;

use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\Concurrency\ConcurrentRunner;
use Tests\TestCase;

/**
 * A CI job that declares a real engine must not let the concurrency tests skip: a skipped race test is green and proves nothing.
 */
class ConcurrentRunnerEngineGuardTest extends TestCase
{
    private const GUARDED_VARIABLES = ['EXPECT_DB_DRIVER', 'CONCURRENCY_TESTS'];

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::GUARDED_VARIABLES as $variable) {
            $this->originalEnvironment[$variable] = getenv($variable);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $variable => $value) {
            putenv($value === false ? $variable : "{$variable}={$value}");
        }
        parent::tearDown();
    }

    public function test_a_job_expecting_mysql_fails_when_the_connection_is_sqlite(): void
    {
        $this->useConnection('sqlite');
        putenv('EXPECT_DB_DRIVER=mysql');
        putenv('CONCURRENCY_TESTS');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('EXPECT_DB_DRIVER=mysql');

        ConcurrentRunner::available();
    }

    public function test_a_job_expecting_mysql_fails_when_the_concurrency_tests_are_switched_off(): void
    {
        $this->useConnection('mysql');
        putenv('EXPECT_DB_DRIVER=mysql');
        putenv('CONCURRENCY_TESTS=0');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('CONCURRENCY_TESTS=0');

        ConcurrentRunner::available();
    }

    public function test_a_local_sqlite_run_is_told_the_runner_is_unavailable_so_it_can_skip(): void
    {
        $this->useConnection('sqlite');
        putenv('EXPECT_DB_DRIVER');
        putenv('CONCURRENCY_TESTS');

        $this->assertFalse(ConcurrentRunner::available());
    }

    public function test_the_sqlite_ci_job_is_told_the_runner_is_unavailable_so_it_can_skip(): void
    {
        $this->useConnection('sqlite');
        putenv('EXPECT_DB_DRIVER=sqlite');
        putenv('CONCURRENCY_TESTS');

        $this->assertFalse(ConcurrentRunner::available());
    }

    public function test_a_job_expecting_mysql_on_mysql_may_run_the_concurrency_tests(): void
    {
        $this->useConnection('mysql');
        putenv('EXPECT_DB_DRIVER=mysql');
        putenv('CONCURRENCY_TESTS');

        $this->assertTrue(ConcurrentRunner::available());
    }

    /** Only the configuration is changed; available() reads it and opens no connection. */
    private function useConnection(string $connection): void
    {
        config(['database.default' => $connection]);
    }
}
