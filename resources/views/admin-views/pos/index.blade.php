@extends('layouts.admin.app')

@section('title', translate('POS'))
@push('css_or_js')
    <meta name="csrf-token" content="{{ csrf_token() }}" />
@endpush
@section('content')
    <div class="content container-fluid">
        <div class="row mt-2">
            <div class="col-lg-7 mb-4 mb-lg-0">
                <div class="card card-body">
                    <div class="d-flex flex-wrap justify-content-end gap-3 mb-4">
                        <div class="flex-grow-1">
                            <form action="{{ route('admin.pos.index') }}" method="get">
                                @if(request()->query('product_type') && request()->query('product_type') == 'digital')
                                    <input type="hidden" name="product_type" value="digital">
                                @endif
                                <div class="search-with-icon flex-grow-1 position-relative">
                                    <div class="input-group position-relative rounded overflow-hidden">
                                        <input id="search" autocomplete="off" type="text"
                                                value="{{ $searchValue }}"
                                                name="searchValue" class="form-control rounded search-bar-input"
                                                placeholder="{{ translate('search_by_name_or_sku') }}"
                                                aria-label="Search here">
                                        <div class="input-group-append search-submit">
                                            <a href="{{route('admin.pos.index')}}" class="search-cross d-none px-2 text-dark fs-10">
                                                <i class="fi fi-rr-cross"></i>
                                            </a>
                                            <button type="submit">
                                                <i class="fi fi-rr-search"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <diV class="card pos-search-card position-absolute z-9 w-100 top-45px">
                                        <div id="pos-search-box"
                                                class="card-body search-result-box d-none"></div>
                                    </diV>
                                </div>
                            </form>
                        </div>

                        <div class="position-relative">
                            <buton type="btn"
                                   @if(!empty(request('filter_sort_by')) || !empty(request('filter_product_types')) || !empty(request('product_status')) || !empty(request('filter_shop_ids')) || !empty(request('filter_brand_ids')) || !empty(request('filter_category_ids')))
                                       class="btn btn-primary px-4"
                                   @else
                                       class="btn btn-outline-primary px-4"
                                   @endif
                                   data-bs-toggle="offcanvas" data-bs-target="#offcanvasPosFilter">
                                <i class="fi fi-sr-settings-sliders d-flex"></i> {{ translate('Filter') }}
                            </buton>
                            @if(!empty(request('filter_sort_by')) || !empty(request('filter_product_types')) || !empty(request('product_status')) || !empty(request('filter_shop_ids')) || !empty(request('filter_brand_ids')) || !empty(request('filter_category_ids')))
                                <div class="position-absolute top-n1 inset-inline-end-n1 btn-circle bg-danger border border-white border-2" style="--size: 12px;"></div>
                            @endif
                        </div>

                    </div>
                    <div class="position-relative nav--tab-wrapper mb-3">
                        <ul class="nav nav-pills nav--tab lang_tab gap-3">
                            <li class="nav-item p-0">
                                <a class="nav-link {{ $productType != 'digital' ? 'active' : '' }}"
                                   href="{{ url()->current() }}?product_type=physical">
                                    {{ translate('Physical_Products') }}
                                </a>
                            </li>
                            <li class="nav-item p-0">
                                <a class="nav-link {{ $productType == 'digital' ? 'active' : '' }}"
                                   href="{{ url()->current() }}?product_type=digital">
                                    {{ translate('Digital_Products') }}
                                </a>
                            </li>
                        </ul>
                    </div>
                    <!-- Tab Content -->
                    <div class="card-body pt-2 pb-80 px-2 overflow-hidden" id="items">
                        @if(count($products) > 0)
                            <div class="pos-item-wrap max-h-100vh-350px">
                                @foreach($products as $product)
                                    @include('admin-views.pos.partials._single-product',['product'=>$product])
                                @endforeach
                            </div>
                        @else
                            <div class="p-4 bg-chat rounded text-center">
                                <div class="py-5">
                                    <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/empty-product.png') }}" width="64" alt="">
                                    <div class="mx-auto my-3 max-w-353px">
                                        {{ translate('No_product_found') }}
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                    <div class="table-responsive bottom-absolute-buttons shadow-toast">
                        <div class="d-flex justify-content-lg-end">
                            {!! $products->withQueryString()->links() !!}
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card billing-section-wrap overflow-hidden">
                    <div class="card-header border-0 bg-section2 p-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h3 class="px-3 m-0">{{ translate('Billing_Section') }}</h3>
                        <button type="button" class="btn text-primary bg-white border-0 d-flex align-items-center justify-content-center gap-2 action-view-all-hold-orders"
                        data-bs-toggle="tooltip" data-bs-title="{{ translate('please_resume_the_order_from_here') }}">
                            <span class="fw-medium">{{ translate('view_All_Hold_Orders') }}</span>
                            <span class="total_hold_orders btn bg-danger text-white fw-medium h-25px p-1 btn-circle" style="--size: 25px;">
                                {{ $totalHoldOrder}}
                            </span>
                        </button>
                    </div>
                    <div class="card-body d-flex flex-column gap-20 overflow-y-auto">
                        <div>
                            @php
                                $userId = 0;
                                if (Illuminate\Support\Str::contains(session('current_user'), 'saved-customer')) {
                                    $userId = explode('-', session('current_user'))[2];
                                }
                            @endphp

                            <div class="position-relative custom_dropdown_wrapper">
                                <div class="form-control custom_dropdown_toggle d-flex gap-2 justify-content-between align-items-center min-h-40 h-auto overflow-wrap-anywhere">
                                    <span class="selected_text">
                                        {{ $userId == 0 ? translate('Walk In Customer') : ($customers->firstWhere('id', $userId)->f_name ?? '') . ' ' . ($customers->firstWhere('id', $userId)->l_name ?? '') }}
                                    </span>
                                    <i class="fi fi-sr-angle-down fs-12"></i>
                                </div>
                                <div class="custom_dropdown_menu">
                                    <div class="card card-body p-3 shadow-popup">
                                        <div class="d-flex flex-column gap-2">
                                            <div class="position-relative d-flex form-control align-items-center gap-2 bg-section">
                                                <i class="fi fi-rr-search fs-16"></i>
                                                <input type="search" class="w-100 border-0 outline-none bg-transparent custom_dropdown_search" placeholder="{{ translate('Search_name_or_phone_number') }}">
                                            </div>
                                            <a class="text-primary fw-medium text-underline text-capitalize text-end"
                                               id="add_new_customer"
                                               data-bs-toggle="offcanvas"
                                               href="#offcanvasAddNewCustomer"
                                               role="button"
                                               aria-controls="offcanvasAddNewCustomer"
                                               title="{{ translate('add_new_customer') }}">
                                                + {{ translate('add_New_Customer') }}
                                            </a>

                                            <div class="custom_dropdown_list">
                                                <div class="custom_dropdown_item action-customer-change fs-12 fw-medium" data-id="0">
                                                    {{ translate('Walk-In-Customer') }}
                                                </div>
                                                @foreach ($customers as $customer)
                                                    <div class="custom_dropdown_item action-customer-change fs-12 fw-medium" data-id="{{ $customer->id }}">
                                                        {{ $customer->f_name }} {{ $customer->l_name }}
                                                        ({{ config('app.mode') != 'demo' ? $customer->phone : '+88017'.rand(111, 999).'XXXXX' }})
                                                    </div>
                                                @endforeach
                                            </div>
                                            <div class="bg-section p-5 rounded custom_dropdown_empty">
                                                <div class="d-flex justify-content-center align-items-center">{{ translate('No_customer_found') }}</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" name="customer_id" id="customer_id" value="{{ $userId }}">
                            @include('admin-views.pos.partials._cart-summary')
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<div class="modal fade pt-5" id="quick-view" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" id="quick-view-modal"></div>
    </div>
