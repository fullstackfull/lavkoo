<?php

namespace App\Http\Controllers\Seller;

use App\Services\SellerIntelligence\ControlTowerService;
use App\Services\SellerIntelligence\DailyBriefingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The single answer to "what requires my attention right now" (handoff 07.2).
 *
 * Sections render in the order the server sends them and an empty section is never rendered as a
 * heading with nothing under it — both are server-authority rules the UI must not undo.
 */
class ControlTowerController extends SellerCenterController
{
    public function __construct(
        private readonly ControlTowerService $tower,
        private readonly DailyBriefingService $briefing,
    ) {
    }

    public function __invoke(Request $request): View
    {
        $principal = $this->principal($request);

        try {
            $tower = $this->tower->forSeller($principal->sellerId());
            $failed = false;
        } catch (\Throwable) {
            $tower = null;
            $failed = true;
        }

        return view('seller-views.control-tower', [
            'tower' => $tower,
            'failed' => $failed,
            // The queue panel counts the operational queues directly. The issue counts answer a
            // different question, and rendering them as a queue would put a zero next to work that
            // is genuinely waiting.
            'queue' => $failed ? null : ($this->briefing->forSeller($principal->sellerId())['waiting'] ?? null),
            // Sections whose module this role cannot read are omitted, and the page says how many
            // were hidden rather than quietly showing a shorter list (handoff 01 §2).
            'hiddenSections' => $this->hiddenFor($principal),
            'checkedAt' => now(),
        ]);
    }

    /** @return array<int, string> */
    private function hiddenFor(\App\Services\Marketplace\SellerPrincipal $principal): array
    {
        $gated = [
            'financial_exceptions' => 'finance.view',
            'catalog_and_pricing' => 'products.view',
            'inventory_risk' => 'products.view',
            'sla_risk' => 'orders.view',
            'returns_requiring_action' => 'orders.view',
            'fulfillment_exceptions' => 'orders.view',
        ];

        return array_keys(array_filter(
            $gated,
            static fn (string $permission) => !$principal->can($permission),
        ));
    }
}
