<?php

namespace App\Services\SellerCenter;

use App\Models\SellerInsight;
use App\Services\Platform\Policy;
use App\Services\Marketplace\SellerPrincipal;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The badge numbers on the rail, the panel and the mobile drawer.
 *
 * Every number is counted from a real row. A badge is a promise that something is waiting, so a
 * count that guessed would send a seller looking for work that is not there — and after the second
 * time, they would stop trusting the badges entirely.
 *
 * Counted in one pass and cached for a minute: the shell renders on every page, and a dozen
 * `COUNT(*)` queries per navigation is a cost the seller pays with latency on every click.
 * A table that does not exist yet returns no badge rather than a zero.
 */
class Counts
{
    private const TTL_SECONDS = 60;

    public function for(SellerPrincipal $principal): array
    {
        return Cache::remember(
            'sc:counts:' . $principal->sellerId() . ':' . ($principal->staffId() ?? 0),
            self::TTL_SECONDS,
            fn () => $this->collect($principal),
        );
    }

    public static function forget(int|string $sellerId): void
    {
        Cache::forget('sc:counts:' . $sellerId . ':0');
    }

    private function collect(SellerPrincipal $principal): array
    {
        $sellerId = $principal->sellerId();

        return array_filter([
            'issues_open' => $this->openIssues($sellerId),
            'issues_severity' => $this->highestOpenSeverity($sellerId),
            'actions_mine' => $this->assignedToMe($principal),
            'orders_ready' => $this->orders($sellerId, ['confirmed', 'processing']),
            'returns_open' => $this->returns($sellerId),
            'products_draft' => $this->products($sellerId, ['request_status' => 0]),
            'products_issues' => $this->productIssues($sellerId),
            'bulk_running' => $this->bulkJobs($sellerId),
            'inventory_low' => $this->insightCount($sellerId, 'INVENTORY_RISK'),
            'shipping_exceptions' => $this->insightCount($sellerId, 'SHIPPING_EXCEPTION'),
            'reconciliation_unmatched' => $this->insightCount($sellerId, 'FINANCE_INTEGRITY'),
            'brands_expiring' => $this->insightCount($sellerId, 'BRAND_COMPLIANCE'),
            'compliance_action' => $this->complianceAction($sellerId),
            'brands_pending' => $this->brandClaims($sellerId),
            'webhooks_failing' => $this->failingWebhooks($sellerId),
        ], static fn ($value) => $value !== null && $value !== 0 && $value !== '');
    }

    private function openIssues(int $sellerId): ?int
    {
        if (!Schema::hasTable('seller_insights')) {
            return null;
        }

        return (int) SellerInsight::forSeller($sellerId)->open()->count();
    }

    /**
     * The tone of the issue badge is the worst thing inside it, so a single critical is never
     * hidden behind a neutral count of forty.
     */
    private function highestOpenSeverity(int $sellerId): ?string
    {
        if (!Schema::hasTable('seller_insights')) {
            return null;
        }

        $severities = SellerInsight::forSeller($sellerId)->open()->distinct()->pluck('severity')->all();
        $highest = Status::highest($severities);

        return $highest === null ? null : Status::severity($highest)['tone'];
    }

    private function assignedToMe(SellerPrincipal $principal): ?int
    {
        if (!Schema::hasTable('seller_insights') || $principal->staffId() === null) {
            return null;
        }

        return (int) SellerInsight::forSeller($principal->sellerId())
            ->open()
            ->where('assigned_staff_id', $principal->staffId())
            ->count();
    }

    private function orders(int $sellerId, array $statuses): ?int
    {
        if (!Schema::hasTable('orders')) {
            return null;
        }

        return (int) DB::table('orders')
            ->where(['seller_is' => 'seller', 'seller_id' => $sellerId])
            ->whereIn('order_status', $statuses)
            ->count();
    }

    private function returns(int $sellerId): ?int
    {
        if (!Schema::hasTable('refund_requests') || !Schema::hasTable('orders')) {
            return null;
        }

        return (int) DB::table('refund_requests')
            ->join('orders', 'orders.id', '=', 'refund_requests.order_id')
            ->where('orders.seller_is', 'seller')
            ->where('orders.seller_id', $sellerId)
            ->where('refund_requests.status', 'pending')
            ->count();
    }

    private function products(int $sellerId, array $where): ?int
    {
        if (!Schema::hasTable('products')) {
            return null;
        }

        return (int) DB::table('products')
            ->where(['added_by' => 'seller', 'user_id' => $sellerId])
            ->where($where)
            ->count();
    }

    private function productIssues(int $sellerId): ?int
    {
        if (!Schema::hasTable('seller_insights')) {
            return null;
        }

        return (int) SellerInsight::forSeller($sellerId)
            ->open()
            ->where('category', SellerInsight::CATEGORY_CATALOG)
            ->count();
    }

    private function insightCount(int $sellerId, string $type): ?int
    {
        if (!Schema::hasTable('seller_insights')) {
            return null;
        }

        return (int) SellerInsight::forSeller($sellerId)->open()->where('type', $type)->count();
    }

    private function bulkJobs(int $sellerId): ?int
    {
        if (!Schema::hasTable('seller_bulk_jobs')) {
            return null;
        }

        return (int) DB::table('seller_bulk_jobs')
            ->where('seller_id', $sellerId)
            ->whereIn('status', ['queued', 'processing'])
            ->count();
    }

    /**
     * Documents needing the seller to do something — rejected, expired, or expiring inside the
     * 45-day window the whole product warns at (handoff 09 §5).
     */
    private function complianceAction(int $sellerId): ?int
    {
        if (!Schema::hasTable('seller_verification_documents')) {
            return null;
        }

        $noticeDays = app(Policy::class)->int('compliance_expiry_notice_days');

        return (int) DB::table('seller_verification_documents')
            ->where('seller_id', $sellerId)
            // How much warning a seller gets is the marketplace's promise, not a literal in a
            // badge query — a pharmacy licence and a tax certificate are not renewed on the same
            // notice.
            ->where(function ($query) use ($noticeDays) {
                $query->whereIn('status', ['rejected', 'expired', 'more_information_required'])
                    ->orWhere(function ($expiring) use ($noticeDays) {
                        $expiring->whereNotNull('expires_at')
                            ->whereBetween('expires_at', [now(), now()->addDays($noticeDays)]);
                    });
            })
            ->count();
    }

    private function brandClaims(int $sellerId): ?int
    {
        if (!Schema::hasTable('brand_claims')) {
            return null;
        }

        return (int) DB::table('brand_claims')
            ->where('seller_id', $sellerId)
            ->whereIn('status', ['submitted', 'under_review', 'more_information_required'])
            ->count();
    }

    private function failingWebhooks(int $sellerId): ?int
    {
        if (!Schema::hasTable('seller_webhooks')) {
            return null;
        }

        return (int) DB::table('seller_webhooks')
            ->where('seller_id', $sellerId)
            ->where('consecutive_failures', '>', 0)
            ->count();
    }

}
