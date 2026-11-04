{{--
    Portal settings: what the console may do, and whether shapes are learned from traffic.

    All four were environment-only, so an operator could not see whether the console was enabled and
    turning writes off during an incident meant editing .env and clearing caches. The configuration
    value is still the fallback, so an install that sets them by environment is unchanged.
--}}

@php($fields = $data['fields'] ?? [])

<x-k.card :title="translate('the_api_console')">
    <p class="mon-note" style="margin-block-start:0">
        {{ translate('a_console_on_an_admin_panel_sends_real_requests_at_the_shop_that_takes_the_orders') }}.
        {{ translate('some_endpoints_are_never_sent_at_any_setting_money_identity_removal_and_that_list_is_deliberately_not_configurable') }}.
    </p>

    <form action="{{ route('admin.settings.policies.update', ['group' => 'developer']) }}" method="post" class="row g-3">
        @csrf
        @foreach ($fields as $key => $field)
            <div class="col-md-6">
                <label class="form-label fs-12 mb-1" for="{{ $key }}">{{ translate($field['label']) }}</label>
                @include('admin-views.settings.partials._policy-field', ['key' => $key, 'field' => $field, 'value' => $data['portal'][$key]])
                @if (!empty($field['help']))
                    <small class="mon-metric__note" style="display:block">{{ translate($field['help']) }}.</small>
                @endif
            </div>
        @endforeach
        <div class="col-12 d-flex justify-content-end">
            <button class="btn btn-primary px-4">{{ translate('save') }}</button>
        </div>
    </form>
</x-k.card>

<x-k.card :title="translate('api_snapshots')">
    <p class="mon-note" style="margin-block-start:0">
        @if ($data['snapshots'] ?? false)
            {{ translate('snapshots_are_being_taken_so_the_change_log_and_the_breaking_change_detection_have_something_to_compare_against') }}.
        @else
            {{-- Stated rather than left blank: the deprecation screen, the OpenAPI flag, the Postman
                 annotation and the Monitoring panel are all wired to render a change log, and
                 without a stored snapshot there is nothing for any of them to show. --}}
            {{ translate('no_snapshot_has_been_stored_yet_so_the_change_log_has_nothing_to_compare_against') }}.
        @endif
    </p>
    <form action="{{ route('admin.developer.snapshot') }}" method="post">
        @csrf
        <button class="btn btn-outline-primary btn-sm">{{ translate('take_a_snapshot_now') }}</button>
    </form>
</x-k.card>
