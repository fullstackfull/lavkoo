<?php

namespace App\Services\Fulfillment\Verification;

/** One run of the read-only checks against one provider. */
final readonly class VerificationRun
{
    /**
     * @param array<string, mixed> $environment mode, base URL host, company id: nothing secret
     * @param list<CheckResult> $checks
     */
    public function __construct(
        public string $id,
        public string $provider,
        public array $environment,
        public string $startedAt,
        public int $durationMs,
        public array $checks,
    ) {
    }

    /** @return array{pass: int, warn: int, fail: int, skipped: int} */
    public function summary(): array
    {
        $summary = [CheckResult::PASS => 0, CheckResult::WARN => 0, CheckResult::FAIL => 0, CheckResult::SKIPPED => 0];
        foreach ($this->checks as $check) {
            $summary[$check->status]++;
        }

        return $summary;
    }

    public function failed(): bool
    {
        return $this->summary()[CheckResult::FAIL] > 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'format' => 1,
            'run_id' => $this->id,
            'provider' => $this->provider,
            'environment' => $this->environment,
            'started_at' => $this->startedAt,
            'duration_ms' => $this->durationMs,
            'summary' => $this->summary(),
            'checks' => array_map(static fn (CheckResult $check): array => $check->toArray(), $this->checks),
            'note' => 'Read-only. Holds no credential, token, name, phone number, e-mail address, street address or note: a value under a personal key is replaced, and so is any string that looks like an e-mail address or a phone number. It records the SHAPE of what each provider answered, which is what a field map is finalised from.',
        ];
    }
}
