{{--
    APIs: the surface this shop publishes, joined to the traffic it actually serves.

    Two stores, one join. The Developer Portal's manifest knows every route under api/ and nothing
    about who calls it; the monitoring buckets know every api request and nothing about what it was
    for. On their own each one answers half a question, and the half each one cannot answer is the
    half that matters: an endpoint documented as deprecated is only a problem while somebody is
    still calling it, and an endpoint with no traffic is only a candidate for removal if anything
    was being recorded at all.

    So silence is never drawn as a clean bill of health. Every list of endpoints without traffic
    carries the number of api requests recorded in the same window as its proof, and when that
    number is zero the list says the silence proves nothing rather than letting four hundred
    untouched endpoints read as four hundred dead ones.

    Percentiles are not added up anywhere on this page. A version's row carries the traffic-weighted
    mean, which is exact, and the p95 of its single worst endpoint, which is named — because there
    is no per-version histogram in the store and averaging percentiles would invent one.
--}}

@php
    $window = $panel['window'];
    $collection = $panel['collection'];
    $manifest = $panel['manifest'];
    $traffic = $panel['traffic'];
    $coverage = $panel['coverage'];
    $filters = $panel['filters'];
    $versions = $panel['versions'];
    $deprecated = $panel['deprecated'];
    $silent = $panel['silent'];
    $unmatched = $panel['unmatched'];

    $stateTitle = static fn (string $state) => match ($state) {
        'failed' => translate('this_could_not_be_read'),
        'not_configured' => translate('not_configured'),
        'permission_denied' => translate('permission_denied'),
        'not_supported' => translate('not_supported'),
        'collector_offline' => translate('collector_offline'),
        'stale' => translate('collector_offline'),
        default => translate('no_data'),
    };

    $count = static fn ($value) => $value === null ? null : number_format((float) $value);
    $ms = static fn ($value) => $value === null ? null : number_format((float) $value, 1) . ' ms';

    // A rate that rounds to zero over a long window is not a dead endpoint: two requests across a
    // day is 0.001/min, and printing "0.00" beside "2 requests" contradicts the number next to it.
    $rate = static function ($value) {
        if ($value === null) {
            return null;
        }
        $value = (float) $value;

        return $value > 0 && $value < 0.01 ? '< 0.01' : number_format($value, 2);
    };

    $percent = static function ($value) {
        if ($value === null) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.') . '%';
    };

    $duration = static function ($milliseconds) {
        if ($milliseconds === null) {
            return null;
        }
        $milliseconds = (float) $milliseconds;

        return $milliseconds < 1000
            ? number_format($milliseconds, 0) . ' ms'
            : number_format($milliseconds / 1000, 1) . ' s';
    };

    // A value out of a column or an attribute only reaches translate() when it is one this build
    // authored: translate() persists every key it has not seen, so one unrecognised audience would
    // mint a language key per value. Versions and group names are identifiers and are echoed.
    $vocabulary = static fn (?string $value, bool $known) => $value === null
        ? null
        : ($known ? translate($value) : $value);

    // The portal knows an endpoint by an id nothing outside it can compute, so the link hands over
    // the path and lets it resolve — the same way the requests and traces sections link in.
    $portal = static fn (string $path, ?string $method = null) => route(
        'admin.developer.lookup',
        array_filter(['path' => $path, 'method' => $method]),
    );

    $swatches = ['a', 'b', 'c', 'd', 'e'];

    $collectionTone = match ($collection['state']) {
        'ok' => null,
        'stale' => 'mon-attention__item--warning',
        default => 'mon-attention__item--critical',
    };

    // Two orderings of one read, drawn from one block of markup: the fifteen slowest endpoints are
    // not a subset of the fifteen busiest, and neither table is a filter over the other.
    $tables = [
        [
            'block' => $panel['busiest'],
            'title' => 'busiest_api_endpoints',
            'why' => 'what_the_api_spends_its_day_answering_ranked_by_request_count_with_the_total_time_each_one_costs_beside_it',
            'icon' => 'trend-up',
        ],
        [
            'block' => $panel['slowest'],
            'title' => 'slowest_api_endpoints',
            'why' => 'ranked_by_p95_the_experience_of_the_unluckiest_one_in_twenty_calls_rather_than_the_average_nobody_actually_has',
            'icon' => 'clock',
        ],
    ];

    $clearUrl = route('admin.monitoring.section', ['section' => 'apis', 'range' => $range]);
