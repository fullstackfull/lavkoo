@extends('layouts.admin.app')

@section('title', translate('seller_scorecard'))

@php
    $tierMap = ['good' => 'success', 'watch' => 'warning', 'at_risk' => 'danger', 'new' => 'secondary'];
    $pct = fn ($v) => number_format(((float) $v) * 100, 1) . '%';
@endphp

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <i class="fi fi-rr-chart-histogram"></i>
                {{ translate('seller_scorecard') }}
            </h2>
            <p class="mb-0 fs-12">{{ translate('fulfilment_cancellations_returns_refunds_rating_and_moderation_per_seller') }}.</p>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <div class="k-table-wrap">
                    <table class="k-table">
                        <thead>
                            <tr>
                                <th>{{ translate('seller') }}</th>
                                <th>{{ translate('health') }}</th>
                                <th class="text-end">{{ translate('orders') }}</th>
                                <th class="text-end">{{ translate('fulfilment') }}</th>
                                <th class="text-end">{{ translate('cancelled') }}</th>
                                <th class="text-end">{{ translate('returned') }}</th>
                                <th class="text-end">{{ translate('refunds') }}</th>
                                <th class="text-end">{{ translate('rating') }}</th>
                                <th class="text-end">{{ translate('strikes') }}</th>
                                <th>{{ translate('kyc') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse ($sellers as $seller)
                            @php($c = $cards[$seller->id] ?? null)
                            <tr>
                                <td>{{ trim(($seller->f_name ?? '') . ' ' . ($seller->l_name ?? '')) ?: ('#' . $seller->id) }}</td>
                                <td><span class="k-badge k-badge--{{ $tierMap[$c['tier']] ?? 'secondary' }}">{{ translate($c['tier'] ?? 'new') }}</span></td>
                                <td class="text-end">{{ $c['orders_total'] ?? 0 }}</td>
                                <td class="text-end">{{ $pct($c['fulfillment_rate'] ?? 0) }}</td>
                                <td class="text-end">{{ $pct($c['cancellation_rate'] ?? 0) }}</td>
                                <td class="text-end">{{ $pct($c['return_rate'] ?? 0) }}</td>
                                <td class="text-end">{{ $pct($c['refund_rate'] ?? 0) }}</td>
                                <td class="text-end">{{ $c['avg_rating'] !== null ? number_format($c['avg_rating'], 2) : '—' }} <span class="fs-10 text-muted">({{ $c['review_count'] ?? 0 }})</span></td>
                                <td class="text-end">{{ $c['moderation_strikes'] ?? 0 }}</td>
                                <td><span class="fs-11">{{ translate($c['kyc_status'] ?? 'unverified') }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="text-center text-muted py-4">{{ translate('no_sellers_found') }}</td></tr>
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
