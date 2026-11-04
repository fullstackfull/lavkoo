{{--
    Logs: the lines the application wrote, read from the file it wrote them to.

    Two things are said before any line is shown, because both change what an empty list means.
    Which file is being read, and how much of it — the tail is capped, so "these are the last
    entries" is a different claim from "this is the log". And the level threshold: at LOG_LEVEL
    warning there is no info line to find, so a quiet page is a fact about the threshold rather
    than about the shop.

    Nothing is parsed here. The panel already split the file, redacted every message and context,
    and decided what has a stack trace behind it.
--}}
@php
    $channel = $panel['channel'];
    $file = $panel['file'];
    $files = $panel['files'];
    $scan = $panel['scan'];
    $filters = $panel['filters'];
    $entries = $panel['entries'];
    $counts = $panel['counts'];

    $bytes = static function (?int $value) {
        if ($value === null) {
            return translate('no_data');
        }
        return match (true) {
            $value >= 1073741824 => number_format($value / 1073741824, 2) . ' GB',
            $value >= 1048576 => number_format($value / 1048576, 1) . ' MB',
            $value >= 1024 => number_format($value / 1024, 1) . ' KB',
            default => number_format($value) . ' B',
        };
    };

    $ago = static function (?float $minutes) {
        if ($minutes === null) {
            return translate('no_data');
        }
        return match (true) {
            $minutes < 1 => translate('just_now'),
            $minutes < 90 => round($minutes) . 'm ' . translate('ago'),
            $minutes < 2880 => round($minutes / 60) . 'h ' . translate('ago'),
            default => round($minutes / 1440) . 'd ' . translate('ago'),
        };
    };

    // Filter state lives in the URL: a filtered log view is something people paste to each other
    // during an incident, and it has to survive the paste.
    $carried = array_filter([
        'range' => $range,
        'level' => $filters['level'],
        'q' => $filters['q'],
        'date' => $filters['date'],
        'file' => $filters['file'],
    ], static fn ($value) => $value !== null && $value !== '');

    $linkTo = static fn (array $extra = []) => route(
        'admin.monitoring.section',
        array_merge(['section' => 'logs'], array_filter(array_merge($carried, $extra), static fn ($value) => $value !== null)),
    );

    $clearUrl = route('admin.monitoring.section', ['section' => 'logs', 'range' => $range]);

    // Every level that appears in the portion read, plus the one being filtered on even when it
    // does not: a chip that vanishes the moment it stops matching cannot be un-clicked.
    $chipLevels = collect($panel['levels'])
        ->filter(fn ($level) => ($counts[$level] ?? 0) > 0 || $filters['level'] === $level)
        ->values();
@endphp

{{-- The channel writes nothing this page can read. Everything below would be an empty list with no
     explanation, so the explanation comes first. --}}
@if ($channel['state'] === 'not_supported' || $file['state'] === 'permission_denied' || $files['state'] === 'permission_denied')
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--critical">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>
                    {{ $channel['state'] === 'not_supported'
                        ? translate('the_active_log_channel_does_not_write_a_file_on_this_server')
                        : translate('the_log_file_cannot_be_read_by_this_process') }}
                </strong>
                <small>{{ $channel['note'] ?? $file['note'] ?? $files['note'] ?? '' }}</small>
                @php($remedy = $channel['remedy'] ?? $file['remedy'] ?? $files['remedy'] ?? null)
                @if ($remedy)
                    <code>{{ $remedy }}</code>
                @endif
            </span>
        </div>
    </div>
@endif

{{-- The threshold, stated as a fact rather than left to be discovered. --}}
@if ($channel['state'] === 'ok' && !empty($channel['never_written']))
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--info">
            <x-k.icon name="info" :size="16" />
            <span class="mon-attention__body">
                <strong>
                    {{ translate('log_level_is') }} {{ strtoupper($channel['level']) }} —
                    {{ translate('these_levels_are_never_written_to_the_file') }}:
                    {{ collect($channel['never_written'])->map(fn ($level) => translate($level))->implode(', ') }}
                </strong>
                <small>{{ translate('an_empty_log_here_is_a_statement_about_the_threshold_not_about_the_application') }}</small>
                <code>LOG_LEVEL=debug — php artisan config:clear</code>
            </span>
        </div>
    </div>
