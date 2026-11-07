@extends('layouts.admin.app')

@use('App\Enums\OperationalCosts\CalcMethod')
@use('App\Enums\OperationalCosts\CostClass')
@use('App\Http\Controllers\Admin\OperationalCosts\CostLabels')

@section('title', $rule ? translate('edit_price_rule') : translate('add_a_price_rule'))

@section('content')
    @php
        $action = $rule
            ? route('admin.operational-costs.contracts.rules.update', [$contract->id, $rule->id])
            : route('admin.operational-costs.contracts.rules.store', $contract->id);
        $value = static fn (string $key) => old($key, $values[$key]);
        $selectedMethod = $value('calc_method');
        $selectedConditions = old('conditions', $values['conditions']);
        $methodGroup = static fn (array $methods): string => implode(' ', array_map(static fn (CalcMethod $method): string => $method->value, $methods));
    @endphp
    <div class="content container-fluid">
        <x-k.page-header :title="$rule ? translate('edit_price_rule') : translate('add_a_price_rule')" :subtitle="$contract->party_name . ' · v' . $contract->version . ' · ' . $contract->currency">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.operational-costs.contracts.show', $contract->id) }}">{{ translate('back_to_the_contract') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        @include('admin-views.operational-costs.partials._module-state')

        <div class="alert alert-info fs-12" role="note">{{ CostLabels::filled(translate('no_figure_is_filled_in_for_you_note'), ['currency' => $contract->currency]) }}</div>

        @if ($errors->any())
            <div class="alert alert-danger fs-12" role="alert"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <form method="POST" action="{{ $action }}" autocomplete="off" data-rule-form>
            @csrf
            <x-k.card :title="translate('what_is_priced_and_how')" class="mb-3">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="service_type">{{ translate('service') }}</label>
                        <select class="form-select" id="service_type" name="service_type" required>
                            <option value="">{{ translate('choose') }}</option>
                            @foreach (CostClass::cases() as $class)
                                @php $inClass = array_filter($components, static fn ($costComponent): bool => $costComponent->costClass() === $class); @endphp
                                @if ($inClass !== [])
                                    <optgroup label="{{ translate($class->labelKey()) }}">
                                        @foreach ($inClass as $costComponent)
                                            <option value="{{ $costComponent->value }}" @selected($value('service_type') === $costComponent->value)>{{ translate($costComponent->labelKey()) }}</option>
                                        @endforeach
                                    </optgroup>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="calc_method">{{ translate('calculation_method') }}</label>
                        <select class="form-select" id="calc_method" name="calc_method" required data-method-select>
                            <option value="">{{ translate('choose') }}</option>
                            @foreach ($methods as $method)
                                <option value="{{ $method->value }}" @selected($selectedMethod === $method->value)>{{ translate($method->labelKey()) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="label">{{ translate('label') }}</label>
                        <input class="form-control" id="label" name="label" maxlength="160" value="{{ $value('label') }}">
                    </div>
                    <div class="col-12">
                        @foreach ($methods as $method)
                            <p class="fs-12 text-muted mb-1" data-method-help="{{ $method->value }}"><strong>{{ translate($method->labelKey()) }}:</strong> {{ CostLabels::of('calc_method_help', $method->value) }}</p>
                        @endforeach
                    </div>
                </div>
            </x-k.card>

            <x-k.card :title="translate('the_figures')" class="mb-3">
                <div class="row g-3">
                    <div class="col-md-4" data-methods="{{ $methodGroup([CalcMethod::Fixed, CalcMethod::PerUnit, CalcMethod::PerShipment, CalcMethod::PerOrder, CalcMethod::PerDay, CalcMethod::PercentPlusFixed]) }}">
                        <label class="form-label" for="amount">{{ translate('rule_amount') }} ({{ $contract->currency }})</label>
                        <input class="form-control" type="number" min="0" step="any" id="amount" name="amount" value="{{ $value('amount') }}">
                    </div>
                    <div class="col-md-4" data-methods="{{ $methodGroup([CalcMethod::Percent, CalcMethod::PercentPlusFixed]) }}">
                        <label class="form-label" for="percent">{{ translate('rule_percentage') }}</label>
                        <input class="form-control" type="number" min="0" max="100" step="any" id="percent" name="percent" value="{{ $value('percent') }}">
                    </div>
                    <div class="col-md-4" data-methods="{{ $methodGroup([CalcMethod::Percent, CalcMethod::PercentPlusFixed]) }}">
                        <label class="form-label" for="min">{{ translate('rule_minimum_charge') }} ({{ $contract->currency }})</label>
                        <input class="form-control" type="number" min="0" step="any" id="min" name="min" value="{{ $value('min') }}">
                    </div>
                    <div class="col-md-4" data-methods="{{ $methodGroup([CalcMethod::Percent, CalcMethod::PercentPlusFixed]) }}">
                        <label class="form-label" for="max">{{ translate('rule_maximum_charge') }} ({{ $contract->currency }})</label>
                        <input class="form-control" type="number" min="0" step="any" id="max" name="max" value="{{ $value('max') }}">
                    </div>
                    <div class="col-md-4" data-methods="{{ $methodGroup([CalcMethod::PerDay]) }}">
                        <label class="form-label" for="multiply_by">{{ translate('rule_multiplied_by') }}</label>
                        <select class="form-select" id="multiply_by" name="multiply_by">
                            @foreach (CostLabels::values('multiply_by') as $multiplier)
                                <option value="{{ $multiplier }}" @selected($value('multiply_by') === $multiplier)>{{ CostLabels::of('multiply_by', $multiplier) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                @foreach ([
                    ['field' => 'weight_steps', 'method' => CalcMethod::ByWeight, 'limit' => translate('rule_up_to_kg'), 'extra' => 'extra_per_kg', 'step' => 'extra_step_kg', 'unit' => translate('rule_kg')],
                    ['field' => 'volume_steps', 'method' => CalcMethod::ByVolume, 'limit' => translate('rule_up_to_m3'), 'extra' => 'extra_per_m3', 'step' => 'extra_step_m3', 'unit' => translate('rule_m3')],
                ] as $group)
                    <div class="mt-3" data-methods="{{ $group['method']->value }}">
                        <div class="fw-semibold fs-12 mb-1">{{ translate($group['method']->labelKey()) }}</div>
                        <div class="k-table-wrap table-responsive">
                            <table class="k-table" data-repeat="{{ $group['field'] }}">
                                <thead><tr><th>{{ $group['limit'] }}</th><th>{{ translate('rule_charge') }} ({{ $contract->currency }})</th></tr></thead>
                                <tbody>
                                @foreach ($value($group['field']) as $index => $row)
                                    <tr>
                                        <td><input class="form-control form-control-sm" type="number" min="0" step="any" name="{{ $group['field'] }}[{{ $index }}][limit]" value="{{ $row['limit'] ?? '' }}" aria-label="{{ $group['limit'] }}"></td>
                                        <td><input class="form-control form-control-sm" type="number" min="0" step="any" name="{{ $group['field'] }}[{{ $index }}][amount]" value="{{ $row['amount'] ?? '' }}" aria-label="{{ translate('rule_charge') }}"></td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        <button class="btn btn-outline-secondary btn-sm mt-1" type="button" data-add-row="{{ $group['field'] }}">{{ translate('add_a_row') }}</button>
                        <div class="row g-3 mt-1">
                            <div class="col-md-4">
                                <label class="form-label fs-12" for="{{ $group['extra'] }}">{{ translate('rule_extra_charge_per_step') }} ({{ $contract->currency }})</label>
                                <input class="form-control form-control-sm" type="number" min="0" step="any" id="{{ $group['extra'] }}" name="{{ $group['extra'] }}" value="{{ $value($group['extra']) }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fs-12" for="{{ $group['step'] }}">{{ translate('rule_extra_step_size') }} ({{ $group['unit'] }})</label>
                                <input class="form-control form-control-sm" type="number" min="0" step="any" id="{{ $group['step'] }}" name="{{ $group['step'] }}" value="{{ $value($group['step']) }}">
                            </div>
                            <div class="col-12 fs-12 text-muted">{{ translate('rule_extra_charge_help') }}</div>
                        </div>
                    </div>
                @endforeach

                <div class="mt-3" data-methods="{{ CalcMethod::ByZone->value }}">
                    <div class="fw-semibold fs-12 mb-1">{{ translate(CalcMethod::ByZone->labelKey()) }}</div>
                    <div class="k-table-wrap table-responsive">
                        <table class="k-table" data-repeat="zones">
                            <thead><tr><th>{{ translate('rule_zone') }}</th><th>{{ translate('rule_cities') }}</th><th>{{ translate('rule_countries') }}</th><th>{{ translate('rule_charge') }} ({{ $contract->currency }})</th></tr></thead>
                            <tbody>
                            @foreach ($value('zones') as $index => $row)
                                <tr>
                                    <td><input class="form-control form-control-sm" name="zones[{{ $index }}][name]" maxlength="120" value="{{ $row['name'] ?? '' }}" aria-label="{{ translate('rule_zone') }}"></td>
                                    <td><input class="form-control form-control-sm" name="zones[{{ $index }}][cities]" value="{{ $row['cities'] ?? '' }}" aria-label="{{ translate('rule_cities') }}"></td>
                                    <td><input class="form-control form-control-sm" name="zones[{{ $index }}][countries]" value="{{ $row['countries'] ?? '' }}" aria-label="{{ translate('rule_countries') }}" dir="ltr"></td>
                                    <td><input class="form-control form-control-sm" type="number" min="0" step="any" name="zones[{{ $index }}][amount]" value="{{ $row['amount'] ?? '' }}" aria-label="{{ translate('rule_charge') }}"></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <button class="btn btn-outline-secondary btn-sm mt-1" type="button" data-add-row="zones">{{ translate('add_a_row') }}</button>
                    <div class="fs-12 text-muted mt-1">{{ translate('rule_zone_help') }}</div>
                </div>

                <div class="mt-3" data-methods="{{ CalcMethod::TieredQuantity->value }}">
                    <div class="fw-semibold fs-12 mb-1">{{ translate(CalcMethod::TieredQuantity->labelKey()) }}</div>
                    <div class="k-table-wrap table-responsive">
                        <table class="k-table" data-repeat="quantity_tiers">
                            <thead><tr><th>{{ translate('rule_from_units') }}</th><th>{{ translate('rule_to_units') }}</th><th>{{ translate('rule_charge') }} ({{ $contract->currency }})</th><th>{{ translate('rule_how_charged') }}</th></tr></thead>
                            <tbody>
                            @foreach ($value('quantity_tiers') as $index => $row)
                                <tr>
                                    <td><input class="form-control form-control-sm" type="number" min="0" step="any" name="quantity_tiers[{{ $index }}][from]" value="{{ $row['from'] ?? '' }}" aria-label="{{ translate('rule_from_units') }}"></td>
                                    <td><input class="form-control form-control-sm" type="number" min="0" step="any" name="quantity_tiers[{{ $index }}][to]" value="{{ $row['to'] ?? '' }}" aria-label="{{ translate('rule_to_units') }}"></td>
                                    <td><input class="form-control form-control-sm" type="number" min="0" step="any" name="quantity_tiers[{{ $index }}][amount]" value="{{ $row['amount'] ?? '' }}" aria-label="{{ translate('rule_charge') }}"></td>
                                    <td>
                                        <select class="form-select form-select-sm" name="quantity_tiers[{{ $index }}][mode]" aria-label="{{ translate('rule_how_charged') }}">
                                            @foreach (CostLabels::values('tier_mode') as $mode)
                                                <option value="{{ $mode }}" @selected(($row['mode'] ?? 'flat') === $mode)>{{ CostLabels::of('tier_mode', $mode) }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <button class="btn btn-outline-secondary btn-sm mt-1" type="button" data-add-row="quantity_tiers">{{ translate('add_a_row') }}</button>
                    <div class="fs-12 text-muted mt-1">{{ translate('rule_tier_help') }}</div>
                </div>

                <div class="mt-3" data-methods="{{ CalcMethod::TieredValue->value }}">
                    <div class="fw-semibold fs-12 mb-1">{{ translate(CalcMethod::TieredValue->labelKey()) }}</div>
                    <div class="k-table-wrap table-responsive">
                        <table class="k-table" data-repeat="value_tiers">
                            <thead><tr><th>{{ translate('rule_from_value') }}</th><th>{{ translate('rule_to_value') }}</th><th>{{ translate('rule_charge') }} ({{ $contract->currency }})</th><th>{{ translate('rule_percentage') }}</th></tr></thead>
                            <tbody>
                            @foreach ($value('value_tiers') as $index => $row)
                                <tr>
                                    <td><input class="form-control form-control-sm" type="number" min="0" step="any" name="value_tiers[{{ $index }}][from]" value="{{ $row['from'] ?? '' }}" aria-label="{{ translate('rule_from_value') }}"></td>
                                    <td><input class="form-control form-control-sm" type="number" min="0" step="any" name="value_tiers[{{ $index }}][to]" value="{{ $row['to'] ?? '' }}" aria-label="{{ translate('rule_to_value') }}"></td>
                                    <td><input class="form-control form-control-sm" type="number" min="0" step="any" name="value_tiers[{{ $index }}][amount]" value="{{ $row['amount'] ?? '' }}" aria-label="{{ translate('rule_charge') }}"></td>
                                    <td><input class="form-control form-control-sm" type="number" min="0" max="100" step="any" name="value_tiers[{{ $index }}][percent]" value="{{ $row['percent'] ?? '' }}" aria-label="{{ translate('rule_percentage') }}"></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <button class="btn btn-outline-secondary btn-sm mt-1" type="button" data-add-row="value_tiers">{{ translate('add_a_row') }}</button>
                    <div class="fs-12 text-muted mt-1">{{ translate('rule_value_tier_help') }}</div>
                </div>
            </x-k.card>

            <x-k.card :title="translate('when_the_rule_applies')" class="mb-3">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label" for="priority">{{ translate('priority') }}</label>
                        <input class="form-control" type="number" min="0" max="65535" step="1" id="priority" name="priority" value="{{ $value('priority') }}">
                        <div class="fs-12 text-muted">{{ translate('rule_priority_help') }}</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="valid_from">{{ translate('valid_from') }}</label>
                        <input class="form-control" type="date" id="valid_from" name="valid_from" value="{{ $value('valid_from') }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="valid_to">{{ translate('valid_to') }}</label>
                        <input class="form-control" type="date" id="valid_to" name="valid_to" value="{{ $value('valid_to') }}">
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <input type="hidden" name="is_active" value="0">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" @checked((bool) $value('is_active'))>
                            <label class="form-check-label" for="is_active">{{ translate('rule_is_active') }}</label>
                        </div>
                    </div>
                </div>
                <hr>
                <div class="fw-semibold fs-12 mb-1">{{ translate('conditions') }}</div>
                <p class="fs-12 text-muted">{{ translate('rule_conditions_help') }}</p>
                <div class="row g-3">
                    @foreach (['cities', 'countries', 'payment_methods', 'service_levels'] as $field)
                        <div class="col-md-6">
                            <label class="form-label fs-12" for="conditions-{{ $field }}">{{ CostLabels::of('condition', $field) }}</label>
                            <input class="form-control form-control-sm" id="conditions-{{ $field }}" name="conditions[{{ $field }}]" value="{{ $selectedConditions[$field] ?? '' }}">
                        </div>
                    @endforeach
                    <div class="col-12">
                        <div class="fs-12 mb-1">{{ translate('condition_sales_channels') }}</div>
                        @foreach ($channels as $channel)
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" id="conditions-channel-{{ $channel->value }}" name="conditions[sales_channels][]" value="{{ $channel->value }}" @checked(in_array($channel->value, (array) ($selectedConditions['sales_channels'] ?? []), true))>
                                <label class="form-check-label fs-12" for="conditions-channel-{{ $channel->value }}">{{ translate($channel->labelKey()) }}</label>
                            </div>
                        @endforeach
                    </div>
                    @foreach (['min_weight_kg', 'max_weight_kg', 'min_value', 'max_value'] as $field)
                        <div class="col-md-3">
                            <label class="form-label fs-12" for="conditions-{{ $field }}">{{ CostLabels::of('condition', $field) }}</label>
                            <input class="form-control form-control-sm" type="number" min="0" step="any" id="conditions-{{ $field }}" name="conditions[{{ $field }}]" value="{{ $selectedConditions[$field] ?? '' }}">
                        </div>
                    @endforeach
                </div>
            </x-k.card>

            <button class="btn btn-primary" type="submit">{{ $rule ? translate('save_rule') : translate('add_rule') }}</button>
            <a class="btn btn-outline-secondary" href="{{ route('admin.operational-costs.contracts.show', $contract->id) }}">{{ translate('cancel') }}</a>
        </form>
    </div>

    @include('admin-views.operational-costs.partials._add-row-script')
@endsection

@push('script')
    <script>
        (function () {
            var form = document.querySelector('[data-rule-form]');
            var select = form && form.querySelector('[data-method-select]');
            if (!select) { return; }

            // Only what the chosen method takes is shown. Without a choice (or without this script) everything is, each part named by its method.
            function showMethod() {
                var method = select.value;
                form.querySelectorAll('[data-methods]').forEach(function (part) {
                    part.hidden = method !== '' && part.getAttribute('data-methods').split(' ').indexOf(method) === -1;
                });
                form.querySelectorAll('[data-method-help]').forEach(function (help) {
                    help.hidden = method !== '' && help.getAttribute('data-method-help') !== method;
                });
            }
            select.addEventListener('change', showMethod);
            showMethod();
        })();
    </script>
@endpush
