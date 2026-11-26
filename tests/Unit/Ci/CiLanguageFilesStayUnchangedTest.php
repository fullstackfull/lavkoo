<?php

namespace Tests\Unit\Ci;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * No test job may leave the tracked language files changed (M2).
 *
 * `translate()` appends a humanised placeholder to `resources/lang/<locale>/new-messages.php` for any key the files do not already know,
 * and those files are tracked. The cost that matters is not a dirty tree, which somebody notices and reverts: it is that a key a REAL
 * FEATURE added becomes indistinguishable from an artifact of a test run -- in the diff of a pull request, and in the admin's own
 * translation screen, where it appears as a phrase the shop is supposed to translate.
 *
 * Before this, no job in either workflow checked: there was no `git status --porcelain` and no `git diff --exit-code` anywhere in
 * `.github/workflows`. The pieces are three, and this class proves each of them rather than describing them:
 *
 *   - `Tests\TestCase` puts the files back after (and before) every test, so a run leaves nothing behind. It is unconditional, because a
 *     gate that depends on a switch is not a gate;
 *   - `scripts/ci/language-files-unchanged.php` is the gate, run after every `phpunit` step of every job. Its rule is run here against
 *     real git working trees, like the Saudi baseline guard's;
 *   - `gcc.tests.language_file_writes_are_failures` turns a write into a FAILURE of the test that made it, which is how the offending
 *     test is found among the suite's twenty-two thousand. It ships off, and off changes nothing: both states are run here against a
 *     fixture that really does write a key.
 */
class CiLanguageFilesStayUnchangedTest extends TestCase
{
    private const GUARD = 'scripts/ci/language-files-unchanged.php';

    private const FIXTURE = 'tests/Fixtures/LanguageFileGuard/WritesAnUnknownKeyTest.php';

    private const WORKFLOWS = ['.github/workflows/ci.yml', '.github/workflows/ci-quick.yml'];

    private const LANGUAGE_PATH = 'resources/lang/en/new-messages.php';

    private string $repository;

