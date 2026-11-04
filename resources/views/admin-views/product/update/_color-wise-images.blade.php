<div class="color_image_column w-100">
    <div class="bg-section p-12 p-sm-20 rounded-10 h-100">
        <div class="form-group">
            <div class="">
                <h2 class="mb-1">{{ translate('Product_Color_Wise_Images') }}</h2>
                <p class="fs-12 mb-0">
                    {{ translate('Here_you_can_add_color_wise_product_image.') }}
                    <span class="text-info">
                        {{ getFileUploadFormats(skip: '.svg, .gif', asBladeMessage: true).' '. translate('Image_size'). ' : '. translate('Max').' '. getFileUploadMaxSize() . 'MB' }}
                        {{ translate('Ratio'). ' (1:1)' }}
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

<input type="hidden" id="color_image" value="{{ json_encode($product->color_images_full_url) }}">
<input type="hidden" id="color_image_json" value="{{ json_encode($product->color_images_full_url) }}">
<input type="hidden" id="images" value="{{ json_encode($product->images_full_url) }}">
<input type="hidden" id="images_json" value="{{ json_encode($product->images_full_url) }}">
<input type="hidden" id="product_id" name="product_id" value="{{ $product->id }}">
<input type="hidden" id="remove_url" value="{{ route('admin.products.delete-image') }}">
@if (request('product-gallery'))
    <input type="hidden" id="clone-product-gallery" value="1">
@endif

