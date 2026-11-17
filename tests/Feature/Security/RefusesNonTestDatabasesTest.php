<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\RefusesNonTestDatabases;
use Tests\TestCase;

/**
 * The seller API tests create the store's own tables and drop them first, so they must run the guard before any drop: only the in-memory
 * database of the testing environment, or a database whose name says "test", is disposable. No query is sent to the database here, so a
 * database that is not disposable is only named, never touched.
 */
class RefusesNonTestDatabasesTest extends TestCase
{
    use RefusesNonTestDatabases;

    /** @return iterable<string, array{0: string}> */
    public static function databasesThatAreNotDisposable(): iterable
    {
        foreach (['lavkoo', 'pharmacy_production', 'shop_latest', 'contest', 'testimony'] as $name) {
            yield $name => [$name];
        }
    }

    /** @return iterable<string, array{0: string}> */
    public static function databasesThatAreDisposable(): iterable
    {
        foreach (['test', 'wf_pr52_test', 'shop_tests', 'app_testing', 'test_orders'] as $name) {
            yield $name => [$name];
        }
    }

    public function test_the_in_memory_database_of_the_testing_environment_is_disposable(): void
    {
        $this->refuseUnlessDisposableTestDatabase();

        $this->assertSame('testing', $this->app->environment());
    }

    #[DataProvider('databasesThatAreNotDisposable')]
    public function test_a_database_whose_name_does_not_say_test_is_refused(string $database): void
    {
        $this->nameTheMysqlDatabase($database);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Refusing to drop tables of '{$database}'");

        $this->refuseUnlessDisposableTestDatabase();
    }

    #[DataProvider('databasesThatAreDisposable')]
    public function test_a_database_whose_name_says_test_is_disposable(string $database): void
    {
        $this->nameTheMysqlDatabase($database);

        $this->refuseUnlessDisposableTestDatabase();

        $this->assertSame($database, DB::connection()->getDatabaseName());
    }

    public function test_a_database_file_is_refused_even_in_the_testing_environment(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => '/var/www/storage/shop.sqlite']);
        DB::purge();

        $this->expectException(RuntimeException::class);

        $this->refuseUnlessDisposableTestDatabase();
    }

    public function test_the_testing_environment_is_required_even_for_a_database_that_says_test(): void
    {
        $this->nameTheMysqlDatabase('lavkoo_test');
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);

        $this->refuseUnlessDisposableTestDatabase();
    }

    private function nameTheMysqlDatabase(string $database): void
    {
        config(['database.default' => 'mysql', 'database.connections.mysql.database' => $database]);
        DB::purge();
    }
}
