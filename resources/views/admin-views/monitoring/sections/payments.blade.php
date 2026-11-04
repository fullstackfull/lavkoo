{{--
    Payments: what the shop recorded taking, and everywhere the record contradicts itself.

    Two halves that must not be read as one, so they are drawn apart. The top half is VOLUME — the
    payment events analytics writes, folded by gateway. The bottom half is INTEGRITY — nine
    reconciliations, each of which is a defect when it returns a row.

    The banner above both exists for one number. Until the change that ships with this page, a
    failed payment was recorded nowhere at all: digital_payment_fail() had an empty body, so a
    declined card and an abandoned tab left byte-identical rows. A success rate computed over a
    window that reaches back past the first recorded failure would divide by a denominator that
    does not exist, and would come out near 100% at exactly the moment a gateway was failing. So
    the panel refuses to compute one there, and this page says why instead of showing a figure.

    Money and counts are echoed, never translated: gateway names, currency codes, references,
    notes and remedies are database values or runtime-composed English, and translate() persists
    any key it has not seen into new-messages.php.
--}}

@php
    $window = $panel['window'];
    $scope = $panel['scope'];
    $recording = $panel['recording'];
    $gateways = $panel['gateways'];
    $volume = $panel['volume'];
    $timeline = $panel['timeline'];
    $rate = $panel['rate'];
    $findings = $panel['findings'];
    $declines = $panel['declines'];
    $unrecorded = $panel['unrecorded'];
    $scans = $panel['scans'];

    $stateTitle = static fn (string $state) => match ($state) {
        'failed' => translate('this_could_not_be_read'),
        'not_configured' => translate('not_configured'),
        'permission_denied' => translate('permission_denied'),
        'not_supported' => translate('not_supported'),
        'collector_offline' => translate('collector_offline'),
        default => translate('no_data'),
    };

    $count = static fn ($value) => $value === null ? null : number_format((float) $value);

    // Trailing zeros removed so a rate of exactly sixty is not printed as "60.0".
    $trim = static fn ($value, int $decimals) => $value === null
        ? null
        : rtrim(rtrim(number_format((float) $value, $decimals, '.', ','), '0'), '.');

    $amount = static fn ($value) => $value === null ? null : number_format((float) $value, 2, '.', ',');

    // A list of {currency, amount} pairs, summed per currency and never across them.
    $money = static function (array $entries) use ($amount) {
        $parts = [];
        foreach ($entries as $entry) {
            $parts[] = $amount($entry['amount']) . ($entry['currency'] ? ' ' . $entry['currency'] : '');
        }

        return $parts === [] ? null : implode(' · ', $parts);
    };

    $kindPill = static fn (string $kind) => match ($kind) {
        'payment_request' => 'mon-pill--critical',
        'settlement' => 'mon-pill--warning',
        'refund' => 'mon-pill--info',
        default => 'mon-pill--unknown',
    };

    $findingPill = static function (array $finding) {
        if ($finding['state'] !== 'ok') {
            return 'mon-pill--unknown';
        }

        return $finding['count'] > 0 ? 'mon-pill--critical' : 'mon-pill--healthy';
    };

    $scanPill = static fn (string $state) => match ($state) {
        'ok' => 'mon-pill--healthy',
        'not_supported' => 'mon-pill--warning',
        default => 'mon-pill--unknown',
    };

    $findingsFound = 0;
    $findingsBlocked = 0;
    foreach ($findings as $finding) {
        if ($finding['state'] === 'ok') {
            $findingsFound += $finding['count'];
        } else {
            $findingsBlocked++;
        }
    }
@endphp

{{-- Before any figure: whether a rate has a denominator, what this page reconciles, and whether
     a gateway is live in test mode. Each of these changes what every number below means. --}}
