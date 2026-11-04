{{--
    Traces: where one request's time actually went.

    The list is only the way in. The page is the waterfall underneath it, and the stacked bar above
    that waterfall is the sentence everybody actually came for — of the 724ms, this many were the
    database, this many an outbound call, and this many everything else.

    Nothing here is computed in the view. Every percentage arrives already positioned, because the
    scale a bar is drawn against is a decision the panel makes with the trace duration and the span
    extent in front of it, not a formatting step.
--}}
@php
    $filters = $panel['filters'];
    $options = $panel['options'];
    $capture = $panel['capture'];
    $summary = $panel['summary'];
    $traceList = $panel['traces'];
    $selected = $panel['selected'] ?? null;

    // Every link carries the window and the filter row: opening a trace must not silently reset
    // the view it was found in, because a filtered trace list is something people paste to each
    // other during an incident.
    $carried = array_filter([
        'range' => $range,
        'captured' => $filters['captured'] === 'all' ? null : $filters['captured'],
        'route' => $filters['route'],
        'min_ms' => $filters['min_ms'] > 0 ? $filters['min_ms'] : null,
    ], static fn ($value) => $value !== null && $value !== '');

    $linkTo = static fn (array $extra = []) => route(
        'admin.monitoring.section',
        array_merge(['section' => 'traces'], $carried, $extra),
    );

    $clearUrl = route('admin.monitoring.section', ['section' => 'traces', 'range' => $range]);

    // A duration that was never recorded is not zero milliseconds, and the two must never print
    // the same way.
    $ms = static fn ($value) => $value === null
        ? translate('not_recorded')
        : ((int) $value >= 1000 ? number_format((int) $value / 1000, 2) . ' s' : number_format((int) $value) . ' ms');

    $count = static fn ($value) => $value === null ? translate('no_data') : number_format((int) $value);

    $ago = static function (?array $moment): string {
        if ($moment === null) {
            return translate('no_data');
        }
        $seconds = (int) $moment['age_seconds'];
        return match (true) {
            $seconds < 90 => $seconds . 's',
            $seconds < 5400 => round($seconds / 60) . 'm',
            $seconds < 172800 => round($seconds / 3600) . 'h',
            default => round($seconds / 86400) . 'd',
        } . ' ' . translate('ago');
    };

    $statusTone = static fn (?int $status) => match (true) {
        $status === null => 'minor',
        $status >= 500 => 'critical',
        $status >= 400 => 'warning',
        default => 'ok',
    };

    // A value that arrived in the URL stays selectable even when the window no longer holds it, so
    // a shared link does not quietly drop half its filter.
    $withCurrent = static function (array $values, ?string $current): array {
        if ($current !== null && !in_array($current, $values, true)) {
            $values[] = $current;
        }
        return $values;
    };

    // translate() writes every key it has not already seen into resources/lang/*/new-messages.php,
    // so a value that came out of a column must never reach it — a span kind wrote 'db' => 'Db'
    // into the Arabic file, and a free-text column would mint one key per distinct value. Only the
    // words this system writes are translated; anything else is shown exactly as it was stored.
    // Typed loosely on purpose: these values arrive as array keys as well as as values, and PHP
    // turns a numeric string key into an int on the way into the array.
    $vocabulary = $panel['vocabulary'];
    $term = static function (int|string|null $value, string $set, string $whenMissing = 'no_data') use ($vocabulary): string {
        $value = $value === null ? '' : trim((string) $value);
        if ($value === '') {
            return translate($whenMissing);
        }
        return in_array($value, $vocabulary[$set], true) ? translate($value) : $value;
    };

    // A truncated grouped read gives a floor rather than a total, and that is true of every tile it
    // feeds — not only the first one.
    $partialCounts = ($summary['state'] ?? '') === 'partial';
@endphp

{{-- Said before anything else: at a 2% sample rate an empty list is the normal state of a healthy
     shop, and with tracing switched off it means nothing at all. --}}
