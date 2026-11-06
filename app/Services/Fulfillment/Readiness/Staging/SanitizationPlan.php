<?php

namespace App\Services\Fulfillment\Readiness\Staging;

/**
 * The statements that make a restored copy of production safe to start, written for a person to review and run: nothing in
 * this object has been executed, and nothing in it is a value from the database (it holds statements and names only).
 */
final readonly class SanitizationPlan
{
    /**
     * @param list<array{title: string, why: string, risk: string, statement: string, verification: string}> $steps
     * @param list<string> $skipped targets that do not exist in this database, with the reason
     * @param list<string> $manual steps no statement can do
     */
    public function __construct(
        public string $database,
        public string $driver,
        public string $guard,
        public array $steps,
        public array $skipped,
        public array $manual,
    ) {
    }

    public function render(): string
    {
        $lines = [
            '-- SANITIZATION PLAN. Nothing here has been run: it is statements only (no credential, no customer value).',
            "-- Written for database \"{$this->database}\" ({$this->driver}) from the schema found there. Review every statement, then run it",
            '-- ONLY against the restored staging copy, with a staging-only database user, before any web, queue or cron process starts.',
            '-- A target whose table or column this database lacks is not listed; see "Skipped" at the end.',
            '',
            '-- 0. Guard: this statement fails, and stops a script run with the mysql client, if the connected database is not the expected one.',
            $this->guard . ';',
            '',
        ];

        foreach ($this->steps as $number => $step) {
            $lines[] = '-- ' . ($number + 1) . ". [{$step['risk']}] {$step['title']}";
            if ($step['why'] !== '') {
                $lines[] = "--    why: {$step['why']}";
            }
            $lines[] = $step['statement'] . ';';
            $lines[] = '';
        }

        $lines[] = '-- Verification: run these afterwards. EVERY count below must be 0; a non-zero count is a channel or a person still reachable.';
        foreach ($this->steps as $step) {
            $lines[] = $step['verification'] . ';';
        }

        if ($this->manual !== []) {
            $lines[] = '';
            $lines[] = '-- Manual steps (no portable statement can do them):';
            foreach ($this->manual as $manual) {
                $lines[] = "-- * {$manual}";
            }
        }
        if ($this->skipped !== []) {
            $lines[] = '';
            $lines[] = '-- Skipped, because this database does not have them (the table names come from the code, and several tables exist only in the production dump):';
            foreach ($this->skipped as $skipped) {
                $lines[] = "-- * {$skipped}";
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['database' => $this->database, 'driver' => $this->driver, 'guard' => $this->guard, 'steps' => $this->steps, 'manual' => $this->manual, 'skipped' => $this->skipped];
    }
}
