<?php

namespace Tests\Feature\SalesChannels;

use Tests\TestCase;

/**
 * The CI matrix splits this directory in two, and this proves the two halves cover all of it.
 *
 * ## Why a guard and not just a careful list
 *
 * `tests/Feature/SalesChannels` outgrew a runner's hour and a half for the third time: on the Amazon-feed branch the
 * `MySQL 8.0 (sales channels)` job ran from 01:05:27 to 02:55:43 — **exactly the 110-minute `timeout-minutes`** — and was
 * killed with "Terminate orphan process: pid (php)". GitHub reports a job killed by its own timeout as `cancelled`, which is
 * the failure mode the workflow's own comment warns about: a slow suite then looks exactly like a broken one, and no number
 * of re-runs gives the head a complete answer.
 *
 * So it is split. The obvious way to split it is by channel — one job for Amazon, one for the rest — and that way is quietly
 * unsafe: a new test file matching neither list would simply never run, on either engine, and **nothing would say so**. A
 * test that never runs is worse than a test that fails.
 *
 * Splitting on the first letter of the file name is safe for one reason only: the partition is provable, and this file
 * proves it. If the globs and the directory ever disagree, this fails — on SQLite, in every job, long before anybody reads
 * a matrix.
 *
 * Nothing here knows about MySQL or about CI. It reads the workflow file and the directory and compares them, so the
 * assertion is about the two things that have to agree rather than about a copy of either.
 */
class CiMatrixCoversEverySalesChannelTestTest extends TestCase
{
    /** The two globs the matrix gives the `sales channels A-M` and `sales channels N-Z` jobs. */
    private const HALVES = ['tests/Feature/SalesChannels/[A-M]*.php', 'tests/Feature/SalesChannels/[N-Z]*.php'];

    public function test_the_two_halves_of_the_matrix_cover_every_test_file_exactly_once(): void
    {
        $everyFile = $this->fileNames(glob(base_path('tests/Feature/SalesChannels/*.php')) ?: []);
        $this->assertNotEmpty($everyFile, 'the directory this guard is about could not be read');

        $covered = [];
        foreach (self::HALVES as $half) {
            foreach ($this->fileNames(glob(base_path($half)) ?: []) as $name) {
                // **Exactly once.** Overlapping globs would run a file twice, which wastes a runner rather than losing
                // coverage -- but it would also mean the halves are not a partition, and the next edit could make the
                // overlap a gap instead.
                $this->assertArrayNotHasKey($name, $covered, "'{$name}' is in more than one half of the matrix.");
                $covered[$name] = true;
            }
        }

        $missed = array_values(array_diff($everyFile, array_keys($covered)));
        $this->assertSame(
            [],
            $missed,
            "These test files are in tests/Feature/SalesChannels and match NEITHER half of the CI matrix, so they run on no "
            . "real engine and nothing else would have said so:\n  " . implode("\n  ", $missed)
            . "\n\nThe halves are " . implode(' and ', self::HALVES) . ' — a file whose name starts with a digit, an '
            . 'underscore or a lower-case letter falls outside both.',
        );
    }

    public function test_the_workflow_really_asks_for_the_two_halves_this_guard_checks(): void
    {
        // The guard is worthless if it checks globs the workflow no longer uses. This reads the workflow itself, so a change
        // to the matrix that forgets this file fails here instead of silently dropping tests.
        $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

        foreach (self::HALVES as $half) {
            $this->assertStringContainsString(
                'paths: ' . $half,
                $workflow,
                "The matrix no longer runs '{$half}', so this guard is checking something CI does not do. Either put the "
                . 'glob back or update HALVES to whatever the matrix now uses — and keep them a partition of the directory.',
            );
        }

        // And the whole directory must NOT still be one job, or the split never took effect.
        $this->assertStringNotContainsString(
            'paths: tests/Feature/SalesChannels' . "\n",
            $workflow,
            'The matrix still runs the whole sales-channel directory as one job, which is what hit the 110-minute ceiling.',
        );
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function fileNames(array $paths): array
    {
        return array_values(array_map(static fn (string $path): string => basename($path), $paths));
    }
}
