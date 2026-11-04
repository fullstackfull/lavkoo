<div class="ana-grid ana-grid--2">
    @include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['devices'], 'title' => translate('devices'), 'label' => translate('device'), 'dimension' => 'device', 'window' => $window])
    @include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['new_vs_returning'], 'title' => translate('new_against_returning'), 'label' => translate('visitor'), 'dimension' => 'new_vs_returning', 'window' => $window])
</div>
<div class="ana-grid ana-grid--2">
    @include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['os'], 'title' => translate('operating_systems'), 'label' => translate('system'), 'dimension' => 'os', 'window' => $window])
    @include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['browsers'], 'title' => translate('browsers'), 'label' => translate('browser'), 'dimension' => 'browser', 'window' => $window])
</div>
<div class="ana-grid ana-grid--2">
    @include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['languages'], 'title' => translate('languages'), 'label' => translate('language'), 'dimension' => 'language', 'window' => $window])
    @include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['app_versions'], 'title' => translate('mobile_app_versions'), 'label' => translate('version'), 'dimension' => 'app_version', 'window' => $window])
</div>

<x-k.card :title="translate('country')">
    @if (($data['countries']['rows'] ?? []) === [])
        {{-- Never a blank card: geography needs a resolver, and saying which one turns it on is
             the difference between a missing feature and a fixable setting. --}}
        <x-k.empty
            :title="translate('country_is_not_being_recorded')"
            :text="translate('country_comes_from_a_header_a_cdn_resolves_set_analytics_country_header_to_cf_ipcountry_behind_cloudflare_no_geo_lookup_is_performed_on_the_request_path_and_no_precise_location_is_ever_stored')" />
    @else
        @include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['countries'], 'title' => translate('countries'), 'label' => translate('country'), 'dimension' => 'country', 'window' => $window])
    @endif
</x-k.card>
