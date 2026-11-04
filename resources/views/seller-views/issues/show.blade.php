@extends('layouts.seller.app')

@section('title', translate($issue->title))

@php
    use App\Services\SellerCenter\IssueAction;
    use App\Services\SellerCenter\Status;

    $severity = Status::severity($issue->severity);
    $action = IssueAction::resolve($issue->action_key, $issue->action_params);
    $overdue = $issue->isOverdue();
    $metadata = $issue->metadata ?? [];
    $escalations = $metadata['escalations'] ?? [];
@endphp

@section('content')
    <x-sc.page-header :title="translate($issue->title)"
                      :back="route('seller.issues.index')"
                      :crumbs="[
                          ['label' => translate('nav_operations')],
                          ['label' => translate('nav_issue_center'), 'href' => route('seller.issues.index')],
                          ['label' => 'ISS-' . $issue->id],
                      ]">
        <x-slot:actions>
            @if ($action['href'])
                <x-sc.button variant="primary" :href="$action['href']">{{ $action['label'] }}</x-sc.button>
            @endif
        </x-slot:actions>
    </x-sc.page-header>

    <div class="sc-scroll">
        <div class="sc-page sc-grid-detail">
            <div class="sc-stack">
                <div class="sc-row">
                    <x-sc.badge :severity="$issue->severity" />
                    <span class="sc-muted sc-code" style="font-size:11px">{{ $issue->type }}</span>
                    <span class="sc-muted sc-code" style="font-size:11px">ISS-{{ $issue->id }}</span>
                    <x-sc.badge :status="$issue->status" />
                    @if (($issue->escalation_level ?? 0) > 0)
                        <span class="sc-muted" style="font-size:11px">{{ translate('raised_from') }} {{ translate('high') }}</span>
                    @endif
                </div>

                {{-- Three stats: affected, due, impact. --}}
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px">
                    <div style="background:var(--color-surface);border-radius:var(--radius-md);padding:9px 11px">
                        <div class="sc-muted" style="font-size:11px">{{ translate('affected') }}</div>
                        <div class="sc-num" style="font-size:20px;font-family:var(--font-heading)">{{ number_format($issue->affected_count) }}</div>
                    </div>
                    <div style="background:var(--color-surface);border-radius:var(--radius-md);padding:9px 11px">
                        <div class="sc-muted" style="font-size:11px">{{ translate('due') }}</div>
                        <div class="sc-num" style="font-size:20px;font-family:var(--font-heading){{ $overdue ? ';color:var(--st-critical)' : '' }}">
                            {{ $issue->due_at === null ? '—' : ($overdue ? translate('breached') : $issue->due_at->diffForHumans()) }}
                        </div>
                    </div>
                    <div style="background:var(--color-surface);border-radius:var(--radius-md);padding:9px 11px">
                        <div class="sc-muted" style="font-size:11px">{{ translate('impact_score') }}</div>
                        <div class="sc-num" style="font-size:20px;font-family:var(--font-heading)">{{ $issue->impact_score }} / 100</div>
                    </div>
                </div>

                <x-sc.card :title="translate('what_happened')">
                    <p class="sc-dim" style="font-size:12.5px;margin:0">{{ $issue->body ?: translate($issue->title) }}</p>
                    @if (!empty($metadata))
                        <div class="sc-info-grid" style="margin-top:10px">
                            @foreach ($metadata as $key => $value)
                                @continue(in_array($key, ['escalations', 'signals'], true) || is_array($value))
                                <x-sc.info :label="translate($key)" :value="is_bool($value) ? ($value ? translate('yes') : translate('no')) : (string) $value" />
                            @endforeach
                        </div>
                    @endif
                </x-sc.card>

                <x-sc.card :title="translate('why_it_matters')">
                    <p class="sc-dim" style="font-size:12.5px;margin:0">
                        {{ \App\Services\SellerCenter\Copy::line('this_issue_affects_n', [
                            'count' => number_format($issue->affected_count),
                            'entity' => translate($issue->entity_type ? $issue->entity_type . 's' : 'items'),
                        ]) }}
                        @if ($issue->impact)
                            {{ translate('estimated_exposure') }} <span class="sc-money">{{ number_format((float) $issue->impact) }}</span>.
                        @endif
                        {{ translate('impact_is_scored_against_this_shops_own_business_not_an_absolute_figure') }}
                    </p>
                </x-sc.card>

                @if (!empty($issue->action_params))
                    <x-sc.card :title="translate('affected_items')" flush>
                        <div style="padding:10px 16px">
                            @foreach ($issue->action_params as $key => $value)
                                @if (is_array($value))
                                    <div class="sc-row" style="gap:6px;margin-bottom:6px">
                                        <span class="sc-muted" style="font-size:11px">{{ translate($key) }}</span>
                                        @foreach (array_slice($value, 0, 20) as $item)
                                            <span class="sc-code" style="color:var(--color-accent)">{{ $item }}</span>
                                        @endforeach
                                        @if (count($value) > 20)
                                            <span class="sc-muted" style="font-size:11px">+{{ count($value) - 20 }}</span>
                                        @endif
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </x-sc.card>
                @endif

                <x-sc.card :title="translate('timeline')">
                    <x-sc.timeline>
                        <x-sc.timeline-item tone="info" :time="\App\Services\SellerCenter\Moment::time($issue->first_detected_at)"
                                            :meta="\App\Services\SellerCenter\Moment::day($issue->first_detected_at)">
                            {{ translate('detected_by') }} {{ $issue->type }}
                        </x-sc.timeline-item>

                        @foreach ($escalations as $escalation)
                            <x-sc.timeline-item tone="critical" :time="isset($escalation['at']) ? \App\Services\SellerCenter\Moment::time(\Illuminate\Support\Carbon::parse($escalation['at'])) : null">
                                {{ translate('severity_raised_from') }} {{ translate($escalation['from'] ?? '') }}
                                {{ translate('to') }} {{ translate($escalation['to'] ?? '') }} —
                                {{ translate($escalation['reason'] ?? '') }}
                            </x-sc.timeline-item>
                        @endforeach

                        @if ($issue->resolved_at)
                            <x-sc.timeline-item tone="good" :time="\App\Services\SellerCenter\Moment::time($issue->resolved_at)">
                                {{ $issue->resolution_type === 'auto' ? translate('resolved_automatically') : translate('resolved') }}
                            </x-sc.timeline-item>
                        @else
                            <x-sc.timeline-item tone="future">
                                {{ translate('closes_when_the_condition_stops_being_true') }}
                            </x-sc.timeline-item>
                        @endif
                    </x-sc.timeline>
                </x-sc.card>
            </div>

            <div class="sc-stack sc-context">
                <x-sc.card side :label="translate('details')">
                    <div class="sc-stack--tight">
                        <x-sc.info :label="translate('category')" :value="translate($issue->category)" />
                        <x-sc.info :label="translate('type')" :value="$issue->type" />
                        <x-sc.info :label="translate('open_for')"
                                   :value="\App\Services\SellerCenter\Copy::line('open_for_n_hours', ['count' => round($issue->openForHours())])" />
                        @if ($issue->assigned_staff_id)
                            <x-sc.info :label="translate('assigned')" :value="'#' . $issue->assigned_staff_id" />
                        @endif
                    </div>
                </x-sc.card>
            </div>
        </div>
    </div>
@endsection
