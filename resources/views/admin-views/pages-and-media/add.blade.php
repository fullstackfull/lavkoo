@extends('layouts.admin.app')

@section('title', translate('Add') .' - '. translate('Page_Setup'))

@push('css_or_js')
    <link href="{{ dynamicAsset(path: 'public/assets/back-end/libs/quill-editor/quill-editor.css') }}" rel="stylesheet">
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="mb-4 pb-2">
            <div class="d-flex gap-10 align-items-center justify-content-between flex-wrap">
                <div>
                    <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                        {{ translate('add_new_business_page') }}
                    </h2>

                    <p>
                        {{ translate('in_tis_page_you_can_create_a_new_business_pages.') }}
                        {{ translate('to_view_the_page_live_click_on_view_url.') }}
                    </p>
                </div>
            </div>
        </div>

        <form action="{{ route('admin.pages-and-media.add') }}" method="post" enctype="multipart/form-data" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
            @csrf

            <div class="d-flex flex-column gap-3">
                <div class="card">
                    <div class="card-body d-flex flex-column gap-3 gap-sm-20">
                        <div class="p-12 p-sm-20 bg-section rounded">
                            <div class="row g-4 align-items-center">
                                <div class="col-lg-8">
                                    <div>
                                        <h2>{{ translate('Page_Availability') }}</h2>
                                        <p class="mb-0">
                                            {{ translate('if_you_turn_of_the_availability_status_this_page_will_not_show_in_the_customer_app_and_website.') }}
                                        </p>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <label
                                        class="d-flex justify-content-between align-items-center gap-3 rounded px-3 py-10 bg-white user-select-none">
                                        <span class="fw-medium text-dark">{{ translate('status') }}</span>
                                        <label class="switcher" for="page-status">
                                            <input type="checkbox" class="switcher_input"
                                                   name="status" value="1" id="page-status" checked>
                                            <span class="switcher_control"></span>
                                        </label>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="p-12 p-sm-20 bg-section rounded">
                            <div class="d-flex flex-column gap-20">
                                <div>
                                    <label for="" class="form-label fw-normal mb-1">
                                        {{ translate('Title_Background_Image') }}
                                    </label>
                                </div>
                                <div class="upload-file">
                                    <input type="file" name="banner" class="upload-file__input single_file_input"
                                           data-max-size="{{ getFileUploadMaxSize() }}"
                                           accept="{{ getFileUploadFormats(skip: '.svg,.gif') }}"  value="">
                                    <button type="button" class="remove_btn btn btn-danger btn-circle w-20 h-20 fs-8">
                                        <i class="fi fi-sr-cross"></i>
                                    </button>
                                    <div
                                        class="upload-file__wrapper ratio-7-1">
                                        <div class="upload-file-textbox text-center">
                                            <img width="34" height="34" class="svg" src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}" alt="{{ translate('upload') }}">
                                            <h6 class="mt-1 fw-medium lh-base text-center">
                                                <span class="text-info">{{ translate('Click_to_upload') }}</span>
                                                <br>
                                                {{ translate('or_drag_and_drop') }}
                                            </h6>
                                        </div>
                                        <img class="upload-file-img" loading="lazy" src="" data-default-src="" alt="">
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
                                <p class="fs-10 mb-0 text-center">
                                    {{ getFileUploadFormats(skip: '.svg,.gif', asBladeMessage: true).' '. translate('Image_size'). ' : '. translate('Max').' '. getFileUploadMaxSize() . 'MB' }}
                                    <span class="fw-medium">{{'(7:1)'}}</span>
                                </p>
                            </div>
                        </div>

                        <div class="p-12 p-sm-20 bg-section rounded">
                            <div class="mb-2" id="page-description-form">
                                <label for="" class="form-label fw-normal mb-2">
                                    {{ translate('Page_Title') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <input class="form-control" name="title" placeholder="{{ translate('type_page_title') }}" data-required-msg="{{ translate('page_title_is_required') }}" required>
                            </div>

                            <div class="" id="page-description-form">
                                <label for="" class="form-label fw-normal mb-2">
                                    {{ translate('Page_Description') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <div id="description-page-editor" class="quill-editor"></div>
                                <textarea name="description" id="description-page" style="display:none;" data-required-msg="{{ translate('page_description_is_required') }}" required></textarea>
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

        </form>
    </div>
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/libs/quill-editor/quill-editor.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/libs/quill-editor/business-pages-quill-editor-init.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/backend/admin/js/pages-and-media/page-setup-add.js') }}"></script>
@endpush
