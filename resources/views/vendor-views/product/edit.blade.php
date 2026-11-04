@extends('layouts.vendor.app')

@section('title', translate(request('product-gallery')==1 ?'product_Add' :'product_Edit'))

@push('css_or_js')
    <link href="{{ dynamicAsset(path: 'public/assets/back-end/css/tags-input.min.css') }}" rel="stylesheet">
    <link href="{{ dynamicAsset(path: 'public/assets/select2/css/select2.min.css') }}" rel="stylesheet">
    <link href="{{ dynamicAsset(path: 'public/assets/back-end/plugins/summernote/summernote.min.css') }}" rel="stylesheet">
    <link href="{{ dynamicAsset(path: 'public/assets/back-end/libs/quill-editor/quill-editor.css') }}" rel="stylesheet">
    <link href="{{ dynamicAsset(path: 'public/assets/back-end/css/vendor-product.css') }}" rel="stylesheet">
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2 flex-grow-1">
                {{ translate(request('product-gallery')==1 ?'product_Add' :'product_Edit') }}
            </h2>

            @if(getActiveAIProviderConfigCache())
            <div class="form-control border-0 badge-pill bg-white d-flex justify-content-center align-items-center px-12 py-2 shadow-sm w-auto text-nowrap" id="ai-remaining-count">
                <span class="d-flex gap-2">
                    <span class="fw-bold" id="count">{{$aiRemainingCount ?? 0}}</span> {{translate('generates_left')}}
                    <img width="18" height="18" class="" src="{{ dynamicAsset(path: 'public/assets/back-end/img/ai/blink-right-small.svg') }}" alt="">
                </span>
            </div>
            @endif

        </div>

        <form class="product-form text-start form-advance-validation form-advance-file-validation" action="{{request('product-gallery')==1?route('vendor.products.add') : route('vendor.products.update',$product->id)}}" method="post"
              enctype="multipart/form-data" id="product_form">
            @csrf

            @include('vendor-views.product.update._basic-setup')
            @include("vendor-views.product.update._general-setup")
            @include("vendor-views.product.update._pricing-others")
            @include("vendor-views.product.update._product-variation-setup")
            <div class="row product-image-wrapper gx-2 gy-3 mt-0">
                @include("vendor-views.product.update._additional-images")
                @include("vendor-views.product.update._digital-product-file")
            </div>
            @include("vendor-views.product.update._product-video")
            @include("vendor-views.product.update._seo-section")

            @include('vendor-views.product.partials.ai-sidebar')

            @if(getActiveAIProviderConfigCache())
            <div class="floating-ai-button">
                <button type="button" class="btn btn-lg rounded-circle shadow-lg" data-toggle="modal" data-target="#aiAssistantModal"
                    data-action="main" title="AI Assistant">
                    <span class="ai-btn-animation">
                        <span class="gradientCirc"></span>
                    </span>
                    <span class="position-relative z-1 text-white d-flex flex-column gap-1 align-items-center">
                        <img width="16" height="17" src="{{ dynamicAsset(path: 'public/assets/back-end/img/ai/hexa-ai.svg') }}" alt="">
                        <span class="fs-12 fw-semibold">{{ translate('Use_AI') }}</span>
                    </span>
                </button>
                <div class="ai-tooltip">
                    <span>{{translate("AI_Assistant")}}</span>
                </div>
            </div>
            @endif

            <div class="d-flex justify-content-end mt-3">
                <button type="button" class="btn btn--primary px-5 product-add-requirements-check">
                    @if($product->request_status == 2)
                        {{ translate('resubmit') }}
                    @else
                        {{ translate(request('product-gallery') ? 'submit' : 'update') }}
                    @endif
                </button>
            </div>
            @if(request('product-gallery'))
                <input hidden name="existing_thumbnail" value="{{$product->thumbnail_full_url['key']}}">
                <input hidden name="existing_meta_image" value="{{$product?->seoInfo?->image_full_url['key'] ?? $product->meta_image_full_url['key']}}">
            @endif

        </form>
    </div>

    <span id="route-vendor-products-sku-combination" data-url="{{ route('vendor.products.sku-combination') }}"></span>
    <span id="route-vendor-products-digital-variation-combination" data-url="{{ route('vendor.products.digital-variation-combination') }}"></span>
    <span id="route-vendor-products-digital-variation-file-delete" data-url="{{ route('vendor.products.digital-variation-file-delete') }}"></span>
    <span id="image-path-of-product-upload-icon" data-path="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/product-upload-icon.svg') }}"></span>
    <span id="image-path-of-product-upload-icon-two" data-path="{{ dynamicAsset(path: 'public/assets/back-end/img/400x400/img2.jpg') }}"></span>
    <span id="message-enter-choice-values" data-text="{{ translate('enter_choice_values') }}"></span>
    <span id="message-upload-image" data-text="{{ translate('upload_Image') }}"></span>
    <span id="message-are-you-sure" data-text="{{ translate('are_you_sure') }}"></span>
    <span id="message-yes-word" data-text="{{ translate('yes') }}"></span>
    <span id="message-no-word" data-text="{{ translate('no') }}"></span>
    <span id="message-want-to-add-or-update-this-product" data-text="{{ translate('want_to_update_this_product') }}"></span>
    <span id="message-please-only-input-png-or-jpg" data-text="{{ translate('please_only_input_png_or_jpg_type_file') }}"></span>
    <span id="message-product-added-successfully" data-text="{{ translate('product_added_successfully') }}"></span>
    <span id="message-discount-will-not-larger-then-variant-price" data-text="{{ translate('the_discount_price_will_not_larger_then_Variant_Price') }}"></span>
    <span id="system-currency-code" data-value="{{ getCurrencySymbol(currencyCode: getCurrencyCode()) }}"></span>
    <span id="system-session-direction" data-value="{{ Session::get('direction') }}"></span>

    <span id="message-file-size-too-big" data-text="{{ translate('file_size_too_big') }}"></span>
    <span id="product-gallery-page" data-page="{{ request('product-gallery') ? 'product-gallery' : '' }}"></span>
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/tags-input.min.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/new/back-end/libs/spartan-multi-image-picker/spartan-multi-image-picker-min.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/plugins/summernote/summernote.min.js') }}"></script>

    <script src="{{ dynamicAsset(path: 'public/assets/back-end/libs/quill-editor/quill-editor.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/libs/quill-editor/quill-editor-init.js') }}"></script>

    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/file-upload/pdf.min.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/file-upload/pdf-worker.min.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/file-upload/multiple-document-upload.js') }}"></script>

    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/vendor/product-add-update.js') }}"></script>

    <script>
        "use strict";
        $(document).on('click', '.remove-addition-image-for-product-gallery', function () {
            $(this).closest('.upload-file').remove();
        });
        $(function () {
            $('.product_variation_toggle').each(function () {
                toggleVisibility($(this));
            });

            $(document).on('change', '.product_variation_toggle', function () {
                toggleVisibility($(this));
            });

            function toggleVisibility($toggle) {
                const $wrapper = $toggle.closest('.variation_wrapper');
                const $content = $wrapper.find('.product_variation_content');

                if ($toggle.is(':checked')) {
                    $content.removeClass('d--none').stop(true, true).slideDown(200);
                } else {
                    $content.stop(true, true).slideUp(200, function () {
                        $content.addClass('d--none');
                    });
                }
            }
        });

        let colors = {{ count($product->colors) }};
        let imageCount = {{15-count(json_decode($product->images)) }};
        let thumbnail = '{{productImagePath('thumbnail').'/'.$product->thumbnail ?? dynamicAsset(path: 'public/assets/back-end/img/400x400/img2.jpg') }}';
        $(function () {
            if (imageCount > 0) {
                $("#coba").spartanMultiImagePicker({
                    fieldName: 'images[]',
                    maxCount: colors === 0 ? 15 : imageCount,
                    rowHeight: 'auto',
                    groupClassName: 'col-6 col-md-4 col-xl-3 col-xxl-2',
                    maxFileSize: '',
                    placeholderImage: {
                        image: '{{ dynamicAsset(path: "public/assets/back-end/img/400x400/img2.jpg") }}',
                        width: '100%',
                    },
                    dropFileLabel: "Drop Here",
                    onAddRow: function (index, file) {
                    },
                    onRenderedPreview: function (index) {
                    },
                    onRemoveRow: function (index) {
                    },
                    onExtensionErr: function () {
                        toastr.error(messagePleaseOnlyInputPNGOrJPG, {
                            CloseButton: true,
                            ProgressBar: true
                        });
                    },
                    onSizeErr: function () {
                        toastr.error(messageFileSizeTooBig, {
                            CloseButton: true,
                            ProgressBar: true
                        });
                    }
                });
            }

            $("#thumbnail").spartanMultiImagePicker({
                fieldName: 'image',
                maxCount: 1,
                rowHeight: 'auto',
                groupClassName: 'col-12',
                maxFileSize: '',
                placeholderImage: {
                    image: '{{ productImagePath('thumbnail').'/'. $product->thumbnail ?? dynamicAsset(path: 'public/assets/back-end/img/400x400/img2.jpg') }}',
                    width: '100%',
                },
                dropFileLabel: "Drop Here",
                onAddRow: function (index, file) {

                },
                onRenderedPreview: function (index) {

                },
                onRemoveRow: function (index) {

                },
                onExtensionErr: function () {
                    toastr.error(messagePleaseOnlyInputPNGOrJPG, {
                        CloseButton: true,
                        ProgressBar: true
                    });
                },
                onSizeErr: function () {
                    toastr.error(messageFileSizeTooBig, {
                        CloseButton: true,
                        ProgressBar: true
                    });
                }
            });

        });

        setTimeout(function () {
            $('.call-update-sku').on('change', function () {
                getUpdateSKUFunctionality();
            });
        }, 2000)

        function colorWiseImageFunctionality(t) {
            let colors = t.val();
            let color_image = $('#color_image').val() ? $.parseJSON($('#color_image').val()) : [];
            let images = $.parseJSON($('#images').val());
            let product_id = $('#product_id').val();
            let remove_url = $('#remove_url').val();

            let color_image_value = $.map(color_image, function (item) {
                return item.color;
            });

            $('#color_wise_existing_image').html('')
            $('#color-wise-image-section').html('')

            $.each(colors, function (key, value) {
                let value_id = value.replace('#', '');
                let in_array_image = $.inArray(value_id, color_image_value);
                let input_image_name = "color_image_" + value_id;
                @if(request('product-gallery'))
                $.each(color_image, function (color_key, color_value) {
                    if ((in_array_image !== -1) && (color_value['color'] === value_id)) {
                        let image_name = color_value['image_name'];
                        let exist_image_html = `
                            <div class="col-6 col-md-4 col-xl-4 color-image-`+color_value['color']+`">
                                <div class="position-relative p-2 border-dashed-2">
                                    <div class="upload--icon-btns d-flex gap-2 position-absolute z-index-2 p-2" >
                                     <button class="btn btn-outline-danger btn-sm square-btn remove-color-image-for-product-gallery"
        aria-label="{{ translate('remove_image') }}"
        data-color="`+color_value['color']+`">
    <i class="tio-delete"></i>
</button>
                                    </div>
                                    <img class="w-100 aspect-ratio-1-img" height="auto"
                                        onerror="this.src='{{ dynamicAsset(path: 'public/assets/front-end/img/image-place-holder.png') }}'"
                                        src="${image_name['path']}"
                                        alt="Product image">
                                        <input type="text" name="color_image_`+color_value['color']+`[]" value="`+image_name['key']+`" hidden>
                                </div>
                            </div>`;
                        $('#color_wise_existing_image').append(exist_image_html)
                    }
                });
                @else
                $.each(color_image, function (color_key, color_value) {
                    if ((in_array_image !== -1) && (color_value['color'] === value_id)) {
                        let image_name = color_value['image_name'];
                        let exist_image_html = `
                            <div class="col-6 col-md-4 col-xl-4">
                                <div class="position-relative p-2 border-dashed-2">
                                    <div class="upload--icon-btns d-flex gap-2 position-absolute z-index-2 p-2" >
                                        <button type="button" class="btn btn-square text-white btn-sm" aria-label="{{ translate('selected_color') }}" style="background: #${color_value['color']}"><i class="tio-done"></i></button>
                                        <a href="` + remove_url + `?id=` + product_id + `&name=` + image_name['key'] + `&color=` + color_value['color'] + `"
                                    class="btn btn-outline-danger btn-sm square-btn"><i class="tio-delete"></i></a>
                                    </div>
                                    <img class="w-100 aspect-ratio-1-img" height="auto"
                                        onerror="this.src='{{ dynamicAsset(path: 'public/assets/front-end/img/image-place-holder.png') }}'"
                                        src="${image_name['path']}"
                                        alt="Product image">
                                </div>
                            </div>`;
                        $('#color_wise_existing_image').append(exist_image_html)
                    }
                });
                @endif
            });

            $.each(colors, function (key, value) {
                let value_id = value.replace('#', '');
                let in_array_image = $.inArray(value_id, color_image_value);
                let input_image_name = "color_image_" + value_id;

                if (in_array_image === -1) {
                    let html = `<div class='col-6 col-md-4 col-xl-4'>
                                    <div class="position-relative p-2 border-dashed-2">
                                        <label style='border-radius: 3px; cursor: pointer; text-align: center; overflow: hidden; position : relative; display: flex; align-items: center; margin: auto; justify-content: center; flex-direction: column;'>
                                        <span class="upload--icon product-image-edit-icon" style="background: ${value}">
                                        <i class="tio-edit"></i>
                                            <input type="file" name="` + input_image_name + `" id="` + value_id + `"data-max-size="{{ getFileUploadMaxSize() }}"  class="d-none custom-upload-input-file action-upload-color-image" accept=".jpg, .webp, .png, .jpeg, .gif" required="">
                                        </span>

                                        <div class="h-100 top-0 aspect-1 w-100 d-flex align-content-center justify-content-center overflow-hidden">
                                            <div class="d-flex flex-column justify-content-center align-items-center">
                                                <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/product-upload-icon.svg') }}" class="w-100 aspect-ratio-1-img" alt="">
                                                <h3 class="text-muted">{{ translate('Upload_Image') }}</h3>
                                            </div>
                                        </div>
                                    </label>
                                    </div>
                                    </div>`;
                    $('#color-wise-image-section').append(html)

                }
            });
        }
        $(document).on('change', '.action-upload-color-image', function () {
            let input = this;
            let thisElement = $(this).closest('label');

            if (input.files && input.files[0]) {
                let maxFileSizeLimit = Number(input?.dataset?.maxSize || ($('#imageUploadMaxSize').data('max-size') || 20));
                let maxFileSizeLimitIAsByte = maxFileSizeLimit * 1024 * 1024;

                if (maxFileSizeLimitIAsByte && maxFileSizeLimitIAsByte < input.files[0]?.size) {
                    toastMagic.error(`${input.files[0].name} exceeds ${maxFileSizeLimit}MB`);
                } else {
                    let uploadedFile = new FileReader();
                    uploadedFile.onload = function (e) {
                        thisElement.find('img').attr('src', e.target.result);
                        thisElement.fadeIn(300);
                        thisElement.find('h3').hide();
                    };
                    uploadedFile.readAsDataURL(input.files[0]);
                }
            }
        });

        $(document).on('click', '.remove-color-image-for-product-gallery', function(event) {
            event.preventDefault();
            let value_id = $(this).data('color');
            let value = '#'+value_id;
            let color = "color_image_" + value_id;
            let html =  `<div class="position-relative p-2 border-dashed-2">
                            <label style='border-radius: 3px; cursor: pointer; text-align: center; overflow: hidden; position : relative; display: flex; align-items: center; margin: auto; justify-content: center; flex-direction: column;'>
                                <span class="upload--icon product-image-edit-icon" style="background: ${value}">
                                <i class="tio-edit"></i>
                                    <input type="file" name="` + color + `" id="` + value_id + `" class="d-none" accept=".jpg, .webp, .png, .jpeg, .gif" required="">
                                </span>

                                <div class="h-100 top-0 aspect-1 w-100 d-flex align-content-center justify-content-center overflow-hidden">
                                    <div class="d-flex flex-column justify-content-center align-items-center">
                                        <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/product-upload-icon.svg') }}" class="w-100 aspect-ratio-1-img" alt="">
                                        <h3 class="text-muted">{{ translate('Upload_Image') }}</h3>
                                    </div>
                                </div>
                            </label>
                        </div>`;
            $('.color-image-'+value_id).empty().append(html);
            $("#color-wise-image-area input[type='file']").each(function () {

                let thisElement = $(this).closest('label');

                function proPicURL(input) {
                    if (input.files && input.files[0]) {
                        let uploadedFile = new FileReader();
                        uploadedFile.onload = function (e) {
                            thisElement.find('img').attr('src', e.target.result);
                            thisElement.fadeIn(300);
                            thisElement.find('h3').hide();
                        };
                        uploadedFile.readAsDataURL(input.files[0]);
                    }
                }

                $(this).on("change", function () {
                    proPicURL(this);
                });
            });
        })
        $('.remove-addition-image-for-product-gallery').on('click',function (){
            $('#'+$(this).data('section-remove-id')).remove();
        })


        $(document).ready(function () {
            setTimeout(function () {
                let category = $("#category_id").val();
                let sub_category = $("#sub-category-select").attr("data-id");
                let sub_sub_category = $("#sub-sub-category-select").attr("data-id");
                getRequestFunctionality('{{ route('vendor.products.get-categories') }}?parent_id=' + category + '&sub_category=' + sub_category, 'sub-category-select', 'select');
                getRequestFunctionality('{{ route('vendor.products.get-categories') }}?parent_id=' + sub_category + '&sub_category=' + sub_sub_category, 'sub-sub-category-select', 'select');
            }, 100)
        });

        updateProductQuantity();
    </script>

    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/vendor/product-add-colors-img.js') }}"></script>
@endpush
