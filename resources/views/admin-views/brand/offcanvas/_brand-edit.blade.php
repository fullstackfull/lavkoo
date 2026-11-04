<form action="{{ route('admin.brand.update', [$brand['id']]) }}" method="post"
    enctype="multipart/form-data" class="brand-setup-form form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
    @csrf
    <div class="offcanvas offcanvas-end" tabindex="-1" id="brandEditOffcanvas-{{$brand['id']}}"
         aria-labelledby="brandEditOffcanvasLabel" style="--bs-offcanvas-width: 500px;">
        <div class="offcanvas-header gap-3 justify-content-between bg-body">
            <h2 class="mb-0 flex-grow-1">
                {{ translate('Edit_Brand') }}
            </h2>
            <a class="d-flex gap-1 align-items-center fs-14 text-primary text-decoration-none d-none brand-edit-back-btn"
               data-bs-toggle="offcanvas" href="#brandViewOffcanvas-{{ $brand['id'] }}">
                <i class="fi fi-rr-arrow-left mt-2px"></i>
                <span class="fw-medium">{{ translate('Back') }}</span>
            </a>
            <button type="button" class="btn btn-circle bg-white text-dark fs-10" style="--size: 1.5rem;" data-bs-dismiss="offcanvas" aria-label="Close">
                <i class="fi fi-rr-cross"></i>
            </button>
        </div>
        <div class="offcanvas-body">
            <div class="d-flex flex-column gap-20">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 p-12 p-sm-20 bg-section rounded">
                    <label for="" class="form-label mb-0 flex-grow-1">
                        {{ translate('availability') }}
                        <span class="tooltip-icon"
                              data-bs-toggle="tooltip" data-bs-placement="bottom"
                              aria-label="{{ translate('Manage Brand status And deletion') }}"
                              data-bs-title="{{ translate('Manage Brand status And deletion') }}"
                        >
                        <i class="fi fi-sr-info"></i>
                    </span>
                    </label>
                    <label
                        class="d-flex justify-content-between align-items-center gap-2 border rounded px-3 py-10 bg-white user-select-none flex-grow-1">
                        <span class="fw-medium text-dark">{{ translate('status') }}</span>
                        <label class="switcher">
                            <input type="hidden" name="status" value="0">
                            <input type="checkbox" class="switcher_input" value="1" id="" name="status"  {{ $brand['status'] == 1 ? 'checked' : '' }}>
                            <span class="switcher_control"></span>
                        </label>
                    </label>
                </div>
                <div class="p-12 p-sm-20 bg-section rounded">
                    <div class="table-responsive w-auto overflow-y-hidden mb-20">
                        <div class="position-relative nav--tab-wrapper">
                            <ul class="nav nav-pills nav--tab lang_tab" id="pills-tab" role="tablist">
                                @foreach($language as $lang)
                                <li class="nav-item p-0">
                                    <a data-bs-toggle="pill" data-bs-target="#{{ $lang }}offcanvas-edit-form" role="tab"
                                        class="nav-link px-2 {{ $lang == $defaultLanguage ? 'active' : '' }}" id="{{ $lang }}offcanvas-edit-link">
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
                            <div class="tab-pane fade {{ $lang == $defaultLanguage ? 'show active' : '' }}" id="{{ $lang }}offcanvas-edit-form"
                                aria-labelledby="{{ $lang }}offcanvas-edit-link" role="tabpanel">
                                <div class="form-group mb-3">
                                    <label class="form-label" for="exampleFormControlInput1">
                                        {{ translate('brand_Name') }} ({{ strtoupper($lang) }})
                                        @if($lang == $defaultLanguage)
                                            <span class="text-danger">*</span>
                                        @endif
                                    </label>
                                    <input type="text" name="name[]" class="form-control"
                                           value="{{ $lang == $defaultLanguage ? $brand['name'] : ($translate[$lang]['name'] ?? '') }}"
                                           data-required-msg="{{ translate('brand_name_is_required') }}"
                                           placeholder="{{ translate('ex') }} : {{ translate('LUX') }}" {{ $lang == $defaultLanguage? 'required':'' }}>
                                </div>
                            </div>
                            <input type="hidden" name="lang[]" value="{{ $lang }}">
                        @endforeach
                         <div class="form-group mb-0">
                             <label class="form-label">
                                 {{ translate('image_alt_text') }}
                             </label>
                             <input type="text" name="image_alt_text" class="form-control" value="{{ $brand['image_alt_text'] ?? '' }}"
                                    placeholder="{{ translate('ex').' : '.translate('apex_Brand') }}">
                         </div>
                    </div>
                </div>
                <div class="p-12 p-sm-20 bg-section rounded">
                    <div class="d-flex flex-column gap-20 text-center">
                       <div>
                            <label class="form-label fw-semibold mb-1">{{ translate('Brand_image') }} <span class="text-danger">*</span></label>
                            <p class="fs-12 mb-0">{{ translate('Upload_your_Brand_Image') }}</p>
                        </div>
                        <div class="upload-file">
                            <input type="file" name="image" id="brand-image" class="upload-file__input single_file_input"
                                accept=".webp, .jpg, .jpeg, .png" value="" data-required-msg="{{ translate('brand_image_is_required') }}">
                            <label class="upload-file__wrapper">
                                <div class="upload-file-textbox text-center">
                                    <img width="34" height="34" class="svg"
                                        src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}"
                                        alt="image upload">
                                    <h6 class="mt-1 fw-medium lh-base text-center">
                                        <span class="text-info">
                                            {{ translate('Click_to_upload') }}
                                        </span>
                                        <br>
                                        {{ translate('or drag and drop') }}
                                    </h6>
                                </div>
                                <img class="upload-file-img" loading="lazy"
                                    src="{{ getStorageImages($brand->image_full_url, 'backend-brand') ?? '' }}"
                                    data-default-src="{{ getStorageImages($brand->image_full_url, 'backend-brand') ?? '' }}" alt="">
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
                            {{ getFileUploadFormats(skip: '.svg, .gif', asBladeMessage: true).' '. translate('Image_size'). ' : '. translate('Max').' '. getFileUploadMaxSize() . 'MB' }}
                            <span class="text-dark fw-medium">
                                {{ THEME_RATIO[theme_root_path()]['Category Image'] }}
                            </span>
                        </p>
                    </div>
                </div>
                <div class="p-12 p-sm-20 bg-section rounded">
                    <div class="border-bottom pb-2 mb-3">
                        <h2 class="text-capitalize mb-0">{{ translate('Meta_Data_Setup') }}</h2>
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label" for="meta_title">{{ translate('Meta_Title') }}
                            <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip" data-bs-placement="top" title="[{{ translate('character_Limit') }} : 50]">
                                <i class="fi fi-sr-info"></i>
                            </span>
                        </label>
                        <input type="text" name="meta_title" class="form-control" id="meta_title" value="{{ $brand?->seo?->title ?? '' }}" placeholder="{{ translate('meta_Title') }}" data-maxlength="50">
                        <div class="d-flex justify-content-end"><span class="text-body-light">0/50</span></div>
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label" for="meta_description">{{ translate('Meta_Description') }}
                            <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip" data-bs-placement="top" title="[{{ translate('character_Limit') }} : 160]">
                                <i class="fi fi-sr-info"></i>
                            </span>
                        </label>
                        <textarea rows="2"
                            name="meta_description"
                            id="meta_description"
                            class="form-control"
                            data-maxlength="160"
                            placeholder="{{ translate('Enter_your_meta_description') }}">{{ $brand?->seo?->description ?? '' }}</textarea>
                        <div class="d-flex justify-content-end"><span class="text-body-light">0/160</span></div>
                    </div>

                    <div class="d-flex flex-column gap-20 text-center">
                        <div>
                            <label class="form-label fw-semibold mb-1">
                                {{ translate('Meta_Image') }}
                            </label>
                            <p class="fs-12 mb-0">{{ translate('Upload_Meta_Image') }}</p>
                        </div>
                        <div class="upload-file">
                            <input type="file" name="meta_image" class="upload-file__input single_file_input" accept="{{ getFileUploadFormats(skip:'.svg,.gif') }}" data-max-size="{{ getFileUploadMaxSize() }}">
                            <label class="upload-file__wrapper ratio-2-1">
                                <div class="upload-file-textbox text-center">
                                    <div class="d-flex gap-2 align-items-center justify-content-center flex-wrap">
                                        <img width="34" height="34" class="svg" src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}" alt="image upload">
                                        <h6 class="mt-1 fw-medium lh-base text-center mb-0">
                                            <span class="text-info">{{ translate('Click_to_upload') }}</span><br>
                                            {{ translate('or drag and drop') }}
                                        </h6>
                                    </div>
                                </div>
                                <img class="upload-file-img show-in-meta-thumbnail" loading="lazy"
                                     src="{{ $brand?->seo?->image_full_url['path'] ? getStorageImages(path: $brand?->seo?->image_full_url['path'] ? $brand?->seo?->image_full_url : '', type: 'backend-banner') : '' }}"
                                     data-default-src="{{ $brand?->seo?->image_full_url['path'] ? getStorageImages(path: $brand?->seo?->image_full_url['path'] ? $brand?->seo?->image_full_url : '', type: 'backend-banner') : '' }}"
                                     alt="">
                            </label>
                            <div class="overlay">
                                <div class="d-flex gap-10 justify-content-center align-items-center h-100">
                                    <button type="button" class="btn btn-outline-info icon-btn view_btn">
                                        <i class="fi fi-sr-eye d-flex"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-info icon-btn edit_btn">
                                        <i class="fi fi-rr-camera d-flex"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <p class="fs-10 mb-0 text-center">{{ getFileUploadFormats(skip:'.svg,.gif') }} : {{ translate('Max_'.getFileUploadMaxSize().'_MB') }} <span class="fw-medium">(2:1)</span></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="offcanvas-footer shadow-popup">
    @include('admin-views.partials._page-banner-field', ['currentBanner' => app(\App\Services\EntityPageBannerService::class)->current('brand', (int) ($brand['id'] ?? 0))])
            <div class="d-flex justify-content-center flex-wrap gap-3 bg-white px-3 py-2">
                <button type="reset" id="reset" class="btn btn-secondary flex-grow-1">
                    {{ translate('reset') }}
                </button>
                <button type="submit" class="btn btn-primary flex-grow-1">
                    {{ translate('update') }}
                </button>
            </div>
        </div>
    </div>
</form>
