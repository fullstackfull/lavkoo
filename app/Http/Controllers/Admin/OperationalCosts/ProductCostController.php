<?php

namespace App\Http\Controllers\Admin\OperationalCosts;

use App\Http\Controllers\Admin\OperationalCosts\Filters\QueryInput;
use App\Http\Controllers\Admin\OperationalCosts\Filters\SellerScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OperationalCosts\RecordPurchaseCostRequest;
use App\Models\InventoryCostConsumption;
use App\Models\InventoryCostLayer;
use App\Models\InventoryLocation;
use App\Services\Fulfillment\Support\SafeToast;
use App\Services\OperationalCosts\Data\LayerReceipt;
use App\Services\OperationalCosts\Exceptions\CostInputMissing;
use App\Services\OperationalCosts\InventoryCostLayerService;
use App\Services\OperationalCosts\PurchaseOrders\OrderLineCandidates;
use App\Services\OperationalCosts\Reports\ProductNames;
use App\Services\OperationalCosts\Support\FxConverter;
use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonImmutable;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * What the goods in stock cost us. A purchase is stock, not an expense: it comes in as a cost layer, and the cost reaches the books when a sale takes its units
 * from the layer (first in, first out). Moving stock between locations is a transfer of layers, not a sale.
 */
class ProductCostController extends Controller
{
    private const CONSUMPTION_LIMIT = 200;

    private const SEARCH_LIMIT = 20;

    public function __construct(
        private readonly InventoryCostLayerService $layers,
        private readonly ProductNames $productNames,
        private readonly FxConverter $fx,
        private readonly OrderLineCandidates $orderLines,
    ) {
    }

    public function index(Request $request): View
    {
        $filters = [
            'location' => QueryInput::integer($request, 'location'),
            'q' => QueryInput::text($request, 'q'),
            'open' => QueryInput::flag($request, 'open'),
            'seller' => SellerScope::resolve($request),
        ];

        $query = SellerScope::apply($this->filteredLayers($filters), $filters['seller']);
        $page = (clone $query)->orderByDesc('received_at')->orderByDesc('id')->paginate(25)->withQueryString();

        return view('admin-views.operational-costs.product-costs.index', [
            'layers' => $page,
            'filters' => $filters,
            'locations' => InventoryLocation::query()->orderBy('name')->get(['id', 'name', 'seller_id']),
            'locationNames' => InventoryLocation::query()->pluck('name', 'id'),
            'productNames' => $this->productNames->lookup($page->pluck('product_id')),
            'stock' => $this->openStock($query),
            'currency' => $this->fx->baseCurrency(),
            'currencyCodes' => array_values(array_unique([$this->fx->baseCurrency(), 'USD', 'EUR', 'AED', 'TRY'])),
            'sellers' => SellerScope::options('inventory_cost_layers'),
            'sourceTypes' => RecordPurchaseCostRequest::SOURCE_TYPES,
        ]);
    }

    public function show(int $layer): View
    {
        $record = InventoryCostLayer::query()->findOrFail($layer);
        $consumptions = InventoryCostConsumption::where('layer_id', $record->id)->orderByDesc('consumed_at')->orderByDesc('id');

        return view('admin-views.operational-costs.product-costs.show', [
            'layer' => $record,
            'consumptions' => (clone $consumptions)->limit(self::CONSUMPTION_LIMIT)->get(),
            'consumptionCount' => (clone $consumptions)->count(),
            'consumptionLimit' => self::CONSUMPTION_LIMIT,
            'location' => InventoryLocation::find($record->inventory_location_id),
            'productName' => $this->productNames->lookup([$record->product_id])[$record->product_id] ?? null,
        ]);
    }

