@extends('layouts.admin.app')

@section('title', translate('shipping_permissions'))

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('shipping_permissions')" :subtitle="translate('shipping_permissions_subtitle')" />

        @if (!$ready)
            <div class="alert alert-warning fs-12" role="alert">{{ translate('the_shipping_permission_table_has_not_been_created_yet') }}</div>
        @endif

        <div class="card mb-3">
            <div class="card-body">
                {{-- A product is chosen by its id: this screen is reached from a product, and it grants nothing by itself. --}}
                <form action="{{ route('admin.shipping.destination-permissions.index') }}" method="GET" class="row g-2 align-items-end">
                    <div class="col-sm-4">
                        <label class="form-label" for="product_id">{{ translate('product_id') }}</label>
                        <input type="number" min="1" class="form-control" id="product_id" name="product_id" value="{{ request('product_id') }}">
                    </div>
                    <div class="col-sm-2">
                        <button type="submit" class="btn btn--primary">{{ translate('show') }}</button>
                    </div>
                </form>
            </div>
        </div>

        @if ($product)
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">{{ $product->name }}</h5>
                    <small class="text-muted">{{ translate('a_destination_with_no_row_is_refused') }}</small>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>{{ translate('destination') }}</th>
                                    <th>{{ translate('may_ship') }}</th>
                                    <th>{{ translate('may_export') }}</th>
                                    <th>{{ translate('may_import') }}</th>
                                    <th>{{ translate('clearance_reference') }}</th>
                                    <th>{{ translate('recorded_at') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($permissions as $permission)
                                    <tr>
                                        <td>{{ $permission->destination_country }}</td>
                                        <td>{!! $permission->may_ship ? '&#10003;' : '&mdash;' !!}</td>
                                        <td>{!! $permission->may_export ? '&#10003;' : '&mdash;' !!}</td>
                                        <td>{!! $permission->may_import ? '&#10003;' : '&mdash;' !!}</td>
                                        <td>{{ $permission->clearance_reference ?: '—' }}</td>
                                        <td>{{ $permission->recorded_at }}</td>
                                        <td class="text-end">
                                            <form action="{{ route('admin.shipping.destination-permissions.record') }}" method="POST">
                                                @csrf
                                                <input type="hidden" name="product_id" value="{{ $product->id }}">
                                                <input type="hidden" name="destination_country" value="{{ $permission->destination_country }}">
                                                <input type="hidden" name="withdraw" value="1">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">{{ translate('withdraw') }}</button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-muted">{{ translate('this_product_may_not_be_shipped_to_any_other_country') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <hr>

                    <form action="{{ route('admin.shipping.destination-permissions.record') }}" method="POST" class="row g-3 align-items-end">
                        @csrf
                        <input type="hidden" name="product_id" value="{{ $product->id }}">
                        <div class="col-sm-3">
                            <label class="form-label" for="destination_country">{{ translate('destination') }}</label>
                            <select class="form-control" id="destination_country" name="destination_country" required>
                                @foreach ($countries as $country)
                                    <option value="{{ $country }}">{{ $country }}</option>
                                @endforeach
                            </select>
                        </div>
                        @foreach (['may_ship', 'may_export', 'may_import'] as $permission)
                            <div class="col-sm-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="1" id="{{ $permission }}" name="{{ $permission }}">
                                    <label class="form-check-label" for="{{ $permission }}">{{ translate($permission) }}</label>
                                </div>
                            </div>
                        @endforeach
                        <div class="col-sm-4">
                            <label class="form-label" for="clearance_reference">{{ translate('clearance_reference') }}</label>
                            <input type="text" class="form-control" id="clearance_reference" name="clearance_reference" maxlength="191"
                                   @disabled(!$mayRecordClearance)
                                   placeholder="{{ translate('the_licence_registration_or_decision_this_permission_rests_on') }}">
                            @if (!empty($clearanceNeededFor))
                                {{-- Named here so the reference is asked for while the permission is being granted, not discovered by a refused customer. --}}
                                <small class="text-muted">{{ translate('a_clearance_is_required_for') }}: {{ implode(', ', $clearanceNeededFor) }}</small>
                            @endif
                        </div>
                        <div class="col-sm-3">
                            <button type="submit" class="btn btn--primary">{{ translate('record_the_permission') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    </div>
@endsection
