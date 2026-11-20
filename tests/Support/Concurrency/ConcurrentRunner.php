<?php

namespace Tests\Support\Concurrency;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs one operation in several PHP processes at the same instant, against the real MySQL/MariaDB the test is connected to, and returns what each process reported.
 *
 * SQLite in memory is private to one process, so none of this works there: `available()` says so and the tests skip. The processes boot the application with the environment of
 * the parent (the database settings come from it), wait for a common start time, run the named operation of `Operations`, and print one JSON line.
 */
final class ConcurrentRunner
{
    /** How long the processes are given to boot before the common start time. */
    private const BOOT_SECONDS = 6.0;

    public static function available(): bool
    {
        return in_array((string) config('database.default'), ['mysql', 'mariadb'], true)
            && (string) config('database.connections.' . config('database.default') . '.driver') === 'mysql'
            && getenv('CONCURRENCY_TESTS') !== '0';
    }

    /**
     * @param list<array<string, mixed>> $arguments one entry per process (its own arguments for the operation). An entry may
     *                                              carry its own `op` to run a DIFFERENT operation in that process, which is
     *                                              how two different actions on one row are raced against each other
     *                                              rather than merely run one after the other.
     * @return list<array{ok: bool, result?: mixed, error?: string, message?: string, started_at: float, finished_at: float, pid: int}>
     */
    public static function run(string $operation, array $arguments): array
    {
        $startAt = microtime(true) + self::BOOT_SECONDS;
        $rendezvous = self::rendezvousDirectory();
        $processes = [];
        foreach ($arguments as $index => $args) {
            $own = (string) ($args['op'] ?? $operation);
            unset($args['op']);
            $spec = json_encode(['op' => $own, 'args' => $args, 'start_at' => $startAt, 'index' => $index, 'rendezvous' => $rendezvous], JSON_THROW_ON_ERROR);
            $command = [PHP_BINARY];
            $prepend = (string) ini_get('auto_prepend_file');
            if ($prepend !== '') {
                $command[] = '-d';
                $command[] = 'auto_prepend_file=' . $prepend;
            }
            $command[] = __DIR__ . '/worker.php';
            $command[] = $spec;
            $process = new Process($command, base_path(), self::environment(), null, 120);
            $process->start();
            $processes[$index] = $process;
        }

        try {
            return self::reportsOf($processes);
        } finally {
            self::forget($rendezvous);
        }
    }

    /**
     * Waits for every process and reads the one JSON line each prints.
     *
     * @param  array<int, Process> $processes
     * @return list<array{ok: bool, result?: mixed, error?: string, message?: string, started_at: float, finished_at: float, pid: int}>
     */
    private static function reportsOf(array $processes): array
    {
        $reports = [];
        foreach ($processes as $index => $process) {
            $process->wait();
            $lines = array_values(array_filter(array_map('trim', explode("\n", $process->getOutput()))));
            $last = $lines === [] ? '' : $lines[array_key_last($lines)];
            $decoded = json_decode($last, true);
            if (!is_array($decoded)) {
                throw new RuntimeException("Worker {$index} gave no report. Exit code {$process->getExitCode()}. Output: " . substr($process->getOutput(), 0, 500) . ' Errors: ' . substr($process->getErrorOutput(), 0, 1500));
            }
            $reports[] = $decoded;
        }

        return $reports;
    }

    /** A directory of this run's own for the marks the processes hold each other at (Rendezvous), so one run cannot release the processes of another. */
    private static function rendezvousDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/concurrent-runner-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0o700) && !is_dir($directory)) {
            throw new RuntimeException("Could not make the rendezvous directory {$directory}.");
        }

        return $directory;
    }

    private static function forget(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $mark) {
            unlink($mark);
        }
        rmdir($directory);
    }

    /** The settings phpunit.mysql.xml gives the test process through <server>, which a child process does not inherit. */
    private static function environment(): array
    {
        return ['APP_ENV' => 'testing', 'BCRYPT_ROUNDS' => '4', 'CACHE_DRIVER' => 'array', 'MAIL_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array'];
    }

    /**
     * True when at least two of the reports describe processes that were running at the same time.
     *
     * READ WHAT THIS DOES NOT SAY. It is not evidence that two critical sections interleaved, and it was
     * cited as that for a while. Every process of a run is released from one common start barrier and
     * stamps `started_at` immediately after it, so any two windows of any run overlap and this answers
     * true whatever the operations did inside them — including a run where one operation ran entirely
     * after the other had committed. What it is good for is the thing it names: the processes really were
     * alive together, so a test asserting an invariant over whatever order they landed in is sampling
     * real concurrency rather than a queue.
     *
     * A test whose subject is ONE interleaving cannot be built on this. It has to arrange that
     * interleaving and show it was arranged: see Rendezvous.
     *
     * @param list<array{started_at: float, finished_at: float}> $reports
     */
    public static function ranAtTheSameTime(array $reports): bool
    {
        foreach ($reports as $i => $a) {
            foreach ($reports as $j => $b) {
                if ($i < $j && $a['started_at'] < $b['finished_at'] && $b['started_at'] < $a['finished_at']) {
                    return true;
                }
            }
        }

        return false;
    }
}
