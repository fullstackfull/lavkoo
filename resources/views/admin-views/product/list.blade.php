@extends('layouts.admin.app')

@section('title', translate('product_List'))

@section('content')
    @php
        use Illuminate\Support\Str;

        $isInHouse = $type == 'in_house';
        $title = $isInHouse ? translate('in_House_Product_List') : translate('vendor_Product_List');

        $currentFilters = [
            'request_status'       => request('request_status'),
            'searchValue'          => request('searchValue'),
            'seller_id'            => request('seller_id'),
            'filter_sort_by'       => request('filter_sort_by'),
            'filter_product_types' => request('filter_product_types'),
            'product_status'       => request('product_status'),
            'filter_brand_ids'     => request('filter_brand_ids'),
            'filter_category_ids'  => request('filter_category_ids'),
        ];
        $hasFilters = collect([
            request('filter_sort_by'), request('filter_product_types'), request('product_status'),
            request('filter_shop_ids'), request('filter_brand_ids'), request('filter_category_ids'),
        ])->filter(fn ($value) => !empty($value))->isNotEmpty();

        // A vendor product awaiting approval, or denied, cannot be published or
        // featured — the same rule the endpoints enforce.
        $awaitingApproval = request()->has('request_status') && (request('request_status') === '0' || request('request_status') === '2');
    @endphp

    <div class="content container-fluid">
        <x-k.page-header :title="$title">
            <x-slot:actions>
                <x-k.button variant="secondary" icon="download"
                            :href="route('admin.products.export-excel', ['type' => request('type')] + $currentFilters)">
                    {{ translate('export') }}
                </x-k.button>
                @if ($isInHouse)
                    <x-k.button variant="primary" icon="plus" :href="route('admin.products.add')">
                        {{ translate('Add_New_Product') }}
                    </x-k.button>
                @endif
            </x-slot:actions>
        </x-k.page-header>

        <x-k.data-view :title="translate('product_List')" :count="$products->total()" :selectable="true"
                       searchName="searchValue" :searchValue="requestString('searchValue')"
                       :searchPlaceholder="translate('search_by_Product_Name')">

            <x-slot:actions>
                <div class="position-relative">
                    @if ($hasFilters)
                        <span class="position-absolute top-n1 inset-inline-end-n1 btn-circle bg-danger border border-white border-2"
                              style="--size: 10px;"></span>
                    @endif
                    <x-k.button variant="secondary" icon="filter" data-bs-toggle="offcanvas"
                                data-bs-target="#offcanvasProductFilter">
                        {{ translate('Filter') }}
                    </x-k.button>
                </div>
            </x-slot:actions>

            <x-slot:bulk>
                @if ($awaitingApproval)
                    <span class="k-field__hint">{{ translate('Non approved products cannot be featured') }}</span>
                @else
                    <x-k.button variant="secondary" size="sm" icon="check" data-k-bulk-action="publish">
                        {{ translate('publish') }}
                    </x-k.button>
                    <x-k.button variant="secondary" size="sm" icon="eye-off" data-k-bulk-action="unpublish">
                        {{ translate('unpublish') }}
                    </x-k.button>
                    <x-k.button variant="secondary" size="sm" icon="sparkles" data-k-bulk-action="feature">
                        {{ translate('toggle_featured') }}
                    </x-k.button>
                @endif
            </x-slot:bulk>

            <table class="k-table" id="datatable">
                <thead>
                <tr>
                    <th style="inline-size:44px">
                        <input type="checkbox" data-k-select-all aria-label="{{ translate('select_all') }}">
                    </th>
                    <th>{{ translate('product Name') }}</th>
                    <th>{{ translate('product Type') }}</th>
                    <th class="k-table__num">{{ translate('unit_price') }}</th>
                    <th class="k-table__num">{{ translate('stock') }}</th>
                    @if ($productWiseTax)
                        <th>{{ translate('Vat/Tax') }}</th>
                    @endif
                    <th title="{{ translate('Highlight_this_product_on_the_homepage_by_marking_it_as_featured') }}">
                        {{ translate('show_as_featured') }}
                    </th>
                    <th>{{ translate('status') }}</th>
                    <th></th>
                </tr>
                </thead>

                <tbody>
                @foreach ($products as $product)
                    @php
                        $viewUrl = route('admin.products.view', [
                            'addedBy' => $product['added_by'] == 'seller' ? 'vendor' : 'in-house',
                            'id' => $product['id'],
                        ]);
                    @endphp
                    <tr>
                        <td>
                            <input type="checkbox" data-k-row-select value="{{ $product['id'] }}"
                                   aria-label="{{ translate('select_product') }} {{ $product['id'] }}">
                        </td>

                        <td>
                            <a href="{{ $viewUrl }}" class="k-row" style="gap:var(--k-size-3)">
                                <img src="{{ getStorageImages(path: $product->thumbnail_full_url, type: 'backend-product') }}"
                                     alt="" width="38" height="38"
                                     style="border-radius:var(--k-radius-sm);object-fit:cover;border:1px solid var(--k-border);flex:0 0 auto">
                                <span style="min-inline-size:0">
                                    <span class="k-row" style="gap:var(--k-size-1)">
                                        <span class="k-truncate" style="max-inline-size:240px" title="{{ $product['name'] }}">
                                            {{ Str::limit($product['name'], 40) }}
                                        </span>
                                        @if ($product?->clearanceSale)
                                            <span class="k-text-subtle" title="{{ translate('Clearance_Sale') }}">
                                                <x-k.icon name="sparkles" :size="13" />
                                            </span>
                                        @endif
                                    </span>
                                    <span class="k-text-subtle" style="font-size:var(--k-text-sm)">
                                        {{ translate('Id') }} #<span class="k-num">{{ $product['id'] }}</span>
                                    </span>
                                </span>
                            </a>
                        </td>

                        <td class="k-text-muted">{{ translate(str_replace('_', ' ', $product['product_type'])) }}</td>

                        <td class="k-table__num k-num">
                            {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $product['unit_price']), currencyCode: getCurrencyCode()) }}
                        </td>

                        <td class="k-table__num">
                            @if ($product['product_type'] === 'physical')
                                {{-- Stock reads as a state, not just a number: a badge makes "out" and
                                     "low" scannable down the column instead of read one row at a time. --}}
                                @if ($product->current_stock <= 0)
                                    <x-k.badge tone="danger">{{ translate('Out_of_Stock') }}</x-k.badge>
                                @elseif ($product->current_stock <= 20)
                                    <x-k.badge tone="warning"><span class="k-num">{{ $product->current_stock }}</span> · {{ translate('Low_Stock') }}</x-k.badge>
                                @else
                                    <span class="k-num">{{ $product->current_stock }}</span>
                                @endif
                            @else
                                <span class="k-text-subtle">—</span>
                            @endif
                        </td>

                        @if ($productWiseTax)
                            <td class="k-text-muted" style="font-size:var(--k-text-sm)">
                                @forelse ($product?->taxVats as $taxVat)
                                    <div>{{ $taxVat?->tax?->name }} <span class="k-num">({{ $taxVat?->tax?->tax_rate }}%)</span></div>
                                @empty
                                    <span class="k-text-subtle">{{ translate('N/A') }}</span>
                                @endforelse
                            </td>
                        @endif

                        <td>
                            <div title="{{ $awaitingApproval ? translate('Non approved products cannot be featured') : '' }}">
                                <input type="checkbox"
                                       class="product-status-checkbox form-check-input checkbox--input checkbox--input_lg"
                                       data-id="{{ $product['id'] }}"
                                       data-action="{{ route('admin.products.featured-status') }}"
                                       {{ $product['featured'] == 1 ? 'checked' : '' }}
                                       {{ $awaitingApproval ? 'disabled' : '' }}>
                            </div>
                        </td>

                        <td>
                            {{-- Kept as the project's confirm-modal switcher: turning a product off
                                 removes it from the storefront, so it keeps its confirmation step. --}}
                            <form action="{{ route('admin.products.status-update') }}" method="post"
                                  id="product-status{{ $product['id'] }}-form" class="admin-product-status-form">
                                @csrf
                                <input type="hidden" name="id" value="{{ $product['id'] }}">
                                <label class="switcher" for="products-status-update-{{ $product['id'] }}">
                                    <input class="switcher_input custom-modal-plugin" type="checkbox" value="1" name="status"
                                           id="products-status-update-{{ $product['id'] }}"
                                           {{ $product['status'] == 1 ? 'checked' : '' }}
                                           data-modal-type="input-change-form"
                                           data-modal-form="#product-status{{ $product['id'] }}-form"
                                           data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/product-status-on.png') }}"
                                           data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/product-status-off.png') }}"
                                           data-on-title="{{ translate('Want_to_Turn_ON') . ' ' . str_replace("'", '`', $product['name']) . ' ' . translate('status') }}"
                                           data-off-title="{{ translate('Want_to_Turn_OFF') . ' ' . str_replace("'", '`', $product['name']) . ' ' . translate('status') }}"
                                           data-on-message="<p>{{ translate('if_enabled_this_product_will_be_available_on_the_website_and_customer_app') }}</p>"
                                           data-off-message="<p>{{ translate('if_disabled_this_product_will_be_hidden_from_the_website_and_customer_app') }}</p>">
                                    <span class="switcher_control"></span>
                                </label>
                            </form>
                        </td>

                        <td>
                            <div class="k-table__actions">
                                <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon" title="{{ translate('barcode') }}"
                                   href="{{ route('admin.products.barcode', [$product['id']]) }}">
                                    <x-k.icon name="grip" :size="15" />
                                </a>
                                <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon" title="{{ translate('view') }}" href="{{ $viewUrl }}">
                                    <x-k.icon name="eye" :size="15" />
                                </a>
                                <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon" title="{{ translate('edit') }}"
                                   href="{{ route('admin.products.update', [$product['id']]) }}">
                                    <x-k.icon name="edit" :size="15" />
                                </a>
                                <span class="k-btn k-btn--ghost k-btn--sm k-btn--icon delete-data"
                                      title="{{ translate('delete') }}" data-id="product-{{ $product['id'] }}"
                                      style="color:var(--k-danger)">
                                    <x-k.icon name="trash" :size="15" />
                                </span>
                            </div>
                            <form action="{{ route('admin.products.delete', [$product['id']]) }}"
                                  method="post" id="product-{{ $product['id'] }}">
                                @csrf @method('delete')
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if (count($products) == 0)
                <x-k.empty icon="catalog" :title="translate('no_product_found')"
                           :text="$hasFilters ? translate('no_product_matches_the_filters_you_applied') : translate('add_your_first_product_to_see_it_here')">
                    <x-slot:action>
                        @if ($hasFilters)
                            <x-k.button variant="secondary" :href="route('admin.products.list', [$type])">
                                {{ translate('clear_all') }}
                            </x-k.button>
                        @elseif ($isInHouse)
                            <x-k.button variant="primary" icon="plus" :href="route('admin.products.add')">
                                {{ translate('Add_New_Product') }}
                            </x-k.button>
                        @endif
                    </x-slot:action>
                </x-k.empty>
            @endif

            @if ($products->total() > 0)
                <x-slot:pager>
                    <span class="k-pager__info">
                        {{ translate('showing') }}
                        <span class="k-num">{{ $products->firstItem() }}–{{ $products->lastItem() }}</span>
                        {{ translate('of') }} <span class="k-num">{{ $products->total() }}</span>
                    </span>
                    <div>{!! $products->appends($currentFilters)->links() !!}</div>
                </x-slot:pager>
            @endif
        </x-k.data-view>
    </div>

    <span id="message-select-word" data-text="{{ translate('select') }}"></span>

    @include('admin-views.product.partials.offcanvas._filter-offcanvas')
