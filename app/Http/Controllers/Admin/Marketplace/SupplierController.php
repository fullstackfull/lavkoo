<?php

namespace App\Http\Controllers\Admin\Marketplace;

use App\Http\Controllers\BaseController;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\AuditLogger;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Admin supplier management (Phase 3, Stage C). Platform-owned suppliers (seller_id null) — the
 * source records that purchase orders point at.
 */
class SupplierController extends BaseController
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $suppliers = collect();
        $paginator = null;

        if (Schema::hasTable('suppliers')) {
            $query = Supplier::ownedBy(null)->orderByDesc('id');
            if ($search = $request?->input('search')) {
                $query->where('name', 'like', "%{$search}%");
            }
            $paginator = $query->paginate(20)->withQueryString();
            $suppliers = collect($paginator->items());
        }

        return view('admin-views.marketplace.suppliers', [
            'suppliers' => $suppliers,
            'paginator' => $paginator,
            'search' => $request?->input('search'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['seller_id'] = null;
        $data['created_by'] = auth('admin')->id();

        $supplier = Supplier::create($data);
        $this->audit->record(action: 'procurement.supplier_created', subject: ['type' => 'supplier', 'id' => $supplier->id], after: ['name' => $supplier->name]);
        ToastMagic::success(translate('supplier_created'));

        return back();
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $supplier = Supplier::ownedBy(null)->findOrFail($id);
        $supplier->update($this->validated($request));
        ToastMagic::success(translate('supplier_updated'));

        return back();
    }

    public function destroy(int $id): RedirectResponse
    {
        $supplier = Supplier::ownedBy(null)->findOrFail($id);

        // Refuse to delete a supplier that has purchase orders — that would orphan a financial record.
        // Deactivate it instead, which keeps it off new orders without erasing history.
        if (Schema::hasTable('purchase_orders') && PurchaseOrder::where('supplier_id', $supplier->id)->exists()) {
            $supplier->update(['status' => Supplier::STATUS_INACTIVE]);
            ToastMagic::info(translate('supplier_has_orders_so_it_was_deactivated_not_deleted'));
            return back();
        }

        $supplier->delete();
        ToastMagic::success(translate('supplier_deleted'));

        return back();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:191',
            'contact_name' => 'nullable|string|max:191',
            'email' => 'nullable|email|max:191',
            'phone' => 'nullable|string|max:40',
            'tax_number' => 'nullable|string|max:60',
            'address' => 'nullable|string|max:512',
            'status' => 'nullable|in:active,inactive',
            'notes' => 'nullable|string|max:1000',
        ]);
    }
}
