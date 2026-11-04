@extends('layouts.vendor.app')
@section('title', translate('product_Report'))
@push('css_or_js')
    <meta name="csrf-token" content="{{ csrf_token() }}">
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex gap-2 align-items-center">
                <img width="20" src="{{dynamicAsset(path: 'public/assets/back-end/img/seller_sale.png')}}" alt="">
                {{translate('product_report')}}
            </h2>
        </div>

        @include('vendor-views.report.product-report-inline-menu')

        <div class="card mb-2">
            <div class="card-body">
                <form action="" id="form-data" method="GET">
                    <h4 class="mb-3">{{translate('filter_Data')}}</h4>
                    <div class="row gx-2 gy-3 align-items-center text-start">
                        <div class="col-sm-6 col-md-4">
                            <select class="js-select2-custom form-control __form-control" name="category_id"
                                    id="cat_id">
                                <option value="all" {{ $category_id == 'all' ? 'selected' : '' }}>{{translate('all_category')}}</option>
                                @foreach($categories as $category)
                                    <option value="{{$category['id']}}" {{ $category_id == $category['id'] ? 'selected' : '' }}>{{$category['name']}}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <div class="">
                                <select
                                        class="form-control"
                                        name="sort">
                                    <option value="ASC" {{ $sort == 'ASC' ? 'selected' : '' }}>{{translate('stock_sort_by_(low_to_high)')}}</option>
                                    <option value="DESC" {{ $sort == 'DESC' ? 'selected' : '' }}>{{translate('stock_sort_by_(high_to_low)')}}</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-2">
                            <button type="submit" class="btn btn--primary px-4 px-md-5">
                                {{translate('filter')}}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <x-k.data-view :title="translate('total_Products')" :count="$products->total()"
                       searchName="search" :searchValue="$search"
                       :searchPlaceholder="translate('search_Product_Name')">

            <x-slot:actions>
                <a class="k-btn k-btn--secondary"
                   href="{{ route('vendor.report.product-stock-export', ['category_id' => request('category_id'), 'sort' => request('sort'), 'search' => request('search')]) }}">
                    <x-k.icon name="download" :size="15" /> {{ translate('export') }}
                </a>
            </x-slot:actions>

            <table class="k-table">
                <thead>
                <tr>
                    <th>{{translate('SL')}}</th>
                    <th>{{translate('product_Name')}}</th>
                    <th>{{translate('last_Updated_Stock')}}</th>
                    <th class="k-table__num">{{translate('current_Stock')}}</th>
                    <th>{{translate('status')}}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($products as $key=>$data)
                    <tr>
                        <td><span class="k-num">{{$products->firstItem()+$key}}</span></td>
                        <td>
                            <a href="{{route('vendor.products.view',[$data['id']])}}"
                               class="title-color k-truncate" style="display:block;max-inline-size:280px"
                               title="{{ $data['name'] }}">
                                {{ $data['name'] }}
                            </a>
                        </td>
                        <td><span class="k-num">{{ date('d M Y', $data['updated_at'] ? strtotime($data['updated_at']) : null) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{$data['current_stock']}}</span></td>
                        <td>
                            @if($data['current_stock'] >= $stockLimit)
                                <x-k.badge tone="success">{{translate('In-Stock')}}</x-k.badge>
                            @elseif($data['current_stock']  == 0)
                                <x-k.badge tone="danger">{{translate('out_of_Stock')}}</x-k.badge>
                            @elseif($data['current_stock'] < $stockLimit)
                                <x-k.badge tone="warning">{{translate('soon_Stock_Out')}}</x-k.badge>
                            @endif
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
