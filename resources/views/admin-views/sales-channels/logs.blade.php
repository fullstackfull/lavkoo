@extends('layouts.admin.app')

@section('title', translate('Channel_API_Logs'))

@section('content')
    @use('Illuminate\Support\Str')
    @php
        $prettyJson = static fn (mixed $value): string => (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $outcomeTone = static fn (?string $outcome): string => match ($outcome) {
            'applied' => 'success',
            'failed' => 'danger',
            'needs_review' => 'warning',
            'received', 'recorded_only' => 'info',
            default => 'neutral',
        };
    @endphp
    <div class="content container-fluid">
        <x-k.page-header :title="translate('Channel_API_Logs')" :subtitle="translate('channel_api_logs_subtitle')">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.index') }}">{{ translate('Channels_Hub') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        <div class="alert alert-info fs-12" role="note">{{ translate('channel_logs_are_redacted_note') }}</div>

        <x-k.tabs class="mb-3" :items="[
            ['label' => translate('api_calls'), 'href' => route('admin.sales-channels.logs.index', ['tab' => 'api']), 'active' => $tab === 'api'],
            ['label' => translate('events'), 'href' => route('admin.sales-channels.logs.index', ['tab' => 'events']), 'active' => $tab === 'events'],
        ]" />

        <x-k.card class="mb-3">
            <form method="GET" action="{{ route('admin.sales-channels.logs.index') }}" class="row g-2 align-items-end">
                <input type="hidden" name="tab" value="{{ $tab }}">
                <div class="col-md-3">
                    <label class="form-label fs-12" for="log-account">{{ translate('account') }}</label>
                    <select class="form-select form-select-sm" id="log-account" name="account">
                        <option value="">{{ translate('all') }}</option>
                        @foreach ($accounts as $id => $name)<option value="{{ $id }}" @selected($filters['account'] === $id)>{{ $name }}</option>@endforeach
                    </select>
                </div>
                @if ($tab === 'api')
                    <div class="col-md-3">
                        <label class="form-label fs-12" for="log-operation">{{ translate('operation') }}</label>
                        <input class="form-control form-control-sm" id="log-operation" name="operation" value="{{ $filters['operation'] }}" placeholder="trendyol.orders">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fs-12" for="log-result">{{ translate('result') }}</label>
                        <select class="form-select form-select-sm" id="log-result" name="result">
                            <option value="">{{ translate('all') }}</option>
                            <option value="ok" @selected($filters['result'] === 'ok')>{{ translate('succeeded_only') }}</option>
                            <option value="failed" @selected($filters['result'] === 'failed')>{{ translate('failed_only') }}</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fs-12" for="log-dry-run">{{ translate('dry_run_mode') }}</label>
                        <select class="form-select form-select-sm" id="log-dry-run" name="dry_run">
                            <option value="">{{ translate('all') }}</option>
                            <option value="yes" @selected($filters['dry_run'] === 'yes')>{{ translate('rehearsals_only') }}</option>
                            <option value="no" @selected($filters['dry_run'] === 'no')>{{ translate('real_calls_only') }}</option>
                        </select>
                    </div>
                @else
                    <div class="col-md-2">
                        <label class="form-label fs-12" for="log-source">{{ translate('source') }}</label>
                        <select class="form-select form-select-sm" id="log-source" name="source">
                            <option value="">{{ translate('all') }}</option>
                            @foreach ($sources as $source)<option value="{{ $source }}" @selected($filters['source'] === $source)>{{ $labels->eventSource($source) }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fs-12" for="log-outcome">{{ translate('outcome') }}</label>
                        <select class="form-select form-select-sm" id="log-outcome" name="outcome">
                            <option value="">{{ translate('all') }}</option>
                            @foreach ($outcomes as $outcome)<option value="{{ $outcome }}" @selected($filters['outcome'] === $outcome)>{{ $labels->eventOutcome($outcome) }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fs-12" for="log-event-type">{{ translate('event_type') }}</label>
                        <input class="form-control form-control-sm" id="log-event-type" name="event_type" value="{{ $filters['event_type'] }}" placeholder="order_package">
                    </div>
                @endif
                <div class="col-md-2 d-flex gap-2">
                    <button class="btn btn-primary btn-sm" type="submit">{{ translate('filter') }}</button>
                    <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.logs.index', ['tab' => $tab]) }}">{{ translate('reset') }}</a>
                </div>
            </form>
        </x-k.card>

        @if ($tab === 'api')
            <x-k.card :padded="false">
                <div class="k-table-wrap table-responsive">
                    <table class="k-table">
                        <thead><tr>
                            <th>{{ translate('time') }}</th><th>{{ translate('account') }}</th><th>{{ translate('operation') }}</th><th>{{ translate('request') }}</th>
                            <th>{{ translate('status') }}</th><th>{{ translate('duration') }}</th><th>{{ translate('detail') }}</th>
                        </tr></thead>
                        <tbody>
                        @forelse ($logs as $log)
                            <tr>
                                <td class="fs-12">{{ $log->created_at?->format('Y-m-d H:i:s') }}</td>
                                <td class="fs-12">{{ $accounts->get($log->account_id) ?? ($log->account_id ? '#' . $log->account_id : '—') }}</td>
                                <td class="fs-12">{{ $log->operation }}</td>
                                <td class="fs-12"><code>{{ $log->method }}</code> <span class="text-break">{{ Str::limit($log->url, 90) }}</span></td>
                                <td>
                                    <x-k.badge :tone="$log->dry_run ? 'info' : ($log->success ? 'success' : 'danger')">{{ $log->dry_run ? translate('rehearsal') : ($log->response_status ?? translate('no_response')) }}</x-k.badge>
                                </td>
                                <td class="fs-12 k-table__num">{{ $log->duration_ms !== null ? $log->duration_ms . ' ms' : '—' }}</td>
                                <td class="fs-12">
                                    @if ($log->error_message)<div class="text-danger text-break">{{ Str::limit($log->error_message, 160) }}</div>@endif
                                    <details>
                                        <summary>{{ translate('request_and_response') }}</summary>
                                        <div class="mt-1 text-muted">{{ translate('request') }}</div>
                                        <pre class="bg-light rounded p-2 mb-1 fs-11 text-break" style="max-height:240px;overflow:auto;white-space:pre-wrap">{{ $log->request_summary === null ? '—' : $prettyJson($log->request_summary) }}</pre>
                                        <div class="text-muted">{{ translate('response') }}</div>
                                        <pre class="bg-light rounded p-2 mb-1 fs-11 text-break" style="max-height:240px;overflow:auto;white-space:pre-wrap">{{ $log->response_summary ?: '—' }}</pre>
                                        <div class="fs-10 text-muted">{{ $log->correlation_id }}@if ($log->request_id) · {{ $log->request_id }}@endif</div>
                                    </details>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7">
                                @if (array_filter($filters))
                                    <x-k.empty icon="search" :title="translate('no_api_call_matches_the_filters')" :text="translate('no_api_call_matches_the_filters_help')">
                                        <x-slot:action><a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.logs.index', ['tab' => 'api']) }}">{{ translate('reset') }}</a></x-slot:action>
                                    </x-k.empty>
                                @else
                                    <x-k.empty icon="search" :title="translate('no_api_calls_yet')" :text="translate('no_channel_api_calls_yet_help')" />
                                @endif
                            </td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($logs->hasPages())
                    <x-slot:footer>{{ $logs->links() }}</x-slot:footer>
                @endif
            </x-k.card>
        @else
            <x-k.card :padded="false">
                <div class="k-table-wrap table-responsive">
                    <table class="k-table">
                        <thead><tr>
                            <th>{{ translate('received') }}</th><th>{{ translate('account') }}</th><th>{{ translate('source') }}</th><th>{{ translate('event_type') }}</th>
                            <th>{{ translate('reference') }}</th><th>{{ translate('outcome') }}</th><th>{{ translate('detail') }}</th>
                        </tr></thead>
                        <tbody>
                        @forelse ($events as $event)
                            <tr>
                                <td class="fs-12">{{ ($event->received_at ?? $event->created_at)?->format('Y-m-d H:i:s') }}
                                    @if ($event->occurred_at)<div class="fs-10 text-muted">{{ translate('occurred') }} {{ $event->occurred_at->format('Y-m-d H:i:s') }}</div>@endif</td>
                                <td class="fs-12">{{ $accounts->get($event->account_id) ?? ($event->account_id ? '#' . $event->account_id : '—') }}</td>
                                <td class="fs-12">{{ $labels->eventSource($event->source) }}</td>
                                <td class="fs-12 text-break">{{ $event->event_type }}</td>
                                <td class="fs-12 text-break">{{ $event->external_reference ?? '—' }}</td>
                                <td>
                                    <x-k.badge :tone="$outcomeTone($event->outcome)">{{ $labels->eventOutcome($event->outcome) }}</x-k.badge>
                                    @if ($event->outcome_note)<div class="fs-10 text-muted text-break">{{ Str::limit($event->outcome_note, 120) }}</div>@endif
                                </td>
                                <td class="fs-12">
                                    <details>
                                        <summary>{{ translate('stored_details') }}</summary>
                                        <pre class="bg-light rounded p-2 mb-0 fs-11 text-break" style="max-height:240px;overflow:auto;white-space:pre-wrap">{{ $event->payload === null ? '—' : $prettyJson($event->payload) }}</pre>
                                    </details>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7">
                                @if (array_filter($filters))
                                    <x-k.empty icon="search" :title="translate('no_event_matches_the_filters')" :text="translate('no_event_matches_the_filters_help')">
                                        <x-slot:action><a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.logs.index', ['tab' => 'events']) }}">{{ translate('reset') }}</a></x-slot:action>
                                    </x-k.empty>
                                @else
                                    <x-k.empty icon="clock" :title="translate('no_events_yet')" :text="translate('no_channel_events_yet_help')" />
                                @endif
                            </td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($events->hasPages())
                    <x-slot:footer>{{ $events->links() }}</x-slot:footer>
                @endif
            </x-k.card>
        @endif
    </div>
@endsection
