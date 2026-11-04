<?php

namespace App\Http\Controllers\Admin\Marketplace;

use App\Http\Controllers\BaseController;
use App\Models\PaymentRoutingRule;
use App\Services\Marketplace\PaymentRoutingService;
use App\Services\AuditLogger;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin payment orchestration (Phase 3, Stage E). Manage gateway routing rules and preview the
 * gateways an order resolves to. The resolver is non-breaking — with no rule the gateway list is
 * returned unchanged.
 */
class PaymentRoutingController extends BaseController
{
    public function __construct(
        private readonly PaymentRoutingService $routing,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $rules = $this->routing->rules();
        $gateways = $this->gatewayCodes();

        // Optional resolution preview.
        $preview = null;
        if ($request?->filled('amount') || $request?->filled('country')) {
            $preview = [
                'input' => $request->only('amount', 'country'),
                'result' => $this->routing->resolve($gateways, (float) $request->input('amount', 0), $request->input('country')),
            ];
        }

        return view('admin-views.marketplace.payment-routing', [
            'rules' => $rules,
            'gateways' => $gateways,
            'preview' => $preview,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $rule = PaymentRoutingRule::create($this->validated($request) + ['created_by' => auth('admin')->id()]);
        $this->audit->record(action: 'payment.routing_rule_created', subject: ['type' => 'payment_routing_rule', 'id' => $rule->id], after: ['gateway' => $rule->gateway_code, 'action' => $rule->action]);
        ToastMagic::success(translate('routing_rule_created'));

        return back();
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        PaymentRoutingRule::findOrFail($id)->update($this->validated($request));
        ToastMagic::success(translate('routing_rule_updated'));

        return back();
    }

    public function destroy(int $id): RedirectResponse
    {
        PaymentRoutingRule::findOrFail($id)->delete();
        ToastMagic::success(translate('routing_rule_deleted'));

        return back();
    }

    private function validated(Request $request): array
    {
        $v = $request->validate([
            'name' => 'required|string|max:191',
            'gateway_code' => 'required|string|max:60',
            'action' => 'required|in:hide,prefer',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0',
            'country' => 'nullable|string|max:120',
            'priority' => 'nullable|integer|min:0',
            'status' => 'nullable|in:active,inactive',
        ]);

        return [
            'name' => $v['name'],
            'gateway_code' => $v['gateway_code'],
            'action' => $v['action'],
            'min_amount' => ($v['min_amount'] ?? '') === '' ? null : $v['min_amount'],
            'max_amount' => ($v['max_amount'] ?? '') === '' ? null : $v['max_amount'],
            'country' => ($v['country'] ?? '') === '' ? null : $v['country'],
            'priority' => $v['priority'] ?? 10,
            'status' => $v['status'] ?? 'active',
        ];
    }

    /** The payment gateway codes the platform knows about (from addon_settings). */
    private function gatewayCodes(): array
    {
        if (!Schema::hasTable('addon_settings')) {
            return [];
        }

        return DB::table('addon_settings')->where('settings_type', 'payment_config')
            ->orderBy('key_name')->pluck('key_name')->all();
    }
}
