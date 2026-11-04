@extends('layouts.admin.app')

@section('title', translate('fulfilment'))

@php
    $statusMap = ['pending' => 'secondary', 'picking' => 'info', 'packed' => 'accent', 'ready' => 'warning', 'shipped' => 'success', 'canceled' => 'danger'];
@endphp

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2"><i class="fi fi-rr-box-open"></i> {{ translate('fulfilment') }}</h2>
            <p class="mb-0 fs-12">{{ translate('pick_pack_and_ship_orders_this_does_not_change_the_order_status') }}.</p>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0">{{ translate('open_a_fulfilment') }}</h5></div>
            <div class="card-body">
                <form action="{{ route('admin.marketplace.fulfillments.store') }}" method="post" class="row g-2 align-items-end">
                    @csrf
                    <div class="col-sm-3"><label class="form-label fs-12">{{ translate('order_id') }} *</label>
                        <input type="number" name="order_id" class="form-control form-control-sm" min="1" required></div>
                    <div class="col-sm-3"><label class="form-label fs-12">{{ translate('seller_id') }}</label>
                        <input type="number" name="seller_id" class="form-control form-control-sm" min="1"></div>
                    <div class="col-sm-4"><label class="form-label fs-12">{{ translate('warehouse') }}</label>
                        <select name="warehouse_id" class="form-control form-control-sm">
                            <option value="">{{ translate('none') }}</option>
                            @foreach ($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach
                        </select></div>
                    <div class="col-sm-2"><button class="btn btn-primary btn-sm w-100">{{ translate('open') }}</button></div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0">{{ translate('fulfilment_queue') }}</h5>
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
                            <th>{{ translate('reference') }}</th><th>{{ translate('order') }}</th>
                            <th>{{ translate('status') }}</th><th>{{ translate('action') }}</th>
                        </tr></thead>
                        <tbody>
                        @forelse ($fulfillments as $f)
                            @php($idx = array_search($f->status, $flow, true))
                            @php($next = ($idx !== false && $idx < count($flow) - 1) ? $flow[$idx + 1] : null)
                            <tr>
                                <td>{{ $f->reference }}
                                    @if($f->tracking_number)<div class="fs-10 text-muted">{{ $f->carrier }} {{ $f->tracking_number }}</div>@endif
                                </td>
                                <td>#{{ $f->order_id }}@if($f->warehouse_id)<div class="fs-10 text-muted">{{ translate('warehouse') }} #{{ $f->warehouse_id }}</div>@endif</td>
                                <td><span class="k-badge k-badge--{{ $statusMap[$f->status] ?? 'secondary' }}">{{ translate($f->status) }}</span></td>
                                <td>
                                    @if ($f->isOpen() && $next)
                                        @if ($next === 'shipped')
                                            <form action="{{ route('admin.marketplace.fulfillments.advance', $f->id) }}" method="post" class="d-flex gap-1">
                                                @csrf <input type="hidden" name="to_status" value="shipped">
                                                <input type="text" name="carrier" class="form-control form-control-sm" placeholder="{{ translate('carrier') }}" style="width:110px">
                                                <input type="text" name="tracking_number" class="form-control form-control-sm" placeholder="{{ translate('tracking') }}" style="width:130px">
                                                <button class="btn btn-sm btn-success">{{ translate('mark') }} {{ translate('shipped') }}</button>
                                            </form>
                                        @else
                                            <form action="{{ route('admin.marketplace.fulfillments.advance', $f->id) }}" method="post" class="d-inline">
                                                @csrf <input type="hidden" name="to_status" value="{{ $next }}">
                                                <button class="btn btn-sm btn-primary">→ {{ translate($next) }}</button>
                                            </form>
                                        @endif
                                        <form action="{{ route('admin.marketplace.fulfillments.cancel', $f->id) }}" method="post" class="d-inline" onsubmit="return confirm('{{ translate('are_you_sure') }}')">
                                            @csrf <button class="btn btn-sm btn-outline-danger">{{ translate('cancel') }}</button>
                                        </form>
                                    @else
                                        <span class="text-muted fs-12">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-4">{{ translate('no_fulfilments_yet') }}</td></tr>
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
