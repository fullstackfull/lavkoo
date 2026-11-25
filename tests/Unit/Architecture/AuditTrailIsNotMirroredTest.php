<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * No test stands in for the audit trail by SUBCLASSING `AuditLogger`; it builds the table and reads the line.
 *
 * `CrossBorderReturnTest` used to spy on the trail with an anonymous class extending `AuditLogger`, overriding `record()` to collect the
 * action and the `after` payload. That override had to repeat all five of `record()`'s parameters to be a compatible declaration, and it
 * repeated them for one reason: PHP requires it, not because the test wanted any of them.
 *
 * Then `record()` gained `?string $country = null`, so an audit line could say which market it concerns (G-313 to G-316). The mirrored
 * signature was now narrower than its parent, which PHP refuses AT CLASS LOAD -- so it was not one failing test. The declaration error
 * aborted the whole process the moment PHPUnit loaded that file, taking the other 20,000 tests with it, and it did so in every job that
 * reached the file: both SQLite runs, the strict-mode run and the fulfillment group. A change to an audit parameter is not supposed to be
 * able to do that.
 *
 * The suite already had the answer everywhere else. `Tests\Support\BuildsAuditLogTable` creates the real `audit_logs`, the real service
 * writes to it, and the test reads the row -- which also tests more than the double did, because the double could not have caught a line
 * the service failed to persist. Nothing to keep in step, so a sixth parameter breaks nothing.
 *
 * Subclassing a service in a test is fine here and several tests do it. What this forbids is narrower: mirroring the signature of the one
 * service that nearly every audited action is handed, whose parameter list is still growing, when a builder for its table exists.
 */
class AuditTrailIsNotMirroredTest extends TestCase
{
    private const SERVICE = 'AuditLogger';

    private const BUILDER = 'BuildsAuditLogTable';

    /** The repository root, resolved the way the other architecture tests do: these run without a booted application. */
    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return list<string> every file under tests/, relative to the root */
    private function testFiles(): array
    {
        $found = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root() . '/tests'));

        foreach ($walk as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $found[] = ltrim(str_replace($this->root(), '', $file->getPathname()), '/');
            }
        }

        sort($found);

        return $found;
    }

    public function test_no_test_subclasses_the_audit_logger(): void
    {
        $mirrors = array_values(array_filter(
            $this->testFiles(),
            fn (string $file): bool => (bool) preg_match(
                '/extends\s+' . self::SERVICE . '\b/',
                (string) file_get_contents($this->root() . '/' . $file),
            ),
        ));

        $this->assertSame([], $mirrors, sprintf(
            "%s subclasses %s. An override of record() has to repeat every parameter the service has, and PHP refuses the declaration at "
            . "class load when the service gains one -- which aborts the whole suite, not one test. Use the %s trait and assert on the "
            . 'audit_logs row instead.',
            implode(', ', $mirrors),
            self::SERVICE,
            self::BUILDER,
        ));
    }

    public function test_the_trait_that_replaces_it_exists_and_creates_the_table(): void
    {
        $trait = (string) file_get_contents($this->root() . '/tests/Support/' . self::BUILDER . '.php');

        $this->assertStringContainsString('trait ' . self::BUILDER, $trait);
        $this->assertStringContainsString("Schema::create('audit_logs'", $trait, 'the guard points tests at a builder that must build the real table');
    }
}
