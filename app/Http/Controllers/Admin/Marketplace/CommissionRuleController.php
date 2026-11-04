<?php

namespace App\Http\Controllers\Admin\Marketplace;

use App\Http\Controllers\BaseController;
use App\Models\CommissionRule;
use App\Services\Marketplace\CommissionEngine;
use App\Services\AuditLogger;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Admin commission rules (Phase 3, Stage B).
 *
 * The commission engine already resolves the winning rule by scope (product > vendor > category >
 * global) and priority and snapshots it per order line — but nothing wrote the `commission_rules`
 * table, so only the legacy flat-percentage fallback ever fired. This is the missing writer: define,
 * edit, activate and remove rules the engine reads. Non-breaking — with no active rule the engine keeps
 * using the legacy percentage exactly as before.
 */
class CommissionRuleController extends BaseController
{
    public function __construct(
        private readonly CommissionEngine $engine,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $rules = Schema::hasTable('commission_rules')
            ? CommissionRule::orderByDesc('priority')->orderByDesc('id')->get()
            : collect();

        // Optional resolution preview: what rule wins, and what commission it charges, for a sample line.
        $preview = null;
        if ($request?->filled('preview_amount')) {
            $line = array_filter([
                'product_id' => $request->input('preview_product_id') ?: null,
                'seller_id' => $request->input('preview_seller_id') ?: null,
                'category_id' => $request->input('preview_category_id') ?: null,
            ]);
            $preview = [
                'input' => $request->only('preview_amount', 'preview_product_id', 'preview_seller_id', 'preview_category_id'),
                'result' => $this->engine->calculate($line, (float) $request->input('preview_amount', 0)),
            ];
        }

        return view('admin-views.marketplace.commission-rules', [
            'rules' => $rules,
            'scopeTypes' => [CommissionEngine::SCOPE_GLOBAL, CommissionEngine::SCOPE_CATEGORY, CommissionEngine::SCOPE_VENDOR, CommissionEngine::SCOPE_PRODUCT],
            'rateTypes' => [CommissionEngine::RATE_PERCENTAGE, CommissionEngine::RATE_FIXED, CommissionEngine::RATE_PERCENTAGE_PLUS_FIXED],
            'preview' => $preview,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $rule = CommissionRule::create($this->validated($request) + ['created_by' => auth('admin')->id()]);
        $this->audit->record(
            action: 'commission.rule_created',
            subject: ['type' => 'commission_rule', 'id' => $rule->id],
            after: ['scope_type' => $rule->scope_type, 'rate_type' => $rule->rate_type, 'percentage' => $rule->percentage],
        );
        ToastMagic::success(translate('commission_rule_created'));

        return back();
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        CommissionRule::findOrFail($id)->update($this->validated($request));
        ToastMagic::success(translate('commission_rule_updated'));

        return back();
    }

    public function toggle(int $id): RedirectResponse
    {
        $rule = CommissionRule::findOrFail($id);
        $rule->update(['is_active' => !$rule->is_active]);
        ToastMagic::success($rule->is_active ? translate('commission_rule_activated') : translate('commission_rule_deactivated'));

        return back();
    }

    public function destroy(int $id): RedirectResponse
    {
        CommissionRule::findOrFail($id)->delete();
        ToastMagic::success(translate('commission_rule_deleted'));

        return back();
    }

    private function validated(Request $request): array
    {
        $v = $request->validate([
            'label' => 'nullable|string|max:191',
            'scope_type' => 'required|in:global,category,vendor,product',
            'scope_id' => 'nullable|integer|min:1|required_unless:scope_type,global',
            'rate_type' => 'required|in:percentage,fixed,percentage_plus_fixed',
            'percentage' => 'nullable|numeric|min:0|max:100',
            'fixed_amount' => 'nullable|numeric|min:0',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0',
            'priority' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
        ]);

        $blank = fn ($k) => ($v[$k] ?? '') === '' || !isset($v[$k]);

        return [
            'label' => $v['label'] ?? null,
            'scope_type' => $v['scope_type'],
            'scope_id' => $v['scope_type'] === 'global' ? null : ($v['scope_id'] ?? null),
            'rate_type' => $v['rate_type'],
            'percentage' => $blank('percentage') ? 0 : $v['percentage'],
            'fixed_amount' => $blank('fixed_amount') ? 0 : $v['fixed_amount'],
            'min_amount' => $blank('min_amount') ? null : $v['min_amount'],
            'max_amount' => $blank('max_amount') ? null : $v['max_amount'],
            'priority' => $v['priority'] ?? 10,
            'is_active' => (int) ($request->boolean('is_active')),
            'starts_at' => $blank('starts_at') ? null : $v['starts_at'],
            'ends_at' => $blank('ends_at') ? null : $v['ends_at'],
        ];
    }
}
