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
        private readonly SellerMarketTotals $totals,
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

    /**
     * What the shop holds. One figure while the account is in one currency, and none at all when it is in two (K-04.2).
     *
     * `available`, `balance` and `withdrawable` all read 140 for a shop holding 100 riyals and 40 dirhams, which is not money and
     * is not labelled with a currency either. Where the account holds more than one currency the three figures are withheld, the
     * reason travels in the answer, and each currency's own real figures are given beside it — the rule the statement already
     * applies, read from the same place in the ledger.
     */
    private function finance(int|string $sellerId): array
    {
        $recentPayout = null;
        if (Schema::hasTable('vendor_payout_requests')) {
            $recentPayout = VendorPayoutRequest::where('seller_id', $sellerId)->latest('id')->first();
        }

        $payout = [
            'recent_payout_status' => $recentPayout?->status,
            'recent_payout_reference' => $recentPayout?->reference,
        ];

        $money = $this->totals->money($this->ledger->balancesByCurrency($sellerId));

        if ($money['refused_because'] !== null) {
            return [
                'available' => null,
                'pending' => null,
                'balance' => null,
                'withdrawable' => null,
                'balances_by_currency' => $money['by_currency'],
                'totals_refused_because' => $money['refused_because'],
            ] + $payout;
        }

        $balances = $this->ledger->balances($sellerId);

        return [
            'available' => $balances['available'] ?? 0.0,
            'pending' => $balances['pending'] ?? 0.0,
            'balance' => $balances['balance'] ?? 0.0,
            'withdrawable' => $this->ledger->withdrawable($sellerId),
        ] + $payout;
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
