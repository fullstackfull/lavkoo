@extends('layouts.admin.app')

@section('title', translate('returns'))

@php
    $statusMap = ['authorized' => 'info', 'in_transit' => 'warning', 'received' => 'secondary', 'restocked' => 'success', 'rejected' => 'danger'];
@endphp

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2"><i class="fi fi-rr-undo"></i> {{ translate('returns') }} <span class="fs-12 text-muted">(RMA)</span></h2>
            <p class="mb-0 fs-12">{{ translate('authorize_returns_track_them_back_and_restock_on_receipt') }}.</p>
        </div>

        <div class="row">
            <div class="col-lg-4">
                <div class="card mb-3">
                    <div class="card-header"><h5 class="mb-0">{{ translate('authorize_a_return') }}</h5></div>
                    <div class="card-body">
                        <form action="{{ route('admin.marketplace.returns.store') }}" method="post">
                            @csrf
                            <div class="row">
                                <div class="col-6 mb-2"><label class="form-label fs-12">{{ translate('order_id') }}</label>
                                    <input type="number" name="order_id" class="form-control form-control-sm" min="1"></div>
                                <div class="col-6 mb-2"><label class="form-label fs-12">{{ translate('product_id') }}</label>
                                    <input type="number" name="product_id" class="form-control form-control-sm" min="1"></div>
                            </div>
                            <div class="row">
                                <div class="col-6 mb-2"><label class="form-label fs-12">{{ translate('refund_request_id') }}</label>
                                    <input type="number" name="refund_request_id" class="form-control form-control-sm" min="1"></div>
                                <div class="col-6 mb-2"><label class="form-label fs-12">{{ translate('qty') }} *</label>
                                    <input type="number" name="qty" class="form-control form-control-sm" min="1" value="1" required></div>
                            </div>
                            <div class="mb-2"><label class="form-label fs-12">{{ translate('reason') }}</label>
                                <input type="text" name="reason" class="form-control form-control-sm" maxlength="120"></div>
                            <div class="mb-2">
                                <label class="d-flex align-items-center gap-2 mb-0">
                                    <input type="checkbox" name="restock" value="1" checked> {{ translate('restock_on_receipt') }}
                                </label>
                                <small class="text-muted">{{ translate('uncheck_for_damaged_or_unsellable_goods') }}.</small>
                            </div>
                            <button class="btn btn-primary w-100">{{ translate('authorize') }}</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="mb-0">{{ translate('return_queue') }}</h5>
                        <form method="get">
                            <select name="status" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">{{ translate('all_statuses') }}</option>
                                @foreach (array_keys($statusMap) as $s)
                                    <option value="{{ $s }}" {{ $status === $s ? 'selected' : '' }}>{{ translate($s) }}</option>
                                @endforeach
                            </select>
                        </form>
                    </div>
                    <div class="card-body p-0">
                        <div class="k-table-wrap">
                            <table class="k-table">
                                <thead><tr>
                                    <th>{{ translate('reference') }}</th><th>{{ translate('product') }}</th>
                                    <th class="text-end">{{ translate('qty') }}</th><th>{{ translate('status') }}</th>
                                    <th class="text-end">{{ translate('action') }}</th>
                                </tr></thead>
                                <tbody>
                                @forelse ($returns as $r)
                                    <tr>
                                        <td>{{ $r->reference }}
                                            @if($r->reason)<div class="fs-10 text-muted">{{ $r->reason }}</div>@endif
                                            @if($r->tracking_number)<div class="fs-10 text-muted">{{ $r->carrier }} {{ $r->tracking_number }}</div>@endif
                                        </td>
                                        <td>{{ $r->product_id ? ('#' . $r->product_id) : ($r->order_id ? (translate('order') . ' #' . $r->order_id) : '—') }}
                                            @unless($r->restock)<span class="k-badge k-badge--warning fs-10">{{ translate('no_restock') }}</span>@endunless
                                        </td>
                                        <td class="text-end">{{ $r->qty }}</td>
                                        <td><span class="k-badge k-badge--{{ $statusMap[$r->status] ?? 'secondary' }}">{{ translate($r->status) }}</span></td>
                                        <td class="text-end">
                                            @if ($r->status === 'authorized')
                                                <button class="btn btn-sm btn-outline-primary" type="button" data-toggle="collapse" data-target="#tr-{{ $r->id }}">{{ translate('mark_in_transit') }}</button>
                                            @endif
                                            @if (in_array($r->status, ['authorized','in_transit']))
                                                <form action="{{ route('admin.marketplace.returns.receive', $r->id) }}" method="post" class="d-inline">
                                                    @csrf <button class="btn btn-sm btn-success">{{ translate('receive') }}</button>
                                                </form>
                                                <form action="{{ route('admin.marketplace.returns.reject', $r->id) }}" method="post" class="d-inline" onsubmit="return confirm('{{ translate('are_you_sure') }}')">
                                                    @csrf <button class="btn btn-sm btn-outline-danger">{{ translate('reject') }}</button>
                                                </form>
                                            @endif
                                            <div class="collapse mt-1 text-start" id="tr-{{ $r->id }}">
                                                <form action="{{ route('admin.marketplace.returns.transit', $r->id) }}" method="post" class="d-flex gap-1">
                                                    @csrf
                                                    <input type="text" name="carrier" class="form-control form-control-sm" placeholder="{{ translate('carrier') }}">
                                                    <input type="text" name="tracking_number" class="form-control form-control-sm" placeholder="{{ translate('tracking') }}">
                                                    <button class="btn btn-sm btn-primary">{{ translate('save') }}</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted py-4">{{ translate('no_returns_yet') }}</td></tr>
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
        </div>
    </div>
@endsection
