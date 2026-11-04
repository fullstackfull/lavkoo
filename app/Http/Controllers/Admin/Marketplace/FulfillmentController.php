<?php

namespace App\Http\Controllers\Admin\Marketplace;

use App\Http\Controllers\BaseController;
use App\Models\OrderFulfillment;
use App\Models\Warehouse;
use App\Services\Marketplace\FulfillmentService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Admin order fulfilment (Phase 3, Stage C). The pick/pack/ship queue and its forward-only
 * transitions, driven by FulfillmentService — which never touches order status.
 */
class FulfillmentController extends BaseController
{
    public function __construct(private readonly FulfillmentService $fulfillment)
    {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $fulfillments = collect();
        $paginator = null;

        if (Schema::hasTable('order_fulfillments')) {
            $query = OrderFulfillment::query()->orderByDesc('id');
            if ($status = $request?->input('status')) {
                $query->where('status', $status);
            }
            if ($orderId = $request?->input('order_id')) {
                $query->where('order_id', (int) $orderId);
            }
            $paginator = $query->paginate(20)->withQueryString();
            $fulfillments = collect($paginator->items());
        }

        $warehouses = (Schema::hasTable('warehouses'))
            ? Warehouse::ownedBy(null)->where('status', 'active')->orderBy('name')->get()
            : collect();

        return view('admin-views.marketplace.fulfillments', [
            'fulfillments' => $fulfillments,
            'paginator' => $paginator,
            'warehouses' => $warehouses,
            'status' => $request?->input('status'),
            'flow' => OrderFulfillment::FLOW,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'order_id' => 'required|integer',
            'seller_id' => 'nullable|integer',
            'warehouse_id' => 'nullable|integer',
        ]);

        $result = $this->fulfillment->open($validated['order_id'], $validated['seller_id'] ?? null, $validated['warehouse_id'] ?? null, auth('admin')->id());
        $result['ok'] ? ToastMagic::success(translate('fulfilment_opened') . ' — ' . $result['fulfillment']->reference) : ToastMagic::error(translate($result['reason']));

        return back();
    }

    public function advance(Request $request, int $id): RedirectResponse
    {
        $fulfillment = OrderFulfillment::findOrFail($id);
        $validated = $request->validate([
            'to_status' => 'required|in:picking,packed,ready,shipped',
            'carrier' => 'nullable|string|max:120',
            'tracking_number' => 'nullable|string|max:120',
        ]);

        $result = $this->fulfillment->advance(
            $fulfillment,
            $validated['to_status'],
            ['carrier' => $validated['carrier'] ?? null, 'tracking_number' => $validated['tracking_number'] ?? null],
            auth('admin')->id(),
        );
        $result['ok'] ? ToastMagic::success(translate('fulfilment_updated')) : ToastMagic::error(translate($result['reason']));

        return back();
    }

    public function cancel(int $id): RedirectResponse
    {
        $fulfillment = OrderFulfillment::findOrFail($id);
        $result = $this->fulfillment->cancel($fulfillment);
        $result['ok'] ? ToastMagic::success(translate('fulfilment_canceled')) : ToastMagic::error(translate($result['reason']));

        return back();
    }
}
