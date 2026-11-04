<x-k.card :title="translate('the_period_at_a_glance')">
    <div class="ana-metrics">
        @include('admin-views.analytics.sections._metric', ['label' => 'visitors', 'metric' => $data['totals']['visitors']])
        @include('admin-views.analytics.sections._metric', ['label' => 'visits', 'metric' => $data['totals']['sessions']])
        @include('admin-views.analytics.sections._metric', ['label' => 'pageviews', 'metric' => $data['totals']['pageviews']])
        @include('admin-views.analytics.sections._metric', ['label' => 'orders', 'metric' => $data['totals']['orders']])
        @include('admin-views.analytics.sections._metric', ['label' => 'revenue', 'metric' => $data['totals']['revenue'], 'format' => 'money'])
        @include('admin-views.analytics.sections._metric', ['label' => 'conversion_rate', 'metric' => $data['totals']['conversion_rate'], 'suffix' => '%'])
        @include('admin-views.analytics.sections._metric', ['label' => 'bounce_rate', 'metric' => $data['totals']['bounce_rate'], 'suffix' => '%'])
        @include('admin-views.analytics.sections._metric', ['label' => 'pages_per_visit', 'metric' => $data['totals']['pages_per_session'], 'format' => 'decimal'])
    </div>
</x-k.card>

@include('admin-views.analytics.sections._trend', ['trend' => $data['trend'], 'trendState' => $data['totals']['state'] ?? null])

<div class="ana-grid ana-grid--2">
    @include('admin-views.analytics.sections._breakdown', [
        'breakdown' => $data['sources'], 'title' => translate('where_visits_came_from'),
        'label' => translate('source'), 'dimension' => 'source', 'window' => $window,
    ])
    @include('admin-views.analytics.sections._breakdown', [
        'breakdown' => $data['devices'], 'title' => translate('devices'),
        'label' => translate('device'), 'dimension' => 'device', 'window' => $window,
    ])
</div>

<div class="ana-grid ana-grid--2">
    @include('admin-views.analytics.sections._breakdown', [
        'breakdown' => $data['pages'], 'title' => translate('most_visited_pages'),
        'label' => translate('page'), 'dimension' => 'path', 'window' => $window,
    ])
    @include('admin-views.analytics.sections._funnel', ['funnel' => $data['funnel'], 'compact' => true])
</div>

{{-- The other half of the picture. This section answers what people did; how fast the server
     answered them, and whether it failed, is measured separately — and the two were built without
     a way to get from one to the other. --}}
<p class="ana-note">
    {{ translate('this_counts_people_and_what_they_did') }}
    <a href="{{ route('admin.monitoring.section', ['section' => 'requests', 'range' => '24h']) }}">{{ translate('how_fast_the_server_answered_them') }}</a>
    {{ translate('and') }}
    <a href="{{ route('admin.monitoring.section', ['section' => 'errors', 'range' => '24h']) }}">{{ translate('what_failed') }}</a>
    {{ translate('are_measured_in_monitoring') }}.
</p>
