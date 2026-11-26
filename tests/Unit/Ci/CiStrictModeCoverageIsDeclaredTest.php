<?php

namespace Tests\Unit\Ci;

use PHPUnit\Framework\TestCase;

/**
 * Which test directories run with the database connection in STRICT mode, and for every one that does not, the measured reason (M2-15).
 *
 * `CiEveryTestDirectoryRunsOnARealEngineTest` is this guard's twin: it asks whether a directory ever meets a real engine. That is not the
 * same question. `config/database.php` sets `strict => false`, which makes Laravel set `sql_mode='NO_ENGINE_SUBSTITUTION'` for the session
 * and so REMOVES `ONLY_FULL_GROUP_BY` even on a MySQL 8 server whose own global default includes it. So a directory can run green on MySQL 8
 * and MariaDB 11.4.2 all day and still hold statements a strict server refuses with error 1055. `DB_STRICT_TESTS=1`
 * ({@see \Tests\CreatesApplication}) is what turns that back on, and only the two strict-mode jobs set it.
 *
 * M2-15 asks for "a strict-mode run of the GCC groups", and the card recording that said the job "omits Storefront, Catalog, Staff scope and
 * platform tests". Read against the workflow, that had drifted in both directions: `tests/Feature/Catalog` and `tests/Feature/Catalogue` are
 * in the job now, and the real list of what is outside it is 24 directories, not four. More to the point, "omits" is not a reason. A gate's
 * coverage list is only worth having if each hole has a measured cause, because the three causes need three different answers: a directory
 * that is **ready** needs one line of YAML, a directory that is **blocked** needs code changed first, and a directory **nobody has run**
 * needs somebody to run it.
 *
 * So each is declared with one of those three states and the measurement behind it, and the declaration is enforced in both
 * directions: a directory that joins a strict job must come off this list, and a directory added to the tree must be put on it.
 *
 * **The five READY directories have now been APPENDED to the job** (`tests/Feature/Queue`, `tests/Feature/Reports`,
 * `tests/Feature/Retention`, `tests/Unit/Ci`, `tests/Unit/Storefront`), so nineteen declarations remain and not one of them says `ready:`.
 * Appended is the operative word: the comment above that YAML key records four merges each writing their own `run:`, with YAML keeping only
 * the last of a duplicated mapping key, which silently took eighteen directories out of strict mode. A directory joins this job by being
 * added to the ONE existing key, never by a second key, and `test_every_test_directory_is_in_a_strict_mode_job_or_says_why_not` is what
 * notices if a merge drops the union again -- it would report the lost directories as undeclared.
 *
 * It reads files only: no database. That used to be the reason its twin listed `tests/Unit/Ci` as SQLite-only, and putting this very
 * directory into the strict job made that entry stale by the twin's own rule ("an entry that is covered by a real-engine path ... is an
 * error"), so the entry came off in the same commit. The two guards are coupled through the workflow and have to be moved together.
 */
class CiStrictModeCoverageIsDeclaredTest extends TestCase
{
    private const WORKFLOW = '.github/workflows/ci.yml';

    private const SUITES = ['tests/Feature', 'tests/Unit'];

    /** Measured here, green under strict mode, and waiting only for a line in the workflow. The counts are the proof. */
    private const READY = 'ready: ';

    /** Measured here and NOT green under strict mode. Code has to change before the directory can join the job. */
    private const BLOCKED = 'blocked: ';

    /** Not run under strict mode by anybody yet. The honest third state, and the one that must not be mistaken for either of the others. */
    private const NOT_MEASURED = 'not measured: ';

