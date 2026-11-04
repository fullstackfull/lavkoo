<form action="{{route('admin.products.update-product-images',['id' => $product['id']])}}" method="post"
      enctype="multipart/form-data" class="form-advance-validation form-advance-file-validation non-ajax-form-validate"
      novalidate="novalidate">
    @csrf
    <div class="offcanvas offcanvas-end"
         tabindex="-1"
         id="offcanvasEditImages"
         aria-labelledby="offcanvasEditImagesLabel"
         style="--bs-offcanvas-width: 500px">

        <div class="offcanvas-header bg-body">
            <h3 class="mb-0">{{ translate('Edit_Images') }}</h3>
            <button type="button" class="btn btn-circle bg-white text-dark fs-10"
                    style="--size: 1.5rem;" data-bs-dismiss="offcanvas" aria-label="Close">
                <i class="fi fi-rr-cross"></i>
            </button>
        </div>

        <div class="offcanvas-body">
            <div class="p-12 p-sm-20 bg-section rounded-10 mb-20">
                <div class="px-2">
                    <label class="form-label fw-semibold mb-1 d-flex gap-1">
                        {{ translate('Product_thumbnail') }}
                        <span class="text-danger">*</span>
                    </label>

                    <p class="mb-20">
                        {{ translate('Edit_product_thumbnail_images_for_this_product.') }}
                        <span class="text-info d-block">
                            {{ getFileUploadFormats(skip: '.svg, .gif', asBladeMessage: true).' '. translate('Image_size'). ' : '. translate('Max').' '. getFileUploadMaxSize() . 'MB' }}
                            {{ translate('Ratio'). ' (1:1)' }}
                        </span>
                    </p>

                    <div class="upload-file">
                        <input type="file"
                               name="image"
                               class="upload-file__input single_file_input action-upload-color-image"
                               accept="{{ getFileUploadFormats(skip: '.svg') }}"
                               data-imgpreview="static_thumb_preview">
                        <label class="upload-file__wrapper">
                            <div class="upload-file-textbox text-center">
                                <img width="34" height="34" class="svg"
                                     src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}"
                                     alt="">
                                <p class="mt-1 fs-12 text-body text-center mb-0">
                                    {{ translate('Add') }}
                                </p>
                            </div>
                            <img class="upload-file-img"
                                 id="static_thumb_preview"
                                 loading="lazy"
                                 src="{{ getStorageImages(path:$product->thumbnail_full_url, type:'backend-product') }}"
                                 data-default-src="{{ getStorageImages(path:$product->thumbnail_full_url, type:'backend-product') }}"
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
            <div class="p-12 p-sm-20 bg-section rounded-10 mb-20">
                <div class="px-2">

                    <label class="form-label fw-semibold mb-1">
                        {{ translate('additional_image') }}
                    </label>

                    <p class="mb-0">
                        {{ translate('Edit_additional_images_for_this_product_from_here.') }}
                        <span class="text-info d-block">
                            {{ getFileUploadFormats(skip: '.svg, .gif', asBladeMessage: true).' '. translate('Image_size'). ' : '. translate('Max').' '. getFileUploadMaxSize() . 'MB' }}
                            {{ translate('Ratio'). ' (1:1)' }}
                        </span>
                    </p>

                    <div class="position-relative">

                        <div class="multi_image_picker space-need d-flex gap-4 pt-4 flex-wrap design_two"
                             data-ratio="1/1"
                             data-field-name="images[]"
                             data-max-count=""
                             data-allowed-formats="{{ getFileUploadFormats(skip: '.svg,.gif') }}"
                             data-validation-error-msg="{{ translate('File_size_is_too_large_Maximum_').' '.getFileUploadMaxSize().' '.'MB' }}"
                        >

                            @php
                                $finalImages = \App\Utils\ProductManager::getProductDetailsEditImages(product: $product);
                            @endphp


                        @foreach($finalImages as $key => $photo)
                                @php
                                    $uid = rand(1111, 9999);
                                    $imagePath = isset($photo['key'])
                                        ? getStorageImages(path: $photo, type: 'backend-product')
                                        : getStorageImages(path: '', type: 'backend-product');
                                    $fileKey = $photo['key'] ?? $photo;
                                @endphp

                                <div class="upload-file m-0 position-relative" id="additional-section-{{ $key }}">
                                    @if(request('product-gallery'))
                                        <button type="button"
                                                class="remove_btn btn btn-danger btn-circle w-20 h-20 fs-8 remove-existing-gallery"
                                                data-section-remove-id="additional-section-{{ $key }}">
                                            <i class="fi fi-sr-cross"></i>
                                        </button>
                                    @else
                                        <a class="remove_btn btn btn-danger btn-circle w-20 h-20 fs-8"
                                           href="{{ route('admin.products.delete-image', [
                                            'id' => $product->id,
                                            'name' => $fileKey,
                                            'color' => $photo['color'] ?? null
                                        ]) }}">
                                            <i class="fi fi-sr-cross"></i>
                                        </a>
                                    @endif
                                    <label class="upload-file__wrapper">
                                        <img class="upload-file-img"
                                             loading="lazy"
                                             id="additional_Image_{{ $uid }}"
                                             src="{{ $imagePath }}"
                                             data-default-src="{{ $imagePath }}"
                                             alt="">
                                    </label>
                                    @if(request('product-gallery'))
                                        <input type="hidden" name="existing_images[]" value="{{ $fileKey }}">
                                    @endif
                                </div>
                            @endforeach
                        </div>

                    </div>
                </div>
            </div>

        </div>
        <div class="offcanvas-footer shadow-popup">
            <div class="d-flex justify-content-center flex-wrap gap-3 bg-white px-4 py-3">
                <button type="button" data-bs-dismiss="offcanvas" aria-label="Close"
                        class="btn btn-secondary flex-grow-1">
                    {{ translate('Cancel') }}
                </button>
                <button type="submit" class="btn btn-primary flex-grow-1">
                    {{ translate('Update') }}
                </button>
            </div>
        </div>
    </div>

</form>
