{{--
    Energy and hardware: what this machine draws, what it costs, and what its sensors say.

    The basis leads the page and is stamped on everything below it. A wattage read off a hardware
    counter and one modelled from a CPU percentage look identical once they are printed, and the
    money derived from the second looks exactly like a bill — so every derived figure here is drawn
    beside the word Measured or Estimated rather than on its own.

    On a virtual machine or a container almost every block on this page is legitimately
    unavailable: the hypervisor owns the meters, the thermal zones, the fan tachometers and the
    disks, and forwards none of them to a guest. That is an answer about the machine, not a broken
    probe, so it is drawn as one sentence with the thing an operator would have to do — and the
    twenty sensors that are all missing for that one reason are collapsed into it instead of
    filling the page with twenty grey rows that each look like a fault.

    The cost figures are the one place with no fallback at all. An unset tariff is never zero:
    a free-electricity figure on this page is a claim nobody can reconcile against their bill.
--}}

@php
    $window = $panel['window'];
    $basis = $panel['basis'];
    $power = $panel['power'];
    $domains = $panel['domains'];
    $windowEnergy = $panel['window_energy'];
    $energy = $panel['energy'];
    $tariff = $panel['tariff'];
    $cost = $panel['cost'];
    $hardware = $panel['hardware'];
    $sensors = $panel['sensors'];
    $temperatures = $panel['temperatures'];
    $fans = $panel['fans'];
    $voltages = $panel['voltages'];
    $drives = $panel['drives'];
    $ecc = $panel['ecc'];
    $ipmi = $panel['ipmi'];

    $stateTitle = static fn (string $state) => match ($state) {
        'failed' => translate('this_could_not_be_read'),
        'not_configured' => translate('not_configured'),
        'permission_denied' => translate('permission_denied'),
        'not_supported' => translate('not_supported'),
        'collector_offline' => translate('collector_offline'),
        default => translate('no_data'),
    };

    // Trailing zeros are dropped so a fraction of a kilowatt hour keeps the digits that make it
    // non-zero, and a whole number is not padded into looking more precise than it is.
    $number = static function ($value, int $places = 2) {
        if ($value === null) {
            return null;
        }
        $formatted = number_format((float) $value, $places, '.', ',');

        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    };

    $count = static fn ($value) => $value === null ? '—' : number_format((float) $value, fmod((float) $value, 1) == 0 ? 0 : 2);

    // The shared chart renderer reads each point's `hits`, so a stored gauge is handed to it under
    // that key. Only the field name is adapted — the value is the sample as it was written.
    $asChart = static fn (array $gauge) => [
        'points' => array_map(
            static fn (array $point) => ['t' => $point['t'], 'hits' => $point['v']],
            $gauge['points'],
        ),
    ];

    $chartedGauges = collect($panel['gauges'])->filter(static fn (array $gauge) => $gauge['state'] === 'ok');
    $listedGauges = collect($panel['gauges'])->reject(static fn (array $gauge) => $gauge['state'] === 'ok');

    $hardwareReadable = $hardware['available'] !== false;
@endphp

{{-- Said once, at the top. A collector that could not answer produces two dozen identical
     unavailable rows underneath, and two dozen copies of one fault reads as two dozen faults. --}}
@if (!empty($panel['collectors']))
    <div class="mon-attention">
        @foreach ($panel['collectors'] as $fault)
            <div class="mon-attention__item mon-attention__item--{{ $fault['state'] === 'failed' ? 'critical' : 'info' }}">
                <x-k.icon name="alert" :size="16" />
                <span class="mon-attention__body">
                    <strong>{{ translate($fault['collector']) }} — {{ translate('this_collector_could_not_answer') }}</strong>
                    <small>{{ $fault['note'] }}</small>
                </span>
            </div>
        @endforeach
    </div>
@endif

{{-- The one fact that decides how every number below must be read. It is drawn before any of them,
     because an estimate quoted back as a measurement is the failure this section exists to prevent
     and a missing basis is a complete answer about the machine rather than a gap in the page. --}}
