@extends('layouts.admin.app')

@section('title', translate('business_Process'))

@section('content')
    <div class="content container-fluid">
        @include('admin-views.business-settings.vendor-registration-setting.partial.inline-menu')

        <form action="{{route('admin.pages-and-media.vendor-registration-settings.business-process')}}" method="post" enctype="multipart/form-data" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
            @csrf

            <div class="card mb-3 mb-sm-20">
                <div class="card-body">
                    <div class="row align-items-center gy-3 mb-3 mb-sm-20">
                        <div class="col-md-9">
                            <div>
                                <h2 class="text-capitalize">{{ translate('business_process') }}</h2>
                                <p class="fs-12 mb-0">
                                    {{ translate('here_you_can_show_vendors_how_your_business_are_working_to_attract_them_sell_in_your_system') }}.
                                </p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="d-flex justify-content-between align-items-center gap-3 rounded px-20 py-3 user-select-none bg-section">
                                <span class="fw-semibold text-dark">{{ translate('Status') }}</span>
                                <label class="switcher"
                                       for="business-process-status">
                                    <input
                                        class="switcher_input custom-modal-plugin"
                                        type="checkbox" value="1" name="status"
                                        id="business-process-status"
                                        {{ isset($businessProcess->status) && $businessProcess?->status == 1 ? 'checked' : '' }}
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
                    <div class="p-12 p-sm-20 bg-section rounded">
                        <div class="row gy-3">
                            <div class="col-lg-6">
                                <div class="form-group">
                                    <label class="form-label">
                                        {{ translate('Title') }} (EN)
                                        <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="{{ translate('Enter_the_main_heading_for_the_business_process_section._This_should_briefly_summarize_the_steps_vendors_need_to_follow_to_start_selling.') }}" data-bs-title="{{ translate('Enter_the_main_heading_for_the_business_process_section._This_should_briefly_summarize_the_steps_vendors_need_to_follow_to_start_selling.') }}">
                                            <i class="fi fi-sr-info"></i>
                                        </span>
                                    </label>
                                    <textarea class="form-control" name="title" rows="1" placeholder="{{translate('enter_title')}}" data-maxlength="50"  data-required-msg="{{ translate('title_is_required') }}"  required>{{$businessProcess?->title}}</textarea>
                                    <div class="d-flex justify-content-end">
                                        <span class="text-body-light">0/50</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="form-group">
                                    <label class="form-label text-capitalize">
                                        {{translate('sub_title')}}
                                        <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="{{ translate('Provide_a_short_description_explaining_how_vendors_can_begin_selling_on_your_platform._Use_this_to_highlight_simplicity_and_benefits.') }}" data-bs-title="{{ translate('Provide_a_short_description_explaining_how_vendors_can_begin_selling_on_your_platform._Use_this_to_highlight_simplicity_and_benefits.') }}">
                                            <i class="fi fi-sr-info"></i>
                                        </span>
                                    </label>
                                    <textarea class="form-control" name="sub_title" rows="1" placeholder="{{translate('enter_sub_title')}}" data-maxlength="160"  data-required-msg="{{ translate('description_is_required') }}" required>{{$businessProcess?->sub_title}}</textarea>
                                    <div class="d-flex justify-content-end">
                                        <span class="text-body-light">0/160</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            @for($index = 1 ;$index <=3 ;$index++)
            <div class="card mb-3 mb-sm-20">
                <div class="card-body">
                    <h2 class="mb-3 mb-sm-20">{{translate('section').' '.$index}}</h2>
                    <div class="row gy-3">
                        <div class="col-lg-8">
                            <div class="p-12 p-sm-20 bg-section rounded h-100">
                                <div>
                                    <label class="form-label">
                                        {{translate('section_title')}}
                                        <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="{{ translate('Write_a_clear_and_engaging_title_for_this_step_of_the_process,such_as_Get_Registered_or_Upload_Products_Keep_it_short_and_action_oriented.') }}" data-bs-title="{{ translate('Write_a_clear_and_engaging_title_for_this_step_of_the_process,such_as_Get_Registered_or_Upload_Products_Keep_it_short_and_action_oriented.') }}">
                                            <i class="fi fi-sr-info"></i>
                                        </span>
                                    </label>
                                    <textarea class="form-control" name="section_{{$index}}_title" rows="1" placeholder="{{translate('enter_title')}}" data-maxlength="50"  data-required-msg="{{ translate('section_'). $index. translate('_title_is_required') }}" required>{{isset($businessProcessStep[$index-1]) ? $businessProcessStep[$index-1]->title : null}}</textarea>
                                    <div class="d-flex justify-content-end">
                                        <span class="text-body-light">0/50</span>
                                    </div>
                                </div>
                                <div>
                                    <label class="form-label">
                                        {{translate('sub_title')}}
                                        <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="{{ translate('Add_a_short_description_explaining_what_happens_in_this_step._Describe_what_the_vendor_should_do_or_expect._Limit_to_2–3_sentences.') }}" data-bs-title="{{ translate('Add_a_short_description_explaining_what_happens_in_this_step._Describe_what_the_vendor_should_do_or_expect._Limit_to_2–3_sentences.') }}">
                                            <i class="fi fi-sr-info"></i>
                                        </span>
                                    </label>
                                    <textarea name="section_{{$index}}_description" class="form-control" rows="2" placeholder="{{translate('write_description').'...'}}" data-maxlength="160" data-required-msg="{{ translate('section_'). $index. translate('_description_is_required') }}" required>{{isset($businessProcessStep[$index-1]) ? $businessProcessStep[$index-1]->description : null}}</textarea>
                                    <div class="d-flex justify-content-end">
                                        <span class="text-body-light">0/160</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="p-12 p-sm-20 bg-section rounded h-100">
                                <div class="d-flex flex-column gap-20">
                                    <div>
                                        <label for="" class="form-label fw-semibold mb-1">
                                            {{translate('section').' '.$index. ' '.translate('image')}}
                                        </label>
                                        <p class="fs-12 mb-0">{{translate('upload_section').' '.$index. ' '.translate('image')}}</p>
                                    </div>
                                    <div class="upload-file">
                                        @php($imagePath = imagePathProcessing(imageData:isset($businessProcessStep[$index-1]) ? $businessProcessStep[$index-1]?->image : null, path: 'vendor-registration-setting'))
                                        <input type="file" name="section_{{$index}}_image" class="upload-file__input single_file_input" accept="{{ getFileUploadFormats(skip: '.svg,.gif') }}" data-max-size="{{ getFileUploadMaxSize() }}" value="{{ getStorageImages(path:$imagePath,type: 'backend-banner') ?? '' }}">
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
                                            <img class="upload-file-img" loading="lazy" src="{{ getStorageImages(path:imagePathProcessing(imageData: $businessProcessStep[$index-1]->image, path: 'vendor-registration-setting'),type: 'backend-banner') ?? '' }}" data-default-src="{{ getStorageImages(path:imagePathProcessing(imageData: $businessProcessStep[$index-1]->image, path: 'vendor-registration-setting'),type: 'backend-banner') ?? '' }}" alt="">
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
                                        <span class="fw-medium text-dark">{{ "(1:1)" }}</span>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            @endfor

            <div class="d-flex justify-content-end trans3 action-btn-wrapper-container">
                <div class="d-flex justify-content-sm-end justify-content-center gap-3 flex-grow-1 flex-grow-sm-0 bg-white action-btn-wrapper trans3">
                    <button type="reset" class="btn btn-secondary px-3 px-sm-4 w-120">{{ translate('reset') }}</button>
                    <button type="submit" class="btn btn-primary px-3 px-sm-4 w-120">
                        {{ translate('submit') }}
                    </button>
                </div>
            </div>
        </form>
    </div>
    @include("layouts.admin.partials.offcanvas._vendor-reg-business-process")
@endsection