    protected function setUp(): void
    {
        $this->repository = sys_get_temp_dir() . '/language-files-guard-' . bin2hex(random_bytes(6));
        mkdir($this->repository . '/resources/lang/en', 0777, true);
        $this->git('init', '-q', '-b', 'main');
        $this->git('config', 'user.email', 'ci@example.invalid');
        $this->git('config', 'user.name', 'CI');
        $this->write(self::LANGUAGE_PATH, "<?php\n\nreturn [\n\t'known_key' => 'Known key',\n];\n");
        $this->git('add', '-A');
        $this->git('commit', '-q', '-m', 'baseline');
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->repository]))->run();

        parent::tearDown();
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function git(string ...$arguments): void
    {
        (new Process(['git', ...$arguments], $this->repository))->mustRun();
    }

    private function write(string $path, string $contents): void
    {
        $full = $this->repository . '/' . $path;
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0777, true);
        }
        file_put_contents($full, $contents);
    }

    /** @return array{status: int, output: string} the guard's answer about the temporary working tree */
    private function askTheGuard(): array
    {
        $guard = new Process(['php', $this->root() . '/' . self::GUARD], $this->repository);
        $guard->run();

        return ['status' => (int) $guard->getExitCode(), 'output' => $guard->getOutput() . $guard->getErrorOutput()];
    }

    /** @return array<string, mixed> */
    private function workflow(string $path): array
    {
        return Yaml::parseFile($this->root() . '/' . $path);
    }

    // ---- the gate is wired into every job that runs tests -------------------------------------------------------------------------------

    public function test_every_step_that_runs_phpunit_is_followed_by_the_guard_in_the_same_job(): void
    {
        $phpunitSteps = 0;

        foreach (self::WORKFLOWS as $path) {
            foreach ($this->workflow($path)['jobs'] as $job => $definition) {
                $unguarded = 0;

                foreach ($definition['steps'] ?? [] as $step) {
                    $run = (string) ($step['run'] ?? '');

                    if (str_contains($run, 'vendor/bin/phpunit')) {
                        $unguarded++;
                        $phpunitSteps++;
                    } elseif (str_contains($run, self::GUARD)) {
                        $unguarded = 0;
                    }
                }

                $this->assertSame(
                    0,
                    $unguarded,
                    "{$path}: the job `{$job}` runs phpunit with no `php " . self::GUARD . '` step after it, so a key a test wrote would'
                    . ' reach the repository with nothing to say so',
                );
            }
        }

        $this->assertGreaterThanOrEqual(8, $phpunitSteps, 'the workflows ran fewer phpunit steps than this guard was written against');
    }

    public function test_the_guard_runs_even_when_a_test_above_it_failed(): void
    {
        foreach (self::WORKFLOWS as $path) {
            foreach ($this->workflow($path)['jobs'] as $job => $definition) {
                foreach ($definition['steps'] ?? [] as $step) {
                    if (!str_contains((string) ($step['run'] ?? ''), self::GUARD)) {
                        continue;
                    }

                    $this->assertSame(
                        'always()',
                        $step['if'] ?? null,
                        "{$path}: the guard of `{$job}` must carry `if: always()`, or a failing test hides the dirty tree it left",
                    );
                }
            }
        }
    }

    // ---- the gate's own rule, against real working trees --------------------------------------------------------------------------------

    public function test_an_unchanged_tree_is_accepted(): void
    {
        $answer = $this->askTheGuard();

        $this->assertSame(0, $answer['status'], $answer['output']);
        $this->assertStringContainsString('exactly as it was', $answer['output']);
    }

    public function test_a_language_file_a_run_changed_is_refused_and_the_key_is_printed(): void
    {
        $this->write(self::LANGUAGE_PATH, "<?php\n\nreturn [\n\t'known_key' => 'Known key',\n\t'invented_key' => 'Invented key',\n];\n");

        $answer = $this->askTheGuard();

        $this->assertSame(1, $answer['status'], 'a changed language file fails the job');
        $this->assertStringContainsString('invented_key', $answer['output'], 'the job says which key was written');
        $this->assertStringContainsString('GCC_TEST_LANGUAGE_FILE_WRITES_ARE_FAILURES=1', $answer['output'], 'and how to find the test');
    }

    public function test_a_language_file_a_run_added_is_refused_even_though_git_does_not_track_it(): void
    {
        $this->write('resources/lang/kw/new-messages.php', "<?php\n\nreturn [\n\t'invented_key' => 'Invented key',\n];\n");

        $answer = $this->askTheGuard();

        $this->assertSame(1, $answer['status'], 'an untracked language file a test wrote is a write like any other');
        $this->assertStringContainsString('resources/lang/kw/new-messages.php', $answer['output']);
    }

    public function test_a_change_outside_the_language_files_is_not_this_guard_s_business(): void
    {
        $this->write('app/Other.php', "<?php\n// edited\n");

        $answer = $this->askTheGuard();

        $this->assertSame(0, $answer['status'], 'this gate is scoped to resources/lang: a source edit is for the other jobs to judge');
    }

    public function test_a_guard_that_cannot_read_a_working_tree_refuses_rather_than_passes(): void
    {
        (new Process(['rm', '-rf', $this->repository . '/.git']))->mustRun();

        $answer = $this->askTheGuard();

        $this->assertSame(1, $answer['status'], 'a guard that cannot answer must say so rather than report success');
        $this->assertStringContainsString('not a git checkout', $answer['output']);
    }

    // ---- the switch that names the offending test ---------------------------------------------------------------------------------------

    /**
     * @return array{status: int, output: string}
     */
    private function runTheFixture(bool $writesAreFailures): array
    {
        $run = new Process(
            ['vendor/bin/phpunit', '-c', 'phpunit.xml', self::FIXTURE],
            $this->root(),
            ['GCC_TEST_LANGUAGE_FILE_WRITES_ARE_FAILURES' => $writesAreFailures ? '1' : '0'],
        );
        $run->setTimeout(300);
        $run->run();

        return ['status' => (int) $run->getExitCode(), 'output' => $run->getOutput() . $run->getErrorOutput()];
    }

    public function test_as_the_switch_ships_a_test_that_writes_a_key_is_not_failed_for_it(): void
    {
        $answer = $this->runTheFixture(writesAreFailures: false);

        $this->assertSame(0, $answer['status'], 'off is today\'s suite: the write is put back in silence' . "\n" . $answer['output']);
        $this->assertStringContainsString('Tests: 1', $answer['output'], 'the fixture really ran');
    }

    public function test_with_the_switch_on_the_test_that_wrote_the_key_is_the_one_that_fails(): void
    {
        $answer = $this->runTheFixture(writesAreFailures: true);

        $this->assertSame(1, $answer['status'], 'the run fails' . "\n" . $answer['output']);
        $this->assertStringContainsString('WritesAnUnknownKeyTest', $answer['output'], 'and it is the offending test that is named');
        $this->assertStringContainsString('en/new-messages.php', $answer['output'], 'with the file it wrote into');
        $this->assertStringContainsString('zz_language_file_guard_fixture_', $answer['output'], 'and the key it invented');
    }
}
