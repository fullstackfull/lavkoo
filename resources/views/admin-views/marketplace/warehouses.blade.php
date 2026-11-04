@extends('layouts.admin.app')

@section('title', translate('warehouses'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2"><i class="fi fi-rr-warehouse-alt"></i> {{ translate('warehouses') }}</h2>
            <p class="mb-0 fs-12">{{ translate('locations_partition_sellable_stock_they_do_not_change_it') }}.</p>
        </div>

        <div class="row">
            <div class="col-lg-5">
                <div class="card mb-3">
                    <div class="card-header"><h5 class="mb-0">{{ translate('add_warehouse') }}</h5></div>
                    <div class="card-body">
                        <form action="{{ route('admin.marketplace.warehouses.store') }}" method="post" class="row g-2">
                            @csrf
                            <div class="col-7"><label class="form-label fs-12">{{ translate('name') }} *</label>
                                <input type="text" name="name" class="form-control form-control-sm" required maxlength="191"></div>
                            <div class="col-5"><label class="form-label fs-12">{{ translate('code') }}</label>
                                <input type="text" name="code" class="form-control form-control-sm" maxlength="40"></div>
                            <div class="col-12"><label class="form-label fs-12">{{ translate('address') }}</label>
                                <input type="text" name="address" class="form-control form-control-sm" maxlength="512"></div>
                            <div class="col-12"><label class="d-flex align-items-center gap-2 mb-0">
                                <input type="checkbox" name="is_default" value="1"> {{ translate('default_warehouse') }}
                            </label></div>
                            <div class="col-12 d-flex justify-content-end"><button class="btn btn-primary btn-sm">{{ translate('save') }}</button></div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h5 class="mb-0">{{ translate('all_warehouses') }}</h5></div>
                    <div class="card-body p-0">
                        <div class="k-table-wrap">
                            <table class="k-table">
                                <thead><tr><th>{{ translate('name') }}</th><th>{{ translate('code') }}</th><th>{{ translate('status') }}</th></tr></thead>
                                <tbody>
                                @forelse ($warehouses as $w)
                                    <tr>
                                        <td>{{ $w->name }} @if($w->is_default)<span class="k-badge k-badge--info fs-10">{{ translate('default') }}</span>@endif</td>
                                        <td>{{ $w->code ?: '—' }}</td>
                                        <td><span class="k-badge k-badge--{{ $w->status === 'active' ? 'success' : 'secondary' }}">{{ translate($w->status) }}</span></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-center text-muted py-3">{{ translate('no_warehouses_yet') }}</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card mb-3">
                    <div class="card-header"><h5 class="mb-0">{{ translate('product_distribution') }}</h5></div>
                    <div class="card-body">
                        <form method="get" class="d-flex gap-2 mb-3">
                            <input type="number" name="product_id" value="{{ $productId }}" class="form-control" min="1" placeholder="{{ translate('product_id') }}">
                            <button class="btn btn-outline-primary">{{ translate('look_up') }}</button>
                        </form>

                        @if ($product)
                            <div class="mb-2 fs-12">
                                <strong>{{ mb_substr($product->name, 0, 40) }}</strong> — {{ translate('current_stock') }}: <strong>{{ $product->current_stock }}</strong>
                            </div>
                            <table class="k-table">
                                <thead><tr><th>{{ translate('warehouse') }}</th><th class="text-end">{{ translate('quantity') }}</th></tr></thead>
                                <tbody>
                                @foreach ($warehouses as $w)
                                    <tr><td>{{ $w->name }}</td><td class="text-end">{{ $breakdown['by_warehouse'][$w->id] ?? 0 }}</td></tr>
                                @endforeach
                                <tr class="table-light"><td>{{ translate('unallocated') }}</td><td class="text-end fw-bold">{{ $breakdown['unallocated'] }}</td></tr>
                                <tr><td class="fw-bold">{{ translate('allocated') }}</td><td class="text-end fw-bold">{{ $breakdown['allocated'] }}</td></tr>
                                </tbody>
                            </table>

                            @if ($warehouses->isNotEmpty())
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6 class="fs-13">{{ translate('place_stock') }}</h6>
                                        <form action="{{ route('admin.marketplace.warehouses.place') }}" method="post" class="row g-1">
                                            @csrf
                                            <input type="hidden" name="product_id" value="{{ $product->id }}">
                                            <div class="col-7"><select name="warehouse_id" class="form-control form-control-sm">
                                                @foreach ($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach
                                            </select></div>
                                            <div class="col-3"><input type="number" name="quantity" class="form-control form-control-sm" min="1" value="1"></div>
                                            <div class="col-2"><button class="btn btn-sm btn-primary w-100">＋</button></div>
                                        </form>
                                        <small class="text-muted">{{ translate('from_the_unallocated_remainder') }}</small>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="fs-13">{{ translate('transfer') }}</h6>
                                        <form action="{{ route('admin.marketplace.warehouses.transfer') }}" method="post" class="row g-1">
                                            @csrf
                                            <input type="hidden" name="product_id" value="{{ $product->id }}">
                                            <div class="col-5"><select name="from_warehouse_id" class="form-control form-control-sm">
                                                @foreach ($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach
                                            </select></div>
                                            <div class="col-5"><select name="to_warehouse_id" class="form-control form-control-sm">
                                                @foreach ($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach
                                            </select></div>
                                            <div class="col-8"><input type="number" name="quantity" class="form-control form-control-sm mt-1" min="1" value="1" placeholder="{{ translate('quantity') }}"></div>
                                            <div class="col-4"><button class="btn btn-sm btn-outline-primary w-100 mt-1">→</button></div>
                                        </form>
                                    </div>
                                </div>
                            @endif
                        @elseif ($productId)
                            <p class="text-muted mb-0">{{ translate('product_not_found') }}</p>
                        @else
                            <p class="text-muted mb-0 fs-12">{{ translate('enter_a_product_id_to_see_and_manage_its_distribution') }}.</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
