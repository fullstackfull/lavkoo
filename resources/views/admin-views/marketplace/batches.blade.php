@extends('layouts.admin.app')

@section('title', translate('batches_and_expiry'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2"><i class="fi fi-rr-calendar-clock"></i> {{ translate('batches_and_expiry') }}</h2>
            <p class="mb-0 fs-12">{{ translate('track_batch_expiry_and_write_off_expired_stock') }}.</p>
        </div>

        <div class="row mb-1">
            <div class="col-sm-6 col-lg-3 mb-2">
                <div class="card"><div class="card-body py-2 d-flex justify-content-between align-items-center">
                    <span class="fs-12 text-muted">{{ translate('expiring_within_30_days') }}</span>
                    <span class="h4 mb-0 text-warning">{{ $expiringCount }}</span>
                </div></div>
            </div>
            <div class="col-sm-6 col-lg-3 mb-2">
                <div class="card"><div class="card-body py-2 d-flex justify-content-between align-items-center">
                    <span class="fs-12 text-muted">{{ translate('expired') }}</span>
                    <span class="h4 mb-0 text-danger">{{ $expiredCount }}</span>
                </div></div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-4">
                <div class="card mb-3">
                    <div class="card-header"><h5 class="mb-0">{{ translate('record_a_batch') }}</h5></div>
                    <div class="card-body">
                        <form action="{{ route('admin.marketplace.batches.store') }}" method="post">
                            @csrf
                            <div class="mb-2"><label class="form-label fs-12">{{ translate('product_id') }} *</label>
                                <input type="number" name="product_id" class="form-control" min="1" required value="{{ $productId }}"></div>
                            <div class="mb-2"><label class="form-label fs-12">{{ translate('batch_number') }}</label>
                                <input type="text" name="batch_number" class="form-control" maxlength="120"></div>
                            <div class="row">
                                <div class="col-7 mb-2"><label class="form-label fs-12">{{ translate('expiry_date') }}</label>
                                    <input type="date" name="expiry_date" class="form-control"></div>
                                <div class="col-5 mb-2"><label class="form-label fs-12">{{ translate('quantity') }} *</label>
                                    <input type="number" name="quantity" class="form-control" min="0" value="0" required></div>
                            </div>
                            <div class="mb-2"><label class="form-label fs-12">{{ translate('note') }}</label>
                                <input type="text" name="note" class="form-control" maxlength="512"></div>
                            <button class="btn btn-primary w-100">{{ translate('save') }}</button>
                            <small class="text-muted d-block mt-2">{{ translate('batches_track_expiry_they_do_not_change_sellable_stock') }}.</small>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="mb-0">{{ translate('batches') }}</h5>
                        <form method="get" class="d-flex gap-2">
                            <input type="number" name="product_id" value="{{ $productId }}" class="form-control form-control-sm" placeholder="{{ translate('product_id') }}" style="width:110px">
                            <select name="filter" class="form-control form-control-sm" onchange="this.form.submit()">
                                @foreach (['all','active','expiring','expired'] as $f)
                                    <option value="{{ $f }}" {{ $filter === $f ? 'selected' : '' }}>{{ translate($f) }}</option>
                                @endforeach
                            </select>
                        </form>
                    </div>
                    <div class="card-body p-0">
                        <div class="k-table-wrap">
                            <table class="k-table">
                                <thead><tr>
                                    <th>{{ translate('product') }}</th><th>{{ translate('batch_number') }}</th>
                                    <th>{{ translate('expiry_date') }}</th><th class="text-end">{{ translate('quantity') }}</th>
                                    <th>{{ translate('status') }}</th><th class="text-end">{{ translate('action') }}</th>
                                </tr></thead>
                                <tbody>
                                @forelse ($batches as $b)
                                    @php($expired = $b->isExpired())
                                    <tr class="{{ $expired && $b->status === 'active' ? 'table-danger' : '' }}">
                                        <td>#{{ $b->product_id }}</td>
                                        <td>{{ $b->batch_number ?: '—' }}</td>
                                        <td class="fs-12">{{ $b->expiry_date?->toDateString() ?: '—' }}
                                            @if($expired && $b->status === 'active')<span class="k-badge k-badge--danger fs-10">{{ translate('expired') }}</span>@endif
                                        </td>
                                        <td class="text-end">{{ $b->quantity }}</td>
                                        <td><span class="k-badge k-badge--{{ $b->status === 'active' ? 'success' : 'secondary' }}">{{ translate($b->status) }}</span></td>
                                        <td class="text-end">
                                            @if ($b->status === 'active' && $b->quantity > 0)
                                                <form action="{{ route('admin.marketplace.batches.write-off', $b->id) }}" method="post" onsubmit="return confirm('{{ translate('write_off_this_batch_from_sellable_stock') }}?')">
                                                    @csrf <button class="btn btn-sm btn-outline-danger">{{ translate('write_off') }}</button>
                                                </form>
                                            @else
                                                <span class="text-muted fs-12">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted py-4">{{ translate('no_batches_yet') }}</td></tr>
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
