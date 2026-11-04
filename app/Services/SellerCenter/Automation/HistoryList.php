<?php

namespace App\Services\SellerCenter\Automation;

use App\Models\SellerAutomationAction;
use App\Models\SellerAutomationRule;
use App\Models\SellerAutomationRun;
use App\Services\SellerCenter\Copy;
use App\Services\SellerCenter\Status;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;

/**
 * Everything automation has done to this shop (handoff 08 A3).
 *
 * The list is of *runs*, not of changes. A run that touched forty products is one thing that
 * happened, and forty rows saying the same thing at the same second is not a history a person can
 * read. The per-record detail lives in the run's drawer, where `before` → `after` and Undo belong.
 *
 * Rows carry the rule's name rather than its id even when the rule has since been deleted: the runs
 * outlive the rule deliberately, and "rule 14 ran" is not an answer to "what changed my prices".
 */
class HistoryList
{
    /** Saved views over the same query (handoff 05 B). */
    public const VIEWS = [
        'all' => ['label' => 'all', 'tone' => 'neutral'],
        'applied' => ['label' => 'automation_outcome_applied', 'tone' => 'good'],
        'no_match' => ['label' => 'automation_outcome_no_match', 'tone' => 'neutral'],
        'capped' => ['label' => 'automation_outcome_capped', 'tone' => 'high'],
        'failed' => ['label' => 'automation_outcome_failed', 'tone' => 'critical'],
    ];

    public function paginate(int $sellerId, Request $request): LengthAwarePaginator
    {
        $query = SellerAutomationRun::where('seller_id', $sellerId)->orderByDesc('id');

        $view = $this->view($request);
        if ($view !== 'all') {
            $query->where('outcome', $view);
        }

        if ($request->filled('rule_id')) {
            $query->where('rule_id', (int) $request->query('rule_id'));
        }

        return $query->paginate($this->pageSize($request))->withQueryString();
    }

    /**
     * The rule name behind each run, in one query.
     *
     * @param  iterable<SellerAutomationRun>  $runs
     * @return array<int, string>
     */
    public function ruleNames(iterable $runs): array
    {
        $ids = collect($runs)->pluck('rule_id')->filter()->unique()->values()->all();

        if ($ids === [] || !Schema::hasTable('seller_automation_rules')) {
            return [];
        }

        return SellerAutomationRule::whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * What a run row says happened, as a sentence with its own numbers.
     *
     * `matched` is never called `applied`: a capped run matched a great many things and changed
     * none of them, and conflating the two is the single most misleading thing this screen could do
     * (handoff 08 A2).
     */
    public function outcomeSentence(SellerAutomationRun $run): string
    {
        return match ($run->outcome) {
            SellerAutomationRun::OUTCOME_APPLIED => Copy::choice(
                'automation_run_applied_one',
                'automation_run_applied_many',
                (int) $run->applied_count,
                ['matched' => (int) $run->matched_count, 'skipped' => (int) $run->skipped_count],
            ),
            SellerAutomationRun::OUTCOME_NO_MATCH => translate('automation_run_no_match_body'),
            SellerAutomationRun::OUTCOME_CAPPED => Copy::line('automation_run_capped_body', [
                'matched' => (int) $run->matched_count,
            ]),
            SellerAutomationRun::OUTCOME_FAILED => $run->message
                ? translate($run->message)
                : translate('automation_run_failed_body'),
            default => '—',
        };
    }

    public function outcomeTone(SellerAutomationRun $run): string
    {
        return match ($run->outcome) {
            SellerAutomationRun::OUTCOME_APPLIED => Status::GOOD,
            SellerAutomationRun::OUTCOME_CAPPED => Status::HIGH,
            SellerAutomationRun::OUTCOME_FAILED => Status::CRITICAL,
            default => Status::NEUTRAL,
        };
    }

    /** How long the run took, or null while it has not finished. */
    public function duration(SellerAutomationRun $run): ?string
    {
        if ($run->started_at === null || $run->finished_at === null) {
            return null;
        }

        $seconds = max(0, $run->finished_at->getTimestamp() - $run->started_at->getTimestamp());

        return $seconds < 60
            ? Copy::line('n_seconds', ['count' => $seconds])
            : Copy::duration(intdiv($seconds, 60));
    }

    /**
     * The per-record detail behind one run.
     *
     * @return array<int, SellerAutomationAction>
     */
    public function records(SellerAutomationRun $run): array
    {
        return SellerAutomationAction::where('run_id', $run->id)
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->all();
    }

    public function view(Request $request): string
    {
        $view = (string) ($request->query('view') ?? 'all');

        return array_key_exists($view, self::VIEWS) ? $view : 'all';
    }

    private function pageSize(Request $request): int
    {
        $size = (int) $request->query('size', 25);

        return in_array($size, [25, 50, 100], true) ? $size : 25;
    }
}