</div>

<button class="d-none" id="hold-orders-modal-btn" type="button" data-bs-toggle="modal" data-bs-target="#hold-orders-modal">
</button>

@if($order)
    @include('admin-views.pos.partials.offcanvas._print-invoice-offcanvas')
@endif

@include('admin-views.pos.partials.modals._add-customer')
@include('admin-views.pos.partials.modals._hold-orders-modal')
@include('admin-views.pos.partials.modals._add-coupon-discount')
@include('admin-views.pos.partials.modals._add-discount')
@include('admin-views.pos.partials.modals._short-cut-keys')

@include('admin-views.pos.partials.offcanvas._filter-offcanvas')
@include('admin-views.pos.partials.offcanvas._add-new-customer-offcanvas')

<span id="route-admin-pos-get-cart-ids" data-url="{{ route('admin.pos.get-cart-ids') }}"></span>
<span id="route-admin-pos-new-cart-id" data-url="{{ route('admin.pos.new-cart-id') }}"></span>
<span id="route-admin-pos-clear-cart-ids" data-url="{{ route('admin.pos.clear-cart-ids') }}"></span>
<span id="route-admin-pos-view-hold-orders" data-url="{{ route('admin.pos.view-hold-orders') }}"></span>
<span id="route-admin-products-search-product" data-url="{{ route('admin.pos.search-product') }}"></span>
<span id="route-admin-pos-change-customer" data-url="{{ route('admin.pos.change-customer') }}"></span>
<span id="route-admin-pos-update-discount" data-url="{{ route('admin.pos.update-discount') }}"></span>
<span id="route-admin-pos-coupon-discount" data-url="{{ route('admin.pos.coupon-discount') }}"></span>
<span id="route-admin-pos-cancel-order" data-url="{{ route('admin.pos.cancel-order') }}"></span>
<span id="route-admin-pos-quick-view" data-url="{{ route('admin.pos.quick-view') }}"></span>
<span id="route-admin-pos-add-to-cart" data-url="{{ route('admin.pos.add-to-cart') }}"></span>
<span id="route-admin-pos-remove-cart" data-url="{{ route('admin.pos.remove-cart') }}"></span>
<span id="route-admin-pos-empty-cart" data-url="{{ route('admin.pos.empty-cart') }}"></span>
<span id="route-admin-pos-update-quantity" data-url="{{ route('admin.pos.update-quantity') }}"></span>
<span id="route-admin-pos-get-variant-price" data-url="{{ route('admin.pos.get-variant-price') }}"></span>
<span id="route-admin-pos-change-cart-editable" data-url="{{ route('admin.pos.change-cart').'/?cart_id=:value' }}"></span>

