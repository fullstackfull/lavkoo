<?php

namespace App\Services\SellerIntelligence;

use App\Models\SellerInsight;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Schema;

/**
 * Problems get worse by being ignored.
 *
 * The severity engine measures how bad a finding is when it is detected. This measures what happens
 * afterwards: an issue that was medium yesterday, is past its deadline today and nobody has touched
 * is not still medium. Without this, a seller who ignores everything sees the same list forever and
 * the platform never says anything louder.
 *
 * Three properties make it escalation rather than noise:
 *
 * **It only ever climbs, and only one step at a time.** `escalation_level` records how far a row has
 * been promoted, so a sweep that runs hourly does not promote the same issue hourly. Running it
 * twice in a minute changes nothing the second time.
 *
 * **An issue somebody is working on does not escalate.** Acknowledged and in-progress are answers.
 * Escalating them would punish the seller for telling the truth about what they are doing, and teach
 * them not to.
 *
 * **It stops at critical.** There is nothing above it, and a level that kept counting would be a
 * number nobody could act on differently.
 */
class IssueEscalationService
{
    /**
     * How long an untouched issue may stand at each severity before it is promoted.
     *
     * Deliberately not uniform. A low-severity finding standing for a week is a shop with a habit;
     * a high-severity one standing for a day is a shop with a problem. The gaps narrow as the
     * severity rises because the cost of waiting rises with it.
     *
     * @var array<string, int>
     */
    public const PROMOTE_AFTER_HOURS = [
        SellerInsight::SEVERITY_LOW => 336,     // two weeks
        SellerInsight::SEVERITY_MEDIUM => 168,  // one week
        SellerInsight::SEVERITY_HIGH => 48,     // two days
    ];

    /** An issue past its own deadline is promoted regardless of how long it has stood. */
    public const PROMOTE_ON_OVERDUE = true;

    /** No issue is promoted more than this many times, however long it stands. */
    public const MAX_ESCALATION_LEVEL = 3;

    /** @var array<string, string> */
    private const NEXT_SEVERITY = [
        SellerInsight::SEVERITY_LOW => SellerInsight::SEVERITY_MEDIUM,
        SellerInsight::SEVERITY_MEDIUM => SellerInsight::SEVERITY_HIGH,
        SellerInsight::SEVERITY_HIGH => SellerInsight::SEVERITY_CRITICAL,
    ];

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Promote everything that has earned it.
     *
     * @return array{escalated: int}
     */
    public function sweep(int|string|null $sellerId = null): array
    {
        if (!Schema::hasTable('seller_insights')) {
            return ['escalated' => 0];
        }

        $escalated = 0;

        $candidates = SellerInsight::query()
            ->open()
            // Somebody is on it. Escalating would punish a seller for saying so.
            ->whereIn('status', [SellerInsight::STATUS_DETECTED, SellerInsight::STATUS_OPEN])
            ->where('severity', '!=', SellerInsight::SEVERITY_CRITICAL)
            ->where('escalation_level', '<', self::MAX_ESCALATION_LEVEL)
            ->when($sellerId !== null, fn ($query) => $query->where('seller_id', $sellerId))
            ->orderBy('id')
            ->limit(1000)
            ->get();

        foreach ($candidates as $issue) {
            if (!$this->shouldPromote($issue)) {
                continue;
            }

            $this->promote($issue);
            $escalated++;
        }

        return ['escalated' => $escalated];
    }

    /**
     * Has this issue earned a promotion?
     *
     * Either it has run out of time, or it has been standing longer than a finding of its severity
     * should have to.
     */
    public function shouldPromote(SellerInsight $issue): bool
    {
        if (!isset(self::NEXT_SEVERITY[$issue->severity])) {
            return false;
        }

        if (self::PROMOTE_ON_OVERDUE && $issue->isOverdue()) {
            // A deadline can only be missed once, so a second promotion for the same overdue issue
            // has to come from the standing-time rule rather than from being late again.
            return $issue->escalation_level === 0 || $this->hasStoodLongEnough($issue);
        }

        return $this->hasStoodLongEnough($issue);
    }

    private function hasStoodLongEnough(SellerInsight $issue): bool
    {
        $threshold = self::PROMOTE_AFTER_HOURS[$issue->severity] ?? null;

        if ($threshold === null) {
            return false;
        }

        // Measured from the last promotion where there was one, so each level has to be earned
        // separately rather than three of them landing at once on an old row.
        $since = $issue->escalation_level > 0
            ? ($issue->updated_at ?? $issue->first_detected_at)
            : ($issue->first_detected_at ?? $issue->created_at);

        return $since !== null && $since->diffInHours(now()) >= $threshold;
    }

    private function promote(SellerInsight $issue): void
    {
        $from = $issue->severity;
        $to = self::NEXT_SEVERITY[$from];

        $issue->forceFill([
            'severity' => $to,
            'escalation_level' => $issue->escalation_level + 1,
            'metadata' => array_merge($issue->metadata ?? [], [
                'escalations' => array_merge($issue->metadata['escalations'] ?? [], [[
                    'from' => $from,
                    'to' => $to,
                    'at' => now()->toIso8601String(),
                    'reason' => $issue->isOverdue() ? 'overdue' : 'unattended',
                ]]),
            ]),
        ])->save();

        $this->audit->record(
            action: 'seller.issue_escalated',
            subject: ['type' => 'seller_insight', 'id' => $issue->id],
            before: ['severity' => $from],
            after: ['severity' => $to, 'escalation_level' => $issue->escalation_level],
            context: ['seller_id' => $issue->seller_id, 'type' => $issue->type],
        );
    }
}
