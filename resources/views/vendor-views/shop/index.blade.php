@extends('layouts.vendor.app')

@section('title', translate('Shop_Settings'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
            <div class="">
                <h2 class="h1 mb-0 text-capitalize d-flex">{{ translate('Shop_Settings') }}</h2>
                @include('vendor-views.shop.inline-menu')
            </div>

            <div class="d-flex gap-2 alert alert-soft-warning max-w-500" role="alert">
                <i class="fi fi-sr-lightbulb-on"></i>
                <p class="fs-12 mb-0 text-dark">
                    {{ translate('here_you_can_setup_you_shop_decoration_and_basic_information.') }}
                    {{ translate('to_create_new_product_got_to_product_add') }}
                    <a href="{{ route('vendor.products.add') }}" class="text-underline font-weight-bold"
                       target="_blank">
                        {{ translate('New_Product') }}
                    </a>
                </p>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <div class="row justify-content-between align-items-center">
                    <div class="col-md-6">
                        <h3>{{ translate('Store_Availability') }}</h3>
                        <p class="fs-12">
                            {{ translate('disabling_this_option_will_temporarily_close_the_shop_on_the_customer_app_and_website') }}
                        </p>
                    </div>
                    <div class="col-md-4">
                        <form action="{{ route('vendor.shop.close-shop-temporary') }}" method="POST"
                              id="vendor-temporary-close-form">
                            @csrf
                            <div
                                class="border rounded border-color-c1 px-4 py-3 d-flex justify-content-between gap-3 mb-1 ">
                                <h5 class="mb-0 d-flex gap-1 c1">
                                    {{ translate('status') }}
                                </h5>
                                <input type="hidden" name="id" value="{{ $shop->id }}">
                                <div class="position-relative">
                                    <label class="switcher">
                                        <input type="checkbox" class="switcher_input custom-modal-plugin"
                                               name="status" value="1"
                                               {{ !isset($shop->temporary_close) || $shop->temporary_close != 1 ? 'checked' : '' }}
                                               data-modal-type="input-change-form"
                                               data-modal-form="#vendor-temporary-close-form"
                                               data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/store-temporary-close-off.png') }}"
                                               data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/store-temporary-close-on.png') }}"
                                               data-on-title="{{ translate('want_to_disable_the_Temporary_Close').'?' }}"
                                               data-off-title="{{ translate('want_to_enable_the_Temporary_Close').'?' }}"
                                               data-on-message="<p>{{ translate('if_you_disable_this_option_your_shop_will_be_open_in_the_user_app_and_website_and_customers_can_add_products_from_your_shop') }}</p>"
                                               data-off-message="<p>{{ translate('if_you_enable_this_option_your_shop_will_be_shown_as_temporarily_closed_in_the_user_app_and_website_and_customers_cannot_add_products_from_your_shop') }}</p>">
                                        <span class="switcher_control"></span>
                                    </label>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header flex-wrap gap-3">
                <div>
                    <h3 class="text-capitalize">{{ translate('shop_Details') }}</h3>
                    <p class="fs-12 mb-0">
                        {{ translate('basic_information_about_this_shop_for_your_quick_review') }}
                    </p>
                </div>

                <div class="d-inline-flex gap-3 flex-wrap">
                    <button class="btn btn-outline-primary text-capitalize" data-toggle="offcanvas"
                            data-target=".offcanvas-vacation-mode">
                        <i class="fi fi-sr-umbrella-beach"></i>
                        {{ translate('vacation_mode') }}
                    </button>

                    <a class="btn btn-primary px-4 text-white" href="{{ route('vendor.shop.update', [$shop->id]) }}">
                        <i class="fi fi-sr-pen-circle"></i>
                        {{ translate('Edit_Information') }}
                    </a>
                </div>
            </div>

            <div class="card-body">

                @if($shop->vacation_status != 1 && $shop?->temporary_close != 1)
                    <div class="d-flex gap-2 alert alert-soft-warning" role="alert">
                        <i class="fi fi-sr-info"></i>
                        <p class="fs-12 mb-0 text-dark">
                            {{ translate('Your_shop_is_now_on_live_status.') }}
                            {{ translate('All_functions_are_work_properly.') }}
                            {{ translate('To_turn_vacation_mode_click_go_vacation_mode_button.') }}
                        </p>
                    </div>
                @endif

                @if($shop?->temporary_close == 1)
                    <div class="d-flex gap-2 alert alert-soft-warning" role="alert">
                        <i class="fi fi-sr-info"></i>
                        <p class="fs-12 mb-0 text-dark">
                            {{ translate('your_shop_is_not_live_now.') }}
                            {{ translate('shop_availability_status_switch_is_turned_off.') }}
                        </p>
                    </div>
                @endif

                @if($shop->vacation_status == 1 && date('Y-m-d') >= $shop->vacation_start_date && date('Y-m-d') <= $shop->vacation_end_date)
                    <div class="d-flex gap-2 alert alert-soft-danger" role="alert">
                        <i class="fi fi-sr-info"></i>
                        <p class="fs-12 mb-0 text-dark">
                            <span>
                                {{ translate('your_shop_is_now_vacation_mode_from') }}
                                    <span class="text-danger font-weight-bold">
                                        {{ Carbon\Carbon::parse($shop->vacation_start_date)?->format('d M Y') }}
                                        - {{ Carbon\Carbon::parse($shop->vacation_end_date)?->format('d M Y') }}.
                                    </span>
                                    {{ translate('to_turn_off_vacation_mode_click')}}
                                    <strong>{{ translate('Go_Vacation_Mode') }}</strong>
                                {{ translate('_button_and_turn_the_status_switch_off.') }}
                            </span>
                        </p>
                    </div>
                @endif

                <div class="position-relative">
                    <div class="cover-img h-550 h-md-350">
                        <img class="w-100 h-100 object-fit-cover rounded-10" alt=""
                             src="{{ getStorageImages(path: $shop->banner_full_url, type:'backend-banner', source: dynamicAsset('public/assets/new/back-end/img/inhouse-banner.webp')) }}">
                    </div>

                    <div class="d-flex align-items-start column-gap-4 px-2 px-sm-4 bg-blur position-absolute bottom-0 start-0 w-100 z-1">
                        @if($shop->image == 'def.png')
                            <div class="position-relative z-1 h-120 aspect-1 d-none d-lg-block">
                                <img class="img-fluid shadow-lg rounded mt-n8 p-2"
                                    src="{{ dynamicAsset(path: 'public/assets/back-end/img/shop.png') }}" alt="">
                            </div>
                        @else
                            <div class="position-relative z-1 h-120 aspect-1 d-none d-lg-block overflow-hidden">
                                <img class="aspect-1 bg-white img-fluid overflow-hidden p-2 rounded shadow-lg"
                                    src="{{ getStorageImages(path: $shop->image_full_url,type: 'backend-basic') }}" alt="">
                            </div>
                        @endif

                        <div class="flex-grow-1 py-2 py-lg-4">
                            <div class="d-flex flex-wrap gap-3 justify-content-between align-items-center mb-4">
                                <div class="d-flex align-items-center gap-2">
                                    @if($shop->image == 'def.png')
                                        <div class="position-relative z-1 w-80px aspect-1 mt-n5 d-block d-lg-none">
                                            <img class="img-fluid shadow-lg rounded bg-white p-1"
                                                src=""{{ dynamicAsset(path: 'public/assets/back-end/img/shop.png') }}" alt="">
                                        </div>
                                    @else
                                        <div class="position-relative z-1 w-80px aspect-1 mt-n5 d-block d-lg-none">
                                            <img class="img-fluid shadow-lg rounded bg-white p-1"
                                                src="{{ getStorageImages(path: $shop->image_full_url,type: 'backend-basic') }}" alt="">
                                        </div>
                                    @endif
                                    <div class="flex-grow-1">
                                        <h3 class="text-capitalize fs-14-mobile">{{ $shop['name'] }}</h3>
                                        <p class="fs-12 mb-0">
                                            {{ translate('created_at') }} {{ date('d M, Y', strtotime($shop['created_at'] ?? $shop['updated_at'])) }}
                                        </p>
                                    </div>
                                </div>
                                <a class="btn btn-outline-primary text-capitalize" target="_blank"
                                href="{{ route('vendor-shop',['slug' => $shop['slug']]) }}">
                                    {{ translate('Visit_Website') }}
                                    <i class="fi fi-rr-exit"></i>
                                </a>
                            </div>

                            <div class="row gy-1">
                                <div class="col-sm-6 col-lg-4 col-xl-3">
                                    <div class="media gap-10">
                                        <img width="36" height="36"
                                            src="{{ dynamicAsset(path: 'public/assets/back-end/img/svg/tp.svg') }}" alt="">
                                        <div class="media-body">
                                            <h5>{{ translate('Total_Products') }} </h5>
                                            <h6>{{ $totalProducts }}</h6>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6 col-lg-4 col-xl-3">
                                    <div class="media gap-10">
                                        <img width="36" height="36"
                                            src="{{ dynamicAsset(path: 'public/assets/back-end/img/svg/to.svg') }}" alt="">
                                        <div class="media-body">
                                            <h5>{{ translate('Total_Orders') }} </h5>
                                            <h6>{{ $totalOrders }}</h6>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6 col-lg-4 col-xl-3">
                                    <div class="media gap-10">
                                        <img width="36" height="36"
                                            src="{{ dynamicAsset(path: 'public/assets/back-end/img/svg/tr.svg') }}" alt="">
                                        <div class="media-body">
                                            <h5>{{ translate('Total_Reviews') }} </h5>
                                            <h6>{{ $totalReviews }}</h6>
                                        </div>
                                    </div>
                                </div>
                                @if($shop?->tax_identification_number && $shop?->tin_expire_date)
                                    <div class="col-sm-6 col-lg-4 col-xl-3">
                                        <div class="media gap-10">
                                            <img width="36" height="36"
                                                 src="{{ dynamicAsset(path: 'public/assets/back-end/img/svg/tin-icon.svg') }}" alt="">
                                            <div class="media-body">
                                                <h5>
                                                    {{ translate('TIN') }}: {{ $shop?->tax_identification_number }}
                                                </h5>
                                                <h6 class="opacity--80">
                                                    {{ translate('Exp') }} : {{ $shop?->tin_expire_date->format('d M Y') }}
                                                </h6>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @include("vendor-views.shop.partials._vacation-mode-offcanvas")
        @include("layouts.vendor.partials.offcanvas._shop_settings")
    </div>
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/backend/vendor/js/business-settings/shop-settings.js') }}"></script>
@endpush
