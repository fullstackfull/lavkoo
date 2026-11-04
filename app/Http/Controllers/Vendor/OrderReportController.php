<?php

namespace App\Http\Controllers\Vendor;

use App\Contracts\Repositories\VendorRepositoryInterface;
use App\Enums\ExportFileNames\Admin\Report;
use App\Exports\OrderReportExport;
use App\Http\Controllers\Controller;
use App\Services\Reports\ReportWindow;
use App\Services\Reports\SellerReportService;
use App\Utils\Helpers;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The vendor panel's order report.
 *
 * The figures come from SellerReportService, which the seller app's API reads too — this page used
 * to compute them inline, in four near-identical period families that no other caller could reach.
 * A seller who sees one revenue figure here and another in the app trusts neither.
 */
class OrderReportController extends Controller
{
    public function __construct(
        private readonly VendorRepositoryInterface $vendorRepo,
        private readonly SellerReportService $reports,
    )
    {
    }

    public function order_report(Request $request): ViewContract
    {
        $sellerId = auth('seller')->id();
        $window = $this->window($request);
        $search = $this->search($request);

        $report = $this->reports->orderReport(sellerId: $sellerId, window: $window, search: $search);
        $orders = $this->reports->orderQuery(sellerId: $sellerId, window: $window, search: $search)
            ->paginate(Helpers::pagination_limit())
            ->appends($this->queryParams($request));

        return view('vendor-views.report.order-report', [
            'orders' => $orders,
            'order_count' => [
                'ongoing_order' => $report['counts']['ongoing'],
                'canceled_order' => $report['counts']['canceled'],
                'delivered_order' => $report['counts']['delivered'],
                'total_order' => $report['counts']['total'],
            ],
            'payment_data' => [
                'cash_payment' => $report['payments']['cash'],
                'wallet_payment' => $report['payments']['wallet'],
                'digital_payment' => $report['payments']['digital'],
                'offline_payment' => $report['payments']['offline'],
                'total_payment' => $report['payments']['total'],
                'return_amount' => $report['payments']['returned'],
            ],
            'chart_data' => ['order_amount' => $report['chart']],
            'chartDataOrderAmountLabel' => $report['chart_labels'],
            'due_amount' => $report['amounts']['due'],
            'settled_amount' => $report['amounts']['settled'],
            'search' => $search,
            'date_type' => $window->type,
            'from' => $request['from'],
            'to' => $request['to'],
        ]);
    }

    public function orderReportExportExcel(Request $request): BinaryFileResponse
    {
        $window = $this->window($request);

        return Excel::download(new OrderReportExport([
            'orders' => $this->reports
                ->orderQuery(sellerId: auth('seller')->id(), window: $window, search: $this->search($request))
                ->get(),
            'search' => $this->search($request),
            'vendor' => $this->vendorRepo->getFirstWhere(params: ['id' => auth('seller')->id()]),
            'from' => $request['from'],
            'to' => $request['to'],
            'dateType' => $window->type,
        ]), Report::ORDER_REPORT_LIST);
    }

    public function exportOrderReportInPDF(Request $request): void
    {
        $window = $this->window($request);
        $orders = $this->reports
            ->orderQuery(sellerId: auth('seller')->id(), window: $window, search: $this->search($request))
            ->get();

        $totalDeliveryCharge = 0;
        $totalDeliverymanIncentive = 0;
        foreach ($orders as $order) {
            // Shipping the seller waived under a free-shipping offer was never charged, so it is not
            // revenue and must not be summed as though it were.
            $totalDeliveryCharge += $order->shipping_cost
                - ($order->extra_discount_type === 'free_shipping_over_order_amount' ? $order->extra_discount : 0);
            $totalDeliverymanIncentive += ($order->delivery_type === 'self_delivery' && $order->delivery_man_id)
                ? $order->deliveryman_charge
                : 0;
        }

        $seller = auth('seller')->user();
        $data = [
            'orders' => $orders,
            'total_orders' => $orders->count(),
            'search' => $this->search($request),
            'seller' => $seller->f_name . ' ' . $seller->l_name,
            'type' => 'seller',
            'from' => $request['from'],
            'to' => $request['to'],
            'company_name' => getWebConfig(name: 'company_name'),
            'company_email' => getWebConfig(name: 'company_email'),
            'company_phone' => getWebConfig(name: 'company_phone'),
            'company_web_logo' => getWebConfig(name: 'company_web_logo'),
            'date_type' => $window->type,
            'total_order_amount' => $orders->sum('order_amount'),
            'total_product_discount' => $orders->sum('details_sum_discount'),
            'total_coupon_discount' => $orders->sum('discount_amount'),
            'total_referral_discount' => $orders->sum('refer_and_earn_discount'),
            'tota_order_due_amount' => $orders->sum('edit_due_amount'),
            'total_order_return_amount' => $orders->sum('edit_return_amount'),
            'total_tax' => $orders->sum('details_sum_tax'),
            'total_order_commission' => $orders->sum('admin_commission'),
            'total_delivery_charge' => $totalDeliveryCharge,
            'total_deliveryman_incentive' => $totalDeliverymanIncentive,
        ];

        $mpdfView = View::make('admin-views.transaction.total_orders_report_pdf', ['data' => $data]);
        Helpers::gen_mpdf($mpdfView, 'order_transaction_summary_report_', $window->type);
    }

    /** Query parameters arrive as whatever the caller sent; a `?search[]=` array is not a search. */
    private function search(Request $request): ?string
    {
        return is_string($request['search']) && trim($request['search']) !== '' ? trim($request['search']) : null;
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

    /**
     * @return array<string, mixed>
     */
    private function queryParams(Request $request): array
    {
        return [
            'search' => $this->search($request),
            'date_type' => $request['date_type'] ?? ReportWindow::THIS_YEAR,
            'from' => $request['from'],
            'to' => $request['to'],
        ];
    }
}
