@extends('layouts.admin.app')

@section('title', translate('restock_product_List'))

@section('content')
    <div class="content container-fluid">

        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/inhouse-product-list.png') }}" alt="">
                {{ translate('Request_Restock_List') }}
                <span class="badge text-dark bg-body-secondary fw-semibold rounded-50">{{ $totalRestockProducts }}</span>
            </h2>
        </div>

        <div class="row mt-20">
            <div class="col-md-12">
                <x-k.data-view :title="translate('Request_Restock_List')" :count="$totalRestockProducts"
                               searchName="searchValue" :searchValue="requestString('searchValue')"
                               :searchPlaceholder="translate('search_by_Product_Name')">

                    <x-slot:actions>
                        <a class="k-btn k-btn--secondary"
                           href="{{ route('admin.products.restock-export', ['restock_date' => request('restock_date'), 'brand_id' => request('brand_id'), 'category_id' => request('category_id'), 'sub_category_id' => request('sub_category_id'), 'searchValue' => request('searchValue')]) }}">
                            <x-k.icon name="download" :size="15" /> {{ translate('export') }}
                        </a>
                        @php($restockFilterActive = !empty(request('restock_date')) || !empty(request('category_id')) || !empty(request('sub_category_id')) || !empty(request('brand_id')))
                        <button type="button" class="k-btn {{ $restockFilterActive ? 'k-btn--primary' : 'k-btn--secondary' }}"
                                data-bs-toggle="offcanvas" data-bs-target="#offcanvasRestockFilter">
                            <x-k.icon name="filter" :size="15" /> {{ translate('Filter') }}
                        </button>
                    </x-slot:actions>

                    <table class="k-table">
                        <thead>
                        <tr>
                            <th>{{ translate('SL') }}</th>
                            <th>{{ translate('product_name') }}</th>
                            <th class="k-table__num">{{ translate('selling_price') }}</th>
                            <th>{{ translate('last_request_date') }}</th>
                            <th class="k-table__num">{{ translate('number_of_request') }}</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($restockProducts as $key => $restockProduct)
                            <tr>
                                <td><span class="k-num">{{ $restockProducts->firstItem() + $key }}</span></td>
                                <td>
                                    <a href="{{ route('admin.products.view', ['addedBy' => $restockProduct->product['added_by'] == 'seller' ? 'vendor' : 'in-house', 'id' => $restockProduct->product['id'] ?? 0]) }}" class="k-row">
                                        <img src="{{ getStorageImages(path: $restockProduct?->product?->thumbnail_full_url, type: 'backend-product') }}"
                                             alt="" width="40" height="40"
                                             style="border-radius:8px;object-fit:cover;flex:0 0 auto;border:1px solid var(--k-border)">
                                        <span style="min-inline-size:0">
                                            <span class="k-truncate text-dark" style="display:block;max-inline-size:200px" title="{{ $restockProduct->product['name'] ?? '' }}">
                                                {{ Str::limit($restockProduct->product['name'] ?? '', 20) }}
                                            </span>
                                            @if ($restockProduct['variant'])
                                                <span class="k-text-subtle">{{ translate('Variant:') }} {{ $restockProduct['variant'] }}</span>
                                            @endif
                                        </span>
                                    </a>
                                </td>
                                <td class="k-table__num">
                                    <span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $restockProduct->product['unit_price'] ?? 0), currencyCode: getCurrencyCode()) }}</span>
                                </td>
                                <td>
                                    <span class="k-num">{{ $restockProduct->updated_at->format('d F Y, h:i A') }}</span>
                                </td>
                                <td class="k-table__num">
                                    <span class="k-num">{{ $restockProduct?->restockProductCustomers?->count() ?? 0 }}</span>
                                </td>
                                <td>
                                    <div class="k-table__actions">
                                        <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon" title="{{ translate('view') }}"
                                           href="{{ route('admin.products.view', ['addedBy' => $restockProduct->product['added_by'] == 'seller' ? 'vendor' : 'in-house', 'id' => $restockProduct->product['id'] ?? 0]) }}">
                                            <x-k.icon name="eye" :size="15" />
                                        </a>
                                        <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon action-update-product-quantity"
                                           title="{{ translate('edit') }}"
                                           id="{{ $restockProduct->product['id'] }}"
                                           data-url="{{ route('admin.products.get-variations', ['id' => $restockProduct->product['id'], 'restock_id' => $restockProduct->id]) }}"
                                           data-bs-target="#update-stock">
                                            <x-k.icon name="edit" :size="15" />
                                        </a>
                                        <span class="k-btn k-btn--ghost k-btn--sm k-btn--icon delete-data" role="button"
                                              title="{{ translate('delete') }}"
                                              data-id="product-{{ $restockProduct->id }}">
                                            <x-k.icon name="trash" :size="15" />
                                        </span>
                                    </div>
                                    <form action="{{ route('admin.products.restock-delete', [$restockProduct->id]) }}"
                                          method="post" id="product-{{ $restockProduct->id }}">
                                        @csrf @method('delete')
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>

                    @if (count($restockProducts) == 0)
                        <x-k.empty icon="catalog" :title="translate('no_product_found')"
                                   :text="request('searchValue') ? translate('no_product_matches_your_search') : null" />
                    @endif

                    @if ($restockProducts->total() > 0)
                        <x-slot:pager>
                            <span class="k-pager__info">
                                {{ translate('showing') }}
                                <span class="k-num">{{ $restockProducts->firstItem() }}–{{ $restockProducts->lastItem() }}</span>
                                {{ translate('of') }} <span class="k-num">{{ $restockProducts->total() }}</span>
                            </span>
                            <div>{!! $restockProducts->appends(request()->except('page'))->links() !!}</div>
                        </x-slot:pager>
                    @endif
                </x-k.data-view>
            </div>
        </div>
    </div>
    <span id="message-select-word" data-text="{{ translate('select') }}"></span>
    <div class="modal fade update-stock-modal restock-stock-update" id="update-stock" tabindex="-1">
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
                            {{ translate('update') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @include('admin-views.product.partials.offcanvas._restock-list-filter-offcanvas')
@endsection

@push('script')
    <script>
        $(document).ready(function () {

            function toggleCustomDate(wrapper) {
                let selectValue = wrapper.find('.date-type-select').val();

                if (selectValue === 'custom') {
                    wrapper.find('.custom-date-div').slideDown(200);
                } else {
                    wrapper.find('.custom-date-div').slideUp(200);
                }
            }

            $(document).on('change', '.date-type-select', function () {
                let wrapper = $(this).closest('.date-filter-wrapper');
                toggleCustomDate(wrapper);
            });

            $('.date-filter-wrapper').each(function () {
                toggleCustomDate($(this));
            });

        });
    </script>
@endpush