    /**
     * Every test directory outside the two strict-mode jobs, with the reason.
     *
     * Where a reason gives counts, they were measured on MariaDB 10.11.14 in a worktree on 2026-11-26 with
     * `DB_STRICT_TESTS=1 ... phpunit -c phpunit.mysql.xml <the directory's files>`. The pilot runs 11.4.2, which this container cannot be
     * given, so a green reading here is evidence that the SQL is acceptable to a strict MariaDB and not a substitute for the job itself.
     *
     * @var array<string, string>
     */
    private const NOT_IN_A_STRICT_MODE_JOB = [
        'tests/Feature/Accounting' => self::NOT_MEASURED . 'not run under strict mode here; it belongs to the accounting axis and was not read',
        'tests/Feature/Address' => self::NOT_MEASURED . 'named as a known hole by M1-04 already ("the strict-mode job\'s list holds neither tests/Feature/Storefront nor tests/Feature/Address"); it is a large directory and a group run of it is the coordinator\'s',
        'tests/Feature/Concurrency' => self::NOT_MEASURED . 'it takes row locks and runs its own concurrent runner, so it is the one directory where a second engine setting could change what it measures rather than only whether it passes',
        'tests/Feature/Currency' => self::NOT_MEASURED . 'it measures which row an UNORDERED query returns first, which two engines are free to answer differently, so a strict-mode reading of it needs its own thought rather than a pass/fail',
        'tests/Feature/DeepLink' => self::NOT_MEASURED . 'it reads no table at all and is listed SQLITE_ONLY by the real-engine guard, so strict mode cannot reach anything in it',
        'tests/Feature/Localization' => self::NOT_MEASURED . 'not run under strict mode here; it writes language files and needs the translate guards, which makes it a poor first candidate',
        'tests/Feature/MyFatoorah' => self::NOT_MEASURED . 'the gateway directory has its own real-engine group and was not read under strict mode here',
        'tests/Feature/Notifications' => self::NOT_MEASURED . 'not run under strict mode here; it belongs to the notification axis',
        'tests/Feature/PlatformAdmin' => self::NOT_MEASURED . 'sixty files, and the platform overview reads the same money reports the staff scope does, so it is a likely second instance of the 1055 below rather than a safe bet; a group run of it is the coordinator\'s',
        'tests/Feature/Security' => self::NOT_MEASURED . 'it runs a real queue worker and a shared cache store, so a strict connection is a second variable on top of the two it already varies',
        'tests/Feature/Seeders' => self::NOT_MEASURED . 'the staging seeder writes a whole world and is the directory most likely to be refused by a strict server, which makes it worth measuring and not worth guessing',
        'tests/Feature/StaffScope' => self::BLOCKED . 'three of its report files are 14 failures in 50 tests under strict mode on MariaDB 10.11.14, every one error 1055 on `order_transactions.created_at` or its siblings. See the test below for where the statements are',
        'tests/Feature/Storefront' => self::NOT_MEASURED . 'the largest directory in the suite (about 5,000 tests) and named as a known hole by M1-04; a group run of it is the coordinator\'s',
        'tests/Unit/Accounting' => self::NOT_MEASURED . 'not run under strict mode here; it belongs to the accounting axis and was not read',
        'tests/Unit/Deploy' => self::NOT_MEASURED . 'deployment tooling on the file system; it needs strace and root, which only the SQLite job provides, so it is SQLITE_ONLY by its twin guard and strict mode has nothing to act on',
        'tests/Unit/MyFatoorah' => self::NOT_MEASURED . 'not run under strict mode here; the gateway unit tests were not read',
        'tests/Unit/OperationalCosts' => self::NOT_MEASURED . 'not run under strict mode here, although tests/Feature/OperationalCosts is in the job, which makes this one a likely quick win',
        'tests/Unit/Payments' => self::NOT_MEASURED . 'not run under strict mode here, although tests/Feature/Payments and tests/Feature/Payment are both in the job',
        'tests/Unit/SalesChannels' => self::NOT_MEASURED . 'not run under strict mode here, although tests/Feature/SalesChannels is in the job, which makes this one a likely quick win',
    ];

    /** The statements measured to refuse a strict server, where they live, and what each group leaves functionally undetermined. */
    private const STATEMENTS_A_STRICT_SERVER_REFUSES = [
        'app/Repositories/OrderTransactionRepository.php' => 'getListWhereBetween selects YEAR, MONTH, DAY and DAYNAME of created_at and groups by a SUBSET of them in all three of its modes: `year, month` (so day and day_of_week are arbitrary within the month), `month, day` (so year is arbitrary, which merges two Januaries exactly as the DATE_FORMAT %M defect does) and `day_of_week` (so all three date parts are arbitrary). This is the money table.',
        'app/Repositories/OrderRepository.php' => 'getListWhereBetween selects the same four and groups by `year, month, day_of_week`, leaving `day` functionally undetermined within the group.',
    ];

    public function test_every_test_directory_is_in_a_strict_mode_job_or_says_why_not(): void
    {
        $covered = $this->strictModePaths();
        $this->assertNotEmpty($covered, 'no strict-mode path was found in the workflow, so this guard proved nothing');

        $undeclared = [];

        foreach (self::SUITES as $suite) {
            foreach ($this->childrenOf(path: $suite) as $directory) {
                if (!$this->isCovered(path: $directory, covered: $covered) && !isset(self::NOT_IN_A_STRICT_MODE_JOB[$directory])) {
                    $undeclared[] = $directory;
                }
            }
        }

        sort($undeclared);

        $this->assertSame(
            [],
            $undeclared,
            "These test directories run in no strict-mode job and this register does not say why:\n  " . implode("\n  ", $undeclared)
            . "\n\nAdd the directory to the strict-mode job in " . self::WORKFLOW . ', or declare it in NOT_IN_A_STRICT_MODE_JOB with a measured reason.',
        );
    }

    /** The other direction: a declaration for a directory that has since joined a strict job, or that no longer exists, is stale. */
    public function test_no_declaration_names_a_directory_that_is_covered_or_gone(): void
    {
        $covered = $this->strictModePaths();
        $stale = [];

        foreach (array_keys(self::NOT_IN_A_STRICT_MODE_JOB) as $directory) {
            if ($this->isCovered(path: $directory, covered: $covered)) {
                $stale[] = $directory . ' (now runs in a strict-mode job: take it off this list)';
            } elseif (!is_dir($this->repositoryRoot() . '/' . $directory)) {
                $stale[] = $directory . ' (no longer in the tree)';
            }
        }

        $this->assertSame([], $stale, "Stale entries in NOT_IN_A_STRICT_MODE_JOB:\n  " . implode("\n  ", $stale));
    }

