<div class="color_image_column w-100">
    <div class="bg-section p-12 p-sm-20 rounded-10 h-100">
        <div class="form-group">
            <div class="">
                <h2 class="mb-1">{{ translate('Product_Color_Wise_Images') }}</h2>
                <p class="fs-12 mb-0">
                    {{ translate('Here_you_can_add_color_wise_product_image.') }}
                    <span class="text-info">
                        {{ translate('JPG,_JPEG,_PNG_Image_size') . ' : ' . translate('Max') .' '. getFileUploadMaxSize(). ' MB' }}
                        ({{ THEME_RATIO[theme_root_path()]['Product Image'] }})
                    </span>
                </p>
            </div>

            <div id="color-wise-image-section" class="d-flex justify-content-start flex-wrap gap-3"></div>
        </div>
    </div>
</div>

<div class="position-absolute start-0 top-0">
    <div class="btn-circle" style="--size: 12px;"></div>
</div>

