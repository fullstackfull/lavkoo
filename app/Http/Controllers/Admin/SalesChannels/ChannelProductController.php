<?php

namespace App\Http\Controllers\Admin\SalesChannels;

use App\Http\Controllers\Admin\SalesChannels\Support\ListingLinker;
use App\Http\Controllers\Admin\SalesChannels\Support\ProductDirectory;
use App\Http\Controllers\Admin\SalesChannels\Support\ReadsFilters;
use App\Http\Controllers\Controller;
use App\Models\InventoryLocation;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\AuditLogger;
use App\Services\Fulfillment\Support\SafeToast;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Inventory\Allocation\DirtyPools;
use App\Services\SalesChannels\Inventory\Allocation\PoolWarnings;
use App\Services\SalesChannels\Inventory\Allocation\StockShare;
use App\Services\SalesChannels\Inventory\Allocation\StockShareValidator;
use App\Services\SalesChannels\Inventory\PoolKey;
use App\Services\SalesChannels\Publishing\PublishPolicyResolver;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Listings read from the channels, and the decisions about them: which of our products each one stands for, where its stock and price come from, and whether either may be
 * published. Linking changes no product and saving a setting publishes nothing: publishing is a separate, approved, rate-limited action on the account page.
 */
class ChannelProductController extends Controller
{
    use ReadsFilters;

    public const STATUSES = [
        SalesChannelProductMapping::UNMAPPED,
        SalesChannelProductMapping::SUGGESTED,
        SalesChannelProductMapping::MAPPED,
        SalesChannelProductMapping::IGNORED,
        SalesChannelProductMapping::CONFLICT,
    ];

    public const PRICE_SOURCES = ['internal', 'channel', 'manual'];

    /** What is audited when a listing's settings change. */
    private const SETTINGS = ['stock_location_id', 'price_source', 'inventory_publish', 'price_publish', 'safety_buffer', 'max_publish_quantity', 'stock_share_mode', 'stock_share_value', 'stock_priority'];

    private const PER_PAGE = 25;

