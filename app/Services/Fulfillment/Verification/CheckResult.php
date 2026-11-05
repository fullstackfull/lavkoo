<?php

namespace App\Services\Fulfillment\Verification;

/** The outcome of one read-only check: what was asked, what came back (as a profile, never as data), and what it means. */
final readonly class CheckResult
{
    public const PASS = 'pass';
    public const WARN = 'warn';
    public const FAIL = 'fail';
    public const SKIPPED = 'skipped';

    /**
     * @param array<string, mixed> $request method, path and query: no header, no credential
     * @param array<string, mixed> $findings the answers read out of the response (see ResponseShape::findings)
     * @param array<string, mixed>|null $shape the profile of the response
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $operation,
        public string $status,
        public ?int $httpStatus,
        public int $durationMs,
        public string $summary,
        public array $request = [],
        public array $findings = [],
        public ?array $shape = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'label' => $this->label,
            'operation' => $this->operation,
            'status' => $this->status,
            'http_status' => $this->httpStatus,
            'duration_ms' => $this->durationMs,
            'summary' => $this->summary,
            'request' => $this->request,
            'findings' => $this->findings,
            'shape' => $this->shape,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
