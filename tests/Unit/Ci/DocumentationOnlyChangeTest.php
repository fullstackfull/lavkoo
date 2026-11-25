<?php

namespace Tests\Unit\Ci;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rule that lets CI skip the heavy engine groups for a documentation-only change, and the proof that it only ever skips them for one
 * (M1-16).
 *
 * This is the one filter in the workflow that can make a test not run at all, so it is tested like code rather than trusted like YAML.
 * The cases are written from the direction of the mistake that matters: a code change the filter calls documentation. Every case that
 * mixes one source file into a pile of Markdown must answer `false`, and the cases that answer `true` are only the ones where every
 * single path is documentation.
 *
 * It also reads `ci.yml` and asserts that every job which starts a database service carries the condition. A rule nothing applies, or a
 * new engine job added without it, would read exactly like a working filter: the docs-only pull request would be green and three
 * quarters of the suite would not have run.
 */
class DocumentationOnlyChangeTest extends TestCase
{
    private const SCRIPT = 'scripts/ci/documentation-only-change.php';

    private const WORKFLOW = '.github/workflows/ci.yml';

    /** The output the workflow reads, and the condition the heavy jobs carry. Written here so a rename of either fails this test. */
    private const OUTPUT = 'documentation_only';

    private const CONDITION = "needs.decide.outputs.documentation_only != 'true'";

    #[DataProvider('changes')]
    public function test_only_a_change_that_is_documentation_to_the_last_path_may_skip_the_engines(bool $documentationOnly, array $paths, string $why): void
    {
        require_once $this->repositoryRoot() . '/' . self::SCRIPT;

        $this->assertSame($documentationOnly, changeIsDocumentationOnly($paths), $why);
    }

    /** @return array<string, array{0: bool, 1: list<string>, 2: string}> */
    public static function changes(): array
    {
        return [
            'one document' => [true, ['docs/gcc/STATUS.md'], 'a file under docs/ cannot change what a test does'],
            'many documents' => [true, ['docs/gcc/STATUS.md', 'docs/address/README.md', 'README.md'], 'all of them are documentation'],
            'a document and one service' => [false, ['docs/gcc/STATUS.md', 'app/Services/Monitoring/Metric.php'], 'one source file makes the whole change a code change'],
            'a document and one test' => [false, ['README.md', 'tests/Unit/Ci/DocumentationOnlyChangeTest.php'], 'a test file change must be run'],
            'a document and one migration' => [false, ['docs/gcc/STATUS.md', 'database/migrations/2026_12_29_100000_x.php'], 'a migration must be run on every engine'],
            'the workflow itself' => [false, ['.github/workflows/ci.yml'], 'a change to what runs must be proved by running it'],
            'a document and a language file' => [false, ['docs/x.md', 'resources/lang/en/messages.php'], 'resources/ is not documentation: the suite asserts what is in it'],
            'a document and a composer lock' => [false, ['docs/x.md', 'composer.lock'], 'a dependency change is the least safe thing to skip'],
            'nothing at all' => [false, [], 'an empty list proves nothing about the change, so everything runs'],
            'blank lines only' => [false, ['', '  '], 'the same, after the lines a git diff can leave behind'],
            'a document named outside the tree' => [false, ['../docs/x.md'], 'a path that climbs out of the repository is not vouched for'],
            'an absolute path' => [false, ['/docs/x.md'], 'only plain relative paths are read'],
            'a directory whose name begins like docs' => [false, ['docsy/app.php'], 'the prefix is the directory docs/, not the letters d-o-c-s'],
            'a document deep under docs' => [true, ['docs/gcc/audits/2026-11-24/count.php'], 'everything under docs/ is documentation, the scripts an audit carries included'],
        ];
    }

