@extends('layouts.vendor.app')

@section('title', translate('Payment_Information'))

@push('css_or_js')

    <link rel="stylesheet"
        href="{{ dynamicAsset(path: 'public/assets/back-end/plugins/intl-tel-input/css/intlTelInput.css') }}">
@endpush

@section('content')
    <div class="content container-fluid">
        <h2 class="h1 mb-0 text-capitalize d-flex mb-3">{{ translate('Payment_Information') }}</h2>

        @include('vendor-views.shop.inline-menu')

        <div class="card card-body mb-3">
            <h3>{{ translate('payment_information') }}</h3>
            <p class="fs-12">
                {{ translate('in_this_page_you_can_add,_edit_or_delete_you_payment_information_for_withdraw_your_earning_amount') }}
            </p>

            <div class="d-flex gap-2 alert alert-soft-warning mb-0" role="alert">
                <i class="fi fi-sr-info"></i>
                <p class="fs-12 mb-0 text-dark">
                    {{ translate('here_you_can_setup_you_shop_decoration_and_basic_information.') }}
                    {{ translate('to_create_new_product_got_to_product_add') }}
                    <a href="{{ route('vendor.products.add') }}" class="text-underline font-weight-bold">
                        {{ translate('New Product') }}
                    </a>
                </p>
            </div>
        </div>

        <div class="card">
            <div class="card-body dynamic_dropdown_parent">
                <div class="p-3">
                    <div class="d-flex flex-wrap gy-1 align-items-center justify-content-between">
                        <h4 class="text-capitalize">{{ translate('payment_method_list') }}</h4>

                        <div class="d-flex flex-wrap gap-3">
                            <form action="{{ url('vendor/shop/payment-information') }}" method="GET">
                                @csrf
                                <div class="input-group">
                                    <input type="search" name="search" value="{{ requestString('search') }}"
                                        class="form-control" placeholder="Search By Method Name">
                                    <div class="input-group-append">
                                        <button type="submit" class="input-group-text bg-light">
                                            <i class="fi fi-rr-search"></i>
                                        </button>
                                    </div>
                                </div>
                            </form>
                            <button type="button" class="btn btn--primary text-nowrap" data-toggle="offcanvas"
                                data-target=".add-payment-offcanvas">
                                <i class="fi fi-sr-add mt-1"></i>
                                {{ translate('add_payment_info') }}
                            </button>

                        </div>
                    </div>
                </div>


                <div class="table-responsive">
                    <table id="datatable"
                        class="k-table">
                        <thead>
                            <tr>
                                <th>{{ translate('SL') }}</th>
                                <th>{{ translate('method_name') }}</th>
                                <th>{{ translate('withdraw_method') }}</th>
                                <th>{{ translate('payment_info') }}</th>
                                <th>{{ translate('status') }}</th>
                                <th class="text-center">{{ translate('action') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($vendorWithdrawMethods as $key => $method)
                                <tr>
                                    <td>{{ $key + 1 }}</td>
                                    <td>
                                        <div class="d-flex g-2 align-items-center">
                                            <span class="max-w-150px text-truncate d-block">
                                                {{ $method->method_name ?? 'N/A' }}
                                            </span>
                                            @if ($method->is_default == 1)
                                                <small class="badge--primary-2 p-1">{{ translate('Default') }}</small>
                                            @endif
                                        </div>
                                    </td>

                                    <td>{{ $method->withdraw_method->method_name ?? 'N/A' }}</td>
                                    <td>
                                        <table class="k-table" role="presentation">
                                            <tbody>
                                            @foreach ($method->method_info as $label => $info)
                                                <tr class="align-items-start bg-transparent d-flex">
                                                    <td class="p-1">
                                                        {{ Str::of($label)->replace('_', ' ')->title() }}</td>
                                                    <td class="p-1 d-flex d-flex align-items-start gap-2">:
                                                        <span class="d-block max-w-360px text-wrap">
                                                            {{ $info }}
                                                        </span>
                                                    </td>
                                                </tr>
                                            @endforeach
                                            </tbody>
                                        </table>
                                    </td>
                                    <td>
                                        <form action="{{ route('vendor.shop.payment-information.update-status') }}"
                                            method="post" enctype="multipart/form-data"
                                            id="status-change-form-{{ $method->id }}">
                                            @csrf
                                            <input name="id" type="hidden" value="{{ $method->id }}">
                                            <label class="switcher"
                                                style="{{ $method->is_default == 1 ? 'opacity: 0.5; pointer-events: none;' : '' }}">
                                                <input type="checkbox" value="1" name="status"
                                                    class="switcher_input custom-modal-plugin"
                                                    data-id="{{ $method->id }}"
                                                    {{ $method->is_active ? 'checked' : '' }}
                                                    {{ $method->is_default == 1 ? 'disabled' : '' }}
                                                    data-modal-type="input-change-form"
                                                    data-modal-form="#status-change-form-{{ $method->id }}"
                                                    data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/product-status-on.png') }}"
                                                    data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/product-status-off.png') }}"
                                                    data-on-title="{{ translate('Want_to_activate') . ' ' . translate($method->method_name) }}"
                                                    data-off-title="{{ translate('Want_to_deactivate') . ' ' . translate($method->method_name) }}"
                                                    data-on-message="<p>{{ translate('if_enabled,_this_payment_method_will_be_active_and_visible.') }}</p>"
                                                    data-off-message="<p>{{ translate('if_disabled,_this_payment_method_will_be_inactive_and_hidden.') }}</p>">
                                                <span class="switcher_control"></span>
                                            </label>
                                        </form>
                                    </td>

                                    <td>
                                        <div class="d-flex justify-content-center">
                                            <div class="dynamic_dropdown">
                                                <button class="btn btn-outline-primary icon-btn dynamic_dropdown_btn">
                                                    <i class="fi fi-rr-menu-dots-vertical position-relative top-01"></i>
                                                </button>
                                                <div class="bg-white dynamic_dropdown_menu py-2 shadow-lg radius-5">
                                                    @if (!$method['is_default'] === true)
                                                        <button class="dropdown-item d-flex align-items-center gap-2"
                                                            data-toggle="modal"
                                                            data-target="#methodDefaultModal{{ $method['id'] }}">
                                                            <i class="fi fi-rr-check-circle mt-1"></i>
                                                            {{ translate('mark_as_default') }}
                                                        </button>
                                                    @endif
                                                    <button class="dropdown-item d-flex align-items-center gap-2" data-toggle="offcanvas"
                                                        data-target=".edit-payment-offcanvas-{{ $method->id }}">
                                                        <i class="fi fi-rr-pen-circle"></i>
                                                        {{ translate('Edit') }}
                                                    </button>

                                                    @if (!$method['is_default'] === true)
                                                        <button class="dropdown-item d-flex align-items-center gap-2"
                                                            data-toggle="modal"
                                                            data-target="#methodDeleteModal{{ $method['id'] }}">
                                                            <i class="ffi fi-rr-trash"></i>
                                                            {{ translate('Delete') }}
                                                        </button>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach

                        </tbody>
                    </table>
                    <div class="table-responsive mt-4">
                        <div class="px-4 d-flex justify-content-lg-end">
                            {{ $vendorWithdrawMethods->links() }}
                        </div>
                    </div>

                    @if ($vendorWithdrawMethods->isEmpty())
                        <div class="my-5 text-center">
                            <img class="mb-3"
                                src="{{ dynamicAsset(path: 'public/assets/back-end/img/svg/no_payment.svg') }}"
                                alt="">
                            <p class="fs-16 text-muted">{{ translate('no_payment_info_added_yet') }}</p>

                            <button type="button" class="btn btn--primary text-nowrap" data-toggle="offcanvas"
                                data-target=".add-payment-offcanvas">
                                <i class="fi fi-sr-add mt-1"></i>
                                {{ translate('add_payment_info') }}
                            </button>
                        </div>
                    @endif

                    @include('vendor-views.shop.partials._payment-information-modals')

                </div>
            </div>
        </div>

        <div class="add-payment-offcanvas offcanvas-sidebar">
            <div class="offcanvas-overlay" data-dismiss="offcanvas"></div>

            <div class="offcanvas-content bg-white shadow d-flex flex-column">
                <div class="offcanvas-header bg-light d-flex justify-content-between align-items-center p-3">
                    <h3 class="text-capitalize m-0">{{ translate('add_payment_info') }}</h3>
                    <button type="button" class="close" data-dismiss="offcanvas" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>

                <form id="add-payment-info-form" class="d-flex flex-column flex-grow-1"
                    action="{{ route('vendor.shop.payment-information.add') }}">
                    @csrf
                    <div class="offcanvas-body p-3 overflow-auto flex-grow-1">
                        <div class="d-flex gap-2 alert alert-soft-warning mb-3" role="alert">
                            <i class="fi fi-sr-info"></i>
                            <p class="fs-12 mb-0 text-dark">
                                {{ translate('if_you_turn_on_the_status') }}.
                                {{ translate('this_payment_will_show_in_dropdown_list_when_withdraw_request_sent_to_admin.') }}
                            </p>
                        </div>

                        <div class="bg-light p-3 rounded mb-3">
                            <div class="form-group">
                                <label class="form-label text-dark">
                                    {{ translate('method_name') }}
                                    <span class="text-danger">*</span>
                                    <i class="fi fi-sr-info cursor-pointer text-muted" data-toggle="tooltip"
                                        title="{{ translate('Provide_the_method_name_of_this_payment_method') }}"></i>
                                </label>
                                <input type="text" class="form-control" value=""
                                    placeholder="{{ translate('method_name') }}" name="method_name">
                            </div>
                            <div class="form-group">
                                <label class="form-label text-dark">
                                    {{ translate('select_payment_method') }} <span class="text-danger">*</span>
                                </label>
                                <select name="withdraw_method_id" class="form-control payment_method" required>
                                    <option value="">{{ translate('select_payment_method') }}</option>
                                    @foreach ($withdrawalMethods as $method)
                                        <option value="{{ $method->id }}"
                                            {{ $method['is_default'] ? 'selected' : '' }}>
                                            {{ $method?->method_name ?? '' }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="form-group">
                                <div class="border rounded bg-white p-3 d-flex justify-content-between gap-3">
                                    <h5 class="mb-0 d-flex gap-1 c1">
                                        {{ translate('status') }}
                                    </h5>
                                    <label class="switcher">
                                        <input type="checkbox" value="1" name="status" class="switcher_input">
                                        <span class="switcher_control"></span>
                                    </label>
                                </div>
                            </div>

                            <div class="dynamic_fields_wrapper">
                                @foreach ($withdrawalMethods as $method)
                                    @if ($method['is_default'])
                                        @foreach ($method['method_fields'] as $item)
                                            <div class="form-group">
                                                <label class="form-label text-dark">
                                                    {{ translate($item['input_name']) }}
                                                    <span class="text-danger">{{ $item['is_required'] ? '*' : '' }}</span>
                                                </label>
                                                <input type="{{ $item['input_type'] == 'phone' ? 'tel' : $item['input_type'] }}" class="form-control"
                                                    placeholder="{{ translate($item['placeholder']) }}"
                                                    name="method_info[{{ $item['input_name'] }}]"
                                                    {{ $item['is_required'] ? 'required' : '' }}>
                                            </div>
                                        @endforeach
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <div class="offcanvas-footer offcanvas-footer-sticky p-3 border-top bg-white d-flex gap-3">
                        <button type="reset" class="btn btn-secondary w-100 reset">{{ translate('reset') }}</button>
                        <button type="submit" class="btn btn--primary w-100 save-btn"
                            data-form-id="add-payment-info-form">
                            {{ translate('save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <span id="route-shop-payment-information-edit"
        data-route="{{ route('vendor.shop.payment-information.update') }}"></span>
    <span id="route-shop-payment-information-methods"
        data-route="{{ route('vendor.shop.payment-information.dynamic-fields') }}"></span>

    @include('layouts.vendor.partials.offcanvas._payment-information')
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/backend/vendor/js/business-settings/payment-information.js') }}">
    </script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/plugins/intl-tel-input/js/intlTelInput.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/country-picker-init.js') }}"></script>
    <script>
        $(document).ready(function () {
            const $body = $('body');
            const isRTL = $('html').attr('dir') === 'rtl';
            let activeDropdownBtn = null;

            function closeAllDropdowns() {
                const $movedMenu = $('.dynamic_dropdown_menu[data-cloned="true"]');
                if ($movedMenu.length) {
                    const $parent = activeDropdownBtn?.closest('.dynamic_dropdown');
                    if ($parent?.length) {
                        $parent.append($movedMenu.removeAttr('data-cloned').hide());
                    } else {
                        $movedMenu.remove();
                    }
                }
                $('.dynamic_dropdown_btn.active').removeClass('active');
                activeDropdownBtn = null;
            }

            function positionDropdown($btn, $menu) {
                const $scrollContainer = $('.table-responsive');

                const containerOffset = $scrollContainer.offset();
                const containerScrollTop = $scrollContainer.scrollTop();
                const containerScrollLeft = $scrollContainer.scrollLeft();
                const containerHeight = $scrollContainer.height();
                const containerWidth = $scrollContainer.width();

                const btnOffset = $btn.offset();
                const btnHeight = $btn.outerHeight();
                const btnWidth = $btn.outerWidth();

                const relativeTop = btnOffset.top - containerOffset.top + containerScrollTop;
                const relativeLeft = btnOffset.left - containerOffset.left + containerScrollLeft;

                const isVerticallyVisible =
                    relativeTop >= containerScrollTop &&
                    relativeTop <= containerScrollTop + containerHeight - btnHeight;

                const isHorizontallyVisible =
                    relativeLeft >= containerScrollLeft &&
                    relativeLeft <= containerScrollLeft + containerWidth - btnWidth;

                const isFullyVisible = isVerticallyVisible && isHorizontallyVisible;

                if (!isFullyVisible) {
                    $menu.hide();
                    return;
                } else {
                    $menu.show();
                }

                const top = btnOffset.top + btnHeight;
                const windowWidth = $(window).width();

                $menu.css({
                    top: top,
                    left: 'auto',
                    right: 'auto'
                });

                $menu[0].style.removeProperty('inset-inline-end');

                let inlineEnd;
                if (isRTL) {
                    inlineEnd = btnOffset.left;
                } else {
                    const btnRightEdge = btnOffset.left + $btn.outerWidth();
                    inlineEnd = windowWidth - btnRightEdge;
                }

                $menu[0].style.setProperty('inset-inline-end', `${inlineEnd}px`);
            }

            $body.on('click', '.dynamic_dropdown_btn', function (e) {
                e.stopPropagation();
                const $btn = $(this);

                if ($btn.hasClass('active')) {
                    closeAllDropdowns();
                    return;
                }

                closeAllDropdowns();
                $btn.addClass('active');
                activeDropdownBtn = $btn;

                const $menu = $btn.closest('.dynamic_dropdown').find('.dynamic_dropdown_menu').first();
                const $clonedMenu = $menu.detach()
                    .attr('data-cloned', 'true')
                    .css({
                        position: 'absolute',
                        display: 'block',
                        zIndex: 2
                    });

                $body.append($clonedMenu);
                positionDropdown($btn, $clonedMenu);
            });

            function updateDropdownPositionOnScrollOrResize() {
                if (activeDropdownBtn) {
                    const $clonedMenu = $('.dynamic_dropdown_menu[data-cloned="true"]');
                    if ($clonedMenu.length) {
                        positionDropdown(activeDropdownBtn, $clonedMenu);
                    }
                }
            }

            $(window).on('scroll resize', updateDropdownPositionOnScrollOrResize);
            $('.table-responsive').on('scroll', updateDropdownPositionOnScrollOrResize);

            // Close on outside click
            $(document).on('click', function () {
                closeAllDropdowns();
            });

            // Prevent close on clicking inside dropdown
            $body.on('click', '.dynamic_dropdown_menu[data-cloned="true"]', function (e) {
                e.stopPropagation();
            });
           $('body').on('click', '.dynamic_dropdown_menu[data-cloned="true"] .dropdown-item', function (e) {
                e.stopPropagation();

                const $item = $(this);
                const toggle = $item.data('toggle');
                const target = $item.data('target');

                if (!target) return;

                if (toggle === 'modal') {
                    const $modal = $(target);
                    if ($modal.length) {
                        $modal.modal('show');
                    }
                }

                if (toggle === 'offcanvas') {
                    const $offcanvas = $(target);
                    if ($offcanvas.length) {
                       $offcanvas.addClass('show');
                    }
                }

                // close the dropdown after triggering
                closeAllDropdowns();
            });


        });
    </script>

@endpush
