<?php

namespace App\Services\PlatformAdmin;

use App\Models\PlatformAdminAuditCheckpoint;

/** What verifying the audit trail found: that the chain holds, with how much of it was read and where it ends, or the first line that is wrong and why. */
final readonly class AuditVerdict
{
    private function __construct(
        public bool $intact,
        public int $hashedLines,
        public int $unhashedLines,
        public ?int $newestId,
        public ?string $newestHash,
        public ?PlatformAdminAuditCheckpoint $checkpoint,
        public ?int $brokenAt,
        public ?string $reason,
    ) {
    }

    public static function intact(int $hashedLines, int $unhashedLines, ?int $newestId, ?string $newestHash, ?PlatformAdminAuditCheckpoint $checkpoint): self
    {
        return new self(intact: true, hashedLines: $hashedLines, unhashedLines: $unhashedLines, newestId: $newestId, newestHash: $newestHash, checkpoint: $checkpoint, brokenAt: null, reason: null);
    }

    /** @param int|null $at the id of the first line that is wrong, or null when it is not a line (the tail row is missing) */
    public static function broken(?int $at, string $reason): self
    {
        return new self(intact: false, hashedLines: 0, unhashedLines: 0, newestId: null, newestHash: null, checkpoint: null, brokenAt: $at, reason: $reason);
    }

    /** The break as one sentence, for the operator. */
    public function describedBreak(): string
    {
        return $this->brokenAt === null ? (string) $this->reason : "line {$this->brokenAt}: {$this->reason}";
    }
}
