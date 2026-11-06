<?php

namespace App\Services\Fulfillment\Readiness;

/** One line of a readiness report: what was checked, whether it is fine, and the evidence. */
final readonly class PreflightCheck
{
    public const PASS = 'pass';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    public function __construct(
        public string $group,
        public string $name,
        public string $status,
        public string $detail,
    ) {
    }

    public function failed(): bool
    {
        return $this->status === self::FAIL;
    }
}
