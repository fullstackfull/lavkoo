<?php

namespace App\Http\Controllers\Admin\Marketplace;

use App\Http\Controllers\BaseController;
use App\Models\ProductModerationEvent;
use App\Services\Marketplace\ProductModerationService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Product moderation with history and bulk actions (Phase 3, Stage A — spec item 7).
 *
 * A thin controller over ProductModerationService: it carries the request in, the service enforces
 * the state mapping, writes the history event and the audit line. Kept separate from the large
 * legacy ProductController so it adds the moderation trail without touching the save path.
 */
class ProductModerationController extends BaseController
{
    public function __construct(private readonly ProductModerationService $moderation)
    {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        return view('admin-views.marketplace.product-moderation', [
            'reasons' => ProductModerationService::REASONS,
        ]);
    }

    /** A product's moderation history — the "why was this rejected" the seller and admin both need. */
    public function history(int $productId): JsonResponse
    {
        if (!Schema::hasTable('product_moderation_events')) {
            return response()->json(['events' => []]);
        }

        return response()->json([
            'events' => ProductModerationEvent::forProduct($productId)->limit(50)->get(),
        ]);
    }

    public function moderate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer',
            'action' => 'required|in:approved,rejected,needs_changes,suspended',
            'reason_codes' => 'nullable|array',
            'note' => 'nullable|string|max:2000',
        ]);

        $event = match ($validated['action']) {
            'approved' => $this->moderation->approve($validated['product_id'], $validated['note'] ?? null),
            'rejected' => $this->moderation->reject($validated['product_id'], $validated['reason_codes'] ?? [], $validated['note'] ?? null),
            'needs_changes' => $this->moderation->needsChanges($validated['product_id'], $validated['reason_codes'] ?? [], $validated['note'] ?? null),
            'suspended' => $this->moderation->suspend($validated['product_id'], $validated['reason_codes'] ?? [], $validated['note'] ?? null),
        };

        if (!$event) {
            ToastMagic::error(translate('product_not_found'));

            return back();
        }

        ToastMagic::success(translate('product_' . $validated['action']));

        return back();
    }

    /** Bulk approve / reject / request changes, each producing its own audit line (spec item 7). */
    public function bulk(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_ids' => 'required|array',
            'product_ids.*' => 'integer',
            'action' => 'required|in:approved,rejected,needs_changes,suspended',
            'reason_codes' => 'nullable|array',
            'note' => 'nullable|string|max:2000',
        ]);

        $result = $this->moderation->bulk(
            $validated['action'],
            $validated['product_ids'],
            $validated['reason_codes'] ?? [],
            $validated['note'] ?? null,
        );

        ToastMagic::success(translate('bulk_moderation_done') . ": {$result['processed']} " . translate('processed')
            . ($result['skipped'] ? ", {$result['skipped']} " . translate('skipped') : ''));

        return back();
    }
}
