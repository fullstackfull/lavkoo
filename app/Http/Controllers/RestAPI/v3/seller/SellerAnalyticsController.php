<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\RestockProduct;
use App\Models\RestockProductCustomer;
use App\Services\Analytics\Reporting\AnalyticsReporting;
use App\Services\Analytics\Reporting\Window;
use App\Services\DeveloperPortal\ApiDoc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A seller's own storefront analytics and the things waiting for their
 * attention — the mobile twin of the vendor Analytics page and of the
 * dashboard's real-time activities poll.
 *
 * The vendor id comes from the auth token and from nowhere else: there is no id
 * parameter to change, and the scoping is a WHERE inside AnalyticsReporting
 * rather than a filter over a wider result set.
 */
class SellerAnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsReporting $reporting)
    {
    }

    #[ApiDoc(
        summary: "The seller's own storefront analytics for a time window",
        description: 'Visitors, sessions, product views, cart adds, orders and revenue, plus the top 25 products '
            . 'by activity with their current names. Accepts range=today|7d|30d|90d|365d (default 30d). '
            . 'state is "ok", "not_installed" when analytics tables are absent, or a reason the window is empty.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function index(Request $request): JsonResponse
    {
        // Window::make is typed ?string, so ?range[]=x would be an uncatchable
        // TypeError rather than a value its allowlist can reject.
        $window = Window::make(is_string($request['range']) ? $request['range'] : null);
        $report = $this->reporting->forVendor((int) $request->seller->id, $window);

        return response()->json([
            'range' => $window->key,
            'range_label' => $window->label(),
            'ranges' => array_keys(Window::RANGES),
            'from' => $window->from->toDateString(),
            'to' => $window->to->toDateString(),
            'state' => $report['state'] ?? 'not_installed',
            'summary' => $report['summary'] ?? null,
            // Names are resolved here rather than in the service so the report stays id-based and
            // cacheable, and so a product renamed today shows its current name against old views.
            'products' => $this->withProductNames($report['products'] ?? []),
        ], 200);
    }

    #[ApiDoc(
        summary: 'Counts of the things waiting for the seller right now',
        description: 'Unchecked new orders and outstanding restock requests, matching the vendor dashboard poll. '
            . 'Data only — the web version also returns panel routes and asset URLs, which a mobile client cannot use.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function activities(Request $request): JsonResponse
    {
        $sellerId = $request->seller->id;

        // Counted in SQL, not by hydrating every row: `checked` is only ever
        // cleared from the web panel, so a seller who works from the app alone
        // accumulates an unbounded backlog — and this endpoint is polled on
        // every home-screen load.
        $newOrderCount = Order::where([
            'seller_is' => 'seller',
            'seller_id' => $sellerId,
            'checked' => 0,
        ])->count();

        // restock_products carries neither added_by nor seller_id — ownership lives
        // on the related product, which is how RestockProductRepository scopes it too.
        $restockProducts = RestockProduct::whereHas('product', function ($query) use ($sellerId) {
            $query->where(['added_by' => 'seller', 'user_id' => $sellerId]);
        });

        return response()->json([
            'new_order_count' => $newOrderCount,
            'restock_product_count' => (clone $restockProducts)->distinct('product_id')->count('product_id'),
            'restock_request_count' => (int) RestockProductCustomer::whereIn(
                'restock_product_id',
                (clone $restockProducts)->select('id'),
            )->count(),
        ], 200);
    }

    /**
     * @param  array<int, object>  $products
     * @return array<int, array<string, mixed>>
     */
    private function withProductNames(array $products): array
    {
        $ids = array_filter(array_map(static fn (object $row) => (int) $row->entity_id, $products));
        $names = $ids === []
            ? []
            : DB::table('products')->whereIn('id', $ids)->pluck('name', 'id')->all();

        return array_map(static fn (object $row) => [
            'product_id' => (int) $row->entity_id,
            'name' => $names[(int) $row->entity_id] ?? null,
            'events' => (int) $row->events,
            'visitors' => (int) $row->visitors,
            'views' => (int) $row->views,
            'cart_adds' => (int) $row->cart_adds,
        ], $products);
    }
}
