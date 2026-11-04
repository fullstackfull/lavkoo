@extends('layouts.admin.app')

@section('title', translate('customer_Details'))

@push('css_or_js')
    <link rel="stylesheet" href="{{dynamicAsset(path:'public/assets/back-end/css/owl.min.css') }}">
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="d-print-none pb-2">
            <div class="row align-items-center">
                <div class="col-sm mb-2 mb-sm-0">
                    <div class="mb-3">
                        <h2 class="h1 mb-0 text-capitalize d-flex gap-2">
                            <img width="20"
                                 src="{{dynamicAsset(path: 'public/assets/back-end/img/add-new-seller.png') }}" alt="">
                            {{ translate('customer_details') }}
                        </h2>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3">

            <div class="col-lg-8">
                <div class="card card-body h-100">
                    <div class="d-flex gap-3 align-items-center justify-content-between flex-wrap mb-2">
                        <h3 class="mb-0"> {{ translate('customer').' # '.$customer['id']}}</h3>
                        <p class="mb-0 fs-12">
                            {{ translate('Join Date') }} : <span
                                class="fw-bold">{{date('d M Y',strtotime($customer['created_at'])) }}</span>
                        </p>
                    </div>
                    <div class="row g-2">
                        <div class="col-xl-6">
                            <div class="bg-section rounded-10 p-2 h-100">
                                <div class="d-flex gap-2 justify-content-between align-items-start">
                                    <div class="flex-grow-1 d-flex gap-2 align-items-center flex-wrap">
                                        <img src="{{ getStorageImages(path: $customer->image_full_url , type: 'backend-profile') }}"
                                            alt="{{ translate('image') }}" class="rounded-circle aspect-1 w-60">
                                        <div>
                                            <h3 class="mb-1">{{ $customer['f_name'].' '.$customer['l_name'] }}</h3>
                                            <p class="fs-12 mb-0">
                                                @if(!empty($customer['phone']))
                                                    <a href="tel:{{ $customer['phone'] }}" class="text-decoration-none text-dark">
                                                        {{ $customer['phone'] }}
                                                    </a>
                                                @else
                                                    {{ translate('no_data_found') }}
                                                @endif
                                            </p>
                                            <p class="fs-12 mb-0">
                                                @if(!empty($customer['email']))
                                                    <a href="mailto:{{ $customer['email'] }}" class="text-decoration-none text-dark">
                                                        {{ $customer['email'] }}
                                                    </a>
                                                @else
                                                    {{ translate('no_data_found') }}
                                                @endif
                                            </p>
                                        </div>
                                    </div>
                                    <a href="#profileUpdateOffcanvas" data-bs-toggle="offcanvas"><i
                                            class="fi fi-sr-pencil text-primary fs-16"></i></a>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-xl-3">
                            <div class="bg-FFF9F0 p-3 rounded-10 h-100">
                                <h2 class="text-warning mb-2 fw-bold">
                                    {{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $customer['wallet_balance'])) }}
                                </h2>
                                <p class="fs-12 mb-0">{{ translate('Wallet Balance') }}</p>
                            </div>
                        </div>
                        <div class="col-sm-6 col-xl-3">
                            <div class="bg-F0FFF3 p-3 rounded-10 h-100">
                                <h2 class="text-warning mb-2 fw-bold">{{$customer['loyalty_point']}}</h2>
                                <p class="fs-12 mb-0">{{ translate('Loyalty point') }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card card-body h-100">
                    <h3 class="mb-2">{{ translate('Saved Address') }}</h3>
                    <div
                        class="bg-section p-3 rounded-10 d-flex gap-2 align-items-center justify-content-between flex-wrap h-100">
                        <div class="flex-grow-1">
                            <h2 class="text-info mb-2 fw-bold">{{ count($customer->addresses) }}</h2>
                            <p class="fs-12 mb-0">{{ translate('Address_Available') }}</p>
                        </div>
                        <button type="button" class="btn btn-primary" data-bs-toggle="offcanvas"
                                data-bs-target="#savedAddressOffcanvas">{{ translate('View Address') }}</button>
                    </div>
                </div>
            </div>

            {{-- Order-stats are always rendered as a horizontal scrollable
                 row (col-lg-12), regardless of whether the customer has any
                 saved addresses or any orders. The previous 2-row 6-cell
                 grid (shown only when addresses === 0) didn't include the
                 Refunded card and was visually inconsistent with the
                 with-orders state. --}}
                <div class="col-lg-12">
                    <div class="card overflow-hidden">
                        <div class="card-body">
                            <div class="d-flex gap-3 overflow-x-auto custom-scrollable">
                                <div class="flex-grow-1 flex-shrink-0 user-select-none">
                                    <a class="order-stats">
                                        <div class="order-stats__content">
                                            <img width="20"
                                                 src="{{dynamicAsset(path:'public/assets/back-end/img/customer/total-order.png') }}"
                                                 alt="">
                                            <h4 class="order-stats__subtitle text-capitalize">{{ translate('total_orders') }}</h4>
                                        </div>
                                        <span
                                            class="order-stats__title text-success">{{$orderStatusArray['total_order']}}</span>
                                    </a>
                                </div>
                                <div class="flex-grow-1 flex-shrink-0 user-select-none">
                                    <a class="order-stats">
                                        <div class="order-stats__content">
                                            <img width="20"
                                                 src="{{dynamicAsset(path:'public/assets/back-end/img/customer/ongoing.png') }}"
                                                 alt="">
                                            <h4 class="order-stats__subtitle">{{ translate('ongoing') }}</h4>
                                        </div>
                                        <span
                                            class="order-stats__title text-success">{{$orderStatusArray['ongoing']}}</span>
                                    </a>
                                </div>
                                <div class="flex-grow-1 flex-shrink-0 user-select-none">
                                    <a class="order-stats">
                                        <div class="order-stats__content">
                                            <img width="20"
                                                 src="{{dynamicAsset(path:'public/assets/back-end/img/customer/completed.png') }}"
                                                 alt="">
                                            <h4 class="order-stats__subtitle">{{ translate('completed') }}</h4>
                                        </div>
                                        <span
                                            class="order-stats__title text-success">{{$orderStatusArray['completed']}}</span>
                                    </a>
                                </div>
                                <div class="flex-grow-1 flex-shrink-0 user-select-none">
                                    <a class="order-stats">
                                        <div class="order-stats__content">
                                            <img width="20"
                                                 src="{{dynamicAsset(path:'public/assets/back-end/img/customer/canceled.png') }}"
                                                 alt="">
                                            <h4 class="order-stats__subtitle">{{ translate('canceled') }}</h4>
                                        </div>
                                        <span
                                            class="order-stats__title text-success">{{$orderStatusArray['canceled']}}</span>
                                    </a>
                                </div>
                                <div class="flex-grow-1 flex-shrink-0 user-select-none">
                                    <a class="order-stats">
                                        <div class="order-stats__content">
                                            <img width="20"
                                                 src="{{dynamicAsset(path:'public/assets/back-end/img/customer/returned.png') }}"
                                                 alt="">
                                            <h4 class="order-stats__subtitle">{{ translate('returned') }}</h4>
                                        </div>
                                        <span
                                            class="order-stats__title text-success">{{$orderStatusArray['returned']}}</span>
                                    </a>
                                </div>
                                <div class="flex-grow-1 flex-shrink-0 user-select-none">
                                    <a class="order-stats">
                                        <div class="order-stats__content">
                                            <img width="20"
                                                 src="{{dynamicAsset(path:'public/assets/back-end/img/customer/failed.png') }}"
                                                 alt="">
                                            <h4 class="order-stats__subtitle">{{ translate('failed') }}</h4>
                                        </div>
                                        <span
                                            class="order-stats__title text-success">{{$orderStatusArray['failed']}}</span>
                                    </a>
                                </div>
                                <div class="flex-grow-1 flex-shrink-0 user-select-none">
                                    <a class="order-stats">
                                        <div class="order-stats__content">
                                            <img width="20"
                                                 src="{{dynamicAsset(path:'public/assets/back-end/img/customer/refunded.png') }}"
                                                 alt="">
                                            <h4 class="order-stats__subtitle">{{ translate('refunded') }}</h4>
                                        </div>
                                        <span
                                            class="order-stats__title text-success">{{$orderStatusArray['refunded']}}</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                            <h3 class="card-title m-0">{{ translate('orders') }} <span
                                    class="badge badge-info text-bg-info">{{$orders->total() }}</span></h3>
                            <div class="d-flex flex-wrap gap-3">
                                <form action="{{ url()->current() }}" method="GET">
                                    <div class="form-group">
                                        <div class="input-group">
                                            <input id="datatableSearch_" type="search" name="searchValue"
                                                   class="form-control"
                                                   placeholder="{{ translate('search_orders') }}"
                                                   aria-label="Search orders"
                                                   value="{{ requestString('searchValue') }}"
                                            >
                                            <div class="input-group-append search-submit">
                                                <button type="submit">
                                                    <i class="fi fi-rr-search"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </form>

                                <div class="position-relative">
                                    @if((request('searchValue') || $from || $to || $filter || $dateType))
                                        <div
                                            class="position-absolute inset-inline-end-0 top-0 mt-n1 me-n1 btn-circle bg-danger border border-white border-2"
                                            style="--size: 12px;"></div>
                                    @endif
                                    <button type="button" class="btn btn-primary" data-bs-toggle="offcanvas"
                                            data-bs-target="#offcanvasCustomerFilter">
                                        <i class="fi fi-sr-settings-sliders"></i>
                                        {{ translate('Filter') }}
                                    </button>
                                </div>

                                <a type="button" class="btn btn-outline-primary"
                                   href="{{route('admin.customer.order-list-export',[$customer['id'], 'searchValue' => request('searchValue')]) }}">
                                    <i class="fi fi-sr-inbox-in"></i>
                                    <span class="fs-12">{{ translate('export') }}</span>
                                </a>
                            </div>
                        </div>

                        <div class="k-table-wrap">
                            <table
                                class="k-table">
                                <thead>
                                <tr>
                                    <th>{{ translate('sl') }}</th>
                                    <th>{{ translate('order_ID') }}</th>
                                    <th class="text-capitalize">{{ translate('order_date') }}</th>
                                    <th>{{ translate('Store') }}</th>
                                    <th>{{ translate('total') }}</th>
                                    <th>{{ translate('order_Status') }}</th>
                                    <th class="text-center">{{ translate('action') }}</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($orders as $key=>$order)
                                    <tr>
                                        <td>{{$orders->firstItem()+$key}}</td>
                                        <td>
                                            <a href="{{route('admin.orders.details',['id'=>$order['id']]) }}"
                                               class="text-dark text-hover-primary">
                                                {{ $order['id'] }}
                                                {!! $order->order_type == 'POS' ? '<span class="text--primary">(POS)</span>' : '' !!}
                                            </a>
                                        </td>
                                        <td>
                                            <div>{{ date('d M Y', strtotime($order['created_at'])) }},</div>
                                            <div>{{ date('h:i A', strtotime($order['created_at'])) }}</div>
                                        </td>
                                        <td>
                                            @if (isset($order->seller_id) && isset($order->seller_is))
                                                <a href="{{ $order->seller_is == 'seller' && $order->seller?->shop ? route('admin.vendors.view', ['id' => $order->seller->shop->id]) : route('admin.business-settings.inhouse-shop') }}"
                                                   class="store-name fw-medium hover-primary text-dark text-wrap d-block max-w-360">
                                                    @if ($order->seller_is == 'seller')
                                                        {{ isset($order->seller?->shop) ? $order->seller?->shop?->name : translate('Store_not_found') }}
                                                    @elseif($order->seller_is == 'admin')
                                                        {{ getInHouseShopConfig(key: 'name') }}
                                                    @endif
                                                </a>
                                            @else
                                                {{ translate('Store_not_found') }}
                                            @endif
                                        </td>
                                        <td>
                                            <div class="">
                                                {{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $order['order_amount'])) }}
                                            </div>
                                            @if($order->payment_status=='paid')
                                                <span
                                                    class="badge badge-success text-bg-success">{{ translate('paid') }}</span>
                                            @else
                                                <span
                                                    class="badge badge-danger text-bg-danger">{{ translate('unpaid') }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($order['order_status']=='pending')
                                                <span class="badge badge-info text-bg-info">
                                                    {{ translate($order['order_status']) }}
                                                </span>

                                            @elseif($order['order_status']=='processing' || $order['order_status']=='out_for_delivery')
                                                <span class="badge badge-warning text-bg-warning">
                                                        {{str_replace('_',' ',$order['order_status'] == 'processing' ? translate('packaging'):translate($order['order_status'])) }}
                                                    </span>
                                            @elseif($order['order_status']=='confirmed')
                                                <span class="badge badge-success text-bg-success">
                                                        {{ translate($order['order_status']) }}
                                                    </span>
                                            @elseif($order['order_status']=='failed')
                                                <span class="badge badge-danger text-bg-danger">
                                                        {{ translate('failed_to_deliver') }}
                                                    </span>
                                            @elseif($order['order_status']=='delivered')
                                                <span class="badge badge-success text-bg-success">
                                                        {{ translate($order['order_status']) }}
                                                    </span>
                                            @else
                                                <span class="badge badge-danger text-bg-danger">
                                                        {{ translate($order['order_status']) }}
                                                    </span>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="d-flex justify-content-center gap-10">
                                                <a class="btn btn-outline-primary edit icon-btn"
                                                   title="{{ translate('view') }}"
                                                   href="{{route('admin.orders.details',['id'=>$order['id']]) }}">
                                                    <i class="fi fi-rr-eye"></i>
                                                </a>
                                                <a class="btn btn-outline-success icon-btn"
                                                   title="{{ translate('invoice') }}" target="_blank"
                                                   href="{{route('admin.orders.generate-invoice',[$order['id']]) }}">
                                                    <i class="fi fi-sr-down-to-line"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="table-responsive mt-4">
                            <div class="d-flex justify-content-end">
                                {!! $orders->appends(request()->query())->links() !!}
                            </div>
                        </div>
                        @if(count($orders)==0)
                            @include('layouts.admin.partials._empty-state',['text'=>'no_order_found'],['image'=>'default'])
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="offcanvas offcanvas-end" tabindex="-1" id="savedAddressOffcanvas"
         aria-labelledby="savedAddressOffcanvasLabel">
        <div class="offcanvas-header bg-body">
            <h3 class="mb-0">{{ translate('Saved Address') }}</h3>
            <button type="button" class="btn-close shadow-none" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
            @if($customer->addresses->isEmpty())
                <div class="text-center">
                    <h5 class="mb-2">{{ translate('no_address_found') }}</h5>
                    <p class="fs-12">{{ translate('you_have_not_added_any_address_yet') }}</p>
                </div>
            @else
                @foreach($customer->addresses as $address)
                    <div class="p-2 bg-section rounded mb-3 mb-sm-20">
                        <div class="fs-14 mb-2">
                            <span class="text-dark fw-medium">{{ ucwords($address['address_type']) }}</span>
                            <span>
                                ({{ translate($address['is_billing'] == 0 ? 'Shipping Address' : 'Billing Address') }})
                            </span>
                        </div>
                        <div class="bg-white rounded p-2 d-flex flex-column gap-2 fs-12">
                            <div class="d-flex gap-1 align-items-center">
                                <span class="min-w-80">{{ translate('Name') }}</span>
                                <span>:</span>
                                <span>{{ $address['contact_person_name'] }} ({{ $address['phone'] }})</span>
                            </div>

                            @if(!empty($address['email']))
                                <div class="d-flex gap-1 align-items-center">
                                    <span class="min-w-80">{{ translate('Email') }}</span>
                                    <span>:</span>
                                    <span>{{ $address['email'] }}</span>
                                </div>
                            @endif

                            <div class="d-flex gap-1 align-items-center">
                                <span class="min-w-80">{{ translate('Address_Type') }}</span>
                                <span>:</span>
                                <span>{{ $address['address_type'] }}</span>
                            </div>

                            @if(!empty($address['country']))
                                <div class="d-flex gap-1 align-items-center">
                                    <span class="min-w-80">{{ translate('Country') }}</span>
                                    <span>:</span>
                                    <span>{{ $address['country'] }}</span>
                                </div>
                            @endif

                            @if(!empty($address['city']))
                                <div class="d-flex gap-1 align-items-center">
                                    <span class="min-w-80">{{ translate('City') }}</span>
                                    <span>:</span>
                                    <span>{{ $address['city'] }}</span>
                                </div>
                            @endif

                            @if(!empty($address['zip']))
                                <div class="d-flex gap-1 align-items-center">
                                    <span class="min-w-80">{{ translate('Zip_Code') }}</span>
                                    <span>:</span>
                                    <span>{{ $address['zip'] }}</span>
                                </div>
                            @endif

                            <div class="d-flex gap-1 align-items-center">
                                <span class="min-w-80">{{ translate('Address') }}</span>
                                <span>:</span>
                                <span>{{ $address['address'] }}</span>
                            </div>
                        </div>
                    </div>
                @endforeach
            @endif
        </div>
    </div>

    @include("admin-views.customer.customer-update-partials")
    @include('admin-views.customer.partials.offcanvas._filter-offcanvas')
@endsection

@push('script')
    <script type="text/javascript">
        'use strict';

        var swiper = new Swiper(".addressSwiper", {
            slidesPerView: 3,
            spaceBetween: 20,
        });

        $(document).ready(function () {

            $("#date_type").change(function() {
                let val = $(this).val();
                $('#from_div').toggle(val === 'custom_date');
                $('#to_div').toggle(val === 'custom_date');

                if(val === 'custom_date'){
                    $('#from_date').attr('required','required');
                    $('#to_date').attr('required','required');
                    $('.filter-btn').attr('class','filter-btn col-12 text-right');
                }else{
                    $('#from_date').val(null).removeAttr('required')
                    $('#to_date').val(null).removeAttr('required')
                    $('.filter-btn').attr('class','col-sm-6 col-md-3 filter-btn');
                }
            }).change();

            $('#from_date,#to_date').change(function () {
                let fr = $('#from_date').val();
                let to = $('#to_date').val();
                if(fr != ''){
                    $('#to_date').attr('required','required');
                }
                if(to != ''){
                    $('#from_date').attr('required','required');
                }
                if (fr != '' && to != '') {
                    if (fr > to) {
                        $('#from_date').val('');
                        $('#to_date').val('');
                        toastMagic.error($('#get-date-range-message').data('error'));
                    }
                }
            })
        });
    </script>

@endpush
