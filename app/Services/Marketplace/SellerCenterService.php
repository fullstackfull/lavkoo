<?php

namespace App\Services\Marketplace;

use App\Models\SellerSlaBreach;
use App\Models\VendorPayoutRequest;
use Illuminate\Support\Facades\Schema;

/**
 * Seller Center — the seller's operational cockpit (Phase 3, Stage A).
 *
 * This owns no data of its own. It composes the seller-facing services built across the phase —
 * verification (KYC), the performance scorecard, the vendor ledger, and the SLA breach ledger — into
 * one overview, so a seller sees their whole standing in a place instead of four. Because it delegates
 * to those services, every number here is the same one their dedicated screen shows; the Center never
 * re-derives a metric.
 */
class SellerCenterService
{
    public function __construct(
        private readonly SellerVerificationService $verification,
        private readonly SellerScorecardService $scorecards,
        private readonly VendorLedger $ledger,
    ) {
    }

    /**
     * The full overview for one seller.
     *
     * @return array{verification: array, performance: array, finance: array, sla: array}
     */
    public function overview(int|string $sellerId): array
    {
        return [
            'verification' => [
                'status' => $this->verification->overallStatus($sellerId),
                'required_for_payout' => $this->verification->isKycRequiredForPayout(),
            ],
            'performance' => $this->scorecards->scorecard($sellerId),
            'finance' => $this->finance($sellerId),
            'sla' => $this->sla($sellerId),
        ];
    }

    private function finance(int|string $sellerId): array
    {
        $balances = $this->ledger->balances($sellerId);
        $recentPayout = null;
        if (Schema::hasTable('vendor_payout_requests')) {
            $recentPayout = VendorPayoutRequest::where('seller_id', $sellerId)->latest('id')->first();
        }

        return [
            'available' => $balances['available'] ?? 0.0,
            'pending' => $balances['pending'] ?? 0.0,
            'balance' => $balances['balance'] ?? 0.0,
            'withdrawable' => $this->ledger->withdrawable($sellerId),
            'recent_payout_status' => $recentPayout?->status,
            'recent_payout_reference' => $recentPayout?->reference,
        ];
    }

    private function sla(int|string $sellerId): array
    {
        $open = [];
        if (Schema::hasTable('seller_sla_breaches')) {
            $open = SellerSlaBreach::where('seller_id', $sellerId)->open()->get()->all();
        }

        return [
            'open_breach_count' => count($open),
            'open_breaches' => $open,
        ];
    }
}
