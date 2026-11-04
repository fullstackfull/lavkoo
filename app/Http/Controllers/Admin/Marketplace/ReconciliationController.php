<?php

namespace App\Http\Controllers\Admin\Marketplace;

use App\Http\Controllers\BaseController;
use App\Services\Marketplace\ReconciliationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Financial reconciliation report (Phase 3, Stage E). Runs the read-only integrity checks over the
 * Stage B financial core and renders the result — green when everything reconciles, with a
 * drill-down of any discrepancy when it does not.
 */
class ReconciliationController extends BaseController
{
    public function __construct(private readonly ReconciliationService $reconciliation)
    {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $results = $this->reconciliation->run();

        return view('admin-views.marketplace.reconciliation', [
            'results' => $results,
            'clean' => $this->reconciliation->isClean($results),
        ]);
    }
}
