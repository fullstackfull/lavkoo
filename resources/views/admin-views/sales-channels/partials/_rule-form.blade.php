{{-- A routing rule, to create or to edit. A field left empty means "any". Expects $values, $action (the form's URL), $bag (error bag), $submit (button text),
     $accounts, $locations, $channels, $modes, $policies, $actions, $providers, $managedDispatchEnabled, $labels, $errors. --}}
@php
    $has = $errors->hasBag($bag);
    $value = static fn (string $field): mixed => $has ? old($field, $values[$field] ?? null) : ($values[$field] ?? null);
    $error = static fn (string $field): ?string => $errors->getBag($bag)->first($field) ?: null;
    $prefix = $bag . '-';
@endphp
<form method="POST" action="{{ $action }}" class="d-flex flex-column gap-3" autocomplete="off">
    @csrf
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="{{ $prefix }}name">{{ translate('rule_name') }}</label>
            <input class="form-control" id="{{ $prefix }}name" name="name" maxlength="160" required value="{{ $value('name') }}">
            @if ($error('name'))<div class="text-danger fs-12" role="alert">{{ $error('name') }}</div>@endif
        </div>
        <div class="col-md-2">
            <label class="form-label" for="{{ $prefix }}priority">{{ translate('priority') }}</label>
            <input class="form-control" type="number" min="0" max="1000000" id="{{ $prefix }}priority" name="priority" required value="{{ $value('priority') }}">
            <div class="fs-12 text-muted">{{ translate('lower_runs_first') }}</div>
            @if ($error('priority'))<div class="text-danger fs-12" role="alert">{{ $error('priority') }}</div>@endif
        </div>
        <div class="col-md-2">
            <label class="form-label" for="{{ $prefix }}seller">{{ translate('seller_id_platform_is_zero') }}</label>
            <input class="form-control" type="number" min="0" id="{{ $prefix }}seller" name="seller_id" value="{{ $value('seller_id') }}" placeholder="0">
            <div class="fs-12 text-muted">{{ translate('rule_seller_help') }}</div>
            @if ($error('seller_id'))<div class="text-danger fs-12" role="alert">{{ $error('seller_id') }}</div>@endif
        </div>
        <div class="col-md-2">@include('admin-views.sales-channels.partials._switch', ['name' => 'is_active', 'id' => $prefix . 'active', 'label' => translate('active'), 'checked' => (bool) $value('is_active')])</div>
    </div>

    <fieldset class="border rounded p-3">
        <legend class="fs-14 fw-semibold w-auto px-2 mb-0">{{ translate('when_the_order_matches') }}</legend>
        <p class="fs-12 text-muted">{{ translate('a_rule_matches_only_when_every_field_it_sets_holds') }}</p>
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label fs-12" for="{{ $prefix }}channel">{{ translate('channel') }}</label>
                <select class="form-select form-select-sm" id="{{ $prefix }}channel" name="sales_channel">
                    <option value="">{{ translate('any') }}</option>
                    @foreach ($channels as $channel)<option value="{{ $channel->value }}" @selected($value('sales_channel') === $channel->value)>{{ translate($channel->labelKey()) }}</option>@endforeach
                </select>
                @if ($error('sales_channel'))<div class="text-danger fs-12" role="alert">{{ $error('sales_channel') }}</div>@endif
            </div>
            <div class="col-md-3">
                <label class="form-label fs-12" for="{{ $prefix }}account">{{ translate('account') }}</label>
                <select class="form-select form-select-sm" id="{{ $prefix }}account" name="account_id">
                    <option value="">{{ translate('any') }}</option>
                    @foreach ($accounts as $id => $name)<option value="{{ $id }}" @selected((int) $value('account_id') === (int) $id)>{{ $name }}</option>@endforeach
                </select>
                @if ($error('account_id'))<div class="text-danger fs-12" role="alert">{{ $error('account_id') }}</div>@endif
            </div>
            <div class="col-md-3">
                <label class="form-label fs-12" for="{{ $prefix }}mode">{{ translate('fulfillment_mode') }}</label>
                <select class="form-select form-select-sm" id="{{ $prefix }}mode" name="fulfillment_mode">
                    <option value="">{{ translate('any') }}</option>
                    @foreach ($modes as $mode)<option value="{{ $mode }}" @selected($value('fulfillment_mode') === $mode)>{{ $labels->fulfillmentMode($mode) }}</option>@endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fs-12" for="{{ $prefix }}policy">{{ translate('execution_policy') }}</label>
                <select class="form-select form-select-sm" id="{{ $prefix }}policy" name="execution_policy">
                    <option value="">{{ translate('any') }}</option>
                    @foreach ($policies as $policy)<option value="{{ $policy->value }}" @selected($value('execution_policy') === $policy->value)>{{ translate($policy->labelKey()) }}</option>@endforeach
                </select>
            </div>
            <div class="col-md-4">
                @include('admin-views.sales-channels.partials._product-picker', ['id' => $prefix . 'product', 'name' => 'product_id', 'label' => translate('product_any_when_empty'), 'value' => $value('product_id'), 'error' => $error('product_id')])
            </div>
            <div class="col-md-2">
                <label class="form-label fs-12" for="{{ $prefix }}sku">{{ translate('sku') }}</label>
                <input class="form-control form-control-sm" id="{{ $prefix }}sku" name="sku" maxlength="191" value="{{ $value('sku') }}">
            </div>
            <div class="col-md-3">
                <label class="form-label fs-12" for="{{ $prefix }}location">{{ translate('stock_location') }}</label>
                <select class="form-select form-select-sm" id="{{ $prefix }}location" name="inventory_location_id">
                    <option value="">{{ translate('any') }}</option>
                    @foreach ($locations as $id => $name)<option value="{{ $id }}" @selected((int) $value('inventory_location_id') === (int) $id)>{{ $name }}</option>@endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fs-12" for="{{ $prefix }}product-provider">{{ translate('product_provider') }}</label>
                <select class="form-select form-select-sm" id="{{ $prefix }}product-provider" name="product_provider">
                    <option value="">{{ translate('any') }}</option>
                    @foreach ($providers as $provider)<option value="{{ $provider->value }}" @selected($value('product_provider') === $provider->value)>{{ $provider->label() }}</option>@endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fs-12" for="{{ $prefix }}country">{{ translate('country_code') }}</label>
                <input class="form-control form-control-sm" id="{{ $prefix }}country" name="country" maxlength="2" placeholder="SA" value="{{ $value('country') }}">
                @if ($error('country'))<div class="text-danger fs-12" role="alert">{{ $error('country') }}</div>@endif
            </div>
            <div class="col-md-3">
                <label class="form-label fs-12" for="{{ $prefix }}city">{{ translate('city') }}</label>
                <input class="form-control form-control-sm" id="{{ $prefix }}city" name="city" maxlength="120" value="{{ $value('city') }}">
            </div>
            <div class="col-md-3">
                <label class="form-label fs-12" for="{{ $prefix }}min-total">{{ translate('min_order_total') }}</label>
                <input class="form-control form-control-sm" type="number" step="0.01" min="0" id="{{ $prefix }}min-total" name="min_order_total" value="{{ $value('min_order_total') }}">
                @if ($error('min_order_total'))<div class="text-danger fs-12" role="alert">{{ $error('min_order_total') }}</div>@endif
            </div>
            <div class="col-md-3">
                <label class="form-label fs-12" for="{{ $prefix }}max-total">{{ translate('max_order_total') }}</label>
                <input class="form-control form-control-sm" type="number" step="0.01" min="0" id="{{ $prefix }}max-total" name="max_order_total" value="{{ $value('max_order_total') }}">
                @if ($error('max_order_total'))<div class="text-danger fs-12" role="alert">{{ $error('max_order_total') }}</div>@endif
            </div>
            <div class="col-md-3">
                <label class="form-label fs-12" for="{{ $prefix }}cod">{{ translate('cash_on_delivery') }}</label>
                <select class="form-select form-select-sm" id="{{ $prefix }}cod" name="cod">
                    <option value="any" @selected($value('cod') === 'any')>{{ translate('any') }}</option>
                    <option value="yes" @selected($value('cod') === 'yes')>{{ translate('only_cash_on_delivery') }}</option>
                    <option value="no" @selected($value('cod') === 'no')>{{ translate('never_cash_on_delivery') }}</option>
                </select>
            </div>
            <div class="col-md-3">@include('admin-views.sales-channels.partials._switch', ['name' => 'requires_confirmed_stock', 'id' => $prefix . 'confirmed-stock', 'label' => translate('requires_confirmed_stock'), 'checked' => (bool) $value('requires_confirmed_stock'), 'help' => translate('requires_confirmed_stock_help')])</div>
        </div>
    </fieldset>

    <fieldset class="border rounded p-3">
        <legend class="fs-14 fw-semibold w-auto px-2 mb-0">{{ translate('then_the_order_is') }}</legend>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label fs-12" for="{{ $prefix }}action">{{ translate('rule_action') }}</label>
                <select class="form-select form-select-sm" id="{{ $prefix }}action" name="action" required>
                    @foreach ($actions as $routingAction)<option value="{{ $routingAction->value }}" @selected($value('action') === $routingAction->value)>{{ translate($routingAction->labelKey()) }}</option>@endforeach
                </select>
                @if ($error('action'))<div class="text-danger fs-12" role="alert">{{ $error('action') }}</div>@endif
                @unless ($managedDispatchEnabled)<div class="fs-12 text-warning">{{ translate('route_to_provider_is_recorded_as_wanted_only') }}</div>@endunless
            </div>
            <div class="col-md-4">
                <label class="form-label fs-12" for="{{ $prefix }}target-provider">{{ translate('target_provider') }}</label>
                <select class="form-select form-select-sm" id="{{ $prefix }}target-provider" name="target_provider">
                    <option value="">{{ translate('the_provider_of_the_line') }}</option>
                    @foreach ($providers as $provider)<option value="{{ $provider->value }}" @selected($value('target_provider') === $provider->value)>{{ $provider->label() }}</option>@endforeach
                </select>
                <div class="fs-12 text-muted">{{ translate('target_is_used_by_route_to_provider_and_monitor_only') }}</div>
                @if ($error('target_provider'))<div class="text-danger fs-12" role="alert">{{ $error('target_provider') }}</div>@endif
            </div>
            <div class="col-md-4">
                <label class="form-label fs-12" for="{{ $prefix }}target-location">{{ translate('target_location') }}</label>
                <select class="form-select form-select-sm" id="{{ $prefix }}target-location" name="target_location_id">
                    <option value="">{{ translate('not_set') }}</option>
                    @foreach ($locations as $id => $name)<option value="{{ $id }}" @selected((int) $value('target_location_id') === (int) $id)>{{ $name }}</option>@endforeach
                </select>
                @if ($error('target_location_id'))<div class="text-danger fs-12" role="alert">{{ $error('target_location_id') }}</div>@endif
            </div>
            <div class="col-12">
                <label class="form-label fs-12" for="{{ $prefix }}note">{{ translate('note') }}</label>
                <textarea class="form-control form-control-sm" id="{{ $prefix }}note" name="note" rows="2" maxlength="500">{{ $value('note') }}</textarea>
            </div>
        </div>
    </fieldset>

    <fieldset class="border rounded p-3">
        <legend class="fs-14 fw-semibold w-auto px-2 mb-0">{{ translate('validity_window') }}</legend>
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label fs-12" for="{{ $prefix }}valid-from">{{ translate('valid_from') }}</label>
                <input class="form-control form-control-sm" type="date" id="{{ $prefix }}valid-from" name="valid_from" value="{{ $value('valid_from') }}">
                @if ($error('valid_from'))<div class="text-danger fs-12" role="alert">{{ $error('valid_from') }}</div>@endif
            </div>
            <div class="col-md-3">
                <label class="form-label fs-12" for="{{ $prefix }}valid-to">{{ translate('valid_to') }}</label>
                <input class="form-control form-control-sm" type="date" id="{{ $prefix }}valid-to" name="valid_to" value="{{ $value('valid_to') }}">
                @if ($error('valid_to'))<div class="text-danger fs-12" role="alert">{{ $error('valid_to') }}</div>@endif
            </div>
            <div class="col-md-6 fs-12 text-muted align-self-end">{{ translate('validity_window_help') }}</div>
        </div>
    </fieldset>

    <div><button class="btn btn-primary" type="submit">{{ $submit }}</button></div>
</form>
