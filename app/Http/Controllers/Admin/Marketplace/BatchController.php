<?php

namespace App\Http\Controllers\Admin\Marketplace;

use App\Http\Controllers\BaseController;
use App\Models\ProductBatch;
use App\Services\Marketplace\BatchService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Admin batch & expiry management (Phase 3, Stage C). Record batches, see what is expiring, and write
 * off expired stock — the write-off going through the inventory adjustment path in BatchService.
 */
class BatchController extends BaseController
{
    public function __construct(private readonly BatchService $batches)
    {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $filter = $request?->input('filter', 'all');
        $productId = $request?->input('product_id') ? (int) $request->input('product_id') : null;

        $batches = collect();
        $paginator = null;
        $expiringCount = 0;
        $expiredCount = 0;

        if (Schema::hasTable('product_batches')) {
            // The marketplace's own horizon, rather than a second copy of the default.
            $expiringCount = $this->batches->expiringSoon()->count();
            $expiredCount = $this->batches->expired()->count();

            $query = ProductBatch::query()->orderByRaw('expiry_date is null')->orderBy('expiry_date')->orderByDesc('id');
            if ($productId) {
                $query->where('product_id', $productId);
            }
            if ($filter === 'expiring') {
                $query->active()->whereNotNull('expiry_date')->whereDate('expiry_date', '<=', now()->addDays(30)->toDateString());
            } elseif ($filter === 'expired') {
                $query->active()->whereNotNull('expiry_date')->whereDate('expiry_date', '<', now()->toDateString());
            } elseif ($filter === 'active') {
                $query->active();
            }

            $paginator = $query->paginate(20)->withQueryString();
            $batches = collect($paginator->items());
        }

        return view('admin-views.marketplace.batches', [
            'batches' => $batches,
            'paginator' => $paginator,
            'filter' => $filter,
            'productId' => $productId,
            'expiringCount' => $expiringCount,
            'expiredCount' => $expiredCount,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer',
            'batch_number' => 'nullable|string|max:120',
            'expiry_date' => 'nullable|date',
            'quantity' => 'required|integer|min:0',
            'note' => 'nullable|string|max:512',
        ]);

        $this->batches->addBatch(
            productId: $validated['product_id'],
            batchNumber: $validated['batch_number'] ?? null,
            expiryDate: $validated['expiry_date'] ?? null,
            qty: $validated['quantity'],
            by: auth('admin')->id(),
            note: $validated['note'] ?? null,
        );
        ToastMagic::success(translate('batch_recorded'));

        return back();
    }

    public function writeOff(int $id): RedirectResponse
    {
        $batch = ProductBatch::findOrFail($id);
        $result = $this->batches->writeOff($batch, auth('admin')->id());

        if ($result['ok']) {
            ToastMagic::success(translate('batch_written_off') . ' — ' . translate('new_balance') . ': ' . ($result['balance_after'] ?? '—'));
        } else {
            ToastMagic::error(translate($result['reason']));
        }

        return back();
    }
}
