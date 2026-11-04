@extends('layouts.admin.app')

@section('title', translate('System_Addons'))

@section('content')
    <div class="content container-fluid">
        <h1 class="mb-3 mb-sm-20">{{ translate('System_Addons') }}</h1>

        <div class="card mb-3">
            <div class="card-body">
                <form enctype="multipart/form-data" id="addon-upload-form" action="{{ route('admin.system-setup.addon.upload') }}" method="POST" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation" novalidate>
                    @csrf
                    <div class="d-flex flex-column gap-3 gap-sm-20">
                        <div class="">
                            <h2>{{ translate('upload_Addons') }}</h2>
                            <p class="mb-0 fs-12">
                                {{ translate('here_you_can_upload_addons_for_customer_website_to_give_customer_better_visual') }}
                            </p>
                        </div>

                        <div class="row g-4">

                            @php($conditionOne=checkUploadMaxFileSizeLimit(limit: 20))
                            @php($conditionTwo=checkPostMaxFileSizeLimit(limit: 20))

                            <div class="col-lg-6">
                                <div class="bg-section rounded-8 p-12 p-sm-20">
                                    <div class="file-upload-parent {{ $conditionOne && $conditionTwo ? "" : 'disabled' }}">
                                        <div class="custom-file-upload mb-4">
                                            <input type="file"  data-required-msg="{{ translate('only_zip_file_is_allowed') }}"
                                                   data-max-size="{{ getFileUploadMaxSize(type: 'file') }}"
                                                   accept=".zip,application/zip,application/x-zip-compressed,application/octet-stream"
                                                    name="file_upload" id="input-file" required />
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
                                                    {{ translate('total_file_size_no_more_than_') . getFileUploadMaxSize(type: 'file').' MB' }}
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
                                                {{ translate('please_make_sure').','.translate('your_server_php').'"'.translate('upload_max_filesize').'"'.translate('value_is_grater_or_equal_to_20MB.').' '.translate('current_value_is').' - '.ini_get('upload_max_filesize').'B' }}
                                            </li>
                                            <li>
                                                {{ translate('please_make_sure').','.translate('your_server_php').'"'.translate('post_max_size').'"'.translate('value_is_grater_or_equal_to_20MB.').' '.translate('current_value_is') .' - '.ini_get('post_max_size').'B'}}
                                            </li>
                                        </ul>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="d-flex flex-wrap justify-content-end gap-3">
                                    <a href="{{ route('admin.system-setup.addon.index') }}" class="btn btn-secondary px-sm-4 min-w-120">
                                        {{ translate('cancel') }}
                                    </a>
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

        @include('admin-views.system-setup.addons.partials._addons-list')
    </div>


    <div class="modal fade" id="activatedThemeModal" tabindex="-1" role="dialog"
         aria-labelledby="activatedThemeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
            <div class="modal-content" id="activateData">
            </div>
        </div>
    </div>
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/admin/addon.js') }}"></script>
@endpush
