<?php

namespace App\Services\Marketplace;

use App\Models\Seller;
use App\Services\Platform\Policy;
use App\Models\SellerApiKey;
use App\Models\SellerAutomationAction;
use App\Models\SellerAutomationRule;
use App\Models\SellerBulkJob;
use App\Models\SellerInsight;
use App\Models\SellerStaff;
use App\Models\SellerWebhook;
use App\Models\SellerWebhookDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the sellers are doing with the platform, from the marketplace's side.
 *
 * Everything the Seller Center gained — rules that change catalogues unattended, keys that act as a
 * shop without a person, endpoints the platform calls, staff who are not the account holder — is
 * something an operator has to be able to see across every seller at once. Not to run the shops for
 * them, but because these are the things that go wrong at three in the morning and the questions
 * are always "how many sellers is this happening to" and "which one".
 *
 * Every figure here is a count of real rows. Where a table does not exist yet the section reports
 * that it is not installed rather than reporting zero, because zero suspended rules and no rules
 * table at all are very different facts about a platform.
 */
class SellerOperationsOverview
{
    /** How many rows a section shows before it becomes a list page instead. */
    public const PREVIEW_ROWS = 10;

    /**
     * The headline counts, one per capability.
     *
     * @return array<string, array{installed: bool, total?: int, attention?: int, label: string}>
     */
    public function summary(): array
    {
        return [
            'automation' => $this->count('seller_automation_rules', fn () => [
                'total' => SellerAutomationRule::count(),
                'attention' => SellerAutomationRule::where('status', SellerAutomationRule::STATUS_SUSPENDED)->count(),
            ], 'automation_rules'),

            'issues' => $this->count('seller_insights', fn () => [
                'total' => SellerInsight::whereIn('status', SellerInsight::LIVE_STATUSES)->count(),
                'attention' => SellerInsight::whereIn('status', SellerInsight::LIVE_STATUSES)
                    ->where('severity', SellerInsight::SEVERITY_CRITICAL)->count(),
            ], 'open_seller_issues'),

            'keys' => $this->count('seller_api_keys', fn () => [
                'total' => SellerApiKey::whereNull('revoked_at')->count(),
                // A key nobody has ever used is the one an operator asks about.
                'attention' => SellerApiKey::whereNull('revoked_at')->whereNull('last_used_at')->count(),
            ], 'live_api_keys'),

            'webhooks' => $this->count('seller_webhooks', fn () => [
                'total' => SellerWebhook::count(),
                'attention' => SellerWebhook::where('status', SellerWebhook::STATUS_DISABLED)->count(),
            ], 'webhook_endpoints'),

            'staff' => $this->count('seller_staff', fn () => [
                'total' => SellerStaff::count(),
                'attention' => SellerStaff::where('status', SellerStaff::STATUS_ACTIVE)
                    ->whereNull('seller_role_id')->count(),
            ], 'seller_staff'),

            'bulk_jobs' => $this->count('seller_bulk_jobs', fn () => [
                'total' => SellerBulkJob::count(),
                'attention' => SellerBulkJob::whereIn('status', SellerBulkJob::OPEN_STATUSES)->count(),
            ], 'bulk_operations'),
        ];
    }

    /**
     * The concrete things that need somebody, as a list rather than a colour.
     *
     * A red-tinted card saying "3" tells an operator that something is wrong and
     * not what. Each entry here names the thing, counts it, and links to the page
     * that can act on it — which is the only form of "needs attention" worth
     * putting at the top of a screen.
     *
     * Empty when nothing does, and the screen then shows nothing rather than a
     * row of reassuring zeroes.
     *
     * @return array<int, array{label: string, count: int, href: string|null, tone: string}>
     */
    public function attentionItems(array $summary): array
    {
        $items = [];

        $map = [
            'issues' => ['label' => 'seller_issues_marked_critical', 'tone' => 'danger', 'route' => 'issues'],
            'automation' => ['label' => 'automation_rules_stopped_by_the_platform', 'tone' => 'danger', 'route' => 'automation'],
            'webhooks' => ['label' => 'endpoints_switched_off_after_repeated_failure', 'tone' => 'danger', 'route' => 'integrations'],
            'bulk_jobs' => ['label' => 'bulk_operations_still_running', 'tone' => 'info', 'route' => 'bulk-jobs'],
            'keys' => ['label' => 'api_keys_never_used', 'tone' => 'warning', 'route' => 'integrations'],
            'staff' => ['label' => 'staff_with_no_role', 'tone' => 'warning', 'route' => 'team'],
        ];

        foreach ($map as $key => $meta) {
            $count = ($summary[$key]['installed'] ?? false) ? ($summary[$key]['attention'] ?? 0) : 0;

            if ($count > 0) {
                $items[] = [
                    'label' => $meta['label'],
                    'count' => $count,
                    'tone' => $meta['tone'],
                    'href' => $meta['route'] ? route('admin.marketplace.seller-operations.' . $meta['route']) : null,
                ];
            }
        }

        return $items;
    }

