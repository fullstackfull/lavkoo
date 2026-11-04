<?php

namespace App\Http\Controllers\Admin\Marketplace;

use App\Http\Controllers\BaseController;
use App\Models\Category;
use App\Models\CategoryGovernance;
use App\Services\AuditLogger;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Configures per-category governance (Phase 3, Stage A — spec item 10).
 *
 * Lists top-level categories with their governance, and saves it. Every field is optional; leaving
 * one blank means the category inherits the global default, which the resolver service enforces.
 */
class CategoryGovernanceController extends BaseController
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $categories = collect();
        if (Schema::hasTable('categories')) {
            $categories = Category::where('position', 0)->orderBy('priority')->orderBy('name')->get();
        }

        $governance = Schema::hasTable('category_governance')
            ? CategoryGovernance::whereIn('category_id', $categories->pluck('id'))->get()->keyBy('category_id')
            : collect();

        return view('admin-views.marketplace.category-governance', [
            'categories' => $categories,
            'governance' => $governance,
            'globalReturnDays' => (int) (getWebConfig(name: 'refund_day_limit') ?? 0),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'category_id' => 'required|integer',
            'return_window_days' => 'nullable|integer|min:0|max:3650',
            'tax_class' => 'nullable|string|max:60',
            'required_attributes' => 'nullable|string|max:2000',
            'requires_moderation' => 'nullable|boolean',
            'shipping_restricted' => 'nullable|boolean',
            'restriction_note' => 'nullable|string|max:512',
        ]);

        // Required attributes arrive as a comma-separated list; store the trimmed, non-empty keys.
        $attributes = array_values(array_filter(array_map('trim', explode(',', $validated['required_attributes'] ?? ''))));

        CategoryGovernance::updateOrCreate(
            ['category_id' => $validated['category_id']],
            [
                // An empty return-window field means "inherit" — stored as null, not 0, so the
                // resolver falls back to the global rather than reading it as "no returns".
                'return_window_days' => ($validated['return_window_days'] === null || $validated['return_window_days'] === '') ? null : (int) $validated['return_window_days'],
                'tax_class' => $validated['tax_class'] ?: null,
                'required_attributes' => $attributes ?: null,
                'requires_moderation' => (bool) ($validated['requires_moderation'] ?? false),
                'shipping_restricted' => (bool) ($validated['shipping_restricted'] ?? false),
                'restriction_note' => $validated['restriction_note'] ?: null,
                'updated_by' => auth('admin')->id(),
            ]
        );

        cache()->flush();

        $this->audit->record(
            action: 'category.governance_updated',
            subject: ['type' => 'category', 'id' => $validated['category_id']],
            after: ['return_window_days' => $validated['return_window_days'], 'requires_moderation' => (bool) ($validated['requires_moderation'] ?? false)],
        );

        ToastMagic::success(translate('category_governance_updated'));

        return back();
    }
}
