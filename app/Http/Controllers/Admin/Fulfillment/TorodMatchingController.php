<?php

namespace App\Http\Controllers\Admin\Fulfillment;

use App\Services\Fulfillment\Support\SafeToast;
use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\HoldReason;
use App\Enums\Fulfillment\ProviderCode;
use App\Http\Controllers\Controller;
use App\Models\AddressCity;
use App\Models\ProviderCatalogItem;
use App\Models\ProviderCity;
use App\Models\ProviderFulfillment;
use App\Models\ProviderProductMapping;
use App\Services\Address\AddressCatalog;
use App\Services\Fulfillment\Exceptions\ProviderUnavailableException;
use App\Services\Fulfillment\ProductProviderAssignmentService;
use App\Services\Fulfillment\Providers\TorodPoint\TorodCityResolver;
use App\Services\Fulfillment\Providers\TorodPoint\TorodPointAdapter;
use App\Services\Fulfillment\ProviderCityMappingService;
use App\Services\Fulfillment\ProviderCityReview;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\ProviderMappingService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Torod Point: matching our products to the warehouse's, and our cities to its cities, explicitly.
 *
 * Nothing here links a product by itself. A warehouse item is shown with the store products that LOOK like
 * it and the reasons why; a person picks one and approves it. Approving a mapping does not assign the
 * product to Torod Point — that is a separate, explicit box — and a matching SKU or barcode never changes
 * who fulfils a product.
 *
 * Cities are matched the same way: Torod's candidates for each of our cities are shown side by side and a person approves
 * one. The only city approved without a person is one whose name is the only exact match in a list read completely.
 */
class TorodMatchingController extends Controller
{
    public function __construct(
        private readonly ProviderMappingService $mappings,
        private readonly ProductProviderAssignmentService $assignments,
        private readonly TorodCityResolver $cities,
        private readonly TorodPointAdapter $adapter,
        private readonly ProviderConnectionManager $connections,
        private readonly ProviderCityMappingService $cityMappings,
        private readonly ProviderCityReview $cityReview,
    ) {
    }

    public function index(Request $request): View
    {
        $provider = ProviderCode::TorodPoint;
        $status = (string) $request->query('status', 'unmatched');
        $mappedIds = ProviderProductMapping::where('provider', $provider->value)->where('status', 'approved')->pluck('external_id');

        $items = ProviderCatalogItem::where('provider', $provider->value)
            ->when($status === 'unmatched', static fn ($query) => $query->whereNotIn('external_id', $mappedIds))
            ->when($status === 'matched', static fn ($query) => $query->whereIn('external_id', $mappedIds))
            ->when($request->filled('q'), static fn ($query) => $query->where(static fn ($inner) => $inner
                ->where('name', 'like', '%' . $request->query('q') . '%')->orWhere('sku', 'like', '%' . $request->query('q') . '%')->orWhere('barcode', 'like', '%' . $request->query('q') . '%')))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $rows = $items->getCollection()->map(fn (ProviderCatalogItem $item): array => [
            'item' => $item,
            'matched' => $mappedIds->contains($item->external_id),
            'suggestions' => $mappedIds->contains($item->external_id) ? [] : $this->mappings->suggestionsFor($item, echoedExternalId: isset($item->payload['externalId']) ? (string) $item->payload['externalId'] : null),
        ]);

        $mapped = ProviderProductMapping::where('provider', $provider->value)->latest('id')->limit(50)->get();
        $products = DB::table('products')->whereIn('id', $mapped->pluck('product_id'))->get(['id', 'name', 'code', 'fulfillment_provider'])->keyBy('id');
        $cityReview = $this->cityMappings->directoryReady() ? $this->cityReview->overview($provider->value) : null;

        return view('admin-views.fulfillment.torod-matching', [
            'rows' => $rows,
            'paginator' => $items,
            'status' => $status,
            'mapped' => $mapped,
            'products' => $products,
            'cityReview' => $cityReview,
            'unmappedCities' => $cityReview === null ? $this->unmappedCities() : array_column($cityReview['typed'], 'text'),
            'config' => $this->connections->config($provider),
            'counts' => ['staged' => ProviderCatalogItem::where('provider', $provider->value)->count(), 'mapped' => $mappedIds->count()],
        ]);
    }

