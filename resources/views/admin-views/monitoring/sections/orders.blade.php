{{--
    Order integrity: orders that contradict themselves.

    Every count on this page is a count of orders THIS PAGE EXAMINED, and it says so beside each
    one rather than in a footnote. The distinction is the whole section: "no paid order is missing
    its items" and "none of the four hundred orders I read is missing its items" are different
    claims, and only the second one was measured.

    The three things that decide how far a finding can be trusted are drawn, not implied — the
    period each check covered, whether its read stopped at a limit, and the gaps at the foot that
    make two of the checks heuristics. A check that could not run at all is never drawn as a check
    that found nothing.
--}}

@php
    $scope = $panel['scope'];
    $shop = $panel['shop'];
    $volume = $panel['volume'];
    $summary = $panel['summary'];
    $findings = $panel['findings'];
    $thresholds = $panel['thresholds'];
    $gaps = $panel['gaps'];
    $window = $panel['window'];

    $stateTitle = static fn (string $state) => match ($state) {
        'failed' => translate('this_could_not_be_read'),
        'not_configured' => translate('not_configured'),
        'permission_denied' => translate('permission_denied'),
        'not_supported' => translate('not_supported'),
        'collector_offline' => translate('collector_offline'),
        default => translate('no_data'),
    };

    $count = static fn ($value) => $value === null ? null : number_format((float) $value);
    $money = static fn ($value) => $value === null ? null : number_format((float) $value, 2, '.', ',');

    $elapsed = static function ($hours) {
        if ($hours === null) {
            return null;
        }
        $hours = (int) $hours;
        if ($hours === 0) {
            // Not "0 hours". The order is younger than the unit this page measures in, and a zero
            // in an age column reads as a timestamp that could not be worked out.
            return translate('under_an_hour');
        }
        if ($hours < 48) {
            return $hours . ' ' . translate('hours');
        }

        return intdiv($hours, 24) . ' ' . translate('days');
    };

    $severityPill = static fn (string $severity) => match ($severity) {
        'critical' => 'mon-pill--critical',
        'major' => 'mon-pill--warning',
        'minor' => 'mon-pill--info',
        default => 'mon-pill--unknown',
    };

    // A finding with rows is the alarm; a finding that ran and found nothing is the good news; a
    // finding that could not run is neither, and gets the muted treatment rather than the green one.
    $findingTone = static function (array $finding) {
        if ($finding['state'] !== 'ok') {
            return 'mon-card--not_configured';
        }

        return ($finding['count'] ?? 0) > 0
            ? ($finding['severity'] === 'critical' ? 'mon-card--critical' : 'mon-card--degraded')
            : 'mon-card--healthy';
    };

    // A stored status is handed to translate() only when it is one of the values this build writes.
    // translate() persists any key it has not seen into new-messages.php, so a value that came out
    // of a column would mint a language key per distinct value.
    $vocabulary = static fn (string $value, bool $known) => $known ? translate($value) : $value;

    $sortUrl = static fn (string $sort) => route('admin.monitoring.section', [
        'section' => 'orders',
        'range' => $range,
        'sort' => $sort,
    ]);
@endphp

{{-- Before any count: whether the shop's own database could be read at all, and what period the
     checks below actually covered. A number here that was read over seven days while the range
     control says "last 15 minutes" has to say so, or the two disagree on one screen. --}}
<div class="mon-attention">
    @if ($shop['state'] !== 'ok')
        <div class="mon-attention__item mon-attention__item--critical">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('the_shops_own_database_could_not_be_read') }}</strong>
                <small>{{ $shop['note'] ?? $stateTitle($shop['state']) }}</small>
                <small>{{ translate('every_check_on_this_page_reads_the_orders_tables_directly_so_none_of_them_ran') }}</small>
                @if (!empty($shop['remedy']))
                    <code>{{ $shop['remedy'] }}</code>
                @endif
            </span>
        </div>
    @endif

    <div class="mon-attention__item mon-attention__item--info">
        <x-k.icon name="info" :size="16" />
        <span class="mon-attention__body">
            <strong>{{ translate('what_period_these_checks_covered') }}</strong>
            <small>
                {{ $scope['event_since'] }} → {{ $scope['event_until'] }} ({{ $scope['timezone'] }}),
                {{ translate('reading_at_most') }} {{ $count($scope['sample_limit']) }} {{ translate('orders') }}.
                @if ($scope['floor_applied'])
                    {{-- Said out loud: otherwise the range control at the top of the page and the
                         period these numbers cover are two different things without saying so. --}}
                    {{ translate('the_selected_range_is_shorter_than_the_minimum_lookback_so_the_last') }}
                    {{ $scope['floor_days'] }} {{ translate('days_were_read_instead') }}.
                @endif
            </small>
            <small>
                {{ translate('orders_stuck_in_a_status_are_read_over_the_last') }} {{ $scope['standing_days'] }}
                {{ translate('days_whatever_range_is_selected_being_stuck_is_a_condition_now_not_an_event_in_a_window') }}.
            </small>
            <small>{{ $scope['note'] }}</small>
        </span>
    </div>
