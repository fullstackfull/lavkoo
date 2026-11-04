@extends('layouts.admin.app')

@section('title', translate('stock_limit_products'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 d-flex flex-column gap-1">
            <h2 class="h1 text-capitalize d-flex gap-2 align-items-center">
                <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/inhouse-product-list.png') }}" class="mb-1 me-1" alt="">
                {{ translate('limited_Stocked_Products_List') }}
                <span class="badge text-dark bg-body-secondary fw-semibold rounded-50">
                    {{ $products->total() }}
                </span>
            </h2>
            <p class="d-flex mb-0">
                {{ translate('the_products_are_shown_in_this_list,_which_quantity_is_below') }} {{ $stockLimit }}
            </p>
        </div>
        <div class="row mt-30">
            <div class="col-md-12">
                <x-k.data-view :title="translate('limited_Stocked_Products_List')" :count="$products->total()"
                               searchName="searchValue" :searchValue="$searchValue"
                               :searchPlaceholder="translate('search_by_Product_Name')">

                    <x-slot:actions>
                        <div class="select-wrapper">
                            <select name="qty_order_sort" class="form-select action-select-onchange-get-view"
                                    data-url-prefix="{{ route('admin.products.stock-limit-list',['in_house', '']) }}/?searchValue={{ urlencode((string) $searchValue) }}&sortOrderQty=">
                                <option value="default" {{ $sortOrderQty== "default"?'selected':''}}>
                                    {{ translate('default') }}
                                </option>
                                <option value="quantity_asc" {{ $sortOrderQty== "quantity_asc"?'selected':''}}>
                                    {{ translate('inventory_quantity(low_to_high)') }}
                                </option>
                                <option value="quantity_desc" {{ $sortOrderQty== "quantity_desc"?'selected':''}}>
                                    {{ translate('inventory_quantity(high_to_low)') }}
                                </option>
                                <option value="order_asc" {{ $sortOrderQty== "order_asc"?'selected':''}}>
                                    {{ translate('order_volume(low_to_high)') }}
                                </option>
                                <option value="order_desc" {{ $sortOrderQty== "order_desc"?'selected':''}}>
                                    {{ translate('order_volume(high_to_low)') }}
                                </option>
                            </select>
                        </div>
                    </x-slot:actions>

                    <table class="k-table">
                        <thead>
                        <tr>
                            <th>{{ translate('SL') }}</th>
                            <th>{{ translate('product_Name') }}</th>
                            <th class="k-table__num">{{ translate('unit_price') }}</th>
                            <th class="k-table__num">{{ translate('quantity') }}</th>
                            <th class="k-table__num">{{ translate('orders') }}</th>
                            <th>{{ translate('active_status') }}</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($products as $key=>$product)
                            <tr>
                                <td><span class="k-num">{{ $products->firstItem()+$key}}</span></td>
                                <td>
                                    <a href="{{route('admin.products.view',['addedBy'=>($product['added_by']=='seller'?'vendor' : 'in-house'),'id'=>$product['id']]) }}" class="k-row">
                                        <img src="{{ getStorageImages(path:$product->thumbnail_full_url,type: 'backend-product')}}"
                                             alt="" width="40" height="40"
                                             style="border-radius:8px;object-fit:cover;flex:0 0 auto;border:1px solid var(--k-border)">
                                        <span class="k-truncate text-dark" style="display:block;max-inline-size:200px" title="{{ $product['name'] }}">
                                            {{ Str::limit($product['name'], 20) }}
                                        </span>
                                    </a>
                                </td>
                                <td class="k-table__num">
                                    <span class="k-num">{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $product['unit_price']), currencyCode: getCurrencyCode()) }}</span>
                                </td>
                                <td class="k-table__num">
                                    <div class="d-flex align-items-center gap-1 product-quantity justify-content-end">
                                        <span class="k-num lh-1">{{ $product['current_stock'] }}</span>

                                        <button class="btn p-0 border-0 fs-18 action-update-product-quantity"
                                                id="{{ $product['id'] }}"
                                                data-url="{{ route('admin.products.get-variations').'?id='.$product['id'] }}"
                                                type="button"
                                                data-bs-target="#update-quantity"
                                                data-bs-toggle="modal"
                                                title="{{ translate('update_quantity') }}">
                                                <i class="fi fi-sr-add text-primary"></i>
                                        </button>
                                    </div>
                                </td>
                                <td class="k-table__num"><span class="k-num">{{ ($product['order_details_count'])}}</span></td>

                                <td>
                                    @if($product->request_status != 2 )
                                        <form action="{{route('admin.products.status-update') }}" method="post"
                                            id="product-status{{ $product['id'] }}-form"
                                            class="no-reload-form">
                                            @csrf
                                            <input type="hidden" name="id" value="{{ $product['id']}}">
                                            <label class="switcher" for="product-status{{ $product['id'] }}">
                                                <input
                                                    class="switcher_input custom-modal-plugin"
                                                    type="checkbox" value="1" name="status"
                                                    id="product-status{{ $product['id'] }}"
                                                    {{ $product['status'] == 1 ? 'checked' : '' }}
                                                    data-modal-type="input-change-form"
                                                    data-modal-form="#product-status{{ $product['id'] }}-form"
                                                    data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/product-status-on.png') }}"
                                                    data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/product-status-off.png') }}"
                                                    data-on-title="{{ translate('Want_to_Turn_ON').' '.$product['name'].' '.translate('status') }}"
                                                    data-off-title="{{ translate('Want_to_Turn_OFF').' '.$product['name'].' '.translate('status') }}"
                                                    data-on-message="<p>{{ translate('if_enabled_this_product_will_be_available_on_the_website_and_customer_app') }}</p>"
                                                    data-off-message="<p>{{ translate('if_disabled_this_product_will_be_hidden_from_the_website_and_customer_app') }}</p>"
                                                    data-on-button-text="{{ translate('turn_on') }}"
                                                    data-off-button-text="{{ translate('turn_off') }}">
                                                <span class="switcher_control"></span>
                                            </label>
                                        </form>
                                    @endif
                                </td>
                                <td>
                                    <div class="k-table__actions">
                                        <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon"
                                        title="{{ translate('barcode') }}"
                                        href="{{ route('admin.products.barcode', [$product['id']]) }}">
                                            <i class="fi fi-sr-barcode"></i>
                                        </a>
                                        <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon"
                                        title="{{ translate('edit') }}"
                                        href="{{route('admin.products.update',[$product['id']]) }}">
                                            <x-k.icon name="edit" :size="15" />
                                        </a>
                                        <span class="k-btn k-btn--ghost k-btn--sm k-btn--icon delete-data" role="button"
                                            title="{{ translate('delete') }}"
                                            data-id="product-{{ $product['id']}}">
                                            <x-k.icon name="trash" :size="15" />
                                        </span>
                                    </div>
                                    <form action="{{ route('admin.products.delete', [$product['id']]) }}"
                                        method="post" id="product-{{ $product['id']}}">
                                        @csrf @method('delete')
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>

                    @if(count($products)==0)
                        <x-k.empty icon="catalog" :title="translate('no_product_found')"
                                   :text="$searchValue ? translate('no_product_matches_your_search') : null" />
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

    <div class="modal fade update-stock-modal" id="update-quantity" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form action="{{ route('admin.products.update-quantity') }}" method="post" class="modal-body p-20">
                    @csrf
                    <div class="rest-part-content"></div>
                    <div class="d-flex justify-content-end gap-10 flex-wrap align-items-center">
                        <button type="button" class="btn btn-danger px-4" data-bs-dismiss="modal" aria-label="Close">
                            {{ translate('close') }}
                        </button>
                        <button class="btn btn-primary px-4" type="submit">
                            {{ translate('submit') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
