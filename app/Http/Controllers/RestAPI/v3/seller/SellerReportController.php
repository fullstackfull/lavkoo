<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Exports\OrderReportExport;
use App\Exports\ProductReportExport;
use App\Exports\ProductStockReportExport;
use App\Http\Controllers\Controller;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\Reports\ReportWindow;
use App\Services\Reports\SellerReportService;
use App\Utils\Helpers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A seller's own reports — the mobile twin of the vendor panel's report pages.
 *
 * Every figure comes from SellerReportService, which the panel reads too, so the app and the web
 * panel cannot drift apart on what a period covers or what was earned in it.
 *
 * The seller id comes from the auth token and nowhere else: there is no id parameter to change, and
 * the scoping is a WHERE inside the service rather than a filter over a wider result set.
 */
class SellerReportController extends Controller
{
    public function __construct(private readonly SellerReportService $reports)
    {
    }

    #[ApiDoc(
        summary: "The seller's order report for a period",
        description: 'Order counts by state, amounts due and settled, the payment-method breakdown '
            . '(including amounts added or returned by an order edit), a chart series bucketed to suit '
            . 'the period, and the orders themselves. Accepts '
            . 'date_type=today|this_week|this_month|this_year|custom_date with from/to for custom, '
            . 'plus search and limit/offset.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function orders(Request $request): JsonResponse
    {
        $sellerId = $request->seller->id;
        $window = $this->window($request);
        $search = $this->search($request);

        $report = $this->reports->orderReport($sellerId, $window, $search);
        $orders = $this->reports->orderQuery($sellerId, $window, $search)
            ->paginate($this->limit($request), ['*'], 'page', $this->page($request));

        return response()->json([
            'period' => $this->period($window),
            'counts' => $report['counts'],
            'amounts' => $report['amounts'],
            'payments' => $report['payments'],
            'chart' => [
                'labels' => $report['chart_labels'],
                'values' => array_values($report['chart']),
            ],
            'orders' => [
                'total_size' => $orders->total(),
                'limit' => $orders->perPage(),
                'offset' => $orders->currentPage(),
                'orders' => $orders->getCollection()->map(fn ($order) => [
                    'id' => $order->id,
                    'order_status' => $order->order_status,
                    'payment_status' => $order->payment_status,
                    'payment_method' => $order->payment_method,
                    'order_amount' => (float) $order->order_amount,
                    'discount_amount' => (float) $order->discount_amount,
                    'product_discount' => (float) ($order->details_sum_discount ?? 0),
                    'tax' => (float) ($order->details_sum_tax ?? 0),
                    'shipping_cost' => (float) $order->shipping_cost,
                    'commission' => (float) $order->admin_commission,
                    'created_at' => $order->created_at,
                ])->values(),
            ],
        ], 200);
    }

    #[ApiDoc(
        summary: "The seller's product report for a period",
        description: 'Products by approval state, quantity and value sold, discount given, the five '
            . 'best-selling products, a chart of listings over the period, and the products themselves '
            . 'each carrying what it has sold. Same period, search and paging parameters as the order report.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function products(Request $request): JsonResponse
    {
        $sellerId = $request->seller->id;
        $window = $this->window($request);
        $search = $this->search($request);

        $report = $this->reports->productReport($sellerId, $window, $search);
        $products = $this->reports->productQuery($sellerId, $window, $search)
            ->paginate($this->limit($request), ['*'], 'page', $this->page($request));

        return response()->json([
            'period' => $this->period($window),
            'counts' => $report['counts'],
            'totals' => $report['totals'],
            'chart' => [
                'labels' => $report['chart_labels'],
                'values' => array_values($report['chart']),
            ],
            'top_products' => $report['top_products']->map(fn ($row) => [
                'product_id' => (int) $row->product_id,
                'name' => $row->product?->name,
                'thumbnail' => $row->product?->thumbnail,
                'total_amount' => (float) $row->total_amount,
                'total_quantity' => (float) $row->total_quantity,
            ])->values(),
            'products' => [
                'total_size' => $products->total(),
                'limit' => $products->perPage(),
                'offset' => $products->currentPage(),
                'products' => $products->getCollection()->map(fn ($product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'thumbnail' => $product->thumbnail,
                    'unit_price' => (float) $product->unit_price,
                    'current_stock' => (int) $product->current_stock,
                    'request_status' => (int) $product->request_status,
                    'sold_quantity' => (float) ($product->orderDetails[0]->product_quantity ?? 0),
                    'sold_amount' => (float) ($product->orderDetails[0]->total_sold_amount ?? 0),
                ])->values(),
            ],
        ], 200);
    }

