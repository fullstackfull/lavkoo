<?php

namespace App\Services\PlatformAdmin;

use App\Models\PlatformAdminAuditCheckpoint;
use App\Models\PlatformAdminAuditTail;
use Illuminate\Support\Facades\DB;

/**
 * Reads the whole audit trail in order and says whether the chain holds: every hashed line follows the hash of the line before it and hashes to its own content,
 * and the end of the trail is where the tail says it is. It starts from the newest checkpoint if a prune left one. It reads only, and names the first line that is
 * wrong. A line with no hash is read as older than the chain; one that turns up after hashed lines is a break.
 */
final class PlatformAdminAuditVerifier
{
    private const READ_SIZE = 500;

    public function verify(): AuditVerdict
    {
        $checkpoint = PlatformAdminAuditCheckpoint::query()->orderByDesc('id')->first();
        $expectedPrevious = $checkpoint?->last_hash ?? PlatformAdminAuditChain::GENESIS;
        $hashedSeen = $checkpoint !== null && $checkpoint->last_hash !== PlatformAdminAuditChain::GENESIS;
        $hashedLines = 0;
        $unhashedLines = 0;
        $firstHashedId = null;
        $newestId = null;
        $newestHash = null;

        foreach (DB::table('platform_admin_audit_logs')->lazyById(self::READ_SIZE) as $row) {
            $id = (int) $row->id;

            if ($checkpoint !== null && $id <= $checkpoint->last_line_id) {
                return AuditVerdict::broken(at: $id, reason: 'it is still there, though the checkpoint says every line up to ' . $checkpoint->last_line_id . ' was removed.');
            }

            if ($row->hash === null) {
                if ($hashedSeen || $row->prev_hash !== null) {
                    return AuditVerdict::broken(at: $id, reason: 'it has no hash, but comes after lines that have one, so it was not written by the writer.');
                }

                $unhashedLines++;

                continue;
            }

            if (!hash_equals($expectedPrevious, (string) $row->prev_hash)) {
                return AuditVerdict::broken(at: $id, reason: 'it does not follow the line before it: that line was changed or removed, or this one was put here.');
            }

            if (!hash_equals(PlatformAdminAuditChain::hashOfRow(row: $row), (string) $row->hash)) {
                return AuditVerdict::broken(at: $id, reason: 'its content does not match its hash: it was changed after it was written.');
            }

            $hashedSeen = true;
            $expectedPrevious = (string) $row->hash;
            $hashedLines++;
            $firstHashedId ??= $id;
            $newestId = $id;
            $newestHash = $expectedPrevious;
        }

        return $this->comparedWithTheTail(hashedLines: $hashedLines, unhashedLines: $unhashedLines, firstHashedId: $firstHashedId, newestId: $newestId, newestHash: $newestHash, checkpoint: $checkpoint);
    }

    private function comparedWithTheTail(int $hashedLines, int $unhashedLines, ?int $firstHashedId, ?int $newestId, ?string $newestHash, ?PlatformAdminAuditCheckpoint $checkpoint): AuditVerdict
    {
        $tail = PlatformAdminAuditTail::query()->find(PlatformAdminAuditTail::ROW);

        if ($tail === null) {
            return AuditVerdict::broken(at: null, reason: 'the tail row is missing: nothing says which line was written last, so lines removed from the end cannot be seen.');
        }

        $tailId = $tail->last_line_id;

        if ($tailId === null && $newestId === null && $tail->last_hash === null) {
            return AuditVerdict::intact(hashedLines: $hashedLines, unhashedLines: $unhashedLines, newestId: null, newestHash: null, checkpoint: $checkpoint);
        }

        if ($tailId === $newestId && $tail->last_hash === $newestHash) {
            return AuditVerdict::intact(hashedLines: $hashedLines, unhashedLines: $unhashedLines, newestId: $newestId, newestHash: $newestHash, checkpoint: $checkpoint);
        }

        return $this->brokenAtTheEnd(tailId: $tailId, firstHashedId: $firstHashedId, newestId: $newestId);
    }

    private function brokenAtTheEnd(?int $tailId, ?int $firstHashedId, ?int $newestId): AuditVerdict
    {
        if ($tailId === null) {
            return AuditVerdict::broken(at: $firstHashedId, reason: 'it is a hashed line, and the tail says none was written, so it was added without the writer.');
        }

        if ($newestId === null || $tailId > $newestId) {
            return AuditVerdict::broken(at: $tailId, reason: 'it is missing: the tail says it was the last line written, so the newest lines were removed.');
        }

        if ($tailId < $newestId) {
            $unknown = (int) DB::table('platform_admin_audit_logs')->where('id', '>', $tailId)->min('id');

            return AuditVerdict::broken(at: $unknown, reason: "it is not known to the tail, which says line {$tailId} was the last one written, so it was added without the writer.");
        }

        return AuditVerdict::broken(at: $newestId, reason: 'it is the newest line, and the tail holds another hash for it than it has.');
    }
}
