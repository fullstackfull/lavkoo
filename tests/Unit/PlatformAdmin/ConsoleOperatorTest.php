<?php

namespace Tests\Unit\PlatformAdmin;

use App\Services\PlatformAdmin\ConsoleOperator;
use PHPUnit\Framework\TestCase;

class ConsoleOperatorTest extends TestCase
{
    public function test_it_names_the_user_the_process_runs_as_and_the_host_it_runs_on(): void
    {
        $operator = ConsoleOperator::describe();

        $this->assertSame(['user', 'host'], array_keys($operator));
        $this->assertSame((string) gethostname(), $operator['host']);
        $this->assertNotSame('', $operator['user']);
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $this->assertSame(posix_getpwuid(posix_geteuid())['name'], $operator['user'], 'the user the process runs as, not the owner of the script file');
        }
    }

    public function test_it_is_text_that_json_can_hold_and_that_is_not_long(): void
    {
        $operator = ConsoleOperator::describe();

        foreach ($operator as $value) {
            $this->assertIsString($value);
            $this->assertTrue(mb_check_encoding($value, 'UTF-8'));
            $this->assertLessThanOrEqual(64, mb_strlen($value));
        }
        $this->assertNotFalse(json_encode($operator));
    }
}