    public function test_every_reason_names_one_of_the_three_states_and_is_a_sentence(): void
    {
        foreach (self::NOT_IN_A_STRICT_MODE_JOB as $directory => $reason) {
            $this->assertTrue(
                str_starts_with($reason, self::READY) || str_starts_with($reason, self::BLOCKED) || str_starts_with($reason, self::NOT_MEASURED),
                "{$directory} must say whether it is ready, blocked or not measured: those three need three different answers",
            );
            $this->assertGreaterThan(80, strlen($reason), "{$directory} needs a reason somebody can act on, not a label");
        }
    }

    /**
     * What blocks the one blocked directory, read out of the repositories rather than out of this register.
     *
     * The existing characterisation of this hazard ({@see \Tests\Feature\Reports\ReportGroupingMatchesWhatItSelectsTest}) describes a
     * DIFFERENT shape -- `group by DATE_FORMAT(created_at, '%M')` with `order by created_at` -- across eleven call sites. The statements
     * found here are a second family: four groupings that select more date expressions than they group by. So the blast radius of hardening
     * `config/database.php` is larger than that document says, which is a finding for the owner rather than something to repair in a test.
     */
    public function test_the_statements_that_block_the_staff_scope_directory_are_still_where_this_register_says(): void
    {
        $this->assertStringStartsWith(
            self::BLOCKED,
            self::NOT_IN_A_STRICT_MODE_JOB['tests/Feature/StaffScope'],
            'this test explains one blocked directory, so that is still what the register has to say about it',
        );

        foreach (array_keys(self::STATEMENTS_A_STRICT_SERVER_REFUSES) as $file) {
            $source = (string) file_get_contents($this->repositoryRoot() . '/' . $file);

            $this->assertStringContainsString(
                'DAYNAME(created_at) day_of_week',
                $source,
                $file . ' is recorded as holding a statement a strict server refuses; if it was repaired, re-measure the directory and move it off BLOCKED',
            );
        }

        // And the cause, so this does not pass merely because the string survived: the application's own connection is still not strict, which
        // is the one line keeping those screens alive. If it is ever hardened, the register's whole premise changes.
        $this->assertStringContainsString(
            "'strict' => false",
            (string) file_get_contents($this->repositoryRoot() . '/config/database.php'),
            'the register assumes the application connection is lenient and the test connection is the strict one; both halves have to stay true',
        );
    }

    /** If the strict-mode jobs stopped setting DB_STRICT_TESTS, every reading in this register would be a reading of an ordinary run. */
    public function test_both_strict_mode_jobs_really_put_the_connection_in_strict_mode(): void
    {
        $workflow = (string) file_get_contents($this->repositoryRoot() . '/' . self::WORKFLOW);

        $this->assertSame(
            2,
            substr_count($workflow, "DB_STRICT_TESTS: '1'"),
            'two jobs put the test connection in strict mode (the serial run and the wallet run); a third or a missing one changes what this register covers',
        );

        $this->assertStringContainsString(
            "database.connections.mysql.strict', true",
            (string) file_get_contents($this->repositoryRoot() . '/tests/CreatesApplication.php'),
            'DB_STRICT_TESTS is only a name until CreatesApplication acts on it',
        );
    }

    /** @return list<string> the test paths given to a job that sets DB_STRICT_TESTS */
    private function strictModePaths(): array
    {
        $lines = file($this->repositoryRoot() . '/' . self::WORKFLOW, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines, 'the CI workflow could not be read');

        $paths = [];

        foreach ($this->jobBlocks(lines: $lines) as $block) {
            $code = array_filter($block, static fn (string $line): bool => !str_starts_with(ltrim($line), '#'));

            if (!str_contains(implode("\n", $code), "DB_STRICT_TESTS: '1'")) {
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

    /**
     * The lines of each job, by name, split at the two-space keys under `jobs:` — the same reading its twin guard does, because a job's
     * `env:` and its `run:` are only in the same job by virtue of this block.
     *
     * @param  list<string>  $lines
     * @return array<string, list<string>>
     */
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

            if (preg_match('/^  ([A-Za-z0-9_-]+):\s*$/', $line, $found) === 1) {
                $current = $found[1];
                $blocks[$current] = [];

                continue;
            }

            if ($current !== null) {
                $blocks[$current][] = $line;
            }
        }

        return $blocks;
    }

    /** @param list<string> $covered */
    private function isCovered(string $path, array $covered): bool
    {
        foreach ($covered as $one) {
            if ($one === $path || str_starts_with($path . '/', $one . '/')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> the directories directly under this suite, which is the granularity the workflow's paths are written at */
    private function childrenOf(string $path): array
    {
        $children = [];

        foreach (scandir($this->repositoryRoot() . '/' . $path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_dir($this->repositoryRoot() . '/' . $path . '/' . $entry)) {
                $children[] = $path . '/' . $entry;
            }
        }

        return $children;
    }

    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
