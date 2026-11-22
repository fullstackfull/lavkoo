<?php

namespace App\Services\PlatformAdmin;

use App\Exceptions\PlatformAdminAuditChainException;
use App\Models\PlatformAdminAuditCheckpoint;
use App\Models\PlatformAdminAuditTail;
use Illuminate\Support\Facades\DB;

/**
 * The only thing that removes lines from the audit trail, and only when an operator runs it. It works on the table itself, because the model refuses.
 *
 * What it takes is the run of lines from the start of the trail that were all written before the cutoff: a line that is newer ends the run, whatever follows
 * it, and the newest line is never in it. What it leaves is a checkpoint, the id and the hash of the last line removed, so that the line that is now first
 * still follows something and verification starts from there; and a line of its own in the chain saying what was cut and by whom. All of it is one transaction
 * that holds the tail, so no writer adds a line between the check that the trail is as it was planned and the removal.
 */
final class PlatformAdminAuditPruner
{
    public function __construct(private readonly PlatformAdminAudit $audit)
    {
    }

    /** @return AuditPrunePlan|null null when no line qualifies */
    public function planFor(int $days): ?AuditPrunePlan
    {
        $cutoff = now()->subDays($days)->format('Y-m-d H:i:s');
        $newestId = DB::table('platform_admin_audit_logs')->max('id');

        if ($newestId === null) {
            return null;
        }

        $firstNotOld = DB::table('platform_admin_audit_logs')->where('created_at', '>=', $cutoff)->min('id');
        $lastRemovable = min(($firstNotOld === null ? (int) $newestId + 1 : (int) $firstNotOld) - 1, (int) $newestId - 1);
        $last = DB::table('platform_admin_audit_logs')->where('id', '<=', $lastRemovable)->orderByDesc('id')->first();

        if ($last === null) {
            return null;
        }

        return new AuditPrunePlan(
            firstId: (int) DB::table('platform_admin_audit_logs')->min('id'),
            lastId: (int) $last->id,
            lines: DB::table('platform_admin_audit_logs')->where('id', '<=', $last->id)->count(),
            lastHash: $last->hash ?? PlatformAdminAuditChain::GENESIS,
            nextPreviousHash: $this->lineAfter(id: (int) $last->id)?->prev_hash,
            cutoff: $cutoff,
        );
    }

    /** @throws PlatformAdminAuditChainException when the trail is no longer the one the plan was made for, or the line of the prune cannot be written because it has no tail */
    public function prune(AuditPrunePlan $plan, int $days): PlatformAdminAuditCheckpoint
    {
        return DB::transaction(function () use ($plan, $days): PlatformAdminAuditCheckpoint {
            $this->holdTheTail();
            $this->requireTheTrailToBeAsPlanned(plan: $plan);

            $operator = ConsoleOperator::describe();
            $checkpoint = PlatformAdminAuditCheckpoint::create([
                'last_line_id' => $plan->lastId,
                'last_hash' => $plan->lastHash,
                'lines_removed' => $plan->lines,
                'days' => $days,
                'operator' => $operator['user'] . '@' . $operator['host'],
                'pruned_at' => now(),
            ]);

            DB::table('platform_admin_audit_logs')->where('id', '<=', $plan->lastId)->delete();

            $this->audit->record(action: 'audit.pruned', context: ConsoleOperator::auditContext(extra: [
                'days' => $days,
                'lines_removed' => $plan->lines,
                'last_removed_id' => $plan->lastId,
                'last_removed_hash' => $plan->lastHash,
            ]));

            return $checkpoint;
        });
    }

    /** Takes the row of the tail and keeps it until the transaction ends, so that no writer adds a line between the check of the plan and the removal. */
    private function holdTheTail(): void
    {
        PlatformAdminAuditTail::query()->lockForUpdate()->find(PlatformAdminAuditTail::ROW);
    }

    /** @throws PlatformAdminAuditChainException */
    private function requireTheTrailToBeAsPlanned(AuditPrunePlan $plan): void
    {
        $last = DB::table('platform_admin_audit_logs')->where('id', $plan->lastId)->first();
        $lines = DB::table('platform_admin_audit_logs')->where('id', '<=', $plan->lastId)->count();

        $asPlanned = $last !== null
            && ($last->hash ?? PlatformAdminAuditChain::GENESIS) === $plan->lastHash
            && $lines === $plan->lines
            && $this->lineAfter(id: $plan->lastId)?->prev_hash === $plan->nextPreviousHash;

        if (!$asPlanned) {
            throw PlatformAdminAuditChainException::trailChanged();
        }
    }

    private function lineAfter(int $id): ?object
    {
        return DB::table('platform_admin_audit_logs')->where('id', '>', $id)->orderBy('id')->first();
    }
}
