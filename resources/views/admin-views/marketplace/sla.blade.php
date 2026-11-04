@extends('layouts.admin.app')

@section('title', translate('seller_sla'))

@php $pct = fn ($v) => rtrim(rtrim(number_format(((float) $v) * 100, 1), '0'), '.') . '%'; @endphp

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
                <h2 class="h1 mb-0 d-flex align-items-center gap-2"><i class="fi fi-rr-shield-exclamation"></i> {{ translate('seller_sla') }}</h2>
                <p class="mb-0 fs-12">{{ translate('policy_thresholds_over_the_scorecard_metrics_with_a_breach_ledger') }}.</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="k-badge k-badge--{{ $openCount > 0 ? 'danger' : 'success' }} fs-14 px-3 py-2">{{ $openCount }} {{ translate('open_breaches') }}</span>
                <form action="{{ route('admin.marketplace.sla.evaluate') }}" method="post">
                    @csrf <button class="btn btn-primary btn-sm">{{ translate('evaluate_all_sellers') }}</button>
                </form>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0">{{ translate('policy') }}</h5></div>
            <div class="card-body">
                <form action="{{ route('admin.marketplace.sla.settings') }}" method="post" class="row g-3 align-items-end">
                    @csrf
                    <div class="col-sm-3"><label class="form-label fs-12">{{ translate('max_cancellation_rate') }} (0–1)</label>
                        <input type="number" step="0.01" min="0" max="1" name="sla_max_cancellation_rate" value="{{ $thresholds['cancellation_rate'] }}" class="form-control"></div>
                    <div class="col-sm-3"><label class="form-label fs-12">{{ translate('max_return_rate') }} (0–1)</label>
                        <input type="number" step="0.01" min="0" max="1" name="sla_max_return_rate" value="{{ $thresholds['return_rate'] }}" class="form-control"></div>
                    <div class="col-sm-3"><label class="form-label fs-12">{{ translate('max_refund_rate') }} (0–1)</label>
                        <input type="number" step="0.01" min="0" max="1" name="sla_max_refund_rate" value="{{ $thresholds['refund_rate'] }}" class="form-control"></div>
                    <div class="col-sm-2"><label class="form-label fs-12">{{ translate('min_rating') }} (0–5)</label>
                        <input type="number" step="0.1" min="0" max="5" name="sla_min_rating" value="{{ $thresholds['avg_rating'] }}" class="form-control"></div>
                    <div class="col-sm-3"><label class="form-label fs-12">{{ translate('hours_to_process_an_order') }}</label>
                        <input type="number" step="1" min="1" max="720" name="sla_processing_hours" value="{{ $thresholds['processing_hours'] }}" class="form-control"></div>
                    <div class="col-12">
                        <small class="text-muted d-block">{{ translate('rates_are_ceilings_rating_is_a_floor_and_is_only_enforced_once_a_seller_has_at_least_five_reviews') }}.
                            {{ translate('the_processing_window_is_what_the_seller_center_counts_down_against') }}.</small>
                    </div>

                    <div class="col-12">
                        <hr class="my-2">
                        <h6 class="mb-1">{{ translate('operations_windows') }}</h6>
                        <small class="text-muted d-block">{{ translate('these_windows_are_what_the_action_center_raises_by_and_what_the_countdown_colours_by') }}.</small>
                    </div>

                    @foreach ($operationFields as $key => $field)
                        <div class="col-sm-3">
                            <label class="form-label fs-12" for="{{ $key }}">{{ translate($field['label']) }}</label>
                            @include('admin-views.settings.partials._policy-field', ['key' => $key, 'field' => $field, 'value' => $operations[$key]])
                        </div>
                    @endforeach

                    <div class="col-12 d-flex justify-content-end">
                        <button class="btn btn-primary px-4">{{ translate('save') }}</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0">{{ translate('breach_ledger') }}</h5>
                <form method="get">
                    <select name="status" class="form-control form-control-sm" onchange="this.form.submit()">
                        <option value="">{{ translate('all') }}</option>
                        <option value="open" {{ $statusFilter === 'open' ? 'selected' : '' }}>{{ translate('open') }}</option>
                        <option value="cleared" {{ $statusFilter === 'cleared' ? 'selected' : '' }}>{{ translate('cleared') }}</option>
                    </select>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="k-table-wrap">
                    <table class="k-table">
                        <thead><tr>
                            <th>{{ translate('seller') }}</th><th>{{ translate('metric') }}</th>
                            <th class="text-end">{{ translate('threshold') }}</th><th class="text-end">{{ translate('actual') }}</th>
                            <th>{{ translate('status') }}</th><th>{{ translate('since') }}</th>
                        </tr></thead>
                        <tbody>
                        @forelse ($breaches as $b)
                            @php($seller = $sellers[$b->seller_id] ?? null)
                            @php($isRate = in_array($b->metric, ['cancellation_rate', 'return_rate', 'refund_rate']))
                            <tr>
                                <td>{{ $seller ? trim(($seller->f_name ?? '') . ' ' . ($seller->l_name ?? '')) : ('#' . $b->seller_id) }}</td>
                                <td>{{ translate($b->metric) }}</td>
                                <td class="text-end">{{ $isRate ? $pct($b->threshold) : number_format((float) $b->threshold, 1) }}</td>
                                <td class="text-end fw-bold {{ $b->status === 'open' ? 'text-danger' : '' }}">{{ $isRate ? $pct($b->actual_value) : number_format((float) $b->actual_value, 2) }}</td>
                                <td><span class="k-badge k-badge--{{ $b->status === 'open' ? 'danger' : 'secondary' }}">{{ translate($b->status) }}</span></td>
                                <td class="fs-12">{{ $b->created_at?->toDateString() }}@if($b->status === 'cleared' && $b->cleared_at)<div class="fs-10 text-muted">{{ translate('cleared') }}: {{ $b->cleared_at->toDateString() }}</div>@endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">{{ translate('no_breaches_recorded_run_an_evaluation_to_start') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if ($paginator && $paginator->hasPages())
                <div class="card-footer">{{ $paginator->links() }}</div>
            @endif
        </div>
    </div>
@endsection
