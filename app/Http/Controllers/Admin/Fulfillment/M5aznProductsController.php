<?php

namespace App\Http\Controllers\Admin\Fulfillment;

use App\Enums\Fulfillment\ProviderCode;
use App\Http\Controllers\Controller;
use App\Models\ProviderCatalogItem;
use App\Models\ProviderPriceChange;
use App\Models\ProviderProductMapping;
use App\Services\Fulfillment\Data\ImportResult;
use App\Services\Fulfillment\FieldMapStore;
use App\Services\Fulfillment\Providers\M5azn\M5aznFieldMaps;
use App\Services\Fulfillment\Providers\M5azn\M5aznProductImporter;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\Support\ProviderCurrency;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** M5AZN: the products it supplies, their cost against our selling price, and price history. */
class M5aznProductsController extends Controller
{
    public function __construct(
        private readonly M5aznProductImporter $importer,
        private readonly ProviderConnectionManager $connections,
        private readonly FieldMapStore $fieldMaps,
        private readonly ProviderCurrency $currency,
    ) {
    }

    public function index(Request $request): View
    {
        $status = (string) $request->query('status', '');
        $items = ProviderCatalogItem::where('provider', ProviderCode::M5azn->value)
            ->when($status !== '', static fn ($query) => $query->where('status', $status))
            ->when($request->filled('q'), static fn ($query) => $query->where(static fn ($inner) => $inner
                ->where('name', 'like', '%' . $request->query('q') . '%')->orWhere('sku', 'like', '%' . $request->query('q') . '%')->orWhere('external_id', $request->query('q'))))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $products = DB::table('products')->whereIn('id', $items->pluck('product_id')->filter())->get()->keyBy('id');
        $mappings = ProviderProductMapping::where('provider', ProviderCode::M5azn->value)->whereIn('product_id', $items->pluck('product_id')->filter())->get()->groupBy('product_id');

        $rows = $items->getCollection()->map(function (ProviderCatalogItem $item) use ($products, $mappings): array {
            $product = $products[$item->product_id] ?? null;
            $mapping = $mappings[$item->product_id][0] ?? null;
            $price = $product === null ? null : $this->currency->fromStored($product->unit_price);
            $cost = $mapping?->supplier_cost === null ? null : (float) $mapping->supplier_cost;

            return [
                'item' => $item,
                'product' => $product,
                'mapping' => $mapping,
                'sellingPrice' => $price,
                'cost' => $cost,
                'margin' => $price !== null && $cost !== null ? round($price - $cost, 2) : null,
                'marginPercent' => $price !== null && $cost !== null && $price > 0 ? round(($price - $cost) / $price * 100, 1) : null,
            ];
        });

        return view('admin-views.fulfillment.m5azn-products', [
            'rows' => $rows,
            'paginator' => $items,
            'status' => $status,
            'config' => $this->connections->config(ProviderCode::M5azn),
            'catalogMapVerified' => $this->fieldMaps->get(ProviderCode::M5azn, M5aznFieldMaps::CATALOG)->verified,
            'priceChanges' => ProviderPriceChange::where('provider', ProviderCode::M5azn->value)->latest('id')->limit(25)->get(),
            'currency' => $this->currency->code(),
            'counts' => ProviderCatalogItem::where('provider', ProviderCode::M5azn->value)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    public function import(Request $request, int $item): RedirectResponse
    {
        $result = $this->importer->import($item, auth('admin')->id(), force: $request->boolean('force'));
        $this->report($result);

        return back();
    }

    public function importAll(): RedirectResponse
    {
        $stats = $this->importer->importPending(actorId: auth('admin')->id());
        ToastMagic::success(translate('import_finished') . ' ' . json_encode($stats));

        return back();
    }

    private function report(ImportResult $result): void
    {
        match (true) {
            $result->succeeded() => ToastMagic::success(translate($result->outcome === ImportResult::IMPORTED ? 'product_imported' : 'product_updated')),
            $result->outcome === ImportResult::NEEDS_REVIEW => ToastMagic::warning(translate('import_needs_review') . ': ' . $result->reason),
            default => ToastMagic::error(translate('import_blocked') . ': ' . $result->reason),
        };
    }
}
