@if(count($stockClearanceProduct) == 0 && request()->has('searchValue'))
    <div class="card mb-3">
        <div class="card-header d-flex gap-3 flex-wrap justify-content-between">
            <h3
                class="m-0">{{ translate('Product_List') }}
            </h3>
            <div class="d-flex flex-wrap justify-content-end gap-3 flex-grow-1">
                <div class="flex-grow-1 max-w-300 min-w-100-mobile">
                    <form action="{{ url()->current() }}" method="GET">
                        <div class="input-group input-group-custom input-group-merge">
                           <input id="datatableSearch_" type="search" name="searchValue" class="form-control"
                                   placeholder="{{ translate('search_by_product_name') }}..." aria-label="Search by Order ID" value="{{ requestString('searchValue') }}">
                                <div class="input-group-append search-submit">
                                    <button type="submit">
                                        <i class="fi fi-rr-search"></i>
                                    </button>
                                </div>
                        </div>
                    </form>
                </div>
                <a href="#product-add-modal" data-bs-toggle="modal" class="btn btn-primary">+
                    {{ translate('Add_Product') }}</a>
                @if(count($stockClearanceProduct) > 0)
                    <a  class="btn text-danger bg-danger bg-opacity-10 stock-clearance-delete-all-products" data-id="clearance-product">{{ translate('clear_all') }}</a>
                @endif
            </div>
        </div>

        @if(count($stockClearanceProduct) > 0)
            <form action="{{ route('admin.deal.clearance-sale.clearance-delete-all-product') }}"
                    method="post" id="clearance-product">
                @csrf @method('delete')
            </form>
        @endif

        <div class="card-body">
            <div class="k-table-wrap">
                <table
                    class="k-table">
                    <thead>
                    <tr>
                        <th>{{ translate('sl') }}</th>
                        <th>
                            <div class="d-flex">
                                <div class="w-60">{{ translate('Image')}}</div>
                                <div>{{translate('name')}}</div>
                            </div>
                        </th>
                        <th class="text-center">
                            {{ translate('unit_price') }}
                            ({{ getCurrencySymbol(currencyCode: getCurrencyCode()) }})
                        </th>
                        @if(isset($clearanceConfig->discount_type) && $clearanceConfig->discount_type == 'product_wise')
                            <th class="text-center">{{ translate('discount_amount') }} </th>
                        @else
                            <th class="text-center">{{ translate('discount_amount') . ' (%)' }} </th>
                        @endif
                        <th class="text-center">
                            {{ translate('discount_price') }}
                            ({{ getCurrencySymbol(currencyCode: getCurrencyCode()) }})
                        </th>
                        <th class="text-center">{{ translate('status') }}</th>
                        <th class="text-center">{{translate('action')}}</th>
                    </tr>
                    </thead>
                </table>
            </div>
            @include('layouts.admin.partials._empty-state',['text'=>'no_product_found'],['image'=>'default'])
        </div>
    </div>
@elseif(count($stockClearanceProduct) == 0)
    <div class="card mb-3">
        <div class="card-header d-flex gap-3 flex-wrap justify-content-between">
            <h3
                class="m-0">{{ translate('Product_List') }}
            </h3>
            <a href="#product-add-modal" data-bs-toggle="modal" class="btn btn-primary">+
                {{ translate('Add_Product') }}</a>
        </div>
        <div class="card-body">
            <div class="p-4 bg-chat rounded text-center">
                <div class="py-5">
                    <img src="{{ dynamicAsset('public/assets/back-end/img/empty-product.png') }}" width="64"
                         alt="">
                    <div class="mx-auto my-3 max-w-353px">
                        {{ translate('add_product_show_in_the_clearance_offer_section_in_customer_app_and_website') }}
                    </div>
                    <a href="#product-add-modal" data-bs-toggle="modal" class="text-primary text-underline">+
                        {{ translate('Add_Product') }}</a>
                </div>
            </div>
        </div>
    </div>
