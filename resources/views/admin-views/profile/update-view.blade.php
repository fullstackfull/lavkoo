@extends('layouts.admin.app')

@section('title', translate('profile_Settings'))

@push('css_or_js')
    <link rel="stylesheet"
          href="{{ dynamicAsset(path: 'public/assets/back-end/plugins/intl-tel-input/css/intlTelInput.css') }}">
@endpush

@section('content')
    <div class="content container-fluid">

        <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3 mb-sm-20">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img width="20" src="{{ dynamicAsset(path: 'public/assets/back-end/img/profile_setting.png') }}" alt="">
                {{ translate('Profile_Information') }}
            </h2>

            <a class="btn btn-primary px-3 rounded" href="{{ route('admin.dashboard.index') }}">
                <i class="fi fi-rr-home"></i>
                <span class="d-none d-md-block">{{ translate('dashboard') }}</span>
            </a>
        </div>

        <div class="position-relative nav--tab-wrapper mb-3">
            <ul class="nav nav-pills nav--tab" id="pills-tab" role="tablist">

                <li class="nav-item">
                    <a class="nav-link {{ empty(request('tab')) || request('tab') != 'password' ?'active':'' }}"
                       href="{{ route('admin.profile.update', ['id' => auth('admin')->user()->id]) }}">
                        <i class="fi fi-sr-user-gear"></i>
                        {{ translate('basic_Information') }}
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link {{ !(empty(request('tab')) || request('tab') != 'password') ? 'active' : '' }}"
                       href="{{ route('admin.profile.update', ['id' => auth('admin')->user()->id, 'tab' => 'password']) }}">
                        <i class="fi fi-rr-lock"></i>
                        {{ translate('password') }}
                    </a>
                </li>
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

    <div class="content container-fluid">

        <div class="row">
            @if(empty(request('tab')) || request('tab') != 'password')
                <div class="col-lg-12">
                    <form action="{{ route('admin.profile.update', ['id' => $admin->id]) }}" method="post"
                          enctype="multipart/form-data" id="admin-profile-form" class="form-advance-validation  form-advance-file-validation" novalidate="novalidate">
                        @csrf
                        <div class="card mb-3 mb-lg-5" id="general-div">
                            <div class="profile-cover">
                                @php($banner = dynamicAsset(path: 'public/assets/back-end/img/media/admin-profile-bg.png'))
                                <div class="profile-cover-img-wrapper profile-bg"
                                     style="background-image: url({{ $banner }})"></div>
                            </div>
                            <div class="profile-cover-avatar d-flex justify-content-center">
                                <div class="upload-file">
                                    <input type="file" name="image" class="upload-file__input single_file_input"
                                           data-required-msg="{{ translate('image_is_required') }}"
                                           data-max-size="{{ getFileUploadMaxSize() }}"
                                           accept="{{ getFileUploadFormats() }}" value="" required="">
                                    <label class="upload-file__wrapper rounded-circle">
                                        <div class="upload-file-textbox text-center">
                                            <img width="34" height="34" class="svg"
                                                 src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}"
                                                 alt="image upload">
                                            <h6 class="mt-1 fw-medium lh-base text-center">
                                                <span class="text-info">{{ translate('Click_to_upload') }}</span>
                                                <br>
                                                {{ translate('Or_drag_and_drop') }}
                                            </h6>
                                        </div>
                                        <img class="upload-file-img" loading="lazy"
                                             src="{{ $admin?->image ? getStorageImages(path:$admin->image_full_url, type:'backend-profile') : '' }}"
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

                            <div class="card-header">
                                <div class="d-flex align-items-center gap-3">
                                    <div>
                                        <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/user-1.svg') }}"
                                            alt="">
                                    </div>
                                    <h4 class="card-title m-0 fs-16">{{ translate('basic_Information') }}</h4>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row gy-3">
                                    <div class="col-md-4">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <label for="firstNameLabel" class="input-label mb-0">
                                                {{ translate('full_name') }}
                                                <span class="text-danger">*</span>
                                            </label>
                                            <span class="input-label-secondary cursor-pointer" data-bs-toggle="tooltip"
                                                  data-bs-title="{{ translate('this_will_be_displayed_as_your_profile_name') }}">
                                            <i class="fi fi-rr-info"></i>
                                        </span>
                                        </div>

                                        <div class="mb-3">
                                            <div class="input-group input-group-sm-down-break">
                                                <input type="text" class="form-control" name="name" id="firstNameLabel"
                                                       placeholder="{{ translate('ex') }}: {{ translate('ABC') }}"
                                                       aria-label=" {{ translate('ABC') }}"
                                                       value="{{ $admin->name }}">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-md-4">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <label for="phoneLabel" class="input-label mb-0">
                                                {{ translate('phone_Number') }}
                                                <span class="text-danger">*</span>
                                            </label>
                                        </div>

                                        <div class="mb-3">
                                            <input class="form-control" type="tel" name="phone"
                                                   value="{{ $admin->phone }}"
                                                   placeholder="{{ translate('ex') }}: {{ translate('123456789') }}">
                                        </div>
                                    </div>

                                    <div class="col-md-4">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <label for="newEmailLabel" class="input-label mb-0">
                                                {{ translate('email') }}
                                                <span class="text-danger">*</span>
                                            </label>

                                            <span class="input-label-secondary cursor-pointer" data-bs-toggle="tooltip"
                                                  data-bs-title="{{ translate('you_can_login_to_your_panel_by_using_this_email') }}">
                                            <i class="fi fi-rr-info"></i>
                                        </span>
                                        </div>
                                        <div class="mb-3">
                                            <input type="email" class="form-control" name="email" id="newEmailLabel"
                                                   value="{{$admin->email}}"
                                                   placeholder="{{ translate('ex') }}: {{ 'admin@admin.com' }}">
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-end">
                                    <button type="button" data-id="admin-profile-form"
                                            data-message="{{ translate('want_to_update_admin_info').'?'}}"
                                            class="btn btn-primary {{config('app.mode')!='demo'?'form-alert':'call-demo-alert'}}">{{ translate('save_Changes') }}</button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            @else
                <div class="col-lg-12">
                    <div id="password-div" class="card mb-3 mb-lg-5">
                        <div class="card-header">
                            <div class="d-flex align-items-center gap-3">
                                <div><img
                                        src="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/password-lock.svg') }}"
                                        alt=""></div>
                                <h4 class="card-title m-0 fs-16">{{ translate('change_Password') }}</h4>
                            </div>
                        </div>
                        <div class="card-body">
                            <form class="change-admin-password-form"
                                  action="{{ route('admin.profile.update', ['id' => $admin->id]) }}"
                                  method="post" enctype="multipart/form-data">
                                @csrf @method('patch')
                                <div class="row gy-3">
                                    <div class="col-md-4">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <label for="newPassword" class="input-label mb-0">
                                                {{ translate('new_password') }}
                                                <span class="text-danger">*</span>
                                            </label>

                                            <span class="input-label-secondary cursor-pointer" data-bs-toggle="tooltip"
                                                  data-bs-title="{{ translate('The_password_must_be_at_least_8_characters_long_and_contain_at_least_one_uppercase_letter').','.translate('_one_lowercase_letter').','.translate('_one_digit_').','.translate('_one_special_character').','.translate('_and_no_spaces').'.'}}">
                                            <i class="fi fi-rr-info"></i>
                                        </span>
                                        </div>
                                        <div class="">
                                            <div class="input-group">
                                                <input type="password"
                                                       class="js-toggle-password form-control password-check password-input-element"
                                                       id="newPassword"
                                                       autocomplete="off" name="password" required minlength="8"
                                                       placeholder="{{ translate('enter_new_password') }}"
                                                >
                                                <div id="changePassTarget" class="input-group-append changePassTarget">
                                                    <a class="text-body-light" href="javascript:">
                                                        <i id="changePassIcon" class="fi fi-sr-eye"></i>
                                                    </a>
                                                </div>

                                            </div>
                                            <span class="text-danger pt-1 d-block password-error"></span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="d-flex align-items-center mb-2">
                                            <label for="confirmNewPasswordLabel" class="input-label mb-1">
                                                {{ translate('confirm_password') }}
                                                <span class="text-danger px-1">*</span>
                                            </label>
                                        </div>

                                        <div class="">
                                            <div class="mb-3">
                                                <div class="input-group">
                                                    <input type="password" class="js-toggle-password form-control confirm-password-check confirm-password-input-element"
                                                           name="confirm_password" required id="confirmNewPasswordLabel"
                                                           placeholder="{{ translate('enter_confirm_password') }}"
                                                           autocomplete="off">
                                                    <div id="changeConfirmPassTarget"
                                                         class="input-group-append changePassTarget">
                                                        <a class="text-body-light" href="javascript:">
                                                            <i id="changeConfirmPassIcon" class="fi fi-sr-eye"></i>
                                                        </a>
                                                    </div>
                                                </div>
                                                <span class="text-danger pt-1 d-block confirm-password-error"></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-end">
                                    <button type="{{ getDemoModeFormButton(type: 'button') }}"
                                            class="btn btn-primary {{ getDemoModeFormButton(type: 'class') }}"
                                    >
                                        {{ translate('save_Changes') }}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            @endif

        </div>
    </div>
@endsection