    public function store(RecordPurchaseCostRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $location = InventoryLocation::query()->findOrFail($data['location_id']);

        $orderLines = $request->boolean('confirm_separate_purchase') ? [] : $this->orderLines->forTypedCost(productId: (int) $data['product_id'], sourceType: $data['source_type'], supplierName: $data['supplier_name'] ?? null, sellerId: (int) $location->seller_id);
        if ($orderLines !== []) {
            return back()->withInput()->withErrors(['confirm_separate_purchase' => CostLabels::filled(sentence: translate('a_purchase_order_received_these_goods_confirm_a_separate_purchase'), values: ['lines' => implode(', ', $orderLines)])]);
        }

        try {
            $layer = $this->layers->receive(new LayerReceipt(
                locationId: (int) $location->id,
                productId: (int) $data['product_id'],
                quantity: (int) $data['quantity'],
                unitCost: Money::of($data['unit_cost']),
                currency: $data['currency'],
                receivedAt: CarbonImmutable::createFromFormat('!Y-m-d', $data['received_at']),
                sourceType: $data['source_type'],
                identity: mb_strtolower(trim($data['reference'])),
                variantKey: (string) ($data['variant_key'] ?? ''),
                sourceRef: $data['reference'],
                landedUnitCost: isset($data['landed_unit_cost']) ? Money::of($data['landed_unit_cost']) : null,
                supplierName: $data['supplier_name'] ?? null,
                sellerId: (int) $location->seller_id,
            ));
        } catch (CostInputMissing $exception) {
            SafeToast::error('', $exception->getMessage());

            return back()->withInput();
        }

        $layer->wasRecentlyCreated
            ? ToastMagic::success(translate('purchase_cost_recorded'))
            : ToastMagic::info(translate('purchase_cost_already_recorded_nothing_changed'));

        return redirect()->route('admin.operational-costs.product-costs.show', $layer->id);
    }

    /** GET …/products/search?q= — physical products of the store, for the product field of the form. */
    public function productSearch(Request $request): JsonResponse
    {
        $term = (string) QueryInput::text($request, 'q');

        return response()->json(DB::table('products')
            ->where('added_by', 'admin')->where('product_type', 'physical')
            ->where(static fn ($query) => $query->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%")->orWhere('barcode', 'like', "%{$term}%")->orWhere('id', $term))
            ->orderBy('name')->limit(self::SEARCH_LIMIT)->get(['id', 'name', 'code', 'barcode']));
    }

    /**
     * @param array{location: ?int, q: ?string, open: bool, seller: ?int} $filters
     * @return Builder<InventoryCostLayer>
     */
    private function filteredLayers(array $filters): Builder
    {
        return InventoryCostLayer::query()
            ->when($filters['location'] !== null, static fn (Builder $query): Builder => $query->where('inventory_location_id', $filters['location']))
            ->when($filters['open'], static fn (Builder $query): Builder => $query->where('status', InventoryCostLayer::OPEN)->where('quantity_remaining', '>', 0))
            ->when($filters['q'] !== null, static fn (Builder $query): Builder => $query->where(static function (Builder $inner) use ($filters): void {
                $inner->whereIn('product_id', DB::table('products')->where('name', 'like', '%' . $filters['q'] . '%')->select('id'))
                    ->orWhere('sku', 'like', '%' . $filters['q'] . '%')
                    ->orWhere('source_ref', 'like', '%' . $filters['q'] . '%');
                if (ctype_digit($filters['q'])) {
                    $inner->orWhere('product_id', (int) $filters['q']);
                }
            }));
    }

    /**
     * What is still on the shelf in the layers listed, at cost: the units, and what they cost us (landed cost included).
     *
     * @param Builder<InventoryCostLayer> $layers
     * @return array{units: int, value: Money}
     */
    private function openStock(Builder $layers): array
    {
        $open = (clone $layers)->toBase()->where('status', InventoryCostLayer::OPEN)
            ->selectRaw('coalesce(sum(quantity_remaining), 0) as units, coalesce(sum(quantity_remaining * (unit_cost + landed_unit_cost)), 0) as value')->first();

        return ['units' => (int) $open->units, 'value' => Money::of($open->value)];
    }
}
