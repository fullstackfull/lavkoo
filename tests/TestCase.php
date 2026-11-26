<?php

namespace Tests;

use App\Services\Address\AddressSchema;
use App\Services\AuditLogger;
use App\Services\Staff\StaffCountryScope;
use App\Services\Fulfillment\FulfillmentSchema;
use App\Services\Shipping\CrossBorderPolicy;
use App\Services\Shipping\OrderCustomsQuote;
use App\Services\Shipping\OrderShippingDecision;
use App\Services\Shipping\ProductDestinationPermission;
use App\Services\Shipping\RegulatedShippingGate;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontVisibility;
use App\Services\Theme\LocalisedSettings;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RestoresLanguageFiles;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
    use RestoresLanguageFiles;

    /** Every test builds its own database, so what the schema classes remembered about the previous one must not carry over. */
    protected function setUp(): void
    {
        parent::setUp();

        FulfillmentSchema::flush();
        AddressSchema::flush();
        StorefrontCatalog::flush();
        // The shop's installed language list, memoised statically because `collapse()` is called once per section of every page. A test that
        // builds its own `business_settings` would otherwise be answered the list of whichever test built one before it -- which is the same
        // class of leak as the schema memos above, and showed up as a storefront list reporting another test's languages.
        LocalisedSettings::forget();

        // The shipping services remember what they read about the schema, because a cart of twenty lines must not ask the database twenty
        // times whether a column exists. On SQLite each test starts from an empty database and the question is asked again; on a real
        // server the process outlives the schema -- every test drops the tables and builds its own -- so an answer from the test before
        // would have this test reading a column that is no longer there. Cleared here rather than in each test, because a test that builds
        // an orders table should not have to know which services cached a fact about it.
        // The audit trail reads once per process whether it carries the market columns; every test builds its own table, so a leftover
        // answer would have a line written against a column that is no longer there.
        AuditLogger::forget();
        StaffCountryScope::forgetTheSchema();
        OrderShippingDecision::forget();
        OrderCustomsQuote::forget();
        ProductDestinationPermission::forget();
        RegulatedShippingGate::forget();
        CrossBorderPolicy::forget();

        $this->startFromAnEmptyRealDatabase();
        StorefrontVisibility::flush();

        // Before the test as well as after it: PHPUnit does not call tearDown when setUp itself fails, so a test that rendered a page and
        // then threw would otherwise leave its key behind for good. Reading what the files are is stat-only once the process has its copy.
        $this->rememberLanguageFiles();
        $this->restoreLanguageFiles();
    }

    /**
     * The tracked language files are put back after EVERY test, not only after the ones that elected to remember them.
     *
     * translate() appends a humanised placeholder for any key it does not know, and 892 of this suite's test files carried no such
     * election, so a run left keys in resources/lang that look exactly like a key a real feature added -- which is the cost that matters:
     * a genuinely new translation key becomes indistinguishable from an artifact of a test run. Measured before this was written:
     * tests/Feature/Address/ApiAddressCrudTest alone added three.
     *
     * The restore is unconditional, because a gate that depends on a switch is not a gate. `gcc.tests.language_file_writes_are_failures`
     * decides only whether the test that wrote is also FAILED, which is how the offending test is found; off, which is how it ships, the
     * write is put back in silence and nothing about the suite changes but the state of the working tree.
     */
    protected function tearDown(): void
    {
        $written = $this->keysWrittenToTheLanguageFiles();
        $this->restoreLanguageFiles();
        $failures = $written !== [] && (bool) config('gcc.tests.language_file_writes_are_failures') ? $written : [];

        parent::tearDown();

        foreach ($failures as $path => $keys) {
            $this->fail(
                'This test let translate() write ' . count($keys) . ' key(s) into ' . basename(dirname($path)) . '/'
                . basename($path) . ': ' . implode(', ', $keys)
                . "\n\nA key a test invents is indistinguishable from one a feature added. Give the key a text in the language file if"
                . ' the feature needs it, or stop the test rendering it.',
            );
        }
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
