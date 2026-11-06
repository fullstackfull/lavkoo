<?php

namespace App\Http\Controllers\Admin\OperationalCosts;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Enums\SalesChannels\SalesChannel;
use App\Enums\Fulfillment\ProviderCode;
use App\Http\Controllers\Admin\OperationalCosts\Filters\InvoiceFilters;
use App\Http\Controllers\Admin\OperationalCosts\Filters\ReportPeriod;
use App\Http\Controllers\Admin\OperationalCosts\Filters\SellerScope;
use App\Http\Controllers\Controller;
use App\Models\OperationalCostEntry;
use App\Models\OperationalCostInvoice;
use App\Models\SalesChannelAccount;
use App\Services\OperationalCosts\Reports\ProfitReportBuilder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The exports: what each one holds, the filters it takes (the same as the page it belongs to), and the download. */
class ReportController extends Controller
{
    public const REPORTS = ['entries', 'missing', 'profit', 'invoices'];

    private const OPTION_LIMIT = 200;

    public function __construct(private readonly ReportExports $exports)
    {
    }

    public function index(): View
    {
        return view('admin-views.operational-costs.reports.index', [
            'reports' => self::REPORTS,
            'period' => ReportPeriod::currentMonth(),
            'components' => CostComponent::cases(),
            'states' => CostState::cases(),
            'channels' => SalesChannel::cases(),
            'providers' => OperationalCostEntry::query()->whereNotNull('provider')->distinct()->orderBy('provider')->limit(self::OPTION_LIMIT)->pluck('provider'),
            'fulfillmentProviders' => ProviderCode::cases(),
            'accounts' => SalesChannelAccount::query()->orderBy('name')->limit(self::OPTION_LIMIT)->pluck('name', 'id'),
            'dimensions' => ProfitReportBuilder::DIMENSIONS,
            'invoiceStatuses' => InvoiceFilters::STATUSES,
            'invoiceProviders' => OperationalCostInvoice::query()->distinct()->orderBy('provider_code')->limit(self::OPTION_LIMIT)->pluck('provider_code'),
            'sellers' => SellerScope::options('operational_cost_entries'),
        ]);
    }

    public function export(Request $request, string $report): StreamedResponse
    {
        return match ($report) {
            'entries' => $this->exports->entries($request),
            'missing' => $this->exports->missing($request),
            'profit' => $this->exports->profit($request),
            'invoices' => $this->exports->invoices($request),
        };
    }
}
