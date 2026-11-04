@extends('layouts.vendor.app')

@section('title', translate('payouts'))

@php
    $statusTones = [
        'requested' => 'info', 'under_review' => 'warning', 'approved' => 'accent',
        'processing' => 'accent', 'paid' => 'success', 'rejected' => 'danger', 'failed' => 'danger',
    ];
@endphp

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <i class="fi fi-rr-coins"></i>
                {{ translate('payouts') }}
            </h2>
            <p class="mb-0 fs-12">{{ translate('request_a_payout_of_your_available_balance') }}</p>
        </div>

        {{-- The four buckets, with the one number that decides everything: withdrawable. --}}
        <div class="k-stats" style="margin-block-end:16px">
            <x-k.stat :label="translate('pending_in_return_window')" :value="setCurrencySymbol($balances['pending'])" icon="clock" />
            <x-k.stat :label="translate('available')" :value="setCurrencySymbol($balances['available'])" icon="check" />
            <x-k.stat :label="translate('reserved_for_payouts')" :value="setCurrencySymbol($balances['reserved'])" icon="info" />
            <x-k.stat :label="translate('withdrawable_now')" :value="setCurrencySymbol($withdrawable)" icon="sparkles" />
        </div>

        @if ($inCoolingPeriod)
            <div class="alert alert-warning">
                <i class="fi fi-rr-info"></i>
                {{ translate('your_bank_details_changed_recently_so_payouts_are_paused_briefly_for_your_security') }}
            </div>
        @endif

        {{-- Request form, disabled when there is nothing to withdraw or a cooling window is open. --}}
        <div class="card mb-3">
            <div class="card-body">
                @php($hasPayoutCurrencies = isset($payoutCurrencies) && $payoutCurrencies->count() > 1)
                <form action="{{ route('vendor.business-settings.payouts.store') }}" method="post" class="row g-2 align-items-end">
                    @csrf
                    <div class="{{ $hasPayoutCurrencies ? 'col-sm-3' : 'col-sm-4' }}">
                        <label class="form-label" for="amount">{{ translate('amount') }}</label>
                        <input type="number" step="0.01" min="0.01" max="{{ $withdrawable }}"
                               class="form-control" id="amount" name="amount"
                               {{ ($withdrawable <= 0 || $inCoolingPeriod) ? 'disabled' : '' }} required>
                    </div>
                    <div class="{{ $hasPayoutCurrencies ? 'col-sm-3' : 'col-sm-4' }}">
                        <label class="form-label" for="method">{{ translate('method') }}</label>
                        <select class="form-control" id="method" name="method" {{ ($withdrawable <= 0 || $inCoolingPeriod) ? 'disabled' : '' }}>
                            <option value="bank_transfer">{{ translate('bank_transfer') }}</option>
                            <option value="manual">{{ translate('manual') }}</option>
                        </select>
                    </div>
                    @if ($hasPayoutCurrencies)
                        <div class="col-sm-3">
                            <label class="form-label" for="payout_currency">{{ translate('pay_in_currency') }}</label>
                            <select class="form-control" id="payout_currency" name="payout_currency" {{ ($withdrawable <= 0 || $inCoolingPeriod) ? 'disabled' : '' }}>
                                @foreach ($payoutCurrencies as $code)
                                    <option value="{{ $code }}">{{ $code }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div class="{{ $hasPayoutCurrencies ? 'col-sm-3' : 'col-sm-4' }}">
                        <button type="submit" class="btn btn-primary w-100"
                                {{ ($withdrawable <= 0 || $inCoolingPeriod) ? 'disabled' : '' }}>
                            <i class="fi fi-rr-paper-plane"></i> {{ translate('request_payout') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <x-k.card :title="translate('my_payout_requests')" :padded="false">
            <div class="k-table-wrap">
                <table class="k-table">
                    <thead>
                        <tr>
                            <th>{{ translate('reference') }}</th>
                            <th>{{ translate('date') }}</th>
                            <th class="k-table__num">{{ translate('amount') }}</th>
                            <th>{{ translate('method') }}</th>
                            <th>{{ translate('status') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($requests as $payout)
                            <tr>
                                <td><span class="k-num">{{ $payout->reference }}</span></td>
                                <td><span class="k-num">{{ $payout->created_at?->format('d M Y H:i') }}</span></td>
                                <td class="k-table__num">
                                    <span class="k-num">{{ setCurrencySymbol($payout->amount) }}</span>
                                    @if ($payout->payout_currency && $payout->payout_amount)
                                        <span class="d-block k-text-subtle k-num">→ {{ number_format($payout->payout_amount, 2) }} {{ $payout->payout_currency }}</span>
                                    @endif
                                </td>
                                <td>{{ translate($payout->method ?? 'bank_transfer') }}</td>
                                <td>
                                    <x-k.badge :tone="$statusTones[$payout->status] ?? 'neutral'">
                                        {{ translate($payout->status) }}
                                    </x-k.badge>
                                </td>
                                <td>
                                    <div class="k-table__actions">
                                        @if ($payout->isOpen())
                                            <form action="{{ route('vendor.business-settings.payouts.cancel', $payout->id) }}" method="post"
                                                  onsubmit="return confirm('{{ translate('cancel_this_payout_request') }}?');">
                                                @csrf
                                                <button class="k-btn k-btn--secondary k-btn--sm">{{ translate('cancel') }}</button>
                                            </form>
                                        @else
                                            <span class="k-text-subtle">—</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($requests->isEmpty())
                <x-k.empty icon="reports" :title="translate('no_payout_requests_yet')" />
            @endif
            <x-slot:footer>
                {!! $requests->links() !!}
            </x-slot:footer>
        </x-k.card>
    </div>
@endsection
