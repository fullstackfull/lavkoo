<?php

namespace App\Http\Controllers\Admin\Marketplace;

use App\Http\Controllers\BaseController;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Marketplace\InventoryService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Admin stock adjustments and movement history (Phase 3, Stage C).
 *
 * The reasoned alternative to editing current_stock on the product form: every change here carries a
 * reason and lands in the movement log. The controller carries the request; InventoryService applies
 * the change under a row lock and writes the movement and audit line.
 */
class InventoryAdjustmentController extends BaseController
{
    public function __construct(private readonly InventoryService $inventory)
    {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $productId = $request?->input('product_id') ? (int) $request->input('product_id') : null;
        $movementType = $request?->input('type');

        $movements = Schema::hasTable('stock_movements')
            ? $this->inventory->recent($productId, $movementType, 25)
            : null;

        // If a product is in focus, surface its current stock so the operator sees what they're changing.
        $product = null;
        if ($productId && Schema::hasTable('products')) {
            $product = Product::select('id', 'name', 'current_stock')->find($productId);
        }

        return view('admin-views.marketplace.inventory-adjustments', [
            'movements' => $movements,
            'product' => $product,
            'productId' => $productId,
            'movementType' => $movementType,
            'reasons' => StockMovement::REASONS,
        ]);
    }

    public function adjust(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer',
            'direction' => 'required|in:increase,decrease',
            'quantity' => 'required|integer|min:1',
            'reason' => 'required|string|max:60',
            'note' => 'nullable|string|max:512',
        ]);

        // The form asks for a magnitude plus a direction; the service takes a signed delta.
        $delta = $validated['direction'] === 'decrease' ? -$validated['quantity'] : $validated['quantity'];

        $result = $this->inventory->adjust(
            productId: $validated['product_id'],
            delta: $delta,
            reason: $validated['reason'],
            note: $validated['note'] ?? null,
            by: auth('admin')->id(),
            byType: 'admin',
        );

        if ($result['ok']) {
            ToastMagic::success(translate('stock_adjusted') . ' — ' . translate('new_balance') . ': ' . $result['balance_after']);
        } else {
            ToastMagic::error(translate($result['reason']));
        }

        return back();
    }
}