@endphp

{{-- Before any count: whether anything was being recorded. Every silence below has two readings,
     and only this block can tell them apart. --}}
<div class="mon-attention">
    @if ($collectionTone !== null)
        <div class="mon-attention__item {{ $collectionTone }}">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('api_traffic_is_not_being_recorded_right_now') }}</strong>
                <small>{{ $collection['note'] }}</small>
                @if ($collection['newest_bucket_at'])
                    <small>
                        {{ translate('newest_request_bucket') }}: {{ $collection['newest_bucket_at'] }}
                        ({{ $window['timezone'] }})
                    </small>
                @endif
                @if (!empty($collection['remedy']))
                    <code>{{ $collection['remedy'] }}</code>
                @endif
            </span>
        </div>
    @endif

    @if ($manifest['state'] !== 'ok')
        <div class="mon-attention__item mon-attention__item--{{ $manifest['state'] === 'failed' ? 'critical' : 'warning' }}">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('the_documented_api_surface_could_not_be_read') }}</strong>
                <small>{{ $manifest['note'] }}</small>
                <small>{{ translate('the_traffic_below_is_still_real_but_it_cannot_be_matched_to_a_version_an_audience_or_a_deprecation') }}</small>
                @if (!empty($manifest['remedy']))
                    <code>{{ $manifest['remedy'] }}</code>
                @endif
            </span>
        </div>
    @endif

    {{-- Drawn whatever the state, because it is the definition of every number on the page. --}}
    <div class="mon-attention__item mon-attention__item--info">
        <x-k.icon name="info" :size="16" />
        <span class="mon-attention__body">
            <strong>{{ translate('what_this_page_joins') }}</strong>
            <small>
                {{ translate('the_developer_portal_manifest_built_from_the_live_route_table') }}
                @if ($manifest['total'] !== null)
                    ({{ $count($manifest['total']) }} {{ translate('endpoints_under_api') }})
                @endif
                {{ translate('joined_to_the_monitoring_request_buckets_on_the_route_pattern_they_share') }}.
            </small>
            <small>{{ translate('an_endpoint_nobody_called_has_no_error_rate_and_no_response_time_it_is_not_a_zero_and_this_page_never_draws_one') }}</small>
            @if ($manifest['generated_at'])
                <small>
                    {{ translate('manifest_built_at') }}: {{ $manifest['generated_at'] }}
                    @if ($manifest['app_version']) — {{ translate('release') }} {{ $manifest['app_version'] }} @endif
                </small>
            @endif
        </span>
    </div>
</div>

{{-- The totals, each carrying its own state: a figure nobody measured renders as the reason it is
     missing, never as a zero that would read as a healthy API. --}}
<x-k.card :title="translate('the_api_at_a_glance')">
    @if (!empty($panel['headline']))
        <div class="mon-grid">
            @foreach ($panel['headline'] as $name => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($name)])
            @endforeach
        </div>
    @else
        <x-k.empty icon="external" :title="$stateTitle($traffic['state'] ?? 'no_data')" :text="$traffic['note'] ?? ''" />
    @endif

    @if (($coverage['state'] ?? '') === 'partial')
        {{-- Two reads of one store answer this page. Where they differ, saying so is the difference
             between a page that looks broken and a page that explains itself. --}}
        <p class="mon-note">{{ $coverage['note'] }}</p>
    @endif

    <p class="mon-note">
        {{ translate('counts_and_percentiles_cover') }} {{ $window['since'] }} → {{ $window['until'] }}
        ({{ $window['timezone'] }}),
        {{ translate('read_from') }} <code>{{ $traffic['source'] }}</code>
        {{ translate('at_resolution') }} {{ translate($window['resolution']) }}.
        {{ translate('the_endpoint_count_is_the_live_route_table_not_a_list_anybody_maintains_by_hand') }}
        @if ($manifest['documented'] !== null && $manifest['total'] !== null)
            {{ $count($manifest['documented']) }} {{ translate('of_them_carry_a_written_apidoc_description') }};
            {{ $count($manifest['rate_limited']) }} {{ translate('declare_a_rate_limit') }}.
        @endif
    </p>
