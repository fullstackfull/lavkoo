<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Models\VendorLedgerEntry;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\Marketplace\SellerLedgerStatementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The seller's account, line by line.
 *
 * Until now a seller saw four totals and none of the entries behind them. A total nobody can take
 * apart is a number you either believe or do not; this is the version that can be checked.
 */
class SellerStatementController extends Controller
{
    public function __construct(private readonly SellerLedgerStatementService $statements)
    {
    }

    #[ApiDoc(
        summary: 'Every line in the seller\'s account',
        description: 'The ledger, newest first, with the running balance each line left behind — read '
            . 'from the entry rather than recomputed, so it is what the balance actually was at the '
            . 'time. Each line traces backwards to the order that earned it and forwards to the payout '
            . 'or settlement that took it out. Filter by entry_type, status, from and to. The bucket '
            . 'totals returned alongside are the whole account, never the filtered range: a seller '
            . 'narrowing to last week still needs to know what they can withdraw today.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function index(Request $request): JsonResponse
    {
        $sellerId = $request->seller->id;
        $filters = $this->filters($request);

        $entries = $this->statements->statement(
            sellerId: $sellerId,
            filters: $filters,
            perPage: $this->limit($request),
            page: $this->page($request),
        );

        return response()->json([
            'total_size' => $entries->total(),
            'limit' => $entries->perPage(),
            'offset' => $entries->currentPage(),
            'entry_types' => $this->statements->entryTypes(),
            'statuses' => $this->statements->statuses(),
            'summary' => $this->statements->summary($sellerId, $filters),
            'entries' => $this->statements->rows($entries->items()),
        ], 200);
    }

    #[ApiDoc(
        summary: 'The statement as a CSV',
        description: 'The same lines the statement returns, under the same filters, as a file — so a '
            . 'seller can reconcile a payout in a spreadsheet against their own books rather than '
            . 'reading a phone screen. Capped at the most recent 5,000 lines; narrow the range for '
            . 'more.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function export(Request $request): StreamedResponse
    {
        $sellerId = $request->seller->id;
        $filters = $this->filters($request);

        // Bounded, and said so in the documentation rather than silently truncated: a file that
        // quietly stops at 5,000 lines would be reconciled against and found to disagree.
        $rows = $this->statements->rows(
            $this->statements->statement($sellerId, $filters, perPage: 5000, page: 1)->items()
        );

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                translate('date'), translate('type'), translate('description'), translate('order'),
                translate('credit'), translate('debit'), translate('balance'), translate('status'),
                translate('payout'), translate('settlement'),
            ]);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['created_at'],
                    $row['entry_type'],
                    $row['description'],
                    $row['order_id'],
                    $row['credit'],
                    $row['debit'],
                    $row['balance_after'],
                    $row['status'],
                    $row['payout_reference'],
                    $row['settlement_reference'],
                ]);
            }

            fclose($handle);
        }, 'statement.csv', ['Content-Type' => 'text/csv']);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return [
            'entry_type' => $request->query('entry_type'),
            'status' => $request->query('status'),
            'from' => $this->date($request, 'from'),
            'to' => $this->date($request, 'to'),
        ];
    }

    private function date(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        // Only a plain calendar date is accepted. Anything else is dropped rather than passed to the
        // query, so a malformed range widens to everything instead of erroring or matching nothing.
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    private function limit(Request $request): int
    {
        return max(1, min((int) $request->query('limit', 25), 100));
    }

    private function page(Request $request): int
    {
        return max(1, (int) $request->query('offset', 1));
    }
}
