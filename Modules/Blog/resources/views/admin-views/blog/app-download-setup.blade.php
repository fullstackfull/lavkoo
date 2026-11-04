@extends('layouts.admin.app')

@section('title', translate('App_Download_Setup'))

@push('css_or_js')

@endpush

@section('content')
    @php
        $downloadAppStatus = getWebConfig(name: 'blog_feature_download_app_status');
        $downloadAppIcon = getWebConfig(name: 'blog_feature_download_app_icon');
        $downloadAppBackground = getWebConfig(name: 'blog_feature_download_app_background');
    @endphp
    <div class="content container-fluid">
        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img width="20" src="{{ dynamicAsset(path: 'public/assets/back-end/img/blog-logo.png') }}" alt="">
                {{ translate('Blog') }}
            </h2>
        </div>

        @include('blog::admin-views.blog.partials._blog-tab-menu')

        <div class="card collapsible-card-body">
            <div class="card-header d-flex justify-content-between align-items-center gap-2">
                <div class="d-flex gap-2 align-items-center">
                    <img width="30" src="{{ dynamicAsset(path: 'public/assets/back-end/img/download-app-button.png') }}" alt="">
                    <div>
                        <h3>{{ translate('download_app_button') }}</h3>
                        <p class="m-0">
                            {{ translate('here_you_can_setup_the_necessary_information_related_to_the_app_download_option') }}
                        </p>
                    </div>
                </div>
                <div>
                    <form action="{{ route('admin.blog.app-download-setup-status') }}" method="post"
                          id="blog-app-download-status-form" data-id="blog-app-download-status-form">
                        @csrf
                        <label class="switcher" for="app-download-setup-status">
                            <input
                                class="switcher_input custom-modal-plugin"
                                type="checkbox" value="1" name="status"
                                id="app-download-setup-status"
                                {{ $downloadAppStatus == 1 ? 'checked' : '' }}
                                data-modal-type="input-change-form"
                                data-modal-form="#blog-app-download-status-form"
                                data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/blog-status-on.png') }}"
                                data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/blog-status-off.png') }}"
                                data-on-title="{{ translate('are_you_sure_to_turn_on_the_download_app_button_status') }}"
                                data-off-title="{{ translate('are_you_sure_to_turn_off_the_download_app_button_status') }}"
                                data-on-message="<p>{{ translate('once_you_turn_on_this_blog_it_will_be_visible_to_the_blog_list_for_users.') }}</p>"
                                data-off-message="<p>{{ translate('when_you_turn_off_this_blog_it_will_not_be_visible_to_the_blog_list_for_users') }}</p>"
                                data-on-button-text="{{ translate('turn_on') }}"
                                data-off-button-text="{{ translate('turn_off') }}">
                            <span class="switcher_control"></span>
                        </label>
                    </form>
                </div>
            </div>
            <div class="card-body collapsible-card-content">
                <form action="{{ route('admin.blog.app-download-setup') }}" method="post" enctype="multipart/form-data" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
                    @csrf
                    <div class="row mt-15">
                        <div class="col-lg-6">
                            <div class="bg-section rounded p-4 mb-5">
                                <div class="position-relative nav--tab-wrapper">
                                    <ul class="nav nav-pills nav--tab lang_tab gap-3 mb-4">
                                        @foreach($languages as $lang)
                                            <li class="nav-item text-capitalize px-0">
                                                <span class="nav-link lang-link form-system-language-tab cursor-pointer px-2 {{ $lang == $defaultLanguage? 'active':''}}" id="{{ $lang}}-link">{{ucfirst(getLanguageName($lang)).'('.strtoupper($lang).')'}}
                                                </span>
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
                                        <div class="form-group {{ $lang != $defaultLanguage ? 'd-none':''}} form-system-language-form" id="{{ $lang}}-form">
                                            <label class="form-label">
                                                {{ translate('Title') }}({{strtoupper($lang)}})
                                                <span class="input-required-icon">{{ $lang == 'en' ? '*' : '' }}</span>
                                            </label>
                                            <input type="text" class="form-control" name="title[{{$lang}}]" value="{{ $titleData[$lang] ?? '' }}" placeholder="{{ translate('Enter_Title') }}" {{ $lang == $defaultLanguage? 'required':''}} data-required-msg="{{ translate('title_field_is_required') }}">
                                            <input type="hidden" name="lang[]" value="{{ $lang}}">
                                        </div>
                                        <div class="form-group mb-0 {{ $lang != $defaultLanguage ? 'd-none':''}} form-system-sub-title-language-form" id="{{ $lang}}-sub-title-form">
                                            <label class="form-label">{{ translate('Subtitle') }}({{strtoupper($lang)}})</label>
                                            <textarea class="form-control" name="sub_title[{{$lang}}]" placeholder="{{ translate('Enter_Subtitle') }}">{{ $subTitleData[$lang] ?? '' }}</textarea>
                                        </div>
                                    @endforeach
                                    <input name="position" value="0" class="d-none">
                                </div>
                            </div>
                            <div class="bg-section rounded p-4 mb-5 mb-lg-0">
                                <div class="mb-30">
                                    <h3>{{ translate('Download_button') }}</h3>
                                    <p class="m-0">
                                        {{ translate('Please_check_which_button_you_want_to_show_in_the_blog_section') }}
                                    </p>
                                </div>
                                <div>
                                    <label class="d-flex gap-2 mb-4 user-select-none cursor-pointer">
                                        <input type="checkbox" value="1" name="google_app_status" {{ $businessSetting['google_app_status'] == 1 ? 'checked' : ''  }}>
                                        <img width="22" src="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/play-store.svg') }}" alt="">
                                        <strong class="mt-1">{{ translate('Playstore_Button') }}</strong>
                                    </label>

                                    <label class="d-flex gap-2 mb-4 user-select-none cursor-pointer">
                                        <input type="checkbox" value="1" name="apple_app_status" {{ $businessSetting['apple_app_status'] == 1 ? 'checked' : ''  }}>
                                        <img width="22" src="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/apple-store.svg') }}" alt="">
                                        <strong class="mt-1">{{ translate('app_Store_Button') }}</strong>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="d-flex flex-column justify-content-between h-100">
                                <div class="d-flex justify-content-center flex-wrap gap-4 mb-4">
                                    <div class="d-flex flex-column gap-20">
                                        <div>
                                            <label for="" class="form-label fw-semibold mb-1">
                                                {{ translate('Icon') }}
                                                <span class="text-danger">*</span>
                                            </label>
                                        </div>
                                        <div class="upload-file">
                                            <input type="file" name="icon" class="upload-file__input single_file_input" accept="{{ getFileUploadFormats() }}" value="" {{$downloadAppIcon ? '' :  'required'}}
                                            data-required-msg="{{ translate('icon_field_is_required') }}" data-max-size="{{ getFileUploadMaxSize() }}"
                                            >
                                            <label class="upload-file__wrapper w-325">
                                                <div class="upload-file-textbox text-center">
                                                    <img width="34" height="34" class="svg" src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}" alt="image upload">
                                                    <h6 class="mt-1 fw-medium lh-base text-center">
                                                        <span class="text-info">Click to upload</span>
                                                        <br>
                                                        Or drag and drop
                                                    </h6>
                                                </div>
                                                <img class="upload-file-img" loading="lazy" src="{{ $downloadAppIcon ? getStorageImages(path: $downloadAppIcon , type: 'backend-basic') : '' }}" data-default-src="" alt="" style="display: none;">
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
                                        <p class="fs-10 mb-0 text-center">{{ getFileUploadFormats() }} . {{ translate('_Image_size_Max_') }} :  {{ getFileUploadMaxSize().'MB' }} <span class="fw-medium">(325 x 100 px)</span></p>
                                    </div>
                                    <div class="d-flex flex-column gap-20">
                                        <div>
                                            <label for="" class="form-label fw-semibold mb-1">
                                                {{ translate('Background') }}
                                                <span class="text-danger">*</span>
                                            </label>
                                        </div>
                                        <div class="upload-file">
                                            <input type="file"
                                                   name="image"
                                                   class="upload-file__input single_file_input"
                                                   accept="{{ getFileUploadFormats() }}"
                                                   value=""
                                                   {{ $downloadAppBackground ? '' : 'required' }}
                                                   data-required-msg="{{ translate('image_field_is_required') }}" data-max-size="{{ getFileUploadMaxSize() }}">

                                            <label class="upload-file__wrapper w-325">
                                                <div class="upload-file-textbox text-center" style="">
                                                    <img width="34" height="34" class="svg" src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}" alt="image upload">
                                                    <h6 class="mt-1 fw-medium lh-base text-center">
                                                        <span class="text-info">Click to upload</span>
                                                        <br>
                                                        Or drag and drop
                                                    </h6>
                                                </div>
                                                <img class="upload-file-img" loading="lazy" src="{{ $downloadAppBackground ? getStorageImages(path: $downloadAppBackground , type: 'backend-basic') : '' }}" data-default-src="" alt="" style="display: none;">
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
                                        <p class="fs-10 mb-0 text-center">{{ getFileUploadFormats() }} . {{ translate('_Image_size_Max_') }} :  {{ getFileUploadMaxSize().'MB' }} <span class="fw-medium">(325 x 100 px)</span></p>
                                    </div>
                                </div>
                                <div class="d-flex flex-wrap gap-3 justify-content-end">
                                    <button type="reset" id="reset"
                                            class="btn btn-secondary">{{ translate('Reset') }}</button>
                                    <button type="submit" class="btn btn-primary">{{ translate('Save') }}</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('script')
    <script>
        'use strict';
        $(document).ready(function () {
            // ---- single image upload starts
            $('.single_file_input').on('change', function (event) {
                var file = event.target.files[0];
                var $card = $(event.target).closest('.upload-file');
                var $textbox = $card.find('.upload-file-textbox');
                var $imgElement = $card.find('.upload-file-img');
                var $editBtn = $card.find('.edit-btn');
                var $removeBtn = $card.find('.remove-btn');

                if (file) {
                    var reader = new FileReader();
                    reader.onload = function (e) {
                        $textbox.hide();
                        $imgElement.attr('src', e.target.result).show();
                        $editBtn.removeClass('d-none');
                    };
                    reader.readAsDataURL(file);
                }
            });

            $('.edit-btn').on('click', function () {
                var $card = $(this).closest('.upload-file');
                var $fileInput = $card.find('.single_file_input');

                $fileInput.click();
            });

            // Check for a valid src on load to handle pre-existing images
            $('.upload-file').each(function () {
                var $card = $(this);
                var $textbox = $card.find('.upload-file-textbox');
                var $imgElement = $card.find('.upload-file-img');
                var $removeBtn = $card.find('.remove-btn');

                // If there's already a valid image source
                if ($imgElement.attr('src') && $imgElement.attr('src') !== window.location.href) {
                    $textbox.hide();
                    $imgElement.show();
                    $removeBtn.removeClass('d-none');
                }
            });

            $('.remove-btn').click(function () {
                var $card = $(this).closest('.upload-file');
                var $textbox = $card.find('.upload-file-textbox');
                $card.find('.single_file_input').val('');
                $card.find('.upload-file-img').css('display', 'none');
                $textbox.show();
            });


            // when the page loads, check if the image has a valid src
            const $img = $('.upload-file-img');
            const $input = $('.upload-file__input');

            const imgSrc = $img.attr('src');
            const hasImage = imgSrc && imgSrc.trim() !== '';

            if (!hasImage) {
                $input.prop('required', true);
            } else {
                $input.prop('required', false);
            }

            $('#blog-app-download-status-form').on('submit', function (event) {
                event.preventDefault();
                $.ajax({
                    url: $(this).attr('action'),
                    method: 'POST',
                    data: $(this).serialize(),
                    success: function (response) {
                        toastMagic.success(response.message);
                    },
                });
            });

        });
    </script>
@endpush
