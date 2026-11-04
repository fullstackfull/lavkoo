{{--
    Web performance: what real shoppers experienced, measured in their own browsers.

    Two things are said before any number is drawn, because both change what the numbers mean.

    The first is whether a reading can arrive at all: nothing here is sampled on a timer, so an
    empty page means nobody visited or the beacon is switched off — never "the shop was fast". The
    banner says which, and carries the fix when there is one.

    The second is which figure this is. The published way to read a Core Web Vital is a p75, and
    this store keeps per-minute aggregates rather than one sample per visit, so a p75 cannot be
    computed from it. The band shares ARE exact and are drawn largest; the average is drawn beside
    them with its sample count and is called an average. Nothing on this page is labelled a p75.
--}}

@php
    $window = $panel['window'];
    $collection = $panel['collection'];
    $figure = $panel['figure'];
    $metrics = $panel['metrics'];
    $timeline = $panel['timeline'];

    $stateTitle = static fn (?string $state) => match ($state) {
        'failed' => translate('this_could_not_be_read'),
        'not_configured' => translate('not_configured'),
        'permission_denied' => translate('permission_denied'),
        'not_supported' => translate('not_supported'),
        'collector_offline' => translate('collector_offline'),
        default => translate('no_data'),
    };

    $count = static fn ($value) => $value === null ? null : number_format((float) $value);

    // Trailing zeros removed so a layout shift of exactly 0.1 is not printed as "0.100" — and
    // only ever after the decimal point, because trimming a whole number turns 1,800 ms into 1,8.
    $trim = static function (float $value, int $decimals) {
        $formatted = number_format($value, $decimals, '.', ',');

        return $decimals > 0 ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    };

    // A timing is milliseconds; CLS is a unitless score that was stored multiplied by a thousand
    // and divided back in the panel. The unit is never guessed from the size of the number.
    $figureOf = static function ($value, array $metric) use ($trim) {
        if ($value === null) {
            return null;
        }

        return $metric['unit'] === 'score'
            ? $trim((float) $value, 3)
            : $trim((float) $value, 0) . ' ms';
    };

    $share = static fn ($value) => $value === null ? null : rtrim(rtrim(number_format((float) $value, 1, '.', ','), '0'), '.') . '%';

    // The three band colours are the console's own healthy / degraded / critical tones. They are
    // borrowed from the status-class modifiers because those are the only three the shared bar
    // defines, and a good LCP has to read the same green as every other healthy verdict here.
    $bandPart = static fn (string $band) => match ($band) {
        'good' => 'mon-split__part--2xx',
        'needs_improvement' => 'mon-split__part--4xx',
        default => 'mon-split__part--5xx',
    };

    $bandPill = static fn (string $band) => match ($band) {
        'good' => 'mon-pill--healthy',
        'needs_improvement' => 'mon-pill--warning',
        default => 'mon-pill--critical',
    };

    $collectionTone = match ($collection['state']) {
        'ok' => null,
        'no_data' => 'mon-attention__item--info',
        'failed' => 'mon-attention__item--warning',
        default => 'mon-attention__item--critical',
    };
@endphp

{{-- Whether a shopper's browser can report anything at all, and when one last did. A zero below
     means something completely different depending on this block. --}}
<div class="mon-attention">
    @if ($collectionTone !== null)
        <div class="mon-attention__item {{ $collectionTone }}">
            <x-k.icon name="{{ $collection['state'] === 'no_data' ? 'info' : 'alert' }}" :size="16" />
            <span class="mon-attention__body">
                <strong>
                    @if ($collection['state'] === 'no_data')
                        {{ translate('no_browser_has_reported_a_web_vital_yet') }}
                    @elseif ($collection['state'] === 'failed')
                        {{ translate('the_stored_web_vitals_could_not_be_read') }}
                    @else
                        {{ translate('nothing_a_shopper_measures_can_reach_this_page_right_now') }}
                    @endif
                </strong>
                <small>{{ $collection['note'] ?? $stateTitle($collection['state']) }}</small>
                @if (!empty($collection['remedy']))
                    <code>{{ $collection['remedy'] }}</code>
                @endif
            </span>
        </div>
    @endif

    {{-- Drawn whatever the state: it is the definition of every figure below, not a fault. --}}
    <div class="mon-attention__item mon-attention__item--info">
        <x-k.icon name="info" :size="16" />
        <span class="mon-attention__body">
            <strong>{{ translate('what_this_page_shows') }}</strong>
            <small>{{ $figure['why'] }}</small>
            <small>
                {{ translate('measured_in_the_shoppers_own_browser_and_posted_back_once_as_the_page_closes') }}
                — {{ translate('last_reading_received') }}:
                {{ $collection['last_reading_at'] ?? translate('never') }}
                @if ($collection['last_reading_at'])
                    ({{ $window['timezone'] }})
                @endif
            </small>
            <code>{{ $collection['beacon'] }} → {{ $collection['recorder'] }}</code>
        </span>
    </div>
</div>

