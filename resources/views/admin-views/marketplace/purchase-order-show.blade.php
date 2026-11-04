@extends('layouts.admin.app')

@section('title', $order->reference)

@php
    $statusMap = ['draft' => 'secondary', 'ordered' => 'info', 'partially_received' => 'warning', 'received' => 'success', 'canceled' => 'danger'];
@endphp

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
                <h2 class="h1 mb-0">{{ $order->reference }}</h2>
                <p class="mb-0 fs-12 text-muted">{{ $order->supplier->name ?? '—' }} · {{ translate('created') }} {{ $order->created_at?->toDateString() }}</p>
            </div>
            <span class="k-badge k-badge--{{ $statusMap[$order->status] ?? 'secondary' }} fs-14 px-3 py-2">{{ translate($order->status) }}</span>
        </div>

        <div class="d-flex gap-2 mb-3 flex-wrap">
            @if ($order->status === 'draft')
                <form action="{{ route('admin.marketplace.purchase-orders.place', $order->id) }}" method="post">
                    @csrf <button class="btn btn-info">{{ translate('place_order') }}</button>
                </form>
            @endif
            @if ($order->canReceive())
                <form action="{{ route('admin.marketplace.purchase-orders.receive', $order->id) }}" method="post">
                    @csrf <input type="hidden" name="receive_all" value="1">
                    <button class="btn btn-success">{{ translate('receive_all') }}</button>
                </form>
            @endif
            @if ($order->status !== 'received' && $order->status !== 'canceled')
                <form action="{{ route('admin.marketplace.purchase-orders.cancel', $order->id) }}" method="post" onsubmit="return confirm('{{ translate('are_you_sure') }}')">
                    @csrf <button class="btn btn-outline-danger">{{ translate('cancel_order') }}</button>
                </form>
            @endif
            <a href="{{ route('admin.marketplace.purchase-orders.index') }}" class="btn btn-outline-secondary">{{ translate('back') }}</a>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="mb-0">{{ translate('lines') }}</h5></div>
            <div class="card-body p-0">
                <div class="k-table-wrap">
                    <table class="k-table">
                        <thead><tr>
                            <th>{{ translate('product') }}</th><th class="text-end">{{ translate('ordered') }}</th>
                            <th class="text-end">{{ translate('received') }}</th><th class="text-end">{{ translate('outstanding') }}</th>
                            <th class="text-end">{{ translate('unit_cost') }}</th><th class="text-end">{{ translate('line_total') }}</th>
                            @if ($order->canReceive())<th style="width:200px">{{ translate('receive') }}</th>@endif
                        </tr></thead>
                        <tbody>
                        @foreach ($order->items as $item)
                            <tr>
                                <td>
                                    {{ $item->description ?: ($item->product_id ? ('#' . $item->product_id) : '—') }}
                                    @if ($item->sku)<div class="fs-10 text-muted">{{ $item->sku }}</div>@endif
                                    @if ($item->product_id)<span class="k-badge fs-10">{{ translate('product') }} #{{ $item->product_id }}</span>@endif
                                </td>
                                <td class="text-end">{{ $item->qty_ordered }}</td>
                                <td class="text-end">{{ $item->qty_received }}</td>
                                <td class="text-end">{{ $item->outstanding() }}</td>
                                <td class="text-end">{{ number_format((float) $item->unit_cost, 2) }}</td>
                                <td class="text-end">{{ number_format((float) $item->line_total, 2) }}</td>
                                @if ($order->canReceive())
                                    <td>
                                        @if ($item->outstanding() > 0)
                                            <form action="{{ route('admin.marketplace.purchase-orders.receive', $order->id) }}" method="post" class="d-flex gap-1">
                                                @csrf
                                                <input type="hidden" name="item_id" value="{{ $item->id }}">
                                                <input type="number" name="qty" class="form-control form-control-sm" min="1" max="{{ $item->outstanding() }}" value="{{ $item->outstanding() }}">
                                                <button class="btn btn-sm btn-success">{{ translate('receive') }}</button>
                                            </form>
                                        @else
                                            <span class="k-badge k-badge--success">{{ translate('complete') }}</span>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="5" class="text-end">{{ translate('total') }}</th>
                                <th class="text-end">{{ number_format((float) $order->total, 2) }} {{ $order->currency }}</th>
                                @if ($order->canReceive())<th></th>@endif
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            @if ($order->notes)
                <div class="card-footer fs-12"><strong>{{ translate('notes') }}:</strong> {{ $order->notes }}</div>
            @endif
        </div>
    </div>
@endsection
