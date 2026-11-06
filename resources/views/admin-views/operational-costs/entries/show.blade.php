@extends('layouts.admin.app')

@use('App\Enums\OperationalCosts\CostState')
@use('App\Http\Controllers\Admin\OperationalCosts\CostFormat')
@use('App\Http\Controllers\Admin\OperationalCosts\CostLabels')
@use('App\Http\Controllers\Admin\OperationalCosts\EntryActions')
@use('App\Services\OperationalCosts\Support\Money')

@section('title', translate('cost_entry') . ' #' . $entry->id)

@section('content')
    @php
        $costComponent = $entry->component();
        $drill = $entry->external_order_mapping_id !== null ? ['channel_order', $entry->external_order_mapping_id] : ($entry->order_id !== null ? ['order', $entry->order_id] : null);
    @endphp
    <div class="content container-fluid">
        <x-k.page-header :title="translate('cost_entry') . ' #' . $entry->id" :subtitle="($costComponent ? translate($costComponent->labelKey()) : $entry->cost_component) . ' · ' . $entry->period_date->toDateString()">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.operational-costs.entries.index') }}">{{ translate('back') }}</a>
                @if ($drill)
                    <a class="btn btn-outline-primary btn-sm" href="{{ route('admin.operational-costs.profit.order', ['type' => $drill[0], 'id' => $drill[1]]) }}">{{ translate('profit_of_this_sale') }}</a>
                @endif
            </x-slot:actions>
        </x-k.page-header>

        @unless ($entry->is_effective)
            <div class="alert alert-secondary fs-12" role="note">{{ translate('this_entry_was_replaced_by') }}
                @if ($entry->superseded_by_id)<a href="{{ route('admin.operational-costs.entries.show', $entry->superseded_by_id) }}">#{{ $entry->superseded_by_id }}</a>@endif.
                {{ translate('replaced_entries_stay_in_the_history_and_count_for_nothing') }}</div>
        @endunless
        @if ($entry->approved_at || $entry->state === CostState::Reconciled)
            <div class="alert alert-info fs-12" role="note">{{ translate('an_approved_entry_is_never_edited_note') }}</div>
        @endif

        <div class="row g-3">
            <div class="col-lg-8">
                <x-k.card :title="translate('the_cost')" class="mb-3">
                    <dl class="row mb-0 fs-12">
                        <dt class="col-sm-4">{{ translate('state') }}</dt>
                        <dd class="col-sm-8">@include('admin-views.operational-costs.partials._cost-state', ['state' => $entry->state])</dd>
                        <dt class="col-sm-4">{{ translate('amount') }}</dt>
                        <dd class="col-sm-8">@include('admin-views.operational-costs.partials._entry-amount', ['entry' => $entry])</dd>
                        <dt class="col-sm-4">{{ translate('base_amount') }}</dt>
                        <dd class="col-sm-8 k-num">{{ CostFormat::amount($entry->base_amount, $entry->base_currency) }}@if ($entry->fx_rate) <span class="text-muted">· {{ translate('rate') }} {{ CostFormat::figure($entry->fx_rate) }}</span>@endif</dd>
                        <dt class="col-sm-4">{{ translate('component') }}</dt>
                        <dd class="col-sm-8">{{ $costComponent ? translate($costComponent->labelKey()) : $entry->cost_component }} <span class="text-muted">· {{ $costComponent ? translate($costComponent->costClass()->labelKey()) : $entry->cost_class }}</span></dd>
                        <dt class="col-sm-4">{{ translate('subject') }}</dt>
                        <dd class="col-sm-8">{{ CostLabels::of('entry_subject', $entry->subject_type) }} #{{ $entry->subject_id }}</dd>
                        <dt class="col-sm-4">{{ translate('sales_channel') }}</dt>
                        <dd class="col-sm-8">{{ CostLabels::channel($entry->sales_channel) }}@if ($account) · {{ $account }}@endif</dd>
                        <dt class="col-sm-4">{{ translate('provider') }}</dt>
                        <dd class="col-sm-8">{{ CostLabels::provider($entry->provider) }}</dd>
                        <dt class="col-sm-4">{{ translate('books_of') }}</dt>
                        <dd class="col-sm-8">@include('admin-views.operational-costs.partials._seller', ['seller' => $entry->seller_id])</dd>
                        <dt class="col-sm-4">{{ translate('source') }}</dt>
                        <dd class="col-sm-8">{{ CostLabels::of('entry_source', $entry->source) }}</dd>
                        <dt class="col-sm-4">{{ translate('contract') }}</dt>
                        <dd class="col-sm-8">
                            @if ($contract)
                                <a href="{{ route('admin.operational-costs.contracts.show', $contract->id) }}">{{ $contract->party_name }} v{{ $entry->contract_version }}</a>
                                @if ($entry->rule_id)<span class="text-muted">· {{ translate('rule') }} #{{ $entry->rule_id }}</span>@endif
                            @else
                                —
                            @endif
                        </dd>
                        @if ($invoiceId)
                            <dt class="col-sm-4">{{ translate('invoice') }}</dt>
                            <dd class="col-sm-8"><a href="{{ route('admin.operational-costs.invoices.show', $invoiceId) }}">#{{ $invoiceId }}</a></dd>
                        @endif
                        <dt class="col-sm-4">{{ translate('reference') }}</dt>
                        <dd class="col-sm-8 text-break">{{ $entry->external_reference ?? '—' }}</dd>
                        <dt class="col-sm-4">{{ translate('allocation') }}</dt>
                        <dd class="col-sm-8">{{ CostLabels::of('allocation_status', $entry->allocation_status) }}</dd>
                        @if ($entry->quantity !== null)
                            <dt class="col-sm-4">{{ translate('quantity') }}</dt>
                            <dd class="col-sm-8 k-num">{{ CostFormat::figure($entry->quantity) }}@if ($entry->unit_amount !== null) × {{ CostFormat::amount($entry->unit_amount, $entry->currency) }}@endif</dd>
                        @endif
                        @if ($entry->missing_reason)
                            <dt class="col-sm-4">{{ translate('missing_reason') }}</dt>
                            <dd class="col-sm-8">{{ CostLabels::of('missing_reason', $entry->missing_reason) }}</dd>
                        @endif
                        @if ($entry->note)
                            <dt class="col-sm-4">{{ translate('note') }}</dt>
                            <dd class="col-sm-8">{{ $entry->note }}</dd>
                        @endif
                        <dt class="col-sm-4">{{ translate('occurred') }}</dt>
                        <dd class="col-sm-8 k-num">{{ $entry->occurred_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                        <dt class="col-sm-4">{{ translate('recorded') }}</dt>
                        <dd class="col-sm-8 k-num">{{ $entry->created_at?->format('Y-m-d H:i') }}</dd>
                        <dt class="col-sm-4">{{ translate('approved') }}</dt>
                        <dd class="col-sm-8 k-num">{{ $entry->approved_at ? $entry->approved_at->format('Y-m-d H:i') . ' · ' . ($approver ?? '#' . $entry->approved_by) : '—' }}</dd>
                        <dt class="col-sm-4">{{ translate('identity') }}</dt>
                        <dd class="col-sm-8 text-break fs-10" dir="ltr">{{ $entry->dedupe_key }}</dd>
                    </dl>
                </x-k.card>

                <x-k.card :title="translate('how_it_was_calculated')" class="mb-3">
                    @if (!empty($entry->calc_inputs))
                        <dl class="row mb-0 fs-12">
                            @foreach ($entry->calc_inputs as $key => $value)
                                <dt class="col-sm-4 text-break">{{ $key }}</dt>
                                <dd class="col-sm-8 text-break" dir="ltr">{{ is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</dd>
                            @endforeach
                        </dl>
                    @else
                        <span class="fs-12 text-muted">{{ translate('no_calculation_inputs_were_recorded') }}</span>
                    @endif
                </x-k.card>

                <x-k.card :title="translate('allocations')" :padded="false" class="mb-3">
                    <div class="k-table-wrap table-responsive">
                        <table class="k-table">
                            <thead><tr><th>{{ translate('target') }}</th><th>{{ translate('product') }}</th><th>{{ translate('basis') }}</th><th class="k-table__num">{{ translate('share') }}</th><th class="k-table__num">{{ translate('amount') }}</th></tr></thead>
                            <tbody>
                            @forelse ($entry->allocations as $allocation)
                                <tr>
                                    <td>{{ CostLabels::of('entry_subject', $allocation->target_type) }} #{{ $allocation->target_id }}</td>
                                    <td>@if ($allocation->product_id)#{{ $allocation->product_id }}@else—@endif</td>
                                    <td class="fs-12">{{ $allocation->basis }} <span class="text-muted k-num">{{ CostFormat::figure($allocation->basis_value) }}</span></td>
                                    <td class="k-table__num k-num">{{ CostFormat::percent(Money::of($allocation->share)->times(100)) }}</td>
                                    <td class="k-table__num k-num">{{ CostFormat::amount($allocation->amount, $allocation->currency) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="fs-12 text-muted">{{ CostLabels::of('allocation_status', $entry->allocation_status) }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-k.card>

                <x-k.card :title="translate('history_of_this_cost')" :padded="false" class="mb-3">
                    <div class="k-table-wrap table-responsive">
                        <table class="k-table">
                            <thead><tr><th>#</th><th>{{ translate('state') }}</th><th class="k-table__num">{{ translate('amount') }}</th><th>{{ translate('source') }}</th><th>{{ translate('recorded') }}</th><th>{{ translate('counts') }}</th></tr></thead>
                            <tbody>
                            @foreach ($chain as $link)
                                <tr @class(['text-muted' => !$link->is_effective, 'fw-semibold' => $link->id === $entry->id])>
                                    <td>@if ($link->id === $entry->id)#{{ $link->id }}@else<a href="{{ route('admin.operational-costs.entries.show', $link->id) }}">#{{ $link->id }}</a>@endif</td>
                                    <td>@include('admin-views.operational-costs.partials._cost-state', ['state' => $link->state])</td>
                                    <td class="k-table__num">@include('admin-views.operational-costs.partials._entry-amount', ['entry' => $link])</td>
                                    <td class="fs-12">{{ CostLabels::of('entry_source', $link->source) }}</td>
                                    <td class="fs-12 k-num">{{ $link->created_at?->format('Y-m-d H:i') }}</td>
                                    <td class="fs-12">{{ $link->is_effective ? translate('yes') : translate('replaced') }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-k.card>

                @if ($related->isNotEmpty())
                    <x-k.card :title="translate('other_entries_for_the_same_subject_and_component')" :padded="false">
                        <div class="k-table-wrap table-responsive">
                            <table class="k-table">
                                <thead><tr><th>#</th><th>{{ translate('state') }}</th><th class="k-table__num">{{ translate('amount') }}</th><th>{{ translate('source') }}</th><th>{{ translate('note') }}</th></tr></thead>
                                <tbody>
                                @foreach ($related as $other)
                                    <tr @class(['text-muted' => !$other->is_effective])>
                                        <td><a href="{{ route('admin.operational-costs.entries.show', $other->id) }}">#{{ $other->id }}</a></td>
                                        <td>@include('admin-views.operational-costs.partials._cost-state', ['state' => $other->state])</td>
                                        <td class="k-table__num">@include('admin-views.operational-costs.partials._entry-amount', ['entry' => $other])</td>
                                        <td class="fs-12">{{ CostLabels::of('entry_source', $other->source) }}</td>
                                        <td class="fs-12">{{ $other->note ?? '—' }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </x-k.card>
                @endif
            </div>

            <div class="col-lg-4">
                <x-k.card :title="translate('what_can_be_done')">
                    @if ($actions === [])
                        <p class="fs-12 text-muted mb-0">{{ translate('no_action_is_available_for_this_entry') }}</p>
                    @endif

                    @if (in_array(EntryActions::APPROVE, $actions, true))
                        <form method="POST" action="{{ route('admin.operational-costs.entries.approve', $entry->id) }}" class="mb-3">@csrf
                            <label class="form-label fs-12" for="approve-note">{{ translate('note_optional') }}</label>
                            <input class="form-control form-control-sm mb-1" id="approve-note" name="note" maxlength="500">
                            <button class="btn btn-success btn-sm w-100" type="submit">{{ translate('approve_this_charge') }}</button>
                        </form>
                    @endif

                    @if (in_array(EntryActions::DISPUTE, $actions, true))
                        <form method="POST" action="{{ route('admin.operational-costs.entries.dispute', $entry->id) }}" class="mb-3">@csrf
                            <label class="form-label fs-12" for="dispute-reason">{{ translate('why_do_you_contest_this_charge') }}</label>
                            <input class="form-control form-control-sm mb-1" id="dispute-reason" name="reason" required maxlength="500">
                            <button class="btn btn-outline-warning btn-sm w-100" type="submit">{{ translate('dispute_this_charge') }}</button>
                        </form>
                    @endif

                    @if (in_array(EntryActions::RESOLVE, $actions, true))
                        <form method="POST" action="{{ route('admin.operational-costs.entries.resolve', $entry->id) }}" class="mb-3">@csrf
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="outcome" id="outcome-stands" value="stands" required>
                                <label class="form-check-label fs-12" for="outcome-stands">{{ translate('the_charge_stands') }}</label>
                            </div>
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="radio" name="outcome" id="outcome-does-not-stand" value="does_not_stand" required>
                                <label class="form-check-label fs-12" for="outcome-does-not-stand">{{ translate('the_charge_does_not_stand') }}</label>
                            </div>
                            <label class="form-label fs-12" for="resolve-reason">{{ translate('how_was_it_settled') }}</label>
                            <input class="form-control form-control-sm mb-1" id="resolve-reason" name="reason" required maxlength="500">
                            <button class="btn btn-primary btn-sm w-100" type="submit">{{ translate('resolve_the_dispute') }}</button>
                        </form>
                    @endif

                    @if (in_array(EntryActions::REVERSE, $actions, true))
                        <form method="POST" action="{{ route('admin.operational-costs.entries.reverse', $entry->id) }}">@csrf
                            <label class="form-label fs-12" for="reverse-reason">{{ translate('why_is_this_cost_being_reversed') }}</label>
                            <input class="form-control form-control-sm mb-1" id="reverse-reason" name="reason" required maxlength="500">
                            <div class="fs-12 text-muted mb-1">{{ translate('reversing_adds_an_opposite_entry_help') }}</div>
                            <button class="btn btn-outline-danger btn-sm w-100" type="submit">{{ translate('reverse_this_cost') }}</button>
                        </form>
                    @endif
                </x-k.card>
            </div>
        </div>
    </div>
@endsection