@endif

<div class="k-stats mon-stats">
    <x-k.stat :label="translate('entries_shown')"
              :value="$entries['state'] === 'ok' ? number_format($entries['returned']) : translate('no_data')"
              icon="orders"
              :caption="$entries['state'] === 'ok'
                    ? number_format($entries['found']) . ' ' . translate('found_in_the_portion_read')
                    : translate('nothing_matched_in_the_portion_read')" />

    <x-k.stat :label="translate('log_file')"
              :value="$file['name'] ?? translate('no_data')"
              icon="reports"
              :caption="$file['state'] === 'ok' ? $bytes($file['bytes']) : translate($file['state'])" />

    {{-- Age leads, the stamp explains it. A log file untouched for two days on a shop taking
         orders does not mean nothing went wrong — it usually means logging moved somewhere else. --}}
    <x-k.stat :label="translate('last_written')"
              :value="$file['state'] === 'ok' ? $ago($file['age_minutes']) : translate('no_data')"
              icon="clock"
              :caption="$file['modified_at'] ?? translate('nothing_has_been_written')" />

    <x-k.stat :label="translate('read_from_the_end_of_the_file')"
              :value="$scan['state'] === 'ok' ? $bytes($scan['bytes_read']) : translate('no_data')"
              icon="download"
              :caption="($scan['reached_start'] ?? false)
                    ? translate('the_whole_file')
                    : translate('the_tail_only_the_file_is') . ' ' . $bytes($scan['bytes_total'])" />

    <x-k.stat :label="translate('log_channel')"
              :value="$channel['state'] === 'ok' ? $channel['name'] . ' (' . $channel['driver'] . ')' : translate('not_supported')"
              icon="settings"
              :caption="$channel['path'] ?? translate('no_file_path')" />
</div>

