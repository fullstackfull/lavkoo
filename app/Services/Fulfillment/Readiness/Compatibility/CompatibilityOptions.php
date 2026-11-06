<?php

namespace App\Services\Fulfillment\Readiness\Compatibility;

/** What the operator asked the compatibility check to do. */
final readonly class CompatibilityOptions
{
    public const DEFAULT_SAMPLE = 2000;
    public const MAX_SAMPLE = 50000;
    public const MIN_SAMPLE = 100;

    /** The most distinct values of one enumeration column that are listed (the rest are counted). */
    public const MAX_LISTED_VALUES = 25;

    public int $sample;

    public function __construct(
        int $sample = self::DEFAULT_SAMPLE,
        public ?string $connection = null,
        public bool $allowProduction = false,
        public ?string $expectDatabase = null,
    ) {
        $this->sample = max(self::MIN_SAMPLE, min(self::MAX_SAMPLE, $sample));
    }
}