@if (($capture['collection_enabled'] ?? true) === false || ($capture['tracing_enabled'] ?? true) === false)
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--critical">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>
                    {{ ($capture['collection_enabled'] ?? true) === false
                        ? translate('monitoring_collection_is_switched_off')
                        : translate('tracing_is_switched_off') }}
                </strong>
                <small>{{ translate('an_empty_trace_list_here_means_nothing_is_being_recorded_not_that_every_request_was_fast') }}</small>
                @if (!empty($capture['remedy']))
                    <code>{{ $capture['remedy'] }}</code>
                @endif
            </span>
        </div>
    </div>
@endif

<div class="k-stats mon-stats">
    <x-k.stat :label="translate('traces_in_this_window')"
              :value="$summary['state'] === 'unavailable' ? translate('no_data') : $count($summary['traces'])"
              icon="reports"
              :caption="$partialCounts ? translate('at_least_this_many_the_window_holds_more_than_was_counted') : translate('kept_by_the_sampler_not_all_requests')" />

    <x-k.stat :label="translate('kept_because_it_failed')"
              :value="$summary['state'] === 'unavailable' ? translate('no_data') : $count($summary['errors'])"
              icon="alert"
              :caption="$partialCounts
                    ? translate('at_least_this_many_the_window_holds_more_than_was_counted')
                    : (($capture['always_trace_errors'] ?? false) ? translate('every_5xx_is_traced') : translate('errors_are_not_always_traced'))" />

    <x-k.stat :label="translate('kept_because_it_was_slow')"
              :value="$summary['state'] === 'unavailable' ? translate('no_data') : $count($summary['slow'])"
              icon="clock"
              :caption="$partialCounts
                    ? translate('at_least_this_many_the_window_holds_more_than_was_counted')
                    : (($capture['always_trace_slower_than_ms'] ?? null) === null
                        ? translate('no_slow_request_threshold_is_set')
                        : translate('slower_than') . ' ' . number_format($capture['always_trace_slower_than_ms']) . ' ms')" />

    <x-k.stat :label="translate('kept_as_a_sample')"
              :value="$summary['state'] === 'unavailable' ? translate('no_data') : $count($summary['sampled'])"
              icon="sparkles"
              :caption="$partialCounts
                    ? translate('at_least_this_many_the_window_holds_more_than_was_counted')
                    : (isset($capture['sample_pct'])
                        ? translate('sample_rate') . ': ' . $capture['sample_pct'] . '%'
                        : translate('the_sample_rate_could_not_be_read'))" />

    {{-- Under a truncated read this is the slowest of what was counted, not of the window, and the
         two are a different claim. --}}
    <x-k.stat :label="translate('slowest_trace_in_this_window')"
              :value="$summary['state'] === 'unavailable' ? translate('no_data') : $ms($summary['slowest_ms'] ?? null)"
              icon="trend-up"
              :caption="$partialCounts
                    ? translate('at_least_this_the_window_holds_more_than_was_measured')
                    : translate('wall_clock_of_the_whole_request')" />
</div>

@if (($summary['state'] ?? '') === 'unavailable' && !empty($summary['message']))
    <p class="mon-note mon-note--critical">{{ translate('the_trace_summary_could_not_be_read') }}: {{ $summary['message'] }}</p>
@endif

