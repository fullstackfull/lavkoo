<div class="additional-image-column-section col-md-12">
    <div class="card card-body h-100">
        <div class="mb-20">
            <h3 class="mb-1">{{ translate('Product_Additional_Images') }}</h3>
            <p class="fs-12 mb-0">
                {{ translate('Upload_additional_images_for_this_product_from_here.') }}
                <span class="text-info">
                    {{ getFileUploadFormats(skip: '.svg,.gif', asBladeMessage: true).' '. translate('Image_size'). ' : '. translate('Max').' '. getFileUploadMaxSize() . 'MB' }}
                    ({{ THEME_RATIO[theme_root_path()]['Product Image'] }})
                </span>
            </p>
        </div>
        <div class="d-flex flex-column bg-section rounded-10" id="additional_Image_Section">
            <div class="position-relative">
                <div class="multi_image_picker d-flex gap-4 p-3"
                    data-ratio="1/1"
                    data-field-name="images[]"
                    data-required="true"
                    data-required-msg="{{ translate('additional_image_is_required') }}"
                     data-max-filesize="{{getFileUploadMaxSize()}}"
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
