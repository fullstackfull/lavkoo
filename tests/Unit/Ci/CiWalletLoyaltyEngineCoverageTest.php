<?php

namespace Tests\Unit\Ci;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The wallet and loyalty locks are only proven on a real engine: SQLite has no row locks, and the concurrency tests skip there.
 *
 * This reads the CI workflow and expands each job's matrix the way GitHub does, so a directory dropped from a job, or a job that
 * stops expecting a real engine, fails here instead of leaving those tests skipped or unrun on every engine.
 */
class CiWalletLoyaltyEngineCoverageTest extends TestCase
{
    private const DATABASE_GUARD = 'tests/Feature/CiDatabaseTest.php';

    /** @return array<string, array{0: string, 1: string}> */
    public static function directoriesAndEngines(): array
    {
        $cases = [];
        foreach (['tests/Feature/Wallet', 'tests/Feature/Loyalty', 'tests/Feature/Concurrency'] as $directory) {
            foreach (['mysql:', 'mariadb:'] as $imagePrefix) {
                $cases[$directory . ' on ' . rtrim($imagePrefix, ':')] = [$directory, $imagePrefix];
            }
        }

        return $cases;
    }

    #[DataProvider('directoriesAndEngines')]
    public function test_the_directory_runs_on_the_engine_in_a_job_that_expects_a_real_engine(string $directory, string $imagePrefix): void
    {
        $covering = array_filter(
            $this->engineRuns(),
            fn (array $run): bool => str_starts_with($run['image'], $imagePrefix) && $this->runsDirectory(paths: $run['paths'], directory: $directory),
        );

        $this->assertNotEmpty($covering, "No CI job runs {$directory} on a {$imagePrefix} service without a --filter, so those tests run on no such engine.");
        foreach ($covering as $run) {
            $this->assertSame('mysql', $run['expectedDriver'], "{$run['job']} runs {$directory} without EXPECT_DB_DRIVER=mysql, so a run that fell back to SQLite would skip silently.");
        }
    }

    public function test_every_job_running_the_concurrency_tests_also_proves_its_engine(): void
    {
        $concurrencyRuns = array_filter(
            $this->engineRuns(),
            fn (array $run): bool => $this->runsDirectory(paths: $run['paths'], directory: 'tests/Feature/Concurrency'),
        );

        $this->assertNotEmpty($concurrencyRuns);
        foreach ($concurrencyRuns as $run) {
            $this->assertContains(self::DATABASE_GUARD, $run['paths'], "{$run['job']} runs the concurrency tests without " . self::DATABASE_GUARD . ', so a misconfigured engine would skip them instead of failing.');
        }
    }

    public function test_the_workflow_never_switches_the_concurrency_tests_off(): void
    {
        $this->assertStringNotContainsString('CONCURRENCY_TESTS', $this->workflowSource());
    }

    /**
     * One entry per job and matrix combination that runs phpunit against a database service.
     *
     * @return list<array{job: string, image: string, paths: list<string>, expectedDriver: string}>
     */
    private function engineRuns(): array
    {
        $workflow = Yaml::parse($this->workflowSource());
        $runs = [];
        foreach ($workflow['jobs'] as $jobId => $job) {
            $image = $job['services']['mysql']['image'] ?? null;
            if ($image === null) {
                continue;
            }
            $command = $this->phpunitCommand($job);
            foreach ($this->matrixCombinations($job['strategy']['matrix'] ?? []) as $combination) {
                $runs[] = [
                    'job' => $jobId . ' ' . json_encode($combination),
                    'image' => $this->substitute(text: $image, combination: $combination),
                    'paths' => $this->phpunitPaths($this->substitute(text: $command, combination: $combination)),
                    'expectedDriver' => (string) ($job['env']['EXPECT_DB_DRIVER'] ?? ''),
                ];
            }
        }

        $this->assertNotEmpty($runs, 'no job with a database service was found, so this guard proved nothing');

        return $runs;
    }

    private function phpunitCommand(array $job): string
    {
        foreach ($job['steps'] as $step) {
            if (str_contains((string) ($step['run'] ?? ''), 'vendor/bin/phpunit')) {
                return (string) $step['run'];
            }
        }
        $this->fail('a job with a database service runs no phpunit step');
    }

    /**
     * GitHub's expansion: the product of the matrix keys, minus exclusions, each include merged into every combination that agrees
     * with it on the original keys. This workflow has no include that adds a combination of its own.
     *
     * @return list<array<string, string>>
     */
    private function matrixCombinations(array $matrix): array
    {
        $includes = $matrix['include'] ?? [];
        $excludes = $matrix['exclude'] ?? [];
        unset($matrix['include'], $matrix['exclude']);

        $combinations = [[]];
        foreach ($matrix as $key => $values) {
            $expanded = [];
            foreach ($combinations as $combination) {
                foreach ($values as $value) {
                    $expanded[] = $combination + [$key => (string) $value];
                }
            }
            $combinations = $expanded;
        }

        $combinations = array_values(array_filter(
            $combinations,
            fn (array $combination): bool => !array_filter($excludes, fn (array $exclude): bool => $this->agrees(combination: $combination, entry: $exclude, keys: array_keys($exclude))),
        ));

        foreach ($combinations as $index => $combination) {
            foreach ($includes as $include) {
                if ($this->agrees(combination: $combination, entry: $include, keys: array_keys($matrix))) {
                    $combinations[$index] += array_map('strval', $include);
                }
            }
        }

        return $combinations;
    }

    private function agrees(array $combination, array $entry, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $entry) && (string) $entry[$key] !== ($combination[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function substitute(string $text, array $combination): string
    {
        return preg_replace_callback('/\$\{\{\s*matrix\.(\w+)\s*\}\}/', fn (array $match): string => $combination[$match[1]] ?? '', $text);
    }

    /**
     * The test paths a phpunit command is handed. A command with --filter narrows them by name, so it is counted as covering nothing.
     *
     * @return list<string>
     */
    private function phpunitPaths(string $command): array
    {
        if (str_contains($command, '--filter')) {
            return [];
        }

        return array_values(array_filter(
            preg_split('/\s+/', $command),
            fn (string $token): bool => str_starts_with($token, 'tests/'),
        ));
    }

    private function runsDirectory(array $paths, string $directory): bool
    {
        foreach ($paths as $path) {
            $path = rtrim($path, '/');
            if ($path === $directory || str_starts_with($directory . '/', $path . '/')) {
                return true;
            }
        }

        return false;
    }

    private function workflowSource(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/.github/workflows/ci.yml');
    }
}
