<?php

namespace App\Http\Controllers\Admin\OperationalCosts;

use App\Enums\OperationalCosts\CostState;
use App\Http\Controllers\Admin\OperationalCosts\Filters\ReportPeriod;
use App\Http\Controllers\Admin\OperationalCosts\Filters\SellerScope;
use App\Http\Controllers\Controller;
use App\Models\OperationalCostEntry;
use App\Models\OperationalCostInvoice;
use App\Models\OperationalCostReconciliation;
use App\Services\OperationalCosts\Profit\ProfitAggregator;
use App\Services\OperationalCosts\Reports\ProfitFilters;
use App\Services\OperationalCosts\Reports\ProfitReportBuilder;
use App\Services\OperationalCosts\Support\FxConverter;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Where the cost books stand for a period: the profit the services compute (estimated and actual apart, each with how many sales have a gap), what is missing
 * and why, and the work waiting for a person - invoices nobody approved, differences nobody resolved, charges in dispute.
 */
class OverviewController extends Controller
{
    private const REASON_LIMIT = 10;

    public function __construct(
        private readonly ProfitReportBuilder $reports,
        private readonly FxConverter $fx,
    ) {
    }

    public function index(Request $request): View
    {
        $period = ReportPeriod::fromRequest($request);
        $seller = SellerScope::resolve($request);
        $report = $this->reports->build(new ProfitFilters($period->from, $period->to, sellerId: $seller), ProfitAggregator::BY_CHANNEL);
        $byChannel = $this->reports->table($report, ProfitAggregator::BY_CHANNEL);

        $missingInPeriod = SellerScope::apply(
            OperationalCostEntry::query()->effective()->inState(CostState::Missing)->whereBetween('period_date', [$period->from->toDateString(), $period->to->toDateString()]),
            $seller,
        );

        return view('admin-views.operational-costs.index', [
            'period' => $period,
            'currency' => $this->fx->baseCurrency(),
            'seller' => $seller,
            'sellers' => SellerScope::options('operational_cost_entries'),
            'report' => $report,
            'totals' => $byChannel->totals,
            'byChannel' => $byChannel->lines,
            'byProvider' => $this->reports->table($report, ProfitAggregator::BY_PROVIDER)->lines,
            'missingCount' => (clone $missingInPeriod)->count(),
            'missingReasons' => (clone $missingInPeriod)->selectRaw('missing_reason, count(*) as total')->groupBy('missing_reason')->orderByDesc('total')->limit(self::REASON_LIMIT)->get(),
            'unapprovedInvoices' => SellerScope::apply(OperationalCostInvoice::query()->whereIn('status', [OperationalCostInvoice::STATUS_DRAFT, OperationalCostInvoice::STATUS_UNDER_REVIEW]), $seller)->count(),
            'openDifferences' => $this->openDifferences($seller),
            'disputedEntries' => SellerScope::apply(OperationalCostEntry::query()->openDisputes(), $seller)->count(),
        ]);
    }

    /** Invoice lines whose charge is not what was expected, or was not expected at all, and that nobody has decided about. */
    private function openDifferences(?int $seller): int
    {
        return OperationalCostReconciliation::query()
            ->whereIn('status', [OperationalCostReconciliation::VARIANCE, OperationalCostReconciliation::UNMATCHED_INVOICE])
            ->whereIn('invoice_id', SellerScope::apply(OperationalCostInvoice::query(), $seller)->select('id'))
            ->count();
    }
}
