@extends('layouts.vendor.app')

@section('title', translate('seller_center'))

@php
    $v = $overview['verification'];
    $p = $overview['performance'];
    $f = $overview['finance'];
    $s = $overview['sla'];
    $tierMap = ['good' => 'success', 'watch' => 'warning', 'at_risk' => 'danger', 'new' => 'secondary'];
    $kycMap = ['verified' => 'success', 'pending' => 'warning', 'rejected' => 'danger', 'unverified' => 'secondary', 'not_required' => 'info'];
    $pct = fn ($x) => number_format(((float) $x) * 100, 1) . '%';
@endphp

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0">{{ translate('seller_center') }}</h2>
            <p class="mb-0 fs-12">{{ translate('your_verification_performance_finances_and_service_standing_in_one_place') }}.</p>
        </div>

        <div class="row g-3">
            {{-- Verification --}}
            <div class="col-md-6 col-xl-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="fs-12 text-muted text-uppercase mb-1">{{ translate('verification') }}</div>
                        <span class="k-badge k-badge--{{ $kycMap[$v['status']] ?? 'secondary' }} fs-14">{{ translate($v['status']) }}</span>
                        @if ($v['required_for_payout'] && !in_array($v['status'], ['verified', 'not_required']))
                            <div class="fs-11 text-danger mt-2">{{ translate('verification_is_required_before_you_can_withdraw') }}.</div>
                        @endif
                        @if (Route::has('vendor.business-settings.seller-verification.index'))
                            <a href="{{ route('vendor.business-settings.seller-verification.index') }}" class="d-block mt-2 fs-12">{{ translate('manage') }} →</a>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Performance --}}
            <div class="col-md-6 col-xl-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="fs-12 text-muted text-uppercase mb-1">{{ translate('performance') }}</div>
                        <span class="k-badge k-badge--{{ $tierMap[$p['tier'] ?? 'new'] ?? 'secondary' }} fs-14">{{ translate($p['tier'] ?? 'new') }}</span>
                        <div class="fs-11 text-muted mt-2">
                            {{ translate('orders') }}: {{ $p['orders_total'] ?? 0 }} ·
                            {{ translate('fulfilment') }}: {{ $pct($p['fulfillment_rate'] ?? 0) }}
                        </div>
                        @if (Route::has('vendor.business-settings.seller-scorecard.index'))
                            <a href="{{ route('vendor.business-settings.seller-scorecard.index') }}" class="d-block mt-2 fs-12">{{ translate('details') }} →</a>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Finance --}}
            <div class="col-md-6 col-xl-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="fs-12 text-muted text-uppercase mb-1">{{ translate('finance') }}</div>
                        <div class="h4 mb-0">{{ number_format((float) $f['withdrawable'], 2) }}</div>
                        <div class="fs-11 text-muted">{{ translate('withdrawable') }}</div>
                        <div class="fs-11 text-muted mt-1">
                            {{ translate('pending') }}: {{ number_format((float) $f['pending'], 2) }}
                            @if ($f['recent_payout_status'])
                                <br>{{ translate('last_payout') }}: {{ translate($f['recent_payout_status']) }}
                            @endif
                        </div>
                        @if (Route::has('vendor.business-settings.payouts.index'))
                            <a href="{{ route('vendor.business-settings.payouts.index') }}" class="d-block mt-2 fs-12">{{ translate('payouts') }} →</a>
                        @endif
                    </div>
                </div>
            </div>

            {{-- SLA --}}
            <div class="col-md-6 col-xl-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="fs-12 text-muted text-uppercase mb-1">{{ translate('service_standing') }}</div>
                        <span class="k-badge k-badge--{{ $s['open_breach_count'] > 0 ? 'danger' : 'success' }} fs-14">
                            {{ $s['open_breach_count'] }} {{ translate('open_breaches') }}
                        </span>
                        @if ($s['open_breach_count'] > 0)
                            <div class="fs-11 text-danger mt-2">
                                @foreach ($s['open_breaches'] as $b)
                                    {{ translate($b->metric) }}@if(!$loop->last) · @endif
                                @endforeach
                            </div>
                        @else
                            <div class="fs-11 text-success mt-2">{{ translate('within_policy') }}</div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
