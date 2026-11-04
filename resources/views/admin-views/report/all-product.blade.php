@extends('layouts.admin.app')
@section('title', translate('product_Report'))
@push('css_or_js')
    <meta name="csrf-token" content="{{ csrf_token() }}">
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex gap-2 align-items-center">
                <img width="20" src="{{ dynamicAsset(path: 'public/assets/back-end/img/seller_sale.png')}}" alt="">
                {{translate('product_Report')}}
            </h2>
        </div>

        @include('admin-views.report.product-report-inline-menu')

        <div class="card card-body mb-3">
            <form action="" id="form-data" method="GET">
                <h3 class="mb-3">{{translate('filter_Data')}}</h3>
                <div class="bg-section rounded p-12 p-sm-20 mb-20">
                    <div class="row g-3 align-items-end">
                        <div class="col-sm-6 col-xl-3">
                            <label class="text-dark mb-2">{{ translate('select_Vendor')}}</label>
                            <select class="custom-select" name="seller_id">
                                <option class="text-center" value="all" {{ $seller_id == 'all' ? 'selected' : '' }}>
                                    {{translate('all')}}
                                </option>
                                <option class="text-center"
                                        value="inhouse" {{ $seller_id == 'inhouse' ? 'selected' : '' }}>
                                    {{translate('inhouse')}}
                                </option>
                                @foreach($sellers as $seller)
                                    <option
                                        value="{{$seller['id'] }}" {{$seller_id==$seller['id']?'selected':''}}>
                                       {{ $seller?->shop?->name ?? "-" }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-sm-6 col-xl-3">
                            <label class="text-dark mb-2">{{ translate('select_Date')}}</label>
                            <div class="select-wrapper">
                                <select class="form-select" name="date_type" id="date_type">
                                    <option
                                        value="this_year" {{ $date_type == 'this_year'? 'selected' : '' }}>{{translate('this_Year')}}</option>
                                    <option
                                        value="this_month" {{ $date_type == 'this_month'? 'selected' : '' }}>{{translate('this_Month')}}</option>
                                    <option
                                        value="this_week" {{ $date_type == 'this_week'? 'selected' : '' }}>{{translate('this_Week')}}</option>
                                    <option
                                        value="today" {{ $date_type == 'today'? 'selected' : '' }}>{{translate('today')}}</option>
                                    <option
                                        value="custom_date" {{ $date_type == 'custom_date'? 'selected' : '' }}>{{translate('custom_Date')}}</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-sm-6 col-xl-3" id="from_div">
                            <div>
                                <label class="text-dark mb-2">{{ ucwords(translate('Start_Date'))}}</label>
                                <input type="date" name="from" value="{{$from}}" id="from_date" class="form-control">
                            </div>
                        </div>
                        <div class="col-sm-6 col-xl-3" id="to_div">
                            <div>
                                <label class="text-dark mb-2">{{ ucwords(translate('End_Date'))}}</label>
                                <input type="date" value="{{$to}}" name="to" id="to_date" class="form-control">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="d-flex justify-content-end gap-3">
                    <a href="{{ url()->current() }}" class="btn btn-secondary flex-grow-1 max-w-120">
                        {{translate('reset')}}
                    </a>
                    <button type="submit" class="btn btn-primary flex-grow-1 max-w-120 filter-btn">
                        {{translate('filter')}}
                    </button>
                </div>
            </form>
        </div>

        <div class="card card-body mb-3">
            <div class="row g-3">
                <div class="col-xl-8">
                    <div class="bg-section rounded-10 p-12 p-sm-20 h-100">
                        <div class="row g-3">
                            <div class="col-12">
                                <div class="d-flex gap-3 align-items-center bg-white rounded-10 p-2 overflow-wrap-anywhere">
                                    <div class="flex-shrink-0 aspect-1 border rounded-circle w-60px d-grid place-items-center bg-white">
                                        <img width="30" src="{{dynamicAsset(path: 'public/assets/back-end/img/packaging-new.png')}}" alt="" class="aspect-1">
                                    </div>
                                    <div>
                                        <h2 class="fs-26 fw-bold mb-2 text-primary">{{ $product_count['reject_product_count']+$product_count['active_product_count']+$product_count['pending_product_count'] }}</h2>
                                        <div class="text-dark">{{translate('total_Product')}}</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="bg-white rounded-10 px-3 py-2 overflow-wrap-anywhere">
                                    <h3 class="fs-20">
                                        <strong class="text-danger">{{ $product_count['reject_product_count'] }}</strong>
                                    </h3>
                                    <div class="text-dark">{{translate('rejected')}}</div>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="bg-white rounded-10 px-3 py-2 overflow-wrap-anywhere">
                                    <h3 class="fs-20">
                                        <strong class="text-primary">{{ $product_count['pending_product_count'] }}</strong>
                                    </h3>
                                     <div class="text-dark">{{translate('pending')}}</div>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="bg-white rounded-10 px-3 py-2 overflow-wrap-anywhere">
                                    <h3 class="fs-20">
                                        <strong class="text-success">{{ $product_count['active_product_count'] }}</strong>
                                    </h3>
                                     <div class="text-dark">{{translate('active')}}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4">
                    <div class="d-flex flex-column gap-3 h-100">
                        <div class="d-flex gap-3 align-items-center bg-success bg-opacity-10 rounded-10 p-2 px-sm-5 overflow-wrap-anywhere h-100">
                            <div class="flex-shrink-0 aspect-1 border rounded-circle w-60px d-grid place-items-center bg-white">
                                <img width="30" src="{{dynamicAsset(path: 'public/assets/back-end/img/total-product-sale.png')}}" alt="" class="aspect-1">
                            </div>
                            <div>
                                <h2 class="fs-26 fw-bold mb-2 text-success">{{ $total_product_sale }}</h2>
                                <div class="text-dark">{{translate('Total_Product_Sale')}}</div>
                            </div>
                        </div>
                        <div class="d-flex gap-3 align-items-center bg-warning bg-opacity-10 rounded-10 p-2 px-sm-5 overflow-wrap-anywhere h-100">
                            <div class="flex-shrink-0 aspect-1 border rounded-circle w-60px d-grid place-items-center bg-white">
                                <img width="30" src="{{dynamicAsset(path: 'public/assets/back-end/img/discount.png')}}" alt="" class="aspect-1">
                            </div>
                            <div>
                                <h2 class="fs-26 fw-bold mb-2 text-warning">
                                    {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $total_discount_given), currencyCode: getCurrencyCode()) }}
                                </h2>
                                <div class="text-dark d-flex gap-1 align-items-center">
                                    {{translate('total_Discount_Given')}}
                                    <span class="lh-1" data-bs-toggle="tooltip" data-bs-title="{{translate('product_wise_discounted_amount_will_be_shown_here')}}">
                                        <i class="fi fi-rr-info"></i>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @include('layouts.admin.partials._apexcharts',['title'=>'product_Statistics','statisticsValue'=>$chart_data['total_product'],'label'=>array_keys($chart_data['total_product']),'statisticsTitle'=>'total_product','getCurrency'=>false])

        <div class="mt-3">
        <x-k.data-view :title="translate('total_Products')" :count="$products->total()"
                       searchName="search" :searchValue="$search"
                       :searchPlaceholder="translate('search_product_name')">

            <x-slot:actions>
                <a class="k-btn k-btn--secondary"
                   href="{{ route('admin.report.all-product-excel', ['seller_id' => request('seller_id'), 'search' => request('search'), 'date_type' => request('date_type'), 'from' => request('from'), 'to' => request('to')]) }}">
                    <x-k.icon name="download" :size="15" /> {{ translate('export') }}
                </a>
            </x-slot:actions>

            <table class="k-table">
                <thead>
                <tr>
                    <th>{{ translate('product_Name') }}</th>
                    <th class="k-table__num">{{ translate('product_Unit_Price') }}</th>
                    <th class="k-table__num">{{ translate('total_Amount_Sold') }}</th>
                    <th class="k-table__num">{{ translate('total_Quantity_Sold') }}</th>
                    <th class="k-table__num">{{ translate('average_Product_Value') }}</th>
                    <th class="k-table__num">{{ translate('current_Stock_Amount') }}</th>
                    <th>{{ translate('average_Ratings') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($products as $product)
                    @php
                        $soldAmount = $product->orderDetails[0]->total_sold_amount ?? 0;
                        $soldQuantity = $product->orderDetails[0]->product_quantity ?? 0;
                    @endphp
                    <tr>
                        <td>
                            <a href="{{route('admin.products.view',['addedBy'=>($product['added_by'] =='seller'?'vendor' : 'in-house'),'id'=>$product['id']])}}"
                               class="k-truncate" style="display:block;max-inline-size:260px" title="{{ $product['name'] }}">
                                {{ $product['name'] }}
                            </a>
                        </td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $product->unit_price), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $soldAmount), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ $soldQuantity }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $soldAmount / max(1, $soldQuantity)), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num">
                            @if ($product->product_type == 'digital')
                                <x-k.badge :tone="$product->status == 1 ? 'success' : 'danger'">
                                    {{ $product->status == 1 ? translate('available') : translate('not_available') }}
                                </x-k.badge>
                            @else
                                <span class="k-num">{{ $product->current_stock }}</span>
                            @endif
                        </td>
                        <td>
                            <span class="k-num">{{ count($product->rating) > 0 ? number_format($product->rating[0]->average, 2, '.', ' ') : 0 }}</span>
                            <span class="k-text-subtle">({{ $product->reviews->count() }})</span>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if(count($products)==0)
                <x-k.empty icon="catalog" :title="translate('no_product_found')"
                           :text="$search ? translate('no_product_matches_your_search') : null" />
            @endif

            @if ($products->total() > 0)
                <x-slot:pager>
                    <span class="k-pager__info">
                        {{ translate('showing') }}
                        <span class="k-num">{{ $products->firstItem() }}–{{ $products->lastItem() }}</span>
                        {{ translate('of') }} <span class="k-num">{{ $products->total() }}</span>
                    </span>
                    <div>{!! $products->appends(request()->except('page'))->links() !!}</div>
                </x-slot:pager>
            @endif
        </x-k.data-view>
        </div>
    </div>
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/new/back-end/js/apexcharts.js')}}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/new/back-end/js/apexcharts-data-show.js')}}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/new/back-end/js/admin/product-report.js') }}"></script>
@endpush
