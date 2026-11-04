@extends('layouts.admin.app')

@section('title', translate('Software_Update'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 mb-sm-20">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                {{ translate('system_Setup') }}
            </h2>
        </div>
        @include('admin-views.system-setup.system-settings-inline-menu')

        <div class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center mb-3 mb-sm-20">
            <i class="fi fi-sr-lightbulb-on text-info"></i>
            <span>
                {{ translate('Your_current_version_is') }} {{ SOFTWARE_VERSION }}. {{ translate('To_check_our_new_version') }}
                <a class="text-decoration-underline fw-semibold" target="_blank"
                   href="{{ 'https://codecanyon.net/item/6valley-multivendor-ecommerce-complete-ecommerce-mobile-app-web-and-admin-panel/31448597' }}">
                    {{ translate('Click_Here') }}
                </a>
            </span>
        </div>

        @php($conditionOne= checkUploadMaxFileSizeLimit(limit: 200))
        @php($conditionTwo= checkPostMaxFileSizeLimit(limit: 200))

        <div class="card">
            <div class="card-body">
                <form action="{{ route('admin.system-setup.software-update') }}" method="post"
                      enctype="multipart/form-data" id="software-update-form" data-redirect-route="{{ route('home') }}"
                      data-input-warning="{{ translate('Please_upload_the_file_first') }}">
                    @csrf
                    <div class="d-flex flex-column gap-3 gap-sm-20">
                        <div class="">
                            <h2>{{ translate('upload_the_updated_file') }}</h2>
                            <p class="mb-0 fs-12">
                                {{ translate('in_this_section_you_can_update_your_system_by_simply_upload_our_updated_zip_file.') }}
                            </p>
                        </div>

                        <div class="bg-section rounded-8 p-12 p-sm-20">
                            <div class="row g-4">
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label class="form-label" for="username">
                                            {{ translate('codecanyon_username') }}
                                            <span class="text-danger">*</span>
                                        </label>
                                        <input type="text" class="form-control" id="username"
                                               placeholder="{{translate('ex').':'.'2.1'}}"
                                               value="{{ env('BUYER_USERNAME') }}" name="username" required
                                               readonly
                                               data-bs-toggle="tooltip"
                                               data-bs-placement="top"
                                               aria-label="{{ translate('This field is read only mode.') }}"
                                               data-bs-title="{{ translate('This field is read only mode.') }}"
                                        >
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label class="form-label" for="purchase_key">
                                            {{ translate('purchase_code') }}
                                            <span class="text-danger">*</span>
                                        </label>
                                        <input type="text" class="form-control" id="purchase_key"
                                               value="{{env('PURCHASE_CODE')}}" name="purchase_key" required
                                               readonly
                                               data-bs-toggle="tooltip"
                                               data-bs-placement="top"
                                               aria-label="{{ translate('This field is read only mode.') }}"
                                               data-bs-title="{{ translate('This field is read only mode.') }}"
                                        >
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row g-4">

                            @php($conditionOne=checkUploadMaxFileSizeLimit(limit: 200))
                            @php($conditionTwo=checkPostMaxFileSizeLimit(limit: 200))

                            <div class="col-lg-6">
                                <div class="bg-section rounded-8 p-12 p-sm-20">
                                    <div class="file-upload-parent {{ $conditionOne && $conditionTwo ? "" : 'disabled' }}">
                                        <div class="custom-file-upload mb-4">
                                            <input type="file" accept=".zip" data-max-file-size="512MB" name="update_file" id="input-file" />
                                            <div class="text-center">
                                                <div class="mb-20">
                                                    <i class="fi fi-rr-cloud-upload-alt fs-1 text-black-50"></i>
                                                </div>
                                                <p class="mb-0 fs-14 mb-1">
                                                    {{ translate('Select_a_file_or') }}
                                                    <span class="fw-semibold">
                                                        {{ translate('Drag_&_Drop') }}
                                                    </span>
                                                    {{ translate('here') }}
                                                </p>
                                                <div class="mb-0 fs-12">
                                                    {{ translate('total_file_size_no_more_than_512mb') }}
                                                </div>
                                                <div class="btn btn-outline-primary mt-30 trigger_input_btn">
                                                    {{ translate('Select_File') }}
                                                </div>
                                            </div>
                                        </div>
                                        <div class="file-preview-list d-flex flex-column gap-4"></div>
                                        <div id="file-upload-config" data-icon-src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/icons/file-view.png') }}"></div>

                                        <div class="mt-4 d--none progress-bar-container">
                                            <div class="d-flex justify-content-between mb-1 flex-wrap gap-2">
                                                <span>{{ translate('Progress') }}...</span>
                                                <span class="upload-progress-label"></span>
                                            </div>
                                            <div class="progress" role="progressbar" aria-label="Success" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                                                <div class="progress-bar progress-bar-striped bg-success upload-progress-bar"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-6">
                                <div class="bg-warning bg-opacity-10 fs-12 px-20 py-3 text-dark rounded-8 h-100 d-flex justify-content-center flex-column">
                                    <h3 class="text-info-dark">{{ translate('instructions') }}</h3>
                                    <ul class="m-0 ps-20 d-flex flex-column gap-1 text-body">
                                        <li>
                                            {{ translate('please_make_sure').','.translate('your_server_php').'"'.translate('upload_max_filesize').'"'.translate('value_is_grater_or_equal_to_200MB.').' '.translate('current_value_is').' - '.ini_get('upload_max_filesize').'B' }}
                                        </li>
                                        <li>
                                            {{ translate('please_make_sure').','.translate('your_server_php').'"'.translate('post_max_size').'"'.translate('value_is_grater_or_equal_to_200MB.').' '.translate('current_value_is') .' - '.ini_get('post_max_size').'B'}}
                                        </li>
                                    </ul>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="d-flex flex-wrap justify-content-end gap-3">
                                    <button type="reset" class="btn btn-secondary px-sm-4 min-w-120">
                                        {{ translate('cancel') }}
                                    </button>
                                    <button type="{{ getDemoModeFormButton(type: 'button') }}"
                                            {{ $conditionOne && $conditionTwo ? '' : 'disabled' }}
                                            class="btn btn-primary px-sm-4 min-w-120 {{ getDemoModeFormButton(type: 'class') }}">
                                        {{ translate('save') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                </form>
            </div>
        </div>
    </div>
    @include("layouts.admin.partials.offcanvas._software-update-setup")
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/backend/admin/js/system-setup/system-setup.js') }}"></script>
@endpush
