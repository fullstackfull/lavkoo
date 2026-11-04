@extends('layouts.seller.app')

@section('title', translate('nav_automation_rules'))

@php
    use App\Services\SellerCenter\Copy;

    $columns = [
        ['key' => 'rule', 'label' => translate('rule')],
        ['key' => 'trigger', 'label' => translate('trigger'), 'width' => 150, 'priority' => 'md'],
        ['key' => 'action', 'label' => translate('action'), 'width' => 150, 'priority' => 'md'],
        ['key' => 'scope', 'label' => translate('scope'), 'width' => 180, 'priority' => 'lg'],
        ['key' => 'status', 'label' => translate('status'), 'width' => 140],
        ['key' => 'last_run', 'label' => translate('last_run'), 'width' => 120, 'priority' => 'lg'],
        ['key' => 'runs', 'label' => translate('runs'), 'width' => 70, 'num' => true, 'priority' => 'lg'],
        ['key' => 'applied', 'label' => translate('applied'), 'width' => 80, 'num' => true, 'priority' => 'lg'],
        ['key' => 'rate', 'label' => translate('success_rate'), 'width' => 100, 'num' => true, 'priority' => 'xl'],
        ['key' => 'row_action', 'label' => '', 'width' => 150],
    ];
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_operations')" :title="translate('nav_automation_rules')"
                      :sub="translate('rules_you_write_the_marketplace_runs_every_change_is_recorded_and_most_can_be_undone')">
        <x-slot:actions>
            @if ($historyUrl = \App\Services\SellerCenter\Shell::route('seller.automation.history'))
                <x-sc.button variant="secondary" icon="clock-counter-clockwise" :href="$historyUrl">{{ translate('nav_automation_history') }}</x-sc.button>
            @endif
            @if ($createUrl = \App\Services\SellerCenter\Shell::route('seller.automation.create'))
                <x-sc.button variant="primary" icon="plus" :href="$createUrl">{{ translate('create_automation') }}</x-sc.button>
            @endif
        </x-slot:actions>
    </x-sc.page-header>

    <div class="sc-scroll">
        <x-sc.table :columns="$columns" :state="$state">
            <x-slot:empty>
                <x-sc.empty glyph="robot" :title="translate('no_automations')"
                            :text="translate('create_rules_to_handle_repetitive_operational_tasks_automatically')">
                    <x-slot:actions>
                        @if ($createUrl = \App\Services\SellerCenter\Shell::route('seller.automation.create'))
                            <x-sc.button variant="primary" :href="$createUrl">{{ translate('create_automation') }}</x-sc.button>
                        @endif
                    </x-slot:actions>
                </x-sc.empty>
            </x-slot:empty>

            @foreach ($presented as $rule)
                <x-sc.tr :href="\App\Services\SellerCenter\Shell::route('seller.automation.edit', $rule['id'])" :id="$rule['id']">
                    <x-sc.td>
                        <div>{{ $rule['name'] }}</div>
                        <div class="sc-muted" style="font-size:11.5px">{{ $rule['sentence'] }}</div>
                    </x-sc.td>
                    <x-sc.td drop="md" class="sc-muted">{{ $rule['trigger_label'] }}</x-sc.td>
                    <x-sc.td drop="md" class="sc-muted">{{ $rule['action_label'] }}</x-sc.td>
                    {{-- No scope means the whole shop, which is a fact about the rule and is said as
                         one rather than left blank. --}}
                    <x-sc.td drop="lg" class="sc-muted">{{ $rule['scope_label'] ?? translate('whole_catalogue') }}</x-sc.td>
                    <x-sc.td>
                        <x-sc.badge :status="$rule['status']" />
                        @if ($rule['stopped_by_marketplace'])
                            <div class="sc-muted" style="font-size:11px">{{ translate('stopped_by_the_marketplace') }}</div>
                        @elseif ($rule['suspension_reason'])
                            <div class="sc-muted" style="font-size:11px">{{ translate($rule['suspension_reason']) }}</div>
                        @elseif ($rule['ran_without_acting'])
                            <div class="sc-muted" style="font-size:11px">{{ Copy::choice('ran_once_no_matches_yet', 'ran_n_times_no_matches_yet', $rule['run_count']) }}</div>
                        @endif
                    </x-sc.td>
                    <x-sc.td drop="lg" class="sc-muted">
                        {{ $rule['last_run_at'] ? $rule['last_run_at']->diffForHumans() : '—' }}
                    </x-sc.td>
                    <x-sc.td num drop="lg" class="sc-muted">{{ number_format($rule['run_count']) }}</x-sc.td>
                    <x-sc.td num drop="lg" class="sc-muted">{{ number_format($rule['applied_count']) }}</x-sc.td>
                    {{-- A rule that has never run has no rate. `—`, never `0%`. --}}
                    <x-sc.td num drop="xl" class="sc-muted">{{ $rule['success_rate'] === null ? '—' : $rule['success_rate'] . '%' }}</x-sc.td>
                    <x-sc.td action>
                        <div class="sc-row" style="gap:4px">
                            @if ($previewUrl = \App\Services\SellerCenter\Shell::route('seller.automation.preview', $rule['id']))
                                <a class="sc-btn sc-btn--ghost sc-btn--sm" href="{{ $previewUrl }}">{{ translate('run_preview') }}</a>
                            @endif
                            @include('seller-views.automation._status-control', ['rule' => $rule])
                        </div>
                    </x-sc.td>
                </x-sc.tr>
            @endforeach

            <x-slot:mobile>
                @foreach ($presented as $rule)
                    <x-sc.entity-card :title="$rule['name']"
                                      :href="\App\Services\SellerCenter\Shell::route('seller.automation.edit', $rule['id'])"
                                      :meta="$rule['scope_label'] ?? translate('whole_catalogue')">
                        <div class="sc-dim" style="font-size:12px">{{ $rule['sentence'] }}</div>
                        <div class="sc-row" style="margin-top:6px"><x-sc.badge :status="$rule['status']" /></div>
                    </x-sc.entity-card>
                @endforeach
            </x-slot:mobile>

            <x-slot:footer><x-sc.pager :paginator="$rules" /></x-slot:footer>
        </x-sc.table>
    </div>
@endsection
