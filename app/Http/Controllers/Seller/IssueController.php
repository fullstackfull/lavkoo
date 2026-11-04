<?php

namespace App\Http\Controllers\Seller;

use App\Models\SellerInsight;
use App\Services\SellerCenter\Lists\IssueList;
use App\Services\SellerCenter\TableFilters;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The full issue backlog with triage; the Control Tower is its today-view (handoff 07.3).
 *
 * A direct visit to an issue renders the full-page layout with the same blocks the drawer shows,
 * so a deep link from a notification and a click from the list arrive at the same content.
 */
class IssueController extends SellerCenterController
{
    public function __construct(private readonly IssueList $issues)
    {
    }

    public function index(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $filters = new TableFilters($request, $this->issues->filterFields(), route('seller.issues.index'));
        $issues = $this->issues->paginate($sellerId, $request);
        $view = $this->issues->view($request);

        return view('seller-views.issues.index', [
            'issues' => $issues,
            'filters' => $filters,
            'views' => $this->issues->views($sellerId, $request, route('seller.issues.index')),
            'currentView' => $view,
            'emptyCopy' => $this->issues->emptyCopy($view),
            'state' => $issues === null
                ? 'empty'
                : $this->listState($issues->total(), $filters->isFiltered()),
        ]);
    }

    public function show(Request $request, int $issueId): View
    {
        $issue = SellerInsight::forSeller($this->sellerId($request))->find($issueId);

        abort_if($issue === null, 404);

        return view('seller-views.issues.show', ['issue' => $issue]);
    }
}
