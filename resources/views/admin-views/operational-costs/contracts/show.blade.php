@extends('layouts.admin.app')

@use('App\Enums\OperationalCosts\ContractStatus')
@use('App\Enums\OperationalCosts\CostComponent')
@use('App\Http\Controllers\Admin\OperationalCosts\CostLabels')
@use('App\Http\Controllers\Admin\OperationalCosts\CostTones')

@section('title', $contract->party_name . ' · v' . $contract->version)

@section('content')
    @php
        $isDraft = $contract->status === ContractStatus::Draft;
        $canStartNewVersion = $contract->status === ContractStatus::Active && $isLatest;
        $expected = (array) ($contract->settings['expected_components'] ?? []);
    @endphp
    <div class="content container-fluid">
        <x-k.page-header :title="$contract->party_name . ' · v' . $contract->version" :subtitle="$contract->provider_code . ' · ' . CostLabels::of('party_type', $contract->party_type)">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.operational-costs.contracts.index') }}">{{ translate('back') }}</a>
                @if ($isDraft)
                    <a class="btn btn-outline-primary btn-sm" href="{{ route('admin.operational-costs.contracts.edit', $contract->id) }}">{{ translate('edit_contract_draft') }}</a>
                    <a class="btn btn-primary btn-sm" href="{{ route('admin.operational-costs.contracts.rules.create', $contract->id) }}">{{ translate('add_a_price_rule') }}</a>
                @endif
            </x-slot:actions>
        </x-k.page-header>

        @include('admin-views.operational-costs.partials._module-state')

        @if (!$isDraft)
            <div class="alert alert-info fs-12" role="note">{{ translate('this_contract_is_not_a_draft_its_rules_never_change_note') }}</div>
        @endif

        <div class="row g-3 mb-3">
            <div class="col-lg-7">
                <x-k.card :title="translate('contract')">
                    <dl class="row mb-0 fs-12">
                        <dt class="col-sm-4">{{ translate('status') }}</dt>
                        <dd class="col-sm-8"><x-k.badge :tone="CostTones::contract($contract->status)">{{ translate($contract->status->labelKey()) }}</x-k.badge></dd>
                        <dt class="col-sm-4">{{ translate('kind_of_company') }}</dt>
                        <dd class="col-sm-8">{{ CostLabels::of('party_type', $contract->party_type) }}</dd>
                        <dt class="col-sm-4">{{ translate('company_code') }}</dt>
                        <dd class="col-sm-8" dir="ltr">{{ $contract->provider_code }}</dd>
                        <dt class="col-sm-4">{{ translate('contract_reference') }}</dt>
                        <dd class="col-sm-8">{{ $contract->reference ?? '—' }}</dd>
                        <dt class="col-sm-4">{{ translate('currency') }}</dt>
                        <dd class="col-sm-8">{{ $contract->currency }}</dd>
                        <dt class="col-sm-4">{{ translate('validity') }}</dt>
                        <dd class="col-sm-8 k-num">{{ $contract->effective_from->toDateString() }} → {{ $contract->effective_to?->toDateString() ?? translate('open_ended') }}</dd>
                        <dt class="col-sm-4">{{ translate('books_of') }}</dt>
                        <dd class="col-sm-8">@include('admin-views.operational-costs.partials._seller', ['seller' => $contract->seller_id])</dd>
                        <dt class="col-sm-4">{{ translate('expected_services') }}</dt>
                        <dd class="col-sm-8">
                            @forelse ($expected as $costComponent)
                                <x-k.badge tone="neutral" :dot="false">{{ CostComponent::tryFrom($costComponent) ? translate(CostComponent::from($costComponent)->labelKey()) : $costComponent }}</x-k.badge>
                            @empty
                                <span class="text-muted">—</span>
                            @endforelse
                            <div class="text-muted">{{ translate('expected_services_explained') }}</div>
                        </dd>
                        <dt class="col-sm-4">{{ translate('reconciliation_tolerance_percent') }}</dt>
                        <dd class="col-sm-8 k-num">{{ isset($contract->settings['tolerance_percent']) ? $contract->settings['tolerance_percent'] . '%' : translate('system_default') . ' (' . config('operational_costs.reconciliation.tolerance_percent') . '%)' }}</dd>
                        @if ($contract->notes)
                            <dt class="col-sm-4">{{ translate('notes') }}</dt>
                            <dd class="col-sm-8">{{ $contract->notes }}</dd>
                        @endif
                    </dl>
                </x-k.card>
            </div>
            <div class="col-lg-5">
                <x-k.card :title="translate('what_can_be_done_with_this_contract')">
                    @if ($isDraft)
                        <form method="POST" action="{{ route('admin.operational-costs.contracts.activate', $contract->id) }}" class="mb-3">@csrf
                            <label class="form-label fs-12" for="activate-reason">{{ translate('reason_for_activating') }}</label>
                            <input class="form-control form-control-sm mb-1" id="activate-reason" name="reason" required maxlength="500" value="{{ old('reason') }}">
                            <div class="fs-12 text-muted mb-1">{{ translate('activating_freezes_the_contract_help') }}</div>
                            <button class="btn btn-primary btn-sm w-100" type="submit">{{ translate('activate_contract') }}</button>
                        </form>
                        <form method="POST" action="{{ route('admin.operational-costs.contracts.archive', $contract->id) }}">@csrf
                            <label class="form-label fs-12" for="archive-reason">{{ translate('reason_for_archiving') }}</label>
                            <input class="form-control form-control-sm mb-1" id="archive-reason" name="reason" required maxlength="500">
                            <button class="btn btn-outline-danger btn-sm w-100" type="submit">{{ translate('archive_draft') }}</button>
                        </form>
                    @elseif ($canStartNewVersion)
                        <form method="POST" action="{{ route('admin.operational-costs.contracts.new-version', $contract->id) }}">@csrf
                            <p class="fs-12 text-muted">{{ translate('a_new_version_takes_over_from_its_start_date_help') }}</p>
                            <label class="form-label fs-12" for="new-version-from">{{ translate('new_version_starts_on') }}</label>
                            <input class="form-control form-control-sm mb-1" type="date" id="new-version-from" name="effective_from" required value="{{ old('effective_from') }}">
                            <label class="form-label fs-12" for="new-version-reason">{{ translate('reason_for_the_new_version') }}</label>
                            <input class="form-control form-control-sm mb-1" id="new-version-reason" name="reason" required maxlength="500">
                            <button class="btn btn-primary btn-sm w-100" type="submit">{{ translate('start_a_new_version') }}</button>
                        </form>
                    @else
                        <p class="fs-12 text-muted mb-0">{{ translate('nothing_to_change_here_see_the_latest_version') }}</p>
                    @endif
                </x-k.card>
            </div>
        </div>

        <x-k.card :title="translate('price_rules')" :padded="false" class="mb-3">
            <div class="k-table-wrap table-responsive">
                <table class="k-table">
                    <thead><tr>
                        <th>{{ translate('service') }}</th><th>{{ translate('calculation_method') }}</th><th>{{ translate('figures') }}</th><th>{{ translate('conditions') }}</th>
                        <th class="k-table__num">{{ translate('priority') }}</th><th>{{ translate('validity') }}</th>@if ($isDraft)<th></th>@endif
                    </tr></thead>
                    <tbody>
                    @forelse ($rules as $rule)
                        <tr>
                            <td>
                                {{ $rule->component() ? translate($rule->component()->labelKey()) : $rule->service_type }}
                                @if ($rule->label)<div class="fs-10 text-muted">{{ $rule->label }}</div>@endif
                                @unless ($rule->is_active)<x-k.badge tone="neutral" :dot="false">{{ translate('inactive') }}</x-k.badge>@endunless
                            </td>
                            <td>{{ $rule->method() ? translate($rule->method()->labelKey()) : $rule->calc_method }}</td>
                            <td>@include('admin-views.operational-costs.partials._rule-parameters', ['rule' => $rule, 'currency' => $contract->currency])</td>
                            <td>@include('admin-views.operational-costs.partials._rule-conditions', ['rule' => $rule])</td>
                            <td class="k-table__num k-num">{{ $rule->priority }}</td>
                            <td class="fs-12 k-num">
                                @if ($rule->valid_from || $rule->valid_to){{ $rule->valid_from?->toDateString() ?? '…' }} → {{ $rule->valid_to?->toDateString() ?? '…' }}@else<span class="text-muted">{{ translate('whole_contract_period') }}</span>@endif
                            </td>
                            @if ($isDraft)
                                <td>
                                    <div class="d-flex gap-1">
                                        <a class="btn btn-outline-primary btn-sm" href="{{ route('admin.operational-costs.contracts.rules.edit', [$contract->id, $rule->id]) }}">{{ translate('edit') }}</a>
                                        <form method="POST" action="{{ route('admin.operational-costs.contracts.rules.destroy', [$contract->id, $rule->id]) }}" onsubmit="return confirm(@js(translate('are_you_sure')))">@csrf
                                            <button class="btn btn-outline-danger btn-sm" type="submit">{{ translate('remove') }}</button></form>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $isDraft ? 7 : 6 }}">
                            @if ($isDraft)
                                <x-k.empty icon="catalog" :title="translate('this_contract_prices_nothing_yet')" :text="translate('this_contract_prices_nothing_yet_help')">
                                    <x-slot:action><a class="btn btn-primary btn-sm" href="{{ route('admin.operational-costs.contracts.rules.create', $contract->id) }}">{{ translate('add_a_price_rule') }}</a></x-slot:action>
                                </x-k.empty>
                            @else
                                <x-k.empty icon="catalog" :title="translate('this_contract_prices_nothing_yet')" :text="translate('this_contract_prices_nothing_yet_help')" />
                            @endif
                        </td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-k.card>

        <div class="row g-3">
            <div class="col-lg-6">
                <x-k.card :title="translate('versions_of_this_contract')" :padded="false">
                    <div class="k-table-wrap table-responsive">
                        <table class="k-table">
                            <thead><tr><th>{{ translate('version') }}</th><th>{{ translate('status') }}</th><th>{{ translate('validity') }}</th></tr></thead>
                            <tbody>
                            @foreach ($versions as $version)
                                <tr @if ($version->id === $contract->id) aria-current="true" @endif>
                                    <td><a href="{{ route('admin.operational-costs.contracts.show', $version->id) }}">v{{ $version->version }}</a></td>
                                    <td><x-k.badge :tone="CostTones::contract($version->status)">{{ translate($version->status->labelKey()) }}</x-k.badge></td>
                                    <td class="fs-12 k-num">{{ $version->effective_from->toDateString() }} → {{ $version->effective_to?->toDateString() ?? translate('open_ended') }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <x-slot:footer>
                        <form method="GET" action="{{ route('admin.operational-costs.contracts.show', $contract->id) }}" class="row g-2 align-items-end">
                            <div class="col-sm-6">
                                <label class="form-label fs-12" for="in-force-on">{{ translate('which_version_was_in_force_on') }}</label>
                                <input class="form-control form-control-sm" type="date" id="in-force-on" name="on" value="{{ $checkedOn?->toDateString() }}" required>
                            </div>
                            <div class="col-sm-3"><button class="btn btn-outline-primary btn-sm" type="submit">{{ translate('check') }}</button></div>
                        </form>
                        @if ($checkedOn)
                            <p class="fs-12 mt-2 mb-0" role="status">
                                @if ($inForce)
                                    {{ $checkedOn->toDateString() }}: <a href="{{ route('admin.operational-costs.contracts.show', $inForce->id) }}">v{{ $inForce->version }}</a>
                                    ({{ $inForce->effective_from->toDateString() }} → {{ $inForce->effective_to?->toDateString() ?? translate('open_ended') }})
                                @else
                                    {{ $checkedOn->toDateString() }}: {{ translate('no_version_of_this_contract_was_in_force_on_that_day') }}
                                @endif
                            </p>
                        @endif
                    </x-slot:footer>
                </x-k.card>
            </div>
            <div class="col-lg-6">
                <x-k.card :title="translate('revision_history')" :padded="false">
                    <div class="k-table-wrap table-responsive">
                        <table class="k-table">
                            <thead><tr><th>{{ translate('when') }}</th><th>{{ translate('what_happened') }}</th><th>{{ translate('by') }}</th><th>{{ translate('reason') }}</th></tr></thead>
                            <tbody>
                            @foreach ($revisions as $revision)
                                <tr>
                                    <td class="fs-12 k-num">{{ $revision->created_at?->format('Y-m-d H:i') }}</td>
                                    <td>{{ CostLabels::of('contract_revision', $revision->action) }}</td>
                                    <td class="fs-12">{{ $revision->actor_type === 'admin' ? ($actors[$revision->actor_id] ?? '#' . $revision->actor_id) : translate('system') }}</td>
                                    <td class="fs-12">{{ $revision->reason ?? '—' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-k.card>
            </div>
        </div>
    </div>
@endsection
