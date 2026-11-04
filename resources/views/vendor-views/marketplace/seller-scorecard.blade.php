@extends('layouts.vendor.app')

@section('title', translate('performance'))

@php
    $tierMap = ['good' => 'success', 'watch' => 'warning', 'at_risk' => 'danger', 'new' => 'secondary'];
    $pct = fn ($v) => number_format(((float) $v) * 100, 1) . '%';
@endphp

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
                <h2 class="h1 mb-0">{{ translate('my_performance') }}</h2>
                <p class="mb-0 fs-12">{{ translate('how_your_store_is_doing_on_the_metrics_that_matter') }}.</p>
            </div>
            <span class="k-badge k-badge--{{ $tierMap[$card['tier']] ?? 'secondary' }} fs-14 px-3 py-2">
                {{ translate('health') }}: {{ translate($card['tier']) }}
            </span>
        </div>

        <div class="row g-3">
            @php
                $tiles = [
                    ['orders', $card['orders_total'], null],
                    ['fulfilment', $pct($card['fulfillment_rate']), 'success'],
                    ['cancelled', $pct($card['cancellation_rate']), 'danger'],
                    ['returned', $pct($card['return_rate']), 'warning'],
                    ['refunds', $pct($card['refund_rate']), 'warning'],
                    ['rating', ($card['avg_rating'] !== null ? number_format($card['avg_rating'], 2) : '—') . ' (' . $card['review_count'] . ')', 'info'],
                    ['moderation_strikes', $card['moderation_strikes'], $card['moderation_strikes'] > 0 ? 'danger' : null],
                    ['kyc', translate($card['kyc_status']), null],
                ];
            @endphp
            @foreach ($tiles as [$label, $value, $tone])
                <div class="col-6 col-md-3">
                    <div class="card h-100">
                        <div class="card-body text-center">
                            <div class="fs-12 text-muted text-uppercase">{{ translate($label) }}</div>
                            <div class="h2 mb-0 {{ $tone ? 'text-' . $tone : '' }}">{{ $value }}</div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="alert alert-light border mt-3 fs-12 mb-0">
            {{ translate('a_high_cancellation_return_or_refund_rate_or_moderation_strikes_move_your_store_from_good_to_watch_to_at_risk') }}.
        </div>
    </div>
@endsection
