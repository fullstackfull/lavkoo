@extends('layouts.admin.app')

@section('title', translate('open_seller_issues'))

@php
    $shopName = fn ($id) => isset($sellers[$id])
        ? (trim(($sellers[$id]->f_name ?? '') . ' ' . ($sellers[$id]->l_name ?? '')) ?: ('#' . $id))
        : ('#' . $id);

    $severityTone = ['critical' => 'danger', 'high' => 'danger', 'medium' => 'warning', 'low' => 'neutral'];

    /** Keeps the other filters when one of them is changed. */
    $withFilter = fn (array $overrides) => route(
        'admin.marketplace.seller-operations.issues',
        array_filter(array_merge(request()->only(['seller_id', 'severity', 'category']), $overrides), fn ($v) => $v !== null && $v !== ''),
    );
@endphp

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('open_seller_issues')"
                         :subtitle="translate('what_detection_has_found_across_every_shop')" />

        @include('admin-views.marketplace.seller-operations._nav')
        @include('admin-views.marketplace.seller-operations._seller-filter')

        {{-- Only the categories that actually have something in them. A filter offering
             eight categories of which six can never match wastes the reader's time. --}}
        @if (! empty($categories))
            <nav class="k-row mb-3" style="flex-wrap:wrap">
                <a class="k-btn k-btn--sm {{ request('category') ? 'k-btn--ghost' : 'k-btn--secondary' }}"
                   href="{{ $withFilter(['category' => null]) }}">{{ translate('all') }}</a>
                @foreach ($categories as $category => $total)
                    <a class="k-btn k-btn--sm {{ request('category') === $category ? 'k-btn--secondary' : 'k-btn--ghost' }}"
                       href="{{ $withFilter(['category' => $category]) }}">
                        {{ translate($category) }}
                        <span class="k-tab__count k-num">{{ $total }}</span>
                    </a>
                @endforeach
            </nav>
        @endif

        <x-k.card :padded="false" :title="$issues && $issues->total() ? ($issues->total() . ' ' . translate('open')) : translate('open')">
            <x-slot:actions>
                <span class="k-text-subtle">{{ translate('an_issue_closes_when_the_condition_stops_being_true') }}</span>
            </x-slot:actions>

            @if ($issues === null)
                <x-k.empty icon="info" :title="translate('not_installed')" />
            @elseif ($issues->isEmpty())
                <x-k.empty icon="check" :title="translate('no_open_issues_on_any_shop')"
                           :text="translate('detection_runs_hourly_and_writes_only_what_it_finds')" />
            @else
                <div class="k-table-wrap">
                    <table class="k-table">
                        <thead>
                            <tr>
                                <th>{{ translate('seller') }}</th>
                                <th>{{ translate('issue') }}</th>
                                <th>{{ translate('severity') }}</th>
                                <th class="k-table__num">{{ translate('score') }}</th>
                                <th class="k-table__num">{{ translate('affected') }}</th>
                                <th>{{ translate('open_for') }}</th>
                                <th>{{ translate('due') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach ($issues as $issue)
                            <tr>
                                <td>{{ $shopName($issue->seller_id) }}</td>
                                <td>
                                    {{ translate($issue->title) }}
                                    @if ($issue->body)
                                        <div class="k-text-subtle k-truncate" style="max-width:340px;font-size:var(--k-text-sm)">
                                            {{ $issue->body }}
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <x-k.badge :tone="$severityTone[$issue->severity] ?? 'neutral'">
                                        {{ translate($issue->severity) }}
                                    </x-k.badge>
                                </td>
                                {{-- Out of a hundred, and measured against this seller's own
                                     business rather than an absolute figure. --}}
                                <td class="k-table__num k-num">{{ $issue->impact_score }} / 100</td>
                                <td class="k-table__num k-num">{{ $issue->affected_count }}</td>
                                <td class="k-text-muted k-num">
                                    @php($hours = $issue->openForHours())
                                    {{ $hours === null ? '—' : round($hours) . ' ' . translate('hours') }}
                                </td>
                                <td class="k-text-muted">
                                    @if ($issue->due_at === null)
                                        —
                                    @elseif ($issue->isOverdue())
                                        {{-- Overdue is the one thing on this row that changes what
                                             an operator does next, so it is the one thing coloured. --}}
                                        <x-k.badge tone="danger">{{ translate('overdue') }}</x-k.badge>
                                    @else
                                        {{ $issue->due_at }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="k-pager">{{ $issues->links() }}</div>
            @endif
        </x-k.card>
    </div>
@endsection
