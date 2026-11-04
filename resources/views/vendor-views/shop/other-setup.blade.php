<?php
$minimumOrderAmountStatus = getWebConfig(name: 'minimum_order_amount_status');
$minimumOrderAmountByVendor = getWebConfig(name: 'minimum_order_amount_by_seller');
$freeDeliveryStatus = getWebConfig(name: 'free_delivery_status');
$freeDeliveryResponsibility = getWebConfig(name: 'free_delivery_responsibility');
?>

@extends('layouts.vendor.app')

@section('title', translate('Other_Setup'))

@section('content')
    <div class="content container-fluid">
        <h2 class="h1 mb-0 text-capitalize d-flex mb-3">
            {{ translate('Other_Setup') }}
        </h2>

        @include('vendor-views.shop.inline-menu')

        <form action="{{ route('vendor.shop.update-other-settings') }}" method="post" enctype="multipart/form-data" class="form-advance-validation  form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
            @csrf
            <div class="card card-body mb-3">
                <div class="mb-4">
                    <h3 class="mb-1">{{ translate('Order_Setup') }}</h3>
                    <p class="fs-12 mb-0">
                        {{ translate('configure_how_the_minimum_order_amount,_free_delivery,_and_reorder_settings_will_work_for_customers_.') }}
                    </p>
                </div>

                <div class="bg-light p-3 rounded mb-3">
                    <div class="row gy-3">
                        @if ($minimumOrderAmountStatus && $minimumOrderAmountByVendor)
                            <div class="col-lg-4 col-sm-6">
                                <div class="form-group mb-0">
                                    <label class="form-label text-dark">
                                        {{ translate('minimum_order_amount') }}
                                        ({{ getCurrencySymbol() }})
                                        <i class="fi fi-sr-info cursor-pointer text-muted" data-toggle="tooltip"
                                            title="{{ translate('define_the_minimum_order_amount_required_for_customers_to_place_an_order_for_your_products') }}"></i>
                                    </label>
                                    <input type="number" step="any" class="form-control w-100"
                                        id="minimum_order_amount" name="minimum_order_amount" min="0"
                                        value="{{ usdToDefaultCurrency(amount: $vendor->minimum_order_amount) ?? 0 }}"
                                        placeholder="{{ translate('Ex') }}: {{ '300' }}">
                                </div>
                            </div>
                        @endif

                        @if ($freeDeliveryStatus && $freeDeliveryResponsibility == 'seller')
                            <div class="col-lg-4 col-sm-6">
                                <div class="form-group mb-0">
                                    <div class="d-flex justify-content-between align-items-center gap-2">
                                        <label class="form-label text-dark">
                                            {{ translate('free_delivery_over_amount') }}
                                            ({{ getCurrencySymbol() }})
                                            <i class="fi fi-sr-info cursor-pointer text-muted" data-toggle="tooltip"
                                                title="{{ translate('set_the_order_amount_for_free_delivery._customers_will_receive_free_delivery_on_orders_reaching_this_value') }}"></i>
                                        </label>
                                        <label class="switcher" for="free-delivery-status">
                                            <input type="checkbox" class="switcher_input toggle-switch-message"
                                                name="free_delivery_status" id="free-delivery-status"
                                                {{ $vendor['free_delivery_status'] == 1 ? 'checked' : '' }}
                                                data-modal-id = "toggle-modal" data-toggle-id = "free-delivery-status"
                                                data-on-image = "free-delivery-on.png"
                                                data-off-image = "free-delivery-on.png"
                                                data-on-title = "{{ translate('want_to_Turn_ON_Free_Delivery') }}"
                                                data-off-title = "{{ translate('want_to_Turn_OFF_Free_Delivery') }}"
                                                data-on-message = "<p>{{ translate('if_enabled_the_free_delivery_feature_will_be_shown_from_the_system') }}</p>"
                                                data-off-message = "<p>{{ translate('if_disabled_the_free_delivery_feature_will_be_hidden_from_the_system') }}</p>">
                                            <span class="switcher_control"></span>
                                        </label>
                                    </div>
                                    <input type="number" class="form-control" name="free_delivery_over_amount"
                                        id="free-delivery-over-amount" min="0"
                                        placeholder="{{ translate('ex') . ':' . translate('10') }}"
                                        value="{{ usdToDefaultCurrency($vendor['free_delivery_over_amount']) ?? 0 }}">
                                </div>
                            </div>
                        @endif

                        <div class="col-lg-4 col-sm-6">
                            <div class="form-group mb-0">
                                <label class="form-label text-dark">
                                    {{ translate('Re-order_Level') }}
                                    <i class="fi fi-sr-info cursor-pointer text-muted" data-toggle="tooltip"
                                        title="{{ translate('set_the_stock_alert_level_the_system_will_notify_when_your_product_stock_gets_down_to_this_number') }}"></i>
                                </label>
                                <input type="number" class="form-control"
                                    placeholder="{{ translate('Ex') }}: {{ '$100' }}"
                                    value="{{ $vendor?->stock_limit ?? 0 }}" name="stock_limit">
                                <small class="text-muted">
                                    {{ translate('set_the_stock_limit_for_the_reorder_level.') }}
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card card-body">
                <div class="mb-4">
                    <h3 class="mb-1">{{ translate('Business_TIN') }}</h3>
                    <p class="fs-12 mb-0">
                        {{ translate('provide_your_business_tax_id_and_related_information_for_taxpayer_verification') }}.
                    </p>
                </div>

                <div class="row">
                    <div class="col-lg-8">
                        <div class="bg-light p-3 rounded h-100">
                            <div class="form-group">
                                <label class="form-label text-dark">
                                    {{ translate('taxpayer_identification_number(TIN)') }}
                                </label>
                                <input type="text" class="form-control" name="tax_identification_number"
                                    value="{{ $shop?->tax_identification_number }}"
                                    placeholder="{{ translate('type_your_TIN_number') }}">
                            </div>
                            <div class="form-group mb-0">
                                <label class="form-label text-dark">
                                    {{ translate('Expire_Date') }}
                                </label>
                                <div class="position-relative">
                                    <span class="fi fi-sr-calendar icon-absolute-on-right"></span>
                                    <input type="text" name="tin_expire_date"
                                        value="{{ $shop?->tin_expire_date ? \Carbon\Carbon::parse($shop->tin_expire_date)->format('m/d/Y') : '' }}"
                                        class="js-daterangepicker_single-date-with-placeholder form-control"
                                        placeholder="{{ translate('click_to_add_date') }}" readonly>
                                </div>
                            </div>
                        </div>
                    </div>

                    @php
                        $certificatePath = null;
                        $certificatePathExist = false;

                        if (!empty($shop?->tin_certificate)) {
                            $certificatePath = dynamicStorage(
                                path: 'storage/app/public/shop/documents/' . $shop->tin_certificate,
                            );
                            $certificatePathExist = file_exists(
                                base_path('storage/app/public/shop/documents/' . $shop->tin_certificate),
                            );
                        }
                    @endphp


                    <div class="col-lg-4">
                        <div class="bg-light p-3 rounded h-100">
                            <div class="d-flex flex-column align-items-center single_mx-100 document-upload-container">
                                <div class="d-flex gap-4 justify-content-between mb-20 w-100">
                                    <div>
                                        <label class="form-label text-dark font-weight-semibold">
                                            {{ translate('TIN_Certificate') }}
                                        </label>
                                        <p class="fs-12 mb-0">
                                            {{ 'pdf, doc, jpg. ' . translate('File_size') . ' : ' . translate('Max_5_MB') }}
                                        </p>
                                    </div>
                                    <div class="d-flex gap-3 align-items-center">
                                        <button type="button" id="tin-certificate-edit-btn"
                                            data-warning-text="{{ translate('are_you_going_to_delete_the_old_file_and_upload_a_new_one') }} ?"
                                            class="btn btn--primary btn-sm square-btn">
                                            <i class="tio-edit"></i>
                                        </button>
                                        @if ($certificatePathExist)
                                            <button type="button"
                                                class="btn btn-success btn-sm square-btn doc_download_btn">
                                                <i class="tio-download-to"></i>
                                            </button>
                                        @endif
                                    </div>
                                </div>
                                <div class="d-flex justify-content-center w-100">
                                    <div class="document-file-assets"
                                        data-picture-icon="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/picture.svg') }}"
                                        data-document-icon="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/document.svg') }}"
                                        data-blank-thumbnail="{{ dynamicAsset(path: 'public/assets/back-end/img/file-placeholder.png') }}">
                                    </div>

                                    <div class="document-existing-file"
                                        data-file-url=""
                                        data-file-name=""
                                        data-file-type="">
                                    </div>
                                    <div class="document-upload-wrapper mw-100 doc-upload-wrapper"
                                        {!! $certificatePathExist ? 'style="display: none"' : '' !!}>
                                        <input type="file" name="tin_certificate" class="document_input"
                                               data-max-size="{{ getFileUploadMaxSize(type: 'file') }}"
                                               data-validation-error-msg="{{ translate('File_size_is_too_large_Maximum_').' '.getFileUploadMaxSize(type: 'file').' '.'MB' }}"
                                            accept=".pdf, .doc, .docx, application/msword, application/vnd.openxmlformats-officedocument.wordprocessingml.document, application/pdf">
                                        <div class="textbox">
                                            <img class="svg"
                                                src="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/doc-upload-icon.svg') }}"
                                                alt="">
                                            <p class="fs-12 mb-0">
                                                {{ translate('select_a_file_or') }}
                                                <span class="font-weight-semibold">
                                                    {{ translate('drag_and_drop_here') }}
                                                </span>
                                            </p>
                                        </div>
                                    </div>
                                    @if ($certificatePathExist)
                                        <div class="pdf-single" data-file-name="{{ $shop?->tin_certificate }}"
                                            data-file-url="{{ $certificatePath }}">
                                            <div class="pdf-frame">
                                                <canvas class="pdf-preview d--none"></canvas>
                                                <img class="pdf-thumbnail" alt="File Thumbnail"
                                                    src="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/document.svg') }}">
                                            </div>
                                            <div class="overlay">
                                                <div class="pdf-info">
                                                    <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/document.svg') }}"
                                                        width="34" alt="File Type Logo">
                                                    <div class="file-name-wrapper">
                                                        <span class="file-name">
                                                            {{ $shop?->tin_certificate }}
                                                        </span>
                                                        <span class="opacity-50">
                                                            {{ translate('Click_to_view_the_file') }}
                                                        </span>
                                                    </div>
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

            <div class="d-flex flex-wrap justify-content-end gap-3 mt-4">
                <a href="{{ route('vendor.shop.other-setup') }}" class="btn btn-secondary px-3 px-sm-4 min-w-120">
                    {{ translate('Reset') }}
                </a>
                <button type="submit" class="btn btn--primary px-3 px-sm-4 min-w-120">
                    <i class="fi fi-sr-disk"></i>
                    {{ translate('Save_information') }}
                </button>
            </div>
        </form>
    </div>
    @include('layouts.vendor.partials.offcanvas._shop-other-setup')
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/backend/vendor/js/business-settings/shop-settings.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/file-upload/pdf.min.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/file-upload/pdf-worker.min.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/file-upload/multiple-document-upload.js') }}"></script>
@endpush
