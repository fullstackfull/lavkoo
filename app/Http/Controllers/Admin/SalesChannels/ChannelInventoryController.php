<?php

namespace App\Http\Controllers\Admin\SalesChannels;

use App\Http\Controllers\Admin\SalesChannels\Support\ProductDirectory;
use App\Http\Controllers\Controller;
use App\Models\InventoryLocation;
use App\Models\SalesChannelAccount;
use App\Services\AuditLogger;
use App\Services\Fulfillment\Support\SafeToast;
use App\Services\SalesChannels\Inventory\LocationStockPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What each place says it holds. Torod Point, M5AZN and Amazon FBA are separate stocks and are shown one at a time, each from its own source and with how far its figure can be
 * trusted; nothing here adds them up, estimates a figure or changes one. The page only reads.
 */
class ChannelInventoryController extends Controller
{
    private const PER_PAGE = 50;

    /**
     * The channels a location may be asked to feed.
     *
     * This list IS the shared pool's membership, one location at a time. The owner approved noon joining it on 2026-11-10
     * (docs/sales-channels/decisions.md §8, decision one) on the condition that the admission stay per location and never be
     * granted automatically, so there is no bulk action here and no default includes `noon`: a location feeds noon because a
     * person opened this location and said so.
     */
    public const ADMISSIBLE_CHANNELS = ['storefront', 'trendyol', 'amazon_fbm', 'noon'];

    public function __construct(
        private readonly ChannelLabels $labels,
        private readonly LocationStockPage $stock,
        private readonly ProductDirectory $products,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Which channels this ONE location may feed. Nothing else is touched, and the location is named in the URL.
     *
     * The whole set is submitted and replaces what was held, so a channel left unticked is removed -- which is why the field
     * is required even when it is empty: a form that lost its inputs would otherwise read as "feed nothing" and silently
     * stop publishing everywhere. An unknown channel key is refused rather than stored, because the key is matched against
     * `StockListingGate::CHANNEL_KEYS` at publish time and a typo there would simply never match anything.
     */
    public function channels(Request $request, InventoryLocation $location): RedirectResponse
    {
        $validated = $request->validate([
            'channels' => ['present', 'array'],
            'channels.*' => ['string', 'in:' . implode(',', self::ADMISSIBLE_CHANNELS)],
        ]);

        $before = (array) ($location->publishable_channels ?? []);
        $after = array_values(array_intersect(self::ADMISSIBLE_CHANNELS, array_unique($validated['channels'])));

        $location->forceFill(['publishable_channels' => $after])->save();

        $this->audit->record(
            action: 'sales_channel.location_channels_changed',
            subject: $location,
            before: ['publishable_channels' => $before],
            after: ['publishable_channels' => $after],
            context: ['by' => auth('admin')->id(), 'added' => array_values(array_diff($after, $before)), 'removed' => array_values(array_diff($before, $after))],
        );

        SafeToast::success(translate('location_channels_saved'), implode(', ', $after) ?: translate('none_of_them'));

        return redirect()->route('admin.sales-channels.inventory.index', ['location' => $location->id]);
    }

    public function index(Request $request): View
    {
        $locations = InventoryLocation::query()->orderBy('type')->orderBy('name')->get();
        $selected = $locations->firstWhere('id', $request->integer('location')) ?? $locations->first();
        $sku = trim(requestString('sku')) ?: null;
        $page = $selected === null ? null : $this->stock->for($selected, $sku, self::PER_PAGE);

        return view('admin-views.sales-channels.inventory', [
            'labels' => $this->labels,
            'admissibleChannels' => self::ADMISSIBLE_CHANNELS,
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