<x-k.card :padded="false">
    {{-- Filter state lives in the URL. Three narrowings, and each one maps to an indexed column:
         why the trace was kept, which route it was, and how slow it had to be. --}}
    <form method="get" class="k-view__toolbar" role="search">
        <input type="hidden" name="range" value="{{ $range }}">

        <div class="k-view__toolbar-grow">
            <select name="captured" class="k-select" aria-label="{{ translate('why_it_was_kept') }}">
                @foreach (['all', 'error', 'slow', 'sampled'] as $reason)
                    <option value="{{ $reason }}" @selected($filters['captured'] === $reason)>
                        {{ $reason === 'all' ? translate('any_reason') : translate($reason) }}
                        @if (isset($options['captured'][$reason]))
                            ({{ number_format($options['captured'][$reason]) }})
                        @endif
                    </option>
                @endforeach
            </select>
        </div>

        <div class="k-view__toolbar-grow">
            <select name="route" class="k-select" aria-label="{{ translate('route') }}">
                <option value="">{{ translate('any_route') }}</option>
                @foreach ($withCurrent($options['routes'], $filters['route']) as $route)
                    <option value="{{ $route }}" @selected($filters['route'] === $route)>{{ $route }}</option>
                @endforeach
            </select>
        </div>

        <div class="k-view__toolbar-grow">
            <input type="number" name="min_ms" class="k-input" min="0" step="50"
                   value="{{ $filters['min_ms'] > 0 ? $filters['min_ms'] : '' }}"
                   placeholder="{{ translate('slower_than_ms') }}"
                   aria-label="{{ translate('slower_than_ms') }}">
        </div>

        <div class="k-view__toolbar-grow">
            {{-- A trace id travels in logs and in error occurrences, so it has to be openable by
                 paste rather than only by clicking a row of this table. --}}
            <div class="k-search">
                <x-k.icon name="search" :size="15" />
                <input type="search" name="trace" class="k-input" value="{{ $filters['trace'] }}"
                       placeholder="{{ translate('open_a_trace_id') }}"
                       aria-label="{{ translate('open_a_trace_id') }}">
            </div>
        </div>

        <div class="k-row">
            <x-k.button type="submit" variant="primary" size="sm" icon="filter">{{ translate('apply') }}</x-k.button>
            <x-k.button :href="$clearUrl" variant="ghost" size="sm">{{ translate('clear') }}</x-k.button>
        </div>
    </form>

    @if ($traceList['state'] === 'unavailable')
        <div class="k-card__body">
            <p class="mon-note mon-note--critical">{{ translate('the_traces_could_not_be_read') }}: {{ $traceList['message'] ?? '' }}</p>
            @if (!empty($traceList['remedy']))
                <details class="mon-metric__remedy" open>
                    <summary>{{ translate('how_to_fix_this') }}</summary>
                    <code>{{ $traceList['remedy'] }}</code>
                </details>
            @endif
        </div>
    @elseif ($traceList['state'] === 'empty')
        <div class="k-card__body">
            @php($reason = $traceList['reason'] ?? 'quiet_window')
            @if ($reason === 'collection_off')
                <x-k.empty icon="alert" :title="translate('monitoring_collection_is_switched_off')"
                           :text="translate('this_page_can_only_show_what_was_recorded_before_it_was_turned_off')" />
            @elseif ($reason === 'tracing_off')
                <x-k.empty icon="settings" :title="translate('tracing_is_switched_off')"
                           :text="translate('spans_are_only_collected_for_requests_the_sampler_chooses_and_it_is_choosing_none')" />
                <details class="mon-metric__remedy" open>
                    <summary>{{ translate('how_to_enable_this') }}</summary>
                    <code>{{ $capture['remedy'] }}</code>
                </details>
            @elseif ($reason === 'never_recorded')
                <x-k.empty icon="info" :title="translate('no_trace_has_ever_been_recorded_on_this_deployment')"
                           :text="translate('tracing_is_enabled_but_nothing_has_reached_the_store_yet_which_is_worth_confirming_if_it_has_been_serving_traffic')" />
                <details class="mon-metric__remedy">
                    <summary>{{ translate('how_traces_reach_this_page') }}</summary>
                    <code>{{ $capture['remedy'] }}</code>
                </details>
            @elseif ($reason === 'beyond_retention')
                <x-k.empty icon="clock" :title="translate('this_window_is_older_than_traces_are_kept')"
                           :text="translate('spans_are_the_largest_table_monitoring_writes_so_they_are_pruned_first')" />
                <p class="mon-note">
                    {{ translate('traces_are_kept_for') }}
                    <span class="k-num">{{ $capture['retention_days'] }}</span> {{ translate('days') }} —
                    {{ translate('choose_a_shorter_range_to_see_them') }}
                </p>
            @elseif ($reason === 'filtered_out')
                <x-k.empty icon="filter" :title="translate('no_trace_matches_these_filters')"
                           :text="translate('the_window_does_hold_traces_they_are_just_not_the_ones_asked_for')">
                    <x-slot:action>
                        <x-k.button :href="$clearUrl" variant="secondary" size="sm">{{ translate('clear_filters') }}</x-k.button>
                    </x-slot:action>
                </x-k.empty>
                @if (!empty($options['captured']))
                    <p class="mon-note">
                        {{ translate('in_this_window') }}:
                        @foreach ($options['captured'] as $reasonKey => $total)
                            {{ number_format($total) }} {{ $term($reasonKey, 'captured') }}@if (!$loop->last), @endif
                        @endforeach
                    </p>
                @endif
            @elseif ($reason === 'unreadable')
                <x-k.empty icon="info" :title="translate('this_window_could_not_be_checked_for_traces')"
                           :text="translate('the_trace_store_did_not_answer_so_an_empty_list_here_proves_nothing')" />
            @else
                {{-- The ordinary one. At this sample rate a quiet list is what a healthy shop looks
                     like, so it is stated plainly rather than dressed as a fault. --}}
                <x-k.empty icon="check" :title="translate('no_trace_was_kept_in_this_window')"
                           :text="translate('nothing_failed_nothing_crossed_the_slow_threshold_and_the_sampler_kept_none_of_the_rest')" />
            @endif
        </div>
    @else
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('trace') }}</th>
                    <th>{{ translate('route') }}</th>
                    <th>{{ translate('method') }}</th>
                    <th class="k-table__num">{{ translate('status') }}</th>
                    <th class="k-table__num">{{ translate('duration') }}</th>
                    <th class="k-table__num">{{ translate('db_ms') }}</th>
                    <th class="k-table__num">{{ translate('queries') }}</th>
                    <th class="k-table__num">{{ translate('outbound_ms') }}</th>
                    <th>{{ translate('client') }}</th>
                    <th>{{ translate('why_it_was_kept') }}</th>
                    <th>{{ translate('started') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($traceList['rows'] as $trace)
                    <tr @if ($trace['is_selected']) aria-selected="true" @endif>
                        <td>
                            <a href="{{ $linkTo(['trace' => $trace['trace_id']]) }}#mon-trace"
                               class="k-num" title="{{ $trace['trace_id'] }}">{{ $trace['short_id'] }}</a>
                        </td>
                        <td>
                            @if (!empty($trace['route']))
                                <a class="k-truncate" style="display:block;max-inline-size:240px"
                                   href="{{ route('admin.developer.lookup', ['path' => $trace['route'], 'method' => $trace['method'] ?? null]) }}"
                                   title="{{ $trace['route'] }} — {{ translate('open_this_endpoint_in_the_developer_portal') }}">{{ $trace['route'] }}</a>
                            @else
                                <span class="k-truncate" style="display:block;max-inline-size:240px">{{ translate('no_route') }}</span>
                            @endif
                        </td>
                        <td>{{ $trace['method'] ?? '—' }}</td>
                        <td class="k-table__num">
                            @if ($trace['status'] === null)
                                <span class="mon-metric__state">{{ translate('not_recorded') }}</span>
                            @else
                                <span class="mon-pill mon-pill--{{ $statusTone($trace['status']) }}">{{ $trace['status'] }}</span>
                            @endif
                        </td>
                        <td class="k-table__num k-num">{{ $ms($trace['duration_ms']) }}</td>
                        <td class="k-table__num k-num">{{ $ms($trace['db_ms']) }}</td>
                        <td class="k-table__num k-num">
                            {{ $trace['db_queries'] === null ? translate('not_recorded') : number_format($trace['db_queries']) }}
                        </td>
                        <td class="k-table__num k-num">{{ $ms($trace['external_ms']) }}</td>
                        <td>
                            {{ $trace['platform'] ?? translate('not_declared') }}
                            @if ($trace['app_version'])
                                <span class="mon-metric__note">{{ $trace['app_version'] }}</span>
                            @endif
                        </td>
                        <td><span class="mon-pill mon-pill--{{ $trace['severity'] }}">{{ $term($trace['captured_because'], 'captured') }}</span></td>
                        <td class="k-num" title="{{ $trace['started_at']['at'] ?? '' }}">{{ $ago($trace['started_at']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        @if ($traceList['capped'])
            <div class="k-card__body">
                <p class="mon-note">
                    {{ translate('the_newest') }} <span class="k-num">{{ number_format($traceList['limit']) }}</span>
                    {{ translate('traces_in_this_window_are_listed_narrow_the_filters_or_the_range_to_reach_the_rest') }}
                </p>
            </div>
        @endif
    @endif

    @if (!empty($options['truncated']))
        <div class="k-card__body">
            <p class="mon-note">{{ translate('this_window_holds_more_filter_values_than_are_listed_above') }}</p>
        </div>
    @endif
</x-k.card>

@if ($selected !== null)
    <x-k.card id="mon-trace" :title="translate('selected_trace')">
        <x-slot:actions>
            <x-k.button :href="$linkTo()" variant="ghost" size="sm" icon="close">{{ translate('close') }}</x-k.button>
        </x-slot:actions>

        @if ($selected['state'] === 'unavailable')
            <p class="mon-note mon-note--critical">{{ translate('this_trace_could_not_be_read') }}: {{ $selected['message'] ?? '' }}</p>
        @elseif ($selected['state'] !== 'ok')
            <x-k.empty icon="clock" :title="translate('this_trace_is_no_longer_stored')"
                       :text="$selected['note'] ?? ''" />
            @if (!empty($selected['remedy']))
                <details class="mon-metric__remedy">
                    <summary>{{ translate('why_this_happened') }}</summary>
                    <code>{{ $selected['remedy'] }}</code>
                </details>
            @endif
        @else
            @php($trace = $selected['trace'])
            @php($split = $selected['split'])
            @php($spans = $selected['spans'])

            <h3 class="mon-heading">
                {{ $trace['method'] ?? '' }} {{ $trace['route'] ?? translate('no_route') }}
            </h3>
            <p class="mon-note">
                <span class="k-num">{{ $trace['trace_id'] }}</span>
                @if ($trace['correlation_id'])
                    · {{ translate('correlation_id') }} <span class="k-num">{{ $trace['correlation_id'] }}</span>
                @endif
            </p>

            <div class="mon-grid">
                <div class="mon-metric">
                    <span class="mon-metric__label">{{ translate('duration') }}</span>
                    <span class="mon-metric__value k-num">{{ $ms($trace['duration_ms']) }}</span>
                    <span class="mon-metric__source">monitoring_traces.duration_ms</span>
                </div>

                <div class="mon-metric">
                    <span class="mon-metric__label">{{ translate('status') }}</span>
                    <span class="mon-metric__value">
                        @if ($trace['status'] === null)
                            <span class="mon-metric__state">{{ translate('not_recorded') }}</span>
                        @else
                            <span class="mon-pill mon-pill--{{ $statusTone($trace['status']) }}">{{ $trace['status'] }}</span>
                        @endif
                        <span class="mon-pill mon-pill--{{ $trace['severity'] }}">{{ $term($trace['captured_because'], 'captured') }}</span>
                    </span>
                    <span class="mon-metric__note">{{ $trace['channel'] ?? translate('no_channel') }}</span>
                </div>

                <div class="mon-metric">
                    <span class="mon-metric__label">{{ translate('database') }}</span>
                    <span class="mon-metric__value k-num">{{ $ms($trace['db_ms']) }}</span>
                    <span class="mon-metric__note">
                        {{ $trace['db_queries'] === null ? translate('not_recorded') : number_format($trace['db_queries']) . ' ' . translate('queries') }}
                    </span>
                </div>

                <div class="mon-metric">
                    <span class="mon-metric__label">{{ translate('outbound_calls') }}</span>
                    <span class="mon-metric__value k-num">{{ $ms($trace['external_ms']) }}</span>
                    <span class="mon-metric__source">monitoring_traces.external_ms</span>
                </div>

                <div class="mon-metric">
                    <span class="mon-metric__label">{{ translate('cache') }}</span>
                    <span class="mon-metric__value k-num">{{ $ms($trace['cache_ms']) }}</span>
                    <span class="mon-metric__source">monitoring_traces.cache_ms</span>
                </div>

                <div class="mon-metric">
                    <span class="mon-metric__label">{{ translate('peak_memory') }}</span>
                    <span class="mon-metric__value k-num">
                        {{ $trace['memory_peak_kb'] === null ? translate('not_recorded') : number_format($trace['memory_peak_kb'] / 1024, 1) . ' MB' }}
                    </span>
                    <span class="mon-metric__source">monitoring_traces.memory_peak_kb</span>
                </div>

                <div class="mon-metric">
                    <span class="mon-metric__label">{{ translate('client') }}</span>
                    <span class="mon-metric__note">{{ $trace['platform'] ?? translate('not_declared') }} {{ $trace['app_version'] ?? '' }}</span>
                    <span class="mon-metric__note">{{ $term($trace['user_type'], 'user_types') }}</span>
                </div>

                <div class="mon-metric">
                    <span class="mon-metric__label">{{ translate('started') }}</span>
                    <span class="mon-metric__value">{{ $trace['started_at']['at'] ?? translate('no_data') }}</span>
                    <span class="mon-metric__note">
                        {{ $ago($trace['started_at']) }}
                        @if ($trace['release'])
                            · {{ translate('release') }} {{ $trace['release'] }}
                        @endif
                    </span>
                </div>
            </div>

            @if (!empty($selected['meta']))
                <p class="mon-note">
                    @foreach ($selected['meta'] as $entry)
                        {{ $term($entry['key'], 'meta') }}: <span class="k-num">{{ $entry['value'] }}</span>@if (!$loop->last) · @endif
                    @endforeach
                </p>
            @endif

            {{-- The answer to "where did the 724ms go", drawn from the request's own counters. --}}
            <h3 class="mon-heading">{{ translate('where_the_time_went') }}</h3>

            @if ($split['state'] === 'ok' || $split['state'] === 'partial')
                <div class="mon-waterfall-split" role="img"
                     aria-label="{{ translate('share_of_the_request_by_kind_of_work') }}">
                    @foreach ($split['segments'] as $segment)
                        @if ($segment['share_pct'] !== null && $segment['share_pct'] > 0)
                            <span class="mon-waterfall-split__part mon-waterfall__bar--{{ $segment['kind'] }}"
                                  style="inline-size: {{ $segment['share_pct'] }}%"
                                  title="{{ $term($segment['kind'], 'kinds') }}: {{ $ms($segment['ms']) }} ({{ $segment['share_pct'] }}%)"></span>
                        @endif
                    @endforeach
                </div>

                <ul class="mon-waterfall-split__legend">
                    @foreach ($split['segments'] as $segment)
                        <li class="mon-waterfall-split__key">
                            <span class="mon-waterfall__swatch mon-waterfall__bar--{{ $segment['kind'] }}" aria-hidden="true"></span>
                            <span>
                                {{ $term($segment['kind'], 'kinds') }}
                                @if ($segment['basis'] === 'remainder')
                                    <small>{{ translate('the_total_minus_everything_measured_above') }}</small>
                                @endif
                            </span>
                            <span class="k-num">
                                @if ($segment['state'] !== 'ok')
                                    <span class="mon-metric__state">{{ translate('not_recorded') }}</span>
                                @else
                                    {{ $ms($segment['ms']) }}
                                    @if ($segment['share_pct'] !== null)
                                        <i>{{ $segment['share_pct'] }}%</i>
                                    @endif
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mon-note mon-note--critical">
                    {{ translate('this_trace_cannot_be_divided_by_kind_of_work') }} — {{ $split['note'] ?? '' }}
                </p>
                <ul class="mon-waterfall-split__legend">
                    @foreach ($split['segments'] as $segment)
                        <li class="mon-waterfall-split__key">
                            <span class="mon-waterfall__swatch mon-waterfall__bar--{{ $segment['kind'] }}" aria-hidden="true"></span>
                            <span>{{ $term($segment['kind'], 'kinds') }}</span>
                            <span class="k-num">
                                @if ($segment['state'] !== 'ok')
                                    <span class="mon-metric__state">{{ translate('not_recorded') }}</span>
                                @else
                                    {{ $ms($segment['ms']) }}
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if (!empty($split['note']) && $split['state'] === 'partial')
                <p class="mon-note">{{ $split['note'] }}</p>
            @endif
            <p class="mon-note">
                @if ($split['state'] === 'ok' || $split['state'] === 'partial')
                    {{ translate('these_shares_come_from_the_requests_own_counters_not_from_adding_the_spans_together_spans_nest_so_summing_them_would_claim_more_time_than_the_request_took') }}
                @else
                    {{ translate('these_figures_are_the_requests_own_counters_they_are_listed_because_they_were_measured_even_though_they_cannot_be_drawn_as_a_division_of_the_whole') }}
                @endif
            </p>

            {{-- The waterfall itself. One row per span, positioned against the axis the panel
                 chose, so a bar can never be drawn past the end of the request. --}}
            <h3 class="mon-heading">{{ translate('span_waterfall') }}</h3>

            @if ($spans['state'] === 'ok')
                <div class="mon-waterfall__axis">
                    <span class="k-num">0 ms</span>
                    <span>
                        {{ $spans['scale']['basis'] === 'trace_duration'
                            ? translate('axis_is_the_full_request_duration')
                            : translate('axis_is_stretched_to_the_last_span_which_finished_after_the_response') }}
                    </span>
                    <span class="k-num">{{ $ms($spans['scale']['total_ms']) }}</span>
                </div>

                <div class="mon-waterfall">
                    @foreach ($spans['rows'] as $span)
                        <div class="mon-waterfall__row">
                            <span class="mon-waterfall__label" style="padding-inline-start: {{ min($span['depth'], 8) * 10 }}px"
                                  title="{{ $span['name'] }}">
                                <span class="mon-waterfall__kind mon-waterfall__bar--{{ $span['kind'] }}">{{ $term($span['kind'], 'kinds') }}</span>
                                <span class="k-truncate">{{ $span['name'] }}</span>
                            </span>

                            <span class="mon-waterfall__track">
                                <span class="mon-waterfall__bar mon-waterfall__bar--{{ $span['kind'] }} {{ $span['failed'] ? 'is-failed' : '' }}"
                                      style="inset-inline-start: {{ $span['left_pct'] }}%; inline-size: {{ $span['width_pct'] }}%"
                                      title="{{ $span['start_offset_ms'] }}–{{ $span['end_offset_ms'] }} ms · {{ $span['share_pct'] }}%"></span>
                            </span>

                            <span class="mon-waterfall__ms k-num" title="{{ translate('starts_at') }} {{ $span['start_offset_ms'] }} ms">
                                {{ $ms($span['duration_ms']) }}
                                @if ($span['widened'])
                                    {{-- The bar was widened to stay visible; the number beside it is
                                         the real one, and this mark says the two disagree. --}}
                                    <i title="{{ translate('drawn_wider_than_it_was_so_it_can_be_seen') }}">*</i>
                                @endif
                            </span>

                            @if ($span['failed'] || !empty($span['attributes']))
                                <span class="mon-waterfall__attrs">
                                    @if ($span['failed'])
                                        <span class="mon-pill mon-pill--critical">{{ translate('failed') }}</span>
                                    @endif
                                    @foreach ($span['attributes'] as $attribute)
                                        <code>{{ $attribute['key'] }}: {{ $attribute['value'] }}</code>
                                    @endforeach
                                </span>
                            @endif
                        </div>
                    @endforeach
                </div>

                @if ($spans['truncated'])
                    <p class="mon-note">
                        {{ translate('only_the_first') }} <span class="k-num">{{ number_format($spans['span_ceiling']) }}</span>
                        {{ translate('spans_of_this_trace_are_drawn') }}
                    </p>
                @endif

                {{-- A census of what was instrumented, deliberately kept apart from the stacked bar
                     above: these totals sum nested spans and so can exceed the request itself. --}}
                <div class="k-table-wrap">
                    <table class="k-table k-table--compact">
                        <thead>
                        <tr>
                            <th>{{ translate('kind_of_work') }}</th>
                            <th class="k-table__num">{{ translate('spans') }}</th>
                            <th class="k-table__num">{{ translate('total_time') }}</th>
                            <th class="k-table__num">{{ translate('slowest') }}</th>
                            <th class="k-table__num">{{ translate('failed') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($spans['by_kind'] as $kind)
                            <tr>
                                <td>
                                    <span class="mon-waterfall__swatch mon-waterfall__bar--{{ $kind['kind'] }}" aria-hidden="true"></span>
                                    {{ $term($kind['kind'], 'kinds') }}
                                </td>
                                <td class="k-table__num k-num">{{ number_format($kind['spans']) }}</td>
                                <td class="k-table__num k-num">{{ $ms($kind['total_ms']) }}</td>
                                <td class="k-table__num k-num">{{ $ms($kind['max_ms']) }}</td>
                                <td class="k-table__num k-num">{{ number_format($kind['failed']) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mon-note">
                    {{ translate('these_totals_count_nested_spans_twice_over_so_they_measure_what_was_instrumented_rather_than_how_the_request_spent_its_time') }}
                </p>
            @elseif ($spans['state'] === 'unavailable')
                <p class="mon-note mon-note--critical">{{ translate('the_spans_could_not_be_read') }}: {{ $spans['message'] ?? '' }}</p>
            @else
                <x-k.empty icon="info" :title="translate('no_span_was_stored_for_this_trace')"
                           :text="$spans['note'] ?? ''" />
                @if (!empty($spans['remedy']))
                    <details class="mon-metric__remedy">
                        <summary>{{ translate('why_this_happened') }}</summary>
                        <code>{{ $spans['remedy'] }}</code>
                    </details>
                @endif
            @endif

            <p class="mon-note">{{ translate('secrets_are_masked_before_a_span_is_stored_and_again_before_it_is_shown') }}</p>
        @endif
    </x-k.card>
@endif

<p class="mon-note">
    {{ translate('every_figure_on_this_page_is_read_from') }} <code>monitoring_traces</code> +
    <code>monitoring_spans</code>,
    {{ translate('window') }}: {{ $panel['window']['since'] }} → {{ $panel['window']['until'] }}
    ({{ $panel['window']['timezone'] }}).
    {{ translate('traces_are_kept_for') }} <span class="k-num">{{ $capture['retention_days'] }}</span> {{ translate('days') }}.
</p>
