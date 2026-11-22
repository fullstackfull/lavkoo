<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Tests that create and drop the store's own tables run it first: a database whose name does not say "test", or an environment that is not
 * `testing`, is refused outright. The in-memory SQLite database of phpunit.xml is always disposable.
 */
trait RefusesNonTestDatabases
{
    protected function refuseUnlessDisposableTestDatabase(): void
    {
        $connection = DB::connection();
        $database = (string) $connection->getDatabaseName();
        $inMemory = $connection->getDriverName() === 'sqlite' && $database === ':memory:';

        if (!app()->environment('testing') || (!$inMemory && !preg_match('/(^|_)test(s|ing)?($|_)/i', $database))) {
            throw new RuntimeException("Refusing to drop tables of '{$database}': the test database name must contain 'test' and APP_ENV must be 'testing'.");
        }
    }
}
