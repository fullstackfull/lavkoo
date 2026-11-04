@extends('layouts.admin.app')
@section('title', translate('wish_listed_products'))
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

        <div class="card mb-3">
            <div class="card-body">
                <form action="" id="form-data" method="GET">
                    <h3 class="mb-3">{{translate('filter_Data')}}</h3>
                    <div class="row g-3 align-items-end">
                        <div class="col-sm-6 col-md-4">
                            <select class="custom-select text-ellipsis" name="seller_id">
                                <option value="all" {{ $seller_id == 'all' ? 'selected' : '' }}>{{translate('all')}}</option>
                                <option value="in_house" {{ $seller_id == 'in_house' ? 'selected' : '' }}>{{translate('in-House')}}</option>
                                @foreach($sellers as $seller)
                                    <option value="{{ $seller['id'] }}" {{ $seller_id == $seller['id'] ? 'selected' : '' }}>
                                        {{ $seller?->shop?->name ?? translate('shop_not_found') }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <div class="select-wrapper">
                                <select class="form-select" name="sort">
                                    <option value="DESC" {{ $sort == 'DESC' ? 'selected' : '' }}>{{translate('wishlist_sort_by_(high_to_low)')}}</option>
                                    <option value="ASC" {{ $sort == 'ASC' ? 'selected' : '' }}>{{translate('wishlist_sort_by_(low_to_high)')}}</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4 text-end text-md-left">
                            <button type="submit" class="btn btn-primary btn-block">
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
                   href="{{route('admin.stock.wishlist-product-export', ['seller_id'=>$seller_id, 'sort'=>$sort, 'search'=>$search])}}">
                    <x-k.icon name="download" :size="15" /> {{ translate('export') }}
                </a>
            </x-slot:actions>

            <table class="k-table">
                <thead>
                <tr>
                    <th>{{ translate('product_Name') }}</th>
                    <th>{{ translate('added_date') }}</th>
                    <th class="k-table__num">{{ translate('total_in_Wishlist') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($products as $data)
                    <tr>
                        <td>
                            <a href="{{route('admin.products.view',['addedBy'=>($data['added_by'] =='seller'?'vendor' : 'in-house'),'id'=>$data['id']])}}"
                               class="k-truncate" style="display:block;max-inline-size:300px" title="{{ $data['name'] }}">
                                {{ $data['name'] }}
                            </a>
                        </td>
                        <td><span class="k-num">{{ date('d M Y', $data['created_at'] ? strtotime($data['created_at']) : null) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ $data->wish_list_count }}</span></td>
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
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/new/back-end/js/admin/product-report.js') }}"></script>
@endpush

