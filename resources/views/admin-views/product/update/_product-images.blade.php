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
                                    data-bs-title="{{ translate('add_your_products_thumbnail_in') }} JPG, PNG or JPEG {{ translate('format_within') }} 2MB">
                                    <i class="fi fi-sr-info"></i>
                                </span>
                            </div>
                        </div>
                        <div class="upload-file">
                            <input type="file" name="image"
                                class="upload-file__input single_file_input action-upload-color-image"
                                accept="{{ getFileUploadFormats(skip:'.svg,.gif') }}" value=""
                                   data-max-size="{{ getFileUploadMaxSize() }}"
                                data-imgpreview="pre_img_viewer">
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
                                <img class="upload-file-img" loading="lazy"
                                    src="{{ getStorageImages(path: $product->thumbnail_full_url, type: 'backend-product') }}"
                                    data-default-src="{{ getStorageImages(path: $product->thumbnail_full_url, type: 'backend-product') }}"
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
                                <span class="badge badge-info text-bg-info">
                                    {{ THEME_RATIO[theme_root_path()]['Product Image'] }}
                                </span>
                                <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                    aria-label="{{ translate('add_color-wise_product_images_here') }}."
                                    data-bs-title="{{ translate('add_color-wise_product_images_here') }}.">
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

        <div
            class="additional-image-column-section {{ $product['product_type'] == 'digital' ? 'col-md-6' : 'col-md-9' }}">
            <div class="item-2 h-100">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                            <div>
                                <label for="name" class="form-label fw-bold mb-0">
                                    {{ translate('upload_additional_image') }}
                                </label>
                                <span class="badge badge-info text-bg-info">
                                    {{ THEME_RATIO[theme_root_path()]['Product Image'] }}
                                </span>
                                <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                    aria-label="{{ translate('upload_any_additional_images_for_this_product_from_here') }}."
                                    data-bs-title="{{ translate('upload_any_additional_images_for_this_product_from_here') }}.">
                                    <i class="fi fi-sr-info"></i>
                                </span>
                            </div>

                        </div>
                        <p class="text-muted">{{ translate('upload_additional_product_images') }}</p>
                        <div class="d-flex flex-column" id="additional_Image_Section">
                            <div class="position-relative">
                                <div class="multi_image_picker d-flex gap-20 pt-20"
                                     data-ratio="1/1"
                                     data-field-name="images[]"
                                     data-max-filesize="{{ getFileUploadMaxSize() }}"
                                     data-allowed-formats="{{ getFileUploadFormats(skip: '.svg,.gif') }}"
                                     data-validation-error-msg="{{ translate('File_size_is_too_large_Maximum_').' '.getFileUploadMaxSize().' '.'MB' }}"
                                >
                                    <div>
                                        <div class="imageSlide_prev">
                                            <div class="d-flex justify-content-center align-items-center h-100">
                                                <button type="button"
                                                    class="btn btn-circle border-0 bg-primary text-white">
                                                    <i class="fi fi-sr-angle-left"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="imageSlide_next">
                                            <div class="d-flex justify-content-center align-items-center h-100">
                                                <button type="button"
                                                    class="btn btn-circle border-0 bg-primary text-white">
                                                    <i class="fi fi-sr-angle-right"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    @if (count($product->colors) == 0)
                                        @foreach ($product->images_full_url as $key => $photo)
                                            @php($unique_id = rand(1111, 9999))
                                            <div class="upload-file m-0 position-relative">
                                                @if (request('product-gallery'))
                                                    <button type="button"
                                                        class="remove_btn btn btn-danger btn-circle w-20 h-20 fs-8 delete_file_input_css remove-addition-image-for-product-gallery"
                                                        data-section-remove-id="addition-image-section-{{ $key }}">
                                                        <i class="fi fi-sr-cross"></i>
                                                    </button>
                                                @else
                                                    <a class="delete_file_input_css remove_btn btn btn-danger btn-circle w-20 h-20 fs-8"
                                                        href="{{ route('admin.products.delete-image', ['id' => $product['id'], 'name' => $photo['key']]) }}">
                                                        <i class="fi fi-rr-cross"></i>
                                                    </a>
                                                @endif

                                                <label class="upload-file__wrapper">
                                                    <img class="upload-file-img" loading="lazy"
                                                        id="additional_Image_{{ $unique_id }}"
                                                        src="{{ getStorageImages(path: $photo, type: 'backend-product') }}"
                                                        data-default-src="{{ getStorageImages(path: $photo, type: 'backend-product') }}"
                                                        alt="">
                                                    @if (request('product-gallery'))
                                                        <input type="text" name="existing_images[]"
                                                            value="{{ $photo['key'] }}" hidden>
                                                    @endif
                                                </label>

                                                <div class="overlay">
                                                    <div
                                                        class="d-flex gap-10 justify-content-center align-items-center h-100">
                                                        <button type="button"
                                                            class="btn btn-outline-info icon-btn view_btn"
                                                            data-img="#additional_Image_{{ $unique_id }}">
                                                            <i class="fi fi-sr-eye"></i>
                                                        </button>

                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    @else
                                        @if ($product->color_image)
                                            @foreach ($product->color_images_full_url as $key => $photo)
                                                @if ($photo['color'] == null)
                                                    @php($unique_id = rand(1111, 9999))
                                                    <div class="upload-file m-0 position-relative">
                                                        <a class="delete_file_input_css remove_btn btn btn-danger btn-circle w-20 h-20 fs-8"
                                                            href="{{ route('admin.products.delete-image', ['id' => $product['id'], 'name' => $photo['image_name']['key']]) }}">
                                                            <i class="fi fi-rr-cross"></i>
                                                        </a>

                                                        <label class="upload-file__wrapper">
                                                            <img class="upload-file-img" loading="lazy"
                                                                id="additional_Image_{{ $unique_id }}"
                                                                src="{{ getStorageImages(path: $photo['image_name'], type: 'backend-product') }}"
                                                                data-default-src="{{ getStorageImages(path: $photo['image_name'], type: 'backend-product') }}"
                                                                alt="">
                                                        </label>

                                                        <div class="overlay">
                                                            <div
                                                                class="d-flex gap-10 justify-content-center align-items-center h-100">
                                                                <button type="button"
                                                                    class="btn btn-outline-info icon-btn view_btn"
                                                                    data-img="#additional_Image_{{ $unique_id }}">
                                                                    <i class="fi fi-sr-eye"></i>
                                                                </button>

                                                            </div>
                                                        </div>
                                                    </div>
                                                @endif
                                            @endforeach
                                        @else
                                            @foreach ($product->images_full_url as $key => $photo)
                                                @php($unique_id = rand(1111, 9999))
                                                <div class="" id="addition-image-section-{{ $key }}">
                                                    <div
                                                        class="custom_upload_input custom-upload-input-file-area position-relative border-dashed-2">
                                                        @if (request('product-gallery'))
                                                            <button
                                                                class="delete_file_input_css btn btn-outline-danger icon-btn remove-addition-image-for-product-gallery"
                                                                data-section-remove-id="addition-image-section-{{ $key }}">
                                                                <i class="fi fi-rr-trash"></i>
                                                            </button>
                                                        @else
                                                            <a class="delete_file_input_css btn btn-outline-danger icon-btn"
                                                                href="{{ route('admin.products.delete-image', ['id' => $product['id'], 'name' => $photo['key']]) }}">
                                                                <i class="fi fi-rr-trash"></i>
                                                            </a>
                                                        @endif

                                                        <div
                                                            class="img_area_with_preview position-absolute z-index-2 border-0">
                                                            <img id="additional_Image_{{ $unique_id }}"
                                                                alt=""
                                                                class="h-auto aspect-1 bg-white onerror-add-class-d-none"
                                                                src="{{ getStorageImages(path: $photo, type: 'backend-product') }}">
                                                            @if (request('product-gallery'))
                                                                <input type="text" name="existing_images[]"
                                                                    value="{{ $photo['key'] }}" hidden>
                                                            @endif
                                                        </div>

                                                        <div
                                                            class="overlay position-absolute top-0 inset-inline-start-0 w-100 h-100 d-flex justify-content-center align-items-center">
                                                            <div class="d-flex gap-10">
                                                                <button type="button"
                                                                    class="btn btn-outline-info icon-btn view_btn"
                                                                    data-img="#additional_Image_{{ $unique_id }}">
                                                                    <i class="fi fi-sr-eye"></i>
                                                                </button>

                                                            </div>
                                                        </div>

                                                        <div
                                                            class="position-absolute h-100 top-0 w-100 d-flex align-content-center justify-content-center">
                                                            <div
                                                                class="d-flex flex-column justify-content-center align-items-center">
                                                                <img alt="" class="w-75"
                                                                    src="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/product-upload-icon.svg') }}">
                                                                <h3 class="text-muted">{{ translate('Upload_Image') }}
                                                                </h3>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        @endif
                                    @endif
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
                                    <label for="name" class="form-label text-capitalize fw-bold mb-0">
                                        {{ translate('Product_Preview_File') }}
                                    </label>
                                    <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                        title="{{ translate('upload_a_suitable_file_for_a_short_product_preview.') }} {{ translate('this_preview_will_be_common_for_all_variations.') }}">
                                        <i class="fi fi-sr-info"></i>
                                    </span>
                                </div>
                            </div>
                            <p class="text-muted">{{ translate('Upload_a_short_preview.') }}</p>
                        </div>
                        <div class="image-uploader">
                            <input type="file" name="preview_file" class="image-uploader__zip" id="input-file" data-max-size="{{ getFileUploadMaxSize(type: 'file')  }}">
                            <div class="image-uploader__zip-preview">
                                <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/product-upload-icon.svg') }}"
                                    class="mx-auto" width="50" alt="">
                                <div class="image-uploader__title line-2">
                                    @if ($product->preview_file_full_url['path'])
                                        {{ $product->preview_file }}
                                    @elseif(request('product-gallery') && $product?->preview_file)
                                        {{ translate('Upload_File') }}
                                    @else
                                        {{ translate('Upload_File') }}
                                    @endif

                                    @if (request('product-gallery'))
                                        <input type="hidden" name="existing_preview_file"
                                            value="{{ $product?->preview_file }}">
                                        <input type="hidden" name="existing_preview_file_storage_type"
                                            value="{{ $product?->preview_file_storage_type }}">
                                    @endif
                                </div>
                            </div>
                            @if ($product->preview_file_full_url['path'])
                                <span
                                    class="btn btn-outline-danger icon-btn collapse show zip-remove-btn delete_preview_file_input"
                                    data-route="{{ route('admin.products.delete-preview-file') }}">
                                    <i class="fi fi-rr-trash"></i>
                                </span>
                            @else
                                <span class="btn btn-outline-danger icon-btn collapse zip-remove-btn">
                                    <i class="fi fi-rr-trash"></i>
                                </span>
                            @endif
                        </div>
                        <p class="text-muted mt-2 fs-12">
                            {{ translate('Format') }} : {{ ' pdf, mp4, mp3' }}
                            <br>
                            {{ translate('image_size') }} : {{ translate('max') }} {{ '10 MB' }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
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
</div>
