@extends('layouts.admin.app')

@section('title', translate('seller_payouts'))

@php
    $statusColours = [
        'requested' => 'info', 'under_review' => 'warning', 'approved' => 'accent',
        'processing' => 'warning', 'paid' => 'success', 'rejected' => 'danger', 'failed' => 'danger',
    ];
@endphp

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <i class="fi fi-rr-money-check-edit"></i>
                {{ translate('seller_payouts') }}
            </h2>
            <p class="mb-0 fs-12">{{ translate('ledger-based_payout_requests_-_approve_then_mark_paid,_or_reject_to_release_the_reserved_funds') }}</p>
        </div>

        @if ($unavailable)
            <div class="alert alert-info">{{ translate('the_payouts_table_is_not_available_-_run_migrations_first') }}.</div>
        @else
            <div class="d-flex flex-wrap gap-2 mb-3">
                @php
                    $tabs = ['' => translate('all'), 'requested' => translate('requested'), 'under_review' => translate('under_review'), 'approved' => translate('approved'), 'paid' => translate('paid'), 'rejected' => translate('rejected'), 'failed' => translate('failed')];
                @endphp
                @foreach ($tabs as $key => $label)
                    <a href="{{ route('admin.marketplace.payouts.index', array_filter(['status' => $key])) }}"
                       class="btn btn-sm {{ ($status ?? '') === $key || ($key === '' && !$status) ? 'btn-primary' : 'btn-outline-secondary' }}">
                        {{ $label }}
                        @if ($key && isset($counts[$key])) <span class="k-badge ms-1">{{ $counts[$key] }}</span> @endif
                    </a>
                @endforeach
            </div>

            <div class="card">
                <div class="card-body p-0">
                    <div class="k-table-wrap" style="overflow-x:auto">
                        <table class="k-table">
                            <thead>
                                <tr>
                                    <th>{{ translate('reference') }}</th>
                                    <th>{{ translate('seller') }}</th>
                                    <th>{{ translate('amount') }}</th>
                                    <th>{{ translate('method') }}</th>
                                    <th>{{ translate('status') }}</th>
                                    <th>{{ translate('requested') }}</th>
                                    <th class="text-end">{{ translate('action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($payouts as $payout)
                                    @php $seller = $sellers[$payout->seller_id] ?? null; @endphp
                                    <tr>
                                        <td class="fw-semibold">{{ $payout->reference }}</td>
                                        <td>
                                            @if ($seller)
                                                {{ $seller->shop->name ?? trim($seller->f_name . ' ' . $seller->l_name) }}
                                            @endif
                                            <span class="d-block fs-12 text-muted">#{{ $payout->seller_id }}</span>
                                        </td>
                                        <td class="fw-semibold">
                                            {{ \App\Utils\Helpers::set_symbol($payout->amount) }}
                                            @if ($payout->payout_currency && $payout->payout_amount)
                                                <span class="d-block fs-12 text-muted">
                                                    → {{ number_format($payout->payout_amount, 2) }} {{ $payout->payout_currency }}
                                                    <span class="text-nowrap">@ {{ rtrim(rtrim(number_format($payout->exchange_rate, 6), '0'), '.') }}</span>
                                                </span>
                                            @endif
                                        </td>
                                        <td>{{ translate(str_replace('_', ' ', $payout->method)) }}</td>
                                        <td><span class="k-badge k-badge--{{ $statusColours[$payout->status] ?? 'secondary' }} text-capitalize">{{ translate($payout->status) }}</span></td>
                                        <td class="fs-12">{{ $payout->created_at?->format('d M Y, h:i A') }}</td>
                                        <td class="text-end">
                                            @if (in_array($payout->status, ['requested', 'under_review']))
                                                <form action="{{ route('admin.marketplace.payouts.approve', $payout->id) }}" method="post" class="d-inline">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-primary">{{ translate('approve') }}</button>
                                                </form>
                                                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#reject-{{ $payout->id }}">{{ translate('reject') }}</button>
                                            @elseif ($payout->status === 'approved')
                                                <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#pay-{{ $payout->id }}">{{ translate('mark_paid') }}</button>
                                                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#reject-{{ $payout->id }}">{{ translate('reject') }}</button>
                                                <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#fail-{{ $payout->id }}">{{ translate('mark_failed') }}</button>
                                            @elseif ($payout->status === 'paid' || $payout->status === 'processing')
                                                {{-- A bank bounce arrives days after the payment was recorded, which is
                                                     exactly the case the status set used to have no answer for. --}}
                                                <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#fail-{{ $payout->id }}">{{ translate('mark_failed') }}</button>
                                            @elseif ($payout->status === 'failed')
                                                <form action="{{ route('admin.marketplace.payouts.reissue', $payout->id) }}" method="post" class="d-inline"
                                                      onsubmit="return confirm('{{ translate('open_a_new_payout_request_for_the_same_amount') }}?')">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-outline-primary">{{ translate('send_again') }}</button>
                                                </form>
                                            @else
                                                <span class="text-muted fs-12">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="text-center py-4 text-muted">{{ translate('no_payout_requests_yet') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                @if ($payouts->hasPages())
                    <div class="card-footer">{{ $payouts->links() }}</div>
                @endif
            </div>

            {{-- Action modals: kept out of the table row so markup stays valid --}}
            @foreach ($payouts as $payout)
                @if (in_array($payout->status, ['requested', 'under_review', 'approved']))
                    <div class="modal fade" id="reject-{{ $payout->id }}" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <form action="{{ route('admin.marketplace.payouts.reject', $payout->id) }}" method="post" class="modal-content">
                                @csrf
                                <div class="modal-header"><h5 class="modal-title">{{ translate('reject_payout') }} {{ $payout->reference }}</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                                <div class="modal-body">
                                    <p class="fs-12 text-muted">{{ translate('rejecting_releases_the_reserved_amount_back_to_the_sellers_available_balance') }}.</p>
                                    <textarea name="note" class="form-control" rows="3" placeholder="{{ translate('reason_(optional)') }}"></textarea>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ translate('cancel') }}</button>
                                    <button type="submit" class="btn btn-danger">{{ translate('reject') }}</button>
                                </div>
                            </form>
                        </div>
                    </div>
                @endif
                @if (in_array($payout->status, ['approved', 'processing', 'paid']))
                    <div class="modal fade" id="fail-{{ $payout->id }}" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <form action="{{ route('admin.marketplace.payouts.mark-failed', $payout->id) }}" method="post" class="modal-content">
                                @csrf
                                <div class="modal-header"><h5 class="modal-title">{{ translate('mark_payout_failed') }} {{ $payout->reference }}</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                                <div class="modal-body">
                                    <p class="fs-12 text-muted">{{ translate('a_failed_transfer_means_the_money_never_left_so_it_goes_back_to_the_sellers_available_balance') }}.</p>
                                    <textarea name="note" class="form-control" rows="3" placeholder="{{ translate('what_the_bank_said') }}"></textarea>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ translate('cancel') }}</button>
                                    <button type="submit" class="btn btn-warning">{{ translate('mark_failed') }}</button>
                                </div>
                            </form>
                        </div>
                    </div>
                @endif
                @if ($payout->status === 'approved')
                    <div class="modal fade" id="pay-{{ $payout->id }}" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <form action="{{ route('admin.marketplace.payouts.mark-paid', $payout->id) }}" method="post" class="modal-content">
                                @csrf
                                <div class="modal-header"><h5 class="modal-title">{{ translate('mark_payout_paid') }} {{ $payout->reference }}</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                                <div class="modal-body">
                                    <label class="form-label fs-12">{{ translate('payment_reference') }}</label>
                                    <input type="text" name="payment_reference" class="form-control" placeholder="{{ translate('bank_transfer_reference') }}">
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ translate('cancel') }}</button>
                                    <button type="submit" class="btn btn-success">{{ translate('mark_paid') }}</button>
                                </div>
                            </form>
                        </div>
                    </div>
                @endif
            @endforeach
        @endif
    </div>
@endsection
