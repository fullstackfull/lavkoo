<?php

namespace App\Http\Controllers\Seller;

use App\Models\SellerAutomationAction;
use App\Models\SellerAutomationRun;
use App\Services\SellerAutomation\AutomationEngine;
use App\Services\SellerCenter\Automation\HistoryList;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * What automation has actually done to this shop, and putting one change back (handoff 08 A3).
 *
 * The history is the answer to "who changed this" when the answer is not a person, so it outlives
 * the rules: deleting a rule does not un-happen what it did, and a run whose rule is gone still
 * names what it touched.
 */
class AutomationHistoryController extends SellerCenterController
{
    public function __construct(
        private readonly HistoryList $history,
        private readonly AutomationEngine $engine,
    ) {
    }

    public function index(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $runs = $this->history->paginate($sellerId, $request);

        return view('seller-views.automation.history', [
            'runs' => $runs,
            'ruleNames' => $this->history->ruleNames($runs->items()),
            'list' => $this->history,
            'currentView' => $this->history->view($request),
            'openRun' => $this->openRun($request, $sellerId),
            'state' => $this->listState($runs->total(), $this->history->view($request) !== 'all' || $request->filled('rule_id')),
        ]);
    }

    /**
     * Undo one automated change.
     *
     * The engine decides whether it may be undone and puts back only the columns the action that
     * made it declares it owns — a trail row is never a way to set an arbitrary column.
     */
    public function revert(Request $request, int $action): RedirectResponse
    {
        $record = SellerAutomationAction::where('seller_id', $this->sellerId($request))->find($action);

        abort_if($record === null, 404);

        $result = $this->engine->revert($record, $this->principal($request));

        return $result['ok']
            ? back()->with('success', translate('automation_action_reverted'))
            : back()->with('error', translate($result['reason']));
    }

    /**
     * The run whose drawer is open, with the records it touched.
     *
     * @return array{run: SellerAutomationRun, records: array<int, SellerAutomationAction>}|null
     */
    private function openRun(Request $request, int $sellerId): ?array
    {
        if (!$request->filled('run')) {
            return null;
        }

        $run = SellerAutomationRun::where('seller_id', $sellerId)->find((int) $request->query('run'));

        return $run === null ? null : ['run' => $run, 'records' => $this->history->records($run)];
    }
}
