@extends('layouts.admin.app')

@section('title', translate('purchase_orders'))

@php
    $statusMap = ['draft' => 'secondary', 'ordered' => 'info', 'partially_received' => 'warning', 'received' => 'success', 'canceled' => 'danger'];
@endphp

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2"><i class="fi fi-rr-inbox-in"></i> {{ translate('purchase_orders') }}</h2>
            <div class="d-flex gap-2">
                <a href="{{ route('admin.marketplace.suppliers.index') }}" class="btn btn-outline-primary btn-sm">{{ translate('suppliers') }}</a>
                <a href="{{ route('admin.marketplace.purchase-orders.create') }}" class="btn btn-primary btn-sm">{{ translate('new_purchase_order') }}</a>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0">{{ translate('all_orders') }}</h5>
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
                            <th>{{ translate('reference') }}</th><th>{{ translate('supplier') }}</th>
                            <th>{{ translate('status') }}</th><th class="text-end">{{ translate('total') }}</th>
                            <th>{{ translate('expected') }}</th><th class="text-end">{{ translate('action') }}</th>
                        </tr></thead>
                        <tbody>
                        @forelse ($orders as $o)
                            <tr>
                                <td><a href="{{ route('admin.marketplace.purchase-orders.show', $o->id) }}">{{ $o->reference }}</a></td>
                                <td>{{ $o->supplier->name ?? '—' }}</td>
                                <td><span class="k-badge k-badge--{{ $statusMap[$o->status] ?? 'secondary' }}">{{ translate($o->status) }}</span></td>
                                <td class="text-end">{{ number_format((float) $o->total, 2) }}</td>
                                <td class="fs-12">{{ $o->expected_at?->toDateString() ?: '—' }}</td>
                                <td class="text-end"><a href="{{ route('admin.marketplace.purchase-orders.show', $o->id) }}" class="btn btn-sm btn-outline-primary">{{ translate('open') }}</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">{{ translate('no_purchase_orders_yet') }}</td></tr>
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
