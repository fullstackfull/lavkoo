<form action="{{ route('vendor.clearance-sale.update-seo-meta') }}"
      method="POST" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate"
      enctype="multipart/form-data" novalidate="novalidate">
    @csrf
    <div class="offcanvas-sidebar clearanceSaleMetaSetupOffcanvas" id="offcanvasSetupGuide" data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">
        <div class="offcanvas-overlay" data-dismiss="offcanvas"></div>

        <div class="offcanvas-content bg-white shadow d-flex flex-column">
            <div class="offcanvas-header bg-light d-flex justify-content-between align-items-center p-3">
                <h3 class="text-capitalize m-0">{{ translate('Meta Data Setup') }}</h3>
                <button type="button" class="close" data-dismiss="offcanvas" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="offcanvas-body p-3 overflow-auto flex-grow-1">
                <div class="d-flex flex-column gap-20">
                    <div class="p-12 p-sm-20 bg-section rounded">
                        <div class="form-group mb-20">
                            <label class="form-label">
                                {{ translate('meta_Title') }}
                                <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="meta_title" placeholder="{{ translate('meta_Title') }}"
                                   class="form-control" id="meta_title" required
                                   value="{{ $clearanceConfig?->seo?->title ?? '' }}">
                        </div>
                        <div class="form-group mb-20">
                            <label class="form-label">
                                {{ translate('meta_Description') }}
                                <span class="text-danger">*</span>
                            </label>
                            <textarea rows="4" type="text" name="meta_description" id="meta_description"
                                      class="form-control" placeholder="{{ translate('Enter_your_meta_description') }}">{{ $clearanceConfig?->seo?->description ?? '' }}</textarea>
                        </div>

                        <div class="form-group">
                            <div class="d-flex justify-content-center">
                                <label for="meta_Image" class="form-label fw-semibold mb-0">
                                    {{ translate('Meta_Image') }}
                                </label>
                            </div>
                            <div class="d-flex justify-content-center pt-4">
                                <div class="upload-file">
                                    <input type="file" name="meta_image"
                                           class="upload-file__input single_file_input" data-max-size="{{ getFileUploadMaxSize() }}"
                                           accept="{{ getFileUploadFormats(skip: '.svg,.gif,.webp') }}">
                                    <label class="upload-file__wrapper mb-0">
                                        <div class="upload-file-textbox text-center">
                                            <img width="34" height="34" class="svg img-fluid"
                                                 src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}"
                                                 alt="image upload">
                                            <h6 class="mt-1 fw-medium lh-base text-center fs-10">
                                                        <span class="text-info text-capitalize">
                                                            {{ translate('Click_to_upload') }}
                                                        </span>
                                                <br>
                                                {{ translate('Or_drag_and_drop') }}
                                            </h6>
                                        </div>
                                        <img class="upload-file-img" loading="lazy"
                                             src="{{ $clearanceConfig?->seo?->image_full_url['path'] ? getStorageImages(path: $clearanceConfig?->seo?->image_full_url['path'] ? $clearanceConfig?->seo?->image_full_url : '', type: 'backend-banner') : '' }}"
                                             alt="">
                                    </label>
                                    <div class="overlay">
                                        <div class="d-flex gap-10 justify-content-center align-items-center h-100">
                                            <button type="button" class="btn btn-outline-info icon-btn edit_btn">
                                                <i class="fi fi-rr-camera"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <div class="offcanvas-footer shadow-popup">
                <div class="d-flex justify-content-center flex-wrap gap-3 bg-white px-3 py-2">
                    <button type="reset" id="reset" class="btn btn-secondary flex-grow-1">
                        {{ translate('reset') }}
                    </button>
                    <button type="submit" class="btn btn-primary flex-grow-1">
                        {{ translate('update') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>
