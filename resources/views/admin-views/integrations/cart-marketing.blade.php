@extends('layouts.admin.app')

@section('title', 'Cart Marketing')

@section('content')
<div class="content container-fluid">

    <div class="mb-4">
        <h1 class="page-header-title">
            Cart Marketing
        </h1>

        <p class="text-muted">
            Send a WhatsApp template to selected registered customers with active carts.
        </p>
    </div>
@if (session('success'))
    <div class="alert alert-success">
        {{ session('success') }}
    </div>
@endif

@if (session('error'))
    <div class="alert alert-danger">
        {{ session('error') }}
    </div>
@endif

@if ($errors->any())
    <div class="alert alert-danger">
        @foreach ($errors->all() as $error)
            <div>{{ $error }}</div>
        @endforeach
    </div>
@endif
    <form
        action="{{ route('admin.integrations.cart-marketing.send') }}"
        method="POST"
    >
        @csrf

        <div class="card mb-4">
            <div class="card-body">

                <div class="row align-items-end">

                    <div class="col-md-6">
                        <label class="form-label">
                            WhatsApp Template
                        </label>

                        <select
                            name="mapping_id"
                            class="form-control"
                            required
                        >
                            <option value="">
                                Select Template
                            </option>

                            @foreach ($templates as $template)
                                <option value="{{ $template->id }}">
                                    {{ $template->template_name }}
                                    -
                                    {{ $template->template_language }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6">
                        <button
                            type="submit"
                            class="btn btn-primary"
                        >
                            Send To Selected
                        </button>
                    </div>

                </div>

            </div>
        </div>


        <div class="card">
            <div class="card-body">

                @if (empty($customers))

                    <div class="text-center py-5 text-muted">
                        No eligible carts found.
                    </div>

                @else

                    <div class="table-responsive">

                        <table class="table table-hover align-middle">

                            <thead>
                            <tr>

                                <th style="width: 40px;">
                                    <input
                                        type="checkbox"
                                        id="select-all"
                                    >
                                </th>

                                <th>Customer</th>
                                <th>Phone</th>
                                <th>Items</th>
                                <th>Cart Total</th>
                                <th>Last Activity</th>

                            </tr>
                            </thead>

                            <tbody>

                            @foreach ($customers as $customer)

                                <tr>

                                    <td>
                                        <input
                                            type="checkbox"
                                            name="customer_ids[]"
                                            value="{{ $customer['customer_id'] }}"
                                            class="customer-checkbox"
                                        >
                                    </td>

                                    <td>
                                        <strong>
                                            {{ $customer['name'] ?: 'Customer #' . $customer['customer_id'] }}
                                        </strong>
                                    </td>

                                    <td>
                                        {{ $customer['phone'] }}
                                    </td>

                                    <td>
                                        {{ $customer['items_count'] }}
                                    </td>

                                    <td>
                                        {{ number_format($customer['cart_total'], 2) }}
                                    </td>

                                    <td>
                                        {{ $customer['last_activity'] }}
                                    </td>

                                </tr>

                            @endforeach

                            </tbody>

                        </table>

                    </div>

                @endif

            </div>
        </div>

    </form>

</div>
@endsection


@push('script')
<script>
document.addEventListener('DOMContentLoaded', function () {

    const selectAll = document.getElementById('select-all');

    if (!selectAll) {
        return;
    }

    selectAll.addEventListener('change', function () {

        document.querySelectorAll('.customer-checkbox')
            .forEach(function (checkbox) {
                checkbox.checked = selectAll.checked;
            });

    });

});
</script>
@endpush