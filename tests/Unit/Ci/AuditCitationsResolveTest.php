<?php

namespace Tests\Unit\Ci;

use PHPUnit\Framework\TestCase;

/**
 * Every `<repo>:<path>:<line>` citation under `docs/gcc` can be followed (M3-13).
 *
 * The Discovery-01 reports state that an automated citation check was run before publication and found no defect. The script was never
 * committed, so for thirteen days the claim could only be believed. `docs/gcc/tools/check_citations.php` is that check, and this runs it.
 *
 * The citations are pinned to the SHA the reports say each repository was read at (`lavkoo@7f42777`), because checking a line number
 * against today's tree would be wrong in both directions: a file that moved since would read as a bad citation and a line that drifted
 * would read as a good one. A citation of a path under `docs/` is a citation of a document this programme itself wrote, often after that
 * commit, and is resolved against the working tree instead -- without that rule the tool reported 148 faults that were all the same
 * non-fault.
 *
 * A shallow clone has no base commit and cannot do the strong check. This test SKIPS there rather than passing: a citation check that
 * silently degrades to nothing is the failure it exists to prevent, and a skip with a reason is visible in `--display-skipped`, which the
 * SQLite jobs pass.
 */
class AuditCitationsResolveTest extends TestCase
{
    private const TOOL = 'docs/gcc/tools/check_citations.php';

    /** The citations counted on 2026-11-25. A floor, not an equality: documents are added, and a tool that checks far fewer checks nothing. */
    private const CITATIONS_SEEN = 11683;

    public function test_every_citation_under_docs_gcc_resolves_at_the_sha_the_reports_name(): void
    {
        $report = $this->checkerOn('docs/gcc');

        $this->assertStringStartsWith('pinned to ', $report['mode'], 'the base commit is in this clone, so the strong check must have run');
        $this->assertSame([], $report['unresolved'], "These citations cannot be followed:\n  " . implode("\n  ", $report['unresolved']));
        $this->assertGreaterThanOrEqual(
            (int) (self::CITATIONS_SEEN * 0.8),
            $report['citations'],
            'far fewer citations were read than the ' . self::CITATIONS_SEEN . ' of 2026-11-25: the reader has probably stopped matching the form they are written in',
        );
    }

    public function test_the_tool_fails_on_a_citation_that_cannot_be_followed(): void
    {
        $directory = sys_get_temp_dir() . '/citations-' . getmypid();
        @mkdir($directory);
        file_put_contents($directory . '/report.md', "base `lavkoo@7f42777`\n\nA finding · `lavkoo:app/Utils/NoSuchFile.php:12` · VERIFIED\n");

        try {
            $report = $this->checkerOn($directory, expectedExit: 1);

            $this->assertCount(1, $report['unresolved']);
            $this->assertStringContainsString('NoSuchFile.php', $report['unresolved'][0]);
        } finally {
            @unlink($directory . '/report.md');
            @rmdir($directory);
        }
    }

    public function test_the_tool_fails_on_a_line_that_is_past_the_end_of_the_file(): void
    {
        $directory = sys_get_temp_dir() . '/citations-line-' . getmypid();
        @mkdir($directory);
        file_put_contents($directory . '/report.md', "base `lavkoo@7f42777`\n\nA finding · `lavkoo:app/Utils/settings.php:999999` · VERIFIED\n");

        try {
            $report = $this->checkerOn($directory, expectedExit: 1);

            $this->assertCount(1, $report['unresolved']);
            $this->assertStringContainsString('past the end of the file', $report['unresolved'][0]);
        } finally {
            @unlink($directory . '/report.md');
            @rmdir($directory);
        }
    }

    /**
     * The measurement the tool makes beside the check, and the reason this is a test and not a note: it is the figure M3-03's criterion
     * asks about -- how many findings name a file, a line and a tag. It is asserted only as a FLOOR, so the reports can be improved and
     * cannot silently get worse.
     */
    public function test_the_share_of_findings_that_name_a_file_and_a_tag_does_not_fall(): void
    {
        $rows = $this->checkerOn('docs/gcc/audits/2026-11-12-0115-discovery-01')['rows'];

        $this->assertGreaterThanOrEqual(7000, $rows['rows'], 'the row reader found almost no table rows, so its figure means nothing');
        $this->assertGreaterThanOrEqual(2480, $rows['with_a_file_and_a_tag'], 'fewer findings carry a file:line and a tag than on 2026-11-25');
    }

    /** @return array<string, mixed> */
    private function checkerOn(string $directory, int $expectedExit = 0): array
    {
        $root = dirname(__DIR__, 3);

        if (!$this->baseCommitIsHere($root)) {
            $this->markTestSkipped('this clone does not hold the SHA the reports were read at, so the citations cannot be pinned to it (a shallow checkout)');
        }

        $command = 'php ' . escapeshellarg($root . '/' . self::TOOL) . ' --json --dir=' . escapeshellarg($directory) . ' 2>&1';
        exec($command, $output, $status);

        $report = json_decode(implode("\n", $output), true);

        $this->assertIsArray($report, 'the tool printed no JSON: ' . implode("\n", $output));
        $this->assertSame($expectedExit, $status, 'the tool exited ' . $status . ' and ' . $expectedExit . ' was expected');

        return $report;
    }

    private function baseCommitIsHere(string $root): bool
    {
        exec('git -C ' . escapeshellarg($root) . ' cat-file -e 7f42777^{commit} 2>/dev/null', $ignored, $status);

        return $status === 0;
    }
}
