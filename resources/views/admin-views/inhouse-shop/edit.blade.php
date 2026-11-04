@extends('layouts.admin.app')

@section('title', translate('Edit') . ' - ' . translate('inhouse_shop'))

@section('content')
    <div class="content container-fluid">
        <div class="row g-3 align-items-center mb-3">
            <div class="col-md-12">
                <h1 class="mb-3 sm-sm-20">
                    {{ translate('In-house_Shop') }}
                </h1>
                @include('admin-views.inhouse-shop._inhouse-shop-menu')
            </div>
        </div>

        <form action="{{ route('admin.business-settings.inhouse-shop') }}" method="post" enctype="multipart/form-data" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
            @csrf
            <div class="card mb-4">
                <div class="card-header border-0 shadow-sm d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h2>{{ translate('Edit_Shop') }}</h2>
                        <p class="fs-12 mb-0">
                            {{ translate('in_this_page_you_can_setup_your_shop_information.') }}
                            {{ translate('this_information_are_not_related_to_your_business_information.') }}
                        </p>
                    </div>
                    <a href="{{ route('admin.business-settings.inhouse-shop') }}"
                        class="d-flex gap-2 align-items-center fw-medium">
                        <i class="fi fi-rr-arrow-left"></i>
                        {{ translate('back_to_shop_settings') }}
                    </a>
                </div>
                <div class="card-body d-flex flex-column gap-3 gap-sm-20">
                    <div
                        class="bg-warning bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                        <i class="fi fi-sr-info text-warning"></i>
                        <span>
                            {{ translate('After_changes_all_information_make_sure_you_click') }}
                            <b>{{ translate('Save_button.') }}</b>
                            {{ translate('this_setup_not_related_or_overwrite_to_your_business_information.') }}
                        </span>
                    </div>
                    <div class="card card-sm-shadow-2">
                        <div class="card-body">
                            <div class="mb-3 mb-sm-20">
                                <h3>{{ translate('Shop_Name') }}</h3>
                                <p class="fs-12 mb-0">
                                    {{ translate('Here you can set your brand logo for website and app.') }}
                                </p>
                            </div>

                            <div class="bg-section p-12 p-sm-20 rounded">
                                <div class="row g-4">
                                    <div class="col-lg-4">
                                        <div>
                                            <label for="" class="form-label mb-2">
                                                {{ translate('Shop name') }} <span class="text-danger">*</span>
                                            </label>
                                            <input type="text" value="{{getInHouseShopConfig('name')}}" class="form-control" name="name" placeholder="{{ translate('Enter_shop_name') }}" required data-required-msg="{{ translate('shop_name_field_is_required')}}">
                                        </div>
                                    </div>
                                    <div class="col-lg-4">
                                        <div>
                                            <label for="" class="form-label mb-2">
                                                {{ translate('Contact') }} <span class="text-danger">*</span>
                                            </label>
                                            <input type="tel" value="{{getInHouseShopConfig('contact')}}" class="form-control" name="contact" required data-required-msg="{{ translate('contact_field_is_required')}}" placeholder="{{ translate('Enter contact number') }}">
                                        </div>
                                    </div>
                                    <div class="col-lg-4">
                                        <div>
                                            <label for="" class="form-label mb-2">
                                                {{ translate('Address') }} <span class="text-danger">*</span>
                                            </label>
                                            <textarea name="address" id="" rows="1" class="form-control" placeholder="{{ translate('Enter address') }}" required data-required-msg="{{ translate('address_field_is_required')}}">{{getInHouseShopConfig('address')}}</textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card card-sm-shadow-2">
                        <div class="card-body pb-0">
                            <div class="mb-3 mb-sm-20">
                                <h3>{{ translate('Logo and Cover') }}</h3>
                                <p class="fs-12 mb-0">
                                    {{ translate('set_your_brand_logo_and_cover_for_website_and_app') }}
                                </p>
                            </div>

                            <div class="row g-4">
                                <div class="col-lg-6">
                                    @include('admin-views.inhouse-shop.partials._shop_logo')
                                </div>
                                <div class="col-lg-6">
                                    @include('admin-views.inhouse-shop.partials._shop_cover')
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-end trans3 mt-4 action-btn-wrapper-container">
                <div
                    class="d-flex justify-content-sm-end justify-content-center gap-3 flex-grow-1 flex-grow-sm-0 bg-white action-btn-wrapper trans3">
                    <button type="reset" class="btn btn-secondary px-3 px-sm-4 w-120" id="custom-reset-btn">
                        {{ translate('reset') }}
                    </button>

                    <button type="submit" class="btn btn-primary px-3 px-sm-4">
                        <i class="fi fi-sr-disk"></i>
                        {{ translate('save_information') }}
                    </button>
                </div>
            </div>
        </form>

    </div>
@endsection
@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/backend/admin/js/business-settings/inhouse-shop.js') }}"></script>
@endpush