@else
    <div class="card">
        <div class="card-header card-header d-flex gap-3 flex-wrap justify-content-between">
            <h3 class="mb-0">
                {{ translate('Product_List') }}
                <span class="badge text-dark bg-body-secondary fw-semibold rounded-50">{{ $stockClearanceProduct->total() }}</span>
            </h3>
            <div class="d-flex flex-wrap justify-content-end gap-3 flex-grow-1">
                <div class="flex-grow-1 max-w-300 min-w-100-mobile">
                    <form action="{{ url()->current() }}" method="GET">
                        <div class="input-group">
                            <input id="datatableSearch_" type="search" name="searchValue" class="form-control"
                                   placeholder="{{ translate('search_by_product_name') }}..." aria-label="Search by Order ID" value="{{ requestString('searchValue') }}">
                                <div class="input-group-append search-submit">
                                    <button type="submit">
                                        <i class="fi fi-rr-search"></i>
                                    </button>
                                </div>
                        </div>
                    </form>
                </div>
                <a href="#product-add-modal" data-bs-toggle="modal" class="btn btn-primary">+
                    {{ translate('Add_Product') }}</a>
                @if(count($stockClearanceProduct) > 0)
                    <a  class="btn text-danger bg-danger bg-opacity-10 stock-clearance-delete-all-products" data-id="clearance-product">{{ translate('clear_all') }}</a>
                @endif
            </div>
        </div>
        @if(count($stockClearanceProduct) > 0)
            <form action="{{ route('admin.deal.clearance-sale.clearance-delete-all-product') }}"
                    method="post" id="clearance-product">
                @csrf @method('delete')
            </form>
        @endif
        <div class="card-body p-0">
            <div class="k-table-wrap">
                <table
                    class="k-table">
                    <thead>
                    <tr>
                        <th>{{ translate('sl') }}</th>
                        <th>
                            <div class="d-flex">
                                <div class="w-60">{{translate('Image')}}</div>
                                <div>{{translate('name')}}</div>
                            </div>
                        </th>
                        <th class="text-center">
                            {{ translate('unit_price') }}
                            ({{ getCurrencySymbol(currencyCode: getCurrencyCode()) }})
                        </th>
                        @if(isset($clearanceConfig->discount_type) && $clearanceConfig->discount_type == 'product_wise')
                            <th class="text-center">{{ translate('discount_amount') }} </th>
                        @else
                            <th class="text-center">{{ translate('discount_amount') . ' (%)' }} </th>
                        @endif
                        <th class="text-center">
                            {{ translate('discount_price') }}
                            ({{ getCurrencySymbol(currencyCode: getCurrencyCode()) }})
                        </th>
                        <th class="text-center">{{ translate('status') }}</th>
                        <th class="text-center">{{translate('action')}}</th>
                    </tr>
                    </thead>
                    <tbody>
                        @foreach($stockClearanceProduct as $key => $clearanceProduct)
                            <tr>
                                <td>{{ $stockClearanceProduct->firstItem() + $key }}</td>
                                <td>
                                    <a href="{{ route('admin.products.view', ['addedBy'=> ($clearanceProduct?->product['added_by'] == 'seller' ? 'vendor' : 'in-house'),'id' => $clearanceProduct?->product['id']]) }}"
                                       class="text-dark text-hover-primary d-flex align-items-center gap-2">
                                        <img src="{{ getStorageImages(path:$clearanceProduct?->product?->thumbnail_full_url , type: 'backend-product') }}"
                                             class="rounded border aspect-1" alt="" width="60">
                                        <div class="max-w-250 overflow-hidden">
                                            <h4 class="text-truncate">
                                                {{ $clearanceProduct?->product?->name }}
                                            </h4>
                                            <div class="fs-12 text-dark d-flex flex-column gap-2">
                                                <div class="border-between gap-2">
                                                    @if($clearanceProduct?->product->product_type == 'physical')
                                                        <span class="parent">
                                                            <span class="opacity-75">
                                                                {{ translate('current_stock') }}
                                                            </span>
                                                           {{ $clearanceProduct?->product?->current_stock }}
                                                        </span>
                                                    @endif
                                                    <span class="parent text-truncate">
                                                        <span class="opacity-75">{{ translate('category') }}:
                                                        </span>
                                                        {{ $clearanceProduct?->product?->category->name ?? translate('not_found') }}
                                                    </span>
                                                </div>
                                                @if($clearanceProduct?->product->product_type == 'physical')
                                                    <span class="parent text-truncate">
                                                            <span class="opacity-75">{{translate('brand')}}: </span>
                                                            {{ $clearanceProduct?->product?->brand?->name ?? translate('not_available') }}
                                                        </span>
                                                @endif
                                            </div>
                                        </div>
                                    </a>
                                </td>
                                <td>
                                    @if($clearanceConfig?->discount_type == 'flat')
                                        @php($discountAmount = $clearanceProduct?->product?->unit_price - ($clearanceProduct?->product?->unit_price * $clearanceProduct->discount_amount) / 100)
                                    @else
                                        @php($discountAmount = $clearanceProduct->discount_type === 'percentage' ? ($clearanceProduct?->product?->unit_price - ($clearanceProduct?->product?->unit_price * $clearanceProduct->discount_amount) / 100): ($clearanceProduct?->product?->unit_price - $clearanceProduct->discount_amount))
                                    @endif

                                    @if($discountAmount < 0)
                                        <div class="fw-bold text-center text-warning">
                                            <span>
                                                {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $clearanceProduct?->product?->unit_price), currencyCode: getCurrencyCode()) }}
                                            </span>
                                            <span class="cursor-pointer" data-bs-toggle="tooltip" data-bs-placement="top" area-label="{{ translate('Your_products_unit_price_is_lower_then_offer_price') }}" data-bs-title="{{ translate('Your_products_unit_price_is_lower_then_offer_price') }}">
                                                <img src="{{ dynamicAsset('public/assets/back-end/img/icons/clearance-product-warning.svg') }}" alt="">
                                            </span>
                                        </div>
                                    @else
                                        <div class="text-center">
                                            {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $clearanceProduct?->product?->unit_price), currencyCode: getCurrencyCode()) }}
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <div class="d-flex justify-content-center align-items-center gap-2">
                                        @if($clearanceConfig?->discount_type == 'flat')
                                            <div class="text-center">
                                                {{ $clearanceProduct->discount_amount }}
                                            </div>
                                        @else
                                            <div class="discount-input-container position-relative d-inline-block">
                                                <div class="input-group w-140">
                                                    <input type="number" class="form-control form-control-focus-none w-80 h-25 text-center px-1 disabled" value="{{ $clearanceProduct?->discount_type == 'flat' ? usdToDefaultCurrency(amount: $clearanceProduct->discount_amount) : $clearanceProduct->discount_amount }}" readonly>
                                                    <div class="input-group-append">
                                                        <span class="input-group-text lh-1 h-100">{{ $clearanceProduct?->discount_type == 'flat' ? getCurrencySymbol(currencyCode: getCurrencyCode()) : '%' }}</span>
                                                    </div>
                                                </div>
                                            </div>
                                            @if(isset($clearanceConfig->discount_type) && $clearanceConfig->discount_type == 'product_wise')
                                                <a href="#discount-update-modal" data-bs-toggle="modal"
                                                   class="btn border-0 shadow-none text-primary discount-edit-btn"
                                                   data-product-id="{{ $clearanceProduct->product_id }}"
                                                   data-discount-amount="{{ $clearanceProduct?->discount_type == 'flat' ? usdToDefaultCurrency(amount: $clearanceProduct->discount_amount) : $clearanceProduct->discount_amount }}"
                                                   data-discount-type="{{ $clearanceProduct->discount_type }}"
                                                   data-image="{{ getStorageImages(path: $clearanceProduct?->product?->thumbnail_full_url, type: 'backend-basic') }}"
                                                   data-product-name="{{ $clearanceProduct?->product?->name }}"
                                                   data-product-brand="{{ $clearanceProduct?->product?->brand?->name ?? "" }}"
                                                   data-product-category="{{ $clearanceProduct?->product?->category?->name ?? "" }}"
                                                   data-product-stock="{{ $clearanceProduct?->product?->current_stock }}"
                                                   data-product-type="{{ $clearanceProduct?->product?->product_type }}"
                                                   data-unit-price="{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $clearanceProduct?->product?->unit_price), currencyCode: getCurrencyCode()) }}"
                                                   data-shop-name="{{ $clearanceProduct?->product?->added_by == 'seller' ? $clearanceProduct?->product?->seller?->shop?->name : getInHouseShopConfig(key: 'name') }}"
                                                   data-id="{{ $clearanceProduct->id }}"
                                                >
                                                    <i class="fi fi-sr-pencil"></i>
                                                </a>
                                            @endif
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <div class="text-center">
                                        @if($clearanceConfig?->discount_type == 'flat')
                                            {{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $clearanceProduct?->product?->unit_price - ($clearanceProduct?->product?->unit_price * $clearanceProduct->discount_amount) / 100), currencyCode: getCurrencyCode()) }}
                                        @else
                                            @php($discountAmount = $clearanceProduct->discount_type === 'percentage'? ($clearanceProduct?->product?->unit_price - ($clearanceProduct?->product?->unit_price * $clearanceProduct->discount_amount) / 100): ($clearanceProduct?->product?->unit_price - $clearanceProduct->discount_amount))
                                            {{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $discountAmount), currencyCode: getCurrencyCode()) }}
                                        @endif
                                    </div>
                                </td>
                                <td>
                                 <div class="d-flex justify-content-center">
                                     <form action="{{ route('admin.deal.clearance-sale.product-status-update') }}"
                                           method="post" id="clearance-sale-product-status{{ $clearanceProduct['id']}}-form" class="no-reload-form">
                                         @csrf
                                         <label class="switcher" for="clearance-sale-product-status{{ $clearanceProduct['id']}}">
                                             <input
                                                 class="switcher_input custom-modal-plugin"
                                                 type="checkbox" value="1" name="status"
                                                 id="clearance-sale-product-status{{ $clearanceProduct['id']}}"
                                                 {{  $clearanceProduct?->is_active == 1 ? 'checked':'' }}
                                                 data-modal-type="input-change-form"
                                                 data-modal-form="#clearance-sale-product-status{{ $clearanceProduct['id']}}-form"
                                                 data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/clearance-sale-on.png') }}"
                                                 data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/clearance-sale-off.png') }}"
                                                 data-on-title="{{translate('Want_to_Turn_ON_Active_Clearance_Sale_product').'?'}}"
                                                 data-off-title="{{translate('Want_to_Turn_OFF_Active_Clearance_Sale_product').'?'}}"
                                                 data-on-message="<p>{{translate('if_enabled_this_clearance_sale_product_will_be_available_to_users_on_the_website_and_app')}}</p>"
                                                 data-off-message="<p>{{translate('if_disabled_this_clearance_sale_product_will_be_hidden_to_users_on_the_website_and_app')}}</p>"
                                                 data-on-button-text="{{ translate('turn_on') }}"
                                                 data-off-button-text="{{ translate('turn_off') }}">
                                             <span class="switcher_control"></span>
                                         </label>
                                     </form>
                                 </div>
                                </td>
                                <td>
                                     <div class="d-flex justify-content-center">
                                         <a title="Delete" class="btn btn-outline-danger icon-btn stock-clearance-delete-data" href="javascript:" data-id="clearance-product-{{ $clearanceProduct->id }}">
                                             <i class="fi fi-rr-trash"></i>
                                         </a>
                                     </div>
                                     <form action="{{ route('admin.deal.clearance-sale.clearance-delete',[$clearanceProduct->product_id]) }}"
                                           method="post" id="clearance-product-{{ $clearanceProduct->id}}">
                                         @csrf @method('delete')
                                     </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif

@if(count($stockClearanceProduct) > 0)
    <div class="px-4 d-flex justify-content-lg-end mt-3">
        {{ $stockClearanceProduct->links() }}
    </div>
@endif

