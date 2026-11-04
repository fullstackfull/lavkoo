<?php

namespace App\Services\SellerCenter\Lists;

use App\Models\SellerInsight;
use App\Services\SellerCenter\Status;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;

/**
 * The issue backlog behind `/seller/issues`.
 *
 * The Control Tower is this list's today-view; this is the whole backlog with triage. Both read the
 * same rows, so an issue closed here disappears there without a second write path.
 *
 * The default sort is deliberate and not negotiable per screen: severity descending, then deadline
 * ascending. A backlog sorted by anything else buries the thing that is about to cost money.
 */
class IssueList
{
    public const VIEWS = [
        'critical' => ['label' => 'critical', 'tone' => 'critical'],
        'high' => ['label' => 'high', 'tone' => 'high'],
        'needs_attention' => ['label' => 'needs_attention', 'tone' => 'high'],
        'monitoring' => ['label' => 'monitoring', 'tone' => 'medium'],
        'all' => ['label' => 'all', 'tone' => 'neutral'],
        'resolved' => ['label' => 'resolved', 'tone' => 'good'],
    ];

    public function paginate(int $sellerId, Request $request): ?LengthAwarePaginator
    {
        if (!Schema::hasTable('seller_insights')) {
            return null;
        }

        $query = SellerInsight::forSeller($sellerId);
        $view = $this->view($request);

        match ($view) {
            'critical' => $query->open()->where('severity', SellerInsight::SEVERITY_CRITICAL),
            'high' => $query->open()->where('severity', SellerInsight::SEVERITY_HIGH),
            'needs_attention' => $query->open()->whereIn('severity', [
                SellerInsight::SEVERITY_CRITICAL, SellerInsight::SEVERITY_HIGH, SellerInsight::SEVERITY_MEDIUM,
            ]),
            // The design's "Monitoring" is this model's waiting/acknowledged backlog: seen, parked,
            // still true. It is not a separate stored status.
            'monitoring' => $query->whereIn('status', [SellerInsight::STATUS_WAITING, SellerInsight::STATUS_ACKNOWLEDGED]),
            'resolved' => $query->whereIn('status', SellerInsight::CLOSED_STATUSES),
            default => $query->open(),
        };

        foreach (['severity' => 'severity', 'category' => 'category'] as $key => $column) {
            $value = $request->query($key);
            if ($value !== null && $value !== '') {
                $query->whereIn($column, is_array($value) ? $value : [$value]);
            }
        }

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $query->where(function ($where) use ($search) {
                $where->where('title', 'like', '%' . $search . '%')
                    ->orWhere('type', 'like', '%' . $search . '%')
                    ->orWhere('body', 'like', '%' . $search . '%');
            });
        }

        $this->sort($query, $request);

        return $query->paginate($this->pageSize($request))->withQueryString();
    }

    /**
     * Tab counts. A count that cannot be computed renders as no badge rather than a zero, so a
     * failed count never reads as "nothing to do here" (handoff 05 B4).
     *
     * @return array<int, array<string, mixed>>
     */
    public function views(int $sellerId, Request $request, string $baseUrl): array
    {
        $current = $this->view($request);

        return collect(self::VIEWS)->map(function (array $view, string $key) use ($sellerId, $baseUrl, $current) {
            return [
                'key' => $key,
                'label' => translate($view['label']),
                'href' => $key === 'all' ? $baseUrl : $baseUrl . '?' . http_build_query(['tab' => $key]),
                'count' => $this->countFor($sellerId, $key),
                'tone' => $view['tone'],
                'current' => $key === $current,
            ];
        })->values()->all();
    }

    /** The per-tab empty copy. Each tab means something different, so each says something different. */
    public function emptyCopy(string $view): array
    {
        return match ($view) {
            'critical' => [
                'title' => translate('no_critical_issues'),
                'text' => translate('everything_requiring_immediate_attention_is_currently_under_control'),
            ],
            'resolved' => [
                'title' => translate('nothing_resolved_in_this_period'),
                'text' => translate('resolved_issues_stay_here_with_what_closed_them'),
            ],
            default => [
                'title' => translate('nothing_needs_attention'),
                'text' => translate('detection_runs_continuously_and_writes_only_what_it_finds'),
            ],
        };
    }

    public function filterFields(): array
    {
        return [
            'severity' => ['label' => 'severity', 'type' => 'enum', 'group' => 'issue', 'options' => array_map(
                static fn (string $severity) => [
                    'value' => $severity,
                    'label' => translate($severity),
                    'tone' => Status::severity($severity)['tone'],
                ],
                Status::SEVERITY_ORDER,
            )],
            'category' => ['label' => 'category', 'type' => 'enum', 'group' => 'issue', 'options' => array_map(
                static fn (string $category) => ['value' => $category, 'label' => translate($category)],
                SellerInsight::CATEGORIES,
            )],
        ];
    }

    public function view(Request $request): string
    {
        $view = (string) $request->query('tab', 'all');

        return array_key_exists($view, self::VIEWS) ? $view : 'all';
    }

    private function countFor(int $sellerId, string $view): ?int
    {
        if (!Schema::hasTable('seller_insights')) {
            return null;
        }

        $query = SellerInsight::forSeller($sellerId);

        match ($view) {
            'critical' => $query->open()->where('severity', SellerInsight::SEVERITY_CRITICAL),
            'high' => $query->open()->where('severity', SellerInsight::SEVERITY_HIGH),
            'needs_attention' => $query->open()->whereIn('severity', [
                SellerInsight::SEVERITY_CRITICAL, SellerInsight::SEVERITY_HIGH, SellerInsight::SEVERITY_MEDIUM,
            ]),
            'monitoring' => $query->whereIn('status', [SellerInsight::STATUS_WAITING, SellerInsight::STATUS_ACKNOWLEDGED]),
            'resolved' => $query->whereIn('status', SellerInsight::CLOSED_STATUSES),
            default => $query->open(),
        };

        return (int) $query->count();
    }

    private function sort($query, Request $request): void
    {
        $direction = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        match ((string) $request->query('sort', '')) {
            'detected' => $query->orderBy('first_detected_at', $direction),
            'due' => $query->orderBy('due_at', $direction),
            'impact' => $query->orderBy('impact_score', $direction),
            'affected' => $query->orderBy('affected_count', $direction),
            // Severity first, then the nearest deadline. A backlog sorted any other way
            // buries the thing that is about to cost money.
            default => $query->orderBySeverity()->orderByRaw('due_at IS NULL, due_at ASC'),
        };
    }

    private function pageSize(Request $request): int
    {
        $size = (int) $request->query('size', 25);

        return in_array($size, [25, 50, 100], true) ? $size : 25;
    }
}
