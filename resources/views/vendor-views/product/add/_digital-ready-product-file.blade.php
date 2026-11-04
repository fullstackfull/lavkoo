<div class="card card-body mt-3">
    <div class="mb-20">
        <h3 class="mb-1 d-flex gap-1">{{ translate('File_Upload') }} <span class="text-danger">*</span></h3>
        <p class="fs-12 mb-0">
            {{ translate('here_you_can_upload_themes_for_customer_website_to_give_customer_better_visual') }}
        </p>
    </div>
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="h-100">
                <div class="d-flex justify-content-center position-relative lg document-upload-container">
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

                    <div class="position-absolute end-0 top-0 p-2 z-2 after_upload_buttons d-none">
                        <div class="d-flex gap-3 align-items-center">
                            <button type="button" class="btn btn-primary icon-btn doc_edit_btn" style="--size: 26px;">
                                <i class="fi fi-sr-pencil"></i>
                            </button>
                            <a type="button" class="btn btn-success icon-btn doc_download_btn" style="--size: 26px;">
                                <i class="fi fi-sr-download"></i>
                            </a>
                        </div>
                    </div>
                    <div class="document-upload-wrapper lg mw-100 doc-upload-wrapper">
                        <input type="file" class="document_input z-index-1"
                            name="digital_file_ready"
                            data-max-size="{{ getFileUploadMaxSize(type: 'file') }}"
                            data-validation-error-msg="{{ translate('File_size_is_too_large_Maximum_').' '.getFileUploadMaxSize(type: 'file').' '.'MB' }}"
                            accept=".jpg,.jpeg,.png,.gif,.zip,.pdf,.xlsx"/>
                        <div class="textbox">
                            <img class="svg"
                                src="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/doc-upload-icon.svg') }}"
                                alt="">
                            <p class="mb-3">
                                {{ translate('Select_a_file_or') }}
                                <span class="fw-semibold">
                                    {{ translate('Drag_and_Drop_here') }}
                                </span>
                            </p>
                            <button type="button" class="btn btn-outline-primary">
                                {{ translate('Select_File') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="bg-warning bg-opacity-10 px-20 py-3 text-dark rounded-8 h-100 d-flex justify-content-center flex-column">
                <h4 class="text-info-dark">{{ translate('instructions') }}</h4>
                <ul class="m-0 ps-20 d-flex flex-column gap-1 text-body fs-12">
                    <li>{{ translate('please_upload_proper_file_for_this_item.') }}</li>
                    <li>{{ translate('after_attach_the_file_click_submit_button.') }}</li>
                    <li>{{ translate('without_upload_any_file_this_item_don’t_show_at_website_or_app.') }}</li>
                </ul>
            </div>
        </div>
    </div>
</div>