@if ($basis['estimated'])
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--warning">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('every_power_energy_and_cost_figure_on_this_page_is_estimated_not_measured') }}</strong>
                <small>{{ $basis['note'] }}</small>
                @if ($basis['band']['state'] === 'ok')
                    <small>
                        {{ translate('cpu_utilisation_is_interpolated_across_the_configured_band') }}:
                        {{ $number($basis['band']['idle_watts']) }} W {{ translate('at_idle') }} →
                        {{ $number($basis['band']['max_watts']) }} W {{ translate('at_full_load') }}.
                        {{ translate('real_draw_also_depends_on_disks_memory_and_power_supply_efficiency') }}.
                    </small>
                @else
                    <small>{{ $basis['band']['note'] }}</small>
                    @if (!empty($basis['band']['remedy']))
                        <code>{{ $basis['band']['remedy'] }}</code>
                    @endif
                @endif
                <small>{{ translate('the_estimate_band_is_edited_in_monitoring_settings_energy_and_nothing_here_was_read_from_the_hardware') }}</small>
            </span>
        </div>
    </div>
@elseif (!$basis['measured'])
    {{-- Not a failure. A host with no energy counters and no estimate switched on has answered
         completely, and the answer is that it cannot be asked. --}}
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--info">
            <x-k.icon name="info" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('this_host_cannot_be_asked_what_it_draws') }}</strong>
                <small>{{ $basis['note'] ?? $stateTitle($basis['state']) }}</small>
                <small>{{ translate('no_watt_energy_or_cost_figure_is_shown_below_because_none_could_be_produced_and_a_number_here_would_have_to_be_invented') }}</small>
                @if (!empty($basis['remedy']))
                    <code>{{ $basis['remedy'] }}</code>
                @endif
            </span>
        </div>
    </div>
@endif

{{-- Draw right now, with the basis beside it rather than in a footnote. --}}
<x-k.card :title="translate('power_draw')">
    <div class="mon-grid">
        @include('admin-views.monitoring.partials._metric', [
            'metric' => $basis['metric'],
            'label' => translate('basis'),
            'hint' => translate('measured_means_the_machines_own_energy_counters_estimated_means_modelled_from_cpu_load'),
        ])
        @if ($power['state'] === 'ok')
            @foreach ($power['metrics'] as $label => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($label)])
            @endforeach
        @endif
    </div>

    @if ($power['state'] !== 'ok')
        <x-k.empty icon="trend-up" :title="$stateTitle($power['state'])" :text="$power['note'] ?? ''" />
        @if (!empty($power['remedy']))
            {{-- The remedy is the difference between a dead card and a task somebody can do. --}}
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $power['remedy'] }}</code>
            </details>
        @endif
    @elseif ($basis['measured'])
        <p class="mon-note">
            {{ translate('these_counters_cover_the_cpu_package_and_where_the_platform_meters_it_dram') }}.
            {{ translate('disks_network_cards_fans_and_power_supply_losses_are_not_in_them_so_this_is_a_floor_for_what_the_machine_draws_at_the_wall_rather_than_the_wall_draw') }}.
        </p>
    @endif
</x-k.card>

{{-- Energy across the range this page is set to. The collector accumulates against the operator's
     calendar, which is the right frame for a bill and the wrong one for a range control that says
     six hours, so this is the same arithmetic over the selected window. --}}