<span id="message-cart-word" data-text="{{ translate('cart') }}"></span>
<span id="message-stock-out" data-text="{{ translate('stock_out') }}"></span>
<span id="message-stock-id" data-text="{{ translate('in_stock') }}"></span>
<span id="message-add-to-cart" data-text="{{ translate('add_to_cart') }}"></span>
<span id="message-cart-updated" data-text="{{ translate('cart_updated') }}"></span>
<span id="message-update-to-cart" data-text="{{ translate('update_to_cart') }}"></span>
<span id="message-cart-is-empty" data-text="{{ translate('cart_is_empty') }}"></span>
<span id="message-enter-valid-amount" data-text="{{ translate('please_enter_a_valid_amount') }}"></span>
<span id="message-less-than-total-amount" data-text="{{ translate('paid_amount_is_less_than_total_amount') }}"></span>
<span id="message-coupon-is-invalid" data-text="{{ translate('coupon_is_invalid') }}"></span>
<span id="message-product-quantity-updated" data-text="{{ translate('product_quantity_updated') }}"></span>
<span id="message-coupon-added-successfully" data-text="{{ translate('coupon_added_successfully') }}"></span>
<span id="message-sorry-stock-limit-exceeded" data-text="{{ translate('sorry_stock_limit_exceeded') }}"></span>
<span id="message-please-choose-all-the-options" data-text="{{ translate('please_choose_all_the_options') }}"></span>
<span id="message-item-has-been-removed-from-cart" data-text="{{ translate('item_has_been_removed_from_cart') }}"></span>
<span id="message-you-want-to-remove-all-items-from-cart" data-text="{{ translate('you_want_to_remove_all_items_from_cart') }}"></span>
<span id="message-you-want-to-create-new-order" data-text="{{ translate('Want_to_create_new_order_for_another_customer') }}"></span>
<span id="message-product-quantity-is-not-enough" data-text="{{ translate('product_quantity_is_not_enough') }}"></span>
<span id="message-sorry-product-is-out-of-stock" data-text="{{ translate('sorry_product_is_out_of_stock') }}"></span>
<span id="message-item-has-been-added-in-your-cart" data-text="{{ translate('item_has_been_added_in_your_cart') }}"></span>
<span id="message-extra-discount-added-successfully" data-text="{{ translate('extra_discount_added_successfully') }}"></span>
<span id="message-amount-can-not-be-negative-or-zero" data-text="{{ translate('amount_can_not_be_negative_or_zero') }}"></span>
<span id="message-sorry-the-minimum-value-was-reached" data-text="{{ translate('sorry_the_minimum_value_was_reached') }}"></span>
<span id="message-this-discount-is-not-applied-for-this-amount" data-text="{{ translate('this_discount_is_not_applied_for_this_amount') }}"></span>
<span id="message-please-add-product-in-cart-before-applying-discount" data-text="{{ translate('please_add_product_to_cart_before_applying_discount') }}"></span>
<span id="message-please-add-product-in-cart-before-applying-coupon" data-text="{{ translate('please_add_product_to_cart_before_applying_coupon') }}"></span>
<span id="message-product-quantity-cannot-be-zero-in-cart" data-text="{{ translate('product_quantity_can_not_be_zero_or_less_than_zero_in_cart') }}"></span>
<span id="message-product-quantity-cannot-be-less-then-one" data-text="{{ translate('product_quantity_can_not_be_less_then_one') }}"></span>

