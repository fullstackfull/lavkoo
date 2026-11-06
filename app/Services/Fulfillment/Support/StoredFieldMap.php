<?php

namespace App\Services\Fulfillment\Support;

final readonly class StoredFieldMap
{
    /**
     * @param array<string, string|null> $paths
     * @param array{sampled?: int, non_empty?: array<string, int>} $coverage what the sample showed when the map was verified; empty while it is not
     */
    public function __construct(
        public ?string $itemsPath,
        public array $paths,
        public ?string $verifiedAt,
        public bool $verified,
        public array $coverage = [],
    ) {
    }

    /** Sampled records in which this logical field carried a value when the map was verified, or null when that was not recorded. */
    public function filledIn(string $logical): ?int
    {
        $count = $this->coverage['non_empty'][$logical] ?? null;

        return is_int($count) ? $count : null;
    }

    public function map(): FieldMap
    {
        return new FieldMap($this->paths);
    }
}