@endsection

@push('script')
    <script>
        "use strict";
        (function () {
            var view = document.querySelector('[data-k-selectable]');
            if (!view) return;

            var endpoint = @json(route('admin.products.bulk-product-status'));
            var csrf = document.querySelector('meta[name="csrf-token"]');
            var labels = {
                confirm: @json(translate('apply_this_to')),
                products: @json(translate('products')),
                working: @json(translate('updating_products')),
                failed: @json(translate('could_not_update_the_products')),
                skipped: @json(translate('skipped')),
            };

            view.addEventListener('click', function (event) {
                var trigger = event.target.closest('[data-k-bulk-action]');
                if (!trigger) return;

                var ids = Kohl.selectedIds(view);
                if (!ids.length) return;
                if (!confirm(labels.confirm + ' ' + ids.length + ' ' + labels.products + '?')) return;

                trigger.disabled = true;
                Kohl.toast({title: labels.working, duration: 2000});

                fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrf ? csrf.getAttribute('content') : ''
                    },
                    body: JSON.stringify({ids: ids, action: trigger.getAttribute('data-k-bulk-action')})
                })
                    .then(function (response) { return response.json(); })
                    .then(function (body) {
                        var skipped = body.skipped || [];
                        Kohl.toast({
                            title: body.message || labels.failed,
                            text: skipped.length
                                ? labels.skipped + ': #' + skipped.map(function (s) { return s.id; }).join(', #')
                                : null,
                            tone: body.status === 1 ? (skipped.length ? 'warning' : 'success') : 'danger',
                        });
                        if (body.updated) setTimeout(function () { location.reload(); }, 1200);
                        else trigger.disabled = false;
                    })
                    .catch(function () {
                        Kohl.toast({title: labels.failed, tone: 'danger'});
                        trigger.disabled = false;
                    });
            });
        })();
    </script>
@endpush
