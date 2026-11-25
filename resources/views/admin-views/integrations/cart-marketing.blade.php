@extends('layouts.admin.app')

@section('title', translate('cart_marketing'))

@section('content')
<div class="content container-fluid">

    <div class="mb-4">
        <h1 class="page-header-title">
            {{ translate('cart_marketing') }}
        </h1>

        <p class="text-muted">
            {{ translate('send_a_whatsapp_template_to_selected_registered_customers_with_active_carts') }}
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
                            {{ translate('whatsapp_template') }}
                        </label>

                        <select
                            name="mapping_id"
                            class="form-control"
                            required
                        >
                            <option value="">
                                {{ translate('select_template') }}
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

                    @if (count($marketChoices ?? []) > 0)
                        <div class="col-md-3">
                            <label class="form-label" for="cart-marketing-market">{{ translate('market') }}</label>
                            <select name="market" id="cart-marketing-market" class="form-control"
                                    data-index="{{ route('admin.integrations.cart-marketing.index') }}">
                                <option value="">{{ $mayChooseEveryMarket ? translate('every_market') : translate('every_market_you_work_with') }}</option>
                                @foreach ($marketChoices as $marketChoice)
                                    <option value="{{ $marketChoice }}" @selected($market === $marketChoice)>{{ $marketChoice }}</option>
                                @endforeach
                            </select>
                            <p class="fs-12 text-muted mb-0 mt-1">
                                {{ translate('the_list_and_the_send_are_both_limited_to_this_market') }}
                            </p>
                        </div>
                    @endif

                    <div class="col-md-3">
                        <button
                            type="submit"
                            class="btn btn-primary"
                            @disabled(!($mayMarket ?? true))
                        >
                            {{ translate('send_to_selected') }}
                        </button>
                    </div>

                </div>

            </div>
        </div>


        <div class="card">
            <div class="card-body">

                @if (empty($customers))

                    <div class="text-center py-5 text-muted">
                        {{ translate('no_eligible_carts_found') }}
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

                                <th>{{ translate('customer') }}</th>
                                <th>{{ translate('phone') }}</th>
                                <th>{{ translate('items') }}</th>
                                <th>{{ translate('cart_total') }}</th>
                                <th>{{ translate('last_activity') }}</th>

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
                                            {{ $customer['name'] ?: translate('customer') . ' #' . $customer['customer_id'] }}
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

    if (selectAll) {

        selectAll.addEventListener('change', function () {

            document.querySelectorAll('.customer-checkbox')
                .forEach(function (checkbox) {
                    checkbox.checked = selectAll.checked;
                });

        });

    }

    // Choosing a market reloads the list for it. The same value posts with the send, so what the
    // operator is looking at and what the button sends to are one choice and cannot diverge.
    const market = document.getElementById('cart-marketing-market');

    if (market) {

        market.addEventListener('change', function () {

            window.location.assign(
                market.getAttribute('data-index') + '?market=' + encodeURIComponent(market.value)
            );

        });

    }

});
</script>
@endpush