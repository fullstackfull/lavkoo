@extends('layouts.seller.app')

@section('title', translate('nav_issue_center'))

@php
    use App\Services\SellerCenter\IssueAction;
    use App\Services\SellerCenter\Status;

    $columns = [
        ['key' => 'severity', 'label' => translate('severity'), 'width' => 110],
        ['key' => 'issue', 'label' => translate('issue')],
        ['key' => 'category', 'label' => translate('category'), 'width' => 110, 'priority' => 'md'],
        ['key' => 'affected', 'label' => translate('affected'), 'width' => 100, 'num' => true, 'sortable' => true],
        ['key' => 'impact', 'label' => translate('impact'), 'width' => 80, 'num' => true, 'sortable' => true, 'priority' => 'lg'],
        ['key' => 'detected', 'label' => translate('detected'), 'width' => 110, 'sortable' => true, 'priority' => 'lg'],
        ['key' => 'due', 'label' => translate('due'), 'width' => 110, 'sortable' => true],
        ['key' => 'status', 'label' => translate('status'), 'width' => 110],
        ['key' => 'action', 'label' => '', 'width' => 130],
    ];

    $sortUrls = collect($columns)->filter(fn ($c) => $c['sortable'] ?? false)
        ->mapWithKeys(fn ($c) => [$c['key'] => $filters->urlSort($c['key'])])->all();
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_operations')" :title="translate('nav_issue_center')" />

    <x-sc.tabs :tabs="$views" :current="$currentView" />

    <div class="sc-scroll">
        <x-sc.toolbar :count="$issues ? \App\Services\SellerCenter\Copy::line('n_issues', ['count' => $issues->total()]) : null"
                      :search-url="route('seller.issues.index')"
                      :search-value="request('q', '')"
                      :search-placeholder="translate('search_issues')"
                      :chips="$filters->chips()"
                      :clear-url="$filters->urlClearAll()"
                      :filters="$filters->available()" />

        <x-sc.table :columns="$columns" :state="$state" :sort="request('sort')" :dir="request('dir', 'asc')"
                    :sort-urls="$sortUrls">
            <x-slot:empty>
                <x-sc.empty glyph="check-circle" tone="good" :title="$emptyCopy['title']" :text="$emptyCopy['text']" />
            </x-slot:empty>
            <x-slot:noResults>
                <x-sc.empty glyph="funnel" :title="translate('no_issues_match_these_filters')"
                            :text="translate('adjust_or_clear_the_filters_to_see_more')" />
            </x-slot:noResults>

            @foreach ($issues ?? [] as $issue)
                @php($action = IssueAction::resolve($issue->action_key, $issue->action_params))
                @php($overdue = $issue->isOverdue())
                <x-sc.tr :href="route('seller.issues.show', $issue->id)" :id="$issue->id">
                    <x-sc.td><x-sc.badge :severity="$issue->severity" /></x-sc.td>
                    <x-sc.td :sub="$issue->type">{{ translate($issue->title) }}</x-sc.td>
                    <x-sc.td drop="md" class="sc-muted">{{ translate($issue->category) }}</x-sc.td>
                    <x-sc.td num>{{ number_format($issue->affected_count) }}</x-sc.td>
                    <x-sc.td num drop="lg" class="sc-muted">{{ $issue->impact_score }}</x-sc.td>
                    <x-sc.td drop="lg" class="sc-muted sc-ts">{{ \App\Services\SellerCenter\Moment::stamp($issue->first_detected_at) }}</x-sc.td>
                    {{-- Overdue takes the critical colour whatever the severity, prefixed "Breached". --}}
                    <x-sc.td :tone="$overdue ? 'critical' : null">
                        @if ($issue->due_at === null)
                            <span class="sc-muted">—</span>
                        @elseif ($overdue)
                            <span class="sc-num">{{ translate('breached') }}</span>
                        @else
                            <span class="sc-num">{{ $issue->due_at->diffForHumans() }}</span>
                        @endif
                    </x-sc.td>
                    <x-sc.td><x-sc.badge :status="$issue->status" /></x-sc.td>
                    <x-sc.td action>
                        @if ($action['href'])
                            <a class="sc-btn sc-btn--ghost sc-btn--sm" href="{{ $action['href'] }}" data-sc-stop>{{ $action['label'] }}</a>
                        @endif
                    </x-sc.td>
                </x-sc.tr>
            @endforeach

            <x-slot:mobile>
                @foreach ($issues ?? [] as $issue)
                    <x-sc.entity-card :title="translate($issue->title)" :href="route('seller.issues.show', $issue->id)"
                                      :meta="$issue->type . ' · ' . \App\Services\SellerCenter\Copy::line('n_affected', ['count' => $issue->affected_count])">
                        <div class="sc-row"><x-sc.badge :severity="$issue->severity" /><x-sc.badge :status="$issue->status" /></div>
                    </x-sc.entity-card>
                @endforeach
            </x-slot:mobile>

            <x-slot:footer>@if ($issues)<x-sc.pager :paginator="$issues" />@endif</x-slot:footer>
        </x-sc.table>
    </div>
@endsection
