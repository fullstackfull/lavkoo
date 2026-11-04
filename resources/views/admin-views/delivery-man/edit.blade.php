@extends('layouts.admin.app')

@section('title', translate('Update_Delivery_Man'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/deliveryman.png') }}" width="20"
                    alt="">
                {{ translate('update_Deliveryman') }}
            </h2>
        </div>

        <div class="row">
            <div class="col-12 mb-3">
                <form action="{{ route('admin.delivery-man.update', [$deliveryMan['id']]) }}" method="post"
                    id="update-delivery-man-form" enctype="multipart/form-data" class="form-advance-validation form-advance-file-validation" novalidate>
                    @csrf
                    <div class="card">
                        <div class="card-body">
                            <h3 class="mb-0 page-header-title d-flex align-items-center gap-2 border-bottom pb-3 mb-3">
                                <i class="fi fi-sr-user"></i>
                                {{ translate('general_Information') }}
                            </h3>
                            <div class="row g-3">

                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="mb-2">{{ translate('first_Name') }}<span class="text-danger">*</span> </label>
                                        <input type="text" value="{{ $deliveryMan['f_name'] }}" name="f_name"  data-required-msg="{{translate('first_name_field_is_required')}}"
                                               class="form-control" placeholder="{{ translate('new_delivery_man') }}" required data-maxlength="50">
                                        <div class="d-flex justify-content-end">
                                            <span class="text-body-light">0/50</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="mb-2">{{ translate('last_Name') }}<span class="text-danger">*</span> </label>
                                        <input type="text" value="{{ $deliveryMan['l_name'] }}" name="l_name"  data-required-msg="{{translate('last_name_field_is_required')}}"
                                               class="form-control" placeholder="{{ translate('last_Name') }}" required data-maxlength="50">
                                        <div class="d-flex justify-content-end">
                                            <span class="text-body-light">0/50</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="mb-2"
                                               for="exampleFormControlInput1">{{ translate('phone') }} <span class="text-danger">*</span></label>
                                        <div class="input-group mb-3">
                                            <input type="tel" value="{{ '+'.$deliveryMan['country_code']. $deliveryMan['phone'] }}" name="phone" class="form-control"
                                                   placeholder="{{ translate('ex') }} : 017********"
                                                   data-required-msg="{{translate('phone_field_is_required')}}"
                                                   required>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="mb-2">{{ translate('identity_Type') }}</label>
                                        <div class="select-wrapper">
                                            <select name="identity_type" class="form-select">
                                                <option value="passport"
                                                    {{ $deliveryMan['identity_type'] == 'passport' ? 'selected' : '' }}>
                                                    {{ translate('passport') }}
                                                </option>
                                                <option value="driving_license"
                                                    {{ $deliveryMan['identity_type'] == 'driving_license' ? 'selected' : '' }}>
                                                    {{ translate('driving_License') }}
                                                </option>
                                                <option value="nid"
                                                    {{ $deliveryMan['identity_type'] == 'nid' ? 'selected' : '' }}>
                                                    {{ translate('nid') }}
                                                </option>
                                                <option value="company_id"
                                                    {{ $deliveryMan['identity_type'] == 'company_id' ? 'selected' : '' }}>
                                                    {{ translate('company_ID') }}
                                                </option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="mb-2">{{ translate('identity_Number') }}</label>
                                        <input type="text" name="identity_number"
                                               value="{{ $deliveryMan['identity_number'] }}" class="form-control"
                                               placeholder="{{ translate('ex') }} : DH-23434-LS" >
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="mb-2">{{ translate('address') }}</label>
                                        <textarea name="address" class="form-control" id="address" rows="1" placeholder="Address">{{ $deliveryMan['address'] }}</textarea>
                                    </div>
                                </div>
                                <div class="col-md-12">
                                    <div class="p-12 p-sm-20 bg-section rounded">
                                        <div class="row g-4">
                                            <div class="col-md-4">
                                                <div class="card shadow-none">
                                                    <div class="card-body">
                                                        <div class="d-flex flex-column gap-20">
                                                            <div>
                                                                <label for="" class="form-label fw-semibold mb-1 text-capitalize">
                                                                    {{ translate('Deliveryman_Image') }} <span class="text-danger">*</span>
                                                                </label>
                                                                <p class="fs-12 mb-0">
                                                                    {{ translate('Displayed_as_the_deliveryman_avatar_in_the_system.') }}
                                                                </p>
                                                            </div>
                                                            <div class="upload-file">
                                                                <input type="file" name="image" class="upload-file__input single_file_input" value="{{ getStorageImages(path: $deliveryMan->image_full_url, type: 'backend-placeholder') ?? '' }}" {{ $deliveryMan?->image_full_url ? '' : 'required' }}
                                                                data-max-size="{{ getFileUploadMaxSize() }}"
                                                                       accept="{{ getFileUploadFormats(skip: '.svg,.gif') }}"
                                                                       data-required-msg="{{ translate('Deliveryman_image_field_is_required') }}">
                                                                <label
                                                                    class="upload-file__wrapper">
                                                                    <div class="upload-file-textbox text-center {{ $deliveryMan?->image_full_url ? 'd-none' : '' }}">
                                                                        <img width="34" height="34" class="svg img-fluid" src="{{ getStorageImages(path: $deliveryMan?->image_full_url, type: 'backend-placeholder') }}" alt="image upload">
                                                                        <h6 class="mt-1 fw-medium lh-base text-center text-capitalize">
                                                                            <span class="text-info">{{ translate('Click_to_upload') }}</span>
                                                                            <br>
                                                                            {{ translate('or_drag_and_drop') }}
                                                                        </h6>
                                                                    </div>
                                                                    <img class="upload-file-img" loading="lazy" src="{{ !empty($deliveryMan?->image_full_url) ? getStorageImages(path: $deliveryMan?->image_full_url, type: 'backend-placeholder') ?? '' :  '' }}"
                                                                         data-default-src="{{ !empty($deliveryMan?->image_full_url) ? getStorageImages(path: $deliveryMan?->image_full_url,type: 'backend-placeholder') ?? '' :  '' }}" alt="">
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
                                                                <span class="text-dark fw-medium">
                                                                    ({{ translate('Ratio') }} {{ "1:1" }})
                                                                </span>
                                                            </p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="col-md-8">
                                                <div class="card shadow-none h-100">
                                                    <div class="card-body">
                                                        <div class="d-flex flex-column gap-20">
                                                            <div>
                                                                <label for="" class="form-label fw-semibold mb-1 text-capitalize">
                                                                    {{ translate('Deliveryman_Identity_Image') }}
                                                                </label>
                                                                <p class="fs-12 mb-0">
                                                                    {{ translate('Upload the documents that help verify and identify the deliveryman.') }}
                                                                </p>
                                                            </div>
                                                            <div class="d-flex flex-column">
                                                                <div class="position-relative">
                                                                    <div class="multi_image_picker d-flex gap-20 pt-20"
                                                                         data-ratio="1/1"
                                                                         data-max-count="5"
                                                                         data-max-filesize="{{getFileUploadMaxSize()}}"
                                                                         data-allowed-formats="{{ getFileUploadFormats(skip: '.svg,.gif') }}"
                                                                         data-validation-error-msg="{{ translate('File_size_is_too_large_Maximum_').' '.getFileUploadMaxSize().' '.'MB' }}"
                                                                         data-field-name="identity_image[]"
                                                                    >
                                                                        <div>
                                                                            <div class="imageSlide_prev">
                                                                                <div class="d-flex justify-content-center align-items-center h-100">
                                                                                    <button type="button"
                                                                                            class="btn btn-circle border-0 bg-primary text-white">
                                                                                        <i class="fi fi-sr-angle-left"></i>
                                                                                    </button>
                                                                                </div>
                                                                            </div>
                                                                            <div class="imageSlide_next">
                                                                                <div class="d-flex justify-content-center align-items-center h-100">
                                                                                    <button type="button"
                                                                                            class="btn btn-circle border-0 bg-primary text-white">
                                                                                        <i class="fi fi-sr-angle-right"></i>
                                                                                    </button>
                                                                                </div>
                                                                            </div>
                                                                        </div>

                                                                        @if ($deliveryMan['identity_image'])
                                                                            @foreach ($deliveryMan->identity_images_full_url as $img)
                                                                                @php($unique_id = rand(1111, 9999))
                                                                                <div class="upload-file m-0 position-relative">
                                                                                    <label class="upload-file__wrapper">
                                                                                        <img class="upload-file-img" loading="lazy"
                                                                                             id="additional_Image_{{ $unique_id }}"
                                                                                             src="{{ getStorageImages(path: $img, type:'backend-basic') }}"
                                                                                             data-default-src="{{ getStorageImages(path: $img, type:'backend-basic') }}"
                                                                                             alt="">
                                                                                    </label>
                                                                                    <div class="overlay">
                                                                                        <div
                                                                                            class="d-flex gap-10 justify-content-center align-items-center h-100">
                                                                                            <button type="button"
                                                                                                    class="btn btn-outline-info icon-btn view_btn"
                                                                                                    data-img="#additional_Image_{{ $unique_id }}">
                                                                                                <i class="fi fi-sr-eye"></i>
                                                                                            </button>
                                                                                        </div>
                                                                                    </div>
                                                                                </div>
                                                                            @endforeach
                                                                        @endif
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
                            </div>
                        </div>
                    </div>
                    <div class="card mt-3">
                        <div class="card-body">
                            <h3 class="mb-0 page-header-title d-flex align-items-center gap-2 border-bottom pb-3 mb-3">
                                <i class="tio-user"></i>
                                {{ translate('account_Information') }}
                            </h3>
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-4">
                                        <label class="mb-2 d-flex">{{ translate('email') }} <span class="text-danger">*</span></label>
                                        <input type="email" value="{{ $deliveryMan['email'] }}" name="email"
                                            class="form-control"
                                            placeholder="{{ translate('ex') . ':' . 'email@example.com' }}" required>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-4">
                                    <label for="user_password" class="mb-2 d-flex gap-1 align-items-center">
                                        {{ translate('password') }}
                                        <span class="input-label-secondary cursor-pointer d-flex" data-bs-toggle="tooltip"
                                            data-bs-title="{{ translate('The_password_must_be_at_least_8_characters_long_and_contain_at_least_one_uppercase_letter') . ',' . translate('_one_lowercase_letter') . ',' . translate('_one_digit_') . ',' . translate('_one_special_character') . ',' . translate('_and_no_spaces') . '.' }}">
                                            <i class="fi fi-rr-info"></i>
                                        </span>
                                    </label>
                                    <div class="input-group">
                                        <input type="password" class="js-toggle-password form-control password-check"
                                            name="password" required id="user_password" minlength="8"
                                               data-required-msg="{{translate('password_field_is_required')}}"
                                            placeholder="{{ translate('password_minimum_8_characters') }}">
                                        <div id="changePassTarget" class="input-group-append changePassTarget">
                                            <a class="text-body-light" href="javascript:">
                                                <i id="changePassIcon" class="fi fi-sr-eye"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-4">
                                    <label for="confirm_password"
                                        class="mb-2 d-flex gap-1 align-items-center">{{ translate('confirm_password') }}</label>
                                    <div class="input-group">
                                        <input type="password" class="js-toggle-password form-control"
                                            name="confirm_password" required id="confirm_password"
                                            placeholder="{{ translate('confirm_password') }}">
                                        <div id="changeConfirmPassTarget" class="input-group-append changePassTarget">
                                            <a class="text-body-light" href="javascript:">
                                                <i id="changeConfirmPassIcon" class="fi fi-sr-eye"></i>
                                            </a>
                                        </div>
                                    </div>
                                    <div class="pass invalid-feedback">{{ translate('repeat_password_not_match') . '.' }}
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex gap-3 justify-content-end">
                                <button type="reset" id="reset"
                                    class="btn btn-secondary">{{ translate('reset') }}</button>
                                <button type="button" class="btn btn-primary form-submit"
                                    data-form-id="update-delivery-man-form"
                                    data-redirect-route="{{ route('admin.delivery-man.list') }}"
                                    data-message="{{ translate('want_to_update_this_delivery_man') . '?' }}">
                                    {{ translate('Update') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <span id="coba-image" data-url="{{ dynamicAsset(path: 'public/assets/back-end/img/400x400/img2.jpg') }}"></span>
    <span id="extension-error" data-text="{{ translate('please_only_input_png_or_jpg_type_file') }}"></span>
    <span id="size-error" data-text="{{ translate('file_size_too_big') }}"></span>
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/spartan-multi-image-picker.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/admin/deliveryman.js') }}"></script>
@endpush
