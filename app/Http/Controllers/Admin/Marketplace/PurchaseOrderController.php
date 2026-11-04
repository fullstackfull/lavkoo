<?php

namespace App\Http\Controllers\Admin\Marketplace;

use App\Http\Controllers\BaseController;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Services\Marketplace\PurchaseOrderService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Admin purchase orders and receiving (Phase 3, Stage C). The controller carries requests in;
 * PurchaseOrderService owns the state machine, the stock-in and the audit lines.
 */
class PurchaseOrderController extends BaseController
{
    public function __construct(private readonly PurchaseOrderService $service)
    {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $orders = collect();
        $paginator = null;

        if (Schema::hasTable('purchase_orders')) {
            $query = PurchaseOrder::ownedBy(null)->with('supplier')->orderByDesc('id');
            if ($status = $request?->input('status')) {
                $query->where('status', $status);
            }
            $paginator = $query->paginate(20)->withQueryString();
            $orders = collect($paginator->items());
        }

        return view('admin-views.marketplace.purchase-orders', [
            'orders' => $orders,
            'paginator' => $paginator,
            'status' => $request?->input('status'),
        ]);
    }

    public function create(Request|null $request, ?string $type = null): View
    {
        $suppliers = Schema::hasTable('suppliers')
            ? Supplier::ownedBy(null)->where('status', 'active')->orderBy('name')->get()
            : collect();

        return view('admin-views.marketplace.purchase-order-create', ['suppliers' => $suppliers]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'currency' => 'nullable|string|max:10',
            'expected_at' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|integer',
            'items.*.description' => 'nullable|string|max:255',
            'items.*.sku' => 'nullable|string|max:120',
            'items.*.qty_ordered' => 'required|integer|min:1',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
        ]);

        // A line needs to point at something — a catalogue product or at least a description.
        $items = array_values(array_filter($validated['items'], fn ($i) => !empty($i['product_id']) || !empty($i['description'])));
        if ($items === []) {
            ToastMagic::error(translate('add_at_least_one_line_with_a_product_or_description'));
            return back()->withInput();
        }

        $po = $this->service->create(
            supplierId: $validated['supplier_id'],
            sellerId: null,
            attributes: ['currency' => $validated['currency'] ?? null, 'expected_at' => $validated['expected_at'] ?? null, 'notes' => $validated['notes'] ?? null],
            items: $items,
            createdBy: auth('admin')->id(),
        );

        ToastMagic::success(translate('purchase_order_created'));

        return redirect()->route('admin.marketplace.purchase-orders.show', $po->id);
    }

    public function show(int $id): View
    {
        $order = PurchaseOrder::ownedBy(null)->with(['supplier', 'items'])->findOrFail($id);

        return view('admin-views.marketplace.purchase-order-show', ['order' => $order]);
    }

    public function place(int $id): RedirectResponse
    {
        $order = PurchaseOrder::ownedBy(null)->findOrFail($id);
        $result = $this->service->place($order);

        $result['ok'] ? ToastMagic::success(translate('purchase_order_placed')) : ToastMagic::error(translate($result['reason']));

        return back();
    }

    public function receive(Request $request, int $id): RedirectResponse
    {
        $order = PurchaseOrder::ownedBy(null)->findOrFail($id);

        if ($request->boolean('receive_all')) {
            $result = $this->service->receiveAll($order, auth('admin')->id());
            $result['ok'] ? ToastMagic::success(translate('all_items_received')) : ToastMagic::error(translate($result['reason']));
            return back();
        }

        $validated = $request->validate([
            'item_id' => 'required|integer',
            'qty' => 'required|integer|min:1',
        ]);

        $item = PurchaseOrderItem::where(['id' => $validated['item_id'], 'purchase_order_id' => $order->id])->first();
        if (!$item) {
            ToastMagic::error(translate('line_not_found'));
            return back();
        }

        $result = $this->service->receiveItem($item, $validated['qty'], auth('admin')->id());
        $result['ok'] ? ToastMagic::success(translate('items_received')) : ToastMagic::error(translate($result['reason']));

        return back();
    }

    public function cancel(int $id): RedirectResponse
    {
        $order = PurchaseOrder::ownedBy(null)->findOrFail($id);
        $result = $this->service->cancel($order);

        $result['ok'] ? ToastMagic::success(translate('purchase_order_canceled')) : ToastMagic::error(translate($result['reason']));

        return back();
    }
}
