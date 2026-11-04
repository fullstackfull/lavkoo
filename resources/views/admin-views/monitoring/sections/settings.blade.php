{{--
    Settings: the rules every other section was measured under.

    Provenance first: a number here means one thing if an operator typed it and another if nobody
    has ever touched it, so no value is shown without saying where it came from. Rows the running
    code reads back are editable in place; the rest stay readings, because saving a key nothing
    reads would be a control that silently does nothing. Secrets state only whether they exist and
    are never editable here — a monitoring screen must not become the place a credential is typed.

    Clearing a field means "go back to what shipped", which is a different instruction from setting
    it to zero, so the placeholder on every input is the shipped default.
--}}

@php
    $self = $panel['self'] ?? null;
    $storage = $self['storage'] ?? null;
    $overrides = $panel['overrides'] ?? ['state' => 'unavailable', 'total' => 0, 'unmapped' => []];

    // Ages are seconds from the panel; the header uses the same shape, so the wording matches.
    $age = static function (?array $reading): string {
        if (!$reading || ($reading['age_seconds'] ?? null) === null) {
            return translate('no_data');
        }
        $seconds = (int) $reading['age_seconds'];
        return match (true) {
            $seconds < 90 => $seconds . 's',
            $seconds < 5400 => round($seconds / 60) . 'm',
            default => round($seconds / 3600) . 'h',
        } . ' ' . translate('ago');
    };

    $decimal = static fn (float $value, int $places = 4) => rtrim(rtrim(number_format($value, $places, '.', ','), '0'), '.');

    // One formatter for every value on the page. A setting is rendered as what it IS — a list stays
    // a list, false stays false — rather than being flattened into a string that reads like a
    // number somebody chose.
    $show = static function (array $row) use ($decimal) {
        $value = $row['value'] ?? null;

        if (is_bool($value)) {
            return $value ? translate('yes') : translate('no');
        }
        if (is_array($value)) {
            return $value === [] ? translate('none') : implode(', ', array_map(static fn ($item) => is_scalar($item) ? (string) $item : json_encode($item), $value));
        }
        if (is_float($value)) {
            return $decimal($value);
        }
        if (is_int($value)) {
            return number_format($value);
        }

        return (string) $value;
    };

    $sourceLabel = static fn (string $kind) => match ($kind) {
        'database' => translate('database_override'),
        'env' => translate('environment_variable'),
        'config' => translate('shipped_default'),
        default => translate('cannot_be_told_apart'),
    };

    // Blue for a value somebody set here, grey for one that arrived with the deployment. No green:
    // a stored threshold is not "good", it is merely different from what shipped.
    $sourceTone = static fn (string $kind) => match ($kind) {
        'database' => 'running',
        'unknown' => 'unknown',
        default => 'info',
    };
@endphp

{{-- A part that could not be built says which part. PanelRegistry would replace the whole section
     with one message, which tells an operator far less than six correct tables and a named gap. --}}
@if (!empty($panel['failures']))
    <div class="mon-attention">
        @foreach ($panel['failures'] as $failure)
            <div class="mon-attention__item mon-attention__item--critical">
                <x-k.icon name="alert" :size="16" />
                <span class="mon-attention__body">
                    <strong>{{ translate($failure['part']) }} — {{ translate('this_part_could_not_be_read') }}</strong>
                    <small>{{ $failure['message'] }}</small>
                </span>
            </div>
        @endforeach
    </div>
@endif

@if (($overrides['state'] ?? null) !== 'ok')
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--warning">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('stored_overrides_could_not_be_read') }}</strong>
                <small>
                    {{ translate('every_value_below_is_the_one_configuration_holds_but_whether_any_of_them_has_been_overridden_in_the_database_is_unknown') }}.
                    {{ $overrides['message'] ?? '' }}
                </small>
                <code>php artisan migrate</code>
            </span>
        </div>
    </div>
@endif

@if (!($panel['environment']['readable'] ?? true))
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--info">
            <x-k.icon name="info" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('the_origin_of_some_values_cannot_be_told_apart_on_this_deployment') }}</strong>
                <small>{{ $panel['environment']['note'] }}</small>
            </span>
        </div>
    </div>
@endif

{{-- Monitoring watching itself, and the bill. The settings below decide both, so the state they
     produce belongs at the top of the page that governs it. --}}
<h3 class="mon-heading">{{ translate('monitoring_itself') }}</h3>
@if ($self === null)
    <x-k.card>
        <x-k.empty icon="alert" :title="translate('the_self_health_block_could_not_be_read')"
                   :text="translate('the_settings_below_are_still_exact_only_monitoring_own_state_is_missing')" />
    </x-k.card>
