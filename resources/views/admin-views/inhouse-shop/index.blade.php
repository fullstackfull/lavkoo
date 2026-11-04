@extends('layouts.admin.app')

@section('title', translate('Shop_settings'))

@section('content')
    <div class="content container-fluid">

        <div class="row g-3 align-items-center mb-3">
            <div class="col-md-12">
                <h1 class="mb-3 sm-sm-20">
                    {{ translate('Shop_Setup') }}
                </h1>
                @include('admin-views.inhouse-shop._inhouse-shop-menu')
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <form action="{{ route('admin.business-settings.inhouse-shop-temporary-close') }}" method="post"
                    id="shop-availability-status-form" class="no-reload-form">
                    @csrf

                    <div class="row align-items-center">
                        <div class="col-md-8 col-xl-9">
                            <div>
                                <h3 class="fs-18">{{ translate('Store_Availability') }}</h3>
                                <p class="mb-0 fs-12">
                                    {{ translate('disabling_this_option_will_temporarily_close_the_inhouse_shop_on_the_customer_app_and_website') }}
                                </p>
                            </div>
                        </div>
                        <div class="col-md-4 col-xl-3">
                            <div class="mt-3 mt-md-0">
                                <label
                                    class="d-flex justify-content-between align-items-center gap-3 border rounded px-20 py-3 user-select-none">
                                    <span class="fw-medium text-dark">{{ translate('Status') }}</span>
                                    <label class="switcher" for="shop-availability-status">
                                        <input class="switcher_input custom-modal-plugin" type="checkbox" value="1"
                                            name="status" id="shop-availability-status"
                                            {{ $temporaryClose['status'] == 0 ? 'checked' : '' }}
                                            data-modal-type="input-change-form"
                                            data-modal-form="#shop-availability-status-form"
                                            data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/store-temporary-close-off.png') }}"
                                            data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/store-temporary-close-on.png') }}"
                                           data-on-title="{{ translate('Are_you_sure_to_turn_on_the_Store_Availability_status') }}?"
                                           data-off-title="{{ translate('Are_you_sure_to_turn_off_the_Store_Availability_status') }}?"
                                           data-on-message="<p>{{ translate('If_you_turn_on_this_option_your_store_will_be_available_from_the_customer_app_and_website') }}.</p>"
                                           data-off-message="<p>{{ translate('If_you_turn_off_this_option_your_store_will_be_temporarily_close_from_the_customer_app_and_website') }}.</p>"
                                           data-on-button-text="{{ translate('Turn_On') }}"
                                            data-off-button-text="{{ translate('Turn_Off') }}">
                                        <span class="switcher_control"></span>
                                    </label>
                                </label>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="d-flex flex-wrap gap-3 justify-content-between">
                    <div class="d-flex flex-column gap-1">
                        <h2 class="mb-1">{{ translate('shop_details') }}</h2>
                        <p class="fs-12 mb-0">
                            {{ translate('basic_information_about_this_shop_for_your_quick_review') }}
                        </p>
                    </div>
                    <div class="d-flex flex-wrap gap-3">
                        <button class="btn btn-secondary lh-1" data-bs-toggle="offcanvas"
                            data-bs-target="#offcanvasVacationMode">
                            <i class="fi fi-sr-umbrella-beach"></i>
                            {{ translate('Go_to_Vacation_Mode') }}
                        </button>
                        <a href="{{ route('admin.business-settings.inhouse-shop') . '?action=edit' }}"
                            class="btn btn-primary lh-1">
                            <i class="fi fi-sr-pen-circle"></i>
                            {{ translate('Edit_Information') }}
                        </a>
                    </div>
                </div>
            </div>
            <div class="card-body d-flex flex-column">

                @if ($vacation['status'] != 1 && $temporaryClose['status'] != 1)
                    <div
                        class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center mb-20">
                        <i class="fi fi-sr-info text-info"></i>
                        <span>
                            {{ translate('Your_shop_is_now_on_live_status.') }}
                            {{ translate('All_functions_are_work_properly.') }}
                            {{ translate('To_turn_vacation_mode_click') }} <b>{{ translate('go_vacation_mode') }}</b>
                            {{ translate('button') }}
                        </span>

                    </div>
                @endif

                @if ($temporaryClose['status'] == 1)
                    <div
                        class="bg-warning bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center mb-20">
                        <i class="fi fi-sr-info text-warning"></i>
                        <span>
                            {{ translate('your_shop_is_not_live_now.') }}
                            {{ translate('shop_availability_status_switch_is_turned_off.') }}
                        </span>
                    </div>
                @endif

                @if (
                    $vacation['status'] == 1 &&
                        date('Y-m-d') >= $vacation['vacation_start_date'] &&
                        date('Y-m-d') <= $vacation['vacation_end_date']
                )
                    <div
                        class="bg-danger bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center mb-20">
                        <i class="fi fi-sr-info text-danger"></i>
                        <span>
                            {{ translate('your_shop_is_now_vacation_mode_from') }} <span
                                class="text-danger fw-semibold">{{ Carbon\Carbon::parse($vacation['vacation_start_date'])?->format('d M Y') }}
                                - {{ Carbon\Carbon::parse($vacation['vacation_end_date'])?->format('d M Y') }}.
                            </span> {{ translate('to_turn_off_vacation_mode_click') }} <strong>
                                {{ translate('Go_Vacation_Mode') }}</strong>
                            {{ translate('_button_and_turn_the_status_switch_off.') }}
                        </span>
                    </div>
                @endif

                <div class="position-relative">
                    <div class="cover-img h-550 h-md-350">
                        <img class="w-100 h-100 object-fit-cover rounded-10" alt=""
                            src="{{ getStorageImages(path: $shop?->banner_full_url, type:'backend-banner', source: dynamicAsset('public/assets/new/back-end/img/inhouse-banner.webp')) }}">
                    </div>


                    <div
                        class="d-flex align-items-start flex-wrap column-gap-4 px-2 px-sm-20 bg-blur position-absolute bottom-0 start-0 w-100 z-1">
                        @if($shop->image == 'def.png')
                        <div class="position-relative z-1">
                            <img height="120" width="120" class="shadow-lg rounded mt-n10 object-fit-cover bg-white mt-n50"
                                src="{{ dynamicAsset(path: 'public/assets/back-end/img/shop.png') }}"
                                alt="">
                        </div>
                        @else
                         <div class="position-relative z-1">
                            <img height="120" width="120" class="shadow-lg rounded mt-n10 object-fit-cover bg-white mt-n50"
                                src="{{ getStorageImages(path: getInHouseShopConfig(key: 'image_full_url'), type: 'shop') }}"
                                alt="">
                        </div>
                    @endif
                        <div class="flex-grow-1 py-4">
                            <div class="d-flex flex-wrap gap-3 justify-content-between mb-4">
                                <div>
                                    <h3 class="text-capitalize">
                                        {{ getInHouseShopConfig(key: 'name') }}
                                    </h3>
                                    <p class="fs-12 mb-0">
                                        {{ translate('created_at') }}
                                        {{ date('d M, Y', strtotime($shop['created_at'])) }}
                                    </p>
                                </div>

                                <a class="btn btn-outline-primary text-capitalize" target="_blank"
                                    href="{{ route('vendor-shop', ['slug'=> getInHouseShopConfig(key:'slug')]) }}">
                                    {{ translate('Visit_Shop') }}
                                    <i class="fi fi-rr-exit"></i>
                                </a>
                            </div>

                            <div class="row gy-1">
                                <div class="col-md-4 col-xl-3">
                                    <div class="media gap-10">
                                        <img width="36" height="36"
                                            src="{{ dynamicAsset(path: 'public/assets/back-end/img/svg/tp.svg') }}"
                                            alt="">
                                        <div class="media-body">
                                            <h5>{{ translate('Total_Products') }} </h5>
                                            <h6>{{ $totalProducts }}</h6>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 col-xl-3">
                                    <div class="media gap-10">
                                        <img width="36" height="36"
                                            src="{{ dynamicAsset(path: 'public/assets/back-end/img/svg/to.svg') }}"
                                            alt="">
                                        <div class="media-body">
                                            <h5>{{ translate('Total_Orders') }} </h5>
                                            <h6>{{ $totalOrders }}</h6>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 col-xl-3">
                                    <div class="media gap-10">
                                        <img width="36" height="36"
                                            src="{{ dynamicAsset(path: 'public/assets/back-end/img/svg/tr.svg') }}"
                                            alt="">
                                        <div class="media-body">
                                            <h5>{{ translate('Total_Reviews') }} </h5>
                                            <h6>{{ $totalReviews }}</h6>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        @include('admin-views.inhouse-shop._vacation-mode-offcanvas')

    </div>

    @include('layouts.admin.partials.offcanvas._inhouse-shop-setup')
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/backend/admin/js/business-settings/inhouse-shop.js') }}"></script>
@endpush
