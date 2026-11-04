@extends('layouts.admin.app')

@section('title', translate('category'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <h2 class="h1 mb-0">
                <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/brand-setup.png') }}" class="mb-1 me-1"
                    alt="">
                @if ($category['position'] == 1)
                    {{ translate('sub') }}
                @elseif($category['position'] == 2)
                    {{ translate('sub_Sub') }}
                @endif
                {{ translate('category') }}
                {{ translate('update') }}
            </h2>
        </div>

        <div class="row">
            <div class="col-md-12">
                <div class="card">

                    <div class="card-body text-start">
                        <form action="{{ route('admin.category.update') }}" method="POST"
                            enctype="multipart/form-data">
                            @csrf
                            <input type="hidden" name="id" value="{{ $category['id'] }}">
                            <div class="table-responsive w-auto overflow-y-hidden mb-4">
                                <div class="position-relative nav--tab-wrapper">
                                    <ul class="nav nav-pills nav--tab lang_tab" id="pills-tab" role="tablist">
                                        @foreach ($languages as $lang)
                                            <li class="nav-item px-0">
                                                <a data-bs-toggle="pill" data-bs-target="#{{ $lang }}-form"
                                                    role="tab"
                                                    class="nav-link px-2 {{ $lang == $defaultLanguage ? 'active' : '' }}"
                                                    id="{{ $lang }}-link">
                                                    {{ ucfirst(getLanguageName($lang)) . '(' . strtoupper($lang) . ')' }}
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
                            <div class="row mb-4">
                                <div
                                    class="{{ $category['parent_id'] == 0 || $category['position'] == 1 ? 'col-lg-6' : 'col-12' }}">
                                    <div class="bg-section rounded-8 p-20 w-100 h-100">
                                        <div class="tab-content" id="pills-tabContent">
                                            @foreach ($languages as $lang)
                                                <div class="tab-pane fade {{ $lang == $defaultLanguage ? 'show active' : '' }}"
                                                    id="{{ $lang }}-form"
                                                    aria-labelledby="{{ $lang }}-link" role="tabpanel">
                                                    <?php
                                                    if (count($category['translations'])) {
                                                        $translate = [];
                                                        foreach ($category['translations'] as $t) {
                                                            if ($t->locale == $lang && $t->key == 'name') {
                                                                $translate[$lang]['name'] = $t->value;
                                                            }
                                                        }
                                                    }

                                              $categoryName = ($category['position'] == 1) ? "sub_category_Name" : (($category['position'] == 2) ? "sub_sub_category_Name" : "category_Name");

                                                    ?>
                                                    <div class="form-group">
                                                        <label class="form-label text-capitalize">
                                                            {{ translate($categoryName) }} ({{ strtoupper($lang) }}) <span class="text-danger">*</span>
                                                        </label>
                                                        <input type="text" name="name[]"
                                                            value="{{ $lang == $defaultLanguage ? $category['name'] : $translate[$lang]['name'] ?? '' }}"
                                                            class="form-control"
                                                            placeholder="{{ translate('new_Category') }}"
                                                            {{ $lang == $defaultLanguage ? 'required' : '' }}>
                                                    </div>
                                                    <input type="hidden" name="lang[]" value="{{ $lang }}">
                                                    <input type="hidden" name="id" value="{{ $category['id'] }}">
                                                </div>
                                            @endforeach
                                        </div>

                                        @if($category['position'] == 1)
                                            <div class="form-group">
                                                <label class="form-label"
                                                       for="exampleFormControlSelect1">{{ translate('main_Category') }}
                                                    <span class="text-danger">*</span>
                                                    <span class="tooltip-icon"
                                                          data-bs-toggle="tooltip" data-bs-placement="top"
                                                          aria-label="{{ translate('select_the_main_category_under_which_this_item_will_be_listed.') }}"
                                                          data-bs-title="{{ translate('select_the_main_category_under_which_this_item_will_be_listed.') }}"
                                                    >
                                                    <i class="fi fi-sr-info"></i>
                                                </span>
                                                </label>
                                                <select class="custom-select form-control" name="parent_id" data-placeholder="{{ translate('Select') }}" required>
                                                    <option value=""></option>
                                                    @foreach($parentCategories as $parentCategory)
                                                        <option value="{{ $parentCategory['id'] }}"
                                                            {{ $parentCategory['id'] == $category['parent_id'] ? 'selected' : '' }}>
                                                            {{ $parentCategory['defaultname']}}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        @endif

                                        <div class="form-group">
                                            <label class="form-label text-capitalize" for="priority">
                                                {{ translate('priority') }}
                                                <span class="text-danger">*</span>
                                                <span class="tooltip-icon"
                                                      data-bs-toggle="tooltip" data-bs-placement="top"
                                                      aria-label="{{ translate('the_lowest_number_will_get_the_highest_priority') }}"
                                                      data-bs-title="{{ translate('the_lowest_number_will_get_the_highest_priority') }}"
                                                >
                                                    <i class="fi fi-sr-info"></i>
                                                </span>
                                            </label>
                                            <div class="select-wrapper">
                                                <select class="form-select" name="priority" id="" required>
                                                    @for ($index = 0; $index <= 10; $index++)
                                                        <option value="{{ $index }}"
                                                            {{ $category['priority'] == $index ? 'selected' : '' }}>
                                                            {{ $index }}</option>
                                                    @endfor
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {{-- Sub-categories get an icon of their own: the storefront draws them as an
                                     entry strip under the category banner, and without artwork they fall back
                                     to a letter chip. Optional below the top level. --}}
                                @php($iconRequired = $category['parent_id'] == 0)
                                <div class="col-lg-6 mt-4 mt-lg-0 from_part_2">
                                    <div
                                        class="d-flex justify-content-center align-items-center bg-section rounded-8 p-20 w-100 h-100">
                                        <div class="d-flex flex-column gap-30 error-wrapper">
                                            <div class="text-center">
                                                <label for="" class="form-label fw-semibold mb-1">
                                                    {{ $iconRequired ? translate('category_Logo') : translate('category_icon') }}
                                                    @if ($iconRequired)<span class="text-danger">*</span>@endif
                                                </label>
                                                <p class="fs-12 mb-0">
                                                    {{ $iconRequired ? translate('Upload_image') : translate('optional_shown_in_the_sub_category_strip_on_the_storefront') }}
                                                </p>
                                            </div>
                                            <div class="upload-file">
                                                {{-- Editing: an image already exists, so native `required` must not block the
                                                     submit (it silently ate every save on this page). --}}
                                                <input type="file" name="image" id="category-image"
                                                    class="upload-file__input single_file_input" data-existing-file="true"
                                                    accept=".webp, .jpg, .png, .jpeg, .gif">

                                                <label class="upload-file__wrapper">
                                                    <div class="upload-file-textbox text-center">
                                                        <img width="34" height="34" class="svg"
                                                            src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}"
                                                            alt="image upload">
                                                        <h6 class="mt-1 fw-medium lh-base text-center">
                                                            <span class="text-info">
                                                                {{ translate('Click_to_upload') }}
                                                            </span><br>
                                                            {{ translate('or_drag_and_drop') }}
                                                        </h6>
                                                    </div>
                                                    <img class="upload-file-img" loading="lazy"
                                                        src="{{ getStorageImages(path: $category->icon_full_url, type: 'backend-basic') ?? '' }}"
                                                        data-default-src="{{ getStorageImages(path: $category->icon_full_url, type: 'backend-basic') ?? '' }}"
                                                        alt="">
                                                </label>

                                                <div class="overlay">
                                                    <div
                                                        class="d-flex gap-10 justify-content-center align-items-center h-100">
                                                        <button type="button"
                                                            class="btn btn-outline-info icon-btn view_btn">
                                                            <i class="fi fi-sr-eye"></i>
                                                        </button>
                                                        <button type="button"
                                                            class="btn btn-outline-info icon-btn edit_btn">
                                                            <i class="fi fi-rr-camera"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>

                                            <p class="fs-10 mb-0 text-center">
                                                {{ translate('JPG,_JPEG,_PNG_image_size_:_max_2_MB') }} <span
                                                    class="text-dark fw-medium">{{ THEME_RATIO[theme_root_path()]['Category Image'] }}</span>
                                            </p>

                                            @include('admin-views.partials._mobile-image-field', [
                                                'storedImage' => $category->mobile_icon,
                                                'previewUrl' => getStorageImages(path: $category->mobile_icon_full_url, type: 'backend-basic'),
                                                'inputId' => 'category-mobile-image',
                                                'hint' => translate('shown_by_the_mobile_apps_wherever_this_category_appears'),
                                            ])
                                        </div>
                                    </div>
                                </div>
                            </div>

                            @include('admin-views.partials._page-banner-field', ['currentBanner' => app(\App\Services\EntityPageBannerService::class)->current('category', (int) ($category['id'] ?? 0))])

                            <div>
                                @if ($category['position'] == 2 || $category['position'] == 1)
                                    <div class="d-flex flex-wrap gap-3 justify-content-end mt-4">
                                        <button type="reset" id="reset" class="btn btn-secondary min-w-120">
                                            {{ translate('reset') }}
                                        </button>
                                        <button type="submit" class="btn btn-primary min-w-120">
                                            {{ translate('update') }}
                                        </button>
                                    </div>
                                @endif
                            </div>

                            @if ($category['parent_id'] == 0)
                                <div class="d-flex flex-wrap gap-3 justify-content-end mt-4">
                                    <button type="reset" id="reset"
                                        class="btn btn-secondary min-w-120">{{ translate('reset') }}</button>
                                    <button type="submit"
                                        class="btn btn-primary min-w-120">{{ translate('update') }}</button>
                                </div>
                            @endif
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/backend/admin/js/products/products-management.js') }}"></script>
@endpush
