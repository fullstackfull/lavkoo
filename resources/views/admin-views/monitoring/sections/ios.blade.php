{{--
    iOS app health.

    The measured half of this page — traffic, latency, error rates, the version mix, and the
    sessions and crashes the app reports about itself — is _mobile-app, shared with Android: the two
    sections ask the same questions of the same series one platform apart, and two copies of that
    markup would drift the first time either was fixed.

    What is drawn here and not there is the part that is genuinely iOS's own, and it is drawn
    BEFORE the numbers rather than under them: every figure on this page is a subset of requests
    that a middleware decided was iOS, and that decision is a declared header first and a user-agent
    guess second. That guess is not the same set as "iPhones and iPads" — it admits mobile Safari
    and any Mac client built on URLSession, and it misses an iPad in desktop mode. A reader who does
    not know that will read "requests from the iOS app" as a fact about the app; on a shop whose app
    sends no X-Platform it is closer to a fact about Apple clients. So the rule is stated as a
    caveat above the cards and transcribed rule by rule below them, and the one number that would
    settle it is shown as the unconfigured reading it is.

    The closing card is the opposite failure: a section that silently omits start-up time reads as
    a section where start-up time is fine. Each missing measurement is drawn with its reason and
    the exact change that would produce it — including the ANR counter on the stability card above,
    which is an Android mechanism this platform does not have.

    Expects: $panel, $range.
--}}
@php
    $identification = $panel['identification'];
    $notMeasured = $panel['not_measured'];
    $window = $panel['window'];
    $timeline = $panel['timeline'];

    $stateTitle = static fn (string $state) => match ($state) {
        'failed' => translate('this_could_not_be_read'),
        'not_configured' => translate('not_configured'),
        'permission_denied' => translate('permission_denied'),
        'not_supported' => translate('not_supported'),
        'collector_offline' => translate('collector_offline'),
        default => translate('no_data'),
    };
@endphp

{{-- Said before any count, because it decides what every count on this page is a count OF. --}}
<div class="mon-attention">
    <div class="mon-attention__item mon-attention__item--info">
        <x-k.icon name="customers" :size="16" />
        <span class="mon-attention__body">
            <strong>{{ translate('what_this_page_counts_as_the_ios_app') }}</strong>
            <small>{{ translate('a_request_that_sends_the_platform_header_is_attributed_exactly_one_that_does_not_is_classified_from_its_user_agent') }}</small>
            <small>{{ $identification['caveat'] }}</small>
            <code>{{ $identification['header'] }}</code>
        </span>
    </div>
</div>

{{-- The measured page, shared with Android. --}}
@include('admin-views.monitoring.partials._mobile-app', ['panel' => $panel, 'range' => $range])

{{-- The shared partial draws the chart only when there are two points to draw a line between, and
     draws nothing whatsoever otherwise. An absent chart above a non-zero request count reads as a
     contradiction, so the reason it is absent is stated where the chart would have been. --}}
@if ($timeline['state'] !== 'ok')
    <x-k.card :title="translate('requests_from_this_app_over_time')">
        <x-k.empty icon="trend-up" :title="$stateTitle($timeline['state'])" :text="$timeline['note'] ?? ''" />
        @if (!empty($timeline['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $timeline['remedy'] }}</code>
            </details>
        @endif
    </x-k.card>
@endif

{{-- The selection rule itself, so the figures above can be checked rather than believed. Two of
     these five branches are the app declaring itself and one is an inference; the table says which
     is which instead of leaving "iOS" to mean whatever the reader assumes. --}}
<x-k.card :title="translate('how_a_request_is_attributed_to_this_app')">
    <div class="k-table-wrap">
        <table class="k-table k-table--compact">
            <thead>
            <tr>
                <th>{{ translate('when') }}</th>
                <th>{{ translate('what_happens') }}</th>
                <th>{{ translate('how_sure') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($identification['rules'] as $rule)
                {{-- Both strings are transcribed from the middleware in the panel, so they are
                     echoed as written. A value that reached translate() would mint a language key
                     per sentence into new-messages.php. --}}
                <tr class="{{ $rule['certain'] ? '' : 'mon-row--muted' }}">
                    <td>{{ $rule['test'] }}</td>
                    <td>{{ $rule['outcome'] }}</td>
                    <td>
                        @if ($rule['certain'])
                            <span class="mon-pill mon-pill--info">{{ translate('declared') }}</span>
                        @else
                            <span class="mon-pill mon-pill--warning">{{ translate('inferred') }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <div class="mon-grid">
        @include('admin-views.monitoring.partials._metric', [
            'metric' => $identification['attribution_split'],
            'label' => translate('share_of_this_traffic_that_declared_itself'),
        ])
    </div>

    <p class="mon-note">
        {{ translate('the_rule_is_read_from') }} <code>{{ $identification['source'] }}</code>
        {{ translate('and_the_counters_are_written_by') }} <code>{{ $identification['recorder'] }}</code>.
        {{ translate('a_release_is_attributed_from') }} <code>{{ $identification['version_header'] }}</code>,
        {{ translate('accepted_only_when_it_matches') }} <code>{{ $identification['version_pattern'] }}</code>.
    </p>

    <details class="mon-metric__remedy">
        <summary>{{ translate('how_to_make_this_exact') }}</summary>
        <code>{{ $identification['remedy'] }}</code>
    </details>
</x-k.card>

{{-- Not measurements that came back empty — questions this deployment cannot answer at all. Drawn
     as readings with their reason, because an omitted card reads as a card with nothing to report. --}}
<x-k.card :title="translate('what_this_section_cannot_measure')">
    <div class="mon-grid">
        @foreach ($notMeasured['fields'] as $name => $metric)
            @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($name)])
        @endforeach
    </div>
    <p class="mon-note">{{ $notMeasured['note'] }}</p>
</x-k.card>

<p class="mon-note">
    {{ translate('traffic_latency_and_error_counts_are_read_from') }} <code>monitoring_series</code>
    (<code>requests.by_platform</code>, <code>requests.by_platform.errors</code>,
    <code>requests.by_platform.client_errors</code>, <code>requests.by_app_version</code>),
    {{ translate('and_the_self_reported_counters_from') }} <code>app.health.sessions</code>,
    <code>app.health.crashes</code>, <code>app.health.anrs</code>.
    {{ translate('the_chart_counts_requests_from_the_bucket_sample_count_not_from_the_millisecond_total_stored_beside_it') }}.
    @if ($timeline['state'] === 'ok' && !empty($timeline['note']))
        {{-- The caveat that survives a chart that drew fine: the newest coarse bucket is partial. --}}
        {{ $timeline['note'] }}
    @endif
    {{ translate('this_page_covers') }} {{ $window['since'] }} → {{ $window['until'] }} ({{ $window['timezone'] }}),
    {{ translate('at_one_point_per') }} {{ translate($window['resolution']) }}.
</p>
