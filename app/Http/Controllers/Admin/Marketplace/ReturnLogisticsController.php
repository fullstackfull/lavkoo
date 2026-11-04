<?php

namespace App\Http\Controllers\Admin\Marketplace;

use App\Http\Controllers\BaseController;
use App\Models\ReturnShipment;
use App\Services\Marketplace\ReturnLogisticsService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Admin returns logistics (Phase 3, Stage C). The RMA queue and its state transitions; receiving a
 * restockable return puts stock back through ReturnLogisticsService.
 */
class ReturnLogisticsController extends BaseController
{
    public function __construct(private readonly ReturnLogisticsService $returns)
    {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $returns = collect();
        $paginator = null;

        if (Schema::hasTable('return_shipments')) {
            $query = ReturnShipment::query()->orderByDesc('id');
            if ($status = $request?->input('status')) {
                $query->where('status', $status);
            }
            $paginator = $query->paginate(20)->withQueryString();
            $returns = collect($paginator->items());
        }

        return view('admin-views.marketplace.returns', [
            'returns' => $returns,
            'paginator' => $paginator,
            'status' => $request?->input('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'order_id' => 'nullable|integer',
            'order_details_id' => 'nullable|integer',
            'refund_request_id' => 'nullable|integer',
            'product_id' => 'nullable|integer',
            'seller_id' => 'nullable|integer',
            'qty' => 'required|integer|min:1',
            'reason' => 'nullable|string|max:120',
            'restock' => 'nullable|boolean',
            'note' => 'nullable|string|max:512',
        ]);

        // A return needs to point at something to be actionable.
        if (empty($validated['product_id']) && empty($validated['order_id']) && empty($validated['order_details_id'])) {
            ToastMagic::error(translate('a_return_needs_an_order_or_a_product'));
            return back()->withInput();
        }

        $validated['restock'] = (bool) ($validated['restock'] ?? true);
        $rma = $this->returns->authorize($validated, auth('admin')->id(), 'admin');
        ToastMagic::success(translate('return_authorized') . ' — ' . $rma->reference);

        return back();
    }

    public function transit(Request $request, int $id): RedirectResponse
    {
        $rma = ReturnShipment::findOrFail($id);
        $validated = $request->validate([
            'carrier' => 'nullable|string|max:120',
            'tracking_number' => 'nullable|string|max:120',
        ]);

        $result = $this->returns->markInTransit($rma, $validated['carrier'] ?? null, $validated['tracking_number'] ?? null);
        $result['ok'] ? ToastMagic::success(translate('marked_in_transit')) : ToastMagic::error(translate($result['reason']));

        return back();
    }

    public function receive(int $id): RedirectResponse
    {
        $rma = ReturnShipment::findOrFail($id);
        $result = $this->returns->receive($rma, auth('admin')->id());

        if ($result['ok']) {
            $msg = $result['restocked']
                ? translate('return_received_and_restocked') . ' — ' . translate('new_balance') . ': ' . $result['balance_after']
                : translate('return_received');
            ToastMagic::success($msg);
        } else {
            ToastMagic::error(translate($result['reason']));
        }

        return back();
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $rma = ReturnShipment::findOrFail($id);
        $validated = $request->validate(['reason' => 'nullable|string|max:512']);

        $result = $this->returns->reject($rma, $validated['reason'] ?? null);
        $result['ok'] ? ToastMagic::success(translate('return_rejected')) : ToastMagic::error(translate($result['reason']));

        return back();
    }
}
