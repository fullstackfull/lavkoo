{{-- Analytics grading itself. A pipeline that cannot report its own failure draws a flat line
     everybody reads as a quiet week. --}}
<x-k.card :title="translate('collection_health')">
    <div class="ana-health ana-health--{{ $data['health']['state'] }}">
        <strong>{{ translate($data['health']['state']) }}</strong>
        @if (isset($data['health']['message_key']))
            <p>{{ translate($data['health']['message_key']) }}</p>
            @if (!empty($data['health']['detail']))
                <p><code>{{ $data['health']['detail'] }}</code></p>
            @endif
        @endif
    </div>
    <ul class="ana-list">
        @isset($data['health']['last_event_at'])
            <li><span>{{ translate('last_event') }}</span><strong>{{ $data['health']['last_event_at'] }} ({{ $data['health']['last_event_age_minutes'] ?? '—' }} {{ translate('minutes_ago') }})</strong></li>
        @endisset
        @isset($data['health']['last_rollup_at'])
            <li><span>{{ translate('last_rollup') }}</span><strong>{{ $data['health']['last_rollup_at'] }}</strong></li>
        @endisset
        @isset($data['health']['write_failures'])
            <li>
                <span>{{ translate('failed_writes') }}</span>
                <strong class="{{ $data['health']['write_failures'] > 0 ? 'ana-warn' : '' }}">{{ number_format($data['health']['write_failures']) }}</strong>
            </li>
        @endisset
    </ul>
    @if (($data['health']['write_failure_detail'] ?? null))
        <p class="ana-warn">{{ $data['health']['write_failure_detail'] }}</p>
    @endif
</x-k.card>

<x-k.card :title="translate('traffic_excluded_from_every_figure')">
    <p class="ana-note">{{ translate('bots_and_staff_are_recorded_and_flagged_never_deleted_a_filter_nobody_can_measure_is_indistinguishable_from_one_that_has_stopped_working') }}</p>
    @if (($data['excluded']['rows'] ?? []) === [])
        <x-k.empty :title="translate('nothing_was_excluded')" :text="translate('no_bot_or_staff_traffic_was_recorded_in_this_period')" />
    @else
        <div class="ana-metrics">
            <div class="ana-metric">
                <small>{{ translate('counted_as_customers') }}</small>
                <span class="k-num">{{ number_format($data['excluded']['counted_sessions']) }}</span>
            </div>
            <div class="ana-metric">
                <small>{{ translate('excluded') }}</small>
                <span class="k-num">
                    {{ number_format($data['excluded']['excluded_sessions']) }}@if ($data['excluded']['overlaps'] ?? false)<i>–{{ number_format($data['excluded']['excluded_sessions_upper']) }}</i>@endif
                </span>
                <span class="ana-change ana-change--none">
                    {{ $data['excluded']['excluded_share'] }}% {{ translate('of_all_recorded_visits') }}
                    @if ($data['excluded']['overlaps'] ?? false)
                        — {{ translate('a_range_because_a_visit_can_be_both_a_bot_and_staff') }}
                    @endif
                </span>
            </div>
        </div>
        <table class="ana-table">
            <thead><tr><th>{{ translate('kind') }}</th><th class="ana-num">{{ translate('visits') }}</th><th class="ana-num">{{ translate('visitors') }}</th><th class="ana-num">{{ translate('pageviews') }}</th></tr></thead>
            <tbody>
            @foreach ($data['excluded']['rows'] as $row)
                <tr>
                    <td>{{ translate($row['kind']) }}</td>
                    <td class="ana-num">{{ number_format($row['sessions']) }}</td>
                    <td class="ana-num">{{ number_format($row['visitors']) }}</td>
                    <td class="ana-num">{{ number_format($row['pageviews']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</x-k.card>

@include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['events'], 'title' => translate('what_is_being_recorded'), 'label' => translate('event'), 'dimension' => 'event', 'window' => $window, 'showEngagement' => false])

{{-- What the pipeline itself did.

     `events_written` and `events_dropped_buffer_full` are recorded explicitly by EventRecorder so
     this screen can show them, and nothing read either — a request loop quietly shortening the
     numbers was recorded and displayed nowhere. The privacy counters answer the question a shop
     asks the day it turns consent on and watches its reported traffic fall. --}}
@php($pipeline = $data['pipeline'] ?? ['state' => 'not_installed'])

@if (($pipeline['state'] ?? '') === 'ok')
    <x-k.card :title="translate('the_pipeline_itself')">
        <div class="k-stats">
            <x-k.stat :label="translate('events_written')" :value="number_format($pipeline['events_written'])" icon="check" />
            <x-k.stat :label="translate('dropped_buffer_full')" :value="number_format($pipeline['events_dropped_buffer_full'])"
                      icon="{{ $pipeline['events_dropped_buffer_full'] > 0 ? 'alert' : 'settings' }}"
                      :caption="$pipeline['drop_share'] === null ? null : $pipeline['drop_share'] . '%'" />
            <x-k.stat :label="translate('write_failures')" :value="number_format($pipeline['write_failed'])" icon="warning-octagon" />
        </div>
        @if ($pipeline['events_dropped_buffer_full'] > 0)
            <p class="mon-note mon-note--critical">
                {{ translate('events_reached_the_recorder_and_were_thrown_away_because_one_request_produced_more_than_the_buffer_holds') }}.
                {{ translate('every_number_on_every_analytics_screen_is_short_by_that_much') }}.
            </p>
        @endif
    </x-k.card>

    <x-k.card :title="translate('visits_we_chose_not_to_measure')">
        @php($privacy = $pipeline['privacy'])
        @if (!$privacy['respect_do_not_track'] && !$privacy['require_consent'])
            <x-k.empty icon="shield" :title="translate('neither_privacy_control_is_switched_on')"
                       :text="translate('no_visit_is_being_refused_so_nothing_is_missing_from_the_figures_for_this_reason')" />
        @else
            <div class="k-stats">
                @if ($privacy['respect_do_not_track'])
                    <x-k.stat :label="translate('do_not_track')" :value="number_format($privacy['do_not_track'])" icon="shield" />
                @endif
                @if ($privacy['require_consent'])
                    <x-k.stat :label="translate('consent_not_given')" :value="number_format($privacy['consent_not_given'])" icon="shield" />
                @endif
                <x-k.stat :label="translate('total_refused')" :value="number_format($privacy['total'])" icon="info" />
            </div>
            <p class="mon-note">
                {{ translate('these_visits_were_deliberately_not_measured_they_are_the_reason_the_figures_are_lower_than_your_server_logs') }}.
            </p>
        @endif
    </x-k.card>
@endif
