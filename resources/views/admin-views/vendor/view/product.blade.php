@extends('layouts.admin.app')

@section('title',$seller?->shop->name ?? translate("shop_name_not_found"))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img src="{{dynamicAsset(path: 'public/assets/back-end/img/add-new-seller.png')}}" alt="">
                {{translate('vendor_details')}}
            </h2>
        </div>

        <div class="flex-between d-sm-flex row align-items-center justify-content-between mb-2 mx-1">
            <div>
                @if ($seller->status=="pending")
                    <div class="mt-4">
                        <div class="flex-start">
                            <div class="mx-1"><h4><i class="fi fi-rr-shop"></i></h4></div>
                            <div>{{translate('vendor_request_for_open_a_shop')}}</div>
                        </div>
                        <div class="text-center">
                            <form class="d-inline-block" action="{{route('admin.vendors.updateStatus')}}" method="POST">
                                @csrf
                                <input type="hidden" name="id" value="{{$seller->id}}">
                                <input type="hidden" name="status" value="approved">
                                <button type="submit" class="btn btn-primary btn-sm">{{translate('approve')}}</button>
                            </form>
                            <form class="d-inline-block" action="{{route('admin.vendors.updateStatus')}}" method="POST">
                                @csrf
                                <input type="hidden" name="id" value="{{$seller->id}}">
                                <input type="hidden" name="status" value="rejected">
                                <button type="submit" class="btn btn-danger btn-sm">{{translate('reject')}}</button>
                            </form>
                        </div>
                    </div>
                @endif
            </div>
        </div>
        <div class="page-header">
            <div class="flex-between row mx-1">
                <div>
                    <h2 class="page-header-title mb-3">{{ $seller?->shop->name ?? translate("shop_Name")." : ".translate("update_Please") }}</h2>
                </div>
            </div>

            <div class="position-relative nav--tab-wrapper">
                <ul class="nav nav-pills nav--tab">
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('admin.vendors.view',$seller->id) }}">{{translate('shop')}}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('admin.vendors.view',['id'=>$seller->id, 'tab'=>'order']) }}">{{translate('order')}}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="{{ route('admin.vendors.view',['id'=>$seller->id, 'tab'=>'product']) }}">{{translate('product')}}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('admin.vendors.view',['id'=>$seller['id'], 'tab'=>'clearance_sale']) }}">{{translate('clearance_sale_products')}}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('admin.vendors.view',['id'=>$seller->id, 'tab'=>'setting']) }}">{{translate('setting')}}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('admin.vendors.view',['id'=>$seller->id, 'tab'=>'transaction']) }}">{{translate('transaction')}}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('admin.vendors.view',['id'=>$seller->id, 'tab'=>'review']) }}">{{translate('review')}}</a>
                    </li>
                </ul>
                <div class="nav--tab__prev">
                    <button type="button" class="btn btn-circle border-0 bg-white text-primary">
                        <i class="fi fi-sr-angle-left"></i>
                    </button>
                </div>
                <div class="nav--tab__next">
                    <button type="button" class="btn btn-circle border-0 bg-white text-primary">
                        <i class="fi fi-sr-angle-right"></i>
                    </button>
                </div>
            </div>
        </div>

        <div class="card mt-3">
            <div class="px-3 py-4">
                <div class="d-flex justify-content-end flex-wrap gap-3 align-items-center mb-4">
                    <h3 class="mb-0 text-capitalize flex-grow-1">
                        {{ translate('products') }}
                        <span class="badge badge-info text-bg-info">{{$products->total()}}</span>
                    </h3>
                </div>

                <div class="k-table-wrap">
                    <table id="columnSearchDatatable" class="k-table">
                        <thead>
                            <tr>
                                <th>{{translate('SL')}}</th>
                                <th>{{translate('product Name')}}</th>
                                <th>{{translate('product_Type')}}</th>
                                <th class="k-table__num">{{translate('unit_price')}}</th>
                                <th class="k-table__num">{{ translate('stock') }}</th>
                                @if ($productWiseTax)
                                    <th class="text-center">{{ translate('Vat/Tax') }}</th>
                                @endif
                                <th class="text-center">
                                    <div class="d-flex justify-content-center">
                                        <div>
                                            <div class="d-flex gap-2">
                                                <span>{{ translate('show_as_featured') }} </span>
                                                <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                      data-bs-placement="top"
                                                      aria-label="{{ translate('Highlight_this_product_on_the_homepage_by_marking_it_as_featured') }}"
                                                      data-bs-title="{{ translate('Highlight_this_product_on_the_homepage_by_marking_it_as_featured') }}">
                                                        <i class="fi fi-sr-info"></i>
                                                    </span>
                                            </div>
                                            <div>{{ translate('on_homepage') }}</div>
                                        </div>
                                    </div>
                                </th>
                                <th class="text-center">{{translate('status')}}</th>
                                <th class="text-center">{{translate('action')}}</th>
                            </tr>
                        </thead>

                        <tbody id="set-rows">
                        @foreach($products as $k=>$product)
                            <tr>
                                <td><span class="k-num">{{$products->firstItem()+$k}}</span></td>
                                <td>
                                    <a href="{{ route('admin.products.view', ['addedBy' => ($product['added_by'] == 'seller' ? 'vendor' : 'in-house'), 'id' => $product['id']]) }}"
                                    class="text-dark text-hover-primary gap-3 d-flex align-items-center">
                                        <img width="42" src="{{ getStorageImages(path: $product->thumbnail_full_url, type: 'backend-basic') }}"
                                            class="aspect-1 border object-fit-cover rounded" alt="">
                                        <div>
                                            <div class="d-flex align-items-center gap-1 text-wrap line-1 max-w-300 min-w-130 text-dark text-hover-primary">
                                                <div class="media-body line-1" data-bs-toggle="tooltip" title="{{ $product['name'] }}">
                                                    {{ Str::limit($product['name'], 20) }}
                                                </div>
                                                @if($product?->clearanceSale)
                                                    <span class="text-secondary" data-bs-toggle="tooltip" title="{{ translate('Clearance_Sale') }}">
                                                        <i class="fi fi-sr-bahai"></i>
                                                    </span>
                                                @endif
                                            </div>
                                            <div class="d-flex gap-2 align-items-center lh-1 mt-2">
                                                <div class="text-body">
                                                    {{ translate('Id') }} # {{$product['id']}}
                                                    @if($product->request_status == 0)
                                                        <x-k.badge tone="warning">{{translate('pending')}}</x-k.badge>
                                                    @elseif($product->request_status == 1)
                                                        <x-k.badge tone="success">{{translate('approved')}}</x-k.badge>
                                                    @elseif($product->request_status == 2)
                                                        <x-k.badge tone="danger">{{translate('denied')}}</x-k.badge>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </a>
                                </td>
                                <td class="text-center">
                                    {{translate(str_replace('_',' ',$product['product_type']))}}
                                </td>
                                <td class="k-table__num">
                                    <span class="k-num">{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $product['unit_price']))}}</span>
                                </td>
                                <td>
                                    <div class="d-flex justify-content-center gap-3 align-items-center lh-1">
                                        @if ($product['product_type'] === 'physical')
                                        <span>{{ $product->current_stock }}</span>
                                            @if ($product->current_stock <= 0)
                                                <span class="text-danger-dark fs-18"
                                                        data-bs-toggle="tooltip"
                                                        title="{{ translate('Out_of_Stock') }}">
                                                    <i class="fi fi-sr-exclamation"></i>
                                                </span>
                                            @elseif ($product->current_stock <= 20)
                                                <span class="text-warning-dark fs-18"
                                                        data-bs-toggle="tooltip"
                                                        title="{{ translate('Low_Stock') }}">
                                                <i class="fi fi-sr-exclamation"></i>
                                            </span>
                                            @endif
                                        @else
                                            <span>-</span>
                                        @endif
                                    </div>
                                </td>
                                @if ($productWiseTax)
                                    <td class="text-center">
                                        <span class="">
                                            @forelse ($product?->taxVats as $key => $taxVat)
                                                <span>{{ $taxVat?->tax?->name }} :
                                                    <span class="font-bold">
                                                        ({{ $taxVat?->tax?->tax_rate }}%)
                                                    </span>
                                                </span>
                                                <br>
                                            @empty
                                                <span>{{ translate('N/A') }}</span>
                                            @endforelse
                                        </span>
                                    </td>
                                @endif
                                <td class="text-center">
                                    @php
                                        $product_name = str_replace("'", '`', $product['name']);
                                        $isDisabled = $product['request_status'] !== 1;
                                    @endphp
                                    <div  @if($isDisabled) data-bs-toggle="tooltip" title="{{ translate('Only approved products can be featured') }}" @endif>
                                        <input type="checkbox"
                                               class="product-status-checkbox form-check-input checkbox--input checkbox--input_lg"
                                               data-id="{{ $product['id'] }}"
                                               data-action="{{ route('admin.products.featured-status') }}"
                                            {{ $product['featured'] == 1 ? 'checked' : '' }}
                                            {{ $isDisabled ? 'disabled' : '' }}>
                                    </div>
                                </td>
                                <td>
                                    <form action="{{route('admin.products.status-update')}}" method="post"
                                        id="product-status{{$product['id']}}-form" class="status-update-form reload-true">
                                        @csrf
                                        <input type="hidden" name="id" value="{{$product['id']}}">
                                        <label class="switcher mx-auto" for="product-status{{$product['id']}}">
                                            <input
                                                class="switcher_input custom-modal-plugin"
                                                type="checkbox" value="1" name="status"
                                                id="product-status{{$product['id']}}"
                                                {{ $product['status'] == 1 ? 'checked':'' }}
                                                data-modal-type="input-change-form"
                                                data-modal-form="#product-status{{$product['id']}}-form"
                                                data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/product-status-on.png') }}"
                                                data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/product-status-off.png') }}"
                                                data-on-title = "{{translate('Want_to_Turn_ON').' '.$product_name.' '.translate('status').'?'}}"
                                                data-off-title = "{{translate('Want_to_Turn_OFF').' '.$product_name.' '.translate('status').'?'}}"
                                                data-on-message = "<p>{{translate('if_enabled_this_product_will_be_available_on_the_website_and_customer_app')}}</p>"
                                                data-off-message = "<p>{{translate('if_disabled_this_product_will_be_hidden_from_the_website_and_customer_app')}}</p>"
                                                data-on-button-text="{{ translate('turn_on') }}"
                                                data-off-button-text="{{ translate('turn_off') }}">
                                            <span class="switcher_control"></span>
                                        </label>
                                    </form>
                                </td>
                                <td>
                                    <div class="k-table__actions">
                                        <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon" title="{{ translate('barcode') }}" href="{{ route('admin.products.barcode', [$product['id']]) }}">
                                            <x-k.icon name="grip" :size="15" />
                                        </a>
                                        <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon" title="{{ translate('view') }}" href="{{route('admin.products.view',['addedBy'=>($product['added_by']=='seller'?'vendor' : 'in-house'),'id'=>$product['id']])}}">
                                            <x-k.icon name="eye" :size="15" />
                                        </a>
                                        <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon" title="{{ translate('edit') }}" href="{{route('admin.products.update',[$product['id']])}}">
                                            <x-k.icon name="edit" :size="15" />
                                        </a>
                                        <span class="k-btn k-btn--ghost k-btn--sm k-btn--icon delete-data" role="button" title="{{ translate('delete') }}" data-id="product-{{$product['id']}}">
                                            <x-k.icon name="trash" :size="15" />
                                        </span>
                                    </div>
                                    <form action="{{route('admin.products.delete',[$product['id']])}}"
                                        method="post" id="product-{{$product['id']}}">
                                        @csrf @method('delete')
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                @if(count($products)==0)
                    <x-k.empty icon="catalog" :title="translate('no_product_found')" />
                @endif
                <div class="k-pager">
                    <span class="k-pager__info">
                        @if ($products->total() > 0)
                            {{ translate('showing') }}
                            <span class="k-num">{{ $products->firstItem() }}–{{ $products->lastItem() }}</span>
                            {{ translate('of') }} <span class="k-num">{{ $products->total() }}</span>
                        @endif
                    </span>
                    <div>{!! $products->appends(request()->except('page'))->links() !!}</div>
                </div>
            </div>
        </div>
    </div>
@endsection