@else
    <div class="k-stats">
        <x-k.stat :label="translate('collection')"
                  :value="$self['collection_enabled'] ? translate('collecting') : translate('collection_off')"
                  icon="settings" :caption="$self['buffer']['description'] ?? null" />
        <x-k.stat :label="translate('gauges')" :value="$age($self['gauges'] ?? null)" icon="trend-up"
                  :caption="$self['gauges']['state'] === 'ok' ? translate('fresh') : translate($self['gauges']['state'])" />
        <x-k.stat :label="translate('requests')" :value="$age($self['requests'] ?? null)" icon="clock"
                  :caption="$self['requests']['state'] === 'ok' ? translate('fresh') : translate($self['requests']['state'])" />
        <x-k.stat :label="translate('tracing')"
                  :value="$self['tracing']['enabled'] ? $decimal(100 * (float) $self['tracing']['sample_rate'], 3) . '%' : translate('off')"
                  icon="reports" :caption="translate('of_ordinary_requests_kept_as_a_full_trace')" />
        <x-k.stat :label="translate('storage_footprint')"
                  :value="($storage['state'] ?? null) === 'ok' ? $decimal((float) $storage['total_mb'], 2) . ' MB' : translate('no_data')"
                  icon="catalog"
                  :caption="($storage['separate_database'] ?? false) ? translate('in_its_own_database') : translate('inside_the_shop_database')" />
    </div>

    <x-k.card :title="translate('what_monitoring_is_costing_in_storage')">
        @if (($storage['state'] ?? null) === 'ok' && !empty($storage['tables']))
            <div class="k-table-wrap">
                <table class="k-table k-table--compact">
                    <thead>
                    <tr>
                        <th>{{ translate('table') }}</th>
                        <th class="k-table__num">{{ translate('size') }}</th>
                        <th class="k-table__num">{{ translate('rows') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($storage['tables'] as $table)
                        <tr>
                            <td><code>{{ $table['table'] }}</code></td>
                            <td class="k-table__num k-num">{{ $decimal((float) $table['mb'], 2) }} MB</td>
                            <td class="k-table__num k-num">{{ number_format($table['rows']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mon-note">
                {{ translate('the_ten_largest_monitoring_tables_read_from_information_schema') }}.
                {{ translate('row_counts_there_are_the_engine_own_estimate_not_a_count_and_the_retention_windows_below_are_what_decides_all_of_it') }}.
            </p>
        @else
            <x-k.empty icon="catalog" :title="translate('the_storage_footprint_could_not_be_read')"
                       :text="translate('information_schema_is_readable_on_most_deployments_but_a_locked_down_grant_will_refuse_it')" />
        @endif
    </x-k.card>
@endif

{{-- One table per group. Four columns and no more: what it is, what it is set to, who set it, and
     what it decides — which is the whole of what an operator needs before changing anything. --}}
<form action="{{ route('admin.monitoring.actions.settings') }}" method="post">
@csrf
@foreach ($panel['groups'] ?? [] as $group)
    <x-k.card :title="translate($group['key'])">
        @if (empty($group['rows']))
            <x-k.empty icon="settings" :title="translate('nothing_is_configured_in_this_group')"
                       :text="translate($group['why'])" />
        @else
            <p class="mon-note" style="margin-block-start:0">{{ translate($group['why']) }}.</p>
            <div class="k-table-wrap">
                <table class="k-table k-table--compact">
                    <thead>
                    <tr>
                        <th>{{ translate('setting') }}</th>
                        <th>{{ translate('value') }}</th>
                        <th>{{ translate('source') }}</th>
                        <th>{{ translate('what_it_does') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($group['rows'] as $row)
                        <tr class="{{ $row['state'] === 'ok' ? '' : 'mon-row--muted' }}">
                            <td>
                                {{ translate($row['label']) }}
                                <small class="mon-metric__source" style="display:block">
                                    <code>monitoring.{{ $row['key'] }}</code>
                                </small>
                            </td>
                            <td>
                                @if ($row['secret'])
                                    {{-- Presence only. The value decides who may read this shop's
                                         metrics and is never printed on a page anyone with
                                         dashboard access can open. --}}
                                    <span class="mon-pill mon-pill--{{ $row['configured'] ? 'ok' : 'info' }}">
                                        {{ $row['configured'] ? translate('configured') : translate('not_configured') }}
                                    </span>
                                @elseif (!empty($row['editable']))
                                    <div class="d-flex align-items-center gap-2">
                                        <input type="number" step="any" class="form-control form-control-sm" style="max-width:9rem"
                                               name="settings[{{ $row['key'] }}]"
                                               value="{{ $row['source'] === 'database' ? $row['value'] : '' }}"
                                               placeholder="{{ is_scalar($row['shipped']) ? $row['shipped'] : '' }}">
                                        @if ($row['unit'])<small class="text-muted">{{ $row['unit'] }}</small>@endif
                                    </div>
                                    <small class="mon-metric__note" style="display:block">
                                        {{ translate('in_effect') }}: <span class="k-num">{{ $show($row) }}</span>
                                    </small>
                                @elseif ($row['state'] === 'ok')
                                    <span class="k-num">{{ $show($row) }}{{ $row['unit'] ? ' ' . $row['unit'] : '' }}</span>
                                @else
                                    <span class="mon-metric__state">{{ translate($row['state']) }}</span>
                                    @if (!empty($row['remedy']))
                                        <details class="mon-metric__remedy">
                                            <summary>{{ translate('how_to_set_this') }}</summary>
                                            <code>{{ $row['remedy'] }}</code>
                                        </details>
                                    @endif
                                @endif
                            </td>
                            <td>
                                <span class="mon-pill mon-pill--{{ $sourceTone($row['source']) }}">{{ $sourceLabel($row['source']) }}</span>
                                <small class="mon-metric__source" style="display:block">{{ $row['source_detail'] }}</small>
                                @if (!empty($row['changed_at']))
                                    <small class="mon-metric__note" style="display:block">
                                        {{ translate('changed') }} {{ $row['changed_at'] }}
                                    </small>
                                @endif
                            </td>
                            <td>
                                {{ translate($row['what']) }}.
                                @if (!empty($row['note']))
                                    <small class="mon-metric__note" style="display:block">{{ $row['note'] }}</small>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-k.card>
@endforeach

<div class="d-flex justify-content-end mb-3">
    <button class="btn btn-primary px-4">{{ translate('save_settings') }}</button>
</div>
</form>

{{-- What is actually stored, including anything stored that this page does not present. A key in
     the table that no group renders is either housekeeping or a setting this panel has fallen
     behind on, and dropping it silently would hide the second. --}}
@if (($overrides['state'] ?? null) === 'ok')
    <x-k.card :title="translate('stored_overrides')">
        <p class="mon-note" style="margin-block-start:0">
            {{ translate('rows_in') }} <code>monitoring_settings</code>:
            <strong class="k-num">{{ number_format($overrides['total']) }}</strong>.
            {{ translate('in_effect') }}: <strong class="k-num">{{ number_format($overrides['applied']) }}</strong>.
            {{ translate('a_stored_row_only_changes_the_system_where_the_code_reads_that_key_back_through_monitoring_settings_which_today_is_the_thresholds_and_the_energy_block') }}.
        </p>

        @if (!empty($overrides['unmapped']))
            <div class="k-table-wrap">
                <table class="k-table k-table--compact">
                    <thead>
                    <tr>
                        <th>{{ translate('key') }}</th>
                        <th>{{ translate('type') }}</th>
                        <th>{{ translate('value') }}</th>
                        <th>{{ translate('changed') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($overrides['unmapped'] as $row)
                        <tr>
                            <td><code>{{ $row['key'] }}</code></td>
                            <td>{{ $row['type'] }}</td>
                            <td>
                                @if ($row['secret'])
                                    <span class="mon-pill mon-pill--{{ $row['configured'] ? 'ok' : 'info' }}">
                                        {{ $row['configured'] ? translate('configured') : translate('not_configured') }}
                                    </span>
                                @else
                                    <code>{{ $row['value'] }}</code>
                                @endif
                            </td>
                            <td class="k-num">{{ $row['changed_at'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mon-note">
                {{ translate('these_keys_are_stored_but_no_group_above_presents_them_they_are_either_housekeeping_written_by_the_system_or_settings_this_page_has_not_caught_up_with') }}.
                {{ translate('a_key_that_looks_like_a_credential_is_reported_as_present_or_absent_and_never_printed') }}.
            </p>
        @elseif ($overrides['total'] === 0)
            <p class="mon-note">
                {{ translate('nothing_has_been_overridden_every_value_above_is_the_one_this_deployment_shipped_with_or_was_given_in_its_environment') }}.
            </p>
        @endif

        @if (!empty($overrides['truncated']))
            <p class="mon-note mon-note--critical">
                {{ translate('the_settings_table_holds_more_rows_than_this_page_reads_so_the_list_above_is_incomplete') }}.
            </p>
        @endif
    </x-k.card>
@endif

<p class="mon-note">
    {{ translate('this_page_is_read_only_saving_arrives_with_the_write_action') }}.
    {{ translate('values_are_read_from') }} <code>config/monitoring.php</code>,
    <code>monitoring_settings</code>, {{ translate('and_the_process_environment') }};
    {{ translate('the_footprint_comes_from') }} <code>information_schema.tables</code>.
    {{ translate('generated') }} {{ $panel['generated']['at'] }} {{ $panel['generated']['timezone'] }} —
    {{ translate('every_timestamp_monitoring_stores_is_utc_and_converted_once_here_for_reading') }}.
</p>
