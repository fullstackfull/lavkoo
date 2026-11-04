@extends('layouts.admin.app')

@section('title', translate('deal_Product'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize">
                <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/inhouse-product-list.png') }}" class="mb-1 me-1" alt="">
                {{ translate('add_new_product') }}
            </h2>
        </div>
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h3 class="mb-0 text-capitalize">{{ $deal['title'] }}</h3>
                    </div>
                    <div class="card-body">
                        <form action="{{route('admin.deal.add-product',[$deal['id']]) }}" method="post">
                            @csrf
                            <div class="form-group">
                                <div class="row">
                                    <div class="col-md-12 mt-3">
                                        <label for="name" class="form-label">{{ translate('select_products') }}</label>
                                        {{-- Same shell as /admin/deal/day (resources/views/admin-views/deal/day-index.blade.php):
                                             a button-style dropdown toggle with the search-form (magnifier + input)
                                             tucked INSIDE the dropdown-menu, instead of a bare search input that
                                             also acts as the toggle. The .search-all-type-product class on the
                                             input keeps the existing multi-select JS hooks
                                             (public/assets/back-end/js/search-and-select-multiple-product.js)
                                             working unchanged, and the chip area below preserves multi-add. --}}
                                        <div class="dropdown select-product-search w-100">
                                            <button class="form-select bg-transparent shadow-none text-start dropdown-toggle line-1 word-break select-product-button"
                                                    data-bs-toggle="dropdown" data-bs-auto-close="outside"
                                                    aria-haspopup="true" aria-expanded="false" type="button">
                                                {{ translate('select_products') }}
                                            </button>
                                            <div class="dropdown-menu w-100 px-2">
                                                <div class="search-form mb-3">
                                                    <button type="button" class="btn" aria-label="{{ translate('search') }}"><i class="fi fi-rr-search"></i></button>
                                                    <input type="text" id="searchInput" autocomplete="off"
                                                           class="js-form-search form-control search-bar-input search-all-type-product"
                                                           data-deal-id="{{$deal_id}}"
                                                           placeholder="{{ translate('search_by_product_name').'...' }}">
                                                </div>
                                                <div class="d-flex flex-column max-h-300 overflow-y-auto overflow-x-hidden search-result-box">
                                                    @include('admin-views.partials._search-product',['products' => $products])
                                                </div>
                                            </div>
                                        </div>
                                        <div class="selected-products d-flex flex-wrap gap-3 mt-3" id="selected-products">
                                            @include('admin-views.partials._select-product')
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex gap-3 justify-content-end">
                                <button type="button" class="btn btn-secondary fw-bold px-4 reset-selected-products">{{ translate('reset') }}</button>
                                <button type="submit" class="btn btn-primary fw-bold px-4">{{ translate('add') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-md-12">
                <div class="card">
                    <div class="px-3 py-4">
                        <h3 class="mb-0 text-capitalize">
                            {{ translate('product_table') }}
                            <span class="badge text-dark bg-body-secondary fw-semibold rounded-50">{{ $dealProducts->total() }}</span>
                        </h3>
                    </div>
                    <div class="k-table-wrap">
                        <table
                            class="k-table">
                            <thead>
                            <tr>
                                <th>{{ translate('SL') }}</th>
                                <th>{{ translate('Product Name') }}</th>
                                <th>{{ translate('Shop') }}</th>
                                <th class="text-center">{{ translate('Product Type') }}</th>
                                <th>{{ translate('Unit Price') }}</th>
                                <th class="text-center">{{ translate('Stock') }}</th>
                                <th class="text-center">{{ translate('Action') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                                @php($companyName = getInHouseShopConfig(key: 'name'))
                                @foreach($dealProducts as $key => $product)
                                    <tr>
                                        <td>{{ $dealProducts->firstitem() + $key}}</td>
                                        <td>
                                            <a href="{{ route('admin.products.view', ['addedBy' => $product['added_by'] == 'seller' ? 'vendor' : 'in-house', 'id' => $product['id']]) }}"
                                               class="media align-items-center gap-2">
                                                <img
                                                    src="{{ getStorageImages(path: $product->thumbnail_full_url, type: 'backend-product') }}"
                                                    class="avatar border object-fit-cover" alt="">
                                                <div>
                                                    <div
                                                        class="d-flex gap-2 align-items-center lh-1 w-max-content text-wrap line-1 max-w-300 min-w-130 text-dark text-hover-primary">
                                                        <div class="media-body text-dark line-1 text-hover-primary"
                                                             data-bs-toggle="tooltip" title="{{ $product['name'] }}">
                                                            {{ Str::limit($product['name'], 20) }}
                                                        </div>
                                                        @if ($product?->clearanceSale)
                                                            <span class="text-secondary" data-bs-toggle="tooltip"
                                                                  title="{{ translate('Clearance_Sale') }}">
                                                                <i class="fi fi-sr-bahai"></i>
                                                            </span>
                                                        @endif
                                                    </div>
                                                    <div class="d-flex gap-2 align-items-center lh-1 mt-2">
                                                        <div class="text-body">
                                                            {{ translate('Id') }} # {{$product['id']}}
                                                        </div>
                                                    </div>
                                                </div>
                                            </a>
                                        </td>
                                        <td>
                                            @if($product->added_by == 'admin')
                                                <div class="d-flex align-items-center gap-10 w-max-content">
                                                    <img width="50" class="avatar rounded-circle object-fit-cover"
                                                         src="{{ getStorageImages(path: getInHouseShopConfig(key: 'image_full_url'), type: 'shop') }}"
                                                         alt="">
                                                    <div>
                                                        <a class="text-dark text-hover-primary" href="{{ route('admin.business-settings.inhouse-shop') }}">
                                                            {{ Str::limit(getInHouseShopConfig(key: 'name'), 20) }}
                                                        </a>
                                                        <span class="text-danger fs-12">
                                                        @if(checkVendorAbility(type: 'inhouse', status: 'temporary_close'))
                                                            <br>
                                                            {{ translate('temporary_closed') }}
                                                        @elseif(checkVendorAbility(type: 'inhouse', status: 'vacation_status'))
                                                            <br>
                                                            {{ translate('On_Vacation') }}
                                                        @endif
                                                        </span>
                                                    </div>
                                                </div>
                                            @elseif($product->added_by == 'seller' && $product?->seller?->shop)
                                                <div class="d-flex align-items-center gap-10 w-max-content">
                                                    <img width="50"
                                                         class="avatar rounded-circle object-fit-cover" src="{{ getStorageImages(path: $product?->seller?->shop?->image_full_url, type: 'backend-basic') }}"
                                                         alt="">
                                                    <div>
                                                        <a class="text-dark text-hover-primary" href="{{ route('admin.vendors.view', ['id' => $product?->seller->id]) }}">
                                                            {{ Str::limit($product?->seller?->shop->name, 20) }}
                                                        </a>
                                                        <span class="text-danger fs-12">
                                                        @if(checkVendorAbility(type: 'vendor', status: 'temporary_close', vendor: $product?->seller?->shop))
                                                            <br>
                                                            {{ translate('temporary_closed') }}
                                                        @elseif(checkVendorAbility(type: 'vendor', status: 'vacation_status', vendor: $product?->seller?->shop))
                                                            <br>
                                                            {{ translate('On_Vacation') }}
                                                        @endif
                                                        </span>
                                                    </div>
                                                </div>
                                            @else
                                                <div class="d-flex align-items-center gap-10 w-max-content">
                                                    <img width="50" alt="" class="avatar rounded-circle object-fit-cover"
                                                         src="{{ getStorageImages(path: '', type: 'backend-basic') }}">
                                                    <div>
                                                        <a class="text-dark text-hover-primary" href="javascript:">
                                                            {{ translate('Shop_Not_Found') }}
                                                        </a>
                                                    </div>
                                                </div>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            {{ translate(str_replace('_', ' ', $product['product_type'])) }}
                                        </td>
                                        <td>{{setCurrencySymbol(usdToDefaultCurrency(amount: $product['unit_price'])) }}</td>
                                        <td>
                                            <div class="d-flex justify-content-center gap-2 align-items-center lh-1">
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
                                        <td>
                                            <div class="d-flex justify-content-center">
                                                <a title="{{ translate ('delete') }}"
                                                   class="btn btn-outline-danger icon-btn delete-data-without-form"
                                                   data-action="{{route('admin.deal.delete-product') }}" data-id="{{ $product['id'] }}">
                                                    <i class="fi fi-rr-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <div class="table-responsive mt-4">
                            <div class="d-flex justify-content-lg-end">
                                {!! $dealProducts->links() !!}
                            </div>
                        </div>
                    </div>
                    @if(count($dealProducts)==0)
                        @include('layouts.admin.partials._empty-state',['text'=>'no_product_select_yet'],['image'=>'default'])
                    @endif
                </div>
            </div>
        </div>
    </div>
    {{-- Carries the localized "already selected" toast copy that
         search-and-select-multiple-product.js reads in showAlreadySelectedToast(). --}}
    <span id="product-already-selected-message"
          data-message="{{ translate('product_is_already_selected') }}"></span>
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/search-and-select-multiple-product.js') }}"></script>
    <script>
        $('.search-all-type-product').off('focus click');
    </script>
@endpush
