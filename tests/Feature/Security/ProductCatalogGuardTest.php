<?php

namespace Tests\Feature\Security;

use Illuminate\Database\Connection;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BuildsProductCatalog;
use Tests\TestCase;

/**
 * The catalog tests drop the store tables before they build them. On in-memory SQLite that is a database of their own, but a real engine
 * keeps its tables, so it is touched only when it is unmistakably a throwaway one: the name says "test" and the environment is "testing".
 */
class ProductCatalogGuardTest extends TestCase
{
    public static function throwawayDatabases(): array
    {
        return [
            'the CI database' => ['pharmacy_testing'],
            'a suffix' => ['lavkoo_test'],
            'a prefix' => ['test_lavkoo'],
            'the bare word' => ['test'],
            'plural, in capitals' => ['LAVKOO_TESTS'],
        ];
    }

    public static function realDatabases(): array
    {
        return [
            'production' => ['lavkoo'],
            'staging' => ['lavkoo_staging'],
            'a name that merely contains the letters' => ['latest'],
            'another one' => ['contest_entries'],
            'a word that starts with them' => ['testimonials'],
            'no name' => [''],
        ];
    }

    #[DataProvider('throwawayDatabases')]
    public function test_a_throwaway_database_may_have_its_catalog_tables_rebuilt(string $database): void
    {
        $this->catalog()->refuseToDropCatalogTablesOutsideATestDatabase(connection: $this->connection(driver: 'mysql', database: $database));

        $this->addToAssertionCount(1);
    }

    #[DataProvider('realDatabases')]
    public function test_a_database_that_is_not_unmistakably_a_test_database_is_refused_before_anything_is_dropped(string $database): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Refusing to drop the catalog tables of '{$database}'");

        $this->catalog()->refuseToDropCatalogTablesOutsideATestDatabase(connection: $this->connection(driver: 'mysql', database: $database));
    }

    public function test_a_real_engine_is_refused_outside_the_testing_environment_even_when_its_name_says_test(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);

        $this->catalog()->refuseToDropCatalogTablesOutsideATestDatabase(connection: $this->connection(driver: 'mysql', database: 'pharmacy_testing'));
    }

    public function test_the_in_memory_sqlite_database_is_always_rebuilt(): void
    {
        $this->catalog()->refuseToDropCatalogTablesOutsideATestDatabase(connection: $this->connection(driver: 'sqlite', database: ':memory:'));

        $this->addToAssertionCount(1);
    }

    private function catalog(): object
    {
        return new class {
            use BuildsProductCatalog {
                refuseToDropCatalogTablesOutsideATestDatabase as public;
            }
        };
    }

    private function connection(string $driver, string $database): Connection
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn($driver);
        $connection->shouldReceive('getDatabaseName')->andReturn($database);

        return $connection;
    }
}
