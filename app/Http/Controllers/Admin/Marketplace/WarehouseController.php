<?php

namespace App\Http\Controllers\Admin\Marketplace;

use App\Http\Controllers\BaseController;
use App\Http\Middleware\ScopeMarketplaceToStaffCountries;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Marketplace\WarehouseMarkets;
use App\Services\Marketplace\WarehouseService;
use App\Services\Staff\StaffCountryScope;
use App\Services\AuditLogger;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Admin warehouses & stock distribution (Phase 3, Stage C). Manage locations, and place or transfer a
 * product's stock across them — all through WarehouseService, which never touches sellable stock.
 */
class WarehouseController extends BaseController
{
    public function __construct(
        private readonly WarehouseService $warehouses,
        private readonly AuditLogger $audit,
        private readonly WarehouseMarkets $markets,
        private readonly StaffCountryScope $staff,
    ) {
        // Slice 5 of the staff country scopes (GCC-14c): these four screens MOVE stock, so a restricted employee reaches only the
        // products of their own countries on them -- the lists and the balances, and every write that names a product, a batch or a
        // purchase order. Inert while the switch is off.
        $this->middleware(ScopeMarketplaceToStaffCountries::class);
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $warehouses = collect();
        if (Schema::hasTable('warehouses')) {
            $listing = Warehouse::ownedBy(null)->orderByDesc('is_default')->orderBy('name');
            $bound = $this->staff->boundCountries();

            // A1-18: a warehouse carries no market of its own, so a restricted employee was shown every shelf -- the one thing on these
            // screens that was NOT narrowed. Its market is derived from what is on it, which presupposes nothing about the owner's open
            // question of whether one warehouse may hold two markets' stock. An empty shelf names no market and is shown to everybody.
            if ($bound !== null) {
                $listing = $this->markets->narrow(query: $listing, countries: $bound);
            }

            $warehouses = $listing->get();
        }

        // Optional product-distribution lookup.
        $productId = $request?->input('product_id') ? (int) $request->input('product_id') : null;
        $product = null;
        $breakdown = null;
        if ($productId && Schema::hasTable('products')) {
            $product = Product::select('id', 'name', 'current_stock')->find($productId);
            if ($product) {
                $breakdown = $this->warehouses->breakdown($productId);
            }
        }

        return view('admin-views.marketplace.warehouses', [
            'warehouses' => $warehouses,
            'productId' => $productId,
            'product' => $product,
            'breakdown' => $breakdown,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:191',
            'code' => 'nullable|string|max:40',
            'address' => 'nullable|string|max:512',
            'is_default' => 'nullable|boolean',
        ]);
        $validated['seller_id'] = null;
        $validated['is_default'] = (bool) ($validated['is_default'] ?? false);
        $validated['created_by'] = auth('admin')->id();

        // Only one default platform warehouse at a time.
        if ($validated['is_default']) {
            Warehouse::ownedBy(null)->update(['is_default' => false]);
        }

        $warehouse = Warehouse::create($validated);
        $this->audit->record(action: 'warehouse.created', subject: ['type' => 'warehouse', 'id' => $warehouse->id], after: ['name' => $warehouse->name]);
        ToastMagic::success(translate('warehouse_created'));

        return back();
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $warehouse = Warehouse::ownedBy(null)->findOrFail($id);
        $validated = $request->validate([
            'name' => 'required|string|max:191',
            'code' => 'nullable|string|max:40',
            'address' => 'nullable|string|max:512',
            'status' => 'nullable|in:active,inactive',
            'is_default' => 'nullable|boolean',
        ]);
        if (($validated['is_default'] ?? false)) {
            Warehouse::ownedBy(null)->where('id', '!=', $id)->update(['is_default' => false]);
        }
        $warehouse->update($validated + ['is_default' => (bool) ($validated['is_default'] ?? false)]);
        ToastMagic::success(translate('warehouse_updated'));

        return back();
    }

    public function place(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|integer',
            'product_id' => 'required|integer',
            'quantity' => 'required|integer|min:1',
        ]);

        $result = $this->warehouses->place($validated['warehouse_id'], $validated['product_id'], $validated['quantity'], auth('admin')->id());
        $result['ok'] ? ToastMagic::success(translate('stock_placed')) : ToastMagic::error(translate($result['reason']));

        return back();
    }

    public function transfer(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'from_warehouse_id' => 'required|integer',
            'to_warehouse_id' => 'required|integer|different:from_warehouse_id',
            'product_id' => 'required|integer',
            'quantity' => 'required|integer|min:1',
        ]);

        $result = $this->warehouses->transfer(
            $validated['from_warehouse_id'], $validated['to_warehouse_id'],
            $validated['product_id'], $validated['quantity'], auth('admin')->id()
        );
        $result['ok'] ? ToastMagic::success(translate('stock_transferred')) : ToastMagic::error(translate($result['reason']));

        return back();
    }
}
