<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\BaseController;
use App\Services\Analytics\Reporting\AnalyticsReporting;
use App\Services\Analytics\Reporting\Window;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A vendor's own analytics, and nothing else's.
 *
 * The one rule that matters here: the vendor id comes from the AUTHENTICATION GUARD and from
 * nowhere else. Not from the route, not from a query parameter, not from a hidden form field. A
 * report that takes an id from the request is one crafted URL away from showing a seller their
 * competitor's numbers, and that is not a bug anybody notices from the outside.
 *
 * The scoping itself lives in AnalyticsReporting::forVendor, which applies it as a WHERE on the
 * query rather than as a filter over a wider result set — the difference between data a vendor
 * cannot reach and data they merely are not shown.
 */
class AnalyticsController extends BaseController
{
    public function __construct(private readonly AnalyticsReporting $reporting)
    {
    }

    /**
     * @param  array<int, object>  $products
     * @return array<int|string, string>
     */
    private function productNames(array $products): array
    {
        $ids = array_filter(array_map(static fn (object $row) => (int) $row->entity_id, $products));

        if ($ids === []) {
            return [];
        }

        try {
            return \Illuminate\Support\Facades\DB::table('products')
                ->whereIn('id', $ids)
                ->pluck('name', 'id')
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function index(?Request $request = null, ?string $type = null): View|JsonResponse|RedirectResponse
    {
        $request ??= request();

        $vendorId = auth('seller')->id();

        if ($vendorId === null) {
            return redirect()->route('vendor.auth.login');
        }

        $window = Window::make($request->query('range'));

        return view('vendor-views.analytics.index', [
            'window' => $window,
            'ranges' => Window::RANGES,
            'data' => $report = $this->reporting->forVendor((int) $vendorId, $window),
            'health' => $this->reporting->collectionHealth(),
            // Resolved here rather than in the service so the report stays id-based and cacheable,
            // and so a product renamed today shows its current name against last month's views.
            'names' => $this->productNames($report['products'] ?? []),
        ]);
    }
}
