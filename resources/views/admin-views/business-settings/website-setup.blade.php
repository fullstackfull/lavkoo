@extends('layouts.admin.app')

@section('title', translate('Website_Setup'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 mb-sm-4">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                {{ translate('business_Setup') }}
            </h2>
        </div>
        @include('admin-views.business-settings.business-setup-inline-menu')

        <form data-k-save-bar action="{{ route('admin.business-settings.website-setup') }}" method="POST" enctype="multipart/form-data" id="website-setup-form-element" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
            @csrf
            <div class="d-flex flex-column gap-sm-20 gap-3 mb-3 sm-sm-4">
                <div class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                    <i class="fi fi-sr-lightbulb-on text-info"></i>
                    <span>
                        {{ translate('you_can_upload_your_business_logo,_icons,_and_other_important_files_here.') . ' ' . translate('all_changes_will_be_saved_and_applied_after_you_click_the_save_information_button.') }}
                    </span>
                </div>
                <div class="card">
                    <div class="card-header py-3">
                        <h2>{{ translate('Websites_&_Panels') }}</h2>
                        <p class="mb-0 fs-12 text-capitalize">
                            {{ translate('Setup_your_business_logo_and_icons') }}
                        </p>
                    </div>
                    <div class="card-body">
                        <div class="d-flex flex-column gap-sm-20 gap-3">
                            <div class="card card-sm shadow-1">
                                <div class="card-body">
                                    <div class="pb-12 pb-sm-20">
                                        <h3>{{ translate('Logo_&_Loader') }}</h3>
                                        <p class="mb-0 fs-12 text-capitalize">
                                            {{ translate('here_you_can_setup_logos_for_web_site_header,_footer,_website_favicon,_website_page_loader_gif_for_better_user_experience') }}
                                        </p>
                                        <div class="bg-warning bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center mt-3">
                                            <i class="fi fi-sr-info text-warning"></i>
                                            <span>
                                            {{ translate('please_use_the_suggested_image_&_gif_size_ratio_for_better_brand_presentation_in_customer_side') }}
                                        </span>
                                        </div>
                                    </div>
                                    <div class="p-12 p-sm-20 bg-section rounded">
                                        <div class="row g-4">
                                            <div class="col-md-6">
                                                <div class="card shadow-none">
                                                    <div class="card-body">
                                                        <div class="d-flex flex-column gap-20">
                                                            <div>
                                                                <label for="" class="form-label fw-semibold mb-1 text-capitalize">
                                                                    {{ translate('website_header_logo') }}
                                                                    <span class="text-danger">*</span>
                                                                </label>
                                                                <p class="fs-12 mb-0">{{ translate('it_will_show_at_website_header_left_side') }}</p>
                                                            </div>
                                                            <div class="upload-file">
                                                                <input type="file" name="company_web_logo" class="upload-file__input single_file_input" id="imageInput" value="{{ getStorageImages(path:$businessSetting['web_logo'],type: 'backend-placeholder') ?? '' }}"
                                                                       accept="{{ getFileUploadFormats(skip: '.svg') }}" {{ !empty($businessSetting['web_logo']) ? '' : 'required' }} data-required-msg="{{ translate('website_header_logo_field_is_required') }}" data-max-size="{{ getFileUploadMaxSize() }}">
                                                                <label
                                                                    class="upload-file__wrapper w-325">
                                                                    <div class="upload-file-textbox text-center {{ $businessSetting['web_logo']['path'] ? 'd-none' : '' }}">
                                                                        <img width="34" height="34" class="svg" src="{{ getStorageImages(path: $businessSetting['web_logo'] , type: 'backend-placeholder') ?? '' }}" alt="image upload">
                                                                        <h6 class="mt-1 fw-medium lh-base text-center">
                                                                            <span class="text-info text-capitalize">{{ translate('Click_to_upload') }}</span>
                                                                            <br>
                                                                            {{ translate('or_drag_and_drop') }}
                                                                        </h6>
                                                                    </div>
                                                                    <img class="upload-file-img" loading="lazy" src="{{ getStorageImages(path: $businessSetting['web_logo'] , type: 'backend-basic') }}" alt="" src="{{ $businessSetting['web_logo']['path'] ? getStorageImages(path:$businessSetting['web_logo'],type: 'backend-placeholder') ?? '' :  '' }}" data-default-src="{{ !empty($businessSetting['web_logo']) ? getStorageImages(path:$businessSetting['web_logo'],type: 'backend-placeholder') ?? '' :  '' }}">
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
                                                            <p class="fs-10 mb-0 text-center text-capitalize">
                                                                {{ getFileUploadFormats(skip: '.svg', asBladeMessage: true).' '. translate('Image_size'). ' : '. translate('Max').' '. getFileUploadMaxSize() . 'MB' }}
                                                                <span class="text-dark fw-medium">
                                                                    {{ "(325 x 100 px)" }}
                                                                </span>
                                                            </p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="col-md-6">
                                                <div class="card shadow-none">
                                                    <div class="card-body">
                                                        <div class="d-flex flex-column gap-20">
                                                            <div>
                                                                <label for="" class="form-label fw-semibold mb-1 text-capitalize">
                                                                    {{ translate('website_header_logo') }} ({{ translate('Mobile_View') }})
                                                                </label>
                                                                <p class="fs-12 mb-0">
                                                                    {{ translate('it_will_show_at_website_header_left_side_in_mobile_devices.') }}
                                                                </p>
                                                            </div>
                                                            <div class="upload-file">
                                                                <input type="file" name="company_mobile_logo" class="upload-file__input single_file_input" value="{{ getStorageImages(path: $businessSetting['mob_logo'],type: 'backend-placeholder') ?? '' }}" {{ $businessSetting['mob_logo']['path'] ? '' : 'required' }}
                                                                accept="{{ getFileUploadFormats(skip: '.svg') }}" data-required-msg="{{ translate('website_header_logo_mobile_view_field_is_required') }}" data-max-size="{{ getFileUploadMaxSize() }}">

                                                                <label
                                                                    class="upload-file__wrapper">
                                                                    <div class="upload-file-textbox text-center {{ $businessSetting['mob_logo']['path'] ? 'd-none' : '' }}">
                                                                        <img width="34" height="34" class="svg img-fluid" src="{{ getStorageImages(path: $businessSetting['mob_logo'] , type: 'backend-placeholder') }}" alt="image upload">
                                                                        <h6 class="mt-1 fw-medium lh-base text-center text-capitalize">
                                                                            <span class="text-info">{{ translate('Click_to_upload') }}</span>
                                                                            <br>
                                                                            {{ translate('or_drag_and_drop') }}
                                                                        </h6>
                                                                    </div>
                                                                    <img class="upload-file-img" loading="lazy" src="{{ !empty($businessSetting['mob_logo']['path']) ? getStorageImages(path:$businessSetting['mob_logo'],type: 'backend-placeholder') ?? '' :  '' }}" data-default-src="{{ !empty($businessSetting['mob_logo']['path']) ? getStorageImages(path:$businessSetting['mob_logo'],type: 'backend-placeholder') ?? '' :  '' }}" alt="">
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
                                                                {{ getFileUploadFormats(skip: '.svg,', asBladeMessage: true).' '. translate('Image_size'). ' : '. translate('Max').' '. getFileUploadMaxSize() . 'MB' }}
                                                                <span class="fw-medium">{{ "(325 x 100 px)" }}</span>
                                                            </p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="col-md-6">
                                                <div class="card shadow-none">
                                                    <div class="card-body">
                                                        <div class="d-flex flex-column gap-20">
                                                            <div>
                                                                <label for="" class="form-label fw-semibold mb-1 text-capitalize">
                                                                    {{ translate('website_footer_logo') }}
                                                                    <span class="text-danger">*</span>
                                                                </label>
                                                                <p class="fs-12 mb-0">{{ translate('it_will_show_at_website_footer_left_side') }}</p>
                                                            </div>
                                                            <div class="upload-file">
                                                                <input type="file" name="company_footer_logo" class="upload-file__input single_file_input"
                                                                       accept="{{ getFileUploadFormats(skip: '.svg') }}"  value="{{ getStorageImages(path:$businessSetting['footer_logo'],type: 'backend-placeholder') ?? '' }}" {{ !empty($businessSetting['footer_logo']) ? '' : 'required' }} data-max-size="{{ getFileUploadMaxSize() }}" data-required-msg="{{ translate('website_footer_logo_is_required') }}">
                                                                <label
                                                                    class="upload-file__wrapper w-325">
                                                                    <div class="upload-file-textbox text-center {{ $businessSetting['footer_logo']['path'] ? 'd-none' : '' }}">
                                                                        <img width="34" height="34" class="svg img-fluid" src="{{ getStorageImages(path:$businessSetting['footer_logo'],type: 'backend-placeholder') ?? '' }}" alt="image upload">
                                                                        <h6 class="mt-1 fw-medium lh-base text-center">
                                                                            <span class="text-info text-capitalize">{{ translate('Click_to_upload') }}</span>
                                                                            <br>
                                                                            {{ translate('or_drag_and_drop') }}
                                                                        </h6>
                                                                    </div>
                                                                    <img class="upload-file-img" loading="lazy" src="{{ !empty($businessSetting['footer_logo']['path']) ? getStorageImages(path:$businessSetting['footer_logo'],type: 'backend-placeholder') ?? '' :  '' }}" data-default-src="{{ !empty($businessSetting['footer_logo']) ? getStorageImages(path:$businessSetting['footer_logo'],type: 'backend-placeholder') ?? '' :  '' }}" alt="">
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
                                                                {{ getFileUploadFormats(skip: '.svg,', asBladeMessage: true).' '. translate('Image_size'). ' : '. translate('Max').' '. getFileUploadMaxSize() . 'MB' }}
                                                                <span class="fw-medium">{{ "(325 x 100 px)" }}
                                                                </span>
                                                            </p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="card shadow-none">
                                                    <div class="card-body">
                                                        <div class="d-flex flex-column gap-20">
                                                            <div>
                                                                <label for="" class="form-label fw-semibold mb-1 text-capitalize">
                                                                    {{ translate('Website_Favicon') }}
                                                                    <span class="text-danger">*</span>
                                                                </label>
                                                                <p class="fs-12 mb-0">{{ translate('it_will_show_as_a_website_favicon') }}.</p>
                                                            </div>
                                                            <div class="upload-file">
                                                                <input type="file" name="company_fav_icon" class="upload-file__input single_file_input" value="{{ getStorageImages(path:$businessSetting['fav_icon'],type: 'backend-placeholder') ?? '' }}" {{ !empty($businessSetting['fav_icon']) ? '' : 'required' }}
                                                                       accept="{{ getFileUploadFormats(skip: '.svg') }}" data-required-msg="{{ translate('website_favicon_is_required') }}" data-max-size="{{ getFileUploadMaxSize() }}">
                                                                <label
                                                                    class="upload-file__wrapper">
                                                                    <div class="upload-file-textbox text-center {{ $businessSetting['fav_icon']['path'] ? 'd-none' : '' }}">
                                                                        <img width="34" height="34" class="svg img-fluid" src="{{ getStorageImages(path: $businessSetting['fav_icon'] , type: 'backend-placeholder') }}" alt="image upload">
                                                                        <h6 class="mt-1 fw-medium lh-base text-center text-capitalize">
                                                                            <span class="text-info">{{ translate('Click_to_upload') }}</span>
                                                                            <br>
                                                                            {{ translate('or_drag_and_drop') }}
                                                                        </h6>
                                                                    </div>
                                                                    <img class="upload-file-img" loading="lazy" src="{{ !empty($businessSetting['fav_icon']['path']) ? getStorageImages(path:$businessSetting['fav_icon'],type: 'backend-placeholder') ?? '' :  '' }}" data-default-src="{{ !empty($businessSetting['fav_icon']) ? getStorageImages(path:$businessSetting['fav_icon'],type: 'backend-placeholder') ?? '' :  '' }}" alt="">
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
                                                                {{ getFileUploadFormats(skip: '.svg,', asBladeMessage: true).' '. translate('Image_size'). ' : '. translate('Max').' '. getFileUploadMaxSize() . 'MB' }}
                                                                <span class="fw-medium">{{ "(325 x 100 px)" }}</span>
                                                            </p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-12">
                                                <div class="card shadow-none">
                                                    <div class="card-body">
                                                        <div class="d-flex flex-column gap-20">
                                                            <div>
                                                                <label for="" class="form-label fw-semibold mb-1 text-capitalize">
                                                                   {{ translate('Loading_GIF') }}
                                                                </label>
                                                                <p class="fs-12 mb-0">{{ translate('it_will_show_when_website_load_any_page') }}.</p>
                                                            </div>
                                                            <div class="upload-file">
                                                                <input type="file" name="loader_gif" class="upload-file__input single_file_input" value="{{ getStorageImages(path:$businessSetting['loader_gif'],type: 'backend-placeholder') ?? '' }}" {{ !empty($businessSetting['loader_gif']) ? '' : 'required' }}
                                                                       accept="{{ getFileUploadFormats(skip: '.svg') }}" data-required-msg="{{ translate('website_loader_gif_is_required') }}" data-max-size="{{ getFileUploadMaxSize() }}">
                                                                <label
                                                                    class="upload-file__wrapper">
                                                                    <div class="upload-file-textbox text-center {{ isset($businessSetting['loader_gif']['path']) && $businessSetting['loader_gif']['path'] ? 'd-none' : '' }}">
                                                                        <img width="34" height="34" class="svg img-fluid" src="{{ getStorageImages(path: $businessSetting['loader_gif'] , type: 'backend-placeholder') }}" alt="image upload">
                                                                        <h6 class="mt-1 fw-medium lh-base text-center text-capitalize">
                                                                            <span class="text-info">{{ translate('Click_to_upload') }}</span>
                                                                            <br>
                                                                            {{ translate('or_drag_and_drop') }}
                                                                        </h6>
                                                                    </div>
                                                                    <img class="upload-file-img" loading="lazy" src="{{ !empty($businessSetting['loader_gif']['path']) ? getStorageImages(path:$businessSetting['loader_gif'], type: 'backend-placeholder-8-1') ?? '' :  '' }}" data-default-src="{{ !empty($businessSetting['loader_gif']) ? getStorageImages(path:$businessSetting['loader_gif'],type: 'backend-placeholder') ?? '' :  '' }}" alt="">
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
                                                                {{ getFileUploadFormats(skip: '.svg,', asBladeMessage: true).' '. translate('Image_size'). ' : '. translate('Max').' '. getFileUploadMaxSize() . 'MB' }}
                                                                <span class="fw-medium">{{ "(325 x 100 px)" }}</span>
                                                            </p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card card-sm shadow-1">
                                <div class="card-body">
                                    <div class="pb-12 pb-sm-20">
                                        <h3>{{ translate('Color_Settings') }}</h3>
                                        <p class="mb-0 fs-12 text-capitalize">
                                            {{ translate('select_the_primary_&_secondary_colors_for_the_website_&_panels') }}
                                        </p>
                                        <div class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center mt-3">
                                            <i class="fi fi-sr-lightbulb-on text-info"></i>
                                            <span>{{ translate('primary_color_used_in_website_header,_sections_&_button._secondary_color_used_in_button_in_websites._panel_sidebar_color_only_used_in_panel_sidebar_menu_background_color_primary_light_color_are_used_in_website_cards_&_sections') }}</span>
                                        </div>
                                    </div>
                                    <div class="p-12 p-sm-20 bg-section rounded">
                                        <div class="row g-4">
                                            <div class="col-lg-3">
                                                <div class="form-group">
                                                    <label class="form-label" for="">
                                                        {{ translate('Primary_Color') }}
                                                    </label>
                                                    <div class="d-flex align-items-center gap-10 bg-white border rounded py-2 px-10">
                                                        <input type="color" name="primary" value="{{ $businessSetting['primary_color'] }}"
                                                        class="form-control form-control_color color-code-preview">
                                                        <span class="fs-14 fw-medium text-dark color-code color-code-selection">#1455AC</span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-lg-3">
                                                <div class="form-group">
                                                    <label class="form-label" for="">
                                                        {{ translate('Secondary_Color') }}
                                                    </label>
                                                    <div class="d-flex align-items-center gap-10 bg-white border rounded py-2 px-10">
                                                        <input type="color" name="secondary" value="{{ $businessSetting['secondary_color'] }}"
                                                        class="form-control form-control_color color-code-preview">
                                                        <span class="fs-14 fw-medium text-dark color-code color-code-selection">#F58300</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card card-sm shadow-1">
                                <div class="card-body text-capitalize">
                                    <div class="pb-12 pb-sm-20">
                                        <h3>{{ translate('footer_app_download_button') }}</h3>
                                        <p class="mb-0 fs-12">
                                            {{ translate('configure_the_link_for_the_app_download_button_here') }}.
                                        </p>
                                    </div>
                                    <div class="p-12 p-sm-20 bg-section rounded">
                                        <div class="row g-4">
                                            @php($appStoreDownload = getWebConfig('download_app_apple_store'))
                                            <div class="col-lg-6">
                                                <div class="form-group">
                                                    <div class="d-flex justify-content-between align-items-center gap-3 mb-2">
                                                        <label class="form-label mb-0" for="">
                                                            {{ translate('apple_store_download_link') }}
                                                            <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="{{ translate('Allow_the_option_&_enter_the_download_link_for_your_app_on_the_apple_store') }}">
                                                                <i class="fi fi-sr-info"></i>
                                                            </span>
                                                        </label>
                                                        <label class="switcher switcher-sm" for="app-store-download-status">
                                                            <input
                                                                class="switcher_input custom-modal-plugin"
                                                                type="checkbox" value="1" name="app_store_download_status"
                                                                id="app-store-download-status"
                                                                {{ isset($appStoreDownload['status']) && $appStoreDownload['status'] == 1 ? 'checked' : '' }}
                                                                data-modal-type="input-change"
                                                                data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/app-store-download-on.png') }}"
                                                                data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/app-store-download-off.png') }}"
                                                                data-on-title="{{ translate('want_to_Turn_ON_the_App_Store_button') }}"
                                                                data-off-title="{{ translate('want_to_Turn_OFF_the_App_Store_button') }}"
                                                                data-on-message="<p>{{ translate('if_disabled_the_App_Store_button_will_be_hidden_from_the_website_footer') }}</p>"
                                                                data-off-message="<p>{{ translate('if_enabled_everyone_can_see_the_App_Store_button_in_the_website_footer') }}</p>">
                                                            <span class="switcher_control"></span>
                                                        </label>
                                                    </div>
                                                    <input type="url" name="app_store_download_url" class="form-control" {{ isset($appStoreDownload['status']) && $appStoreDownload['status'] == 1 ? 'required' : '' }}
                                                    value="{{ $appStoreDownload['link'] ?? '' }}"
                                                    placeholder="{{ translate('ex') . ' : ' . 'https://www.apple.com/app-store/' }}">
                                                </div>
                                            </div>
                                            @php($playStoreDownload = getWebConfig('download_app_google_store'))
                                            <div class="col-lg-6">
                                                <div class="form-group">
                                                    <div class="d-flex justify-content-between align-items-center gap-3 mb-2">
                                                        <label class="form-label mb-0" for="">
                                                            {{ translate('google_play_store_download_link') }}
                                                            <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="{{ translate('Allow_the_option_&_enter_the_download_link_for_your_app_on_the_google_play_store') }}">
                                                                <i class="fi fi-sr-info"></i>
                                                            </span>
                                                        </label>
                                                        <label class="switcher switcher-sm" for="play-store-download-status">
                                                            <input
                                                                class="switcher_input custom-modal-plugin"
                                                                type="checkbox" value="1" name="play_store_download_status"
                                                                id="play-store-download-status"
                                                                {{ isset($playStoreDownload['status']) && $playStoreDownload['status'] == 1 ? 'checked' : '' }}
                                                                data-modal-type="input-change"
                                                                data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/play-store-download-on.png') }}"
                                                                data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/play-store-download-off.png') }}"
                                                                data-on-title="{{ translate('want_to_Turn_ON_the_Google_Play_Store_button') }}"
                                                                data-off-title="{{ translate('want_to_Turn_OFF_the_Google_Play_Store_button') }}"
                                                                data-on-message="<p>{{ translate('if_disabled_the_Google_Play_Store_button_will_be_hidden_from_the_website_footer') }}</p>"
                                                                data-off-message="<p>{{ translate('if_enabled_everyone_can_see_the_Google_Play_Store_button_in_the_website_footer') }}</p>">
                                                            <span class="switcher_control"></span>
                                                        </label>
                                                    </div>
                                                    <input type="url" name="play_store_download_url" class="form-control"
                                                        value="{{ $playStoreDownload['link'] ?? '' }}" {{ isset($playStoreDownload['status']) && $playStoreDownload['status'] == 1 ? 'required' : '' }}
                                                        placeholder="{{ translate('ex') . ' : ' . 'https://play.google.com/store/apps' }}">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-end trans3 mt-4 action-btn-wrapper-container">
                <div class="d-flex justify-content-sm-end justify-content-center gap-3 flex-grow-1 flex-grow-sm-0 bg-white action-btn-wrapper trans3">
                    <button type="reset" class="btn btn-secondary px-3 px-sm-4 w-120">{{ translate('reset') }}</button>
                    <button type="submit" class="btn btn-primary px-3 px-sm-4">
                        <i class="fi fi-sr-disk"></i>
                        {{ translate('save_information') }}
                    </button>
                </div>
            </div>
            <x-k.save-bar />
        </form>
    </div>
    @include("layouts.admin.partials.offcanvas._website-setup")
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/backend/admin/js/business-settings/business-settings.js') }}"></script>
@endpush
