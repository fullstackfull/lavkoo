<?php

namespace App\Services\Fulfillment\Readiness\Compatibility;

/** The outcome of one compatibility run, and the proof that it only read. */
final readonly class CompatibilityReport
{
    public const COMPATIBLE = 'compatible';
    public const COMPATIBLE_WITH_WARNINGS = 'compatible_with_warnings';
    public const NOT_COMPATIBLE = 'not_compatible';
    public const REFUSED = 'refused';

    /**
     * @param list<CompatibilityCheck> $checks
     * @param array<string, mixed> $environment driver, server version, database name: never a host, a user or a password
     * @param array{session_locked: bool, statements: int, write_statements: int}|null $readOnlyProof null when the run was refused before the database was touched
     */
    public function __construct(
        public array $checks,
        public array $environment,
        public int $sample,
        public ?array $readOnlyProof,
        public bool $refused = false,
    ) {
    }

    public function verdict(): string
    {
        return match (true) {
            $this->refused => self::REFUSED,
            $this->count(CompatibilityCheck::FAIL) > 0 => self::NOT_COMPATIBLE,
            $this->count(CompatibilityCheck::WARN) > 0 => self::COMPATIBLE_WITH_WARNINGS,
            default => self::COMPATIBLE,
        };
    }

    public function hasFailures(): bool
    {
        return $this->count(CompatibilityCheck::FAIL) > 0;
    }

    public function count(string $status): int
    {
        return count(array_filter($this->checks, static fn (CompatibilityCheck $check): bool => $check->status === $status));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict(),
            'scope' => 'Structure and the aggregate shape of the data only. It does not prove that checkout, routing or dispatch work: run the scenarios in docs/integrations/staging-validation.md on the restored copy.',
            'environment' => $this->environment,
            'sample_rows' => $this->sample,
            'counts' => [
                'pass' => $this->count(CompatibilityCheck::PASS),
                'warn' => $this->count(CompatibilityCheck::WARN),
                'fail' => $this->count(CompatibilityCheck::FAIL),
                'info' => $this->count(CompatibilityCheck::INFO),
            ],
            'read_only_proof' => $this->readOnlyProof,
            'checks' => array_map(static fn (CompatibilityCheck $check): array => $check->toArray(), $this->checks),
        ];
    }
}
