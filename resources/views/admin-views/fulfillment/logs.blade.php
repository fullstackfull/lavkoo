@extends('layouts.admin.app')

@section('title', translate('provider_api_logs'))

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('provider_api_logs')" :subtitle="translate('provider_api_logs_subtitle')">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.fulfillment-providers.index') }}">{{ translate('Fulfillment_Providers') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        <div class="alert alert-info fs-12">{{ translate('provider_api_logs_redaction_note') }}</div>

        <x-k.card class="mb-3">
            <form method="GET" action="{{ route('admin.fulfillment-providers.logs') }}" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fs-12" for="log-provider">{{ translate('provider') }}</label>
                    <select class="form-select form-select-sm" id="log-provider" name="provider">
                        <option value="">{{ translate('all') }}</option>
                        @foreach ($providers as $provider)
                            <option value="{{ $provider->value }}" @selected(($filters['provider'] ?? '') === $provider->value)>{{ config("fulfillment.providers.{$provider->value}.label") }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fs-12" for="log-operation">{{ translate('operation') }}</label>
                    <input class="form-control form-control-sm" id="log-operation" name="operation" value="{{ $filters['operation'] ?? '' }}" placeholder="order.create">
                </div>
                <div class="col-md-3">
                    <label class="form-label fs-12" for="log-result">{{ translate('result') }}</label>
                    <select class="form-select form-select-sm" id="log-result" name="result">
                        <option value="">{{ translate('all') }}</option>
                        <option value="failed" @selected(($filters['result'] ?? '') === 'failed')>{{ translate('failed_only') }}</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button class="btn btn-primary btn-sm" type="submit">{{ translate('filter') }}</button>
                    <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.fulfillment-providers.logs') }}">{{ translate('reset') }}</a>
                </div>
            </form>
        </x-k.card>

        <x-k.card :padded="false">
            <div class="k-table-wrap">
                <table class="k-table">
                    <thead><tr><th>{{ translate('time') }}</th><th>{{ translate('provider') }}</th><th>{{ translate('operation') }}</th><th>{{ translate('request') }}</th><th>{{ translate('status') }}</th><th>{{ translate('duration') }}</th><th>{{ translate('detail') }}</th></tr></thead>
                    <tbody>
                    @forelse ($logs as $log)
                        <tr>
                            <td class="fs-12">{{ $log->created_at?->format('Y-m-d H:i:s') }}</td>
                            <td>{{ config("fulfillment.providers.{$log->provider}.label", $log->provider) }}</td>
                            <td>{{ $log->operation }}
                                @if ($log->provider_fulfillment_id)<div class="fs-10"><a href="{{ route('admin.fulfillment-providers.fulfillments.show', $log->provider_fulfillment_id) }}">#{{ $log->provider_fulfillment_id }}</a></div>@endif</td>
                            <td class="fs-12"><code>{{ $log->method }}</code> <span class="text-break">{{ \Illuminate\Support\Str::limit($log->url, 70) }}</span></td>
                            <td><x-k.badge :tone="$log->success ? 'success' : 'danger'">{{ $log->response_status ?? translate('no_response') }}</x-k.badge></td>
                            <td class="fs-12">{{ $log->duration_ms !== null ? $log->duration_ms . ' ms' : '—' }}</td>
                            <td class="fs-12">
                                @if ($log->error_message)<div class="text-danger">{{ \Illuminate\Support\Str::limit($log->error_message, 120) }}</div>@endif
                                @if ($log->response_summary)<div class="text-muted text-break">{{ \Illuminate\Support\Str::limit($log->response_summary, 160) }}</div>@endif
                                <div class="fs-10 text-muted">{{ $log->correlation_id }}</div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><x-k.empty icon="search" :title="translate('no_api_calls_yet')" :text="translate('no_api_calls_yet_help')" /></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if ($logs->hasPages())
                <x-slot:footer>{{ $logs->links() }}</x-slot:footer>
            @endif
        </x-k.card>
    </div>
@endsection