{{-- The counts, each rendering its own state so a share nobody could read is never a zero. --}}
<x-k.card :title="translate('what_shoppers_experienced')">
    @if (!empty($panel['headline']))
        <div class="mon-grid">
            @foreach ($panel['headline'] as $name => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($name)])
            @endforeach
        </div>
    @else
        <x-k.empty icon="customers" :title="$stateTitle($metrics['state'])" :text="$metrics['note'] ?? ''" />
    @endif

    <p class="mon-note">
        {{ translate('the_share_is_the_proportion_of_readings_that_landed_in_the_good_band_which_this_store_can_count_exactly') }}.
        {{ translate('window') }}: {{ $window['since'] }} → {{ $window['until'] }} ({{ $window['timezone'] }}).
    </p>
</x-k.card>

{{-- One card per metric: the band split first because it is the exact figure, the average beside it
     with the number of readings it was taken from, then the pages that fared worst. --}}
@if ($metrics['state'] === 'failed' || $metrics['rows'] === [])
    <x-k.card :title="translate('web_vitals')">
        <x-k.empty icon="trend-up" :title="$stateTitle($metrics['state'])" :text="$metrics['note'] ?? ''" />
        @if (!empty($metrics['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $metrics['remedy'] }}</code>
            </details>
        @endif
    </x-k.card>
@else
    @foreach ($metrics['rows'] as $metric)
        @php
            $pages = $metric['pages'];
            // Translated only when this build has wording for the key: a metric the recorder grows
            // arrives here as a stored column value, and translate() would mint a language entry
            // from it. Such a metric is titled with its stored key instead.
            $metricTitle = $metric['label_key']
                ? translate($metric['label_key']) . ' (' . $metric['abbreviation'] . ')'
                : $metric['key'];
        @endphp
        <x-k.card :title="$metricTitle">

            @if ($metric['description'])
                <p class="mon-note" style="margin-block-start:0">{{ $metric['description'] }}</p>
            @endif

            @if ($metric['state'] === 'ok')
                <div class="mon-split" role="img" aria-label="{{ translate('share_of_readings_by_band') }}">
                    @foreach ($metric['bands'] as $band)
                        @if (($band['share_pct'] ?? 0) > 0)
                            <span class="mon-split__part {{ $bandPart($band['band']) }}"
                                  style="inline-size: {{ $band['share_pct'] }}%"
                                  title="{{ translate($band['band']) }}: {{ $count($band['count']) }} ({{ $share($band['share_pct']) }})"></span>
                        @endif
                    @endforeach
                </div>

                <ul class="mon-split__legend">
                    @foreach ($metric['bands'] as $band)
                        <li class="mon-split__key">
                            <span class="mon-split__swatch {{ $bandPart($band['band']) }}" aria-hidden="true"></span>
                            <span>{{ translate($band['band']) }}</span>
                            <span class="k-num">
                                {{ $count($band['count']) ?? '—' }}<i>{{ $share($band['share_pct']) ?? translate('no_data') }}</i>
                            </span>
                        </li>
                    @endforeach
                </ul>

                <p class="mon-note">
                    {{-- The average is stated as an average every time it is drawn. Left unqualified
                         beside a band share it would be read as the p75 the standard asks for. --}}
                    {{ translate('average_of_the_readings_not_a_p75') }}:
                    <strong class="k-num">{{ $figureOf($metric['average'], $metric) ?? translate('no_data') }}</strong>
                    — {{ translate('readings') }}: {{ $count($metric['readings']) ?? translate('no_data') }},
                    {{ translate('rated_into_a_band') }}: {{ $count($metric['rated']) ?? translate('no_data') }}.
                    {{ translate('good_at_or_below') }} {{ $figureOf($metric['thresholds']['good'], $metric) }},
                    {{ translate('poor_above') }} {{ $figureOf($metric['thresholds']['poor'], $metric) }}.
                    @if ($metric['unit_note'])
                        {{ $metric['unit_note'] }}
                    @endif
                    @if ($metric['counts_agree'] === false)
                        {{ translate('the_timings_and_the_band_counters_disagree_for_this_window_both_are_shown_rather_than_reconciled') }}.
                    @endif
                </p>

                @if ($pages['state'] === 'ok' && !empty($pages['rows']))
                    <h3 class="mon-heading">{{ translate('pages_with_the_largest_share_of_poor_readings') }}</h3>

                    @unless ($pages['any_poor'])
                        <p class="mon-note">
                            {{ translate('no_page_reported_a_poor_reading_in_this_window_so_the_list_is_ordered_by_volume_instead') }}.
                        </p>
                    @endunless

                    <div class="k-table-wrap">
                        <table class="k-table k-table--compact">
                            <thead>
                            <tr>
                                <th>{{ translate('page') }}</th>
                                <th class="k-table__num">{{ translate('rated') }}</th>
                                <th class="k-table__num">{{ translate('good') }}</th>
                                <th class="k-table__num">{{ translate('needs_improvement') }}</th>
                                <th class="k-table__num">{{ translate('poor') }}</th>
                                <th class="k-table__num">{{ translate('poor_share') }}</th>
                                <th class="k-table__num">{{ translate('average') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($pages['rows'] as $page)
                                <tr class="{{ ($page['poor'] ?? 0) > 0 ? '' : 'mon-row--muted' }}">
                                    {{-- A normalised path pattern read out of a column: printed, never translated. --}}
                                    <td><code>{{ $page['path'] }}</code></td>
                                    <td class="k-table__num k-num">{{ $count($page['rated']) ?? '—' }}</td>
                                    <td class="k-table__num k-num">{{ $count($page['good']) ?? '—' }}</td>
                                    <td class="k-table__num k-num">{{ $count($page['needs_improvement']) ?? '—' }}</td>
                                    <td class="k-table__num k-num">{{ $count($page['poor']) ?? '—' }}</td>
                                    <td class="k-table__num k-num">
                                        @if ($page['poor_share_pct'] === null)
                                            <span class="mon-metric__state">{{ translate('no_data') }}</span>
                                        @else
                                            <span class="mon-pill {{ $bandPill($page['poor_share_pct'] > 0 ? 'poor' : 'good') }}">{{ $share($page['poor_share_pct']) }}</span>
                                        @endif
                                    </td>
                                    <td class="k-table__num k-num">{{ $figureOf($page['average'], $metric) ?? '—' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    <p class="mon-note">
                        {{ translate('a_share_taken_from_a_handful_of_visits_moves_on_every_visit_the_count_beside_it_is_what_says_whether_it_means_anything') }}.
                        @if ($pages['truncated'])
                            {{ translate('more_pages_reported_this_metric_than_are_listed') }}: {{ $pages['limit'] }} {{ translate('shown') }}.
                        @endif
                    </p>
                @else
                    <p class="mon-note">{{ $pages['note'] ?? $stateTitle($pages['state']) }}</p>
                @endif
            @else
                <x-k.empty icon="customers" :title="$stateTitle($metric['state'])" :text="$metric['note'] ?? ''" />
                <p class="mon-note">
                    {{ translate('good_at_or_below') }} {{ $figureOf($metric['thresholds']['good'], $metric) }},
                    {{ translate('poor_above') }} {{ $figureOf($metric['thresholds']['poor'], $metric) }}
                    — {{ translate('the_published_thresholds_this_metric_is_scored_against') }}.
                    @if ($metric['unit_note'])
                        {{ $metric['unit_note'] }}
                    @endif
                </p>
            @endif
        </x-k.card>
    @endforeach
@endif

{{-- Readings over the window. What a line is good for here is showing whether shoppers kept
     arriving and whether what they got changed while they did. --}}
<x-k.card :title="translate('readings_received_over_time')">
    @if ($timeline['state'] === 'ok')
        <div class="mon-chart" data-mon-chart='@json(['points' => $timeline['points']])'></div>
        @if ($timeline['truncated'])
            <p class="mon-note mon-note--critical">
                {{ translate('this_window_holds_more_buckets_than_the_chart_reads_so_the_line_ends_before_the_window_does') }}
            </p>
        @endif
        <p class="mon-note">
            {{ translate('the_line_is_rated_readings_per_bucket_the_second_line_is_the_readings_that_landed_in_the_poor_band') }},
            {{ translate('counted_across_every_metric') }} — <code>{{ $timeline['source'] }}</code>,
            {{ translate('resolution') }}: {{ translate($window['resolution']) }}.
        </p>
    @else
        <x-k.empty icon="trend-up" :title="$stateTitle($timeline['state'])" :text="$timeline['note'] ?? ''" />
    @endif
</x-k.card>

{{-- The five things somebody could otherwise read into this page that are not true. Panel-authored
     English, printed as written: they name files and settings, which no translation should touch. --}}
<x-k.card :title="translate('what_these_numbers_are_not')">
    <ul class="mon-note">
        @foreach ($panel['caveats'] as $caveat)
            <li>{{ $caveat['text'] }}</li>
        @endforeach
    </ul>
</x-k.card>

{{-- Normally empty. A series the recorder writes and this page draws nowhere is indistinguishable
     from one nobody ever measured, so it is named rather than dropped. --}}
@if (!empty($panel['unrendered']))
    <p class="mon-note">
        {{ translate('the_store_also_holds_web_vital_series_this_page_does_not_draw') }}:
        @foreach ($panel['unrendered'] as $series)
            <code>{{ $series['metric'] }}</code>{{ $loop->last ? '' : ',' }}
        @endforeach
    </p>
@endif

<p class="mon-note">
    {{ translate('every_figure_on_this_page_is_read_from') }} <code>monitoring_series</code>
    (<code>web.vitals.*</code>), {{ translate('written_only_by') }} <code>{{ $collection['recorder'] }}</code>
    {{ translate('when_a_shoppers_browser_posts_to') }} <code>/{{ ltrim($collection['endpoint'], '/') }}</code>.
    {{ translate('all_timestamps_are_shown_in') }} {{ $window['timezone'] }}.
</p>