<x-k.card :title="translate('energy_in_this_window')">
    @if ($windowEnergy['state'] === 'ok')
        <div class="mon-usage">
            <div class="mon-usage__head">
                <span class="mon-usage__value k-num">
                    {{ $number($windowEnergy['kwh'], 4) }}<i>kWh</i>
                    @if ($windowEnergy['estimated'])
                        <span class="mon-pill mon-pill--info">{{ translate('estimated') }}</span>
                    @else
                        <span class="mon-pill mon-pill--ok">{{ translate('measured') }}</span>
                    @endif
                </span>
                <span class="mon-usage__caption">
                    {{ translate('average_draw') }}: {{ $number($windowEnergy['average_watts']) }} W ·
                    {{ translate('minutes_sampled') }}:
                    {{ $count($windowEnergy['covered_minutes']) }} / {{ $count($windowEnergy['window_minutes']) }}
                </span>
            </div>
        </div>

        <div class="mon-grid">
            <div class="mon-metric">
                <span class="mon-metric__label">{{ translate('energy_in_this_window') }}</span>
                <span class="mon-metric__value k-num">{{ $number($windowEnergy['wh'], 2) }}<i>Wh</i></span>
                <span class="mon-metric__source" title="{{ translate('where_this_number_came_from') }}">{{ $windowEnergy['source'] }}</span>
            </div>
            <div class="mon-metric {{ $windowEnergy['cost']['state'] === 'ok' ? '' : 'mon-metric--muted' }}">
                <span class="mon-metric__label">{{ translate('cost_of_this_window') }}</span>
                @if ($windowEnergy['cost']['state'] === 'ok')
                    <span class="mon-metric__value k-num">
                        {{ $number($windowEnergy['cost']['amount'], 4) }}
                        @if ($windowEnergy['cost']['currency'])<i>{{ $windowEnergy['cost']['currency'] }}</i>@endif
                    </span>
                    <span class="mon-metric__note">
                        {{ translate('tariff') }}: {{ $number($windowEnergy['cost']['price_per_kwh'], 4) }}
                        {{ $windowEnergy['cost']['currency'] }}/kWh
                        @if ($windowEnergy['estimated']) — {{ translate('estimated_energy_priced_at_a_real_tariff') }} @endif
                    </span>
                @else
                    {{-- Never a zero. A tariff that is not set makes the cost unavailable, and a
                         0.00 here would be a statement that the electricity was free. --}}
                    <span class="mon-metric__state">{{ $stateTitle($windowEnergy['cost']['state']) }}</span>
                    @if ($windowEnergy['cost']['note'])
                        <span class="mon-metric__note">{{ $windowEnergy['cost']['note'] }}</span>
                    @endif
                    @if (!empty($windowEnergy['cost']['remedy']))
                        <details class="mon-metric__remedy">
                            <summary>{{ translate('how_to_enable_this') }}</summary>
                            <code>{{ $windowEnergy['cost']['remedy'] }}</code>
                        </details>
                    @endif
                @endif
                <span class="mon-metric__source" title="{{ translate('where_this_number_came_from') }}">{{ $windowEnergy['cost']['source'] }}</span>
            </div>
        </div>

        @if ($windowEnergy['note'])
            <p class="mon-note">{{ $windowEnergy['note'] }}</p>
        @endif

        <p class="mon-note">
            {{ translate('accumulated_from_the_once_a_minute_samples_stored_in') }} <code>monitoring_series</code>.
            {{ translate('each_bucket_is_credited_only_with_the_minutes_it_was_actually_sampled_for_so_a_gap_in_collection_stays_a_gap_rather_than_becoming_consumption') }}.
            {{ translate('window') }}: {{ $window['since'] }} → {{ $window['until'] }} ({{ $window['timezone'] }}).
            {{ translate('resolution') }}: {{ translate($windowEnergy['resolution']) }}.
        </p>
    @else
        <x-k.empty icon="trend-up" :title="$stateTitle($windowEnergy['state'])" :text="$windowEnergy['note'] ?? ''" />
        @if (!empty($windowEnergy['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $windowEnergy['remedy'] }}</code>
            </details>
        @endif
        <p class="mon-note">{{ translate('source') }}: <code>{{ $windowEnergy['source'] }}</code></p>
    @endif
</x-k.card>

{{-- The collector's own accumulations, against the operator's calendar rather than this page's
     range: "what did today cost" is a question about a day, not about the last six hours. --}}
<x-k.card :title="translate('energy_today_and_this_month')">
    @if ($energy['state'] === 'ok' && !empty($energy['metrics']))
        <div class="mon-grid">
            @foreach ($energy['metrics'] as $label => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($label)])
            @endforeach
        </div>
        <p class="mon-note">
            @if ($energy['estimated'])
                {{ translate('every_figure_in_this_card_is_estimated_not_measured') }}.
            @endif
            {{ translate('today_and_this_month_are_the_dashboards_own_calendar_days') }} ({{ $window['timezone'] }}).
            {{ translate('the_projection_is_the_whole_month_at_the_average_draw_recorded_so_far_rather_than_a_forecast_of_load') }}.
        </p>
    @else
        <x-k.empty icon="trend-up" :title="$stateTitle($energy['state'])" :text="$energy['note'] ?? ''" />
        @if (!empty($energy['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $energy['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- Two settings, not two measurements: what a kilowatt hour costs here and what that price is
     denominated in. Without the first there is no money on this page at all. --}}
<x-k.card :title="translate('electricity_tariff')">
    @if ($tariff['state'] === 'ok' && !empty($tariff['metrics']))
        <div class="mon-grid">
            @foreach ($tariff['metrics'] as $label => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($label)])
            @endforeach
        </div>
    @else
        <x-k.empty icon="settings" :title="$stateTitle($tariff['state'])" :text="$tariff['note'] ?? ''" />
        @if (!empty($tariff['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $tariff['remedy'] }}</code>
            </details>
        @endif
    @endif
    <p class="mon-note">
        {{ translate('a_tariff_is_never_assumed_it_differs_by_country_contract_and_time_of_day_and_a_wrong_one_produces_a_confident_bill_nobody_can_reconcile') }}.
    </p>
</x-k.card>

{{-- The money. When no price is set every figure here is unavailable for that one reason, which is
     stated once — and never as 0.00, which would put free electricity on the page. --}}
<x-k.card :title="translate('what_the_energy_has_cost')">
    @if ($cost['state'] === 'ok' && !empty($cost['metrics']))
        <div class="mon-grid">
            @foreach ($cost['metrics'] as $label => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($label)])
            @endforeach
        </div>
        @if ($cost['estimated'])
            <p class="mon-note mon-note--critical">
                {{ translate('these_amounts_are_priced_from_estimated_energy_not_measured_energy') }}.
                {{ translate('they_are_a_model_of_a_bill_and_must_not_be_quoted_as_one') }}.
            </p>
        @endif
    @else
        <x-k.empty icon="settings" :title="$stateTitle($cost['state'])" :text="$cost['note'] ?? ''" />
        @if (!empty($cost['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $cost['remedy'] }}</code>
            </details>
        @endif
        <p class="mon-note">{{ translate('no_cost_is_shown_rather_than_a_zero_a_zero_here_would_read_as_electricity_that_cost_nothing') }}.</p>
    @endif
</x-k.card>

{{-- Every domain the platform exposes. The rows deliberately overlap — core and uncore sit inside
     package and psys spans the whole platform — so the table says which of them the total is made
     of rather than inviting anyone to add them up. --}}
<x-k.card :title="translate('power_domains')">
    @if ($domains['state'] === 'ok' && !empty($domains['rows']))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('domain') }}</th>
                    <th class="k-table__num">{{ translate('draw') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($domains['rows'] as $domain)
                    <tr>
                        {{-- The domain name is what the kernel called it, not a translation key. --}}
                        <td><code>{{ $domain['domain'] }}</code></td>
                        <td class="k-table__num k-num">
                            @if ($domain['watts'] === null)
                                <span class="mon-metric__state">{{ translate('no_data') }}</span>
                            @else
                                {{ $number($domain['watts']) }} W
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @if ($domains['note'])
            <p class="mon-note">{{ $domains['note'] }}</p>
        @endif
        @if ($domains['truncated'])
            <p class="mon-note">{{ translate('this_host_exposes_more_domains_than_are_listed_here') }}.</p>
        @endif
    @else
        <x-k.empty icon="reports" :title="$stateTitle($domains['state'])" :text="$domains['note'] ?? ''" />
        @if (!empty($domains['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $domains['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- The same readings over the window. The cards above are one instant, and a machine that heats
     up every afternoon or a fan that has stopped is only visible as a line. --}}
@foreach ($chartedGauges as $gauge)
    <x-k.card :title="translate($gauge['title'])">
        <div class="mon-chart" data-mon-chart='@json($asChart($gauge))'></div>
        {{-- The window is stated once at the foot of the page rather than on every card: the shared
             renderer labels its axis with the VIEWER's clock, so a dashboard timezone printed
             directly under it reads as a contradiction on a browser set elsewhere. --}}
        <p class="mon-note">
            {{ translate('latest') }}: {{ $count($gauge['latest']) }} {{ $gauge['unit'] }} —
            <code>{{ $gauge['metric'] }}</code>
            @if ($basis['estimated'] && $gauge['collector'] === 'energy')
                — {{ translate('estimated_not_measured') }}
            @endif
        </p>
    </x-k.card>
@endforeach

{{-- The gauges with no line, and why each one has none. Four different silences — collection off,
     the reading missing on this host, a rolled-up range, an empty window — draw the same flat
     nothing, so the reason is named rather than left to be guessed at. --}}
@if ($listedGauges->isNotEmpty())
    <x-k.card :title="translate('stored_energy_and_hardware_gauges')">
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('gauge') }}</th>
                    <th>{{ translate('series') }}</th>
                    <th class="k-table__num">{{ translate('latest') }}</th>
                    <th class="k-table__num">{{ translate('samples_in_window') }}</th>
                    <th>{{ translate('state') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($listedGauges as $gaugeKey => $gauge)
                    <tr>
                        <td>{{ translate($gaugeKey) }}</td>
                        <td><code>{{ $gauge['metric'] }}</code></td>
                        <td class="k-table__num k-num">
                            {{ $gauge['latest'] === null ? '—' : $count($gauge['latest']) . ' ' . $gauge['unit'] }}
                        </td>
                        <td class="k-table__num k-num">
                            {{-- Null, not zero: a series read that failed did not find nothing, it
                                 did not look, and a 0 here would read as an empty window. --}}
                            {{ $gauge['samples'] === null ? '—' : number_format($gauge['samples']) }}
                        </td>
                        <td>
                            <span class="mon-metric__state">{{ $stateTitle($gauge['state']) }}</span>
                            <small class="mon-metric__note" style="display:block">{{ $gauge['note'] ?? '' }}</small>
                            @if (!empty($gauge['remedy']))
                                <details class="mon-metric__remedy">
                                    <summary>{{ translate('how_to_enable_this') }}</summary>
                                    <code>{{ $gauge['remedy'] }}</code>
                                </details>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="mon-note">
            {{ translate('these_gauges_are_sampled_every_minute_and_stored_in') }} <code>monitoring_series</code>.
            {{ translate('a_reading_is_only_stored_while_it_is_available_so_a_gauge_with_no_line_on_this_host_is_a_missing_reading_rather_than_a_missing_sampler') }}.
        </p>
    </x-k.card>
@endif

<h3 class="mon-heading">{{ translate('hardware') }}</h3>

@unless ($hardwareReadable)
    {{-- One honest empty state for the whole half. The collector already folded the question into a
         single availability reading; twenty grey cards would read as twenty faults, so the distinct
         reasons behind them are stated once each with the readings they account for. --}}
    <x-k.card :title="translate('hardware_sensors')">
        <x-k.empty icon="settings"
                   :title="translate('no_hardware_telemetry_on_this_host')"
                   :text="$hardware['note'] ?? ''" />
        @if (!empty($hardware['unavailable']))
            <div class="k-table-wrap">
                <table class="k-table k-table--compact">
                    <thead>
                    <tr>
                        <th>{{ translate('readings') }}</th>
                        <th>{{ translate('state') }}</th>
                        <th>{{ translate('why') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($hardware['unavailable'] as $group)
                        <tr>
                            <td>
                                @foreach ($group['metrics'] as $metricName)
                                    {{-- Collector metric names, drawn as themselves. --}}
                                    <code>{{ $metricName }}</code>{{ $loop->last ? '' : ',' }}
                                @endforeach
                            </td>
                            <td><span class="mon-metric__state">{{ $stateTitle($group['state']) }}</span></td>
                            <td>
                                <small class="mon-metric__note">{{ $group['note'] }}</small>
                                @if (!empty($group['remedy']))
                                    <details class="mon-metric__remedy">
                                        <summary>{{ translate('how_to_enable_this') }}</summary>
                                        <code>{{ $group['remedy'] }}</code>
                                    </details>
                                @endif
                                @if (!empty($group['source']))
                                    <small class="mon-metric__source" style="display:block">{{ $group['source'] }}</small>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <p class="mon-note">
            {{ translate('a_missing_smart_reading_is_not_a_healthy_disk_an_absent_thermal_zone_is_not_a_cool_cpu_and_an_ecc_counter_that_cannot_be_found_is_not_zero_errors') }}.
            {{ translate('physical_sensors_belong_to_the_machine_and_a_virtual_guest_is_never_shown_the_ones_underneath_it') }}.
        </p>
    </x-k.card>
@else
    <x-k.card :title="translate('temperatures_and_fans')">
        @if (!empty($sensors['metrics']))
            <div class="mon-grid">
                @foreach ($sensors['metrics'] as $label => $metric)
                    @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($label)])
                @endforeach
            </div>
        @else
            <x-k.empty icon="settings" :title="$stateTitle($sensors['state'])" :text="$sensors['note'] ?? ''" />
            @if (!empty($sensors['remedy']))
                <details class="mon-metric__remedy">
                    <summary>{{ translate('how_to_enable_this') }}</summary>
                    <code>{{ $sensors['remedy'] }}</code>
                </details>
            @endif
        @endif
        <p class="mon-note">
            {{ translate('the_cpu_figure_is_the_package_sensor_where_the_platform_has_one_and_otherwise_the_hottest_core_rather_than_the_mean') }} —
            {{ translate('one_core_at_ninety_five_degrees_is_the_event_and_an_average_of_eight_cores_hides_it') }}.
        </p>
    </x-k.card>

    <x-k.card :title="translate('every_temperature_sensor')">
        @if ($temperatures['state'] === 'ok' && !empty($temperatures['rows']))
            <div class="k-table-wrap">
                <table class="k-table k-table--compact">
                    <thead>
                    <tr>
                        <th>{{ translate('sensor') }}</th>
                        <th>{{ translate('chip') }}</th>
                        <th>{{ translate('part') }}</th>
                        <th class="k-table__num">{{ translate('temperature') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($temperatures['rows'] as $sensor)
                        <tr>
                            {{-- Sensor labels and chip names are what the kernel published, not
                                 translation keys: one unrecognised board would mint a language key
                                 per sensor. --}}
                            <td>{{ $sensor['label'] ?? '—' }}</td>
                            <td><code>{{ $sensor['chip'] ?? '—' }}</code></td>
                            <td>{{ $sensor['kind'] ?? '—' }}</td>
                            <td class="k-table__num k-num">
                                {{ $sensor['celsius'] === null ? '—' : $number($sensor['celsius'], 1) . ' °C' }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($temperatures['truncated'])
                <p class="mon-note">{{ translate('this_host_exposes_more_sensors_than_are_listed_here') }}.</p>
            @endif
        @else
            <x-k.empty icon="settings" :title="$stateTitle($temperatures['state'])" :text="$temperatures['note'] ?? ''" />
            @if (!empty($temperatures['remedy']))
                <details class="mon-metric__remedy">
                    <summary>{{ translate('how_to_enable_this') }}</summary>
                    <code>{{ $temperatures['remedy'] }}</code>
                </details>
            @endif
        @endif
    </x-k.card>

    <x-k.card :title="translate('fans')">
        @if ($fans['state'] === 'ok' && !empty($fans['rows']))
            <div class="k-table-wrap">
                <table class="k-table k-table--compact">
                    <thead>
                    <tr>
                        <th>{{ translate('fan') }}</th>
                        <th>{{ translate('chip') }}</th>
                        <th class="k-table__num">{{ translate('speed') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($fans['rows'] as $fan)
                        <tr>
                            <td>{{ $fan['label'] ?? '—' }}</td>
                            <td><code>{{ $fan['chip'] ?? '—' }}</code></td>
                            <td class="k-table__num k-num">
                                {{ $fan['rpm'] === null ? '—' : $count($fan['rpm']) . ' RPM' }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mon-note">
                {{ translate('a_tachometer_that_reads_nothing_is_a_missing_input_rather_than_a_stopped_fan_and_is_left_out_of_this_table_instead_of_being_shown_as_zero') }}.
            </p>
        @else
            <x-k.empty icon="settings" :title="$stateTitle($fans['state'])" :text="$fans['note'] ?? ''" />
            @if (!empty($fans['remedy']))
                <details class="mon-metric__remedy">
                    <summary>{{ translate('how_to_enable_this') }}</summary>
                    <code>{{ $fans['remedy'] }}</code>
                </details>
            @endif
        @endif
    </x-k.card>

    <x-k.card :title="translate('voltage_rails')">
        @if ($voltages['state'] === 'ok' && !empty($voltages['rows']))
            <div class="k-table-wrap">
                <table class="k-table k-table--compact">
                    <thead>
                    <tr>
                        <th>{{ translate('rail') }}</th>
                        <th>{{ translate('chip') }}</th>
                        <th class="k-table__num">{{ translate('voltage') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($voltages['rows'] as $rail)
                        <tr>
                            <td>{{ $rail['label'] ?? '—' }}</td>
                            <td><code>{{ $rail['chip'] ?? '—' }}</code></td>
                            <td class="k-table__num k-num">
                                {{ $rail['volts'] === null ? '—' : $number($rail['volts'], 3) . ' V' }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <x-k.empty icon="settings" :title="$stateTitle($voltages['state'])" :text="$voltages['note'] ?? ''" />
            @if (!empty($voltages['remedy']))
                <details class="mon-metric__remedy">
                    <summary>{{ translate('how_to_enable_this') }}</summary>
                    <code>{{ $voltages['remedy'] }}</code>
                </details>
            @endif
        @endif
    </x-k.card>

    <x-k.card :title="translate('drive_health')">
        @if ($drives['state'] === 'ok' && !empty($drives['rows']))
            <div class="k-table-wrap">
                <table class="k-table k-table--compact">
                    <thead>
                    <tr>
                        <th>{{ translate('device') }}</th>
                        <th>{{ translate('model') }}</th>
                        <th>{{ translate('self_assessment') }}</th>
                        <th class="k-table__num">{{ translate('temperature') }}</th>
                        <th class="k-table__num">{{ translate('powered_on') }}</th>
                        <th class="k-table__num">{{ translate('reallocated_sectors') }}</th>
                        <th class="k-table__num">{{ translate('pending_sectors') }}</th>
                        <th class="k-table__num">{{ translate('life_used') }}</th>
                        <th class="k-table__num">{{ translate('media_errors') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($drives['rows'] as $drive)
                        <tr>
                            <td><code>{{ $drive['device'] }}</code></td>
                            <td>{{ $drive['model'] ?? '—' }}</td>
                            <td>
                                {{-- Three-valued. A drive that did not answer has neither passed nor
                                     failed, and drawing the silence as either is a claim about a
                                     disk nobody interrogated. --}}
                                @if ($drive['passed'] === true)
                                    <span class="mon-pill mon-pill--healthy">{{ translate('passed') }}</span>
                                @elseif ($drive['passed'] === false)
                                    <span class="mon-pill mon-pill--critical">{{ translate('failing') }}</span>
                                @else
                                    <span class="mon-metric__state">{{ translate('no_data') }}</span>
                                @endif
                            </td>
                            <td class="k-table__num k-num">{{ $drive['temp_c'] === null ? '—' : $number($drive['temp_c'], 1) . ' °C' }}</td>
                            <td class="k-table__num k-num">{{ $drive['power_on_hours'] === null ? '—' : $count($drive['power_on_hours']) . ' ' . translate('hours') }}</td>
                            <td class="k-table__num k-num">{{ $drive['reallocated_sectors'] === null ? '—' : $count($drive['reallocated_sectors']) }}</td>
                            <td class="k-table__num k-num">{{ $drive['pending_sectors'] === null ? '—' : $count($drive['pending_sectors']) }}</td>
                            <td class="k-table__num k-num">{{ $drive['percentage_used'] === null ? '—' : $count($drive['percentage_used']) . '%' }}</td>
                            <td class="k-table__num k-num">{{ $drive['media_errors'] === null ? '—' : $count($drive['media_errors']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($drives['note'])
                <p class="mon-note">{{ $drives['note'] }}</p>
            @endif
            <p class="mon-note">
                {{ translate('a_dash_is_an_attribute_the_drive_did_not_report_rather_than_a_count_of_zero') }} —
                {{ translate('sata_and_nvme_publish_different_attributes_and_neither_is_filled_in_for_the_other') }}.
            </p>
        @else
            <x-k.empty icon="catalog" :title="$stateTitle($drives['state'])" :text="$drives['note'] ?? ''" />
            @if (!empty($drives['remedy']))
                <details class="mon-metric__remedy">
                    <summary>{{ translate('how_to_enable_this') }}</summary>
                    <code>{{ $drives['remedy'] }}</code>
                </details>
            @endif
        @endif
    </x-k.card>

    <x-k.card :title="translate('memory_errors')">
        @if (!empty($ecc['metrics']))
            <div class="mon-grid">
                @foreach ($ecc['metrics'] as $label => $metric)
                    @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($label)])
                @endforeach
            </div>
            <p class="mon-note">
                {{ translate('counted_since_boot_by_the_memory_controller') }}.
                {{ translate('a_zero_here_is_a_real_reading_from_a_working_edac_controller_which_is_exactly_why_a_host_without_one_reports_not_supported_instead_of_borrowing_it') }}.
            </p>
        @else
            <x-k.empty icon="settings" :title="$stateTitle($ecc['state'])" :text="$ecc['note'] ?? ''" />
            @if (!empty($ecc['remedy']))
                <details class="mon-metric__remedy">
                    <summary>{{ translate('how_to_enable_this') }}</summary>
                    <code>{{ $ecc['remedy'] }}</code>
                </details>
            @endif
        @endif
    </x-k.card>

    <x-k.card :title="translate('chassis_sensors_from_the_bmc')">
        @if ($ipmi['state'] === 'ok' && !empty($ipmi['rows']))
            <div class="k-table-wrap">
                <table class="k-table k-table--compact">
                    <thead>
                    <tr>
                        <th>{{ translate('sensor') }}</th>
                        <th>{{ translate('status') }}</th>
                        <th class="k-table__num">{{ translate('temperature') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($ipmi['rows'] as $sensor)
                        <tr>
                            {{-- Sensor name and status are the BMC's own strings. --}}
                            <td>{{ $sensor['sensor'] ?? '—' }}</td>
                            <td>{{ $sensor['status'] ?? '—' }}</td>
                            <td class="k-table__num k-num">
                                {{ $sensor['celsius'] === null ? '—' : $number($sensor['celsius'], 1) . ' °C' }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mon-note">
                {{ translate('a_sensor_the_bmc_lists_without_a_reading_is_skipped_rather_than_shown_as_zero') }}.
            </p>
        @else
            <x-k.empty icon="external" :title="$stateTitle($ipmi['state'])" :text="$ipmi['note'] ?? ''" />
            @if (!empty($ipmi['remedy']))
                <details class="mon-metric__remedy">
                    <summary>{{ translate('how_to_enable_this') }}</summary>
                    <code>{{ $ipmi['remedy'] }}</code>
                </details>
            @endif
        @endif
    </x-k.card>
@endunless

{{-- Normally empty. A reading the collector produces and this page draws nowhere is
     indistinguishable from one nobody ever took, so it is named rather than dropped. --}}
@if (!empty($panel['unrendered']))
    <p class="mon-note">
        {{ translate('these_collectors_also_returned_readings_this_page_does_not_draw') }}:
        @foreach ($panel['unrendered'] as $reading)
            <code>{{ $reading['collector'] }}.{{ $reading['metric'] }}</code> ({{ translate($reading['state']) }}){{ $loop->last ? '' : ',' }}
        @endforeach
    </p>
@endif

<p class="mon-note">
    {{ translate('power_is_read_from_intel_rapl_powercap_which_exists_on_bare_metal_only') }}:
    <code>/sys/class/powercap</code>.
    {{ translate('an_estimate_from_cpu_load_is_used_only_where_it_has_been_switched_on_deliberately') }}:
    <code>MONITORING_ENERGY_ESTIMATED</code>.
    {{ translate('the_tariff_is_a_setting_not_a_measurement') }}:
    <code>MONITORING_ENERGY_PRICE</code>.
    {{ translate('energy_over_the_window_is_summed_from_the_stored_watt_samples_which_do_not_record_which_basis_each_was_taken_under_so_they_are_labelled_with_the_basis_in_force_now') }}:
    <code>monitoring_series</code>.
    {{ translate('temperatures_fans_voltages_drive_health_and_ecc_counters_come_from') }}
    <code>/sys/class/thermal</code>, <code>/sys/class/hwmon</code>, <code>smartctl</code>,
    <code>/sys/devices/system/edac</code>, <code>ipmitool</code>.
    {{-- The remedy above offers a BMC as an alternative to RAPL, and it is worth saying plainly
         that this build does not take one: ipmitool is read for chassis sensors only, so a server
         with a BMC and no RAPL still has no watts here. --}}
    {{ translate('the_bmc_is_read_for_chassis_sensors_only_no_power_figure_is_taken_over_ipmi_in_this_build') }}.
</p>
