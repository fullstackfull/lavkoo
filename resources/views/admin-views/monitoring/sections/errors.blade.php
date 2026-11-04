{{--
    Errors: the bug behind the exceptions.

    The table is a list of groups, not of failures, because a group is the thing somebody can
    actually fix. Two numbers are kept apart on purpose — a group's lifetime occurrence counter and
    how many times it fired inside this window — since presenting the first as the second is the
    easiest lie this page could tell.

    An empty table reads as good news only when it has earned it: with collection switched off, or
    with nothing ever recorded, the same emptiness means something else entirely and says so.
--}}
@php
    $filters = $panel['filters'];
    $options = $panel['options'];
    $summary = $panel['summary'];
    $groupList = $panel['groups'];
    $selected = $panel['selected'] ?? null;
    $capture = $panel['capture'];
    $pagination = $groupList['pagination'] ?? null;

    // Every link on this page carries the window and the filter row: clicking a group must not
    // silently reset the view it was found in.
    $carried = array_filter([
        'range' => $range,
        'q' => $filters['q'],
        'status' => $filters['status'],
        'severity' => $filters['severity'],
        'channel' => $filters['channel'],
        'release' => $filters['release'],
        'route' => $filters['route'],
    ], static fn ($value) => $value !== null && $value !== '');

    $linkTo = static fn (array $extra = []) => route(
        'admin.monitoring.section',
        array_merge(['section' => 'errors'], $carried, $extra),
    );

    $clearUrl = route('admin.monitoring.section', ['section' => 'errors', 'range' => $range]);

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

    $statusTone = static fn (string $status) => match ($status) {
        'resolved' => 'ok',
        'ignored' => 'minor',
        default => 'warning',
    };

    // A value that arrived in the URL stays selectable even when the window no longer contains it,
    // so a shared link does not quietly drop half its filter.
    $withCurrent = static function (array $values, ?string $current): array {
        if ($current !== null && !in_array($current, $values, true)) {
            $values[] = $current;
        }
        return $values;
    };

    $count = static fn ($value) => is_numeric($value) ? number_format($value) : translate('no_data');
@endphp

{{-- Nothing below can be read as "the shop is fine" while nothing is being recorded. --}}
@if (($capture['collection_enabled'] ?? true) === false)
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--critical">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('monitoring_collection_is_switched_off') }}</strong>
                <small>{{ translate('this_page_can_only_show_what_was_recorded_before_it_was_turned_off') }}</small>
                <code>MONITORING_ENABLED=true</code>
            </span>
        </div>
    </div>
@endif

<div class="k-stats mon-stats">
    {{-- The fallback prints the state the panel reported, not "no data": a window that could not
         be read and a window that held nothing are different answers. --}}
    <x-k.stat :label="translate('error_groups_in_this_window')"
              :value="$summary['state'] === 'ok' ? $count($summary['groups']) : translate($summary['state'])"
              icon="alert" :caption="translate('grouped_by_fingerprint')" />

    <x-k.stat :label="translate('new_groups')"
              :value="$summary['state'] === 'ok' ? $count($summary['new_groups']) : translate($summary['state'])"
              icon="sparkles"
              :caption="$summary['state'] === 'ok'
                    ? $count($summary['recurring_groups']) . ' ' . translate('recurring')
                    : null" />

    <x-k.stat :label="translate('occurrences_in_this_window')"
              :value="($summary['occurrences']['state'] ?? '') === 'ok'
                    ? $count($summary['occurrences']['value'])
                    : translate($summary['occurrences']['state'] ?? 'no_data')"
              icon="reports" :caption="translate('rows_in_monitoring_errors')" />

    {{-- Accounts, not shoppers: an error on an admin or vendor route is recorded against that
         guard, and the tile counts every signed-in one of them. --}}
    <x-k.stat :label="translate('signed_in_accounts_affected')"
              :value="($summary['affected_users']['state'] ?? '') === 'ok'
                    ? $count($summary['affected_users']['value'])
                    : translate($summary['affected_users']['state'] ?? 'no_data')"
              icon="customers" :caption="translate('guests_are_not_counted')" />
</div>

@if ($summary['state'] !== 'ok' && isset($summary['message']))
    <p class="mon-note mon-note--critical">{{ translate('the_summary_could_not_be_read') }}: {{ $summary['message'] }}</p>