</x-k.card>

{{-- Per version: the one grouping the buckets cannot do themselves. Version is not a column on a
     bucket — it is a property of the route pattern — so this is a fold of the same per-endpoint
     read the tables below use, and it agrees with them by construction. --}}
<x-k.card :title="translate('traffic_and_errors_by_api_version')">
    @if ($versions['state'] === 'ok')
        @if (($versions['total_hits'] ?? 0) > 0)
            <div class="mon-split" role="img" aria-label="{{ translate('share_of_api_traffic_by_version') }}">
                @foreach ($versions['rows'] as $row)
                    @if (($row['share'] ?? 0) > 0)
                        <span class="mon-split__part mon-split__part--{{ $swatches[$loop->index % count($swatches)] }}"
                              style="inline-size: {{ $row['share'] }}%"
                              title="{{ $row['version'] }}: {{ $count($row['hits']) }} ({{ $row['share'] }}%)"></span>
                    @endif
                @endforeach
            </div>
            <ul class="mon-split__legend">
                @foreach ($versions['rows'] as $row)
                    <li class="mon-split__key">
                        <span class="mon-split__swatch mon-split__part--{{ $swatches[$loop->index % count($swatches)] }}" aria-hidden="true"></span>
                        <span class="k-num">{{ $row['version'] }}</span>
                        <span class="k-num">{{ $count($row['hits']) }}@if ($row['share'] !== null)<i>{{ $row['share'] }}%</i>@endif</span>
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('version') }}</th>
                    <th class="k-table__num">{{ translate('documented_endpoints') }}</th>
                    <th class="k-table__num">{{ translate('called_in_this_window') }}</th>
                    <th class="k-table__num">{{ translate('never_called') }}</th>
                    <th class="k-table__num">{{ translate('requests') }}</th>
                    <th class="k-table__num">{{ translate('per_minute') }}</th>
                    <th class="k-table__num">{{ translate('error_rate') }}</th>
                    <th class="k-table__num">{{ translate('client_error_rate') }}</th>
                    <th class="k-table__num">{{ translate('mean') }}</th>
                    <th>{{ translate('slowest_endpoint_p95') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($versions['rows'] as $row)
                    {{-- Three different blanks in one row, and they must not look alike: a version
                         nothing was recorded for wears the collection's state, a version that was
                         watched and called nothing says so, and only a real reading prints. --}}
                    @php($unwatched = $row['hits'] === null)
                    <tr class="{{ ($row['hits'] ?? 0) > 0 ? '' : 'mon-row--muted' }}">
                        <td><code>{{ $row['version'] }}</code></td>
                        <td class="k-table__num k-num">
                            @if ($row['documented_endpoints'] === null)
                                <span class="mon-metric__state">{{ translate('no_data') }}</span>
                            @else
                                {{ $count($row['documented_endpoints']) }}
                            @endif
                        </td>
                        <td class="k-table__num k-num">
                            @if ($row['endpoints_called'] === null)
                                <span class="mon-metric__state">{{ $stateTitle($traffic['state'] ?? 'no_data') }}</span>
                            @else
                                {{ $count($row['endpoints_called']) }}
                                @if (($row['endpoints_called_undocumented'] ?? 0) > 0)
                                    <small class="mon-metric__note" style="display:block">
                                        {{ $count($row['endpoints_called_undocumented']) }} {{ translate('not_in_the_manifest') }}
                                    </small>
                                @endif
                            @endif
                        </td>
                        <td class="k-table__num k-num">
                            @if ($row['endpoints_silent'] === null)
                                <span class="mon-metric__state">{{ $stateTitle($traffic['state'] ?? 'no_data') }}</span>
                            @else
                                {{ $count($row['endpoints_silent']) }}
                            @endif
                        </td>
                        <td class="k-table__num k-num">
                            @if ($row['hits'] === null)
                                <span class="mon-metric__state">{{ $stateTitle($traffic['state'] ?? 'no_data') }}</span>
                            @else
                                {{ $count($row['hits']) }}
                            @endif
                        </td>
                        <td class="k-table__num k-num">
                            @if ($row['requests_per_minute'] === null)
                                <span class="mon-metric__state">{{ $unwatched ? $stateTitle($traffic['state'] ?? 'no_data') : translate('no_traffic') }}</span>
                            @else
                                {{ $rate($row['requests_per_minute']) }}
                            @endif
                        </td>
                        <td class="k-table__num k-num">
                            @if ($row['error_rate'] === null)
                                {{-- No request means no rate. A zero here would say this version
                                     answers everything perfectly, which is a claim about traffic
                                     that never arrived. --}}
                                <span class="mon-metric__state">{{ $unwatched ? $stateTitle($traffic['state'] ?? 'no_data') : translate('no_traffic') }}</span>
                            @elseif ($row['severity'] === 'ok')
                                {{ $percent($row['error_rate']) }}
                            @else
                                <span class="mon-pill mon-pill--{{ $row['severity'] }}">{{ $percent($row['error_rate']) }}</span>
                            @endif
                        </td>
                        <td class="k-table__num k-num">
                            @if ($row['client_error_rate'] === null)
                                <span class="mon-metric__state">{{ $unwatched ? $stateTitle($traffic['state'] ?? 'no_data') : translate('no_traffic') }}</span>
                            @else
                                {{ $percent($row['client_error_rate']) }}
                            @endif
                        </td>
                        <td class="k-table__num k-num">
                            @if ($row['avg_ms'] === null)
                                <span class="mon-metric__state">{{ $unwatched ? $stateTitle($traffic['state'] ?? 'no_data') : translate('no_traffic') }}</span>
                            @else
                                {{ $ms($row['avg_ms']) }}
                            @endif
                        </td>
                        <td>
                            @if ($row['worst_p95_ms'] === null)
                                <span class="mon-metric__state">{{ $unwatched ? $stateTitle($traffic['state'] ?? 'no_data') : translate('no_traffic') }}</span>
                            @else
                                <span class="k-num">{{ $ms($row['worst_p95_ms']) }}</span>
                                <small class="mon-metric__note" style="display:block">
                                    @if ($row['worst_p95_linkable'])
                                        <a href="{{ $portal($row['worst_p95_path'], $row['worst_p95_method']) }}">{{ $row['worst_p95_path'] }}</a>
                                    @else
                                        {{ $row['worst_p95_path'] }}
                                    @endif
                                </small>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <p class="mon-note">{{ $versions['mean_definition'] }}</p>
        <p class="mon-note">{{ $versions['percentile_caveat'] }}</p>
    @else
        <x-k.empty icon="reports" :title="$stateTitle($versions['state'])" :text="$versions['note'] ?? ''" />
        @if (!empty($versions['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $versions['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- The filter lives in the URL: a narrowed endpoint list is something people paste to each other
     while they are deciding whether a version can be retired. --}}
<x-k.card :padded="false">
    <form method="get" class="k-view__toolbar">
        <input type="hidden" name="range" value="{{ $range }}">

        <div class="k-view__toolbar-grow">
            <select name="version" class="k-select" aria-label="{{ translate('version') }}">
                <option value="all" @selected($filters['version'] === 'all')>{{ translate('every_version') }}</option>
                @foreach ($filters['choices'] as $choice)
                    <option value="{{ $choice }}" @selected($filters['version'] === $choice)>{{ $choice }}</option>
                @endforeach
            </select>
        </div>

        <div class="k-row">
            <x-k.button type="submit" variant="primary" size="sm" icon="filter">{{ translate('apply') }}</x-k.button>
            <x-k.button :href="$clearUrl" variant="ghost" size="sm">{{ translate('clear') }}</x-k.button>
        </div>
    </form>

    <div class="k-card__body">
        <p class="mon-note" style="margin-block-start:0">
            {{ translate('the_version_filter_narrows_the_four_tables_below_only_the_totals_and_the_version_table_above_always_cover_every_api_endpoint') }}
        </p>
    </div>
</x-k.card>

@foreach ($tables as $table)
    @php($block = $table['block'])
    <x-k.card :title="translate($table['title'])">
        <p class="mon-note" style="margin-block-start:0">{{ translate($table['why']) }}</p>

        @if ($block['state'] === 'ok')
            <div class="k-table-wrap">
                <table class="k-table k-table--compact">
                    <thead>
                    <tr>
                        <th>{{ translate('endpoint') }}</th>
                        <th>{{ translate('method') }}</th>
                        <th>{{ translate('version') }}</th>
                        <th class="k-table__num">{{ translate('requests') }}</th>
                        <th class="k-table__num">{{ translate('per_minute') }}</th>
                        <th class="k-table__num">{{ translate('avg') }}</th>
                        <th class="k-table__num">p95</th>
                        <th class="k-table__num">p99</th>
                        <th class="k-table__num">{{ translate('errors') }}</th>
                        <th class="k-table__num">{{ translate('error_rate') }}</th>
                        <th class="k-table__num">{{ translate('db_ms') }}</th>
                        <th class="k-table__num">{{ translate('total_time') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($block['rows'] as $row)
                        <tr>
                            <td>
                                @if ($row['linkable'])
                                    <a class="k-truncate" style="display:block;max-inline-size:280px"
                                       href="{{ $portal($row['path'], $row['method']) }}"
                                       title="{{ $row['method'] }} {{ $row['path'] }} — {{ translate('open_this_endpoint_in_the_developer_portal') }}">{{ $row['path'] }}</a>
                                @else
                                    {{-- A synthetic key the recorder writes when a request matched
                                         no route. It is not a path, so it resolves to nothing. --}}
                                    <span class="k-truncate" style="display:block;max-inline-size:280px"
                                          title="{{ translate('requests_that_matched_no_route_at_all') }}">{{ $row['path'] }}</span>
                                @endif
                                @if ($row['summary'])
                                    <small class="mon-metric__note" style="display:block">{{ $row['summary'] }}</small>
                                @endif
                                @if ($row['deprecated'] === true)
                                    <span class="mon-pill mon-pill--critical">{{ translate('deprecated') }}</span>
                                @endif
                                @if ($row['in_manifest'] === false)
                                    {{-- Only when the manifest was read and does not carry it. With
                                         no manifest the question was never asked, and a pill here
                                         would answer it anyway. --}}
                                    <span class="mon-pill mon-pill--warning">{{ translate('not_in_the_manifest') }}</span>
                                @endif
                                @if ($row['audience'])
                                    <span class="mon-pill mon-pill--info">{{ $vocabulary($row['audience'], $row['audience_known']) }}</span>
                                @endif
                                @if ($row['group'])
                                    <span class="mon-pill mon-pill--info">{{ $row['group'] }}</span>
                                @endif
                            </td>
                            <td class="k-num">{{ $row['method'] }}</td>
                            <td>
                                <code>{{ $row['version'] }}</code>
                                @if ($row['version_from_path'])
                                    <small class="mon-metric__note" style="display:block">{{ translate('read_from_the_path') }}</small>
                                @endif
                            </td>
                            <td class="k-table__num k-num">{{ $count($row['hits']) }}</td>
                            <td class="k-table__num k-num">{{ $rate($row['requests_per_minute']) ?? '—' }}</td>
                            <td class="k-table__num k-num">{{ $ms($row['avg']) ?? '—' }}</td>
                            <td class="k-table__num k-num">{{ $ms($row['p95']) ?? '—' }}</td>
                            <td class="k-table__num k-num">{{ $ms($row['p99']) ?? '—' }}</td>
                            <td class="k-table__num k-num">{{ $count($row['errors']) }}</td>
                            <td class="k-table__num k-num">
                                @if ($row['error_rate'] === null)
                                    <span class="mon-metric__state">{{ translate('no_data') }}</span>
                                @elseif ($row['severity'] === 'ok')
                                    {{ $percent($row['error_rate']) }}
                                @else
                                    <span class="mon-pill mon-pill--{{ $row['severity'] }}">{{ $percent($row['error_rate']) }}</span>
                                @endif
                            </td>
                            <td class="k-table__num k-num">{{ $ms($row['db_ms_avg']) ?? '—' }}</td>
                            <td class="k-table__num k-num">{{ $duration($row['total_time_ms']) ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <p class="mon-note">
                {{ count($block['rows']) }}
                @if ($block['truncated'])
                    {{ translate('of') }} {{ $count($block['measured_routes']) }} {{ translate('api_endpoints_that_recorded_a_request_in_this_window') }}
                @else
                    {{ translate('api_endpoints_recorded_a_request_in_this_window') }}
                @endif
                @if ($filters['narrowed'])
                    — {{ translate('narrowed_to_version') }} <code>{{ $filters['version'] }}</code>
                @endif
            </p>
        @else
            <x-k.empty :icon="$table['icon']" :title="$stateTitle($block['state'])" :text="$block['note'] ?? ''" />
            @if (!empty($block['remedy']))
                <details class="mon-metric__remedy">
                    <summary>{{ translate('how_to_enable_this') }}</summary>
                    <code>{{ $block['remedy'] }}</code>
                </details>
            @endif
            @if ($filters['narrowed'])
                <x-k.button :href="$clearUrl" variant="secondary" size="sm">{{ translate('clear_filters') }}</x-k.button>
            @endif
        @endif
    </x-k.card>
@endforeach

{{-- The removal-blocking list. A deprecation is a decision; traffic is what decides whether it can
     be acted on, and "3 calls yesterday" is a fact somebody can work with where "are you sure?"
     is not. --}}
<x-k.card :title="translate('deprecated_endpoints_that_are_still_being_called')">
    @if ($deprecated['state'] === 'ok')
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('endpoint') }}</th>
                    <th>{{ translate('version') }}</th>
                    <th>{{ translate('still_being_called') }}</th>
                    <th class="k-table__num">{{ translate('requests') }}</th>
                    <th class="k-table__num">{{ translate('error_rate') }}</th>
                    <th class="k-table__num">p95</th>
                    <th>{{ translate('deprecated_since') }}</th>
                    <th>{{ translate('removal_planned_for') }}</th>
                    <th>{{ translate('replaced_by') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($deprecated['rows'] as $row)
                    <tr class="{{ $row['still_called'] === true ? '' : 'mon-row--muted' }}">
                        <td>
                            <a class="k-truncate" style="display:block;max-inline-size:280px"
                               href="{{ $portal($row['path'], $row['methods'][0] ?? null) }}"
                               title="{{ $row['path'] }} — {{ translate('open_this_endpoint_in_the_developer_portal') }}">{{ $row['path'] }}</a>
                            <small class="mon-metric__note" style="display:block">{{ implode(', ', $row['methods']) }}</small>
                        </td>
                        <td><code>{{ $row['version'] }}</code></td>
                        <td>
                            @if ($row['still_called'] === true)
                                <span class="mon-pill mon-pill--critical">{{ translate('yes') }}</span>
                            @elseif ($row['still_called'] === false)
                                <span class="mon-pill mon-pill--healthy">{{ translate('no_traffic_in_this_window') }}</span>
                            @else
                                {{-- Not "no". Nothing was recorded, so nobody looked — which is the
                                     absence of an answer rather than an answer of no. --}}
                                <span class="mon-metric__state">{{ translate('unknown') }}</span>
                            @endif
                            @if (!empty($row['reason']['note']))
                                <small class="mon-metric__note" style="display:block">{{ $row['reason']['note'] }}</small>
                            @endif
                            @if (!empty($row['note']))
                                <small class="mon-metric__note" style="display:block">{{ $row['note'] }}</small>
                            @endif
                        </td>
                        <td class="k-table__num k-num">
                            @if ($row['hits'] === null)
                                <span class="mon-metric__state">{{ translate('no_traffic') }}</span>
                            @else
                                {{ $count($row['hits']) }}
                            @endif
                        </td>
                        <td class="k-table__num k-num">
                            @if ($row['error_rate'] === null)
                                {{-- An endpoint nobody called has no error rate. A zero here would
                                     be the best possible number attached to no measurement. --}}
                                <span class="mon-metric__state">{{ translate('no_traffic') }}</span>
                            @else
                                {{ $percent($row['error_rate']) }}
                            @endif
                        </td>
                        <td class="k-table__num k-num">
                            @if ($row['p95'] === null)
                                <span class="mon-metric__state">{{ translate('no_traffic') }}</span>
                            @else
                                {{ $ms($row['p95']) }}
                            @endif
                        </td>
                        <td class="k-num">{{ $row['deprecated_since'] ?? '—' }}</td>
                        <td class="k-num">{{ $row['sunset_at'] ?? '—' }}</td>
                        <td>
                            @if ($row['replaced_by'])
                                <code>{{ $row['replaced_by'] }}</code>
                            @else
                                {{-- A deprecation with no replacement named is a caller with
                                     nowhere to go, which is worth seeing as a gap. --}}
                                <span class="mon-metric__state">{{ translate('not_recorded') }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <p class="mon-note {{ ($deprecated['still_called'] ?? 0) > 0 || $deprecated['still_called'] === null ? 'mon-note--critical' : '' }}">
            @if ($deprecated['still_called'] === null)
                {{-- A count of zero here is the sentence that gets an endpoint deleted while its
                     callers are still calling it, so it is withheld rather than assembled out of
                     an unwatched window. --}}
                {{ translate('whether_any_of_these_is_still_being_called_could_not_be_determined_in_this_window') }}
            @else
                {{ $count($deprecated['still_called']) }}
                {{ translate('of_the_deprecated_endpoints_listed_recorded_traffic_in_this_window_and_cannot_be_removed_yet') }}
            @endif
            {{ $deprecated['proof']['note'] }}
        </p>
        @if ($deprecated['truncated'])
            <p class="mon-note">
                {{ translate('more_endpoints_are_documented_as_deprecated_than_this_page_reads') }}:
                {{ $deprecated['limit'] }} {{ translate('shown') }}.
            </p>
        @endif
    @else
        {{-- A build with no deprecations should not wear the same face as one whose manifest could
             not be read: the first is a measurement over every route, the second is a gap. --}}
        <x-k.empty :icon="$deprecated['state'] === 'none_deprecated' ? 'check' : 'alert'"
                   :title="$deprecated['state'] === 'none_deprecated' ? translate('nothing_is_documented_as_deprecated') : $stateTitle($deprecated['state'])"
                   :text="$deprecated['note'] ?? ''" />
        @if (!empty($deprecated['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $deprecated['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- Endpoints nobody called. The count is complete; only the listing is cut — and the proof line
     under it is what stops the list being read as a list of things safe to delete. --}}
<x-k.card :title="translate('documented_endpoints_with_no_traffic_in_this_window')">
    @if ($silent['state'] === 'ok')
        <p class="mon-note {{ $silent['proof']['state'] === 'unproven' ? 'mon-note--critical' : '' }}" style="margin-block-start:0">
            {{ $silent['proof']['note'] }}
        </p>

        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('endpoint') }}</th>
                    <th>{{ translate('methods') }}</th>
                    <th>{{ translate('version') }}</th>
                    <th>{{ translate('group') }}</th>
                    <th>{{ translate('audience') }}</th>
                    <th>{{ translate('authentication') }}</th>
                    <th class="k-table__num">{{ translate('rate_limit') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($silent['rows'] as $row)
                    <tr>
                        <td>
                            <a class="k-truncate" style="display:block;max-inline-size:280px"
                               href="{{ $portal($row['path'], $row['methods'][0] ?? null) }}"
                               title="{{ $row['path'] }} — {{ translate('open_this_endpoint_in_the_developer_portal') }}">{{ $row['path'] }}</a>
                            @if ($row['deprecated'])
                                <span class="mon-pill mon-pill--critical">{{ translate('deprecated') }}</span>
                            @endif
                        </td>
                        <td class="k-num">{{ implode(', ', $row['methods']) }}</td>
                        <td><code>{{ $row['version'] }}</code></td>
                        <td>{{ $row['group'] ?? '—' }}</td>
                        <td>{{ $vocabulary($row['audience'], $row['audience_known']) ?? '—' }}</td>
                        <td>
                            @if ($row['auth_required'] === null)
                                <span class="mon-metric__state">{{ translate('no_data') }}</span>
                            @else
                                {{ $row['auth_required'] ? translate('required') : translate('public') }}
                            @endif
                        </td>
                        <td class="k-table__num k-num">
                            @if ($row['rate_limit'] === null)
                                {{-- Null is "no limit declared", which is not a limit of zero. --}}
                                <span class="mon-metric__state">{{ translate('none_declared') }}</span>
                            @else
                                {{ $count($row['rate_limit']) }}
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <p class="mon-note">
            {{ $count($silent['total']) }} {{ translate('documented_endpoints_recorded_no_request_in_this_window') }}@if ($silent['truncated']), {{ $silent['limit'] }} {{ translate('shown') }}@endif.
            @if ($filters['narrowed'])
                {{ translate('narrowed_to_version') }} <code>{{ $filters['version'] }}</code>.
            @endif
            {{ $silent['window_only'] }}
        </p>
    @else
        <x-k.empty :icon="$silent['state'] === 'all_called' ? 'check' : 'catalog'"
                   :title="$silent['state'] === 'all_called' ? translate('every_documented_endpoint_was_called_in_this_window') : $stateTitle($silent['state'])"
                   :text="$silent['note'] ?? ''" />
        @if (!empty($silent['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $silent['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- The other direction of the same join: traffic with no documentation behind it. --}}
<x-k.card :title="translate('api_traffic_the_manifest_does_not_document')">
    @if ($unmatched['state'] === 'ok')
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('path') }}</th>
                    <th>{{ translate('method') }}</th>
                    <th class="k-table__num">{{ translate('requests') }}</th>
                    <th class="k-table__num">{{ translate('errors') }}</th>
                    <th class="k-table__num">{{ translate('error_rate') }}</th>
                    <th class="k-table__num">p95</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($unmatched['rows'] as $row)
                    <tr>
                        <td>
                            @if ($row['linkable'])
                                <a class="k-truncate" style="display:block;max-inline-size:280px"
                                   href="{{ $portal($row['path'], $row['method']) }}">{{ $row['path'] }}</a>
                            @else
                                <span class="k-truncate" style="display:block;max-inline-size:280px"
                                      title="{{ translate('requests_that_matched_no_route_at_all') }}">{{ $row['path'] }}</span>
                            @endif
                        </td>
                        <td class="k-num">{{ $row['method'] }}</td>
                        <td class="k-table__num k-num">{{ $count($row['hits']) }}</td>
                        <td class="k-table__num k-num">{{ $count($row['errors']) }}</td>
                        <td class="k-table__num k-num">{{ $percent($row['error_rate']) ?? '—' }}</td>
                        <td class="k-table__num k-num">{{ $ms($row['p95']) ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="mon-note">
            {{ $count($unmatched['hits']) }} {{ translate('api_requests_in_this_window_went_to_a_path_the_manifest_does_not_carry') }}
            {{ translate('a_path_here_is_a_route_the_portal_cannot_describe_a_request_that_matched_no_route_at_all_or_an_endpoint_removed_while_callers_still_call_it') }}
            @if ($unmatched['truncated'])
                {{ $unmatched['limit'] }} {{ translate('shown') }}.
            @endif
        </p>
    @else
        <x-k.empty :icon="$unmatched['state'] === 'no_data' ? 'check' : 'alert'"
                   :title="$unmatched['state'] === 'no_data' ? translate('all_api_traffic_matched_a_documented_route') : $stateTitle($unmatched['state'])"
                   :text="$unmatched['note'] ?? ''" />
    @endif
</x-k.card>

<p class="mon-note">
    {{ translate('the_documented_surface_is_read_from') }} <code>ApiManifest</code>,
    {{ translate('derived_from_the_live_route_table_so_a_route_added_today_appears_here_with_no_further_action') }}
    {{ translate('the_traffic_is_read_from') }} <code>monitoring_request_buckets</code>
    (<code>channel=api</code>), {{ translate('folded_per_minute_per_route_pattern_with_percentiles_interpolated_from_the_stored_latency_histogram') }}
    {{ translate('the_two_join_on_the_route_pattern_itself_which_is_why_neither_has_to_know_about_the_other') }}
    {{ translate('window') }}: {{ $window['since'] }} → {{ $window['until'] }} ({{ $window['timezone'] }}).
    <a href="{{ route('admin.developer.index') }}">{{ translate('the_full_documentation_for_every_endpoint_listed_here') }}</a>.
</p>