<div class="mon-attention">
    @if ($recording['can_compute_rate'] !== true)
        <div class="mon-attention__item {{ $recording['state'] === 'failed' ? 'mon-attention__item--critical' : 'mon-attention__item--warning' }}">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('no_payment_success_rate_is_shown_for_this_range') }}</strong>
                <small>{{ $recording['note'] ?? $stateTitle($recording['state']) }}</small>
                <small>
                    {{ translate('failure_hook') }}: <code>{{ $recording['failure_hook'] }}</code> —
                    {{ translate('first_recorded_failure') }}:
                    {{ $recording['first_failure_at'] ?? translate('never') }}
                    @if ($recording['first_failure_at']) ({{ $window['timezone'] }}) @endif
                </small>
                @if (!empty($recording['remedy']))
                    <code>{{ $recording['remedy'] }}</code>
                @endif
            </span>
        </div>
    @endif

    @if (!empty($gateways['test_active']))
        <div class="mon-attention__item mon-attention__item--critical">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('a_payment_gateway_is_switched_on_in_test_mode') }}</strong>
                <small>{{ translate('a_gateway_in_test_mode_accepts_checkouts_and_takes_no_real_money_the_orders_it_produces_look_paid') }}.</small>
                <small>
                    @foreach ($gateways['test_active'] as $gateway)<code>{{ $gateway }}</code>{{ $loop->last ? '' : ', ' }}@endforeach
                </small>
            </span>
        </div>
    @endif

    {{-- Drawn whatever the state: it is the definition of every finding below, not a fault. --}}
    <div class="mon-attention__item mon-attention__item--info">
        <x-k.icon name="info" :size="16" />
        <span class="mon-attention__body">
            <strong>{{ translate('what_this_page_reconciles') }}</strong>
            {{-- Sentences lead with their translated fragment and end with the number. translate()
                 ucfirsts on English, so a key placed mid-sentence prints a capital where a lower
                 case letter belongs. --}}
            <small>
                {{ translate('the_orders_created_inside_the_selected_window_newest_first_up_to_a_cap_of') }}
                {{ $count($scope['limit']) }}.
                {{ translate('examined_here') }}: {{ $count($scope['orders_examined']) ?? translate('no_data') }}.
                {{ translate('an_order_created_before_this_window_is_not_on_this_page_however_it_behaved_inside_it') }}.
            </small>
            <small>
                {{ translate('read_on_the_index') }} <code>{{ $scope['index'] }}</code>.
                {{ translate('every_other_table_on_this_page_is_looked_up_by_that_sample_because_none_of_them_carries_an_index_a_read_could_be_bounded_on') }}.
            </small>
            <small>{{ $rate['caveat'] }}</small>
        </span>
    </div>
</div>

{{-- The counts, each rendering its own state so a number that could not be read is never a zero. --}}
<x-k.card :title="translate('payments_at_a_glance')">
    @if (!empty($panel['headline']))
        <div class="mon-grid">
            @foreach ($panel['headline'] as $name => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($name)])
            @endforeach
        </div>
    @else
        <x-k.empty icon="reports" :title="$stateTitle($scope['state'])" :text="$scope['note'] ?? ''" />
    @endif

    <p class="mon-note">
        {{ translate('window') }}: {{ $window['since'] }} → {{ $window['until'] }} ({{ $window['timezone'] }}),
        {{ translate('resolution') }} {{ translate($window['resolution']) }}.
        {{ translate('orders_payments_and_payment_events_are_written_by_the_shop_in') }}
        <code>{{ $window['shop_timezone'] }}</code>,
        {{ translate('and_every_bound_on_this_page_is_converted_into_that_clock_before_it_is_queried') }}.
    </p>
</x-k.card>

{{-- Volume: what was recorded, per gateway. Never a rate — that is the card below, which knows
     whether a denominator exists for this window. --}}
