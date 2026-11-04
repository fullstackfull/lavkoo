@extends('layouts.admin.app')

@section('title', translate('suppliers'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <i class="fi fi-rr-truck-side"></i> {{ translate('suppliers') }}
            </h2>
            <a href="{{ route('admin.marketplace.purchase-orders.index') }}" class="btn btn-outline-primary btn-sm">
                {{ translate('purchase_orders') }}
            </a>
        </div>

        <div class="row">
            <div class="col-lg-4">
                <div class="card mb-3">
                    <div class="card-header"><h5 class="mb-0">{{ translate('add_supplier') }}</h5></div>
                    <div class="card-body">
                        <form action="{{ route('admin.marketplace.suppliers.store') }}" method="post">
                            @csrf
                            <div class="mb-2"><label class="form-label fs-12">{{ translate('name') }} *</label>
                                <input type="text" name="name" class="form-control" required maxlength="191"></div>
                            <div class="mb-2"><label class="form-label fs-12">{{ translate('contact_name') }}</label>
                                <input type="text" name="contact_name" class="form-control" maxlength="191"></div>
                            <div class="row">
                                <div class="col-6 mb-2"><label class="form-label fs-12">{{ translate('email') }}</label>
                                    <input type="email" name="email" class="form-control" maxlength="191"></div>
                                <div class="col-6 mb-2"><label class="form-label fs-12">{{ translate('phone') }}</label>
                                    <input type="text" name="phone" class="form-control" maxlength="40"></div>
                            </div>
                            <div class="mb-2"><label class="form-label fs-12">{{ translate('tax_number') }}</label>
                                <input type="text" name="tax_number" class="form-control" maxlength="60"></div>
                            <div class="mb-2"><label class="form-label fs-12">{{ translate('address') }}</label>
                                <input type="text" name="address" class="form-control" maxlength="512"></div>
                            <button class="btn btn-primary w-100">{{ translate('save') }}</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="mb-0">{{ translate('all_suppliers') }}</h5>
                        <form method="get"><input type="text" name="search" value="{{ $search }}" class="form-control form-control-sm" placeholder="{{ translate('search') }}"></form>
                    </div>
                    <div class="card-body p-0">
                        <div class="k-table-wrap">
                            <table class="k-table">
                                <thead><tr>
                                    <th>{{ translate('name') }}</th><th>{{ translate('contact') }}</th>
                                    <th>{{ translate('status') }}</th><th class="text-end">{{ translate('action') }}</th>
                                </tr></thead>
                                <tbody>
                                @forelse ($suppliers as $s)
                                    <tr>
                                        <td>{{ $s->name }}@if($s->tax_number)<div class="fs-10 text-muted">{{ $s->tax_number }}</div>@endif</td>
                                        <td class="fs-12">{{ $s->contact_name ?: '—' }}<br>{{ $s->phone ?: $s->email }}</td>
                                        <td><span class="k-badge k-badge--{{ $s->status === 'active' ? 'success' : 'secondary' }}">{{ translate($s->status) }}</span></td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-secondary" type="button" data-toggle="collapse" data-target="#edit-{{ $s->id }}">{{ translate('edit') }}</button>
                                            <form action="{{ route('admin.marketplace.suppliers.destroy', $s->id) }}" method="post" class="d-inline" onsubmit="return confirm('{{ translate('are_you_sure') }}')">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger">{{ translate('delete') }}</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <tr class="collapse" id="edit-{{ $s->id }}">
                                        <td colspan="4">
                                            <form action="{{ route('admin.marketplace.suppliers.update', $s->id) }}" method="post" class="row g-2">
                                                @csrf @method('PUT')
                                                <div class="col-md-3"><input type="text" name="name" value="{{ $s->name }}" class="form-control form-control-sm" required></div>
                                                <div class="col-md-3"><input type="text" name="contact_name" value="{{ $s->contact_name }}" class="form-control form-control-sm" placeholder="{{ translate('contact_name') }}"></div>
                                                <div class="col-md-2"><input type="text" name="phone" value="{{ $s->phone }}" class="form-control form-control-sm" placeholder="{{ translate('phone') }}"></div>
                                                <div class="col-md-2">
                                                    <select name="status" class="form-control form-control-sm">
                                                        <option value="active" {{ $s->status === 'active' ? 'selected' : '' }}>{{ translate('active') }}</option>
                                                        <option value="inactive" {{ $s->status === 'inactive' ? 'selected' : '' }}>{{ translate('inactive') }}</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">{{ translate('update') }}</button></div>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-center text-muted py-4">{{ translate('no_suppliers_yet') }}</td></tr>
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
