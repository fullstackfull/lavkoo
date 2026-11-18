<?php

namespace Tests\Unit\Ci;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * M2-01 of the master plan: the Saudi baseline has a named CI job on both real engines, and no pull request changes its expectations by the side effect of a GCC feature.
 *
 * The job `Saudi baseline pack, MySQL 8` and `Saudi baseline pack, MariaDB 11.4.2` run the golden answers of the checkout (SaudiGoldenResponsesTest), so that a branch
 * protection rule can require them by name. The job `Saudi baseline expectations unchanged` runs .github/scripts/saudi-baseline-guard.sh, which fails a pull request that
 * modifies or deletes an existing golden file (adding one is fine) unless its title carries [saudi-baseline-change], written by a person. The rule is the script's, so this
 * class runs the script against real git histories; the workflow is only checked to wire it up the way the script expects.
 */
class SaudiBaselineJobTest extends TestCase
{
    private const GUARD = '.github/scripts/saudi-baseline-guard.sh';

    private const GOLDEN = 'tests/Fixtures/SaudiGolden';

    private string $repository;

    protected function setUp(): void
    {
        $this->repository = sys_get_temp_dir() . '/saudi-baseline-' . bin2hex(random_bytes(6));
        mkdir($this->repository, 0777, true);
        $this->git('init', '-q', '-b', 'main');
        $this->git('config', 'user.email', 'ci@example.invalid');
        $this->git('config', 'user.name', 'CI');
        $this->write(self::GOLDEN . '/cash_on_delivery.json', "{\"status\":200}\n");
        $this->write('app/Other.php', "<?php\n");
        $this->commit('baseline');
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->repository]))->run();
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string, mixed> */
    private function workflow(): array
    {
        return Yaml::parseFile($this->root() . '/.github/workflows/ci.yml');
    }

    // ---- the named job ------------------------------------------------------------------------------------------------------------------

    public function test_the_baseline_pack_is_a_named_job_on_mysql_8_and_mariadb_11_4_2_that_runs_the_golden_test(): void
    {
        $job = $this->workflow()['jobs']['tests-mysql'];
        $matrix = $job['strategy']['matrix'];

        $this->assertSame('${{ matrix.group }}, ${{ matrix.engine }}', $job['name'], 'a job is named by its group and its engine');
        $this->assertContains('Saudi baseline pack', $matrix['group']);
        $this->assertSame(['MySQL 8', 'MariaDB 11.4.2'], $matrix['engine']);

        $paths = collect($matrix['include'])->firstWhere('group', 'Saudi baseline pack')['paths'] ?? null;
        $this->assertSame('tests/Feature/Address/SaudiGoldenResponsesTest.php', $paths);
        $this->assertFileExists($this->root() . '/' . $paths);
        $this->assertFileExists($this->root() . '/' . self::GOLDEN . '/cash_on_delivery.json', 'the golden files the pack compares with');
    }

    public function test_the_baseline_job_runs_against_a_real_server_with_the_phpunit_file_of_that_engine(): void
    {
        $steps = collect($this->workflow()['jobs']['tests-mysql']['steps']);

        $run = $steps->first(fn (array $step): bool => str_contains((string) ($step['run'] ?? ''), 'phpunit'))['run'];

        $this->assertStringContainsString('-c phpunit.mysql.xml ${{ matrix.paths }}', $run, 'phpunit.xml pins SQLite and would make the job run on the wrong engine');
    }

    public function test_the_expectations_job_runs_the_script_with_the_base_the_head_and_the_title_taken_from_the_environment(): void
    {
        $job = $this->workflow()['jobs']['saudi-baseline-expectations'];
        $step = collect($job['steps'])->first(fn (array $step): bool => str_contains((string) ($step['run'] ?? ''), 'saudi-baseline-guard.sh'));

        $this->assertSame('Saudi baseline expectations unchanged', $job['name']);
        $this->assertSame("github.event_name == 'pull_request'", $job['if']);
        $this->assertSame('bash ' . self::GUARD, $step['run'], 'no pull request text reaches the shell as text of the step');
        $this->assertSame(['PR_TITLE', 'BASE_SHA', 'HEAD_SHA'], array_keys($step['env']));
        $this->assertSame(0, $job['steps'][0]['with']['fetch-depth'], 'the merge base needs the whole history');
        $this->assertFileExists($this->root() . '/' . self::GUARD);
    }

    // ---- the rule, against real histories ----------------------------------------------------------------------------------------------

    private function git(string ...$arguments): string
    {
        $process = new Process(['git', ...$arguments], $this->repository);
        $process->mustRun();

        return trim($process->getOutput());
    }

    private function write(string $path, string $contents): void
    {
        $full = $this->repository . '/' . $path;
        @mkdir(dirname($full), 0777, true);
        file_put_contents($full, $contents);
    }

    private function commit(string $message): string
    {
        $this->git('add', '-A');
        $this->git('commit', '-q', '-m', $message);

        return $this->git('rev-parse', 'HEAD');
    }

    /** @return array{0: int, 1: string} the exit code and the output of the guard for a branch cut from the baseline */
    private function guard(callable $change, string $title = 'GCC feature'): array
    {
        $base = $this->git('rev-parse', 'HEAD');
        $this->git('checkout', '-q', '-b', 'feature-' . bin2hex(random_bytes(3)));
        $change();
        $head = $this->commit('the pull request');

        $process = new Process(['bash', $this->root() . '/' . self::GUARD], $this->repository, ['BASE_SHA' => $base, 'HEAD_SHA' => $head, 'PR_TITLE' => $title]);
        $process->run();

        return [$process->getExitCode(), $process->getOutput()];
    }

    public function test_a_pull_request_that_adds_a_golden_file_or_changes_other_files_passes(): void
    {
        [$exit, $output] = $this->guard(function (): void {
            $this->write(self::GOLDEN . '/refund.json', "{\"status\":200}\n");
            $this->write('app/Other.php', "<?php // changed\n");
        });

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('unchanged', $output);
    }

    public function test_a_pull_request_that_modifies_an_existing_golden_file_fails_and_names_it(): void
    {
        [$exit, $output] = $this->guard(fn () => $this->write(self::GOLDEN . '/cash_on_delivery.json', "{\"status\":201}\n"));

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Saudi baseline changed', $output);
        $this->assertStringContainsString(self::GOLDEN . '/cash_on_delivery.json', $output);
    }

    public function test_a_pull_request_that_deletes_or_moves_away_an_existing_golden_file_fails(): void
    {
        [$exit] = $this->guard(fn () => unlink($this->repository . '/' . self::GOLDEN . '/cash_on_delivery.json'));
        $this->assertSame(1, $exit, 'a deletion');

        $this->git('checkout', '-q', 'main');

        [$exit] = $this->guard(function (): void {
            $this->write('tests/Fixtures/Elsewhere/cash_on_delivery.json', file_get_contents($this->repository . '/' . self::GOLDEN . '/cash_on_delivery.json'));
            unlink($this->repository . '/' . self::GOLDEN . '/cash_on_delivery.json');
        });
        $this->assertSame(1, $exit, 'a move');
    }

    public function test_the_marker_in_the_title_lets_a_change_through_and_a_near_miss_does_not(): void
    {
        $change = fn () => $this->write(self::GOLDEN . '/cash_on_delivery.json', "{\"status\":201}\n");

        [$exit, $output] = $this->guard($change, 'Saudi wallet rounding [saudi-baseline-change]');
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('a person decided it', $output);

        $this->git('checkout', '-q', 'main');
        foreach (['[saudi-baseline]', 'saudi-baseline-change', '(saudi-baseline-change)'] as $near) {
            [$exit] = $this->guard($change, $near);
            $this->assertSame(1, $exit, $near);
            $this->git('checkout', '-q', 'main');
        }
    }

    public function test_a_title_is_text_and_never_a_command(): void
    {
        $marker = $this->repository . '/pwned';

        [$exit] = $this->guard(fn () => $this->write(self::GOLDEN . '/cash_on_delivery.json', "{\"status\":201}\n"), 'x`touch ' . $marker . '`$(touch ' . $marker . ')"; touch ' . $marker . '; "');

        $this->assertSame(1, $exit);
        $this->assertFileDoesNotExist($marker);
    }

    public function test_a_change_of_the_golden_files_on_the_base_branch_after_the_branch_point_is_not_counted_against_the_pull_request(): void
    {
        $this->git('checkout', '-q', '-b', 'feature');
        $this->write('app/Feature.php', "<?php\n");
        $head = $this->commit('the pull request');
        $this->git('checkout', '-q', 'main');
        $this->write(self::GOLDEN . '/cash_on_delivery.json', "{\"status\":201}\n");
        $base = $this->commit('the owner changed the baseline on main');

        $process = new Process(['bash', $this->root() . '/' . self::GUARD], $this->repository, ['BASE_SHA' => $base, 'HEAD_SHA' => $head, 'PR_TITLE' => 'GCC feature']);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }

    public function test_the_guard_refuses_to_run_without_a_base(): void
    {
        $process = new Process(['bash', $this->root() . '/' . self::GUARD], $this->repository, ['HEAD_SHA' => 'HEAD', 'PR_TITLE' => '']);
        $process->run();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('BASE_SHA', $process->getErrorOutput());
    }
}
