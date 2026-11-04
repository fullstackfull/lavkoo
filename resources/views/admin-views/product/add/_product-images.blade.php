<div class="mt-3 rest-part">
    <div class="product-image-wrapper row g-4">
        <div class="col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex flex-column gap-20">
                        <div>
                            <label for="" class="form-label fw-semibold mb-1">
                                {{ translate('product_thumbnail') }}
                                <span class="text-danger">*</span>
                            </label>
                            <div class="align-items-center d-flex flex-wrap gap-1">
                                <span class="badge text-bg-info badge-info badge-lg text-wrap">
                                    {{ THEME_RATIO[theme_root_path()]['Product Image'] }}
                                </span>
                                <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                      aria-label="{{ translate('add_your_products_thumbnail_in') }} JPG, PNG or JPEG {{ translate('format_within') }} 2MB"
                                      data-bs-title="{{ translate('add_your_products_thumbnail_in') }} JPG, PNG or JPEG {{ translate('format_within') }} 2MB"
                                >
                                    <i class="fi fi-sr-info"></i>
                                </span>
                            </div>
                        </div>
                        <div class="upload-file">
                            <input type="file" name="image" class="upload-file__input single_file_input action-upload-color-image"
                                   accept="{{getFileUploadFormats(skip: '.svg')}}"
                                   data-max-size="{{ getFileUploadMaxSize() }}"
                                   value="" data-imgpreview="pre_img_viewer">
                            <label class="upload-file__wrapper">
                                <div class="upload-file-textbox text-center">
                                    <img width="34" height="34" class="svg"
                                         src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}"
                                         alt="image upload">
                                    <h6 class="mt-1 fw-medium lh-base text-center">
                                        <span class="text-info">{{ translate('Click to upload') }}</span>
                                        <br>
                                        {{ translate('or drag and drop') }}
                                    </h6>
                                </div>
                                <img class="upload-file-img" loading="lazy" src="" data-default-src=""
                                     alt="">
                            </label>
                            <div class="overlay">
                                <div class="d-flex gap-10 justify-content-center align-items-center h-100">
                                    <button type="button" class="btn btn-outline-info icon-btn view_btn">
                                        <i class="fi fi-sr-eye"></i>
                                    </button>
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

        <div class="col-md-9 color_image_column">
            <div class="card h-100">
                <div class="card-body">
                    <div class="form-group">
                        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                            <div>
                                <label for="name" class="form-label fw-bold mb-0">
                                    {{ translate('colour_wise_product_image') }}
                                    <span class="input-required-icon">*</span>
                                </label>
                                <span
                                    class="badge badge-info text-bg-info">{{ THEME_RATIO[theme_root_path()]['Product Image'] }}</span>
                                <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                      aria-label="{{ translate('add_color-wise_product_images_here') }}."
                                      data-bs-title="{{ translate('add_color-wise_product_images_here') }}."
                                >
                                                <i class="fi fi-sr-info"></i>
                                            </span>
                            </div>

                        </div>
                        <p class="text-muted">
                            {{ translate('must_upload_colour_wise_images_first.') }}
                            {{ translate('Colour_is_shown_in_the_image_section_top_right') }}
                        </p>

                        <div id="color-wise-image-section" class="d-flex justify-content-start flex-wrap gap-3"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-9 additional-image-column-section">
            <div class="item-2 h-100">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                            <div>
                                <label for="name"
                                       class="form-label fw-bold mb-0">{{ translate('upload_additional_image') }}</label>
                                <span
                                    class="badge badge-info text-bg-info">{{ THEME_RATIO[theme_root_path()]['Product Image'] }}</span>
                                <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                      aria-label="{{ translate('upload_any_additional_images_for_this_product_from_here') }}."
                                      data-bs-title="{{ translate('upload_any_additional_images_for_this_product_from_here') }}."
                                >
                                    <i class="fi fi-sr-info"></i>
                                </span>
                            </div>

                        </div>
                        <p class="text-muted">{{ translate('upload_additional_product_images') }}</p>
                        <div class="d-flex flex-column" id="additional_Image_Section">
                            <div class="position-relative">
                                <div class="multi_image_picker d-flex gap-20 pt-20"
                                     data-ratio="1/1"
                                     data-max-filesize="{{ getFileUploadMaxSize() }}"
                                     data-field-name="images[]"
                                     data-required="true"
                                     data-required-msg="{{ translate('additional_image_is_required') }}"
                                     data-allowed-formats="{{ getFileUploadFormats(skip: '.svg,.gif') }}"
                                     data-validation-error-msg="{{ translate('File_size_is_too_large_Maximum_').' '.getFileUploadMaxSize().' '.'MB' }}"
                                >
                                    <div>
                                        <div class="imageSlide_prev">
                                            <div
                                                class="d-flex justify-content-center align-items-center h-100">
                                                <button
                                                    type="button"
                                                    class="btn btn-circle border-0 bg-primary text-white">
                                                    <i class="fi fi-sr-angle-left"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="imageSlide_next">
                                            <div
                                                class="d-flex justify-content-center align-items-center h-100">
                                                <button
                                                    type="button"
                                                    class="btn btn-circle border-0 bg-primary text-white">
                                                    <i class="fi fi-sr-angle-right"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="item-1 show-for-digital-product h-100">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="form-group">
                            <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                                <div>
                                    <label for="name"
                                           class="form-label text-capitalize fw-bold mb-0">{{ translate('Product_Preview_File') }}</label>
                                    <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                          title="{{ translate('upload_a_suitable_file_for_a_short_product_preview.') }} {{ translate('this_preview_will_be_common_for_all_variations.') }}">
                                        <i class="fi fi-sr-info"></i>
                                    </span>
                                </div>
                            </div>
                            <p class="text-muted">{{ translate('Upload_a_short_preview') }}.</p>
                        </div>
                        <div class="image-uploader">
                            <input type="file" name="preview_file" class="image-uploader__zip" data-max-size="{{ getFileUploadMaxSize(type: 'file')  }}"
                                   id="input-file">
                            <div class="image-uploader__zip-preview">
                                <img
                                    src="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/product-upload-icon.svg') }}"
                                    class="mx-auto" width="50" alt="">
                                <div class="image-uploader__title line-2">
                                    {{ translate('Upload_File') }}
                                </div>
                            </div>
                            <span class="btn btn-outline-danger icon-btn collapse zip-remove-btn">
                                <i class="fi fi-rr-trash"></i>
                            </span>
                        </div>
                        <p class="text-muted mt-2 fs-12">
                            {{ translate('Format') }} : {{ " pdf, mp4, mp3" }}
                            <br>
                            {{ translate('image_size') }} : {{ translate('max') }} {{ "10 MB" }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
