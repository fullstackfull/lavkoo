<?php

namespace App\Http\Controllers\Admin\SalesChannels;

use App\Http\Controllers\Admin\SalesChannels\Support\ProductDirectory;
use App\Http\Controllers\Controller;
use App\Models\InventoryLocation;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Inventory\LocationStockPage;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What each place says it holds. Torod Point, M5AZN and Amazon FBA are separate stocks and are shown one at a time, each from its own source and with how far its figure can be
 * trusted; nothing here adds them up, estimates a figure or changes one. The page only reads.
 */
class ChannelInventoryController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly ChannelLabels $labels,
        private readonly LocationStockPage $stock,
        private readonly ProductDirectory $products,
    ) {
    }

    public function index(Request $request): View
    {
        $locations = InventoryLocation::query()->orderBy('type')->orderBy('name')->get();
        $selected = $locations->firstWhere('id', $request->integer('location')) ?? $locations->first();
        $sku = trim(requestString('sku')) ?: null;
        $page = $selected === null ? null : $this->stock->for($selected, $sku, self::PER_PAGE);

        return view('admin-views.sales-channels.inventory', [
            'labels' => $this->labels,
            'locations' => $locations,
            'selected' => $selected,
            'accounts' => SalesChannelAccount::orderBy('name')->pluck('name', 'id'),
            'sku' => $sku,
            'rows' => $page['rows'] ?? null,
            'skus' => $page['skus'] ?? 0,
            'products' => $page === null ? collect() : $this->products->named($page['rows']->pluck('product_id')),
        ]);
    }
}