    public function test_the_script_prints_the_answer_and_nothing_else(): void
    {
        $root = $this->repositoryRoot();

        $documentation = shell_exec('cd ' . escapeshellarg($root) . ' && php ' . escapeshellarg(self::SCRIPT) . ' docs/a.md README.md 2>&1');
        $code = shell_exec('cd ' . escapeshellarg($root) . ' && php ' . escapeshellarg(self::SCRIPT) . ' docs/a.md app/x.php 2>&1');

        $this->assertSame("true\n", $documentation);
        $this->assertSame("false\n", $code);
    }

    public function test_the_script_reads_the_paths_from_its_input_as_a_git_diff_gives_them(): void
    {
        $root = $this->repositoryRoot();
        $command = 'cd ' . escapeshellarg($root) . ' && printf %s ' . escapeshellarg("docs/a.md\ndocs/b.md\n") . ' | php ' . escapeshellarg(self::SCRIPT) . ' 2>&1';

        $this->assertSame("true\n", shell_exec($command));
    }

    public function test_every_job_that_starts_a_database_carries_the_condition(): void
    {
        $jobs = $this->jobsOfTheWorkflow();
        $this->assertNotEmpty($jobs, 'no jobs were read from ' . self::WORKFLOW . ', so this guard proved nothing');

        $unconditional = [];
        foreach ($jobs as $name => $block) {
            $code = implode("\n", array_filter($block, static fn (string $line): bool => !str_starts_with(ltrim($line), '#')));
            $startsADatabase = str_contains($code, 'mysql:8.0') || str_contains($code, 'mariadb:11.4.2') || str_contains($code, '${{ matrix.image }}');

            if ($startsADatabase && !str_contains($code, self::CONDITION)) {
                $unconditional[] = $name;
            }
        }

        $this->assertSame(
            [],
            $unconditional,
            "These jobs start a database and do not carry `if: " . self::CONDITION . "`, so a documentation-only pull request would run them:\n  "
            . implode("\n  ", $unconditional)
            . "\n\nThat is only a waste of runner-minutes. The opposite mistake is the dangerous one, so if a job must always run, say why here.",
        );
    }

    public function test_the_job_that_decides_runs_the_script_and_publishes_the_answer(): void
    {
        $jobs = $this->jobsOfTheWorkflow();

        $this->assertArrayHasKey('decide', $jobs, self::WORKFLOW . ' must hold the job named `decide` that the condition refers to');

        $decide = implode("\n", $jobs['decide']);
        $this->assertStringContainsString(self::SCRIPT, $decide, 'the deciding job must run the script this test covers, not a rule of its own');
        $this->assertStringContainsString(self::OUTPUT, $decide, 'the deciding job must publish its answer as the output the heavy jobs read');
    }

    public function test_the_syntax_check_and_the_sqlite_suite_are_never_skipped(): void
    {
        $jobs = $this->jobsOfTheWorkflow();

        foreach (['lint', 'tests-sqlite', 'leak-guard'] as $job) {
            $this->assertArrayHasKey($job, $jobs);
            $this->assertStringNotContainsString(
                self::CONDITION,
                implode("\n", $jobs[$job]),
                "`{$job}` must run on every change: it is cheap, it needs no engine, and it is the one answer a documentation-only pull request still has to give (the leak guard reads the documentation itself).",
            );
        }
    }

    /** @return array<string, list<string>> the lines of each job, by job name */
    private function jobsOfTheWorkflow(): array
    {
        $lines = file($this->repositoryRoot() . '/' . self::WORKFLOW, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines, self::WORKFLOW . ' could not be read');

        $jobs = [];
        $current = null;
        $inJobs = false;
        foreach ($lines as $line) {
            if (preg_match('/^jobs:\s*$/', $line) === 1) {
                $inJobs = true;
                continue;
            }
            if (!$inJobs) {
                continue;
            }
            if (preg_match('/^  ([A-Za-z0-9_-]+):\s*$/', $line, $match) === 1) {
                $current = $match[1];
                $jobs[$current] = [];
                continue;
            }
            if ($current !== null) {
                $jobs[$current][] = $line;
            }
        }

        return $jobs;
    }

    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
