<?php

namespace App\Http\Controllers\Admin\OperationalCosts;

use App\Enums\Fulfillment\ProviderCode;
use App\Enums\SalesChannels\SalesChannel;
use App\Http\Controllers\Admin\OperationalCosts\Filters\ProfitInput;
use App\Http\Controllers\Admin\OperationalCosts\Filters\SellerScope;
use App\Http\Controllers\Controller;
use App\Models\SalesChannelAccount;
use App\Services\OperationalCosts\Profit\ProfitabilityService;
use App\Services\OperationalCosts\Reports\ProfitLine;
use App\Services\OperationalCosts\Reports\ProfitReportBuilder;
use App\Services\OperationalCosts\Sales\SaleDocument;
use App\Services\OperationalCosts\Sales\SaleDocumentReader;
use App\Services\OperationalCosts\Support\FxConverter;
use App\Services\OperationalCosts\Support\Money;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Profit, as the profit services compute it, over a date range that is always bounded: the current month unless another is asked for, no more than a fixed
 * number of sales read, and a notice whenever that limit cut the report short. Estimated and actual profit stay two figures, and a line says how many sales
 * have a gap in their costs. Nothing is computed here: the page lays out what ProfitabilityService, ProfitAggregator and ProductProfitability return.
 */
class ProfitabilityController extends Controller
{
    public const DOCUMENT_TYPES = [SaleDocument::SUBJECT_ORDER, SaleDocument::SUBJECT_CHANNEL_ORDER];

    /** The most lines the page lists. The total above them is over every line. */
    public const LINE_LIMIT = 200;

    private const ACCOUNT_OPTION_LIMIT = 200;

    public function __construct(
        private readonly ProfitReportBuilder $reports,
        private readonly ProfitabilityService $profit,
        private readonly SaleDocumentReader $documents,
        private readonly ReportExports $exports,
        private readonly FxConverter $fx,
    ) {
    }

    public function index(Request $request): View
    {
        $input = ProfitInput::fromRequest($request);
        $report = $this->reports->build($input->filters(), $input->dimension);
        $table = $this->reports->table($report, $input->dimension);

        return view('admin-views.operational-costs.profit.index', [
            'input' => $input,
            'currency' => $this->fx->baseCurrency(),
            'report' => $report,
            'table' => $table,
            'lines' => array_slice($this->sorted($table->lines, $input), 0, self::LINE_LIMIT),
            'lineLimit' => self::LINE_LIMIT,
            'dimensions' => ProfitReportBuilder::DIMENSIONS,
            'channels' => SalesChannel::cases(),
            'providers' => ProviderCode::cases(),
            'accounts' => SalesChannelAccount::query()->orderBy('name')->limit(self::ACCOUNT_OPTION_LIMIT)->pluck('name', 'id'),
            'sellers' => SellerScope::options('external_order_mappings'),
        ]);
    }

    /** One sale, and every cost entry that makes up its profit. */
    public function order(string $type, int $id): View
    {
        $document = $type === SaleDocument::SUBJECT_ORDER ? $this->documents->forOrder($id) : $this->documents->forChannelOrder($id);
        abort_if($document === null, 404);

        return view('admin-views.operational-costs.profit.order', [
            'document' => $document,
            'currency' => $this->fx->baseCurrency(),
            'result' => $this->profit->forDocument($document),
            'entries' => $this->profit->entriesForDocuments([$document])[$document->cacheKey()] ?? collect(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        return $this->exports->profit($request);
    }

    /**
     * @param list<ProfitLine> $lines
     * @return list<ProfitLine>
     */
    private function sorted(array $lines, ProfitInput $input): array
    {
        $figure = match ($input->sort) {
            ProfitInput::SORT_ESTIMATED_PROFIT => static fn (ProfitLine $line) => $line->estimatedProfit() ?? Money::zero(),
            ProfitInput::SORT_ACTUAL_PROFIT => static fn (ProfitLine $line) => $line->actualProfit() ?? Money::zero(),
            default => static fn (ProfitLine $line) => $line->revenue(),
        };
        usort($lines, static fn (ProfitLine $a, ProfitLine $b): int => $input->ascending ? $figure($a)->compareTo($figure($b)) : $figure($b)->compareTo($figure($a)));

        return $lines;
    }
}
