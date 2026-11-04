@extends('layouts.vendor.app')

@section('title', translate('stock_limit_products'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 d-flex flex-column gap-1">
            <h2 class="h1 text-capitalize d-flex gap-2 align-items-center">
                <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/inhouse-product-list.png') }}" class="mb-1 me-1" alt="">
                {{ translate('limited_Stocked_Products_List') }}
                <span class="badge badge-soft-dark radius-50 fz-14 ms-1">
                    {{ $products->total() }}
                </span>
            </h2>
            <p class="d-flex">
                {{ translate('the_products_are_shown_in_this_list,_which_quantity_is_below') }} {{ $stockLimit }}
            </p>
        </div>
        <div class="row mt-30">
            <div class="col-md-12">
                <x-k.data-view :title="translate('limited_Stocked_Products_List')" :count="$products->total()"
                               searchName="searchValue" :searchValue="$searchValue"
                               :searchPlaceholder="translate('search_by_Product_Name')">

                    <x-slot:actions>
                        <select name="qty_order_sort" class="k-input action-select-onchange-get-view" style="inline-size:auto"
                                data-url-prefix="{{ route('vendor.products.stock-limit-list') }}/?sortOrderQty="
                                aria-label="{{ translate('sort') }}">
                            <option value="default" {{ $sortOrderQty== "default"?'selected':''}}>
                                {{ translate('default') }}
                            </option>
                            <option value="quantity_asc" {{ $sortOrderQty== "quantity_asc"?'selected':''}}>
                                {{ translate('inventory_quantity_(low_to_high)') }}
                            </option>
                            <option value="quantity_desc" {{ $sortOrderQty== "quantity_desc"?'selected':''}}>
                                {{ translate('inventory_quantity_(high_to_low)') }}
                            </option>
                            <option value="order_asc" {{ $sortOrderQty== "order_asc"?'selected':''}}>
                                {{ translate('order_volume_(low_to_high)') }}
                            </option>
                            <option value="order_desc" {{ $sortOrderQty== "order_desc"?'selected':''}}>
                                {{ translate('order_volume_(high_to_low)') }}
                            </option>
                        </select>
                    </x-slot:actions>

                    <table class="k-table">
                        <thead>
                        <tr>
                            <th>{{ translate('SL') }}</th>
                            <th>{{ translate('product_Name') }}</th>
                            <th class="k-table__num">{{ translate('unit_price') }}</th>
                            <th>{{ translate('verify_status') }}</th>
                            <th class="k-table__num">{{ translate('quantity') }}</th>
                            <th class="k-table__num">{{ translate('orders') }}</th>
                            <th>{{ translate('active_Status') }}</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($products as $key => $product)
                            <tr>
                                <td><span class="k-num">{{ $products->firstItem()+$key}}</span></td>
                                <td>
                                    <a href="{{route('vendor.products.view',[$product['id']]) }}" class="k-row">
                                        <img src="{{ getStorageImages(path:$product->thumbnail_full_url,type: 'backend-product')}}"
                                             data-onerror="{{ dynamicAsset(path: 'public/assets/back-end/img/brand-logo.png') }}"
                                             alt="" width="40" height="40"
                                             style="border-radius:8px;object-fit:cover;flex:0 0 auto;border:1px solid var(--k-border)">
                                        <span class="k-truncate title-color" style="max-inline-size:200px" title="{{ $product['name'] }}">
                                            {{ Str::limit($product['name'], 20) }}
                                        </span>
                                    </a>
                                </td>
                                <td class="k-table__num">
                                    <span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $product['unit_price']), currencyCode: getCurrencyCode()) }}</span>
                                </td>
                                <td>
                                    @if($product->request_status == 0)
                                        <x-k.badge tone="warning">{{ translate('new_Request') }}</x-k.badge>
                                    @elseif($product->request_status == 1)
                                        <x-k.badge tone="success">{{ translate('approved') }}</x-k.badge>
                                    @elseif($product->request_status == 2)
                                        <x-k.badge tone="danger">{{ translate('denied') }}</x-k.badge>
                                    @endif
                                </td>
                                <td class="k-table__num">
                                    <span class="k-num">{{ $product['current_stock']}}</span>
                                    <button class="k-btn k-btn--ghost k-btn--sm k-btn--icon action-update-product-quantity"
                                            id="{{ $product['id'] }}"
                                            data-url="{{ route('vendor.products.get-variations').'?id='.$product['id'] }}"
                                            type="button" data-target="#update-quantity"
                                            title="{{ translate('update_quantity') }}">
                                        <x-k.icon name="plus" :size="15" />
                                    </button>
                                </td>
                                <td class="k-table__num">
                                    <span class="k-num">{{ $product['order_details_count']}}</span>
                                </td>
                                <td>
                                    <form action="{{route('vendor.products.status-update') }}" method="post"
                                          id="product-status{{ $product['id']}}-form"
                                          class="admin-product-status-form">
                                        @csrf
                                        <input type="hidden" name="id" value="{{ $product['id']}}">
                                        <label class="switcher mx-auto">
                                            <input type="checkbox" class="switcher_input toggle-switch-message"
                                                   name="status"
                                                   id="product-status{{ $product['id'] }}" value="1"
                                                   {{ $product['status'] == 1 ? 'checked' : '' }}
                                                   data-modal-id="toggle-status-modal"
                                                   data-toggle-id="product-status{{ $product['id'] }}"
                                                   data-on-image="product-status-on.png"
                                                   data-off-image="product-status-off.png"
                                                   data-on-title="{{ translate('Want_to_Turn_ON').' '.$product['name'].' '.translate('status') }}"
                                                   data-off-title="{{ translate('Want_to_Turn_OFF').' '.$product['name'].' '.translate('status') }}"
                                                   data-on-message="<p>{{ translate('if_enabled_this_product_will_be_available_on_the_website_and_customer_app') }}</p>"
                                                   data-off-message="<p>{{ translate('if_disabled_this_product_will_be_hidden_from_the_website_and_customer_app') }}</p>">
                                            <span class="switcher_control"></span>
                                        </label>
                                    </form>
                                </td>
                                <td>
                                    <div class="k-table__actions">
                                        <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon"
                                           title="{{ translate('barcode') }}"
                                           href="{{ route('vendor.products.barcode', [$product['id']]) }}">
                                            <x-k.icon name="grip" :size="15" />
                                        </a>
                                        <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon"
                                           title="{{ translate('edit') }}"
                                           href="{{route('vendor.products.update',[$product['id']]) }}">
                                            <x-k.icon name="edit" :size="15" />
                                        </a>
                                        <span class="k-btn k-btn--ghost k-btn--sm k-btn--icon delete-data" role="button"
                                              title="{{ translate('delete') }}"
                                              data-id="product-{{ $product['id']}}">
                                            <x-k.icon name="trash" :size="15" />
                                        </span>
                                    </div>
                                    <form action="{{ route('vendor.products.delete', [$product['id']]) }}"
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
                <div class="modal-body">
                    <form action="{{ route('vendor.products.update-quantity') }}" method="post">
                        @csrf
                        <div class="rest-part-content"></div>
                        <div class="d-flex justify-content-end gap-10 flex-wrap align-items-center">
                            <button type="button" class="btn btn-danger px-4" data-dismiss="modal" aria-label="Close">
                                {{ translate('close') }}
                            </button>
                            <button class="btn btn--primary" class="btn btn--primary px-4" type="submit">
                                {{ translate('submit') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