@endif
@if (!empty($summary['affected_users']['remedy']))
    <details class="mon-metric__remedy">
        <summary>{{ translate('why_this_is_not_being_counted') }}</summary>
        <code>{{ $summary['affected_users']['remedy'] }}</code>
    </details>
@endif

<x-k.card :padded="false">
    {{-- Filter state lives in the URL: a filtered error view is something people paste to each
         other during an incident. --}}
    {{-- Filter state lives in the URL: a filtered error view is something people paste to each
         other during an incident.

         Every control is wrapped in the toolbar's grow class rather than left bare, because
         `.k-select` is 100% wide by design — five of those in one flex row would each claim the
         full width and wrap onto five separate lines. --}}
    <form method="get" class="k-view__toolbar" role="search">
        <input type="hidden" name="range" value="{{ $range }}">

        <div class="k-view__toolbar-grow">
            <div class="k-search">
                <x-k.icon name="search" :size="15" />
                <input type="search" name="q" class="k-input" value="{{ $filters['q'] }}"
                       placeholder="{{ translate('search_message_exception_or_route') }}"
                       aria-label="{{ translate('search_message_exception_or_route') }}">
            </div>
        </div>

        <div class="k-view__toolbar-grow">
            <select name="status" class="k-select" aria-label="{{ translate('status') }}">
                @foreach (['open', 'resolved', 'ignored', 'all'] as $status)
                    <option value="{{ $status }}" @selected($filters['status'] === $status)>
                        {{ translate($status) }}
                        @if (isset($options['statuses'][$status]))
                            ({{ number_format($options['statuses'][$status]) }})
                        @endif
                    </option>
                @endforeach
            </select>
        </div>

        <div class="k-view__toolbar-grow">
            <select name="severity" class="k-select" aria-label="{{ translate('severity') }}">
                <option value="">{{ translate('any_severity') }}</option>
                @foreach ($withCurrent($options['severities'], $filters['severity']) as $severity)
                    <option value="{{ $severity }}" @selected($filters['severity'] === $severity)>{{ translate($severity) }}</option>
                @endforeach
            </select>
        </div>

        <div class="k-view__toolbar-grow">
            <select name="channel" class="k-select" aria-label="{{ translate('channel') }}">
                <option value="">{{ translate('any_channel') }}</option>
                @foreach ($withCurrent($options['channels'], $filters['channel']) as $channel)
                    <option value="{{ $channel }}" @selected($filters['channel'] === $channel)>{{ translate($channel) }}</option>
                @endforeach
            </select>
        </div>

        <div class="k-view__toolbar-grow">
            <select name="release" class="k-select" aria-label="{{ translate('release') }}">
                <option value="">{{ translate('any_release') }}</option>
                @foreach ($withCurrent($options['releases'], $filters['release']) as $release)
                    <option value="{{ $release }}" @selected($filters['release'] === $release)>{{ $release }}</option>
                @endforeach
            </select>
        </div>

        <div class="k-row">
            <x-k.button type="submit" variant="primary" size="sm" icon="filter">{{ translate('apply') }}</x-k.button>
            <x-k.button :href="$clearUrl" variant="ghost" size="sm">{{ translate('clear') }}</x-k.button>
        </div>

        {{-- Arrived from an endpoint's page. A list scoped to one route that does not say so reads
             as "this shop has one error", so the scope is stated and removable. --}}
        @if (!empty($filters['route']))
            <input type="hidden" name="route" value="{{ $filters['route'] }}">
            <p class="mon-note" style="margin-block-end:0">
                {{ translate('showing_only') }} <code>{{ $filters['route'] }}</code>
                — <a href="{{ route('admin.monitoring.section', array_merge(['section' => 'errors'], collect($carried)->except('route')->all())) }}">{{ translate('every_route') }}</a>
            </p>
        @endif
    </form>

    {{-- An unreadable filter list renders as "any severity, any channel, any release" with nothing
         behind it, which is indistinguishable from a window that simply holds none of them. Say
         which of the two it is. --}}
    @if (($options['state'] ?? 'ok') !== 'ok')
        <div class="k-card__body">
            <p class="mon-note mon-note--critical">
                {{ translate('the_filter_values_for_this_window_could_not_be_read_so_the_lists_above_are_empty') }}@if (!empty($options['message'])): {{ $options['message'] }}@endif
            </p>
        </div>
    @endif

    @if ($groupList['state'] === 'unavailable')
        <div class="k-card__body">
            <p class="mon-note mon-note--critical">{{ translate('the_error_groups_could_not_be_read') }}: {{ $groupList['message'] ?? '' }}</p>
            @if (!empty($groupList['remedy']))
                <details class="mon-metric__remedy" open>
                    <summary>{{ translate('how_to_fix_this') }}</summary>
                    <code>{{ $groupList['remedy'] }}</code>
                </details>
            @endif
        </div>
    @elseif ($groupList['state'] === 'empty')
        <div class="k-card__body">
            @php($reason = $groupList['reason'] ?? 'quiet_window')
            @if ($reason === 'collection_off')
                <x-k.empty icon="alert"
                           :title="translate('monitoring_collection_is_switched_off')"
                           :text="translate('an_empty_error_list_here_means_nothing_is_being_recorded_not_that_nothing_failed')" />
            @elseif ($reason === 'never_recorded')
                <x-k.empty icon="check"
                           :title="translate('no_errors_recorded_in_this_window')"
                           :text="translate('no_exception_has_ever_been_recorded_in_this_store_which_is_worth_confirming_if_it_has_been_serving_traffic')" />
                @if (!empty($capture['remedy']))
                    <details class="mon-metric__remedy">
                        <summary>{{ translate('how_errors_reach_this_page') }}</summary>
                        <code>{{ $capture['remedy'] }}</code>
                    </details>
                @endif
            @elseif ($reason === 'filtered_out')
                <x-k.empty icon="filter"
                           :title="translate('no_error_groups_match_these_filters')"
                           :text="translate('the_window_does_hold_errors_they_are_just_not_the_ones_asked_for')">
                    <x-slot:action>
                        <x-k.button :href="$clearUrl" variant="secondary" size="sm">{{ translate('clear_filters') }}</x-k.button>
                    </x-slot:action>
                </x-k.empty>
                @if (!empty($options['statuses']))
                    <p class="mon-note">
                        {{ translate('in_this_window') }}:
                        @foreach ($options['statuses'] as $status => $total)
                            {{ number_format($total) }} {{ translate($status) }}@if (!$loop->last), @endif
                        @endforeach
                    </p>
                @endif
            @elseif ($reason === 'unreadable')
                <x-k.empty icon="info"
                           :title="translate('this_window_could_not_be_checked_for_errors')"
                           :text="translate('the_error_store_did_not_answer_so_an_empty_list_here_proves_nothing')" />
            @else
                {{-- The good one. No hedging, no "no data" — the shop did not throw anything. --}}
                <x-k.empty icon="check"
                           :title="translate('no_errors_recorded_in_this_window')"
                           :text="translate('nothing_in_this_window_threw_an_exception_that_reached_monitoring')" />
            @endif
        </div>
    @else
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('exception') }}</th>
                    {{-- Status sits second, before the wide prose column, so the one field that
                         decides whether a row needs attention is readable without scrolling a
                         nine-column table sideways. --}}
                    <th>{{ translate('status') }}</th>
                    <th>{{ translate('message') }}</th>
                    <th>{{ translate('route') }}</th>
                    <th class="k-table__num" title="{{ translate('total_since_the_group_was_first_seen') }}">{{ translate('occurrences') }}</th>
                    <th class="k-table__num" title="{{ translate('occurrence_rows_recorded_inside_the_selected_window') }}">{{ translate('in_this_window') }}</th>
                    <th class="k-table__num" title="{{ translate('total_since_the_group_was_first_seen') }}">{{ translate('users') }}</th>
                    <th>{{ translate('first_seen') }}</th>
                    <th>{{ translate('last_seen') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($groupList['rows'] as $group)
                    <tr @if ($group['is_selected']) aria-selected="true" @endif>
                        <td>
                            <a href="{{ $linkTo(['group' => $group['id']]) }}#mon-error-group"
                               title="{{ $group['exception_class'] }}">{{ $group['exception_short'] }}</a>
                            @if ($group['is_new'])
                                <span class="mon-pill mon-pill--warning">{{ translate('new') }}</span>
                            @endif
                        </td>
                        <td><span class="mon-pill mon-pill--{{ $statusTone($group['status']) }}">{{ translate($group['status']) }}</span></td>
                        {{-- One line per group: an exception message is prose, and prose in an
                             auto-laid-out table wraps to four lines and drags every row with it.
                             The full text is in the title and in the group card below. --}}
                        <td class="k-truncate" title="{{ $group['message'] }}">{{ \Illuminate\Support\Str::limit($group['message'], 48) }}</td>
                        <td>{{ $group['route'] ?? translate('no_route') }}</td>
                        <td class="k-table__num k-num">{{ number_format($group['occurrences_all_time']) }}</td>
                        <td class="k-table__num k-num">
                            @if ($group['occurrences_in_window'] === null)
                                <span class="mon-metric__state">{{ translate('not_read') }}</span>
                            @else
                                {{ number_format($group['occurrences_in_window']) }}
                            @endif
                        </td>
                        <td class="k-table__num k-num">{{ number_format($group['affected_users_all_time']) }}</td>
                        <td class="k-num" title="{{ $group['first_seen']['at'] ?? '' }}">{{ $ago($group['first_seen']) }}</td>
                        <td class="k-num" title="{{ $group['last_seen']['at'] ?? '' }}">{{ $ago($group['last_seen']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        @if ($pagination !== null)
            <div class="k-pager">
                <span class="k-pager__info">
                    {{ translate('showing') }}
                    <span class="k-num">{{ number_format($pagination['from']) }}–{{ number_format($pagination['to']) }}</span>
                    {{ translate('of') }} <span class="k-num">{{ number_format($pagination['total']) }}</span>
                    {{ translate('error_groups') }}
                </span>
                <div class="k-pager__controls">
                    @if ($pagination['has_previous'])
                        <x-k.button :href="$linkTo(['page' => $pagination['page'] - 1])" variant="secondary" size="sm"
                                    icon="chevron-start">{{ translate('previous') }}</x-k.button>
                    @else
                        <x-k.button variant="secondary" size="sm" icon="chevron-start" disabled>{{ translate('previous') }}</x-k.button>
                    @endif

                    @if ($pagination['has_next'])
                        <x-k.button :href="$linkTo(['page' => $pagination['page'] + 1])" variant="secondary" size="sm"
                                    iconEnd="chevron-end">{{ translate('next') }}</x-k.button>
                    @else
                        <x-k.button variant="secondary" size="sm" iconEnd="chevron-end" disabled>{{ translate('next') }}</x-k.button>
                    @endif
                </div>
            </div>

            {{-- The offset is capped, so beyond this point "next" is disabled with pages still
                 behind it. A disabled control that does not say why reads as "this is the end". --}}
            @if (!empty($pagination['capped']) && $pagination['page'] >= $pagination['max_page'])
                <p class="mon-note">
                    {{ translate('paging_stops_at_page') }} {{ number_format($pagination['max_page']) }} —
                    {{ translate('narrow_the_window_or_the_filters_to_reach_the_rest') }}
                </p>
            @endif
        @endif
    @endif

    @if (!empty($options['truncated']))
        <p class="mon-note">{{ translate('this_window_holds_more_filter_values_than_are_listed_above') }}</p>
    @endif
</x-k.card>

@if ($selected !== null)
    <x-k.card id="mon-error-group" :title="translate('selected_error_group')">
        <x-slot:actions>
            <x-k.button :href="$linkTo()" variant="ghost" size="sm" icon="close">{{ translate('close') }}</x-k.button>
        </x-slot:actions>

        @if ($selected['state'] === 'unavailable')
            <p class="mon-note mon-note--critical">{{ translate('this_error_group_could_not_be_read') }}: {{ $selected['message'] ?? '' }}</p>
        @elseif ($selected['state'] !== 'ok')
            <x-k.empty icon="info"
                       :title="translate('this_error_group_is_no_longer_stored')"
                       :text="translate('groups_are_pruned_once_they_pass_the_retention_period')" />
            @if (!empty($selected['remedy']))
                <details class="mon-metric__remedy">
                    <summary>{{ translate('why_this_happened') }}</summary>
                    <code>{{ $selected['remedy'] }}</code>
                </details>
            @endif
        @else
            @php($group = $selected['group'])
            @php($occurrences = $selected['occurrences'])

            <h3 class="mon-heading">{{ $group['exception_class'] }}</h3>
            <p class="mon-note">{{ $group['message'] }}</p>

            <div class="mon-grid">
                <div class="mon-metric">
                    <span class="mon-metric__label">{{ translate('status') }}</span>
                    <span class="mon-metric__value">
                        <span class="mon-pill mon-pill--{{ $statusTone($group['status']) }}">{{ translate($group['status']) }}</span>
                        <span class="mon-pill">{{ translate($group['severity']) }}</span>
                    </span>
                    <span class="mon-metric__source">monitoring_error_groups.status</span>
                </div>

                <div class="mon-metric">
                    <span class="mon-metric__label">{{ translate('where_it_was_thrown') }}</span>
                    <span class="mon-metric__note">{{ $group['file'] ?? translate('no_file_recorded') }}@if ($group['line']):{{ $group['line'] }}@endif</span>
                    <span class="mon-metric__note">{{ $group['route'] ?? translate('no_route') }} · {{ $group['channel'] ?? translate('no_channel') }}</span>
                </div>

                <div class="mon-metric">
                    <span class="mon-metric__label">{{ translate('occurrences') }}</span>
                    <span class="mon-metric__value k-num">{{ number_format($group['occurrences_all_time']) }}<i>{{ translate('all_time') }}</i></span>
                    @if ($group['occurrences_in_window'] === null)
                        <span class="mon-metric__state">{{ translate('not_read') }}</span>
                    @else
                        <span class="mon-metric__note">{{ number_format($group['occurrences_in_window']) }} {{ translate('in_this_window') }}</span>
                    @endif
                </div>

                <div class="mon-metric">
                    <span class="mon-metric__label">{{ translate('affected_users') }}</span>
                    <span class="mon-metric__value k-num">{{ number_format($group['affected_users_all_time']) }}<i>{{ translate('all_time') }}</i></span>
                    <span class="mon-metric__source">monitoring_error_groups.affected_users</span>
                </div>

                <div class="mon-metric">
                    <span class="mon-metric__label">{{ translate('first_seen') }}</span>
                    <span class="mon-metric__value">{{ $group['first_seen']['at'] ?? translate('no_data') }}</span>
                    <span class="mon-metric__note">{{ $ago($group['first_seen']) }}{{ $group['is_new'] ? ' · ' . translate('new_in_this_window') : '' }}</span>
                </div>

                <div class="mon-metric">
                    <span class="mon-metric__label">{{ translate('last_seen') }}</span>
                    <span class="mon-metric__value">{{ $group['last_seen']['at'] ?? translate('no_data') }}</span>
                    <span class="mon-metric__note">{{ $ago($group['last_seen']) }}</span>
                </div>

                <div class="mon-metric">
                    <span class="mon-metric__label">{{ translate('release') }}</span>
                    <span class="mon-metric__note">{{ translate('first') }}: {{ $group['release'] ?? translate('no_data') }}</span>
                    <span class="mon-metric__note">{{ translate('latest') }}: {{ $group['last_release'] ?? translate('no_data') }}</span>
                </div>

                <div class="mon-metric">
                    <span class="mon-metric__label">{{ translate('fingerprint') }}</span>
                    <span class="mon-metric__note">{{ $group['fingerprint'] }}</span>
                    <span class="mon-metric__source">{{ translate('exception_class_plus_normalised_message_plus_top_application_frame') }}</span>
                </div>
            </div>

            {{-- One trace, not ten. Every occurrence in a group shares the fingerprint that was
                 built from these frames, so the newest copy is the whole evidence. --}}
            <h3 class="mon-heading">{{ translate('stack_trace') }}</h3>
            @if (($occurrences['stack_trace'] ?? null) !== null)
                <p class="mon-note">
                    {{ translate('from_the_most_recent_occurrence') }}
                    @if (!empty($occurrences['stack_trace_from']['at']['at']))
                        · {{ $occurrences['stack_trace_from']['at']['at'] }}
                    @endif
                    @if (!empty($occurrences['stack_trace_from']['request_id']))
                        · {{ translate('request_id') }} {{ $occurrences['stack_trace_from']['request_id'] }}
                    @endif
                </p>
                <pre class="mon-pre">{{ $occurrences['stack_trace']['text'] }}</pre>
                @if ($occurrences['stack_trace']['truncated'])
                    <p class="mon-note">{{ translate('this_trace_was_cut_short_for_display') }}</p>
                @endif
                <p class="mon-note">{{ translate('secrets_are_masked_before_this_is_stored_and_again_before_it_is_shown') }}</p>
            @elseif (($occurrences['state'] ?? '') === 'ok')
                <p class="mon-note">{{ translate('no_stack_trace_was_stored_with_this_occurrence') }}</p>
            @elseif (($occurrences['state'] ?? '') === 'unavailable')
                <p class="mon-note mon-note--critical">{{ translate('the_occurrence_that_carries_the_stack_trace_could_not_be_read') }}</p>
            @else
                {{-- The heading used to stand alone here with nothing under it, which reads as a
                     trace that is missing rather than one that was never in range. --}}
                <p class="mon-note">{{ translate('the_stack_trace_comes_from_an_occurrence_row_and_none_of_this_groups_occurrences_fall_inside_this_window') }}</p>
            @endif

            <h3 class="mon-heading">{{ translate('recent_occurrences') }}</h3>
            @if (($occurrences['state'] ?? '') === 'ok')
                <div class="k-table-wrap">
                    <table class="k-table k-table--compact">
                        <thead>
                        <tr>
                            <th>{{ translate('when') }}</th>
                            <th>{{ translate('request_id') }}</th>
                            <th>{{ translate('trace_id') }}</th>
                            <th>{{ translate('route') }}</th>
                            <th>{{ translate('method') }}</th>
                            <th class="k-table__num">{{ translate('status') }}</th>
                            <th>{{ translate('platform') }}</th>
                            <th>{{ translate('app_version') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($occurrences['rows'] as $occurrence)
                            <tr>
                                <td class="k-table__num k-num">{{ $occurrence['at']['at'] ?? translate('no_data') }}</td>
                                <td class="k-num">{{ $occurrence['request_id'] ?? translate('no_data') }}</td>
                                {{-- The two ways out of this row: the trace that recorded the request,
                                     and what the route it hit actually is. The traces section takes a
                                     trace filter and the Developer Portal resolves a path, so both are
                                     one link rather than a copy and a search. --}}
                                <td class="k-num">
                                    @if (!empty($occurrence['trace_id']))
                                        <a href="{{ route('admin.monitoring.section', ['section' => 'traces', 'trace' => $occurrence['trace_id'], 'range' => $range]) }}#mon-trace">{{ $occurrence['trace_id'] }}</a>
                                    @else
                                        {{ translate('no_data') }}
                                    @endif
                                </td>
                                <td>
                                    @if (!empty($occurrence['route']))
                                        <a href="{{ route('admin.developer.lookup', ['path' => $occurrence['route'], 'method' => $occurrence['method'] ?? null]) }}">{{ $occurrence['route'] }}</a>
                                    @else
                                        {{ translate('no_route') }}
                                    @endif
                                </td>
                                <td>{{ $occurrence['method'] ?? translate('no_data') }}</td>
                                <td class="k-table__num k-num">{{ $occurrence['status'] ?? translate('no_data') }}</td>
                                <td>{{ $occurrence['platform'] ?? translate('no_data') }}</td>
                                <td class="k-num">{{ $occurrence['app_version'] ?? translate('no_data') }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                {{-- Ten rows under a tile reading "forty in this window" is a contradiction unless
                     the table says it is the newest slice. --}}
                @if (!empty($occurrences['limited']))
                    <p class="mon-note">
                        {{ translate('showing_the_most_recent') }} {{ number_format($occurrences['limit']) }}
                        {{ translate('occurrences_of_this_group_in_this_window') }}
                    </p>
                @endif
            @elseif (($occurrences['state'] ?? '') === 'unavailable')
                <p class="mon-note mon-note--critical">{{ translate('the_occurrences_could_not_be_read') }}: {{ $occurrences['message'] ?? '' }}</p>
            @else
                <p class="mon-note">{{ translate('no_occurrence_rows_for_this_group_fall_inside_this_window') }}</p>
                @if (!empty($occurrences['remedy']))
                    <details class="mon-metric__remedy">
                        <summary>{{ translate('why_this_happened') }}</summary>
                        <code>{{ $occurrences['remedy'] }}</code>
                    </details>
                @endif
            @endif
        @endif
    </x-k.card>
@endif

<p class="mon-note">
    {{ translate('read_from') }} {{ $panel['source'] }} ·
    {{ translate('window_starts') }} {{ $panel['window']['since'] }} ({{ $panel['window']['timezone'] }}) ·
    {{ translate('occurrences_are_kept_for') }} {{ $capture['retention_days'] }} {{ translate('days') }}
</p>