    #[ApiDoc(
        summary: 'Stock levels across the seller\'s physical products',
        description: 'Physical products ordered by how little is left, with the level at which this '
            . 'seller\'s stock counts as low (their own setting, or the platform default). Accepts '
            . 'search, sort=asc|desc, category_id and limit/offset. Not a period report — a stock '
            . 'level is a fact about now.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function stock(Request $request): JsonResponse
    {
        $sellerId = $request->seller->id;
        $stockLimit = $this->reports->stockLimitFor($request->seller);

        $products = $this->reports
            ->stockQuery($sellerId, $this->search($request), $this->sort($request), $this->categoryId($request))
            ->paginate($this->limit($request), ['*'], 'page', $this->page($request));

        return response()->json([
            'stock_limit' => $stockLimit,
            // Mapped rather than serialised whole: the model appends an icon URL resolver and a
            // translations relation, neither of which a filter chip has any use for.
            'categories' => $this->reports->stockFilterCategories()
                ->map(fn ($category) => ['id' => (int) $category->id, 'name' => $category->name])
                ->values(),
            'total_size' => $products->total(),
            'limit' => $products->perPage(),
            'offset' => $products->currentPage(),
            'products' => $products->getCollection()->map(fn ($product) => [
                'id' => $product->id,
                'name' => $product->name,
                'thumbnail' => $product->thumbnail,
                'unit_price' => (float) $product->unit_price,
                'current_stock' => (int) $product->current_stock,
                'is_low_stock' => (int) $product->current_stock <= $stockLimit,
            ])->values(),
        ], 200);
    }

    #[ApiDoc(
        summary: 'The order report as a spreadsheet',
        description: 'Returns the same orders the report lists as an .xlsx download, rendered by the '
            . 'same exporter the vendor panel uses. Takes the same period and search parameters. '
            . 'Responds with a file, not JSON.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function exportOrders(Request $request): BinaryFileResponse
    {
        $window = $this->window($request);
        $search = $this->search($request);

        return Excel::download(new OrderReportExport([
            'orders' => $this->reports->orderQuery($request->seller->id, $window, $search)->get(),
            'search' => $search,
            'vendor' => $request->seller,
            'from' => $window->from->toDateString(),
            'to' => $window->to->toDateString(),
            'dateType' => $window->type,
        ]), 'order-report.xlsx');
    }

    #[ApiDoc(
        summary: 'The stock report as a spreadsheet',
        description: 'Returns the stock listing as an .xlsx download, rendered by the same exporter '
            . 'the vendor panel uses. Takes the same search, sort and category parameters. Responds '
            . 'with a file, not JSON.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function exportStock(Request $request): BinaryFileResponse
    {
        $categoryId = $this->categoryId($request);

        return Excel::download(new ProductStockReportExport([
            'products' => $this->reports
                ->stockQuery($request->seller->id, $this->search($request), $this->sort($request), $categoryId)
                ->get(),
            'search' => $this->search($request),
            'seller' => $request->seller,
            'category' => $categoryId ?? 'all',
            'sort' => $this->sort($request),
            'stock_limit' => $this->reports->stockLimitFor($request->seller),
        ]), 'product-stock-report.xlsx');
    }

