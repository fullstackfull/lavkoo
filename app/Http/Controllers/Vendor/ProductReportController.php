<?php

namespace App\Http\Controllers\Vendor;

use App\Contracts\Repositories\VendorRepositoryInterface;
use App\Exports\ProductReportExport;
use App\Exports\ProductStockReportExport;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\Reports\ReportWindow;
use App\Services\Reports\SellerReportService;
use App\Utils\Helpers;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The vendor panel's product and stock reports.
 *
 * The figures come from SellerReportService, which the seller app's API reads too — this page used
 * to compute them inline, in its own copy of the same four period families the order report carried.
 */
class ProductReportController extends Controller
{
    public function __construct(
        private readonly VendorRepositoryInterface $vendorRepo,
        private readonly SellerReportService $reports,
    )
    {
    }

    public function all_product(Request $request): View
    {
        $sellerId = auth('seller')->id();
        $window = $this->window($request);
        $search = $this->search($request);

        $report = $this->reports->productReport(sellerId: $sellerId, window: $window, search: $search);
        $products = $this->reports->productQuery(sellerId: $sellerId, window: $window, search: $search)
            ->paginate(Helpers::pagination_limit())
            ->appends([
                'search' => $search,
                'date_type' => $window->type,
                'from' => $request['from'],
                'to' => $request['to'],
            ]);

        return view('vendor-views.report.all-product', [
            'products' => $products,
            'product_count' => [
                'reject_product_count' => $report['counts']['rejected'],
                'active_product_count' => $report['counts']['active'],
                'pending_product_count' => $report['counts']['pending'],
            ],
            'total_product_sale' => $report['totals']['sold_quantity'],
            'total_discount_given' => $report['totals']['discount_given'],
            'chart_data' => ['total_product' => $report['chart']],
            'chartDataTotalProductLabel' => $report['chart_labels'],
            'search' => $search,
            'date_type' => $window->type,
            'from' => $request['from'],
            'to' => $request['to'],
        ]);
    }

    public function allProductExportExcel(Request $request): BinaryFileResponse
    {
        $window = $this->window($request);

        return Excel::download(new ProductReportExport([
            'products' => $this->reports
                ->productQuery(sellerId: auth('seller')->id(), window: $window, search: $this->search($request))
                ->get(),
            'search' => $this->search($request),
            'seller' => $this->vendorRepo->getFirstWhere(params: ['id' => auth('seller')->id()]),
            'from' => $request['from'],
            'to' => $request['to'],
            'date_type' => $window->type,
        ]), 'Product-Report-List.xlsx');
    }

    public function stock_product_report(Request $request): View
    {
        $sort = $this->sort($request);
        $categoryId = $this->categoryId($request);

        $products = $this->reports
            ->stockQuery(
                sellerId: auth('seller')->id(),
                search: $this->search($request),
                sort: $sort,
                categoryId: $categoryId,
            )
            ->paginate(Helpers::pagination_limit())
            ->appends(['search' => $this->search($request), 'sort' => $sort, 'category_id' => $categoryId ?? 'all']);

        return view('vendor-views.report.product-stock', [
            'products' => $products,
            'categories' => $this->reports->stockFilterCategories(),
            'search' => $this->search($request),
            'stockLimit' => $this->reports->stockLimitFor(auth('seller')->user()),
            'sort' => $sort,
            'category_id' => $categoryId ?? 'all',
        ]);
    }

    public function productStockExport(Request $request): BinaryFileResponse
    {
        $categoryId = $this->categoryId($request);

        return Excel::download(new ProductStockReportExport([
            'products' => $this->reports
                ->stockQuery(
                    sellerId: auth('seller')->id(),
                    search: $this->search($request),
                    sort: $this->sort($request),
                    categoryId: $categoryId,
                )
                ->get(),
            'search' => $this->search($request),
            'seller' => $this->vendorRepo->getFirstWhere(params: ['id' => auth('seller')->id()]),
            'category' => $categoryId === null ? 'all' : Category::find($categoryId),
            'sort' => $this->sort($request),
            'stock_limit' => $this->reports->stockLimitFor(auth('seller')->user()),
        ]), 'Product-stock-report.xlsx');
    }

    /** Query parameters arrive as whatever the caller sent; a `?search[]=` array is not a search. */
    private function search(Request $request): ?string
    {
        return is_string($request['search']) && trim($request['search']) !== '' ? trim($request['search']) : null;
    }

    private function sort(Request $request): string
    {
        return is_string($request['sort']) && strtoupper($request['sort']) === 'DESC' ? 'DESC' : 'ASC';
    }

    private function categoryId(Request $request): ?string
    {
        $categoryId = $request['category_id'];

        return is_string($categoryId) && $categoryId !== '' && $categoryId !== 'all' ? $categoryId : null;
    }

    private function window(Request $request): ReportWindow
    {
        // Coerced first: ReportWindow::make is typed ?string, so a `?date_type[]=` array would be an
        // uncatchable TypeError rather than a value its allowlist can reject.
        $asString = static fn ($value): ?string => is_string($value) ? $value : null;

        return ReportWindow::make(
            type: $asString($request['date_type']),
            from: $asString($request['from']),
            to: $asString($request['to']),
        );
    }
}