@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/libs/printThis/printThis.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/admin/pos-script.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/backend/libs/easyzoom/easyzoom.min.js') }}"></script>
    <script>
        "use strict";
        document.addEventListener('DOMContentLoaded', function () {
            @if($order)
            const offcanvasElement = document.getElementById('print-invoice');
            if (offcanvasElement) {
                const offcanvasInstance = new bootstrap.Offcanvas(offcanvasElement);
                offcanvasInstance.show();
            }
            @endif

            function initPosTooltips() {
                document.querySelectorAll('#cart-summary [data-bs-toggle="tooltip"]').forEach(function (el) {
                    var existing = bootstrap.Tooltip.getInstance(el);
                    if (existing) existing.dispose();
                    new bootstrap.Tooltip(el, { trigger: 'hover' });
                });
            }

            initPosTooltips();

            document.addEventListener('click', function (e) {
                var el = e.target.closest('#cart-summary [data-bs-toggle="tooltip"]');
                if (el) {
                    var instance = bootstrap.Tooltip.getInstance(el);
                    if (instance) instance.hide();
                }
            });

            var cartSummary = document.getElementById('cart-summary');
            if (cartSummary) {
                new MutationObserver(initPosTooltips).observe(cartSummary, { childList: true });
            }
        });
    </script>
    <script>
        $(document).ready(function() {
            updateProductCounts(@json($productCounts ?? []));
        });
    </script>

    <script>
        let popupHideTimeout;
        let trackingInterval;

        $(document).on('mouseenter', '.table-items', function () {
            const $popup = $(this).find('.table-items-popup');
            const $item = $(this)[0];

            $('.table-items-popup').not($popup).removeClass('show');
            clearTimeout(popupHideTimeout);
            $popup.addClass('show');

            const updatePopupPosition = () => {
                const rect = $item.getBoundingClientRect();
                $popup.css({
                    top: rect.top + rect.height + 5 + 'px',
                    left: rect.left + (rect.width / 2) - ($popup.outerWidth() / 2) + 'px'
                });
            };

            updatePopupPosition();
            trackingInterval = setInterval(updatePopupPosition, 30);
        });

        $(document).on('mouseleave', '.table-items', function () {
            const $popup = $(this).find('.table-items-popup');
            popupHideTimeout = setTimeout(() => {
                $popup.removeClass('show');
                clearInterval(trackingInterval);
            }, 100);
        });

        $(document).on('mouseenter', '.table-items-popup', function () {
            clearTimeout(popupHideTimeout);
        });

        $(document).on('mouseleave', '.table-items-popup', function () {
            const $popup = $(this);
            popupHideTimeout = setTimeout(() => {
                $popup.removeClass('show');
                clearInterval(trackingInterval);
            }, 100);
        });
    </script>
@endpush
