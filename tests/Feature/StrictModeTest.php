<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** DB_STRICT_TESTS=1 really switches the test connection to strict mode, so the strict runs of the suites mean something. */
class StrictModeTest extends TestCase
{
    private function strictRun(): bool
    {
        return getenv('DB_STRICT_TESTS') === '1' && in_array(config('database.default'), ['mysql', 'mariadb'], true);
    }

    public function test_the_strict_run_uses_a_strict_sql_mode(): void
    {
        if (!$this->strictRun()) {
            $this->markTestSkipped('Only in the strict-mode run (DB_STRICT_TESTS=1 on MySQL/MariaDB).');
        }

        $mode = (string) DB::selectOne('select @@session.sql_mode as mode')->mode;

        $this->assertStringContainsString('STRICT_TRANS_TABLES', $mode);
        $this->assertStringContainsString('ONLY_FULL_GROUP_BY', $mode);
    }

    public function test_the_strict_run_rejects_a_value_longer_than_its_column(): void
    {
        if (!$this->strictRun()) {
            $this->markTestSkipped('Only in the strict-mode run (DB_STRICT_TESTS=1 on MySQL/MariaDB).');
        }
        Schema::dropIfExists('strict_probe');
        Schema::create('strict_probe', function (Blueprint $table): void {
            $table->id();
            $table->string('short', 5);
        });

        try {
            $this->expectException(QueryException::class);
            DB::table('strict_probe')->insert(['short' => 'far too long for the column']);
        } finally {
            Schema::dropIfExists('strict_probe');
        }
    }
}
