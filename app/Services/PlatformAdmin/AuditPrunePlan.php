<?php

namespace App\Services\PlatformAdmin;

/**
 * What a prune is about to remove: the run of lines from the start of the trail that were all written before the cutoff, and what the line after the run looks
 * like now, so that a prune can tell that the trail is still the one it was planned for.
 */
final readonly class AuditPrunePlan
{
    public function __construct(
        public int $firstId,
        public int $lastId,
        public int $lines,
        public string $lastHash,
        public ?string $nextPreviousHash,
        public string $cutoff,
    ) {
    }
}
