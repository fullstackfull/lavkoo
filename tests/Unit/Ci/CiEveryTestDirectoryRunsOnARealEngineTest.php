<?php

namespace Tests\Unit\Ci;

use PHPUnit\Framework\TestCase;

/**
 * Every test directory runs in at least one CI job that talks to a real MySQL or MariaDB, or is listed below with the reason it does not.
 *
 * SQLite in memory is the default test database, and it is more lenient than the engines the shop runs on: it reads an unknown double-quoted
 * name as a string (so `where "no_such_column" > 0` is true instead of an error), it accepts a bare GROUP BY that MySQL 8 refuses, it has no
 * row locks, and it returns decimals as numbers. A directory that only SQLite runs proves less than its green tick says. Running twenty-three
 * such directories of the open pull requests on MySQL 8 found 34 failures, every one a fixture that SQLite's leniency had hidden, and, in
 * strict mode, a third ONLY_FULL_GROUP_BY statement in the checkout.
 *
 * A job runs on a real engine when its command is `phpunit -c phpunit.mysql.xml`, so the paths it is given are what is read here. A test
 * directory is covered when a path names it or one of its parents. The SQLite job runs everything and is not counted.
 *
 * It lives in tests/Unit and reads only files, like the guard of the sales-channel split.
 */
class CiEveryTestDirectoryRunsOnARealEngineTest extends TestCase
{
    private const WORKFLOW = '.github/workflows/ci.yml';

    private const SUITES = ['tests/Feature', 'tests/Unit'];

    /** What only SQLite runs, and why that is enough. An entry that is covered by a real-engine path, or that no longer exists, is an error. */
    private const SQLITE_ONLY = [
        'tests/Feature/DeepLink' => 'resolves paths against configuration; it reads no table',
        'tests/Feature/ExampleTest.php' => 'the framework stub, skipped without the installation dump',
        'tests/Unit/ExampleTest.php' => 'the framework stub; no database',
        'tests/Unit/Ci' => 'reads the workflow, the test files and the migration source; no database',
        'tests/Unit/Deploy' => 'deployment tooling on the file system; it needs strace and, once more, root, which only the SQLite job installs and runs',
    ];

    public function test_every_test_directory_and_file_runs_on_a_real_engine_or_says_why_not(): void
    {
        $covered = $this->realEnginePaths();
        $this->assertNotEmpty($covered, 'no real-engine path was found in the workflow, so this guard proved nothing');

        $uncovered = [];
        foreach (self::SUITES as $suite) {
            foreach ($this->childrenOf(path: $suite) as $child) {
                array_push($uncovered, ...$this->uncoveredPaths(path: $child, covered: $covered));
            }
        }

        $this->assertSame(
            [],
            $uncovered,
            "These tests run on no real engine, only on SQLite, which hides what MySQL and MariaDB refuse:\n  " . implode("\n  ", $uncovered)
            . "\n\nAdd the directory to the `paths` of a group in " . self::WORKFLOW . ' (and to the strict-mode job), or list it in SQLITE_ONLY with the reason.',
        );
    }

    public function test_a_directory_listed_as_sqlite_only_exists_and_is_really_not_covered(): void
    {
        $covered = $this->realEnginePaths();
        $root = $this->repositoryRoot();

        $stale = [];
        foreach (array_keys(self::SQLITE_ONLY) as $path) {
            if (!file_exists($root . '/' . $path)) {
                $stale[] = $path . ' (does not exist)';
            } elseif ($this->isCovered(path: $path, covered: $covered)) {
                $stale[] = $path . ' (a real-engine job runs it now)';
            }
        }

        $this->assertSame([], $stale, "Remove these entries from SQLITE_ONLY:\n  " . implode("\n  ", $stale));
    }

    public function test_every_path_a_real_engine_job_runs_exists(): void
    {
        $root = $this->repositoryRoot();

        $missing = array_values(array_filter(
            $this->realEnginePaths(),
            static fn (string $path): bool => !file_exists($root . '/' . $path),
        ));

        $this->assertSame([], $missing, "The workflow names test paths that do not exist (a typo makes a job run nothing):\n  " . implode("\n  ", $missing));
    }

