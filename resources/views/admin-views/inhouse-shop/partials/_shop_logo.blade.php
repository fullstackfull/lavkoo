<div class="d-flex flex-column gap-20 bg-section p-12 p-sm-20 rounded">
    <div>
        <label for="" class="form-label fw-semibold mb-1">
            {{ translate('Shop_Logo') }}
            <span class="text-danger">*</span>
        </label>
        <p class="fs-12 mb-0">
            {{ translate('upload_your_shop_logo') }}
        </p>
    </div>

    <div class="upload-file">
        <input type="file" name="image" class="upload-file__input single_file_input" data-required-msg="{{ translate('shop_logo_field_is_required')}}"
               accept="{{getFileUploadFormats(skip: '.svg,.gif')}}"
               data-max-size="{{ getFileUploadMaxSize() }}"  {{ getInHouseShopConfig(key: 'image_full_url') ? '' : 'required' }}>
        <label class="upload-file__wrapper ratio-1-1">
            <div class="upload-file-textbox text-center">
                <img width="34" height="34" class="svg"
                    src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}"
                    alt="{{ translate('Image_Upload') }}">
                <h6 class="mt-1 fw-medium lh-base text-center">
                    <span class="text-info">{{ translate('Click_to_upload') }}</span>
                    <br>
                    {{ translate('or_drag_and_drop') }}
                </h6>
            </div>
            <img class="upload-file-img" loading="lazy"
                src="{{ getStorageImages(path: getInHouseShopConfig(key: 'image_full_url'), type: 'shop') }}"
                data-default-src="{{ getStorageImages(path: getInHouseShopConfig(key: 'image_full_url'), type: 'shop') }}"
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

    <p class="fs-10 mb-0 text-center">
        {{ getFileUploadFormats(skip: '.svg,.gif', asBladeMessage: true).' '. translate('Image_size'). ' : '. translate('Max').' '. getFileUploadMaxSize() . 'MB' }}
        <span class="fw-medium">
            {{ THEME_RATIO[theme_root_path()]['Store cover Image'] }}
        </span>
    </p>
</div>
