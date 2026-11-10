<?php

namespace Tests\Unit\Ci;

use PHPUnit\Framework\TestCase;

/**
 * The CI matrix runs `tests/Feature/SalesChannels` in two halves, and this proves the halves cover all of it.
 *
 * ## Why the split exists
 *
 * That directory outgrew a runner's hour and a half for the third time: the `MySQL 8.0 (sales channels)` job ran from
 * 01:05:27 to 02:55:43 — **exactly the 110-minute `timeout-minutes`** — and was killed with "Terminate orphan process:
 * pid (php)". GitHub reports a job killed by its own timeout as `cancelled`, which is the failure mode the workflow's own
 * comment warns about: a slow suite then looks exactly like a broken one, and no number of re-runs gives the head a
 * complete answer.
 *
 * ## Why THIS form of split, after two wrong ones
 *
 *  - **By channel** ("Amazon here, the rest there") is quietly unsafe. A new test file matching neither list would never
 *    run, on either engine, and nothing would say so. A test that never runs is worse than a test that fails.
 *  - **By file glob** (`SalesChannels/[A-M]*.php`) failed outright, 45 seconds in: the directory holds abstract base
 *    classes, and phpunit handed a file list tries to load each as a test and dies on the first one — "Class
 *    Tests\Feature\SalesChannels\AdminPagesTestCase ... is abstract". Handing it the directory lets its own discovery
 *    skip them, which is why the matrix still passes the directory and narrows with `--filter`.
 *  - **By class name, with `--filter`**, is what it does now. Provable, and this file is the proof.
 *
 * ## And why it lives in tests/Unit rather than beside what it describes
 *
 * It reads two files and compares them: no database, no HTTP, nothing to set up. More to the point, the first version of
 * this guard lived in `tests/Feature/SalesChannels` and **adding it there changed the order that directory runs in**, which
 * exposed a pre-existing order-dependence in four dispatch test classes and turned two green SQLite jobs red. The
 * dependence is a real defect and is recorded in docs/sales-channels/gap-matrix.md; this guard has no business being the
 * thing that triggers it.
 */
class CiSalesChannelSplitCoversEveryTestTest extends TestCase
{
    /** The two `--filter` regexes the matrix gives the `sales channels A-M` and `sales channels N-Z` jobs. */
    private const HALVES = [
        '/^Tests.Feature.SalesChannels.[A-M]/',
        '/^Tests.Feature.SalesChannels.[N-Z]/',
    ];

    private const DIRECTORY = 'tests/Feature/SalesChannels';

    public function test_the_two_halves_cover_every_test_class_exactly_once(): void
    {
        $classes = $this->testClassNames();
        $this->assertNotEmpty($classes, 'no test classes were found, so this guard proved nothing');

        $uncovered = [];
        $doubled = [];
        foreach ($classes as $class) {
            $matched = 0;
            foreach (self::HALVES as $half) {
                if (preg_match($half, $class) === 1) {
                    $matched++;
                }
            }
            if ($matched === 0) {
                $uncovered[] = $class;
            }
            if ($matched > 1) {
                $doubled[] = $class;
            }
        }

        $this->assertSame(
            [],
            $uncovered,
            "These test classes run in NEITHER half of the CI matrix, so they run on no real engine and nothing else would "
            . "have said so:\n  " . implode("\n  ", $uncovered)
            . "\n\nThe halves are " . implode(' and ', self::HALVES) . ' — a class whose name starts with a digit, an '
            . 'underscore or a lower-case letter falls outside both.',
        );
        // Overlap wastes a runner rather than losing coverage, but it also means the halves are not a partition — and the
        // next edit could turn the overlap into a gap.
        $this->assertSame([], $doubled, 'These test classes run in BOTH halves: ' . implode(', ', $doubled));
    }

    public function test_the_workflow_still_asks_for_the_halves_this_guard_checks(): void
    {
        // Worthless if it checks filters CI no longer uses, so it reads the workflow itself.
        $workflow = (string) file_get_contents($this->base('.github/workflows/ci.yml'));

        foreach (self::HALVES as $half) {
            $this->assertStringContainsString(
                "--filter '" . $half . "'",
                $workflow,
                "The matrix no longer narrows with '{$half}', so this guard checks something CI does not do. Either put the "
                . 'filter back or update HALVES to what the matrix now uses — and keep them a partition of the directory.',
            );
        }

        // And the directory must still be handed to phpunit, because that is what makes its discovery skip the abstract
        // base classes. A file list here is the mistake that failed in 45 seconds.
        $this->assertStringContainsString('paths: ' . self::DIRECTORY . ' --filter', $workflow, 'the matrix no longer hands phpunit the directory');
        $this->assertStringNotContainsString(self::DIRECTORY . '/[', $workflow, 'the matrix is back to a file glob, which dies on the abstract base classes');
    }

    /**
     * Every concrete test class in the directory, by its fully-qualified name — which is what `--filter` matches on.
     *
     * Abstract classes are left out because phpunit's own discovery leaves them out; counting them would make this guard
     * demand coverage of something that is never a test.
     *
     * @return list<string>
     */
    private function testClassNames(): array
    {
        $classes = [];
        foreach (glob($this->base(self::DIRECTORY . '/*.php')) ?: [] as $path) {
            $source = (string) file_get_contents($path);
            if (preg_match('/^abstract\s+class\s/m', $source) === 1) {
                continue;
            }
            if (preg_match('/^class\s+(\w+)/m', $source, $found) !== 1) {
                continue;
            }
            $classes[] = 'Tests\\Feature\\SalesChannels\\' . $found[1];
        }

        return $classes;
    }

    private function base(string $path): string
    {
        return dirname(__DIR__, 3) . '/' . $path;
    }
}