    #[ApiDoc(
        summary: 'The product report as a spreadsheet',
        description: 'Returns the products the report lists as an .xlsx download, rendered by the same '
            . 'exporter the vendor panel uses. Takes the same period and search parameters. Responds '
            . 'with a file, not JSON.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function exportProducts(Request $request): BinaryFileResponse
    {
        $window = $this->window($request);
        $search = $this->search($request);

        return Excel::download(new ProductReportExport([
            'products' => $this->reports->productQuery($request->seller->id, $window, $search)->get(),
            'search' => $search,
            'seller' => $request->seller,
            'from' => $window->from->toDateString(),
            'to' => $window->to->toDateString(),
            'date_type' => $window->type,
        ]), 'product-report.xlsx');
    }

    #[ApiDoc(
        summary: 'The order report as a PDF',
        description: 'The printed summary the vendor panel produces — the orders plus the totals at its '
            . 'foot, two of which cannot be summed from a column: waived shipping was never charged, and '
            . 'a delivery incentive is only owed on the seller\'s own deliveries. Responds with a file, '
            . 'not JSON.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function exportOrdersPdf(Request $request): Response
    {
        $window = $this->window($request);
        $orders = $this->reports->orderQuery($request->seller->id, $window, $this->search($request))->get();
        $seller = $request->seller;

        $data = array_merge($this->reports->orderTotals($orders), [
            'orders' => $orders,
            'search' => $this->search($request),
            'seller' => trim("{$seller->f_name} {$seller->l_name}"),
            'type' => 'seller',
            'from' => $window->from->toDateString(),
            'to' => $window->to->toDateString(),
            'date_type' => $window->type,
            'company_name' => getWebConfig(name: 'company_name'),
            'company_email' => getWebConfig(name: 'company_email'),
            'company_phone' => getWebConfig(name: 'company_phone'),
            'company_web_logo' => getWebConfig(name: 'company_web_logo'),
        ]);

        $mpdf = new \Mpdf\Mpdf([
            'default_font' => 'FreeSerif',
            'mode' => 'utf-8',
            'format' => [190, 250],
            'autoLangToFont' => true,
        ]);
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;
        $mpdf->WriteHTML(View::make('admin-views.transaction.total_orders_report_pdf', ['data' => $data])->render());

        // Returned as a body rather than written to the output stream: a mobile
        // client saves the file itself, and `Output(..., 'D')` would emit headers
        // this response has no say over.
        return response($mpdf->Output('', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="order-report.pdf"',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function period(ReportWindow $window): array
    {
        return [
            'date_type' => $window->type,
            'date_types' => ReportWindow::TYPES,
            'from' => $window->from->toDateString(),
            'to' => $window->to->toDateString(),
            'bucket' => $window->bucket,
        ];
    }

    /**
     * Every reader below coerces to a string first: these are query parameters, so `?search[]=x`
     * arrives as an array and would be an uncatchable TypeError against a string-typed argument.
     */
    private function window(Request $request): ReportWindow
    {
        return ReportWindow::make(
            type: $this->stringOrNull($request, 'date_type'),
            from: $this->stringOrNull($request, 'from'),
            to: $this->stringOrNull($request, 'to'),
        );
    }

    private function search(Request $request): ?string
    {
        $search = $this->stringOrNull($request, 'search');

        return $search === null || trim($search) === '' ? null : trim($search);
    }

    private function sort(Request $request): string
    {
        return strtoupper((string) $this->stringOrNull($request, 'sort')) === 'DESC' ? 'DESC' : 'ASC';
    }

    private function categoryId(Request $request): ?string
    {
        $categoryId = $this->stringOrNull($request, 'category_id');

        return $categoryId === null || $categoryId === '' || $categoryId === 'all' ? null : $categoryId;
    }

    private function limit(Request $request): int
    {
        $limit = (int) ($this->stringOrNull($request, 'limit') ?? Helpers::pagination_limit());

        return max(1, min($limit, 100));
    }

    private function page(Request $request): int
    {
        // The v3 seller API calls its page cursor `offset`, one-based, everywhere else too.
        return max(1, (int) ($this->stringOrNull($request, 'offset') ?? 1));
    }

    private function stringOrNull(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }
}
