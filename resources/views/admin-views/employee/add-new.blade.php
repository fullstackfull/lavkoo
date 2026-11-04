@extends('layouts.admin.app')

@section('title', translate('employee Add'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img src="{{dynamicAsset(path: 'public/assets/back-end/img/add-new-employee.png') }}" alt="">
                {{ translate('add_new_employee') }}
            </h2>
        </div>

        <form action="{{route('admin.employee.add-new-post') }}" method="post" enctype="multipart/form-data" class=" form-advance-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
            @csrf
            <div class="card text-start">
                <div class="card-body">
                    <h3 class="mb-0 page-header-title text-capitalize d-flex align-items-center gap-2 border-bottom pb-3 mb-3">
                        <i class="fi fi-sr-user"></i>
                        {{ translate('general_information') }}
                    </h3>
                    <div class="row g-4">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="name" class="mb-2">{{ translate('full_name') }} <span class="text-danger">*</span> </label>
                                <input type="text" name="name" class="form-control" id="name" data-required-msg="{{ translate('full_name_field_is_required') }}"
                                       placeholder="{{ translate('ex'). ':'. translate('John_Doe') }}"
                                       value="{{old('name') }}" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="phone_number"
                                       class=" mb-2">{{ translate('phone_number') }} <span class="text-danger">*</span></label>
                                <input class="form-control form-control-user"
                                       type="tel" value="{{ old('phone') }}"
                                       placeholder="{{ translate('ex').': 017xxxxxxxx' }}" name="phone"  data-required-msg="{{ translate('phone_number_field_is_required') }}" >
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="role_id" class="mb-2">{{ translate('role') }} <span class="text-danger">*</span></label>
                                <div class="select-wrapper">
                                    <select class="form-select" name="role_id" id="role_id" required  data-required-msg="{{ translate('role_field_is_required') }}" >
                                        <option selected disabled>{{ translate('select') }}
                                        </option>
                                        @foreach($employee_roles as $role)
                                            <option value="{{$role->id}}" {{old('role_id')==$role->id?'selected':''}}>
                                                {{ ucfirst($role->name) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="identify_type" class="mb-2">{{ translate('identify_Type') }}</label>
                                <div class="select-wrapper">
                                    <select class="form-select" name="identify_type" id="identify_type">
                                        <option value="" selected disabled>{{ translate('Select_Identify_Type') }}</option>
                                        <option value="nid" {{ old('identify_type') == 'nid' ? 'selected' : '' }}>{{ translate('NID') }}</option>
                                        <option value="passport" {{ old('identify_type') == 'passport' ? 'selected' : '' }}>{{ translate('passport') }}</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="form-group">
                                <label for="identify_number" class="mb-2">{{ translate('identify_Number') }}</label>
                                <input type="number" name="identify_number" value="{{ old('identify_number') }}" class="form-control"
                                       placeholder="{{ translate('ex').':'.'9876123123' }}" id="identify_number">
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
                                                            {{ translate('Employee_Image') }} <span class="text-danger">*</span>
                                                        </label>
                                                        <p class="fs-12 mb-0">
                                                            {{ translate('Displayed_as_the_employee_avatar_in_the_system.') }}
                                                        </p>
                                                    </div>
                                                    <div class="upload-file">
                                                        <input type="file" name="image" class="upload-file__input single_file_input" value=""
                                                                data-max-size="{{ getFileUploadMaxSize() }}"
                                                                accept="{{ getFileUploadFormats(skip: '.svg,.gif') }}"
                                                                data-required-msg="{{ translate('employee_image_field_is_required') }}">
                                                        <label
                                                            class="upload-file__wrapper">
                                                            <div class="upload-file-textbox text-center {{ [] ? 'd-none' : '' }}">
                                                                <img width="34" height="34" class="svg img-fluid" src="{{ getStorageImages(path: [] , type: 'backend-placeholder') }}" alt="image upload">
                                                                <h6 class="mt-1 fw-medium lh-base text-center text-capitalize">
                                                                    <span class="text-info">{{ translate('Click_to_upload') }}</span>
                                                                    <br>
                                                                    {{ translate('or_drag_and_drop') }}
                                                                </h6>
                                                            </div>
                                                            <img class="upload-file-img" loading="lazy" src="{{ !empty([]) ? getStorageImages(path:[], type: 'backend-placeholder') ?? '' :  '' }}"
                                                                 data-default-src="{{ !empty([]) ? getStorageImages(path:[],type: 'backend-placeholder') ?? '' :  '' }}" alt="">
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
                                                            {{ translate('Employee_Identity_Image') }}
                                                        </label>
                                                        <p class="fs-12 mb-0">
                                                            {{ translate('Upload the documents that help verify and identify the employee.') }}
                                                        </p>
                                                    </div>
                                                    <div class="d-flex flex-column">
                                                        <div class="position-relative">
                                                            <div class="multi_image_picker d-flex gap-20 pt-20"
                                                                 data-ratio="1/1"
                                                                 data-field-name="identity_image[]"
                                                                 data-max-count="5"
                                                                 data-max-filesize="{{getFileUploadMaxSize()}}"
                                                                 data-required="true"
                                                                 data-required-msg="{{ translate('Employee_Identity_Image_is_required') }}"
                                                                 data-allowed-formats="{{ getFileUploadFormats(skip: '.svg,.gif') }}"
                                                                 data-validation-error-msg="{{ translate('File_size_is_too_large_Maximum_').' '.getFileUploadMaxSize().' '.'MB' }}"
                                                            >
                                                                <div>
                                                                    <div class="imageSlide_prev">
                                                                        <div
                                                                            class="d-flex justify-content-center align-items-center h-100">
                                                                            <button
                                                                                type="button"
                                                                                class="btn btn-circle border-0 bg-primary text-white">
                                                                                <i class="fi fi-sr-angle-left"></i>
                                                                            </button>
                                                                        </div>
                                                                    </div>
                                                                    <div class="imageSlide_next">
                                                                        <div class="d-flex justify-content-center align-items-center h-100">
                                                                            <button
                                                                                type="button"
                                                                                class="btn btn-circle border-0 bg-primary text-white">
                                                                                <i class="fi fi-sr-angle-right"></i>
                                                                            </button>
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
                    </div>
                </div>
            </div>
            <div class="card mt-3">
                <div class="card-body">
                    <h3 class="mb-0 page-header-title d-flex align-items-center gap-2 border-bottom pb-3 mb-3">
                        <i class="fi fi-sr-user"></i>
                        {{ translate('account_Information') }}
                    </h3>
                    <div class="row g-4">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="email" class="mb-2">{{ translate('email') }} <span class="text-danger">*</span></label>
                                <input type="email" name="email" value="{{old('email') }}" class="form-control" id="email" placeholder="{{ translate('ex').':'.'ex@gmail.com'}}" required data-required-msg="{{ translate('email_field_is_required') }}">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="user_password" class="mb-2 d-flex gap-2 align-items-center">
                                    {{ translate('password') }} <span class="text-danger">*</span>
                                    <span class="input-label-secondary cursor-pointer" data-bs-toggle="tooltip" data-bs-title="{{ translate('The_password_must_be_at_least_8_characters_long_and_contain_at_least_one_uppercase_letter').','.translate('_one_lowercase_letter').','.translate('_one_digit_').','.translate('_one_special_character').','.translate('_and_no_spaces').'.'}}">
                                        <i class="fi fi-rr-info"></i>
                                    </span>
                                </label>
                                <div class="input-group">
                                    <input type="password" class="js-toggle-password form-control password-check" name="password"  id="user_password" placeholder="{{ translate('password_minimum_8_characters') }}" data-required-msg="{{ translate('password_field_is_required') }}">
                                    <div id="changePassTarget" class="input-group-append changePassTarget">
                                        <a class="text-body-light" href="javascript:">
                                            <i id="changePassIcon" class="fi fi-sr-eye"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="confirm_password" class="mb-2">
                                    {{ translate('confirm_password') }} <span class="text-danger">*</span>
                                </label>

                                <div class="input-group">
                                    <input type="password" class="js-toggle-password form-control" name="confirm_password"  id="confirm_password" placeholder="{{ translate('confirm_password') }}" data-required-msg="{{ translate('confirm_password_field_is_required') }}">
                                    <div id="changeConfirmPassTarget" class="input-group-append changePassTarget">
                                        <a class="text-body-light" href="javascript:">
                                            <i id="changeConfirmPassIcon" class="fi fi-sr-eye"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-3 mt-4">
                        <button type="reset" id="reset" class="btn btn-secondary">{{ translate('reset') }}</button>
                        <button type="submit" class="btn btn-primary">{{ translate('submit') }}</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
    <span id="coba-image" data-url="{{dynamicAsset(path: "public/assets/back-end/img/400x400/img2.jpg") }}"></span>
    <span id="extension-error" data-text="{{ translate("please_only_input_png_or_jpg_type_file") }}"></span>
    <span id="size-error" data-text="{{ translate("file_size_too_big") }}"></span>
@endsection

@push('script')
    <script src="{{dynamicAsset(path: 'public/assets/backend/admin/js/user-management/employee.js') }}"></script>
@endpush
