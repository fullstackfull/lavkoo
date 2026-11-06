<?php

namespace App\Http\Controllers\Admin\SalesChannels;

use App\Http\Controllers\Admin\SalesChannels\Support\ReadsFilters;
use App\Http\Controllers\Controller;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelApiLog;
use App\Models\SalesChannelEvent;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/**
 * What the module has said to the channels and what the channels have said to it. Both are stored already stripped of secrets and personal data; the page still prints them
 * as text, never as markup.
 */
class ChannelLogController extends Controller
{
    use ReadsFilters;

    public const TAB_API = 'api';
    public const TAB_EVENTS = 'events';

    public const SOURCES = ['webhook', 'notification', 'poll', 'system', 'manual'];

    public const OUTCOMES = ['received', 'recorded_only', 'needs_review', 'applied', 'ignored_capability_off', 'ignored_invalid', 'failed'];

    private const PER_PAGE = 50;

    public function __construct(private readonly ChannelLabels $labels)
    {
    }

    public function index(Request $request): View
    {
        $tab = requestString('tab') === self::TAB_EVENTS ? self::TAB_EVENTS : self::TAB_API;

        return view('admin-views.sales-channels.logs', [
            'labels' => $this->labels,
            'tab' => $tab,
            'accounts' => SalesChannelAccount::orderBy('name')->pluck('name', 'id'),
            'filters' => $this->filters($request),
            'logs' => $tab === self::TAB_API ? $this->apiCalls($request) : null,
            'events' => $tab === self::TAB_EVENTS ? $this->events($request) : null,
            'sources' => self::SOURCES,
            'outcomes' => self::OUTCOMES,
        ]);
    }

    private function apiCalls(Request $request): LengthAwarePaginator
    {
        $filters = $this->filters($request);

        return SalesChannelApiLog::query()
            ->when($filters['account'], static fn ($query, int $account) => $query->where('account_id', $account))
            ->when($filters['operation'], static fn ($query, string $operation) => $query->where('operation', 'like', $operation . '%'))
            ->when($filters['result'], static fn ($query, string $result) => $query->where('success', $result === 'ok'))
            ->when($filters['dry_run'], static fn ($query, string $dryRun) => $query->where('dry_run', $dryRun === 'yes'))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    private function events(Request $request): LengthAwarePaginator
    {
        $filters = $this->filters($request);

        return SalesChannelEvent::query()
            ->when($filters['account'], static fn ($query, int $account) => $query->where('account_id', $account))
            ->when($filters['source'], static fn ($query, string $source) => $query->where('source', $source))
            ->when($filters['outcome'], static fn ($query, string $outcome) => $query->where('outcome', $outcome))
            ->when($filters['event_type'], static fn ($query, string $type) => $query->where('event_type', 'like', $type . '%'))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * @return array{account: int|null, operation: string|null, result: string|null, dry_run: string|null, source: string|null, outcome: string|null, event_type: string|null}
     */
    private function filters(Request $request): array
    {
        return [
            'account' => $request->integer('account') ?: null,
            'operation' => trim(requestString('operation')) ?: null,
            'result' => $this->oneOf('result', ['ok', 'failed']),
            'dry_run' => $this->oneOf('dry_run', ['yes', 'no']),
            'source' => $this->oneOf('source', self::SOURCES),
            'outcome' => $this->oneOf('outcome', self::OUTCOMES),
            'event_type' => trim(requestString('event_type')) ?: null,
        ];
    }
}
