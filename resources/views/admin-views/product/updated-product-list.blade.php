@extends('layouts.admin.app')

@section('title', translate('updated_product_list'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 text-capitalize mb-1 d-flex gap-2 align-items-center">
                <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/inhouse-product-list.png') }}" alt="">
                {{ translate('update_product') }}
            </h2>
        </div>

        <div class="row mt-20">
            <div class="col-md-12">
                <x-k.data-view :title="translate('product_table')" :count="$products->total()"
                               searchName="searchValue" :searchValue="$searchValue"
                               :searchPlaceholder="translate('search_Product_Name')">

                    <x-slot:actions>
                        @php($updatedFilterActive = !empty(request('filter_sort_by')) || !empty(request('filter_product_types')) || !empty(request('product_status')) || !empty(request('filter_shop_ids')) || !empty(request('filter_brand_ids')) || !empty(request('filter_category_ids')))
                        <button type="button" class="k-btn {{ $updatedFilterActive ? 'k-btn--primary' : 'k-btn--secondary' }}"
                                data-bs-toggle="offcanvas" data-bs-target="#offcanvasProductFilter">
                            <x-k.icon name="filter" :size="15" /> {{ translate('Filter') }}
                        </button>
                    </x-slot:actions>

                    <table class="k-table">
                        <thead>
                        <tr>
                            <th>{{ translate('SL') }}</th>
                            <th>{{ translate('product Name') }}</th>
                            <th class="k-table__num">{{ translate('previous_shipping_cost') }}</th>
                            <th class="k-table__num">{{ translate('new_shipping_cost') }}</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($products as $key=>$product)
                            <tr>
                                <td><span class="k-num">{{ $products->firstItem()+$key}}</span></td>
                                <td>
                                    <a href="{{route('admin.products.view',['addedBy'=>($product['added_by']=='seller'?'vendor' : 'in-house'),'id'=>$product['id']]) }}"
                                       class="text-dark text-hover-primary">
                                        {{ Str::limit($product['name'],20) }}
                                    </a>
                                </td>
                                <td class="k-table__num">
                                    <span class="k-num">{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $product['shipping_cost']), currencyCode: getCurrencyCode()) }}</span>
                                </td>
                                <td class="k-table__num">
                                    <span class="k-num">{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $product['temp_shipping_cost']), currencyCode: getCurrencyCode()) }}</span>
                                </td>
                                <td>
                                    <div class="k-table__actions">
                                        <button class="k-btn k-btn--primary k-btn--sm update-status"
                                                data-id="{{ $product['id'] }}"
                                                data-message ="{{translate('want_to_approve_this_update_request').'?'}}"
                                                data-status="1">
                                            {{ translate('approved') }}
                                        </button>
                                        <button class="k-btn k-btn--secondary k-btn--sm update-status"
                                                data-id="{{ $product['id'] }}"
                                                data-message ="{{translate('want_to_deny_this_update_request').'?'}}"
                                                data-status="2">
                                            {{ translate('denied') }}
                                        </button>
                                    </div>
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
        </div>
    </div>
<span id="get-update-status-route" data-action="{{ route('admin.products.updated-shipping') }}"></span>
    @include('admin-views.product.partials.offcanvas._filter-offcanvas')
@endsection
