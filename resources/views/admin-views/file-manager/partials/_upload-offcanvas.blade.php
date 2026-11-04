<form action="{{ route('admin.system-setup.file-manager.image-upload') }}" method="post" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate"
      enctype="multipart/form-data">
    @csrf
    <div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasZipUpload" aria-labelledby="offcanvasZipUploadLabel">
        <div class="offcanvas-header bg-body">
            <h3 class="mb-0">{{ translate('File_Upload') }}</h3>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
            <div class="bg-warning-subtle p-3 rounded mb-20">
                <h3 class="text-info">{{ translate('Instructions') }}</h3>

                <ul class="d-flex flex-column gap-2 ps-4">
                    <li>
                        {{ translate('upload_file_must_be_zip_file_format_in_and_click_save_information.') }}
                    </li>
                    <li>
                        {{ translate('uploaded_file_total_size_maximum_'.ini_get('upload_max_filesize').'B') }}
                    </li>
                    <li>
                        {{ translate('without_click_upload_the_items_are_not_uploaded_to_your_server_and_can_not_see_the_items_in_your_gallery.') }}
                    </li>
                </ul>
            </div>

            <div>
                <input type="text" name="path" value="{{ base64_decode($targetFolder) }}" hidden>
                <input type="text" name="storage" value="{{ $storage }}" hidden>

                <div class="bg-section rounded-8 p-12 p-sm-20">
                    <div class="file-upload-parent">
                        <div class="custom-file-upload mb-4">
                            <input type="file" accept=".zip" name="file" id="input-file" required
                                   data-required-msg="{{ translate('file_field_is_required') }}"
                                   data-max-file-size="{{ ini_get('upload_max_filesize').'B' }}" />

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
                                    {{ translate('total_file_size_no_more_than_'.ini_get('upload_max_filesize').'B') }}
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
        </div>
        <div class="offcanvas-footer shadow-lg">
            <div
                class="d-flex justify-content-center flex-wrap gap-3 bg-white px-3 py-2">
                <button type="reset" data-bs-dismiss="offcanvas" class="btn btn-secondary px-3 px-sm-4 flex-grow-1">
                    {{ translate('cancel') }}
                </button>
                <button type="{{ getDemoModeFormButton(type: 'button') }}" class="btn btn-primary px-3 px-sm-4 flex-grow-1 {{ getDemoModeFormButton(type: 'class') }}">
                    {{ translate('Upload') }}
                </button>
            </div>
        </div>
    </div>
</form>

<form action="{{ route('admin.system-setup.file-manager.image-upload') }}" method="post" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate"
      enctype="multipart/form-data">
    @csrf
    <div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasImageUpload"
         aria-labelledby="offcanvasImageUploadLabel">
        <div class="offcanvas-header bg-body">
            <h3 class="mb-0">{{ translate('Image_Upload') }}</h3>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
            <div class="bg-warning-subtle p-3 rounded mb-20">
                <h3 class="text-info">{{ translate('Instructions') }}</h3>

                <ul class="d-flex flex-column gap-2 ps-4">
                    <li>
                        {{ translate('upload_file_must_be '). str_replace('.','',strtoupper(getFileUploadFormats())). translate(' file_format_in_and_click_save_information') }}
                    </li>
                    <li>
                        {{ translate('uploaded_file_total_size_maximum_'.getFileUploadMaxSize().'MB') }}
                    </li>
                    <li>
                        {{ translate('without_click_upload_the_items_are_not_uploaded_to_your_server_and_can_not_see_the_items_in_your_gallery.') }}
                    </li>
                </ul>
            </div>

            <input type="text" name="path" value="{{ base64_decode($targetFolder) }}" hidden>
            <input type="text" name="storage" value="{{ $storage }}" hidden>

            <div class="">
                <div class="file-upload-parent">
                    <div class="d-flex flex-column gap-20 justify-content-center bg-section rounded-8 p-12 p-sm-20 mb-4">
                        <label for="" class="form-label fw-semibold mb-0 text-center">
                            {{ translate('Choose_Image') }}
                            <span class="text-danger">*</span>
                        </label>
                        <div class="custom-file-upload border-0 p-0 bg-transparent">
                            <input type="file" name="images[]" id="images-input-file" required    data-required-msg="{{ translate('image_field_is_required') }}"
                                   data-max-size="{{ getFileUploadMaxSize() }}"
                                   accept="{{ getFileUploadFormats() }}" multiple />
                                <div class="d-flex justify-content-center align-items-center">
                                    <label class="upload-file__wrapper trigger_input_btn cursor-pointer">
                                        <div class="upload-file-textbox text-center">
                                            <img width="34" height="34" class="svg" src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}" alt="image upload">
                                            <h6 class="mt-1 fw-medium lh-base text-center">
                                                <span class="text-info">{{ translate('Click to upload') }}</span>
                                                <br>{{ translate('or drag and drop') }}
                                            </h6>
                                        </div>
                                    </label>
                                </div>
                        </div>
                    </div>
                    <div class="file-preview-list file-preview-list_two d-flex flex-column gap-4"></div>
                    <div id="file-upload-config" data-icon-src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/icons/file.svg') }}"></div>

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

        <div class="offcanvas-footer shadow-lg">
            <div class="d-flex justify-content-center flex-wrap gap-3 bg-white px-3 py-2">
                <button type="reset" data-bs-dismiss="offcanvas" class="btn btn-secondary px-3 px-sm-4 flex-grow-1">
                    {{ translate('cancel') }}
                </button>
                <button type="{{ getDemoModeFormButton(type: 'button') }}" class="btn btn-primary px-3 px-sm-4 flex-grow-1 {{ getDemoModeFormButton(type: 'class') }}">
                    {{ translate('Upload') }}
                </button>
            </div>
        </div>
    </div>
</form>
