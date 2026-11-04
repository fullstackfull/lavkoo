@extends('layouts.admin.app')

@section('title', translate('Brand_Update'))

@section('content')
    <div class="content container-fluid">

        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <img width="20" src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/brand.png') }}" alt="">
                {{ translate('Brand_Update') }}
            </h2>
        </div>

        <form action="{{ route('admin.brand.update', [$brand['id']]) }}" method="post" enctype="multipart/form-data"
              class="brand-setup-form form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate>
            @csrf

            <div class="card">
                <div class="card-body">
                    <div class="row gy-3">
                        <div class="col-lg-8">
                            <div class="p-12 p-sm-20 bg-section rounded h-100">

                                <div class="form-group mb-3">
                                    <div class="table-responsive w-auto overflow-y-hidden mb-4">
                                        <div class="position-relative nav--tab-wrapper">
                                            <ul class="nav nav-pills nav--tab lang_tab" id="pills-tab" role="tablist">
                                                @foreach($language as $lang)
                                                    <li class="nav-item p-0">
                                                        <a data-bs-toggle="pill" data-bs-target="#{{ $lang }}-form" role="tab"
                                                           class="nav-link px-2 {{ $lang == $defaultLanguage ? 'active' : '' }}"
                                                           id="{{ $lang }}-link">
                                                            {{ ucfirst(getLanguageName($lang)).'('.strtoupper($lang).')' }}
                                                        </a>
                                                    </li>
                                                @endforeach
                                            </ul>
                                            <div class="nav--tab__prev">
                                                <button type="button" class="btn btn-circle border-0 bg-white text-primary">
                                                    <i class="fi fi-sr-angle-left"></i>
                                                </button>
                                            </div>
                                            <div class="nav--tab__next">
                                                <button type="button" class="btn btn-circle border-0 bg-white text-primary">
                                                    <i class="fi fi-sr-angle-right"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="tab-content" id="pills-tabContent">
                                        @foreach($language as $lang)
                                            <?php
                                                if (count($brand['translations'])) {
                                                    $translate = [];
                                                    foreach ($brand['translations'] as $translations) {
                                                        if ($translations->locale == $lang && $translations->key == "name") {
                                                            $translate[$lang]['name'] = $translations->value;
                                                        }
                                                    }
                                                }
                                            ?>
                                            <div
                                                class="tab-pane fade {{ $lang == $defaultLanguage ? 'show active' : '' }}"
                                                id="{{ $lang }}-form" aria-labelledby="{{ $lang }}-link" role="tabpanel">
                                                <label class="form-label" for="exampleFormControlInput1">
                                                    {{ translate('brand_Name') }}
                                                    @if($lang == $defaultLanguage)
                                                        <span class="text-danger">*</span>
                                                    @endif
                                                    ({{ strtoupper($lang) }})
                                                </label>
                                                <input type="text" name="name[]" class="form-control"
                                                       value="{{ $lang == $defaultLanguage ? $brand['name'] : ($translate[$lang]['name'] ?? '') }}"
                                                       data-required-msg="{{ translate('brand_name_is_required') }}"
                                                       placeholder="{{ translate('ex') }} : {{ translate('LUX') }}" {{ $lang == $defaultLanguage? 'required':'' }}>
                                            </div>
                                            <input type="hidden" name="lang[]" value="{{ $lang }}">
                                        @endforeach
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">
                                        {{ translate('image_alt_text') }}
                                    </label>
                                    <input type="text" name="image_alt_text" class="form-control" value="{{ $brand['image_alt_text'] ?? '' }}"
                                           placeholder="{{ translate('ex').' : '. translate('apex_Brand') }}">
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <div class="p-12 p-sm-20 bg-section rounded h-100">
                                <div class="d-flex flex-column gap-20">
                                    <div class="d-flex flex-column align-items-center">
                                        <label for="" class="form-label fw-semibold mb-1 text-capitalize">
                                            {{ translate('Brand_image') }}
                                            <span class="text-danger">*</span>
                                        </label>
                                        <p class="fs-12 mb-0">{{ translate('Upload_your_Brand_Image') }}</p>
                                    </div>
                                    <div class="upload-file">
                                        <input type="file" name="image" id="brand-image" class="upload-file__input single_file_input"
                                               data-max-size="{{ getFileUploadMaxSize() }}"
                                               data-required-msg="{{ translate('Brand_image_is_required') }}"
                                               accept="{{ getFileUploadFormats(skip:'.svg,.gif') }}"
                                                {{ $brand?->image_full_url ? '' : 'required' }}>
                                        <label
                                            class="upload-file__wrapper">
                                            <div class="upload-file-textbox text-center">
                                                <img width="34" height="34" class="svg" src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}" alt="image upload">
                                                <h6 class="mt-1 fw-medium lh-base text-center">
                                                        <span class="text-info">
                                                            {{ translate('Click_to_upload') }}
                                                        </span>
                                                    <br>
                                                    {{ translate('or drag and drop') }}
                                                </h6>
                                            </div>
                                            <img class="upload-file-img" loading="lazy"
                                                 src="{{ getStorageImages($brand?->image_full_url, 'backend-brand') ?? '' }}"
                                                 data-default-src="{{ getStorageImages($brand?->image_full_url, 'backend-brand') ?? '' }}"
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
                                        {{ getFileUploadFormats(skip:'.svg,.gif') }} : {{ translate('Max_'.getFileUploadMaxSize().'_MB') }}
                                    </p>

                                    @include('admin-views.partials._mobile-image-field', [
                                        'storedImage' => $brand?->mobile_image,
                                        'previewUrl' => getStorageImages($brand?->mobile_image_full_url, 'backend-brand'),
                                        'inputId' => 'brand-mobile-image',
                                        'hint' => translate('shown_by_the_mobile_apps_wherever_this_brand_appears'),
                                    ])
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-body">
                    <div class="row align-items-center gy-3 mb-3 mb-sm-20">
                        <div class="col-md-9">
                            <div>
                                <h2 class="text-capitalize">
                                    {{ translate('Meta_Data_Setup') }}
                                </h2>
                                <p class="fs-12 mb-0">
                                    {{ translate('Include meta titles, descriptions, and images for your brand.') }}
                                    {{ translate('This will enhance visibility and help more people discover your content on search engines.') }}
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="row gy-3 mb-4">
                        <div class="col-lg-8">
                            <div class="p-12 p-sm-20 bg-section rounded h-100">
                                <div class="form-group mb-0">
                                    <label class="form-label" for="meta_title">
                                        {{ translate('Meta_Title') }}
                                        <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                              data-bs-placement="top"
                                              title="{{ translate('add_the_clearance_sale_taglines_etc_here.').' '.translate('this_meta_title_will_be_seen_on_search_engine_results_pages_and_while_sharing_the_clearance_sale_link_on_social_platforms') .' [ '. translate('character_Limit') }} : 50 ]">
                                            <i class="fi fi-sr-info"></i>
                                        </span>
                                    </label>
                                    <input type="text" name="meta_title" placeholder="{{ translate('meta_Title') }}"
                                           class="form-control" id="meta_title" data-maxlength="50" value="{{ $brand?->seo?->title ?? '' }}">
                                    <div class="d-flex justify-content-end">
                                        <span class="text-body-light">0/50</span>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label text-capitalize" for="meta_description">
                                        {{ translate('Meta_Description') }}
                                        <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                              data-bs-placement="top"
                                              title="{{ translate('write_a_short_description_of_the_clearance_sale.').' '.translate('this_description_will_be_seen_on_search_engine_results_pages_and_while_sharing_the_clearance_sale_link_on_social_platforms') .' [ '. translate('character_Limit') }} : 160 ]">
                                            <i class="fi fi-sr-info"></i>
                                        </span>
                                    </label>
                                    <textarea rows="2" type="text" name="meta_description" id="meta_description"
                                              class="form-control" data-maxlength="160"
                                              placeholder="{{ translate('Enter_your_meta_description') }}">{{ $brand?->seo?->description }}</textarea>
                                    <div class="d-flex justify-content-end">
                                        <span class="text-body-light">0/160</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <div class="p-12 p-sm-20 bg-section rounded h-100">
                                <div class="d-flex flex-column gap-20">
                                    <div class="d-flex flex-column align-items-center">
                                        <label for="" class="form-label fw-semibold mb-1 text-capitalize">
                                            {{ translate('Meta_Image') }}
                                            <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                                  aria-label="{{ translate('add_Meta_Image_in') }} {{ getFileUploadFormats(skip:'.svg,.gif') }} {{ translate('format_within') }} {{ getFileUploadMaxSize().'MB' }}, {{ translate('which_will_be_shown_in_search_engine_results') }}."
                                                  data-bs-title="{{ translate('add_Meta_Image_in') }} {{ getFileUploadFormats(skip:'.svg,.gif') }} {{ translate('format_within') }} {{ getFileUploadMaxSize().'MB' }}, {{ translate('which_will_be_shown_in_search_engine_results') }}."
                                                    >
                                                <i class="fi fi-sr-info"></i>
                                            </span>
                                        </label>
                                        <p class="fs-12 mb-0">{{ translate('Upload_your_Meta_Image') }}</p>
                                    </div>
                                    <div class="upload-file">
                                        <input type="file" name="meta_image" class="upload-file__input single_file_input"
                                               data-max-size="{{ getFileUploadMaxSize() }}"
                                               data-required-msg="{{ translate('Meta_image_is_required') }}"
                                               accept="{{ getFileUploadFormats(skip:'.svg,.gif') }}">
                                        <label
                                            class="upload-file__wrapper">
                                            <div class="upload-file-textbox text-center">
                                                <img width="34" height="34" class="svg" src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}" alt="image upload">
                                                <h6 class="mt-1 fw-medium lh-base text-center">
                                                        <span class="text-info">
                                                            {{ translate('Click_to_upload') }}
                                                        </span>
                                                    <br>
                                                    {{ translate('or drag and drop') }}
                                                </h6>
                                            </div>
                                            <img class="upload-file-img" loading="lazy"
                                                 src="{{ $brand?->seo?->image_full_url['path'] ? getStorageImages(path: $brand?->seo?->image_full_url['path'] ? $brand?->seo?->image_full_url : '', type: 'backend-banner') : '' }}"
                                                 data-default-src="{{ $brand?->seo?->image_full_url['path'] ? getStorageImages(path: $brand?->seo?->image_full_url['path'] ? $brand?->seo?->image_full_url : '', type: 'backend-banner') : '' }}"
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
                                        {{ getFileUploadFormats(skip:'.svg,.gif') }} : {{ translate('Max_'.getFileUploadMaxSize().'_MB') }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

    @include('admin-views.partials._page-banner-field', ['currentBanner' => app(\App\Services\EntityPageBannerService::class)->current('brand', (int) ($brand['id'] ?? 0))])
            <div class="d-flex flex-wrap justify-content-end gap-3 my-4">
                <button type="reset" class="btn btn-secondary px-4 w-120">{{translate('reset')}}</button>
                <button type="submit" class="btn btn-primary px-4 w-120">{{translate('save')}}</button>
            </div>
        </form>
    </div>
@endsection

@push('script')
    <script>
        $('.brand-setup-form').on('reset', function () {
            window.location.reload()
        });
    </script>
    <script src="{{ dynamicAsset(path: 'public/assets/backend/admin/js/products/products-management.js') }}"></script>
@endpush
