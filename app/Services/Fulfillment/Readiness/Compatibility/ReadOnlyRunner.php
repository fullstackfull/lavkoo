<?php

namespace App\Services\Fulfillment\Readiness\Compatibility;

use Closure;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Runs a read-only inspection of a restored database: decides whether the run may start at all (looking only at
 * configuration, never at the database), locks the session read-only for its duration, and returns the findings together with
 * the proof that nothing was written. Shared by every command that inspects a copy of production.
 */
final class ReadOnlyRunner
{
    private const SECTION = 'safety';

    /** Drivers for which the session can be locked read-only (see ReadOnlyProbe). */
    private const SUPPORTED_DRIVERS = ['mysql', 'mariadb', 'sqlite'];

    /** @param Closure(ReadOnlyProbe): list<CompatibilityCheck> $collect */
    public function run(CompatibilityOptions $options, Closure $collect): CompatibilityReport
    {
        $name = $options->connection ?? (string) config('database.default');

        $refusal = $this->refusal($name, $options);
        if ($refusal !== null) {
            return new CompatibilityReport(checks: [$refusal], environment: ['app_env' => app()->environment()], sample: 0, readOnlyProof: null, refused: true);
        }

        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection($name);

        try {
            $probe = new ReadOnlyProbe(DB::connection($name));
            $lockedDuringRun = false;
            $checks = $probe->run(function (ReadOnlyProbe $probe) use ($collect, &$lockedDuringRun): array {
                $lockedDuringRun = $probe->sessionLocked();

                return $collect($probe);
            });

            return new CompatibilityReport(
                checks: [...$checks, $this->readOnlyCheck($probe, $lockedDuringRun)],
                environment: $this->environment($name, $probe),
                sample: $options->sample,
                readOnlyProof: ['session_locked' => $lockedDuringRun, 'statements' => count($probe->statements()), 'write_statements' => count($probe->writeStatements())],
            );
        } finally {
            DB::setDefaultConnection($previous);
        }
    }

    /** Looks only at configuration: nothing is read from the database before the run is allowed. */
    private function refusal(string $connection, CompatibilityOptions $options): ?CompatibilityCheck
    {
        $settings = config("database.connections.{$connection}");

        return match (true) {
            !is_array($settings) => $this->refused('connection', "there is no database connection named '{$connection}'"),
            !in_array($settings['driver'] ?? null, self::SUPPORTED_DRIVERS, true) => $this->refused('connection', "the '" . ($settings['driver'] ?? 'unknown') . "' driver cannot be locked read-only here, so the check will not run on it"),
            app()->environment('production') && !$options->allowProduction => $this->refused('environment', 'APP_ENV is production. This check is meant for a restored COPY; pass --allow-production-read-only only if you accept that it will read (never write) the live database.'),
            $options->expectDatabase !== null && (string) ($settings['database'] ?? '') !== $options->expectDatabase => $this->refused('database', "the connection points at a different database than the one you expected ('{$options->expectDatabase}'); nothing was read"),
            default => null,
        };
    }

    private function refused(string $name, string $detail): CompatibilityCheck
    {
        return new CompatibilityCheck(self::SECTION, "refused: {$name}", CompatibilityCheck::FAIL, $detail);
    }

    private function readOnlyCheck(ReadOnlyProbe $probe, bool $lockedDuringRun): CompatibilityCheck
    {
        $writes = $probe->writeStatements();
        $clean = $writes === [] && $lockedDuringRun;

        return new CompatibilityCheck(
            section: 'read_only',
            name: 'nothing was written',
            status: $clean ? CompatibilityCheck::PASS : CompatibilityCheck::FAIL,
            detail: $clean
                ? count($probe->statements()) . ' statements were run in a read-only session, and every one was a read'
                : 'THE CHECK ISSUED ' . count($writes) . ' STATEMENT(S) THAT WERE NOT READS, OR COULD NOT LOCK THE SESSION. This is a defect in the check: stop, and inspect the database.',
            facts: $clean ? ['statements' => count($probe->statements())] : ['statements' => count($probe->statements()), 'not_reads' => array_map(static fn (string $sql): string => mb_substr($sql, 0, 120), array_slice($writes, 0, 3))],
        );
    }

    /** @return array<string, string> */
    private function environment(string $connection, ReadOnlyProbe $probe): array
    {
        return [
            'app_env' => app()->environment(),
            'driver' => $probe->driver(),
            'server_version' => (string) DB::connection($connection)->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION),
            'database' => $probe->databaseName(),
        ];
    }
}
