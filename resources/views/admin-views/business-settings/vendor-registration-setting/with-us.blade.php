@extends('layouts.admin.app')

@section('title', translate('with_us'))
@section('content')
    <div class="content container-fluid">
        @include('admin-views.business-settings.vendor-registration-setting.partial.inline-menu')

        <form action="{{route('admin.pages-and-media.vendor-registration-settings.with-us')}}" method="post" enctype="multipart/form-data" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
            @csrf

            <div class="card mb-3">
                <div class="card-body">
                    <div class="row align-items-center gy-3 mb-3 mb-sm-20">
                        <div class="col-md-9">
                            <div>
                                <h2>{{translate('Why_Sell_With_Us')}}</h2>
                                <p class="fs-12 mb-0">
                                    {{ translate('this_section_represents_why_vendor_sell_their_product_in_your_business') }}.
                                </p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="d-flex justify-content-between align-items-center gap-3 rounded px-20 py-3 user-select-none bg-section">
                                <span class="fw-semibold text-dark">{{ translate('Status') }}</span>
                                <label class="switcher"
                                       for="with-us-status">
                                    <input
                                        class="switcher_input custom-modal-plugin"
                                        type="checkbox" value="1" name="status"
                                        id="with-us-status"
                                        {{ isset($sellWithUs?->status) && $sellWithUs?->status == 1 ? 'checked' : '' }}
                                        data-modal-type="input-change"
                                        data-on-image="{{ dynamicAsset(path: 'public/assets/backend/media/toggle-modal-icons/turn-on.svg') }}"
                                        data-off-image="{{ dynamicAsset(path: 'public/assets/backend/media/toggle-modal-icons/turn-off.svg') }}"
                                        data-on-title="{{translate('want_to_Turn_ON_this_status').'?'}}"
                                        data-off-title="{{translate('want_to_Turn_OFF_this_status').'?'}}"
                                        data-on-message="<p>{{ translate('once_you_turn_on_the_status_and_complete_the_setup')}}, {{ translate('_this_section_will_be_displayed_on_the_vendor_registration_page') }}</p>"
                                        data-off-message="<p>{{ translate('once_you_turn_off_the_status')}}, {{ translate('_this_section_won’t_be_displayed_on_the_vendor_registration_page') }}</p>"
                                        data-on-button-text="{{ translate('turn_on') }}"
                                        data-off-button-text="{{ translate('turn_off') }}">
                                    <span class="switcher_control"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="row gy-3 mb-4">
                        <div class="col-lg-8">
                            <div class="p-12 p-sm-20 bg-section rounded h-100">
                                <div class="form-group">
                                    <label class="form-label text-capitalize">
                                        {{translate('section_title')}} (EN) <span class="text-danger">*</span>
                                        <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="{{ translate('Write_the_title_for_the_Why_Sell_With_Us_section_that_will_appear_to_potential_vendors._Keep_it_compelling') }}" data-bs-title="{{ translate('Write_the_title_for_the_Why_Sell_With_Us_section_that_will_appear_to_potential_vendors._Keep_it_compelling') }}">
                                            <i class="fi fi-sr-info"></i>
                                        </span>
                                    </label>
                                    <textarea class="form-control" name="title" rows="1" placeholder="{{translate('enter_title')}}" data-maxlength="50" data-required-msg="{{ translate('title_is_required') }}"  required>{{$sellWithUs?->title}}</textarea>
                                    <div class="d-flex justify-content-end">
                                        <span class="text-body-light">0/50</span>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label text-capitalize">
                                        {{translate('sub_title')}} <span class="text-danger">*</span>
                                        <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="{{ translate('A_brief_subtitle_explaining_the_benefits_for_the_vendor,_why_they_should_choose_to_sell_with_you') }}" data-bs-title="{{ translate('A_brief_subtitle_explaining_the_benefits_for_the_vendor,_why_they_should_choose_to_sell_with_you') }}">
                                            <i class="fi fi-sr-info"></i>
                                        </span>
                                    </label>
                                    <textarea class="form-control" name="sub_title" rows="1" placeholder="{{translate('enter_sub_title')}}" data-maxlength="50"  data-required-msg="{{ translate('sub_title_is_required') }}"  required>{{$sellWithUs?->sub_title}}</textarea>
                                    <div class="d-flex justify-content-end">
                                        <span class="text-body-light">0/50</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="p-12 p-sm-20 bg-section rounded h-100">
                                <div class="d-flex flex-column gap-20">
                                    <div>
                                        <label for="" class="form-label fw-semibold mb-1 text-capitalize">
                                            {{ translate('section_Image') }}
                                            <span class="text-danger">*</span>
                                        </label>
                                        <p class="fs-12 mb-0">{{ translate('upload_why_sell_with_us_image') }}</p>
                                    </div>
                                    <div class="upload-file">
                                        @php($imagePath = imagePathProcessing(imageData:$sellWithUs?->image, path: 'vendor-registration-setting'))
                                        <input type="file" name="image" class="upload-file__input single_file_input" accept="{{getFileUploadFormats(skip: '.svg,.gif')}}" data-max-size="{{getFileUploadMaxSize()}}" value="{{ getStorageImages(path:$imagePath,type: 'backend-placeholder') ?? '' }}" {{ !empty($imagePath['path']) ? '' : 'required' }} data-required-msg="{{ translate('section_image_is_required') }}">
                                        <label
                                            class="upload-file__wrapper  w-130">
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
                                            <img class="upload-file-img" loading="lazy" src="{{ getStorageImages(path:imagePathProcessing(imageData: $sellWithUs?->image, path: 'vendor-registration-setting'),type: 'backend-banner') ?? '' }}" data-default-src="{{ getStorageImages(path:imagePathProcessing(imageData: $sellWithUs?->image, path: 'vendor-registration-setting'),type: 'backend-banner') ?? '' }}" alt="">
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
                                        {{ getFileUploadFormats(skip: '.svg,.gif', asBladeMessage: true).' '. translate('Image_size'). ' : '. translate('Max').' '. getFileUploadMaxSize() . 'MB' }}
                                        <span class="fw-medium text-dark">{{ "(310 x 240px)" }}</span>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap justify-content-end gap-3">
                        <button type="reset" class="btn btn-secondary px-4 w-120">{{translate('reset')}}</button>
                        <button type="submit" class="btn btn-primary px-4 w-120">{{translate('save')}}</button>
                    </div>
                </div>
            </div>
        </form>
        @include('admin-views.business-settings.vendor-registration-setting.add-reason')
    </div>
    @include("layouts.admin.partials.offcanvas._vendor-reg-with-us")
@endsection
@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/admin/business-setting/vendor-registration-setting.js')}}"></script>
@endpush