    /**
     * Rules across every seller, worst state first.
     *
     * Suspended before failing before running: an operator opening this page is looking for the
     * shops where automation has stopped working, not browsing a catalogue of rules.
     */
    public function rules(?int $sellerId = null, ?string $status = null, int $perPage = 25)
    {
        if (!Schema::hasTable('seller_automation_rules')) {
            return null;
        }

        return SellerAutomationRule::query()
            ->when($sellerId, fn ($query) => $query->where('seller_id', $sellerId))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderByRaw("CASE status WHEN 'suspended' THEN 0 WHEN 'active' THEN 1 ELSE 2 END")
            ->orderByDesc('consecutive_failures')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** What automation has actually changed, across every shop. */
    public function automationActivity(?int $sellerId = null, int $perPage = 25)
    {
        if (!Schema::hasTable('seller_automation_actions')) {
            return null;
        }

        return SellerAutomationAction::query()
            ->when($sellerId, fn ($query) => $query->where('seller_id', $sellerId))
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Live issues across the platform, by seller.
     *
     * Ranked by measured impact rather than by count, because a shop with one critical finding needs
     * an operator before a shop with forty low ones.
     */
    public function issuesBySeller(int $limit = 20): array
    {
        if (!Schema::hasTable('seller_insights')) {
            return [];
        }

        return DB::table('seller_insights')
            ->whereIn('status', SellerInsight::LIVE_STATUSES)
            ->groupBy('seller_id')
            ->selectRaw(
                'seller_id, COUNT(*) as total, MAX(impact_score) as worst_score, ' .
                "SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical",
            )
            ->orderByDesc('critical')
            ->orderByDesc('worst_score')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * Which shops are operationally broken right now, by shop id.
     *
     * Sits beside the storefront analytics deliberately: a shop whose traffic is falling and whose
     * automation has been suspended for a week is one story, not two, and an operator reading the
     * vendor breakdown has no way to know the second half unless it is on the same screen.
     *
     * Only shops with something wrong appear. A shop with nothing wrong is not a row of zeroes.
     *
     * @return array<int, array{issues: int, critical: int, suspended_rules: int, failing_webhooks: int}>
     */
    public function attentionBySeller(): array
    {
        $state = [];

        foreach ($this->issuesBySeller(limit: app(Policy::class)->int('limit_admin_seller_rollup')) as $row) {
            $state[(int) $row->seller_id] = [
                'issues' => (int) $row->total,
                'critical' => (int) $row->critical,
                'suspended_rules' => 0,
                'failing_webhooks' => 0,
            ];
        }

        $blank = ['issues' => 0, 'critical' => 0, 'suspended_rules' => 0, 'failing_webhooks' => 0];

        if (Schema::hasTable('seller_automation_rules')) {
            $suspended = SellerAutomationRule::where('status', SellerAutomationRule::STATUS_SUSPENDED)
                ->groupBy('seller_id')
                ->selectRaw('seller_id, COUNT(*) as total')
                ->pluck('total', 'seller_id');

            foreach ($suspended as $sellerId => $total) {
                $state[(int) $sellerId] = ($state[(int) $sellerId] ?? $blank);
                $state[(int) $sellerId]['suspended_rules'] = (int) $total;
            }
        }

        if (Schema::hasTable('seller_webhooks')) {
            $failing = SellerWebhook::where(function ($query) {
                $query->where('status', SellerWebhook::STATUS_DISABLED)
                    ->orWhere('consecutive_failures', '>', 0);
            })
                ->groupBy('seller_id')
                ->selectRaw('seller_id, COUNT(*) as total')
                ->pluck('total', 'seller_id');

            foreach ($failing as $sellerId => $total) {
                $state[(int) $sellerId] = ($state[(int) $sellerId] ?? $blank);
                $state[(int) $sellerId]['failing_webhooks'] = (int) $total;
            }
        }

        return $state;
    }

    /**
     * The issues themselves, worst first.
     *
     * The overview ranks shops; this is the layer under it. An operator who sees
     * that one shop has a critical finding needs to be able to read what it is —
     * without that, the count is a dead end and they have to ask the seller.
     *
     * Live issues only by default. A resolved issue is history, and a queue that
     * mixes the two stops being a queue.
     */
    public function issues(?int $sellerId = null, ?string $severity = null, ?string $category = null, int $perPage = 25)
    {
        if (!Schema::hasTable('seller_insights')) {
            return null;
        }

        return SellerInsight::query()
            ->whereIn('status', SellerInsight::LIVE_STATUSES)
            ->when($sellerId, fn ($query) => $query->where('seller_id', $sellerId))
            ->when($severity, fn ($query) => $query->where('severity', $severity))
            ->when($category, fn ($query) => $query->where('category', $category))
            ->worstFirst()
            ->orderByDesc('impact_score')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Which categories actually have live issues right now, with their counts.
     *
     * Read from the rows rather than from the list of categories the code knows
     * about: a filter offering eight categories of which six can never match is a
     * filter that wastes the operator's time.
     *
     * @return array<string, int>
     */
    public function issueCategories(): array
    {
        if (!Schema::hasTable('seller_insights')) {
            return [];
        }

        return SellerInsight::query()
            ->whereIn('status', SellerInsight::LIVE_STATUSES)
            ->whereNotNull('category')
            ->groupBy('category')
            ->selectRaw('category, COUNT(*) as total')
            ->pluck('total', 'category')
            ->all();
    }

    public function keys(?int $sellerId = null, int $perPage = 25)
    {
        if (!Schema::hasTable('seller_api_keys')) {
            return null;
        }

        return SellerApiKey::query()
            ->when($sellerId, fn ($query) => $query->where('seller_id', $sellerId))
            ->orderByRaw('revoked_at IS NULL DESC')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function webhooks(?int $sellerId = null, int $perPage = 25)
    {
        if (!Schema::hasTable('seller_webhooks')) {
            return null;
        }

        return SellerWebhook::query()
            ->when($sellerId, fn ($query) => $query->where('seller_id', $sellerId))
            ->orderByRaw("CASE status WHEN 'disabled' THEN 0 ELSE 1 END")
            ->orderByDesc('consecutive_failures')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Delivery health across the platform for the last day.
     *
     * @return array{installed: bool, delivered?: int, failed?: int, pending?: int}
     */
    public function deliveryHealth(): array
    {
        if (!Schema::hasTable('seller_webhook_deliveries')) {
            return ['installed' => false];
        }

        $since = now()->subDay();

        return [
            'installed' => true,
            'delivered' => SellerWebhookDelivery::where('created_at', '>=', $since)
                ->where('status', SellerWebhookDelivery::STATUS_DELIVERED)->count(),
            'failed' => SellerWebhookDelivery::where('created_at', '>=', $since)
                ->where('status', SellerWebhookDelivery::STATUS_FAILED)->count(),
            'pending' => SellerWebhookDelivery::where('created_at', '>=', $since)
                ->where('status', SellerWebhookDelivery::STATUS_PENDING)->count(),
        ];
    }

    public function staff(?int $sellerId = null, int $perPage = 25)
    {
        if (!Schema::hasTable('seller_staff')) {
            return null;
        }

        return SellerStaff::with('role')
            ->when($sellerId, fn ($query) => $query->where('seller_id', $sellerId))
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function bulkJobs(?int $sellerId = null, int $perPage = 25)
    {
        if (!Schema::hasTable('seller_bulk_jobs')) {
            return null;
        }

        return SellerBulkJob::query()
            ->when($sellerId, fn ($query) => $query->where('seller_id', $sellerId))
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Shop names for a set of ids, in one query.
     *
     * Every list on these pages is "rows plus which shop", and looking the shop up per row is how a
     * twenty-five row page becomes twenty-six queries.
     *
     * @param  iterable<int|string>  $sellerIds
     */
    public function sellersFor(iterable $sellerIds)
    {
        $ids = collect($sellerIds)->filter()->unique()->values();

        if ($ids->isEmpty() || !Schema::hasTable('sellers')) {
            return collect();
        }

        return Seller::whereIn('id', $ids)->get(['id', 'f_name', 'l_name', 'status'])->keyBy('id');
    }

    /**
     * @param  callable(): array{total: int, attention: int}  $counts
     * @return array{installed: bool, total?: int, attention?: int, label: string}
     */
    private function count(string $table, callable $counts, string $label): array
    {
        // Not installed and zero are different facts about a platform, and an operator reading a
        // dashboard of zeroes has no way to tell which they are looking at.
        if (!Schema::hasTable($table)) {
            return ['installed' => false, 'label' => $label];
        }

        return ['installed' => true, 'label' => $label] + $counts();
    }
}
