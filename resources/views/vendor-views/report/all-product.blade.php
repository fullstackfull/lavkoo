@extends('layouts.vendor.app')
@section('title', translate('product_Report'))
@push('css_or_js')
    <meta name="csrf-token" content="{{ csrf_token() }}">
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex gap-2 align-items-center">
                <img width="20" src="{{ dynamicAsset(path: 'public/assets/back-end/img/seller_sale.png') }}" alt="">
                {{translate('product_report')}}
            </h2>
        </div>

        @include('vendor-views.report.product-report-inline-menu')

        <div class="card mb-3">
            <div class="card-body">
                <form action="" id="form-data" method="GET">
                    <h4 class="mb-3">{{translate('filter_Data')}}</h4>
                    <div class="bg-section rounded p-12 p-sm-20 mb-20">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <div>
                                    <label for="date_type" class="text-dark">{{ translate('filter_Data') }}</label>
                                    <select class="form-control __form-control" name="date_type" id="date_type">
                                        <option value="this_year" {{ $date_type == 'this_year'? 'selected' : '' }}>{{translate('this_Year')}}</option>
                                        <option value="this_month" {{ $date_type == 'this_month'? 'selected' : '' }}>{{translate('this_Month')}}</option>
                                        <option value="this_week" {{ $date_type == 'this_week'? 'selected' : '' }}>{{translate('this_Week')}}</option>
                                        <option value="today" {{ $date_type == 'today'? 'selected' : '' }}>{{translate('today')}}</option>
                                        <option value="custom_date" {{ $date_type == 'custom_date'? 'selected' : '' }}>{{translate('custom_Date')}}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-sm-6 col-md-4 d--none" id="from_div">
                                <div>
                                    <label for="from_date" class="text-dark">{{translate('Start_Date')}}</label>
                                    <input type="date" name="from" value="{{$from}}" id="from_date" class="form-control">
                                </div>
                            </div>
                            <div class="col-sm-6 col-md-4 d--none" id="to_div">
                                <div>
                                    <label for="to_date" class="text-dark">{{translate('End_Date')}}</label>
                                    <input type="date" value="{{$to}}" name="to" id="to_date" class="form-control">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end gap-3">
                        <a href="{{ url()->current() }}" class="btn btn-secondary flex-grow-1 max-w-120">
                            {{translate('reset')}}
                        </a>
                        <button type="submit" class="btn btn--primary flex-grow-1 max-w-120 filter-btn">
                            {{translate('filter')}}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card card-body mb-3">
            <div class="row g-2">
                <div class="col-xl-8">
                    <div class="bg-section rounded-10 p-12 p-sm-20 h-100">
                        <div class="row g-2">
                            <div class="col-12">
                                <div class="d-flex gap-3 align-items-center bg-white rounded-10 p-2 overflow-wrap-anywhere">
                                    <div class="flex-shrink-0 aspect-1 border rounded-circle w-60px d-grid place-items-center bg-white">
                                        <img width="30" src="{{dynamicAsset(path: 'public/assets/back-end/img/packaging-new.png')}}" alt="" class="aspect-1">
                                    </div>
                                    <div>
                                        <h2 class="fs-26 fw-bold mb-2 text--primary">{{ $product_count['reject_product_count']+$product_count['active_product_count']+$product_count['pending_product_count'] }}</h2>
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
                                    <span data-toggle="tooltip" data-placement="top"
                                        title="{{translate('product_wise_discounted_amount_will_be_shown_here')}}">
                                        <img class="info-img" src="{{dynamicAsset(path: 'public/assets/back-end/img/info-circle.svg')}}"
                                            alt="{{translate('image')}}">
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @include('layouts.vendor.partials._apexcharts',['title'=>'product_Statistics','statisticsValue'=>$chart_data['total_product'],'label'=> $chartDataTotalProductLabel,'statisticsTitle'=>'total_product','getCurrency'=>false])

        <x-k.data-view class="mt-3" :title="translate('products')" :count="$products->total()"
                       searchName="search" :searchValue="$search"
                       :searchPlaceholder="translate('search_Product_Name')">

            <x-slot:actions>
                <a class="k-btn k-btn--secondary"
                   href="{{ route('vendor.report.all-product-excel', ['search' => request('search'), 'date_type' => request('date_type'), 'from' => request('from'), 'to' => request('to')]) }}">
                    <x-k.icon name="download" :size="15" /> {{ translate('export') }}
                </a>
            </x-slot:actions>

            <table class="k-table">
                <thead>
                <tr>
                    <th>{{translate('SL')}}</th>
                    <th>{{translate('product_Name')}}</th>
                    <th class="k-table__num">{{translate('product_Unit_Price')}}</th>
                    <th class="k-table__num">{{translate('total_Amount_Sold')}}</th>
                    <th class="k-table__num">{{translate('total_Quantity_Sold')}}</th>
                    <th class="k-table__num">{{translate('average_Product_Value')}}</th>
                    <th class="k-table__num">{{translate('current_Stock_Amount')}}</th>
                    <th class="k-table__num">{{translate('average_Ratings')}}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($products as $key=>$product)
                    <tr>
                        <td><span class="k-num">{{ $products->firstItem()+$key }}</span></td>
                        <td>
                            <a href="{{route('vendor.products.view',[$product['id']])}}"
                               class="title-color k-truncate" style="display:block;max-inline-size:220px"
                               title="{{ $product->name }}">
                                {{ \Illuminate\Support\Str::limit($product->name, 20) }}
                            </a>
                        </td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $product->unit_price), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: isset($product->orderDetails[0]->total_sold_amount) ? $product->orderDetails[0]->total_sold_amount : 0), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num">
                            <span class="k-num">{{ isset($product->orderDetails[0]->product_quantity) ? $product->orderDetails[0]->product_quantity : 0 }}</span>
                        </td>
                        <td class="k-table__num">
                            <span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: (
                                    isset($product->orderDetails[0]->total_sold_amount) ? $product->orderDetails[0]->total_sold_amount : 0) /
                                    (isset($product->orderDetails[0]->product_quantity) ? $product->orderDetails[0]->product_quantity : 1)
                                ), currencyCode: getCurrencyCode()) }}</span>
                        </td>
                        <td class="k-table__num">
                            @if ($product->product_type == 'digital')
                                {{ $product->status==1 ? translate('available') : translate('not_available') }}
                            @else
                                <span class="k-num">{{ $product->current_stock }}</span>
                            @endif
                        </td>
                        <td class="k-table__num">
                            <span class="k-num d-inline-flex align-items-center gap-1">
                                <i class="fi fi-sr-star text-warning-dark fs-12"></i>
                                {{count($product->rating)>0?number_format($product->rating[0]->average, 2, '.', ' '):0}}
                                <span class="k-text-subtle">({{$product->reviews->count()}})</span>
                            </span>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if(count($products)==0)
                <x-k.empty icon="catalog" :title="translate('no_product_found')" />
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
@endsection

@push('script')
    <script src="{{dynamicAsset(path: 'public/assets/back-end/js/apexcharts.js')}}"></script>
    <script src="{{dynamicAsset(path: 'public/assets/back-end/js/apexcharts-data-show.js')}}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/vendor/product-report.js') }}"></script>
@endpush
