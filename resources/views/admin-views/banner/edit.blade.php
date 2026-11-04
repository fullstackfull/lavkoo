@extends('layouts.admin.app')

@section('title', translate('banner'))

@section('content')
    <div class="content container-fluid">

        <div class="d-flex justify-content-between flex-wrap gap-2 align-items-center mb-3">
            <div>
                <h2 class="h1 mb-1 text-capitalize d-flex align-items-center gap-2">
                    <img width="20" src="{{ dynamicAsset(path: 'public/assets/back-end/img/banner.png') }}" alt="">
                    {{ translate('banner_update_form') }}
                </h2>
            </div>
            <div>
                <a class="btn btn-primary text-white" href="{{ route('admin.banner.list') }}">
                    <i class="fi fi-sr-angle-left"></i> {{ translate('back') }}
                </a>
            </div>
        </div>

        <div class="row text-start">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <form action="{{ route('admin.banner.update', [$banner['id']]) }}" method="post"
                              enctype="multipart/form-data" class="banner_form form-advance-validation  form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
                            @csrf
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="h-100">
                                        <div class="form-group">
                                            <input type="hidden" id="id" name="id">
                                        </div>

                                        <div class="form-group">
                                            <label for="name" class="form-label">{{ translate('banner_type') }} <span class="text-danger">*</span></label>
                                            <select class="custom-select" name="banner_type" required
                                                    id="banner_type_select">
                                                @foreach($bannerTypes as $key => $singleBanner)
                                                    <option
                                                        value="{{ $key }}" {{ $banner['banner_type'] == $key ? 'selected':'' }}>
                                                        {{ $singleBanner }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>

                                        {{-- Layout only applies to the grid banner types; the
                                             type select shows and hides this block. --}}
                                        <div class="form-group {{ in_array($banner['banner_type'], \App\Services\BannerService::GRID_TYPES) ? '' : 'd-none' }}"
                                             id="banner_layout_group">
                                            <div class="row g-3">
                                                <div class="col-md-7">
                                                    <label class="form-label">{{ translate('banner_layout') }}</label>
                                                    <select class="custom-select" name="layout">
                                                        <option value="full" {{ $banner['layout'] == 'full' ? 'selected' : '' }}>{{ translate('full_width_row') }}</option>
                                                        <option value="half" {{ $banner['layout'] == 'half' ? 'selected' : '' }}>{{ translate('half_width_beside_another') }}</option>
                                                        <option value="slider" {{ $banner['layout'] == 'slider' ? 'selected' : '' }}>{{ translate('inside_the_rotating_slider') }}</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-5">
                                                    <label class="form-label">{{ translate('priority') }}</label>
                                                    <input type="number" name="priority" class="form-control" min="0"
                                                           value="{{ $banner['priority'] }}">
                                                </div>
                                            </div>
                                            <small class="text-muted">{{ translate('lower_numbers_come_first_in_the_grid') }}</small>
                                        </div>

                                        <div class="form-group" id="banner_resource_type" >
                                            <label for="resource_id"
                                                   class="form-label">{{ translate('resource_type') }} <span class="text-danger">*</span></label>
                                            <select class="custom-select action-display-data" name="resource_type" required>
                                                <option
                                                    value="product" {{ $banner['resource_type'] == 'product'?'selected':'' }}>{{ translate('product') }}</option>
                                                <option
                                                    value="category" {{ $banner['resource_type'] == 'category'?'selected':'' }}>{{ translate('category') }}</option>
                                                <option
                                                    value="shop" {{ $banner['resource_type'] == 'shop'?'selected':'' }}>{{ translate('shop') }}</option>
                                                <option value="brand" {{ $banner['resource_type'] == 'brand'?'selected':'' }}>{{ translate('brand') }}</option>
                                                <option value="custom" {{ $banner['resource_type'] == 'custom' ? 'selected':'' }}>{{ translate('custom') }}</option>
                                            </select>
                                        </div>

                                        <div
                                            class="form-group mb-0 {{ $banner['resource_type'] == 'product'?'d--block':'d--none'}}"
                                            id="resource-product">
                                            <label for="product_id" class="form-label">{{ translate('product') }} </label>
                                            <select class="custom-select"
                                                    name="product_id" id="resource-product">
                                                @foreach($products as $product)
                                                    <option
                                                        value="{{ $product['id'] }}" {{ $banner['resource_id']==$product['id']?'selected':''}}>{{ $product['name'] }}</option>
                                                @endforeach
                                            </select>
                                        </div>

                                        <div
                                            class="form-group mb-0 {{ $banner['resource_type'] == 'category'?'d--block':'d--none' }}"
                                            id="resource-category">
                                            <label for="name" class="form-label">{{ translate('category') }}</label>
                                            <select class="custom-select"
                                                    name="category_id">
                                                @foreach($categories as $category)
                                                    <option
                                                        value="{{ $category['id'] }}" {{ $banner['resource_id']==$category['id']?'selected':''}}>{{ $category['label'] }}</option>
                                                @endforeach
                                            </select>
                                        </div>

                                        <div
                                            class="form-group mb-0 {{ $banner['resource_type'] == 'shop'?'d--block':'d--none' }}"
                                            id="resource-shop">
                                            <label for="shop_id" class="form-label">{{ translate('shop') }}</label>
                                            <select class="custom-select"
                                                    name="shop_id">
                                                @foreach($shops as $shop)
                                                    <option
                                                        value="{{ $shop['id'] }}" {{ $banner['resource_id']==$shop['id']?'selected':''}}>{{ $shop['name'] }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div
                                            class="form-group mb-0 {{ $banner['resource_type'] == 'brand' ? 'd--block' : 'd--none' }}"
                                            id="resource-brand">
                                            <label for="brand_id" class="form-label">{{ translate('brand') }}</label>
                                            <select class="custom-select"
                                                    name="brand_id">
                                                @foreach($brands as $brand)
                                                    <option
                                                        value="{{ $brand['id'] }}" {{ $banner['resource_id']==$brand['id']?'selected':''}}>{{ $brand['name'] }}</option>
                                                @endforeach
                                            </select>
                                        </div>

                                        <div class="form-group mb-0 {{ $banner['resource_type'] == 'custom' ? 'd--block' : 'd--none' }}" id="resource-custom-url">
                                            <label for="name" class="form-label">{{ translate('banner_URL') }} <span class="text-danger">*</span></label>
                                            <input type="url" name="url" class="form-control" id="url"
                                                   placeholder="{{ translate('enter_url') }}" value="{{ $banner['url'] }}">
                                        </div>

                                    </div>
                                </div>
                                <div class="col-md-6 d-flex flex-column justify-content-center">
                                    <div class="d-flex justify-content-center align-items-center bg-section rounded-8 p-20 w-100 h-100">
                                        <div class="d-flex flex-column gap-30 w-100">
                                            <div class="text-center">
                                                <label for="" class="form-label fw-semibold mb-1">
                                                    {{ translate('banner_image') }} <span class="text-danger">*</span>
                                                </label>
                                                <h4 class="mb-0"><span class="text-info-dark" id="theme_ratio"> ( {{ translate('ratio') }} 4:1 )</span></h4>
                                            </div>
                                            <div class="upload-file">
                                                <input type="file"
                                                       name="image"
                                                       id="banner"
                                                       class="upload-file__input single_file_input"
                                                       data-max-size="{{ getFileUploadMaxSize() }}"
                                                       accept="{{ getFileUploadFormats(skip: '.svg') }}"
                                                       data-required-msg="{{ translate('banner_image_is_required') }}"
                                                    {{ empty(getStorageImages(path: $banner['photo_full_url'], type: 'banner')) ? 'required' : '' }}>

                                                <div class="upload-file__wrapper ratio-4-1">
                                                    <div class="upload-file-textbox text-center">
                                                        <img width="34" height="34" class="svg"
                                                             src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}"
                                                             alt="image upload">
                                                        <h6 class="mt-1 fw-medium lh-base text-center">
                                                            <span class="text-info">
                                                                {{ translate('Click to upload') }}
                                                            </span>
                                                            <br>
                                                            {{ translate('or_drag_and_drop') }}
                                                        </h6>
                                                    </div>
                                                    <img class="upload-file-img" loading="lazy"
                                                         src="{{ getStorageImages(path:$banner['photo_full_url'],type: 'banner' ) }}"
                                                         data-default-src="{{ getStorageImages(path:$banner['photo_full_url'],type: 'banner' ) }}"
                                                         alt="">
                                                </div>
                                                <div class="overlay">
                                                    <div
                                                        class="d-flex gap-10 justify-content-center align-items-center h-100">
                                                        <button type="button" class="btn btn-outline-info icon-btn view_btn">
                                                            <i class="fi fi-sr-eye"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-outline-info icon-btn edit_btn">
                                                            <i class="fi fi-rr-camera"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            <p class="fs-12 text-center max-w-360 m-auto">
                                                {{ getFileUploadFormats(skip: '.svg', asBladeMessage: true).' '. translate('Image_size'). ' : '. translate('Max').' '. getFileUploadMaxSize() . 'MB' }}
                                            </p>
                                            <p class="fs-12 text-center max-w-360 m-auto">
                                                {{ translate('banner_Image_ratio_is_not_same_for_all_sections_in_website.') }}
                                                {{ translate('please_review_the_ratio_before_upload') }}
                                            </p>

                                            {{-- Optional phone-shaped image served to the mobile apps; leaving
                                                 it empty keeps whatever is already stored, and the checkbox
                                                 below is how it is taken away again. --}}
                                            <div class="text-center">
                                                <label for="banner-mobile" class="form-label fw-semibold mb-1">
                                                    {{ translate('mobile_app_image') }}
                                                    <span class="text-muted fs-12">({{ translate('optional') }})</span>
                                                </label>
                                                <h4 class="mb-0"><span class="text-info-dark"> ( {{ translate('ratio') }} 2:1 )</span></h4>
                                            </div>
                                            <div class="upload-file">
                                                <input type="file"
                                                       name="mobile_image"
                                                       id="banner-mobile"
                                                       class="upload-file__input single_file_input"
                                                       data-max-size="{{ getFileUploadMaxSize() }}"
                                                       accept="{{ getFileUploadFormats(skip: '.svg') }}">

                                                <div class="upload-file__wrapper ratio-2-1">
                                                    <div class="upload-file-textbox text-center">
                                                        <img width="34" height="34" class="svg"
                                                             src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}"
                                                             alt="image upload">
                                                        <h6 class="mt-1 fw-medium lh-base text-center">
                                                            <span class="text-info">{{ translate('Click to upload') }}</span>
                                                            <br>
                                                            {{ translate('or_drag_and_drop') }}
                                                        </h6>
                                                    </div>
                                                    <img class="upload-file-img" loading="lazy"
                                                         src="{{ $banner['mobile_photo'] ? getStorageImages(path: $banner['mobile_photo_full_url'], type: 'banner') : '' }}"
                                                         data-default-src="{{ $banner['mobile_photo'] ? getStorageImages(path: $banner['mobile_photo_full_url'], type: 'banner') : '' }}"
                                                         alt="">
                                                </div>
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
                                            <p class="fs-12 text-center max-w-360 m-auto">
                                                {{ translate('used_by_the_mobile_apps_where_a_wide_banner_would_crop_badly_on_a_phone') }}
                                            </p>

                                            @if ($banner['mobile_photo'])
                                                {{-- Uploading replaces; this is the only way to take one away.
                                                     The apps fall back to the image above, so removing it is a
                                                     safe action rather than a banner with nothing to draw. --}}
                                                <div class="d-flex justify-content-center align-items-center gap-2 mt-2">
                                                    <input class="form-check-input m-0" type="checkbox"
                                                           name="remove_mobile_image" value="1" id="remove-mobile-image">
                                                    <label class="form-check-label fs-12 m-0" for="remove-mobile-image">
                                                        {{ translate('remove_the_mobile_image_and_use_the_web_image') }}
                                                    </label>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-12 d-flex justify-content-end gap-3">
                                    <button type="submit" class="btn btn-primary px-4">
                                        {{ translate('update') }}
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/backend/admin/js/promotion/banner.js') }}"></script>
    <script>
        "use strict";
        $(document).on('ready', function () {
            getThemeWiseRatio();
        });
        let elementBannerTypeSelect = $('#banner_type_select');
        let requiredResourceByType = {!! json_encode(\App\Services\BannerService::REQUIRED_RESOURCE_TYPES) !!};

        function lockResourceForBannerType() {
            let required = requiredResourceByType[elementBannerTypeSelect.val()] || null;
            let resourceSelect = $('select[name="resource_type"]');
            resourceSelect.find('option').prop('disabled', false);
            if (required) {
                if (resourceSelect.val() !== required) resourceSelect.val(required).trigger('change');
                resourceSelect.find('option').each(function () {
                    if ($(this).val() !== required) $(this).prop('disabled', true);
                });
            }
        }

        elementBannerTypeSelect.on('change', function () {
            getThemeWiseRatio();
            lockResourceForBannerType();
        });
        $(document).on('ready', function () {
            lockResourceForBannerType();
        });
        function getThemeWiseRatio() {
            let bannerType = elementBannerTypeSelect.val();
            let theme = '{{ theme_root_path() }}';
            window.bannerGridTypes = {!! json_encode(\App\Services\BannerService::GRID_TYPES) !!};
            let themeRatio = {!! json_encode(THEME_RATIO) !!};
            let getRatio = themeRatio[theme][bannerType];
            $('#theme_ratio').text(getRatio);
        }
    </script>
@endpush