    public function __construct(
        private readonly ListingLinker $linker,
        private readonly ProductDirectory $products,
        private readonly AuditLogger $audit,
        private readonly ChannelLabels $labels,
        private readonly StockShareValidator $shares,
        private readonly PoolWarnings $warnings,
        private readonly DirtyPools $dirtyPools,
        private readonly PublishPolicyResolver $policies,
    ) {
    }

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $listings = SalesChannelProductMapping::with('account')
            ->when($filters['account'], static fn ($query, int $account) => $query->where('account_id', $account))
            ->when($filters['status'], static fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['unlinked'], static fn ($query) => $query->unlinked())
            ->when($filters['q'], static fn ($query, string $term) => $query->where(static fn ($inner) => $inner
                ->where('listing_key', 'like', "%{$term}%")->orWhere('external_sku', 'like', "%{$term}%")
                ->orWhere('external_barcode', 'like', "%{$term}%")->orWhere('external_title', 'like', "%{$term}%")))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin-views.sales-channels.products', [
            'labels' => $this->labels,
            'listings' => $listings,
            'filters' => $filters,
            'statuses' => self::STATUSES,
            'priceSources' => self::PRICE_SOURCES,
            'shareModes' => StockShare::MODES,
            'accounts' => SalesChannelAccount::orderBy('name')->get(['id', 'name', 'channel']),
            'locations' => InventoryLocation::active()->orderBy('name')->get(['id', 'name']),
            'products' => $this->products->named($listings->getCollection()->flatMap(static fn (SalesChannelProductMapping $listing): array => [$listing->product_id, $listing->suggested_product_id])),
        ]);
    }

    public function link(Request $request, SalesChannelProductMapping $mapping): RedirectResponse
    {
        $validated = $request->validateWithBag($this->bag($mapping), [
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('added_by', 'admin')->where('product_type', 'physical')],
            'variant_key' => ['nullable', 'string', 'max:191'],
        ]);
        if ($mapping->status === SalesChannelProductMapping::IGNORED) {
            SafeToast::warning(translate('listing_is_ignored_restore_it_first'));

            return back();
        }

        $this->announceLinked($this->linker->link($mapping, (int) $validated['product_id'], (string) ($validated['variant_key'] ?? ''), 'manual', auth('admin')->id()));

        return back();
    }

    public function acceptSuggestion(SalesChannelProductMapping $mapping): RedirectResponse
    {
        $suggested = $mapping->status === SalesChannelProductMapping::SUGGESTED ? $mapping->suggested_product_id : null;
        if ($suggested === null || $this->products->named([$suggested])->isEmpty()) {
            SafeToast::warning(translate('no_suggestion_to_accept'));

            return back();
        }

        $this->announceLinked($this->linker->link($mapping, (int) $suggested, (string) $mapping->variant_key, (string) ($mapping->match_method ?? 'manual'), auth('admin')->id()));

        return back();
    }

    public function ignore(SalesChannelProductMapping $mapping): RedirectResponse
    {
        $this->linker->ignore($mapping, auth('admin')->id());
        ToastMagic::success(translate('listing_ignored'));

        return back();
    }

    public function unignore(SalesChannelProductMapping $mapping): RedirectResponse
    {
        if ($mapping->status !== SalesChannelProductMapping::IGNORED) {
            SafeToast::warning(translate('listing_is_not_ignored'));

            return back();
        }
        $this->linker->unignore($mapping, auth('admin')->id());
        ToastMagic::success(translate('listing_restored'));

        return back();
    }

    /** Where the quantity and the price come from, and what may be published. Saving this publishes nothing. */
    public function settings(Request $request, SalesChannelProductMapping $mapping): RedirectResponse
    {
        $bag = $this->bag($mapping);
        $validated = $request->validateWithBag($bag, [
            'stock_location_id' => ['nullable', 'integer', Rule::exists('inventory_locations', 'id')->where('is_active', true)],
            'price_source' => ['required', Rule::in(self::PRICE_SOURCES)],
            'manual_price' => [Rule::requiredIf(fn (): bool => $request->input('price_source') === 'manual'), 'nullable', 'numeric', 'min:0.01', 'max:99999999999'],
            'inventory_publish' => ['sometimes', 'boolean'],
            'price_publish' => ['sometimes', 'boolean'],
            'safety_buffer' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'max_publish_quantity' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);
        $this->refuseSwitchesThatCannotWork($mapping, $validated, $bag);
        $share = $this->shares->share($request->all(), $bag);

        $before = $mapping->only(self::SETTINGS);
        $poolBefore = $this->poolOf($mapping);
        $snapshot = (array) $mapping->snapshot;
        if ($validated['price_source'] === 'manual') {
            $snapshot['manual_price'] = Money::of((string) $validated['manual_price'])->toStorage();
        }
        $mapping->forceFill([
            'stock_location_id' => $validated['stock_location_id'] ?? null,
            'price_source' => $validated['price_source'],
            'inventory_publish' => (bool) ($validated['inventory_publish'] ?? false),
            'price_publish' => (bool) ($validated['price_publish'] ?? false),
            'safety_buffer' => $validated['safety_buffer'] ?? null,
            'max_publish_quantity' => $validated['max_publish_quantity'] ?? null,
            'snapshot' => $snapshot,
        ] + $share);
        // What the last division said about the listing stays as long as the listing stays in the same pool: it is how the hub knows what the pool's channels show, and the next division
        // brings it up to date. A listing that moves to another pool is no longer part of the old one.
        $poolAfter = $this->poolOf($mapping);
        if ((string) $poolBefore !== (string) $poolAfter) {
            $mapping->allocation = null;
        }
        $mapping->save();
        $this->audit->record(
            action: 'sales_channel.listing_settings_changed',
            subject: $mapping,
            before: $before,
            after: $mapping->only(self::SETTINGS),
            context: ['by' => auth('admin')->id(), 'manual_price_set' => $validated['price_source'] === 'manual'],
        );
        // What the pool this listing took part in is shared out as has changed, and so has the pool it takes part in now: both are worked out again.
        $this->dirtyPools->mark(array_filter([$poolBefore, $poolAfter]));

        ToastMagic::success(translate('listing_settings_saved_nothing_published'));
        $this->announceStockWarnings($mapping);

        return back();
    }

    /** GET …/product-search?q= — our products, for the field that links a listing to one. */
    public function productSearch(Request $request): JsonResponse
    {
        $term = trim(requestString('q'));

        return response()->json(mb_strlen($term) < 2 ? [] : $this->products->search($term)->values());
    }

    /**
     * @return array{account: int|null, status: string|null, q: string|null, unlinked: bool}
     */
    private function filters(Request $request): array
    {
        return [
            'account' => $request->integer('account') ?: null,
            'status' => $this->oneOf('status', self::STATUSES),
            'q' => trim(requestString('q')) ?: null,
            'unlinked' => $request->boolean('unlinked'),
        ];
    }

    /** Amazon holds FBA stock, so it is never pushed; and only a price a person typed is ever published, so a price switch on another source would promise what never happens. */
    private function refuseSwitchesThatCannotWork(SalesChannelProductMapping $mapping, array $validated, string $bag): void
    {
        $problems = [];
        if (($validated['inventory_publish'] ?? false) && $mapping->fulfillment_mode === 'fba') {
            $problems['inventory_publish'] = translate('fba_stock_is_never_published');
        }
        if (($validated['price_publish'] ?? false) && $validated['price_source'] !== 'manual') {
            $problems['price_publish'] = translate('only_a_manual_price_is_published');
        }
        if ($problems !== []) {
            throw ValidationException::withMessages($problems)->errorBag($bag);
        }
    }

    private function poolOf(SalesChannelProductMapping $mapping): ?PoolKey
    {
        $locationId = $this->policies->resolve($mapping)->stockLocationId;

        return $mapping->product_id === null || $locationId === null ? null : PoolKey::ofListing($mapping, $locationId);
    }

    /** What a person should know after changing how a listing takes stock: it shares the stock, the shares ask for too much, the stock is not where the product's provider keeps it. */
    private function announceStockWarnings(SalesChannelProductMapping $mapping): void
    {
        foreach ($this->warnings->after($mapping) as $warning) {
            SafeToast::warning(match ($warning['code']) {
                PoolWarnings::SHARED => translate('stock_pool_shared_with_other_listings'),
                PoolWarnings::OVER_SUBSCRIBED => translate('stock_shares_ask_for_more_than_the_pool_holds'),
                PoolWarnings::OTHER_LOCATION => translate('stock_location_is_not_where_the_products_provider_keeps_it'),
            }, $warning['detail']);
        }
    }

    /** @param array{lines: int, orders: int, alsoLinkedTo: list<string>} $result */
    private function announceLinked(array $result): void
    {
        ToastMagic::success(translate('listing_linked'));
        if ($result['alsoLinkedTo'] !== []) {
            SafeToast::warning(translate('product_already_linked_to_other_listings'), implode(', ', $result['alsoLinkedTo']));
        }
        if ($result['lines'] > 0) {
            SafeToast::info(translate('waiting_order_lines_linked'), $result['lines'] . ' / ' . $result['orders']);
        }
    }

    /** The errors of one listing's form belong to that listing's row. */
    private function bag(SalesChannelProductMapping $mapping): string
    {
        return 'listing_' . $mapping->id;
    }
}
