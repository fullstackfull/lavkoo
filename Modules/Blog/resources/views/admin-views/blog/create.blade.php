@extends('layouts.admin.app')

@section('title', translate('Create_New_Blog'))

@push('css_or_js')
    <link href="{{ dynamicAsset(path: 'public/assets/back-end/plugins/summernote/summernote.min.css') }}" rel="stylesheet">
    <link href="{{ dynamicAsset(path: 'public/assets/back-end/libs/quill-editor/quill-editor.css') }}" rel="stylesheet">
@endpush

@section('content')
    <div class="content container-fluid service-description-wrapper">
        <form action="{{ route('admin.blog.store') }}" method="POST" enctype="multipart/form-data" id="blog-ajax-form" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation" novalidate>
            @csrf
            <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-1 fs-18-mobile">
                    <a href="{{ route('admin.blog.view') }}">
                        <i class="fi fi-rr-arrow-left"></i>
                    </a>
                    {{ translate('Create_New_Blog') }}
                </h2>
            </div>

           <div class="card card-body mb-3">
               <div class="row gy-4">
                   <div class="col-xl-6">
                       <div class="bg-section rounded p-12 p-sm-20 h-100">
                           <div class="form-group">
                               <div class="d-flex justify-content-between align-items-center gap-3 mb-2">
                                   <label for="name" class="form-label mb-0">
                                       {{ translate('Category') }}
                                       <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-title="{{ translate('select_a_category_from_the_dropdown_menu_to_assign_this_blog') }} {{ translate('if_no_categories_are_available_or_want_to_add_a_new_category_please_add_it_from_the_manage_category_section') }}">
                                           <i class="fi fi-sr-info"></i>
                                       </span>
                                   </label>
                                   <a data-bs-toggle="offcanvas" href="#offcanvasCategory" class="user-select-none">
                                       {{ translate('Manage_Category') }}
                                   </a>
                               </div>

                               <select class="custom-select" name="blog_category" id="blog-category-select"
                                       data-text="{{ translate('select') }}"
                                       data-route="{{ route('admin.blog.category.get-list') }}">
                                   <option value="" selected disabled>{{ translate('select') }}</option>
                                   @foreach($categories as $category)
                                       <option value="{{ $category->id }}">
                                           @if(getDefaultLanguage() == 'en')
                                               {{ $category->name }}
                                           @else
                                               {{ $category?->translations()->where('key', 'name')->where('locale', getDefaultLanguage())->first()?->value ?? $category?->name }}
                                           @endif
                                       </option>
                                   @endforeach
                               </select>
                           </div>
                           <div class="form-group">
                               <label for="writer" class="form-label">
                                   {{ translate('Writer') }}
                               </label>
                               <input
                                   type="text"
                                   name="writer"
                                   id="writer"
                                   value="{{ old('writer') }}"
                                   class="form-control"
                                   placeholder="{{ translate('Ex') }}: {{ 'Jhon Milar' }}"
                                   maxlength="50"
                               >
                               <div class="d-flex justify-content-end">
                                   <small class="text-muted" id="writer-counter">{{ translate('Max 50 characters') }}</small>
                               </div>
                           </div>
                           <div class="form-group mb-0">
                               <label for="name" class="form-label">
                                   {{ translate('Publish_Date') }}
                                   <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-title="{{ translate('pick_the_date_that_you_want_to_show_for_customers_as_blog_publishing_date') }}">
                                       <i class="fi fi-sr-info"></i>
                                   </span>
                               </label>
                               <div class="position-relative">
                                   <input type="date" name="publish_date" class="form-control cursor-pointer"
                                          value="{{ date('Y-m-d') }}" placeholder="{{ translate('Select_Date') }}" autocomplete="off">
                               </div>
                           </div>
                       </div>
                   </div>

                   <div class="col-xl-6">
                       <div class="bg-section rounded p-12 p-sm-20 h-100">
                           <div class="d-flex flex-column gap-20 justify-content-between h-100 blog-image-div">
                               <div class="text-center">
                                   <label for="" class="form-label fw-semibold mb-1">
                                       {{ translate('Thumbnail') }}
                                       <span class="text-danger">*</span>
                                   </label>
                                   <p class="fs-12 mb-0">{{ translate('Upload_thumbnail_image') }}</p>
                               </div>
                               <div class="upload-file">
                                   <input type="file" name="image" class="upload-file__input single_file_input action-upload-color-image" data-max-size="{{ getFileUploadMaxSize() }}"
                                          accept="{{getFileUploadFormats(skip: '.svg')}}" data-required-msg="{{ translate('Thumbnail_is_required') }}" data-imgpreview="pre_img_viewer">
                                   <button type="button" class="remove_btn btn btn-danger btn-circle w-20 h-20 fs-8" style="opacity: 0;">
                                       <i class="fi fi-sr-cross"></i>
                                   </button>
                                   <label class="upload-file__wrapper w-325">
                                       <div class="upload-file-textbox text-center" style="">
                                           <img width="34" height="34" class="svg" src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}" alt="image upload">
                                           <h6 class="mt-1 fw-medium lh-base text-center">
                                               <span class="text-info">{{ translate('Click_to_upload') }}</span>
                                               <br>
                                              {{ translate(' Or_drag_and_drop') }}
                                           </h6>
                                       </div>
                                       <img class="upload-file-img" loading="lazy" src="" data-default-src="" alt="" style="display: none;">
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
                                   {{ getFileUploadFormats(skip: '.svg', asBladeMessage: true).' '. translate('Image_size'). ' : '. translate('Max').' '. getFileUploadMaxSize() . 'MB' }}
                                   <span class="fw-medium">(325 x 100 px)</span>
                               </p>
                               <span class=" mb-0 text-center error-msg text-danger d-none">{{ translate('Invalid_file_type') }}!</span>
                           </div>
                       </div>
                   </div>
               </div>
            </div>
            <div class="card card-body mb-3">
                <div class="bg-section rounded p-12 p-sm-20">
                    <div class="position-relative nav--tab-wrapper">
                        <ul class="nav nav-pills nav--tab lang_tab gap-3 mb-4">
                            @foreach($languages as $lang)
                                <li class="nav-item text-capitalize px-0">
                                    <a class="nav-link lang-link form-system-language-tab px-2 {{$lang == $defaultLanguage ? 'active':''}}" href="javascript:" id="{{$lang}}-link">{{getLanguageName($lang).'('.strtoupper($lang).')'}}</a>
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
                    <div>
                        @foreach($languages as $lang)
                            <div class="{{$lang != $defaultLanguage ? 'd-none':''}} form-system-language-form" id="{{$lang}}-form">
                                <div class="form-group mb-4">
                                    <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                                       <label for="name" class="form-label mb-0">
                                            {{ translate('title')}}
                                            ({{strtoupper($lang)}})
                                            <span class="input-required-icon">*</span>
                                        </label>
                                        @if(getActiveAIProviderConfigCache())
                                        <button type="button" class="btn bg-white text-primary bg-transparent shadow-none border-0 opacity-1 generate_btn_wrapper p-0 blog_title_auto_fill"
                                                id="title-{{  getLanguageCode(country_code: $lang) }}-action-btn"  data-lang="{{  getLanguageCode(country_code: $lang) }}" data-route="{{ route('admin.blog.title-auto-fill') }}" >
                                            <div class="btn-svg-wrapper">
                                                <img width="18" height="18" class=""
                                                src="{{ dynamicAsset(path: 'public/assets/back-end/img/ai/blink-right-small.svg') }}" alt="">
                                            </div>
                                            <span class="ai-text-animation d-none" role="status">
                                                {{ translate('Just_a_second') }}
                                            </span>
                                            <span class="btn-text">{{ translate('Generate') }}</span>
                                        </button>
                                        @endif
                                    </div>

                                    <div class="outline-wrapper" id="title-container-{{ $lang }}">
                                        <input type="text" name="title[{{$lang}}]" class="form-control" id="{{ getLanguageCode($lang) }}_title" placeholder="{{ translate('ex').':'.translate('LUX')}}"
                                               data-required-msg="{{ translate('Title_is_required') }}"
                                            {{ $lang == $defaultLanguage ? 'required' : '' }}>
                                    </div>

                                </div>
                            </div>
                            <input type="hidden" name="lang[{{$lang}}]" value="{{$lang}}" id="lang-{{$lang}}">
                            <div class="form-group mb-0 {{$lang != $defaultLanguage ? 'd-none':''}} form-system-description-language-form" id="{{ $lang}}-description-form">
                                <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                                    <label class="form-label mb-0">{{ translate('Description') }}({{strtoupper($lang)}}) <span class="input-required-icon">*</span></label>
                                    @if(getActiveAIProviderConfigCache())
                                   <button type="button" class="btn bg-white text-primary bg-transparent shadow-none border-0 opacity-1 generate_btn_wrapper p-0 blog_description_auto_fill"   id="description-{{ $lang }}-action-btn"  data-lang="{{getLanguageCode($lang) }}" data-route="{{ route('admin.blog.description-auto-fill') }}">
                                        <div class="btn-svg-wrapper">
                                            <img width="18" height="18" class=""
                                            src="{{ dynamicAsset(path: 'public/assets/back-end/img/ai/blink-right-small.svg') }}" alt="">
                                        </div>
                                        <span class="ai-text-animation d-none" role="status">
                                            {{ translate('Just_a_second') }}
                                        </span>
                                        <span class="btn-text">{{ translate('Generate') }}</span>
                                    </button>
                                    @endif
                                </div>

                                <div class="outline-wrapper" id="editor-container-{{ getLanguageCode($lang)}}">
                                    <div id="description-{{  getLanguageCode(country_code: $lang) }}-editor" class="quill-editor"></div>
                                    <textarea name="description[{{$lang}}]" id="description-{{$lang}}" style="display:none;" data-required-msg="{{ translate('Description_is_required') }}"
                                            {{ $lang == $defaultLanguage ? 'required' : '' }}></textarea>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            @include('blog::admin-views.blog.partials._seo-section')

            <input type="hidden" name="status" id="status" value="1">
            <input type="hidden" name="is_draft" id="is_draft" value="0">

            <div class="d-flex flex-wrap gap-2 gap-sm-3 justify-content-center justify-content-md-end mt-4">
                <button type="reset" id="reset" class="btn btn-secondary min-w-120 reset-form">
                    {{ translate('reset') }}
                </button>
                <a class="btn btn-outline-primary min-w-120 save-draft">
                    {{ translate('Save_to_Draft') }}
                </a>
                <button type="button" class="btn btn-primary min-w-120 publish" data-bs-toggle="modal" data-bs-target="#toggle-status-publish-modal">
                    {{ translate('Publish') }}
                </button>
            </div>
        </form>
    </div>
    @include('blog::admin-views.blog.partials._publish-modal')
    @include('blog::admin-views.blog.category.index')
    @if(getActiveAIProviderConfigCache())
    @include("blog::admin-views.blog.partials.ai-sidebar")
    @endif
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/libs/quill-editor/quill-editor.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/libs/quill-editor/quill-editor-init.js') }}"></script>

    @include('blog::admin-views.blog.partials._blog-script')
    @include('blog::admin-views.blog.category.partials._script')

    <script>
       // ---- for demo testing, remove below code after dynamic
       //  $(document).on('click', '.generate_btn_wrapper', function () {
       //     const $button = $(this);
       //     let $container;
       //
       //     // Case 1: Button is inside .outline-wrapper (like General setup divs)
       //     if ($button.closest('.outline-wrapper').length) {
       //         $container = $button.closest('.outline-wrapper');
       //     }
       //     // Case 2: Button is outside, like title/description
       //     else {
       //         $container = $button.closest('.form-group').find('.outline-wrapper');
       //     }
       //
       //     $container.addClass('outline-animating');
       //     $container.find('.bg-animate').addClass('active');
       //
       //     $button.prop('disabled', true);
       //
       //     $button.find('.btn-text').text('');
       //     const $aiText = $button.find('.ai-text-animation');
       //     $aiText.removeClass('d-none').addClass('ai-text-animation-visible');
       // });
       // document.querySelectorAll('.outline-wrapper').forEach(wrapper => {
       //     const child = wrapper.firstElementChild;
       //     if (child) {
       //         const radius = getComputedStyle(child).borderRadius;
       //         wrapper.style.borderRadius = radius;
       //     }
       // });

    </script>
@endpush
