<form
    @if ($categoryType == 'sub_category')
        action="{{ route('admin.sub-category.store') }}"
    @elseif($categoryType == 'sub_sub_category')
        action="{{ route('admin.sub-sub-category.store') }}"
    @else
        action="{{ route('admin.category.store') }}"
    @endif
    method="POST" class="form-advance-validation form-advance-file-validation non-ajax-form-validate"
      enctype="multipart/form-data" >
    @csrf
    <div class="offcanvas offcanvas-end" tabindex="-1" id="categoryAddOffcanvas"
         aria-labelledby="categoryAddOffcanvasLabel" style="--bs-offcanvas-width: 500px;">
        <div class="offcanvas-header bg-body">
            <h3 class="mb-0">
                @if ($categoryType == 'sub_category')
                    {{ translate('Add_Sub_Category') }}
                @elseif($categoryType == 'sub_sub_category')
                    {{ translate('Add_Sub_Sub_Category') }}
                @else
                    {{ translate('Add_Category') }}
                @endif
            </h3>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
            <div class="d-flex flex-column gap-20">
                @if($categoryType == 'category')
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <label for="" class="form-label mb-0 flex-grow-1 d-flex align-items-center gap-1">
                            {{ translate('availability') }}
                            <span class="tooltip-icon"
                                  data-bs-toggle="tooltip" data-bs-placement="bottom"
                                  aria-label="{{ translate('turn_on_to_show_this_category_as_a_home_category,_or_turn_off_to_hide_it.') }}"
                                  data-bs-title="{{ translate('turn_on_to_show_this_category_as_a_home_category,_or_turn_off_to_hide_it.') }}"
                            >
                            <i class="fi fi-sr-info d-flex"></i>
                        </span>
                        </label>
                        <label
                            class="d-flex justify-content-between align-items-center gap-2 border rounded px-3 py-10 user-select-none flex-grow-1">
                            <span class="fw-medium text-dark">{{ translate('status') }}</span>
                            <label class="switcher">
                                <input type="checkbox" class="switcher_input" value="1" id="" name="home_status"
                                       checked>
                                <span class="switcher_control"></span>
                            </label>
                        </label>
                    </div>
                @endif

                <div class="p-12 p-sm-20 bg-section rounded">
                    @if ($categoryType == 'sub_category')
                        <input type="hidden" name="position" value="1">
                    @elseif($categoryType == 'sub_sub_category')
                        <input type="hidden" name="position" value="2">
                    @else
                        <input type="hidden" name="position" value="0">
                    @endif

                    <div class="table-responsive w-auto overflow-y-hidden mb-20">
                        <div class="position-relative nav--tab-wrapper">
                            <ul class="nav nav-pills nav--tab lang_tab px-0" id="pills-tab" role="tablist">
                                @foreach ($languages as $lang)
                                    <li class="nav-item px-0">
                                        <a data-bs-toggle="pill" data-bs-target="#{{ $lang }}-add-form-category-name" role="tab"
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
                    <div class="tab-content" id="pills-tabContent">
                        @foreach ($languages as $lang)
                            <div class="tab-pane fade {{ $lang == $defaultLanguage ? 'show active' : '' }}"
                                 id="{{ $lang }}-add-form-category-name"
                                 aria-labelledby="{{ $lang }}-link" role="tabpanel">
                                <?php
                                    $categoryName = ($categoryType == 'sub_category') ? "sub_category_Name" : (($categoryType == 'sub_sub_category') ? "sub_sub_category_Name" : "category_Name");
                                    $validationMessage = ($categoryType == 'sub_category') ? "sub_category_name_is_required" : (($categoryType == 'sub_sub_category') ? "sub_sub_category_name_is_required" : "category_name_is_required");
                                ?>
                                <div class="form-group mb-20">
                                    <label class="form-label text-capitalize">
                                        {{ translate($categoryName) }} ({{ strtoupper($lang) }})
                                        @if($lang == $defaultLanguage)<span class="text-danger">*</span>@endif
                                    </label>
                                    <input type="text" name="name[]"
                                           value="" data-maxlength="50"
                                           data-required-msg="{{ translate($validationMessage) }}"
                                           class="form-control"
                                            @if ($categoryType == 'sub_category')
                                                placeholder="{{ translate('type_sub_category_name') }}"
                                            @elseif($categoryType == 'sub_sub_category')
                                                placeholder="{{ translate('type_sub_sub_category_name') }}"
                                            @else
                                                placeholder="{{ translate('type_category_name') }}"
                                            @endif
                                        {{ $lang == $defaultLanguage ? 'required' : '' }}>
                                    <div class="d-flex justify-content-end">
                                        <span class="text-body-light">0/50</span>
                                    </div>
                                </div>
                                <input type="hidden" name="lang[]" value="{{ $lang }}">
                            </div>
                        @endforeach
                    </div>

                    @if($categoryType == 'sub_category' && isset($parentCategories))
                        <div class="form-group mb-20">
                            <label class="form-label d-flex align-items-center gap-1"
                                   for="exampleFormControlSelect1">{{ translate('main_Category') }}
                                <span class="text-danger">*</span>
                                <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
                                      aria-label="{{ translate('select_the_main_category_under_which_this_item_will_be_listed.') }}"
                                      data-bs-title="{{ translate('select_the_main_category_under_which_this_item_will_be_listed.') }}">
                                    <i class="fi fi-sr-info d-flex"></i>
                                </span>
                            </label>
                            <select class="custom-select form-control" name="parent_id"
                                    data-placeholder="{{ translate('Select') }}" data-required-msg="{{ translate('main_category_is_required') }}" required>
                                <option value=""></option>
                                @foreach($parentCategories as $parentCategory)
                                    <option value="{{ $parentCategory['id'] }}">
                                        {{ $parentCategory['defaultname']}}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    @if($categoryType == 'sub_sub_category' && isset($parentCategories))
                        <div class="form-group mb-20">
                            <label
                                class="form-label">{{ translate('main_Category') }}
                                <span class="text-danger">*</span></label>
                            <div class="select-wrapper">
                                <select class="form-select action-get-sub-category-onchange"
                                        data-required-msg="{{ translate('main_category_is_required') }}"
                                        id="main-category" data-route="{{ route('admin.sub-sub-category.getSubCategory') }}" required>
                                    <option disabled
                                            selected>{{ translate('Select_Main_Category') }}</option>
                                    @foreach($parentCategories as $category)
                                        <option
                                            value="{{ $category['id']}}">{{ $category['defaultName']}}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="form-group mb-20">
                            <label class="form-label text-capitalize" for="parent_id">
                                {{ translate('sub_category_Name') }}<span
                                    class="text-danger">*</span>
                            </label>
                            <div class="select-wrapper">
                                <select name="parent_id" id="parent_id" class="form-select">
                                    <option value="" disabled selected>
                                        {{ translate('Select_Sub_Category_First') }}
                                    </option>
                                </select>
                            </div>
                        </div>
                    @endif

                    <div class="form-group mb-20">
                        <label class="form-label text-capitalize d-flex align-items-center gap-1" for="priority">
                            {{ translate('priority') }}<span class="text-danger">*</span>
                            <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
                                  aria-label="{{ translate('the_lowest_number_will_get_the_highest_priority') }}"
                                  data-bs-title="{{ translate('the_lowest_number_will_get_the_highest_priority') }}">
                                    <i class="fi fi-sr-info d-flex"></i>
                                </span>
                        </label>
                        <div>
                            <select class="custom-select" name="priority" id=""
                                    data-placeholder="{{ translate('set_Priority') }}"
                                    data-required-msg="{{ translate('Priority_is_required') }}"
                                    required>
                                <option></option>
                                @for ($index = 0; $index <= 10; $index++)
                                    <option value="{{ $index }}">
                                        {{ $index }}
                                    </option>
                                @endfor
                            </select>
                        </div>
                    </div>

                    @if ($categoryType == 'category')
                        @if ($categoryWiseTax)
                            <div class="form-group mb-20">
                                <label class="form-label" for="tax-ids-for-category-add">
                                    {{ translate('Select_Vat/Tax_Rate') }}
                                    <span class="input-required-icon">*</span>
                                </label>

                                <select class="custom-select multiple-select2" id="tax-ids-for-category-add"
                                        name="tax_ids[]" multiple="multiple"
                                        data-placeholder="{{ translate('type_&_Select_Vat/Tax_Rate') }}">
                                    @foreach ($taxVats as $taxVat)
                                        <option value="{{ $taxVat->id }}"> {{ $taxVat->name }}
                                            ({{ $taxVat->tax_rate }}%)
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                    @endif

                    {{-- Every level gets an icon, not just the top one: the storefront draws
                         sub-categories as an entry strip under the category banner, and without
                         artwork they fall back to a letter chip. Required only for a main
                         category, so adding a sub-category stays a two-field job. --}}
                    @php($iconRequired = $categoryType == 'category')
                    <div class="d-flex flex-column gap-20">
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
                                <input type="file" name="image" id="category-image"
                                    class="upload-file__input single_file_input"
                                    data-max-size="{{ getFileUploadMaxSize() }}"
                                    @if ($iconRequired) data-required-msg="{{ translate('category_Logo_is_required') }}" @endif
                                    accept="{{getFileUploadFormats(skip: '.svg,.gif')}},image/*">
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
                                    <img class="upload-file-img" loading="lazy" src="" data-default-src="" alt="">
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
                                {{ translate(getFileUploadFormats(skip: '.svg,.gif', asMessage: 'true')) }} {{ translate(':_max_2_MB') }}
                                <span class="text-dark fw-medium">
                                    {{ THEME_RATIO[theme_root_path()]['Category Image'] }}
                                </span>
                            </p>

                            @include('admin-views.partials._mobile-image-field', [
                                'storedImage' => null,
                                'inputId' => 'category-add-mobile-image',
                                'hint' => translate('shown_by_the_mobile_apps_wherever_this_category_appears'),
                            ])

                    </div>
                </div>


                <div class="p-12 p-sm-20 bg-section rounded">
                    <h3 class="border-bottom pb-2 mb-3">{{ translate('Meta_Data_Setup') }}</h3>
                    <div class="form-group mb-20">
                        <label class="form-label d-flex align-items-center gap-1">
                            {{ translate('meta_Title') }}
                            <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                  data-bs-placement="top"
                                  title="{{ translate('add_the_category_name_taglines_etc_here.').' '.translate('this_meta_title_will_be_seen_on_search_engine_results_pages_and_while_sharing_the_category_link_on_social_platforms') .' [ '. translate('character_Limit') }} : 50 ]">
                                <i class="fi fi-sr-info d-flex"></i>
                            </span>
                        </label>
                        <input type="text" name="meta_title" placeholder="{{ translate('meta_Title') }}" data-maxlength="50"
                               class="form-control" id="meta_title">
                        <div class="d-flex justify-content-end">
                            <span class="text-body-light">0/50</span>
                        </div>
                    </div>
                    <div class="form-group mb-20">
                        <label class="form-label d-flex align-items-center gap-1">
                            {{ translate('meta_Description') }}
                            <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                  data-bs-placement="top"
                                  title="{{ translate('write_a_short_description_of_the_category.').' '.translate('this_description_will_be_seen_on_search_engine_results_pages_and_while_sharing_the_category_link_on_social_platforms') .' [ '. translate('character_Limit') }} : 160 ]">
                                <i class="fi fi-sr-info d-flex"></i>
                            </span>
                        </label>
                        <textarea rows="4" type="text" name="meta_description" id="meta_description" data-maxlength="160"
                                  class="form-control" placeholder="{{ translate('Enter_your_meta_description') }}"></textarea>
                        <div class="d-flex justify-content-end">
                            <span class="text-body-light">0/160</span>
                        </div>
                    </div>

                    <div class="d-flex flex-column gap-20">
                        <div class="text-center">
                            <label for="" class="form-label fw-semibold mb-1 d-flex align-items-center justify-content-center gap-1">
                                {{ translate('meta_Image') }}
                                <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                      aria-label="{{ translate('add_Meta_Image_in') }} JPG, PNG or JPEG {{ translate('format_within') }} 2MB, {{ translate('which_will_be_shown_in_search_engine_results') }}."
                                      data-bs-title="{{ translate('add_Meta_Image_in') }} JPG, PNG or JPEG {{ translate('format_within') }} 2MB, {{ translate('which_will_be_shown_in_search_engine_results') }}."
                                >
                                    <i class="fi fi-sr-info d-flex"></i>
                                </span>
                            </label>
                            <p class="fs-12 mb-0"> {{ translate('Upload_meta_image') }}</p>
                        </div>
                        <div class="d-flex justify-content-center">
                            <div class="upload-file">
                                <input type="file" name="meta_image"
                                       class="upload-file__input single_file_input"
                                       data-max-size="{{ getFileUploadMaxSize() }}"
                                       id="meta_image_input"
                                       accept="{{ getFileUploadFormats(skip:'.svg,.gif') }}"
                                       value=""
                                >
                                <label class="upload-file__wrapper ratio-2-1">
                                    <div class="upload-file-textbox text-center">
                                        <div class="d-flex gap-2 align-items-center justify-content-center flex-wrap">
                                            <img width="34" height="34"
                                                src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}"
                                                alt="image upload">
                                            <h6 class="mt-1 mb-0 fw-medium lh-base">
                                                <span class="text-info">{{ translate('Click_to_upload') }}</span>
                                                <br>
                                                {{ translate('or_drag_and_drop') }}
                                            </h6>
                                        </div>
                                    </div>
                                    <img class="upload-file-img pre-meta-image-viewer" loading="lazy" src=""
                                         data-default-src="" alt="">
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
                        <p class="fs-10 mb-0 text-center">
                            {{ translate(getFileUploadFormats(skip: '.svg,.gif', asMessage: 'true')) }} {{ translate(':_max_2_MB') }}
                            <span class="text-dark fw-medium">
                                {{ THEME_RATIO[theme_root_path()]['Meta Thumbnail'] }}
                            </span>
                        </p>
                    </div>
                </div>

            </div>
        </div>

        <div class="offcanvas-footer shadow-popup">
    @include('admin-views.partials._page-banner-field', ['currentBanner' => null])
            <div class="d-flex justify-content-center flex-wrap gap-3 bg-white px-3 py-2">
                <button type="reset" class="btn btn-secondary flex-grow-1">
                    {{ translate('reset') }}
                </button>
                <button type="submit" class="btn btn-primary flex-grow-1">
                    {{ translate('Submit') }}
                </button>
            </div>
        </div>
    </div>
</form>
