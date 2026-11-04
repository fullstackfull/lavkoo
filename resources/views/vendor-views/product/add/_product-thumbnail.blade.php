<div class="h-100 bg-section rounded-10 d-flex align-items-center justify-content-center p-12 p-sm-20 text-center">
    <div class="">
        <div class="mb-5">
            <label for="" class="form-label fw-semibold mb-1">
                {{ translate('product_thumbnail') }}
                <span class="text-danger">*</span>
            </label>
            <p class="fs-12 mb-0">{{ translate('Upload_image') }}</p>
        </div>
        <div class="upload-file mb-4">
            <input type="file" name="image" class="upload-file__input single_file_input action-upload-color-image"
                accept="{{ getFileUploadFormats(skip: '.svg') }}"
                data-max-size="{{ getFileUploadMaxSize() }}"
                value="" data-imgpreview="pre_img_viewer">
            <label class="upload-file__wrapper">
                <div class="upload-file-textbox text-center">
                    <img width="34" height="34" class="svg"
                        src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}"
                        alt="image upload">
                    <h6 class="mt-1 fw-medium lh-base text-center fs-10">
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
                    <button type="button" class="btn btn-outline-primary text-primary icon-btn view_btn">
                        <i class="fi fi-sr-eye"></i>
                    </button>
                    <button type="button" class="btn btn-outline-primary text-primary icon-btn edit_btn">
                        <i class="fi fi-rr-camera"></i>
                    </button>
                </div>
            </div>
        </div>
        <p class="fs-10 mb-0 text-center">
            {{ getFileUploadFormats(skip: '.svg,', asBladeMessage: true).' '. translate('Image_size'). ' : '. translate('Max').' '. getFileUploadMaxSize() . 'MB' }}
            <span class="text-dark fw-semibold">
                {{ THEME_RATIO[theme_root_path()]['Product Image'] }}
            </span>
        </p>
    </div>
</div>