    /**
     * Every group runs on BOTH engines, and nothing takes one out of the matrix.
     *
     * The paths above say a real engine runs a directory; they do not say WHICH, and the two engines refuse different things. MySQL 8
     * refuses a bare GROUP BY under ONLY_FULL_GROUP_BY; MariaDB 11.4.2 is what the pilot server runs, and it is the one that rejected an
     * index name of 69 characters and read `kw` as equal to `KW` under its own collation. A group that ran on one of them would look
     * exactly as green as a group that ran on both.
     *
     * The engine lists are what `matrix.engine` holds, and a `matrix.exclude` is the one thing that can drop a cell silently -- in this
     * workflow the cell would be a whole test directory on the pilot's engine. There is none, and this is what says so.
     */
    public function test_each_engine_matrix_holds_two_engines_and_drops_no_cell(): void
    {
        $workflow = file($this->repositoryRoot() . '/' . self::WORKFLOW, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($workflow, 'the CI workflow could not be read');

        $engineLists = [];
        $excludes = [];
        foreach ($this->jobBlocks(lines: $workflow) as $name => $block) {
            foreach ($block as $line) {
                if (str_starts_with(ltrim($line), '#')) {
                    continue;
                }
                if (preg_match("/^\s*engine:\s*\[(.+)\]\s*$/", $line, $match) === 1) {
                    $engineLists[$name] = array_map(static fn (string $engine): string => trim($engine, " '\""), explode(',', $match[1]));
                }
                if (preg_match('/^\s*exclude:\s*$/', $line) === 1) {
                    $excludes[] = $name;
                }
            }
        }

        $this->assertNotEmpty($engineLists, 'no engine matrix was found, so this guard proved nothing');
        $this->assertSame([], $excludes, 'A matrix `exclude:` drops a cell of the engine matrix, which here means a test directory that no longer runs on one of the two engines: ' . implode(', ', $excludes));

        foreach ($engineLists as $job => $engines) {
            $this->assertCount(2, $engines, "`{$job}` must run its groups on two engines, and it names: " . implode(', ', $engines));
            $this->assertNotEmpty(
                array_filter($engines, static fn (string $engine): bool => str_contains($engine, 'MySQL')),
                "`{$job}` must run on MySQL, which refuses a bare GROUP BY that SQLite accepts",
            );
            $this->assertNotEmpty(
                array_filter($engines, static fn (string $engine): bool => str_contains($engine, 'MariaDB 11.4.2')),
                "`{$job}` must run on MariaDB 11.4.2, which is what the pilot server runs",
            );
        }
    }

    /** @return list<string> the test paths the jobs that run `phpunit -c phpunit.mysql.xml` are given */
    private function realEnginePaths(): array
    {
        $workflow = file($this->repositoryRoot() . '/' . self::WORKFLOW, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($workflow, 'the CI workflow could not be read');

        $paths = [];
        foreach ($this->jobBlocks(lines: $workflow) as $block) {
            $code = array_filter($block, static fn (string $line): bool => !str_starts_with(ltrim($line), '#'));
            if (!str_contains(implode("\n", $code), 'phpunit.mysql.xml')) {
                continue;
            }

            foreach ($code as $line) {
                foreach (preg_split('/\s+/', trim($line)) ?: [] as $token) {
                    if (preg_match('#^tests/[A-Za-z0-9_./-]+$#', $token) === 1) {
                        $paths[] = rtrim($token, '/');
                    }
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /** @return array<string, list<string>> the lines of each job, by name, split at the two-space keys under `jobs:` */
    private function jobBlocks(array $lines): array
    {
        $blocks = [];
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
                $blocks[$current] = [];
            }
            if ($current !== null) {
                $blocks[$current][] = $line;
            }
        }

        return $blocks;
    }

    /** @return list<string> the test paths under $path that no real-engine job runs and that SQLITE_ONLY does not excuse */
    private function uncoveredPaths(string $path, array $covered): array
    {
        if ($this->isExcused(path: $path) || $this->isCovered(path: $path, covered: $covered)) {
            return [];
        }

        $isDirectory = is_dir($this->repositoryRoot() . '/' . $path);
        if (!$isDirectory || !$this->hasCoveredDescendant(path: $path, covered: $covered)) {
            return [$path];
        }

        $uncovered = [];
        foreach ($this->childrenOf(path: $path) as $child) {
            array_push($uncovered, ...$this->uncoveredPaths(path: $child, covered: $covered));
        }

        return $uncovered;
    }

    private function isExcused(string $path): bool
    {
        foreach (array_keys(self::SQLITE_ONLY) as $excused) {
            if ($path === $excused || str_starts_with($path, $excused . '/')) {
                return true;
            }
        }

        return false;
    }

    private function isCovered(string $path, array $covered): bool
    {
        foreach ($covered as $coveredPath) {
            if ($path === $coveredPath || str_starts_with($path, $coveredPath . '/')) {
                return true;
            }
        }

        return false;
    }

    private function hasCoveredDescendant(string $path, array $covered): bool
    {
        foreach ($covered as $coveredPath) {
            if (str_starts_with($coveredPath, $path . '/')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> the sub-directories and test files directly under $path */
    private function childrenOf(string $path): array
    {
        $root = $this->repositoryRoot();
        $children = [];
        foreach (scandir($root . '/' . $path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (is_dir($root . '/' . $path . '/' . $name) || str_ends_with($name, 'Test.php')) {
                $children[] = $path . '/' . $name;
            }
        }
        sort($children);

        return $children;
    }

    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
