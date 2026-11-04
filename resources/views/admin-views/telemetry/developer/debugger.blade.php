{{--
    Request debugger: an id in, everything the system recorded about that one request out.

    The Errors page tells developers to keep the X-Request-Id because it is what makes a failure
    findable. This is where it becomes findable. The lookup is exact on purpose — an id either
    matches or it does not, and a search that helpfully widened to "around that time" would return
    another request's stack trace with the confidence of an exact match.
--}}

@php($lookup = $data['lookup'] ?? ['state' => 'waiting', 'note' => null, 'errors' => [], 'trace' => null, 'spans' => []])

<x-k.card :title="translate('look_up_a_request')">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-8">
            <label class="form-label fs-12 mb-1" for="request-id">{{ translate('request_id') }}</label>
            <input id="request-id" name="request_id" class="form-control form-control-sm" maxlength="40"
                   value="{{ $lookup['id'] ?? '' }}" placeholder="a1b2c3d4e5f60718">
        </div>
        <div class="col-md-2">
            <button class="btn btn-primary btn-sm w-100">{{ translate('look_up') }}</button>
        </div>
    </form>
    @if (!empty($lookup['note']))
        <p class="mon-note">{{ translate($lookup['note']) }}.</p>
    @endif
</x-k.card>

@if (($lookup['state'] ?? '') === 'ok')
    @if ($lookup['trace'])
        @php($trace = $lookup['trace'])
        <x-k.card :title="translate('the_request')">
            <div class="k-stats">
                <x-k.stat :label="translate('route')" :value="$trace['route'] ?: '—'" icon="orders"
                          :caption="$trace['method'] . ' · ' . ($trace['status'] ?? '—')" />
                <x-k.stat :label="translate('total')" :value="($trace['duration_ms'] ?? '—') . ' ms'" icon="clock"
                          :caption="translate('kept_because') . ': ' . translate($trace['captured_because'])" />
                <x-k.stat :label="translate('database')" :value="($trace['db_ms'] ?? '—') . ' ms'" icon="catalog"
                          :caption="($trace['db_queries'] ?? 0) . ' ' . translate('queries')" />
                <x-k.stat :label="translate('external')" :value="($trace['external_ms'] ?? '—') . ' ms'" icon="external" />
                <x-k.stat :label="translate('started')" :value="$trace['started_at']" icon="trend-up"
                          :caption="$trace['release'] ? translate('release') . ' ' . $trace['release'] : null" />
            </div>
        </x-k.card>

        @if (!empty($lookup['spans']))
            <x-k.card :title="translate('where_the_time_went')">
                <div class="k-table-wrap">
                    <table class="k-table k-table--compact">
                        <thead><tr>
                            <th>{{ translate('span') }}</th><th>{{ translate('kind') }}</th>
                            <th class="k-table__num">{{ translate('at') }}</th>
                            <th class="k-table__num">{{ translate('took') }}</th>
                        </tr></thead>
                        <tbody>
                        @foreach ($lookup['spans'] as $span)
                            <tr>
                                <td>{{ $span['name'] }}</td>
                                <td><code>{{ $span['kind'] }}</code></td>
                                <td class="k-table__num k-num">{{ $span['start_offset_ms'] }} ms</td>
                                <td class="k-table__num k-num">{{ $span['duration_ms'] }} ms</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </x-k.card>
        @endif
    @else
        <x-k.card :title="translate('the_request')">
            <x-k.empty icon="info" :title="translate('no_trace_was_kept_for_this_request')"
                       :text="translate('traces_carry_a_correlation_id_rather_than_a_request_id_so_a_request_that_did_not_fail_has_no_join_to_its_trace')" />
        </x-k.card>
    @endif

    @if (!empty($lookup['errors']))
        <x-k.card :title="translate('what_failed')">
            @foreach ($lookup['errors'] as $error)
                <h3 class="mon-heading">
                    <span class="mon-pill mon-pill--critical">{{ $error['status'] ?? '—' }}</span>
                    {{ $error['method'] }} {{ $error['route'] ?: '—' }}
                    <small class="mon-metric__source">{{ $error['created_at'] }}</small>
                </h3>
                <p class="mon-note">
                    <a href="{{ route('admin.monitoring.section', ['section' => 'errors', 'group' => $error['group_id']]) }}">
                        {{ translate('open_this_error_group') }}
                    </a>
                </p>
                @if ($error['context'] !== '')
                    <details class="mon-metric__remedy"><summary>{{ translate('request_context') }}</summary>
                        <pre style="white-space:pre-wrap">{{ $error['context'] }}</pre>
                    </details>
                @endif
                @if ($error['stack_trace'] !== '')
                    <details class="mon-metric__remedy"><summary>{{ translate('stack_trace') }}</summary>
                        <pre style="white-space:pre-wrap">{{ $error['stack_trace'] }}</pre>
                    </details>
                @endif
            @endforeach
            <p class="mon-note">{{ translate('everything_here_is_redacted_before_it_is_drawn_a_stack_trace_is_a_reliable_place_to_find_a_token') }}.</p>
        </x-k.card>
    @endif
@endif
