<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The check a test helper has to make before it drops anything.
 *
 * phpunit.xml pins an in-memory SQLite database that is built and thrown away for each test, so there is
 * nothing there to protect. phpunit.mysql.xml deliberately leaves DB_DATABASE to the environment, so on a
 * real engine the connection points at whatever the person running the suite exported — which may be a
 * restored copy of the shop, where `addon_settings` holds every gateway's encrypted credentials. A
 * database whose name does not say "test", or an environment that is not `testing`, is refused outright.
 */
trait RefusesToTouchALiveDatabase
{
    protected function refuseUnlessTheDatabaseIsThrowaway(): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() === 'sqlite') {
            return;
        }

        $database = (string) $connection->getDatabaseName();

        if (!app()->environment('testing') || !preg_match('/(^|_)test(s|ing)?($|_)/i', $database)) {
            throw new RuntimeException("Refusing to drop tables in '{$database}': the test database name must contain 'test' and APP_ENV must be 'testing'.");
        }
    }
}
