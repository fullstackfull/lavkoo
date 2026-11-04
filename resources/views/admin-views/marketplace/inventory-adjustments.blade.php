@extends('layouts.admin.app')

@section('title', translate('inventory_adjustments'))

@php
    $typeMap = ['adjustment' => 'accent', 'receipt' => 'success', 'sale' => 'secondary', 'return' => 'info', 'transfer' => 'warning'];
@endphp

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2"><i class="fi fi-rr-boxes"></i> {{ translate('inventory_adjustments') }}</h2>
            <p class="mb-0 fs-12">{{ translate('adjust_stock_with_a_reason_and_keep_an_auditable_history') }}.</p>
        </div>

        <div class="row">
            <div class="col-lg-4">
                <div class="card mb-3">
                    <div class="card-header"><h5 class="mb-0">{{ translate('adjust_stock') }}</h5></div>
                    <div class="card-body">
                        <form action="{{ route('admin.marketplace.inventory-adjustments.adjust') }}" method="post">
                            @csrf
                            <div class="mb-2">
                                <label class="form-label fs-12">{{ translate('product_id') }} *</label>
                                <input type="number" name="product_id" class="form-control" min="1" required value="{{ $productId }}">
                                @if ($product)
                                    <small class="text-muted">{{ mb_substr($product->name, 0, 40) }} — {{ translate('current_stock') }}: <strong>{{ $product->current_stock }}</strong></small>
                                @endif
                            </div>
                            <div class="row">
                                <div class="col-6 mb-2">
                                    <label class="form-label fs-12">{{ translate('direction') }}</label>
                                    <select name="direction" class="form-control">
                                        <option value="increase">＋ {{ translate('increase') }}</option>
                                        <option value="decrease">－ {{ translate('decrease') }}</option>
                                    </select>
                                </div>
                                <div class="col-6 mb-2">
                                    <label class="form-label fs-12">{{ translate('quantity') }}</label>
                                    <input type="number" name="quantity" class="form-control" min="1" value="1" required>
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label fs-12">{{ translate('reason') }}</label>
                                <select name="reason" class="form-control">
                                    @foreach ($reasons as $r)
                                        <option value="{{ $r }}">{{ translate($r) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label fs-12">{{ translate('note') }}</label>
                                <input type="text" name="note" class="form-control" maxlength="512">
                            </div>
                            <button class="btn btn-primary w-100">{{ translate('apply_adjustment') }}</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="mb-0">{{ translate('movement_history') }}</h5>
                        <form method="get" class="d-flex gap-2">
                            <input type="number" name="product_id" value="{{ $productId }}" class="form-control form-control-sm" placeholder="{{ translate('product_id') }}" style="width:120px">
                            <select name="type" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">{{ translate('all_types') }}</option>
                                @foreach (array_keys($typeMap) as $t)
                                    <option value="{{ $t }}" {{ $movementType === $t ? 'selected' : '' }}>{{ translate($t) }}</option>
                                @endforeach
                            </select>
                            <button class="btn btn-sm btn-outline-primary">{{ translate('filter') }}</button>
                        </form>
                    </div>
                    <div class="card-body p-0">
                        <div class="k-table-wrap">
                            <table class="k-table">
                                <thead><tr>
                                    <th>{{ translate('date') }}</th><th>{{ translate('product') }}</th><th>{{ translate('type') }}</th>
                                    <th class="text-end">{{ translate('change') }}</th><th class="text-end">{{ translate('balance') }}</th>
                                    <th>{{ translate('reason') }}</th>
                                </tr></thead>
                                <tbody>
                                @forelse (($movements ?? []) as $m)
                                    <tr>
                                        <td class="fs-12">{{ $m->created_at?->format('Y-m-d H:i') }}</td>
                                        <td>#{{ $m->product_id }}</td>
                                        <td><span class="k-badge k-badge--{{ $typeMap[$m->type] ?? 'secondary' }}">{{ translate($m->type) }}</span></td>
                                        <td class="text-end {{ $m->qty_change < 0 ? 'text-danger' : 'text-success' }}">{{ $m->qty_change > 0 ? '+' : '' }}{{ $m->qty_change }}</td>
                                        <td class="text-end">{{ $m->balance_after ?? '—' }}</td>
                                        <td class="fs-12">{{ $m->reason ? translate($m->reason) : '—' }}@if($m->note)<div class="fs-10 text-muted">{{ $m->note }}</div>@endif</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted py-4">{{ translate('no_movements_recorded_yet') }}</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                    @if ($movements && $movements->hasPages())
                        <div class="card-footer">{{ $movements->links() }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