</div>

{{-- The counts, each rendering its own state so a figure that could not be read is never a zero. --}}
<x-k.card :title="translate('order_integrity_at_a_glance')">
    <div class="mon-grid">
        @foreach ($panel['headline'] as $name => $metric)
            @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($name)])
        @endforeach
    </div>

    <p class="mon-note">
        {{ translate('orders_that_contradict_themselves_is_counted_once_per_order_however_many_checks_it_breaks') }}.
        {{ $summary['amount_note'] }}
        @if (!$summary['orders_implicated_exact'])
            {{ translate('at_least_one_check_stopped_at_its_limit_so_the_figures_above_are_a_floor_rather_than_a_total') }}.
        @endif
    </p>
</x-k.card>

{{-- Ranked by the money behind each finding, because that is what an operator triages by: one paid
     order holding nothing matters more than ninety orders missing an audit row. --}}
<x-k.card :padded="false">
    <form method="get" class="k-view__toolbar">
        <input type="hidden" name="range" value="{{ $range }}">

        <div class="k-view__toolbar-grow">
            <select name="sort" class="k-select" aria-label="{{ translate('sort_by') }}">
                <option value="amount" @selected($panel['sort'] === 'amount')>{{ translate('money_implicated') }}</option>
                <option value="count" @selected($panel['sort'] === 'count')>{{ translate('orders_implicated') }}</option>
            </select>
        </div>

        <div class="k-row">
            <x-k.button type="submit" variant="primary" size="sm" icon="filter">{{ translate('apply') }}</x-k.button>
            <x-k.button :href="$sortUrl('amount')" variant="ghost" size="sm">{{ translate('clear') }}</x-k.button>
        </div>
    </form>

    <div class="k-card__body">
        <h3 class="mon-heading">{{ translate('what_contradicts_itself') }}</h3>

        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('check') }}</th>
                    <th>{{ translate('severity') }}</th>
                    <th class="k-table__num">{{ translate('orders') }}</th>
                    <th class="k-table__num">{{ translate('money_implicated') }}</th>
                    <th>{{ translate('period_read') }}</th>
                    <th>{{ translate('state') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($findings as $finding)
                    <tr class="{{ $finding['state'] === 'ok' && ($finding['count'] ?? 0) > 0 ? '' : 'mon-row--muted' }}">
                        <td>{{ translate($finding['key']) }}</td>
                        <td><span class="mon-pill {{ $severityPill($finding['severity']) }}">{{ translate($finding['severity']) }}</span></td>
                        <td class="k-table__num k-num">
                            @if ($finding['count'] === null)
                                {{-- Not a zero. The check did not look, which is a different fact
                                     from looking and finding none. --}}
                                <span class="mon-metric__state">{{ $stateTitle($finding['state']) }}</span>
                            @else
                                {{ $finding['count_exact'] ? '' : '≥' }}{{ $count($finding['count']) }}
                            @endif
                        </td>
                        <td class="k-table__num k-num">
                            @if ($finding['amount_known'])
                                {{ $money($finding['amount']) }}
                            @elseif (($finding['count'] ?? 0) > 0)
                                <span class="mon-metric__state">{{ translate('not_knowable') }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @if ($finding['scope'] === 'standing')
                                {{ translate('last') }} {{ $scope['standing_days'] }} {{ translate('days') }}
                            @else
                                {{ $scope['event_since'] }} →
                            @endif
                        </td>
                        <td>
                            @if ($finding['state'] === 'ok')
                                <span class="mon-pill {{ ($finding['count'] ?? 0) > 0 ? $severityPill($finding['severity']) : 'mon-pill--healthy' }}">
                                    {{ ($finding['count'] ?? 0) > 0 ? translate('found') : translate('clean') }}
                                </span>
                            @else
                                <span class="mon-pill mon-pill--unknown">{{ $stateTitle($finding['state']) }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <p class="mon-note">
            {{ $count($summary['checks_ran']) }}/{{ $count($summary['checks_total']) }} {{ translate('checks_ran') }};
            @if ($summary['checks_blocked'] > 0)
                {{ $count($summary['checks_blocked']) }} {{ translate('could_not_run_and_say_why_below') }}.
            @else
                {{ translate('none_was_blocked') }}.
            @endif
            {{ translate('a_row_marked_with_a_greater_or_equal_sign_stopped_at_its_limit_and_is_a_floor_rather_than_a_total') }}.
        </p>
    </div>
</x-k.card>

{{-- One card per check: what it found, what it means, and what to do about it. The prose is written
     in the panel and echoed as-is — it is composed at runtime from thresholds and counts, and
     putting it through translate() would mint a language key per value. --}}
@foreach ($findings as $finding)
    <x-k.card :title="translate($finding['key'])">
        <x-slot:actions>
            <span class="mon-pill {{ $severityPill($finding['severity']) }}">{{ translate($finding['severity']) }}</span>
        </x-slot:actions>

        <p class="mon-note {{ $finding['state'] === 'ok' && ($finding['count'] ?? 0) > 0 ? 'mon-note--critical' : '' }}">
            {{ $finding['meaning'] }}
        </p>

        @if ($finding['state'] === 'ok' && !empty($finding['rows']))
            <div class="k-table-wrap">
                <table class="k-table k-table--compact">
                    <thead>
                    <tr>
                        <th>{{ translate('order') }}</th>
                        <th>{{ translate('order_status') }}</th>
                        <th>{{ translate('payment_status') }}</th>
                        <th class="k-table__num">{{ translate('amount') }}</th>
                        <th class="k-table__num">{{ translate('age') }}</th>
                        <th>{{ translate('what_is_wrong') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($finding['rows'] as $row)
                        <tr>
                            <td class="k-num"><code>{{ $row['id'] }}</code></td>
                            <td>{{ $vocabulary($row['order_status'], $row['order_status_known']) }}</td>
                            <td>{{ $vocabulary($row['payment_status'], $row['payment_status_known']) }}</td>
                            <td class="k-table__num k-num">
                                @if ($row['amount'] === null)
                                    <span class="mon-metric__state">{{ translate('not_recorded') }}</span>
                                @else
                                    {{ $money($row['amount']) }}
                                @endif
                            </td>
                            <td class="k-table__num k-num">
                                @if ($row['age_hours'] === null)
                                    <span class="mon-metric__state">{{ translate('not_recorded') }}</span>
                                @else
                                    {{ $elapsed($row['age_hours']) }}
                                @endif
                            </td>
                            <td>
                                <small class="mon-metric__note">{{ $row['detail'] }}</small>
                                @if ($row['created_at'])
                                    <small class="mon-metric__note" style="display:block">
                                        {{ translate('placed') }}: {{ $row['created_at'] }}
                                        @if ($row['updated_at'])
                                            — {{ translate('last_touched') }}: {{ $row['updated_at'] }}
                                        @endif
                                    </small>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <p class="mon-note">
                <strong>{{ translate('what_to_do') }}:</strong> {{ $finding['action'] }}
            </p>

            @if ($finding['truncated'])
                <p class="mon-note">
                    {{ translate('more_orders_match_this_check_than_are_listed') }}:
                    {{ $count($finding['limit']) }} {{ translate('shown') }}.
                </p>
            @endif
        @elseif ($finding['state'] === 'ok')
            <x-k.empty icon="check"
                       :title="translate('nothing_found_by_this_check')"
                       :text="$finding['note'] ?? ''" />
        @else
            <x-k.empty icon="settings"
                       :title="$stateTitle($finding['state'])"
                       :text="$finding['note'] ?? ''" />
            @if ($finding['blocked_by_connection'])
                {{-- The reason is stated once at the top of the page. Repeating it under all six
                     checks would turn one fault into six. --}}
                <p class="mon-note">{{ translate('the_reason_is_at_the_top_of_this_page') }}</p>
            @endif
            @if (!empty($finding['remedy']))
                <details class="mon-metric__remedy">
                    <summary>{{ translate('how_to_enable_this') }}</summary>
                    <code>{{ $finding['remedy'] }}</code>
                </details>
            @endif
            <p class="mon-note">
                <strong>{{ translate('what_it_would_show') }}:</strong> {{ $finding['action'] }}
            </p>
        @endif

        @if (!empty($finding['caveat']))
            <p class="mon-note">{{ $finding['caveat'] }}</p>
        @endif

        <p class="mon-note">
            {{ translate('read_from') }} <code>{{ $finding['source'] }}</code>
            @if (!empty($finding['index']))
                {{ translate('on') }} <code>{{ $finding['index'] }}</code>
            @endif
            @if ($finding['examined'] !== null)
                — {{ translate('checked_over_the') }} {{ $count($finding['examined']) }}
                {{ translate('most_recent_orders_in_the_period') }}@if ($finding['sample_truncated']), {{ translate('which_is_fewer_than_the_period_holds') }}@endif.
            @elseif ($finding['scope'] === 'standing')
                — {{ translate('over_the_last') }} {{ $scope['standing_days'] }} {{ translate('days') }},
                {{ translate('threshold') }}: {{ $thresholds['stuck_order_hours'] }} {{ translate('hours') }}.
            @endif
        </p>
    </x-k.card>
@endforeach

{{-- The denominator. Six broken orders out of eight and six out of eighty thousand are the same
     six rows and completely different news, so the size of the period is drawn beside them. --}}
<x-k.card :title="translate('orders_in_this_period')">
    @if ($volume['state'] === 'ok')
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('order_status') }}</th>
                    <th class="k-table__num">{{ translate('orders') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($volume['rows'] as $row)
                    <tr class="{{ $row['orders'] === 0 ? 'mon-row--muted' : '' }}">
                        <td>{{ $vocabulary($row['status'], $row['status_known']) }}</td>
                        <td class="k-table__num k-num">{{ $count($row['orders']) }}</td>
                    </tr>
                @endforeach
                @if ($volume['other'] > 0)
                    <tr>
                        {{-- Counted rather than folded away: a status outside the vocabulary this
                             build writes is a finding of its own, and it is not translated because
                             it did not come from this page's own list of words. --}}
                        <td>{{ translate('a_status_this_build_does_not_write') }}</td>
                        <td class="k-table__num k-num">{{ $count($volume['other']) }}</td>
                    </tr>
                @endif
                <tr>
                    <td><strong>{{ translate('total') }}</strong></td>
                    <td class="k-table__num k-num"><strong>{{ $count($volume['total']) }}</strong></td>
                </tr>
                </tbody>
            </table>
        </div>
        <p class="mon-note">{{ $volume['amount_note'] }}</p>
    @else
        <x-k.empty icon="orders" :title="$stateTitle($volume['state'])" :text="$volume['note'] ?? ''" />
        @if (!empty($volume['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $volume['remedy'] }}</code>
            </details>
        @endif
    @endif

    <p class="mon-note">
        {{ translate('counted_from') }} <code>{{ $volume['source'] }}</code>
        {{ translate('on') }} <code>{{ $volume['index'] }}</code>,
        {{ $scope['event_since'] }} → {{ $scope['event_until'] }} ({{ $scope['timezone'] }}).
    </p>
</x-k.card>

{{-- Not caveats this page chose to add — facts about the schema that decide how far the findings
     above can be trusted. Drawn as readings with the exact change that would remove each one. --}}
<x-k.card :title="translate('what_this_build_does_not_record_about_an_order')">
    <div class="mon-grid">
        @foreach ($gaps['fields'] as $name => $metric)
            @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($name)])
        @endforeach
    </div>
    <p class="mon-note">{{ $gaps['note'] }}</p>
    <p class="mon-note">{{ $thresholds['note'] }} <code>{{ $thresholds['source'] }}</code></p>
</x-k.card>

<p class="mon-note">
    {{ translate('every_figure_on_this_page_is_read_live_from_the_shops_own_tables') }}:
    <code>orders</code>, <code>order_details</code>, <code>order_status_histories</code>
    ({{ translate('connection') }}: <code>{{ $shop['connection'] ?? '—' }}</code>).
    {{ translate('nothing_here_is_stored_or_measured_by_a_collector_so_there_is_no_history_behind_it_and_no_alert_rule_can_fire_on_it') }}.
    {{ translate('order_timestamps_are_written_by_the_shop_in_its_own_timezone_and_are_shown_here_in') }}
    {{ $window['timezone'] }}.
</p>