<x-k.card :title="translate('recorded_payment_events')">
    @if ($volume['state'] === 'ok')
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('gateway') }}</th>
                    <th>{{ translate('kind') }}</th>
                    <th class="k-table__num">{{ translate('started') }}</th>
                    <th class="k-table__num">{{ translate('succeeded') }}</th>
                    <th class="k-table__num">{{ translate('failed') }}</th>
                    <th class="k-table__num">{{ translate('captured') }}</th>
                    <th class="k-table__num">{{ translate('value_of_failed_attempts') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($volume['rows'] as $row)
                    <tr class="{{ $row['kind'] === 'offline' ? 'mon-row--muted' : '' }}">
                        <td><code>{{ $row['gateway'] ?? '—' }}</code></td>
                        <td>
                            {{-- 'gateway', 'offline' and 'unknown' are this panel's own vocabulary,
                                 so they may be translated. The gateway name beside them may not. --}}
                            <span class="mon-pill {{ $row['kind'] === 'gateway' ? 'mon-pill--info' : 'mon-pill--unknown' }}">{{ translate($row['kind']) }}</span>
                        </td>
                        <td class="k-table__num k-num">
                            @if ($row['started'] === 0 && !$recording['starts_recorded'])
                                {{-- Not a zero. Nothing emits payment_started on this deployment. --}}
                                <span class="mon-metric__state">{{ translate('not_recorded') }}</span>
                            @else
                                {{ $count($row['started']) }}
                            @endif
                        </td>
                        <td class="k-table__num k-num">{{ $count($row['succeeded']) }}</td>
                        <td class="k-table__num k-num">{{ $count($row['failed']) }}</td>
                        <td class="k-table__num k-num">{{ $money($row['captured']) ?? '—' }}</td>
                        <td class="k-table__num k-num">{{ $money($row['declined_value']) ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <p class="mon-note">
            {{ translate('recorded_in_this_window') }}:
            {{ $count($volume['totals']['succeeded']) }} {{ translate('payments_succeeded') }},
            {{ $count($volume['totals']['failed']) }} {{ translate('failed') }},
            {{ $money($volume['totals']['captured']) ?? translate('no_data') }} {{ translate('captured') }}.
            {{ translate('cash_wallet_and_offline_payments_among_those_successes') }}:
            {{ $count($volume['totals']['offline_succeeded']) }}.
            @if ($volume['totals']['unclassified_succeeded'] > 0)
                {{ translate('successes_on_a_payment_method_this_deployment_does_not_configure_as_a_gateway_counted_as_neither') }}:
                {{ $count($volume['totals']['unclassified_succeeded']) }}.
            @endif
            @if ($volume['truncated'])
                {{ translate('this_window_holds_more_distinct_gateways_than_are_listed') }}.
            @endif
        </p>
    @else
        <x-k.empty icon="reports" :title="$stateTitle($volume['state'])" :text="$volume['note'] ?? ''" />
        @if (!empty($volume['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $volume['remedy'] }}</code>
            </details>
        @endif
    @endif

    @if ($timeline['state'] === 'ok')
        <h3 class="mon-heading">{{ translate('succeeded_and_failed_over_the_window') }}</h3>
        <div class="mon-chart" data-mon-chart='@json(['points' => $timeline['points']])'></div>
        <p class="mon-note">
            {{ translate('the_line_is_recorded_successes_the_second_line_is_recorded_failures') }}.
            {{ translate('failures_only_exist_from') }}
            {{ $recording['first_failure_at'] ?? translate('never') }}
            {{ translate('onward_so_an_empty_failure_line_before_that_moment_is_the_absence_of_recording') }}.
        </p>
    @else
        <p class="mon-note">{{ $timeline['note'] ?? $stateTitle($timeline['state']) }}</p>
    @endif
</x-k.card>

{{-- The rate, or the reason there is none. Never both, and never a figure with a missing half. --}}
<x-k.card :title="translate('payment_success_rate')">
    @if ($rate['state'] === 'ok')
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('gateway') }}</th>
                    <th class="k-table__num">{{ translate('succeeded') }}</th>
                    <th class="k-table__num">{{ translate('failed') }}</th>
                    <th class="k-table__num">{{ translate('settled_attempts') }}</th>
                    <th class="k-table__num">{{ translate('success_rate') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($rate['rows'] as $row)
                    <tr>
                        <td><code>{{ $row['gateway'] ?? '—' }}</code></td>
                        <td class="k-table__num k-num">{{ $count($row['succeeded']) }}</td>
                        <td class="k-table__num k-num">{{ $count($row['failed']) }}</td>
                        <td class="k-table__num k-num">{{ $count($row['settled']) }}</td>
                        <td class="k-table__num k-num">
                            @if ($row['success_rate'] === null)
                                {{-- Zero settled attempts is not a zero rate. It is no rate. --}}
                                <span class="mon-metric__state">{{ translate('no_data') }}</span>
                            @else
                                {{ $trim($row['success_rate'], 1) }}%
                            @endif
                        </td>
                    </tr>
                @endforeach
                <tr>
                    <td><strong>{{ translate('all_gateways') }}</strong></td>
                    <td class="k-table__num k-num">{{ $count($rate['succeeded']) }}</td>
                    <td class="k-table__num k-num">{{ $count($rate['failed']) }}</td>
                    <td class="k-table__num k-num">{{ $count($rate['settled']) }}</td>
                    <td class="k-table__num k-num">
                        @if ($rate['rate'] === null)
                            <span class="mon-metric__state">{{ translate('no_data') }}</span>
                        @else
                            <strong>{{ $trim($rate['rate'], 1) }}%</strong>
                        @endif
                    </td>
                </tr>
                </tbody>
            </table>
        </div>

        <p class="mon-note">{{ $rate['basis'] }}</p>
        <p class="mon-note">
            {{ translate('cash_wallet_and_offline_payments_are_excluded_they_cannot_fail_through_the_gateway_hook_so_counting_them_could_only_push_this_figure_upwards') }} —
            {{ translate('excluded_here') }}: {{ $count($rate['excluded_offline']) }}.
            @if ($rate['excluded_unclassified'] > 0)
                {{ translate('also_excluded_rather_than_assumed_to_be_a_gateway_successes_on_a_method_this_deployment_does_not_configure') }}:
                {{ $count($rate['excluded_unclassified']) }}.
            @endif
        </p>
        <p class="mon-note mon-note--critical">{{ $rate['caveat'] }}</p>
    @else
        <x-k.empty icon="alert" :title="$stateTitle($rate['state'])" :text="$rate['note'] ?? ''" />
        @if (!empty($rate['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $rate['remedy'] }}</code>
            </details>
        @endif
        <p class="mon-note">{{ $rate['basis'] }}</p>
        <p class="mon-note">{{ $rate['caveat'] }}</p>
    @endif
</x-k.card>

{{-- Which gateways exist at all. This is also the list that decides whether a payment method
     counts towards the rate above, so it is shown rather than assumed. --}}
<x-k.card :title="translate('configured_payment_gateways')">
    @if ($gateways['state'] === 'ok' && !empty($gateways['rows']))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('gateway') }}</th>
                    <th>{{ translate('enabled') }}</th>
                    <th>{{ translate('mode') }}</th>
                    <th class="k-table__num">{{ translate('succeeded_in_window') }}</th>
                    <th class="k-table__num">{{ translate('failed_in_window') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($gateways['rows'] as $row)
                    <tr class="{{ $row['active'] ? '' : 'mon-row--muted' }}">
                        <td><code>{{ $row['gateway'] }}</code></td>
                        <td>
                            <span class="mon-pill {{ $row['active'] ? 'mon-pill--healthy' : 'mon-pill--unknown' }}">
                                {{ $row['active'] ? translate('yes') : translate('no') }}
                            </span>
                        </td>
                        <td>
                            @if ($row['mode'] === null)
                                <span class="mon-metric__state">{{ translate('not_recorded') }}</span>
                            @else
                                {{-- Only 'live' and 'test' are ours to translate; anything else in
                                     the column is somebody's own value and is echoed as stored. --}}
                                <span class="mon-pill {{ $row['mode'] === 'test' && $row['active'] ? 'mon-pill--critical' : 'mon-pill--info' }}">
                                    {{ $row['mode_known'] ? translate($row['mode']) : $row['mode'] }}
                                </span>
                            @endif
                        </td>
                        <td class="k-table__num k-num">
                            @if ($row['succeeded'] === null)
                                <span class="mon-metric__state">{{ translate('no_data') }}</span>
                            @else
                                {{ $count($row['succeeded']) }}
                            @endif
                        </td>
                        <td class="k-table__num k-num">
                            @if ($row['failed'] === null)
                                <span class="mon-metric__state">{{ translate('no_data') }}</span>
                            @else
                                {{ $count($row['failed']) }}
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <p class="mon-note">
            {{ translate('gateways_enabled_in_live_mode') }}: {{ $count($gateways['live_count']) }}.
            {{ translate('read_from') }} <code>{{ $gateways['source'] }}</code>.
            @if ($gateways['truncated'])
                {{ translate('more_gateways_are_configured_than_are_listed') }}.
            @endif
        </p>
    @else
        <x-k.empty icon="settings" :title="$stateTitle($gateways['state'])" :text="$gateways['note'] ?? ''" />
        @if (!empty($gateways['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $gateways['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- The one gateway in this build that writes a row whichever way the attempt went. It is the
     only independent decline record that exists, and it speaks for PayTabs alone. --}}
<x-k.card :title="translate('paytabs_attempts')">
    @if ($declines['state'] === 'ok')
        <div class="mon-grid">
            <div class="mon-metric">
                <span class="mon-metric__label">{{ translate('approved') }}</span>
                <span class="mon-metric__value k-num">{{ $count($declines['approved']) }}</span>
                <span class="mon-metric__source">{{ $declines['source'] }}</span>
            </div>
            <div class="mon-metric {{ $declines['declined'] > 0 ? 'mon-metric--warning' : '' }}">
                <span class="mon-metric__label">{{ translate('declined') }}</span>
                <span class="mon-metric__value k-num">{{ $count($declines['declined']) }}</span>
                <span class="mon-metric__source">{{ $declines['source'] }}</span>
            </div>
            <div class="mon-metric">
                <span class="mon-metric__label">{{ translate('decline_rate') }}</span>
                @if ($declines['decline_rate'] === null)
                    <span class="mon-metric__state">{{ translate('no_data') }}</span>
                @else
                    <span class="mon-metric__value k-num">{{ $trim($declines['decline_rate'], 1) }}<i>%</i></span>
                @endif
                <span class="mon-metric__source">{{ $declines['source'] }}</span>
            </div>
        </div>

        @if (!empty($declines['codes']))
            <div class="k-table-wrap">
                <table class="k-table k-table--compact">
                    <thead>
                    <tr>
                        <th>{{ translate('paytabs_response_code') }}</th>
                        <th class="k-table__num">{{ translate('attempts') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($declines['codes'] as $code)
                        <tr>
                            <td class="k-num"><code>{{ $code['code'] }}</code></td>
                            <td class="k-table__num k-num">{{ $count($code['attempts']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <p class="mon-note">
            {{ translate('paytabs_is_the_only_gateway_in_this_build_that_writes_a_row_for_a_failed_attempt') }}.
            {{ translate('these_figures_cover_paytabs_and_no_other_gateway') }}.
            @if ($declines['truncated'])
                {{ translate('this_window_holds_more_attempts_than_are_folded_into_these_counts') }}.
            @endif
        </p>
    @else
        <x-k.empty icon="external" :title="$stateTitle($declines['state'])" :text="$declines['note'] ?? ''" />
        @if (!empty($declines['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $declines['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

<h3 class="mon-heading">{{ translate('where_the_money_record_contradicts_itself') }}</h3>

<p class="mon-note">
    {{ translate('each_check_below_is_a_defect_when_it_returns_a_row_a_count_of_zero_is_a_measurement_a_check_that_could_not_run_says_so_instead_of_showing_zero') }}.
    {{ translate('findings_across_the_orders_this_page_examined') }}: {{ $count($findingsFound) }}@if ($findingsBlocked > 0);
        {{ translate('checks_that_could_not_be_run') }}: {{ $count($findingsBlocked) }}@endif.
</p>

@foreach ($findings as $key => $finding)
    <x-k.card :title="translate($key)">
        <p class="mon-note">
            <span class="mon-pill {{ $findingPill($finding) }}">
                @if ($finding['state'] === 'ok')
                    {{ $count($finding['count']) }}
                    @if (!$finding['count_exact'])
                        {{ translate('or_more') }}
                    @endif
                @else
                    {{ $stateTitle($finding['state']) }}
                @endif
            </span>
            {{ $finding['means'] }}
        </p>

        @if ($finding['state'] === 'ok' && !empty($finding['rows']))
            <div class="k-table-wrap">
                <table class="k-table k-table--compact">
                    <thead>
                    <tr>
                        <th>{{ translate('reference') }}</th>
                        <th>{{ translate('points_at') }}</th>
                        <th>{{ translate('gateway') }}</th>
                        <th class="k-table__num">{{ translate('amount') }}</th>
                        <th class="k-table__num">{{ translate('compared_with') }}</th>
                        <th>{{ translate('recorded_at') }}</th>
                        <th>{{ translate('what_was_found') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($finding['rows'] as $row)
                        <tr>
                            <td><code>{{ $row['reference'] ?? '—' }}</code></td>
                            <td>
                                {{-- The kind is this panel's own vocabulary, never a stored value. --}}
                                <span class="mon-pill {{ $kindPill($row['kind']) }}">{{ translate($row['kind']) }}</span>
                            </td>
                            <td><code>{{ $row['gateway'] ?? '—' }}</code></td>
                            <td class="k-table__num k-num">
                                {{ $amount($row['amount']) ?? '—' }}
                                @if ($row['currency']) <i>{{ $row['currency'] }}</i> @endif
                            </td>
                            <td class="k-table__num k-num">
                                @if ($row['expected'] === null)
                                    <span class="mon-metric__state">—</span>
                                @else
                                    {{ $amount($row['expected']) }}
                                @endif
                            </td>
                            <td class="k-num">{{ $row['occurred_at'] ?? '—' }}</td>
                            <td><small class="mon-metric__note">{{ $row['detail'] }}</small></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <p class="mon-note mon-note--critical">{{ $finding['action'] }}</p>

            <p class="mon-note">
                {{ translate('read_from') }} <code>{{ $finding['source'] }}</code>.
                {{ translate('listed') }}: {{ $count($finding['listed']) }}/{{ $count($finding['count']) }}.
                @if (!$finding['count_exact'])
                    {{ translate('one_of_the_reads_behind_this_check_was_cut_at_its_limit_so_the_count_is_a_floor_rather_than_a_total') }}.
                @endif
                @if (!empty($finding['note']))
                    {{ $finding['note'] }}
                @endif
            </p>
        @elseif ($finding['state'] === 'ok')
            <p class="mon-note">
                {{ translate('nothing_found_in_the_orders_this_page_examined') }} —
                {{ translate('read_from') }} <code>{{ $finding['source'] }}</code>.
                @if (!$finding['count_exact'])
                    {{ translate('one_of_the_reads_behind_this_check_was_cut_at_its_limit') }}.
                @endif
                @if (!empty($finding['note']))
                    {{ $finding['note'] }}
                @endif
            </p>
        @else
            <x-k.empty icon="alert" :title="$stateTitle($finding['state'])" :text="$finding['note'] ?? ''" />
            @if (!empty($finding['remedy']))
                <details class="mon-metric__remedy">
                    <summary>{{ translate('how_to_enable_this') }}</summary>
                    <code>{{ $finding['remedy'] }}</code>
                </details>
            @endif
            <p class="mon-note">{{ $finding['action'] }}</p>
        @endif
    </x-k.card>
@endforeach

{{-- Not measurements this page chose to leave out — measurements nothing on this deployment takes.
     Drawn as readings with their reason so the gap is a task rather than an empty cell somebody
     reads as a gateway that answered instantly. --}}
<x-k.card :title="translate('what_this_build_does_not_record_about_a_payment')">
    <div class="mon-grid">
        @foreach ($unrecorded['fields'] as $name => $metric)
            @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($name)])
        @endforeach
    </div>
    <p class="mon-note">{{ $unrecorded['note'] }}</p>
</x-k.card>

{{-- Which tables were cheap enough to read. A check that was refused for cost is a different fact
     from one that ran and found nothing, and the difference is an index somebody can add. --}}
<x-k.card :title="translate('what_this_page_was_allowed_to_read')">
    @if ($scans['state'] === 'ok')
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('table') }}</th>
                    <th>{{ translate('read') }}</th>
                    <th class="k-table__num">{{ translate('estimated_rows') }}</th>
                    <th>{{ translate('index_that_would_make_this_cheap') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($scans['rows'] as $row)
                    <tr class="{{ $row['state'] === 'ok' ? '' : 'mon-row--muted' }}">
                        <td><code>{{ $row['table'] }}</code></td>
                        <td><span class="mon-pill {{ $scanPill($row['state']) }}">{{ $row['state'] === 'ok' ? translate('yes') : $stateTitle($row['state']) }}</span></td>
                        <td class="k-table__num k-num">
                            @if ($row['estimated_rows'] === null)
                                <span class="mon-metric__state">{{ translate('no_data') }}</span>
                            @else
                                {{ $count($row['estimated_rows']) }}
                            @endif
                        </td>
                        <td>
                            @if ($row['remedy'])
                                <code>{{ $row['remedy'] }}</code>
                            @else
                                —
                            @endif
                            @if ($row['note'])
                                <small class="mon-metric__note" style="display:block">{{ $row['note'] }}</small>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <p class="mon-note">
            {{ translate('none_of_these_tables_carries_an_index_a_read_could_be_bounded_on_so_the_cost_of_reading_one_is_its_size') }}.
            {{ translate('a_table_over_the_ceiling_is_not_read_at_all_and_the_checks_that_needed_it_say_so_rather_than_reporting_nothing_found') }} —
            {{ translate('ceiling') }}: {{ $count($scans['ceiling']) }} {{ translate('estimated_rows') }}.
            {{ translate('sizes_come_from') }} <code>{{ $scans['source'] }}</code>
            {{ translate('and_are_approximate_for_innodb_which_is_why_the_ceiling_sits_well_below_anything_that_would_hurt') }}.
        </p>
    @else
        <p class="mon-note">{{ $scans['note'] ?? $stateTitle($scans['state']) }}</p>
    @endif
</x-k.card>

<p class="mon-note">
    {{ translate('orders_settlements_and_commissions_are_read_from_the_shops_own_database') }}
    (<code>orders</code>, <code>order_transactions</code>, <code>order_item_commissions</code>),
    {{ translate('gateway_captures_from') }} <code>payment_requests</code>,
    {{ translate('refunds_from') }} <code>refund_transactions</code>,
    {{ translate('paytabs_attempts_from') }} <code>paytabs_invoices</code>,
    {{ translate('and_payment_events_from') }} <code>analytics_events</code>.
    {{ translate('failed_payments_are_written_by') }} <code>{{ $recording['failure_hook'] }}</code>,
    {{ translate('which_recorded_nothing_at_all_before_this_release') }}.
    {{ translate('all_timestamps_are_shown_in') }} {{ $window['timezone'] }}.
</p>