    public function approve(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'catalog_item_id' => ['required', 'integer'],
            'product_id' => ['required', 'integer'],
            'variant_key' => ['nullable', 'string', 'max:191'],
            'assign_to_provider' => ['nullable', 'boolean'],
        ]);
        $item = ProviderCatalogItem::where('provider', ProviderCode::TorodPoint->value)->findOrFail($data['catalog_item_id']);

        try {
            $this->mappings->approve(
                provider: ProviderCode::TorodPoint,
                productId: (int) $data['product_id'],
                variantKey: (string) ($data['variant_key'] ?? ''),
                externalId: $item->external_id,
                externalSku: $item->sku,
                externalBarcode: $item->barcode,
                externalName: $item->name,
                approvedBy: auth('admin')->id(),
            );
        } catch (InvalidArgumentException $exception) {
            SafeToast::error('', $exception->getMessage());

            return back();
        }

        $item->forceFill(['status' => ProviderCatalogItem::STATUS_IMPORTED, 'product_id' => (int) $data['product_id']])->save();
        ToastMagic::success(translate('mapping_approved'));

        if ($request->boolean('assign_to_provider')) {
            $result = $this->assignments->assign((int) $data['product_id'], ProviderCode::TorodPoint->value, 'admin', auth('admin')->id(), 'matched_to_warehouse_product');
            $result->ok ? ToastMagic::success(translate('product_assigned_to_provider')) : SafeToast::warning('', $result->message());
        }

        return back();
    }

    public function retire(int $mapping): RedirectResponse
    {
        try {
            $this->mappings->retire($mapping);
            ToastMagic::success(translate('mapping_retired'));
        } catch (InvalidArgumentException $exception) {
            SafeToast::error('', $exception->getMessage());
        }

        return back();
    }

    public function stock(Request $request): RedirectResponse
    {
        $data = $request->validate(['mapping_id' => ['required', 'integer'], 'quantity' => ['required', 'integer', 'min:0', 'max:100000000']]);
        if (!$this->mappings->setManualStock((int) $data['mapping_id'], (int) $data['quantity'])) {
            SafeToast::warning('', translate('stock_is_set_by_the_warehouse_sync'));

            return back();
        }

        ToastMagic::success(translate('manual_stock_saved'));

        return back();
    }

    public function alias(Request $request): RedirectResponse
    {
        $data = $request->validate(['alias' => ['required', 'string', 'max:191'], 'external_city_id' => ['required', 'string', 'max:32']]);
        $this->cities->addAlias($data['alias'], $data['external_city_id'], auth('admin')->id());
        ToastMagic::success(translate('city_alias_saved'));

        return back();
    }

    /** GET …/cities?q= — search the cached city list (for the alias form and the manual match). */
    public function citySearch(Request $request): JsonResponse
    {
        return response()->json($this->cities->search((string) $request->query('q'))->map(static fn (ProviderCity $city): array => [
            'id' => $city->external_id,
            'name' => trim(($city->name_ar ?? '') . ' / ' . ($city->name_en ?? ''), ' /'),
            'region_id' => $city->region_external_id,
            'region' => trim(($city->region_name_ar ?? '') . ' / ' . ($city->region_name_en ?? ''), ' /'),
            'prefix' => $city->prefix,
            'selected' => $city->is_selected,
        ])->values());
    }

    /** GET …/cities/internal-search?q= — our own cities, with their region, for the manual match. */
    public function internalCitySearch(Request $request, AddressCatalog $catalog): JsonResponse
    {
        $this->requireCityMatching();

        return response()->json($catalog->searchCities(query: (string) $request->query('q'), limit: 20)->load('region')->map(static fn (AddressCity $city): array => [
            'id' => $city->id, 'name_ar' => $city->name_ar, 'name_en' => $city->name_en,
            'region' => trim(($city->region?->name_ar ?? '') . ' / ' . ($city->region?->name_en ?? ''), ' /'),
        ])->values());
    }

    public function approveCity(Request $request): RedirectResponse
    {
        $this->requireCityMatching();
        $data = $request->validate([
            'address_city_id' => ['required', 'integer', 'min:1'],
            'external_city_id' => ['required', 'string', 'max:32'],
            'replacing' => ['nullable', 'integer', 'min:1'],
        ]);
        $admin = auth('admin')->user();

        try {
            $this->cityMappings->approve(
                provider: ProviderCode::TorodPoint->value,
                cityId: (int) $data['address_city_id'],
                externalCityId: $data['external_city_id'],
                adminId: $admin?->getKey(),
                adminName: $admin?->name,
                replacing: isset($data['replacing']) ? (int) $data['replacing'] : null,
            );
        } catch (InvalidArgumentException $exception) {
            SafeToast::error('', $exception->getMessage());

            return back();
        }

        ToastMagic::success(translate('city_match_approved'));

        return back();
    }

    public function rejectCity(Request $request): RedirectResponse
    {
        $this->requireCityMatching();
        $data = $request->validate(['address_city_id' => ['required', 'integer', 'min:1'], 'external_city_id' => ['required', 'string', 'max:32']]);
        $admin = auth('admin')->user();

        try {
            $this->cityMappings->reject(
                provider: ProviderCode::TorodPoint->value,
                cityId: (int) $data['address_city_id'],
                externalCityId: $data['external_city_id'],
                adminId: $admin?->getKey(),
                adminName: $admin?->name,
            );
        } catch (InvalidArgumentException $exception) {
            SafeToast::error('', $exception->getMessage());

            return back();
        }

        ToastMagic::success(translate('city_candidate_rejected'));

        return back();
    }

    public function retireCity(int $mapping): RedirectResponse
    {
        $this->requireCityMatching();
        $admin = auth('admin')->user();

        try {
            $this->cityMappings->retire(mappingId: $mapping, adminId: $admin?->getKey(), adminName: $admin?->name);
        } catch (InvalidArgumentException $exception) {
            SafeToast::error('', $exception->getMessage());

            return back();
        }

        ToastMagic::success(translate('mapping_retired'));

        return back();
    }

    /** POST …/cities/discover — ask Torod Point for the cities named like one of ours (a read), and propose the exact ones. */
    public function discoverCity(Request $request): RedirectResponse
    {
        $this->requireCityMatching();
        $city = AddressCity::query()->with('region')->findOrFail($request->validate(['address_city_id' => ['required', 'integer', 'min:1']])['address_city_id']);

        try {
            $result = $this->adapter->discoverCities($city);
        } catch (ProviderUnavailableException $exception) {
            SafeToast::warning('', $exception->getMessage());

            return back();
        }

        $result['ok']
            ? SafeToast::success(translate('city_discovery_done'), sprintf('%d / %d / %d', $result['found'], count($result['matches']), $result['proposed']))
            : SafeToast::error(translate('city_discovery_failed'), $result['error']);

        return back();
    }

    /** GET …/products?q= — store products for the matching selector. */
    public function productSearch(Request $request): JsonResponse
    {
        $term = (string) $request->query('q');

        return response()->json(DB::table('products')
            ->where('added_by', 'admin')->where('product_type', 'physical')
            ->where(static fn ($query) => $query->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%")->orWhere('barcode', 'like', "%{$term}%"))
            ->limit(20)->get(['id', 'name', 'code', 'barcode', 'fulfillment_provider']));
    }

    public function push(Request $request): RedirectResponse
    {
        $ids = array_map('intval', (array) $request->validate(['product_ids' => ['required', 'array', 'min:1', 'max:100'], 'product_ids.*' => ['integer']])['product_ids']);

        try {
            $result = $this->adapter->pushProducts($ids, auth('admin')->id());
        } catch (ProviderUnavailableException $exception) {
            SafeToast::warning('', $exception->getMessage());

            return back();
        }

        if (!$result['ok']) {
            SafeToast::error('', (string) ($result['error'] ?? translate('push_failed')));
        } elseif ($result['dry_run'] ?? false) {
            SafeToast::info(translate('push_dry_run_nothing_sent'), (string) count($result['preview'] ?? []));
        } else {
            SafeToast::success(translate('products_pushed'), (string) ($result['pushed'] ?? 0));
        }

        return back();
    }

    /** Until the address and mapping migrations have run, the city actions do not exist. */
    private function requireCityMatching(): void
    {
        abort_unless($this->cityMappings->directoryReady(), 404);
    }

    /** @return list<string> city strings our checkout produced that no Torod Point city matched (held fulfillments) */
    private function unmappedCities(): array
    {
        return ProviderFulfillment::where('provider', ProviderCode::TorodPoint->value)
            ->where('status', FulfillmentStatus::Held->value)->where('hold_reason', HoldReason::CITY_UNMAPPED)
            ->whereNotNull('last_error_message')->distinct()->pluck('last_error_message')->all();
    }
}
