@extends('layouts.vendor.app')

@section('title', translate($type == 'new-request' ? 'Pending_Products' : ($type == 'approved' ? 'Approved_Products' : 'Product_List')))

@section('content')
    <div class="content container-fluid">

        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex gap-2 align-items-center">
                <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/inhouse-product-list.png') }}" alt="">
                {{ translate($type=='new-request'?'pending_for_approval_products':($type=='approved'?'approved_products':'product_list')) }}
                <span class="badge badge-soft-dark radius-50 fz-14 ms-1">
                    {{ $products->total() }}
                </span>
            </h2>
        </div>

        <div class="row mt-20">
            <div class="col-md-12">
                <x-k.data-view :title="translate($type=='new-request'?'pending_for_approval_products':($type=='approved'?'approved_products':'product_list'))"
                               :count="$products->total()"
                               searchName="searchValue" :searchValue="requestString('searchValue')"
                               :searchPlaceholder="translate('search_by_Product_Name')">

                    <x-slot:actions>
                        <a class="k-btn k-btn--secondary"
                           href="{{ route('vendor.products.export-excel', [
                                'type' => $type,
                                'searchValue' => request('searchValue'),
                                'filter_sort_by' => request('filter_sort_by'),
                                'filter_product_types' => request('filter_product_types'),
                                'product_status' => request('product_status'),
                                'filter_brand_ids' => request('filter_brand_ids'),
                                'filter_category_ids' => request('filter_category_ids'),
                                ]) }}">
                            <x-k.icon name="download" :size="15" /> {{ translate('export') }}
                        </a>
                        @php($vendorFilterActive = !empty(request('filter_sort_by')) || !empty(request('filter_product_types')) || !empty(request('product_status')) || !empty(request('filter_shop_ids')) || !empty(request('filter_brand_ids')) || !empty(request('filter_category_ids')))
                        <button type="button" class="k-btn {{ $vendorFilterActive ? 'k-btn--primary' : 'k-btn--secondary' }}"
                                data-toggle="offcanvas" data-target="#offcanvasProductFilter">
                            <x-k.icon name="filter" :size="15" /> {{ translate('Filter') }}
                        </button>
                        @if($type != 'new-request' )
                        <a href="{{ route('vendor.products.add') }}" class="k-btn k-btn--primary">
                            <x-k.icon name="plus" :size="15" /> {{ translate('add_new_product') }}
                        </a>
                        @endif
                    </x-slot:actions>

                    <table class="k-table">
                        <thead>
                        <tr>
                            <th>{{ translate('product_name') }}</th>
                            <th>{{ translate('product_type') }}</th>
                            <th class="k-table__num">{{ translate('unit_price') }}</th>
                            <th class="k-table__num">{{ translate('stock') }}</th>
                            @if ($productWiseTax)
                                <th>{{ translate('Vat/Tax') }}</th>
                            @endif
                            @if($type != 'new-request' )
                            <th>{{ translate('active_status') }}</th>
                            @endif
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($products as $product)
                            <tr>
                                <td>
                                    <a href="{{ route('vendor.products.view', [$product['id']]) }}" class="k-row">
                                        <img src="{{ getStorageImages(path:$product->thumbnail_full_url,type:'backend-product')}}"
                                             alt="" width="40" height="40"
                                             style="border-radius:8px;object-fit:cover;flex:0 0 auto;border:1px solid var(--k-border)">
                                        <span style="min-inline-size:0">
                                            <span class="k-truncate" style="display:block;max-inline-size:220px" title="{{ $product['name'] }}">
                                                {{ $product['name'] }}
                                                @if($product?->clearanceSale)
                                                    <span class="text-secondary-base fs-12" data-toggle="tooltip" title="{{ translate('Clearance_Sale') }}">
                                                        <i class="fi fi-sr-bahai"></i>
                                                    </span>
                                                @endif
                                            </span>
                                            <span class="k-text-subtle">#{{ $product['id'] }}</span>
                                            @if($product->request_status == 0)
                                                <x-k.badge tone="warning">{{ translate('pending') }}</x-k.badge>
                                            @elseif($product->request_status == 2)
                                                <x-k.badge tone="danger">{{ translate('denied') }}</x-k.badge>
                                            @endif
                                            @if($product->request_status == 2 && \Illuminate\Support\Facades\Schema::hasTable('product_moderation_events'))
                                                @php($lastModeration = \App\Models\ProductModerationEvent::forProduct($product->id)->first())
                                                @if($lastModeration && ($lastModeration->reason_codes || $lastModeration->note))
                                                    <span class="k-badge k-badge--info" role="button" data-toggle="tooltip"
                                                          title="{{ collect($lastModeration->reason_codes ?? [])->map(fn($r) => translate($r))->implode(', ') }}{{ $lastModeration->note ? ' — ' . $lastModeration->note : '' }}">
                                                        {{ $lastModeration->action === 'needs_changes' ? translate('needs_changes') : translate('why') }}
                                                    </span>
                                                @endif
                                            @endif
                                        </span>
                                    </a>
                                </td>
                                <td>{{ translate($product['product_type']) }}</td>
                                <td class="k-table__num"><span class="k-num">{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $product['unit_price']), currencyCode: getCurrencyCode()) }}</span></td>
                                <td class="k-table__num">
                                    @if ($product['product_type'] === 'physical')
                                        <span class="k-num">{{ $product->current_stock }}</span>
                                        @if ($product->current_stock <= 0)
                                            <x-k.badge tone="danger">{{ translate('Out_of_Stock') }}</x-k.badge>
                                        @elseif ($product->current_stock <= 20)
                                            <x-k.badge tone="warning">{{ translate('Low_Stock') }}</x-k.badge>
                                        @endif
                                    @else
                                        <span class="k-text-subtle">—</span>
                                    @endif
                                </td>
                                @if ($productWiseTax)
                                    <td>
                                        @forelse ($product?->taxVats as $taxVat)
                                            <span class="k-text-subtle">{{ $taxVat?->tax?->name }} <span class="k-num">({{ $taxVat?->tax?->tax_rate }}%)</span></span><br>
                                        @empty
                                            <span class="k-text-subtle">{{ translate('N/A') }}</span>
                                        @endforelse
                                    </td>
                                @endif
                                @if($type != 'new-request' )
                                    <td>
                                        @php($productName = str_replace("'",'`',$product['name']))
                                        <form action="{{ route('vendor.products.status-update') }}" method="post" data-from="product-status"
                                              id="product-status{{ $product['id']}}-form" class="admin-product-status-form">
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
                                                       data-on-title="{{ translate('Want_to_Turn_ON').' '.$productName.' '.translate('status') }}"
                                                       data-off-title="{{ translate('Want_to_Turn_OFF').' '.$productName.' '.translate('status') }}"
                                                       data-on-message="<p>{{ translate('if_enabled_this_product_will_be_available_on_the_website_and_customer_app') }}</p>"
                                                       data-off-message="<p>{{ translate('if_disabled_this_product_will_be_hidden_from_the_website_and_customer_app') }}</p>">
                                                <span class="switcher_control"></span>
                                            </label>
                                        </form>
                                    </td>
                                @endif
                                <td>
                                    <div class="k-table__actions">
                                        @if($type != 'new-request' )
                                            <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon"
                                               title="{{ translate('barcode') }}"
                                               href="{{ route('vendor.products.barcode', [$product['id']]) }}">
                                                <x-k.icon name="grip" :size="15" />
                                            </a>
                                        @endif
                                        <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon" title="{{ translate('view') }}"
                                           href="{{ route('vendor.products.view', [$product['id']]) }}">
                                            <x-k.icon name="eye" :size="15" />
                                        </a>
                                        <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon"
                                           title="{{ translate('edit') }}"
                                           href="{{ route('vendor.products.update',[$product['id']]) }}">
                                            <x-k.icon name="edit" :size="15" />
                                        </a>
                                        <span class="k-btn k-btn--ghost k-btn--sm k-btn--icon delete-data" role="button"
                                              title="{{ translate('delete') }}"
                                              data-id="product-{{ $product['id']}}">
                                            <x-k.icon name="trash" :size="15" />
                                        </span>
                                    </div>
                                    <form action="{{ route('vendor.products.delete',[$product['id']]) }}"
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
                                   :text="request('searchValue') ? translate('no_product_matches_your_search') : null">
                            @if($type != 'new-request')
                                <x-slot:action>
                                    <x-k.button variant="primary" icon="plus" :href="route('vendor.products.add')">
                                        {{ translate('add_new_product') }}
                                    </x-k.button>
                                </x-slot:action>
                            @endif
                        </x-k.empty>
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

                @include('vendor-views.product.partials.offcanvas._filter-offcanvas')
            </div>
        </div>
    </div>
    <span id="message-select-word" data-text="{{ translate('select') }}"></span>
@endsection
