<?php

namespace App\Services\Fulfillment\Readiness\Compatibility;

use App\Services\Fulfillment\Readiness\PreflightCheck;

/**
 * One finding of the compatibility report. `facts` holds only aggregates (counts, names of tables and columns,
 * enumeration values that passed the printable test): never a value taken from a customer's, a vendor's or an order's data.
 */
final readonly class CompatibilityCheck
{
    public const PASS = PreflightCheck::PASS;
    public const WARN = PreflightCheck::WARN;
    public const FAIL = PreflightCheck::FAIL;
    /** A fact worth knowing that is neither good nor bad: volumes, distributions. */
    public const INFO = 'info';

    /** @param array<string, mixed> $facts */
    public function __construct(
        public string $section,
        public string $name,
        public string $status,
        public string $detail,
        public array $facts = [],
    ) {
    }

    public static function fromPreflight(PreflightCheck $check): self
    {
        return new self(section: $check->group, name: $check->name, status: $check->status, detail: $check->detail);
    }

    public function failed(): bool
    {
        return $this->status === self::FAIL;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'section' => $this->section,
            'name' => $this->name,
            'status' => $this->status,
            'detail' => $this->detail,
            'facts' => $this->facts,
        ], static fn (mixed $value): bool => $value !== []);
    }
}
