@extends('layouts.seller.app')

@section('title', translate('nav_control_tower'))

@php
    use App\Services\SellerCenter\IssueAction;
    use App\Services\SellerCenter\Status;

    /* Section titles and their accent, in the server's own reading order. An empty section is
       never rendered as a heading with nothing under it (handoff README, server-authority rule 1). */
    $sectionMeta = [
        'critical_now' => ['label' => translate('critical_now'), 'tone' => 'critical'],
        'needs_action_today' => ['label' => translate('needs_action_today'), 'tone' => 'high'],
        'sla_risk' => ['label' => translate('sla_risk'), 'tone' => 'high'],
        'fulfillment_exceptions' => ['label' => translate('fulfilment_exceptions'), 'tone' => 'critical'],
        'returns_requiring_action' => ['label' => translate('returns_requiring_action'), 'tone' => 'medium'],
        'inventory_risk' => ['label' => translate('inventory_risk'), 'tone' => 'high'],
        'financial_exceptions' => ['label' => translate('financial_exceptions'), 'tone' => 'high'],
        'catalog_and_pricing' => ['label' => translate('catalog_and_pricing'), 'tone' => 'medium'],
        'recently_auto_resolved' => ['label' => translate('fixed_automatically_last_24h'), 'tone' => 'good'],
    ];

    $sections = collect($tower['sections'] ?? [])
        ->reject(fn ($section, $key) => in_array($key, $hiddenSections, true))
        ->filter(fn ($section) => ($section['count'] ?? 0) > 0);

    $problemSections = $sections->except('recently_auto_resolved');
    $autoResolved = $sections->get('recently_auto_resolved');

    /* Each queue row links to the list it counted, so the number and the destination agree. */
    $queueRows = $queue === null ? [] : [
        ['label' => translate('awaiting_shipment'), 'value' => $queue['awaiting_shipment'] ?? 0, 'tone' => 'neutral', 'href' => \App\Services\SellerCenter\Shell::route('seller.orders.index', ['view' => 'ship_today'])],
        ['label' => translate('sla_at_risk'), 'value' => $queue['sla_at_risk'] ?? 0, 'tone' => 'high', 'href' => \App\Services\SellerCenter\Shell::route('seller.orders.index', ['view' => 'sla_risk'])],
        ['label' => translate('returns_to_answer'), 'value' => $queue['returns_to_answer'] ?? 0, 'tone' => 'medium', 'href' => \App\Services\SellerCenter\Shell::route('seller.returns.index')],
        ['label' => translate('low_stock_lines'), 'value' => $queue['low_stock_products'] ?? 0, 'tone' => 'high', 'href' => \App\Services\SellerCenter\Shell::route('seller.inventory.index', ['view' => 'low_stock'])],
    ];
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_home')" :title="translate('operations_control_tower')"
                      :sub="\App\Services\SellerCenter\Moment::longDay($checkedAt) . ' · ' . \App\Services\SellerCenter\Moment::time($checkedAt) . ' · ' . translate('sections_in_server_reading_order_empty_ones_hidden')" />

    <div class="sc-scroll">
        <div class="sc-page sc-grid-tower">
            <div>
                @if ($failed)
                    {{-- Names what failed, and says what is unaffected: this page only aggregates
                         the other screens (handoff 07.2). --}}
                    <div style="background:var(--color-surface);border-inline-start:2px solid var(--st-critical);border-radius:var(--radius-md);padding:14px 16px">
                        <div style="font-size:13.5px;font-family:var(--font-heading)">{{ translate('control_tower_could_not_load') }}</div>
                        <p class="sc-dim" style="font-size:12.5px;margin:4px 0 10px">
                            {{ translate('the_operations_service_did_not_answer_order_and_inventory_screens_are_unaffected_this_page_only_aggregates_them') }}
                        </p>
                        <div class="sc-row">
                            <x-sc.button variant="primary" icon="arrow-clockwise" :href="url()->current()">{{ translate('retry') }}</x-sc.button>
                            @if ($issuesUrl = \App\Services\SellerCenter\Shell::route('seller.issues.index'))
                                <x-sc.button variant="secondary" :href="$issuesUrl">{{ translate('open_issue_center') }}</x-sc.button>
                            @endif
                        </div>
                    </div>
                @elseif ($problemSections->isEmpty() && ($autoResolved === null || ($autoResolved['count'] ?? 0) === 0))
                    {{-- All clear is a dedicated state, not a blank page. --}}
                    <x-sc.empty glyph="check-circle" tone="good" :title="translate('nothing_needs_attention')"
                                :text="\App\Services\SellerCenter\Copy::line('nothing_needs_attention_body', ['time' => \App\Services\SellerCenter\Moment::time($checkedAt)])">
                        <x-slot:actions>
                            @if ($ordersUrl = \App\Services\SellerCenter\Shell::route('seller.orders.index'))
                                <x-sc.button variant="primary" :href="$ordersUrl">{{ translate('open_order_queue') }}</x-sc.button>
                            @endif
                            @if ($historyUrl = \App\Services\SellerCenter\Shell::route('seller.automation.history'))
                                <x-sc.button variant="secondary" :href="$historyUrl">{{ translate('nav_automation_history') }}</x-sc.button>
                            @endif
                        </x-slot:actions>
                    </x-sc.empty>
                @else
                    @foreach ($problemSections as $key => $section)
                        @php($meta = $sectionMeta[$key] ?? ['label' => translate($key), 'tone' => 'neutral'])
                        <section class="sc-section">
                            <x-sc.section-header :title="$meta['label']" :tone="$meta['tone']"
                                                 :summary="\App\Services\SellerCenter\Copy::choice('one_issue_n_affected', 'n_issues_n_affected', $section['count'], ['affected' => $section['affected']])" />
                            <div class="sc-stack--tight">
                                @foreach ($section['issues'] as $issue)
                                    @php($action = IssueAction::resolve($issue['action_key'] ?? null, $issue['action_params'] ?? []))
                                    @php($due = $issue['due_at'] ?? null)
                                    @php($sla = Status::sla($due instanceof \DateTimeInterface ? $due : null))
                                    <x-sc.issue-card :severity="$issue['severity']"
                                                     :title="translate($issue['title'])"
                                                     :code="$issue['type']"
                                                     :affected="\App\Services\SellerCenter\Copy::line('n_affected', ['count' => $issue['affected_count']])"
                                                     :due="$due === null ? null : (($issue['is_overdue'] ?? false) ? translate('breached') : \Illuminate\Support\Carbon::parse($due)->diffForHumans())"
                                                     :due-tone="($issue['is_overdue'] ?? false) ? 'critical' : ($sla['tone'] === 'neutral' ? null : $sla['tone'])"
                                                     :detected="$issue['first_detected_at'] ? \App\Services\SellerCenter\Copy::line('detected_at', ['time' => \App\Services\SellerCenter\Moment::time(\Illuminate\Support\Carbon::parse($issue['first_detected_at']))]) : null"
                                                     :impact="$issue['impact_score'] ?: null">
                                        @if (($issue['escalation_level'] ?? 0) > 0)
                                            <x-slot:flag>
                                                <span class="sc-muted" style="font-size:11px">{{ translate('raised_from') }} {{ translate('high') }}</span>
                                            </x-slot:flag>
                                        @endif

                                        {{-- The body is the sentence with the number and the cause.
                                             Compared after translation, because a detector that has
                                             only its title restates it and the card would say the
                                             same thing twice. --}}
                                        @php($heading = translate($issue['title']))
                                        {{ $issue['body'] && $issue['body'] !== $heading && $issue['body'] !== $issue['title'] ? $issue['body'] : $heading }}

                                        <x-slot:actions>
                                            @if ($action['href'])
                                                <x-sc.button variant="primary" size="sm" :href="$action['href']">{{ $action['label'] }}</x-sc.button>
                                            @endif
                                            @if ($detailUrl = \App\Services\SellerCenter\Shell::route('seller.issues.show', $issue['id']))
                                                <x-sc.button variant="secondary" size="sm" :href="$detailUrl">{{ translate('details') }}</x-sc.button>
                                            @endif
                                        </x-slot:actions>
                                    </x-sc.issue-card>
                                @endforeach
                            </div>
                        </section>
                    @endforeach

                    @if ($autoResolved && ($autoResolved['count'] ?? 0) > 0)
                        <section class="sc-section">
                            <x-sc.section-header :title="$sectionMeta['recently_auto_resolved']['label']" tone="good"
                                                 :summary="\App\Services\SellerCenter\Copy::choice('one_resolved', 'n_resolved', $autoResolved['count'])" />
                            <div class="sc-stack--tight">
                                @foreach ($autoResolved['issues'] as $issue)
                                    <x-sc.auto-row :time="$issue['resolved_at'] ? \App\Services\SellerCenter\Moment::time(\Illuminate\Support\Carbon::parse($issue['resolved_at'])) : null">
                                        {{ translate($issue['title']) }}
                                        @if ($issue['resolution_type'])
                                            <span class="sc-muted">· {{ translate($issue['resolution_type']) }}</span>
                                        @endif
                                        <x-slot:action>
                                            @if ($trailUrl = \App\Services\SellerCenter\Shell::route('seller.issues.show', $issue['id']))
                                                <a class="sc-btn sc-btn--ghost sc-btn--sm" href="{{ $trailUrl }}">{{ translate('view_trail') }}</a>
                                            @endif
                                        </x-slot:action>
                                    </x-sc.auto-row>
                                @endforeach
                            </div>
                        </section>
                    @endif
                @endif

                @if ($hiddenSections !== [])
                    <p class="sc-muted" style="font-size:11.5px">
                        {{ \App\Services\SellerCenter\Copy::line('n_sections_hidden_by_your_role', ['count' => count($hiddenSections)]) }}
                    </p>
                @endif
            </div>

            {{-- Right column: system health and today's queue --}}
            <div class="sc-stack sc-context">
                <x-sc.card side :label="translate('system_health')">
                    @if ($failed)
                        <span class="sc-muted" style="font-size:12px">—</span>
                    @else
                        @foreach ($tower['health'] ?? [] as $domain => $state)
                            <x-sc.health :label="translate($domain)" :state="$state['state']" :count="$state['open']" />
                        @endforeach
                    @endif
                </x-sc.card>

                <x-sc.card side :label="translate('todays_queue')">
                    @if ($queue === null)
                        <span class="sc-muted" style="font-size:12px">—</span>
                    @else
                        <div class="sc-stack--tight">
                            @foreach ($queueRows as $row)
                                <a class="sc-row" href="{{ $row['href'] ?? '#' }}" style="color:inherit">
                                    <span style="flex:1 1 auto;font-size:12px">{{ $row['label'] }}</span>
                                    <span class="sc-num" @if ($row['value'] > 0 && $row['tone'] !== 'neutral') style="color:var(--st-{{ $row['tone'] }})" @endif>
                                        {{ $row['value'] }}
                                    </span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </x-sc.card>
            </div>
        </div>
    </div>
@endsection
