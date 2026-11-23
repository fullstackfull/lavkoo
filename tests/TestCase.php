<?php

namespace Tests;

use App\Services\Address\AddressSchema;
use App\Services\Fulfillment\FulfillmentSchema;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontVisibility;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /** Every test builds its own database, so what the schema classes remembered about the previous one must not carry over. */
    protected function setUp(): void
    {
        parent::setUp();

        FulfillmentSchema::flush();
        AddressSchema::flush();
        StorefrontCatalog::flush();

        $this->startFromAnEmptyRealDatabase();
        StorefrontVisibility::flush();
    }

    /**
     * SQLite in memory is empty for every test. A real server keeps what the last test built, so a plain `Schema::create` of `users` or
     * `business_settings` meets a table that is already there and every test after the first of that class fails, and the application, which
     * booted before this runs, may have cached the settings of that leftover. Only a database that is unmistakably a throwaway one is touched:
     * its name says test and APP_ENV is testing.
     */
    private function startFromAnEmptyRealDatabase(): void
    {
        $connection = (string) config('database.default');
        if (config("database.connections.{$connection}.driver") === 'sqlite') {
            return;
        }

        $database = (string) config("database.connections.{$connection}.database");
        if (app()->environment('testing') && preg_match('/(^|_)test(s|ing)?($|_)/i', $database) === 1) {
            Schema::dropAllTables();
            Cache::flush();
        }
        StorefrontVisibility::flush();
    }
}
