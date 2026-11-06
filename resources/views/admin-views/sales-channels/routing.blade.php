@extends('layouts.admin.app')

@section('title', translate('Dynamic_Routing'))

@section('content')
    @use('App\Enums\Fulfillment\ProviderCode')
    @php
        $formData = compact('accounts', 'locations', 'channels', 'modes', 'policies', 'actions', 'providers', 'managedDispatchEnabled', 'labels');
    @endphp
    <div class="content container-fluid">
        <x-k.page-header :title="translate('Dynamic_Routing')" :subtitle="translate('dynamic_routing_subtitle')">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.index') }}">{{ translate('Channels_Hub') }}</a>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.orders.index') }}">{{ translate('Unified_Orders') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        <x-k.card :title="translate('how_an_order_is_decided')" class="mb-3">
            <p class="fs-12">{{ translate('invariants_come_first') }}</p>
            <ol class="fs-12 ps-3">
                <li>{{ translate('invariant_channel_ships_it') }}</li>
                <li>{{ translate('invariant_unknown_unreleased_or_closed') }}</li>
                <li>{{ translate('invariant_dispatched_or_linked_is_never_decided_again') }}</li>
                <li>{{ translate('invariant_channel_arranged_carrier_is_only_followed') }}</li>
                <li>{{ translate('invariant_cod_and_unlinked_lines_wait_for_a_person') }}</li>
            </ol>
            <p class="fs-12">{{ translate('then_the_first_matching_rule_decides') }}</p>
            <div class="alert alert-warning fs-12 py-2 mb-2" role="note">{{ translate('with_no_rule_the_answer_is_manual_review') }}</div>
            @unless ($managedDispatchEnabled)
                <div class="alert alert-secondary fs-12 py-2 mb-0" role="note">{{ translate('managed_dispatch_not_available_explained') }}</div>
            @endunless
        </x-k.card>

        <x-k.card :title="translate('routing_rules')" :padded="false" class="mb-3">
            <div class="k-table-wrap table-responsive">
                <table class="k-table">
                    <thead><tr>
                        <th>{{ translate('priority') }}</th><th>{{ translate('rule') }}</th><th>{{ translate('when_the_order_matches') }}</th><th>{{ translate('then_the_order_is') }}</th>
                        <th>{{ translate('validity_window') }}</th><th>{{ translate('status') }}</th><th>{{ translate('actions') }}</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($rules as $entry)
                        @php($rule = $entry['rule'])
                        <tr id="rule-{{ $rule->id }}">
                            <td class="k-table__num">{{ $rule->priority }}</td>
                            <td>
                                <div class="fw-semibold">{{ $rule->name }}</div>
                                <div class="fs-10 text-muted">#{{ $rule->id }} · {{ translate('seller') }}: {{ $rule->seller_id === 0 ? translate('platform') : '#' . $rule->seller_id }}</div>
                                @if ($rule->note)<div class="fs-12 text-muted text-break">{{ $rule->note }}</div>@endif
                            </td>
                            <td>
                                @forelse ($entry['chips'] as $chip)
                                    <x-k.chip :key="$chip['key']" :removable="false">{{ $chip['value'] }}</x-k.chip>
                                @empty
                                    <x-k.badge :dot="false">{{ translate('every_order') }}</x-k.badge>
                                @endforelse
                            </td>
                            <td class="fs-12">
                                <x-k.badge tone="info" :dot="false">{{ $labels->routingAction($rule->action) }}</x-k.badge>
                                @if ($rule->target_provider)<div>{{ ProviderCode::tryFrom($rule->target_provider)?->label() ?? $rule->target_provider }}</div>@endif
                                @if ($rule->target_location_id)<div class="text-muted">{{ $locations->get($rule->target_location_id) ?? '#' . $rule->target_location_id }}</div>@endif
                            </td>
                            <td class="fs-12">
                                {{ $rule->valid_from?->format('Y-m-d') ?? '—' }} → {{ $rule->valid_to?->format('Y-m-d') ?? '—' }}
                                @if ($rule->valid_from?->isFuture())<div><x-k.badge tone="info" :dot="false">{{ translate('not_started_yet') }}</x-k.badge></div>@endif
                                @if ($rule->valid_to?->isPast())<div><x-k.badge tone="warning" :dot="false">{{ translate('expired') }}</x-k.badge></div>@endif
                            </td>
                            <td><x-k.badge :tone="$rule->is_active ? 'success' : 'neutral'">{{ $rule->is_active ? translate('active') : translate('inactive') }}</x-k.badge></td>
                            <td>
                                <div class="d-flex flex-wrap gap-1">
                                    <a class="btn btn-outline-primary btn-sm" href="{{ route('admin.sales-channels.routing.index', ['edit' => $rule->id]) }}#edit-rule">{{ translate('edit') }}</a>
                                    <form method="POST" action="{{ route('admin.sales-channels.routing.rules.toggle', $rule) }}">@csrf
                                        <button class="btn btn-outline-secondary btn-sm" type="submit">{{ $rule->is_active ? translate('switch_off') : translate('switch_on') }}</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7">
                            <x-k.empty icon="settings" :title="translate('no_routing_rules_yet')" :text="translate('no_routing_rules_yet_help')" />
                        </td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-k.card>

        @if ($editing)
            <x-k.card :title="translate('edit_rule') . ': ' . $editing->name" class="mb-3" id="edit-rule">
                <x-slot:actions><a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.routing.index') }}">{{ translate('close') }}</a></x-slot:actions>
                @include('admin-views.sales-channels.partials._rule-form', $formData + ['values' => $values, 'action' => route('admin.sales-channels.routing.rules.update', $editing), 'bag' => 'rule_edit', 'submit' => translate('save_rule')])
            </x-k.card>
        @endif

        <x-k.card :title="translate('new_rule')" class="mb-3" id="new-rule">
            <details @if ($errors->hasBag('rule_new')) open @endif>
                <summary class="fs-12">{{ translate('write_a_new_rule') }}</summary>
                <div class="mt-3">
                    @include('admin-views.sales-channels.partials._rule-form', $formData + ['values' => $newValues, 'action' => route('admin.sales-channels.routing.rules.store'), 'bag' => 'rule_new', 'submit' => translate('create_rule')])
                </div>
            </details>
        </x-k.card>

        <x-k.card :title="translate('latest_decisions')" :padded="false">
            <form method="GET" action="{{ route('admin.sales-channels.routing.index') }}" class="row g-2 align-items-end p-3">
                <div class="col-md-3">
                    <label class="form-label fs-12" for="decision-action">{{ translate('rule_action') }}</label>
                    <select class="form-select form-select-sm" id="decision-action" name="action">
                        <option value="">{{ translate('all') }}</option>
                        @foreach ($actions as $routingAction)<option value="{{ $routingAction->value }}" @selected($filters['action'] === $routingAction->value)>{{ translate($routingAction->labelKey()) }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fs-12" for="decision-channel">{{ translate('channel') }}</label>
                    <select class="form-select form-select-sm" id="decision-channel" name="channel">
                        <option value="">{{ translate('all') }}</option>
                        @foreach ($channels as $channel)<option value="{{ $channel->value }}" @selected($filters['channel'] === $channel->value)>{{ translate($channel->labelKey()) }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button class="btn btn-primary btn-sm" type="submit">{{ translate('filter') }}</button>
                    <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.routing.index') }}">{{ translate('reset') }}</a>
                </div>
            </form>
            <div class="k-table-wrap table-responsive">
                <table class="k-table">
                    <thead><tr>
                        <th>{{ translate('time') }}</th><th>{{ translate('order') }}</th><th>{{ translate('rule_action') }}</th><th>{{ translate('why') }}</th><th>{{ translate('rule') }}</th><th>{{ translate('decided_by') }}</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($decisions as $decision)
                        <tr>
                            <td class="fs-12">{{ $decision->created_at?->format('Y-m-d H:i') }}</td>
                            <td>
                                @if ($decision->external_order_mapping_id)
                                    <a href="{{ route('admin.sales-channels.orders.show', $decision->external_order_mapping_id) }}">#{{ $decision->external_order_mapping_id }}</a>
                                @else — @endif
                                <div class="fs-10 text-muted">{{ translate('version') }} {{ $decision->version }}@if ($decision->is_current) · {{ translate('current_decision') }}@endif</div>
                            </td>
                            <td><x-k.badge tone="info" :dot="false">{{ $labels->routingAction($decision->action) }}</x-k.badge>
                                @if ($decision->provider)<div class="fs-12">{{ ProviderCode::tryFrom($decision->provider)?->label() ?? $decision->provider }}</div>@endif</td>
                            <td class="fs-12">{{ $labels->orderReason($decision->reason_code) }}</td>
                            <td class="fs-12">{{ $decision->rule_snapshot['name'] ?? '—' }}</td>
                            <td class="fs-12">{{ $decision->decided_by === 'admin' ? translate('decided_by_an_administrator') : translate('decided_by_the_system') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">
                            <x-k.empty icon="clock" :title="translate('no_decisions_yet')" :text="translate('no_decisions_yet_help')" />
                        </td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if ($decisions->hasPages())
                <x-slot:footer>{{ $decisions->links() }}</x-slot:footer>
            @endif
        </x-k.card>
    </div>
@endsection

@push('script')
    @include('admin-views.sales-channels.partials._product-picker-script')
@endpush
