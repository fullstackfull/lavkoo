<?php

namespace App\Services\SellerIntelligence\Producers;

use App\Services\Marketplace\OperationsPolicy;
use App\Models\SellerInsight;
use App\Services\SellerCenter\Copy;
use App\Models\VendorLedgerEntry;
use App\Services\SellerIntelligence\InsightDraft;
use App\Services\SellerIntelligence\InsightProducer;
use App\Services\SellerIntelligence\Severity\ImpactSignals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money a seller earned that the ledger does not know about.
 *
 * The platform reconciles its own books — `ReconciliationService` runs five checks — but those are
 * an operator's view, and a seller has never been shown the one finding that is unambiguously
 * theirs: a delivered order line with no earning entry against it. That is work done and not
 * credited. Nothing else in the Action Center is as directly about the seller's own money.
 *
 * The delay before a line counts as missing is deliberate. Earnings are written inside the order
 * transaction, but a line delivered thirty seconds ago in a system with a queue behind it is not
 * evidence of anything. Raising it immediately would produce a finding that resolves itself while
 * the seller is reading it, which teaches them the whole list is noise.
 */
class FinanceIntegrityProducer implements InsightProducer
{
    public const TYPE = 'FINANCE_INTEGRITY';

    /** Older than this and the money is a support case, not a banner. */
    private const LOOKBACK_DAYS = 90;

    private const LIMIT = 200;

    public function type(): string
    {
        return self::TYPE;
    }

    public function produce(int|string $sellerId): iterable
    {
        if (!Schema::hasTable('order_details') || !Schema::hasTable('vendor_ledger_entries')) {
            return [];
        }

        $graceHours = app(OperationsPolicy::class)->financeGraceHours();

        $delivered = DB::table('order_details')
            ->where('seller_id', $sellerId)
            ->where('delivery_status', 'delivered')
            ->whereBetween('created_at', [now()->subDays(self::LOOKBACK_DAYS), now()->subHours($graceHours)])
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['id', 'order_id', 'price', 'qty']);

        if ($delivered->isEmpty()) {
            return [];
        }

        // One query for the whole page rather than one per line.
        $credited = VendorLedgerEntry::where('seller_id', $sellerId)
            ->where('entry_type', VendorLedgerEntry::TYPE_ORDER_EARNING)
            ->where('reference_type', 'order_details')
            ->whereIn('reference_id', $delivered->pluck('id')->all())
            ->pluck('reference_id')
            ->flip();

        $missing = $delivered->reject(fn (object $line) => $credited->has((string) $line->id) || $credited->has($line->id));

        if ($missing->isEmpty()) {
            return [];
        }

        $uncredited = round($missing->sum(fn (object $line) => (float) $line->price * (int) $line->qty), 2);

        yield new InsightDraft(
            sellerId: $sellerId,
            type: self::TYPE,
            severity: SellerInsight::SEVERITY_HIGH,
            title: 'insight_delivered_without_earning',
            body: Copy::choice(
                'insight_body_delivered_without_earning_one',
                'insight_body_delivered_without_earning',
                $missing->count(),
                ['value' => $uncredited],
            ),
            entityType: 'finance_check',
            entityId: 'missing_earning',
            metric: $missing->count(),
            impact: $uncredited,
            actionKey: 'open_statement',
            actionParams: ['order_ids' => $missing->pluck('order_id')->unique()->take(50)->values()->all()],
            category: SellerInsight::CATEGORY_FINANCE,
            affectedCount: $missing->count(),
            signals: new ImpactSignals(
                revenueAtRisk: $uncredited,
                affectedCount: $missing->count(),
                // Uncredited money does not become less wrong because there is little of it.
                severityFloor: SellerInsight::SEVERITY_HIGH,
            ),
            metadata: [
                'count' => $missing->count(),
                'uncredited_amount' => $uncredited,
                'grace_hours' => $graceHours,
            ],
        );
    }
}