<x-k.card :padded="false">
    {{-- Level chips are links rather than a select: they carry a count each, and picking one is
         the single most common thing done on this page. --}}
    <div class="mon-log__levels">
        <a class="mon-log__chip {{ $filters['level'] === null ? 'is-active' : '' }}" href="{{ $linkTo(['level' => null]) }}">
            {{ translate('all_levels') }}
            @if ($entries['found'] ?? 0)<i class="k-num">{{ number_format($entries['found']) }}</i>@endif
        </a>
        @foreach ($chipLevels as $level)
            <a class="mon-log__chip mon-log__chip--{{ $level }} {{ $filters['level'] === $level ? 'is-active' : '' }}"
               href="{{ $linkTo(['level' => $level]) }}">
                {{ translate($level) }}
                <i class="k-num">{{ number_format($counts[$level] ?? 0) }}</i>
            </a>
        @endforeach
        @if ($chipLevels->isEmpty())
            <span class="mon-note">{{ translate('no_level_appears_in_the_portion_read') }}</span>
        @endif
    </div>

    {{-- Every control is wrapped in the toolbar's grow class rather than left bare, because
         `.k-select` is 100% wide by design and three bare ones wrap onto three lines. --}}
    <form method="get" class="k-view__toolbar" role="search">
        <input type="hidden" name="range" value="{{ $range }}">
        @if ($filters['level'])
            <input type="hidden" name="level" value="{{ $filters['level'] }}">
        @endif

        <div class="k-view__toolbar-grow">
            <div class="k-search">
                <x-k.icon name="search" :size="15" />
                <input type="search" name="q" class="k-input" value="{{ $filters['q'] }}"
                       placeholder="{{ translate('search_the_entries_that_were_read') }}"
                       aria-label="{{ translate('search_the_entries_that_were_read') }}">
            </div>
        </div>

        <div class="k-view__toolbar-grow">
            <input type="date" name="date" class="k-input" value="{{ $filters['date'] }}"
                   aria-label="{{ translate('date') }}">
        </div>

        <div class="k-view__toolbar-grow">
            <select name="file" class="k-select" aria-label="{{ translate('log_file') }}">
                <option value="">{{ translate('current_log_file') }}</option>
                @foreach ($files['items'] as $item)
                    <option value="{{ $item['name'] }}" @selected($filters['file'] === $item['name'])>
                        {{ $item['name'] }} — {{ $bytes($item['bytes']) }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="k-row">
            <x-k.button type="submit" variant="primary" size="sm" icon="filter">{{ translate('apply') }}</x-k.button>
            <x-k.button :href="$clearUrl" variant="ghost" size="sm">{{ translate('clear') }}</x-k.button>
        </div>
    </form>

    @if ($filters['file_rejected'])
        <div class="k-card__body">
            <p class="mon-note mon-note--critical">
                {{ translate('that_log_file_is_not_one_of_the_files_in_this_directory_so_the_current_one_was_read_instead') }}
            </p>
        </div>
    @endif

    @if ($entries['state'] === 'ok')
        <ul class="mon-log">
            @php($previousDate = null)
            @foreach ($entries['items'] as $entry)
                @if ($entry['date'] !== $previousDate)
                    @php($previousDate = $entry['date'])
                    <li class="mon-log__day">
                        {{ $entry['date'] ?? translate('undated_entries') }}
                        <small>{{ translate('shown_in') }} {{ $panel['window']['timezone'] }}</small>
                    </li>
                @endif

                <li class="mon-log__row mon-log__row--{{ $entry['tone'] }}">
                    <span class="mon-log__level mon-log__level--{{ $entry['level'] }}">{{ translate($entry['level']) }}</span>
                    <span class="mon-log__time k-num" title="{{ $entry['at'] ?? $entry['raw_at'] }}">
                        {{ $entry['time'] ?? '—' }}
                    </span>

                    <span class="mon-log__body">
                        <span class="mon-log__message">{{ $entry['message'] ?? translate('this_entry_has_no_message') }}</span>

                        <span class="mon-log__meta">
                            <span class="mon-log__channel">{{ $entry['channel'] }}</span>
                            @if ($entry['correlation_id'])
                                {{-- The pivot: one correlation id ties this line to the request that
                                     produced it, and to every other line that request wrote. --}}
                                <code title="{{ translate('correlation_id') }}">{{ $entry['correlation_id'] }}</code>
                            @endif
                            @if ($entry['request_id'])
                                <code title="{{ translate('request_id') }}">{{ $entry['request_id'] }}</code>
                            @endif
                            @if ($entry['has_trace'])
                                <span class="mon-log__flag">{{ translate('stack_trace') }}</span>
                            @endif
                        </span>

                        @if ($entry['detail'])
                            <details class="mon-log__trace">
                                <summary>
                                    {{ $entry['has_trace'] ? translate('stack_trace_and_context') : translate('context') }}
                                </summary>
                                <pre class="mon-pre">{{ $entry['detail'] }}</pre>
                            </details>
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>

        <div class="k-card__body">
            <p class="mon-note">
                {{ translate('every_message_and_context_on_this_page_has_been_redacted_before_it_reached_the_screen') }}
                @if ($entries['capped'])
                    — {{ translate('the_newest') }} {{ number_format($scan['cap_entries']) }}
                    {{ translate('entries_are_shown_the_portion_read_holds_more') }}
                @elseif ($entries['scan_capped'])
                    — {{ translate('the_filter_pass_stopped_after') }} {{ number_format($entries['examined']) }}
                    {{ translate('entries_narrow_the_window_with_a_date_to_reach_further_back') }}
                @endif
            </p>
            <p class="mon-note">
                {{ translate('the_range_selector_does_not_narrow_this_section_a_log_file_is_not_bucketed_use_the_date_filter') }}
            </p>
        </div>
    @else
        <div class="k-card__body">
            @if ($entries['state'] === 'permission_denied')
                <x-k.empty icon="alert"
                           :title="translate('the_log_file_cannot_be_read_by_this_process')"
                           :text="$file['note'] ?? ''" />
                @if (!empty($file['remedy']))
                    <details class="mon-metric__remedy" open>
                        <summary>{{ translate('how_to_fix_this') }}</summary>
                        <code>{{ $file['remedy'] }}</code>
                    </details>
                @endif
            @elseif ($entries['state'] === 'not_supported')
                <x-k.empty icon="settings"
                           :title="translate('this_log_channel_writes_no_file_to_read')"
                           :text="$channel['note'] ?? ''" />
                @if (!empty($channel['remedy']))
                    <details class="mon-metric__remedy" open>
                        <summary>{{ translate('how_to_enable_this') }}</summary>
                        <code>{{ $channel['remedy'] }}</code>
                    </details>
                @endif
            @elseif ($entries['state'] === 'unrecognised')
                <x-k.empty icon="info"
                           :title="translate('the_portion_read_holds_no_laravel_log_entry')"
                           :text="translate('either_this_file_is_written_in_another_format_or_one_entry_is_longer_than_the_whole_tail_that_was_read')" />
            @elseif ($entries['state'] === 'failed')
                <p class="mon-note mon-note--critical">
                    {{ translate('the_log_file_could_not_be_parsed') }}: {{ $entries['reason'] ?? '' }}
                </p>
            @elseif ($entries['reason'] === 'filtered_out')
                <x-k.empty icon="filter"
                           :title="translate('no_entry_matches_these_filters')"
                           :text="translate('the_portion_read_does_hold_entries_they_are_just_not_the_ones_asked_for')">
                    <x-slot:action>
                        <x-k.button :href="$clearUrl" variant="secondary" size="sm">{{ translate('clear_filters') }}</x-k.button>
                    </x-slot:action>
                </x-k.empty>
            @else
                <x-k.empty icon="check"
                           :title="translate('nothing_has_been_logged')"
                           :text="$file['note'] ?? translate('the_log_file_is_empty')" />
                @if ($channel['state'] === 'ok' && !empty($channel['never_written']))
                    <p class="mon-note">
                        {{ translate('remember_that_only_these_levels_reach_the_file') }}:
                        {{ collect($panel['levels'])->reject(fn ($level) => in_array($level, $channel['never_written'], true))->map(fn ($level) => translate($level))->implode(', ') }}
                    </p>
                @endif
            @endif
        </div>
    @endif
</x-k.card>

<x-k.card :title="translate('log_files_on_this_server')">
    @if ($files['state'] === 'ok')
        <div class="k-table-wrap">
            <table class="k-table">
                <thead>
                <tr>
                    <th>{{ translate('file') }}</th>
                    <th class="k-table__num">{{ translate('size') }}</th>
                    <th>{{ translate('last_written') }}</th>
                    <th>{{ translate('readable') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($files['items'] as $item)
                    <tr class="{{ $item['readable'] ? '' : 'mon-row--muted' }}">
                        <td>
                            <a href="{{ $linkTo(['file' => $item['name']]) }}">{{ $item['name'] }}</a>
                            @if (($file['name'] ?? null) === $item['name'])
                                <span class="mon-pill mon-pill--ok">{{ translate('being_read') }}</span>
                            @endif
                        </td>
                        <td class="k-table__num k-num">{{ $bytes($item['bytes']) }}</td>
                        <td class="k-num">{{ $item['modified_at'] ?? translate('no_data') }}</td>
                        <td>{{ $item['readable'] ? translate('yes') : translate('permission_denied') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="mon-note">
            {{ $files['directory'] }} — {{ number_format($files['total']) }} {{ translate('files') }}.
            {{ translate('an_unrotated_log_file_is_the_most_common_way_a_healthy_server_runs_out_of_disk') }}
        </p>
    @else
        <x-k.empty icon="info"
                   :title="translate($files['state'])"
                   :text="$files['note'] ?? ''" />
        @if (!empty($files['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_fix_this') }}</summary>
                <code>{{ $files['remedy'] }}</code>
            </details>
        @endif
    @endif
    <p class="mon-metric__source">{{ $panel['source'] }}</p>
</x-k.card>
