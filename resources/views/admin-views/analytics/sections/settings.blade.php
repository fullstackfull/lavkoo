{{-- The decisions an administrator is allowed to make about live customer traffic.

     This page used to open with "Read-only for now, and honest about it" and print config() values
     with no form — so whether Do Not Track was honoured, whether consent was required before a
     visitor was measured, whether an IP was masked and how long anything was kept were all an
     environment variable and a deploy.

     Precedence is stored, then environment, then the shipped default: an install that sets these in
     .env keeps behaving exactly as it does. The read-only blocks below the form stay, because the
     values they show (the country header, the cookie name, what is never stored) are facts about
     the pipeline rather than choices. --}}

<x-k.card :title="translate('what_this_shop_measures')">
    <form action="{{ route('admin.settings.policies.update', ['group' => 'analytics']) }}" method="post" class="row g-3">
        @csrf
        @foreach ($data['fields'] ?? [] as $key => $field)
            <div class="col-md-6 col-xl-4">
                <label class="form-label fs-12 mb-1" for="{{ $key }}">{{ translate($field['label']) }}</label>
                @include('admin-views.settings.partials._policy-field', ['key' => $key, 'field' => $field, 'value' => $data['values'][$key]])
                @if (!empty($field['help']))
                    <small class="text-muted d-block mt-1">{{ translate($field['help']) }}.</small>
                @endif
            </div>
        @endforeach
        <div class="col-12 d-flex justify-content-end">
            <button class="btn btn-primary px-4">{{ translate('save') }}</button>
        </div>
    </form>
</x-k.card>

{{-- Below: facts about the pipeline rather than choices — they have no form because there is
     nothing to decide. --}}
<x-k.card :title="translate('what_is_collected')">
    <ul class="ana-list">
        <li><span>{{ translate('collection') }}</span><strong>{{ config('analytics.enabled') ? translate('on') : translate('off') }}</strong></li>
        <li><span>{{ translate('bots_excluded_from_reports') }}</span><strong>{{ config('analytics.exclude_bots') ? translate('yes') : translate('no') }}</strong></li>
        <li><span>{{ translate('staff_excluded_from_reports') }}</span><strong>{{ config('analytics.exclude_internal') ? translate('yes') : translate('no') }}</strong></li>
        <li><span>{{ translate('a_visit_ends_after') }}</span><strong>{{ config('analytics.session_gap_minutes') }} {{ translate('minutes_of_inactivity') }}</strong></li>
        <li><span>{{ translate('engaged_after') }}</span><strong>{{ config('analytics.engaged_after_seconds') }} {{ translate('seconds_or_a_second_page') }}</strong></li>
    </ul>
</x-k.card>

<x-k.card :title="translate('privacy')">
    <ul class="ana-list">
        <li><span>{{ translate('ip_addresses') }}</span><strong>{{ config('analytics.privacy.mask_ip') ? translate('masked_to_the_network_then_hashed') : translate('hashed_only') }}</strong></li>
        <li><span>{{ translate('precise_location') }}</span><strong>{{ translate('never_stored') }}</strong></li>
        {{-- The header name is only meaningful when the country is actually stored; printing it
             beside a switch that is off told an operator a country was being recorded. --}}
        <li>
            <span>{{ translate('country') }}</span>
            <strong>
                @if (!config('analytics.privacy.store_country'))
                    {{ translate('not_stored') }}
                @else
                    {{ config('analytics.privacy.country_header') ?: translate('not_configured') }}
                @endif
            </strong>
        </li>
        <li><span>{{ translate('fingerprinting') }}</span><strong>{{ translate('none_identity_is_a_random_first_party_cookie_the_visitor_can_clear') }}</strong></li>
        {{-- Both of these were declared in config and read by nothing, so a shop that switched
             them on believed it was honouring a signal nobody looked at. --}}
        <li>
            <span>{{ translate('do_not_track_and_global_privacy_control') }}</span>
            <strong>{{ config('analytics.privacy.respect_do_not_track') ? translate('honoured_such_visits_are_not_recorded') : translate('not_honoured') }}</strong>
        </li>
        <li>
            <span>{{ translate('cookie_consent') }}</span>
            <strong>{{ config('analytics.privacy.require_consent') ? translate('required_before_anything_is_recorded') : translate('not_required') }}</strong>
        </li>
    </ul>
    <p class="ana-note">{{ translate('event_properties_pass_through_the_same_redactor_the_monitoring_system_uses_so_a_password_an_otp_a_token_or_a_card_number_cannot_reach_these_tables') }}</p>
</x-k.card>

<x-k.card :title="translate('how_long_data_is_kept')">
    <ul class="ana-list">
        <li><span>{{ translate('raw_events') }}</span><strong>{{ config('analytics.retention.event_days') }} {{ translate('days') }}</strong></li>
        <li><span>{{ translate('visits') }}</span><strong>{{ config('analytics.retention.session_days') }} {{ translate('days') }}</strong></li>
        <li><span>{{ translate('daily_rollups') }}</span><strong>{{ config('analytics.retention.daily_days') }} {{ translate('days') }}</strong></li>
    </ul>
    <p class="ana-note">{{ translate('pruning_runs_nightly_with_the_rollup_and_deletes_in_chunks_rather_than_one_table_locking_statement') }}</p>
</x-k.card>
