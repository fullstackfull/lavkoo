<?php

namespace Tests\Unit\SalesChannels;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every operation name a concurrency test asks for is actually registered — checked WITHOUT a database.
 *
 * The concurrency tests need a real MySQL or MariaDB and skip on SQLite, so a misspelt or unregistered operation name is
 * invisible locally and only shows up in CI, after the whole engine job has run. That is how `noon.page` reached CI: it was
 * passed to `ConcurrentRunner::run()` as though it were a generic dispatcher, nothing had registered it, and sixteen workers
 * each failed with `Unknown concurrent operation` thirty-seven minutes into a MariaDB job.
 *
 * This test reads the sources instead. It needs no engine, so it fails in seconds on any machine.
 *
 * It reads text rather than booting the registry on purpose: the registrations are loaded by the worker process from
 * `registrations/*.php`, and that loading is itself the thing that could be missing.
 */
class ConcurrentOperationsAreRegisteredTest extends TestCase
{
    /** Names the harness itself answers in `Operations::run()`'s match, so no registration file needs to name them. */
    private const BUILT_IN = [
        'probe_insert',
        'dispatch_channel_order',
        'mirror_managed_shipment',
        'layers_from_purchase_orders',
        'layer_purchase_order_tranche',
        'stock.ingest_many',
    ];

    /**
     * Names a test asks for ON PURPOSE without registering them.
     *
     * `ConcurrencyHarnessTest` runs an unknown operation deliberately, to prove the harness reports one worker's failure without
     * hiding the others. Excluding it by name keeps that test honest and keeps this guard honest: anything else unregistered is
     * a mistake.
     */
    private const DELIBERATELY_UNREGISTERED = ['no_such_operation'];

    public function test_every_operation_a_concurrency_test_runs_is_registered_or_built_in(): void
    {
        $registered = [...self::BUILT_IN, ...self::DELIBERATELY_UNREGISTERED, ...$this->registeredNames()];
        $asked = $this->namesAskedForByTests();

        $this->assertNotEmpty($asked, 'the scan found no operation names, so it is not checking anything');
        $this->assertSame(
            [],
            array_values(array_diff($asked, $registered)),
            'a concurrency test asks for an operation nothing registers; it would fail only on a real engine, in CI',
        );
    }

    public function test_the_scan_sees_the_names_it_claims_to(): void
    {
        // A guard that cannot see is worse than none: it reports success for a suite it never read.
        $asked = $this->namesAskedForByTests();

        $this->assertContains('noon.ingest_same_order', $asked);
        $this->assertContains('noon.page', $asked);
        $this->assertContains('stock.mixed', $asked);
    }

    public function test_every_registration_file_is_loadable_php(): void
    {
        // The worker requires each of these at run time. One with a syntax error takes every concurrency test with it.
        foreach ($this->registrationFiles() as $file) {
            $this->assertNotFalse(
                @token_get_all((string) file_get_contents($file), TOKEN_PARSE),
                basename($file) . ' is not parseable, and the worker requires it',
            );
        }
    }

    /** @return list<string> every name passed to Operations::register() in a registration file */
    private function registeredNames(): array
    {
        $names = [];
        foreach ($this->registrationFiles() as $file) {
            preg_match_all("/Operations::register\(\s*'([^']+)'/", (string) file_get_contents($file), $matches);
            foreach ($matches[1] as $name) {
                $names[] = $name;
            }
        }
        // StockOperations::mixed and the noon dispatcher are registered in files; anything registered inside a test method is
        // out of scope here, because the worker process would not see it either.
        return array_values(array_unique($names));
    }

    /** @return list<string> every name a concurrency test hands to ConcurrentRunner::run() or names as a worker's operation */
    private function namesAskedForByTests(): array
    {
        $names = [];
        foreach ($this->concurrencyTestFiles() as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all("/ConcurrentRunner::run\(\s*'([^']+)'/", $source, $direct);
            preg_match_all("/'operation'\s*=>\s*'([^']+)'/", $source, $perWorker);
            foreach ([...$direct[1], ...$perWorker[1]] as $name) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /** @return list<string> */
    private function registrationFiles(): array
    {
        return array_values(array_filter((array) glob($this->path('tests/Support/Concurrency/registrations/*.php'))));
    }

    /** @return list<string> */
    private function concurrencyTestFiles(): array
    {
        $files = [];
        $directory = $this->path('tests/Feature/Concurrency');
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), 'Test.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . $relative;
    }
}
