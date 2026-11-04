@extends('layouts.admin.app')

@section('title', translate('storage_connection_settings'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 mb-sm-20">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                {{ translate('3rd_Party') }} - {{ translate('Other_Configurations') }}
            </h2>
        </div>
        @include('admin-views.third-party._third-party-others-menu')

        <div class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center mb-3">
            <i class="fi fi-sr-lightbulb-on text-info"></i>
            <span>
                {{ translate('you_can_manage_all_your_storage_files_from') }}
                <a href="{{ route('admin.system-setup.file-manager.index') }}" target="_blank"
                   class="text-decoration-underline fw-semibold">{{ translate('Gallery') }}</a>.
            </span>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <div class="row align-items-center g-3">
                    <div class="col-lg-4">
                        <h2>{{ translate('Storage_Connection') }}</h2>
                        <p class="fs-12 mb-0">
                            {{ translate('choose_the_sms_model_you_want_to_use_for_otp_&_other_sms') }}
                        </p>
                        <div
                            class="bg-warning bg-opacity-10 fs-12 px-12 py-20 text-dark rounded d-flex gap-2 align-items-center mt-5">
                            <i class="fi fi-sr-info text-warning"></i>
                            <span>
                                {{ translate('3rd_party_storage_is_not_set_up_yet_please_configure_it_first_to_ensure_it_works_properly') }}.
                            </span>
                        </div>
                    </div>
                    <div class="col-lg-8">
                        <div class="p-12 p-sm-20 bg-section rounded-8">
                            <div class="form-group">
                                <label class="form-label" for="">{{ translate('Select_Storage_Connection_Model') }}
                                    <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
                                          aria-label="{{ translate('Select_Storage_Connection_Model') }}"
                                          data-bs-title="{{ translate('Select_Storage_Connection_Model') }}">
                                        <i class="fi fi-sr-info"></i>
                                    </span>
                                </label>
                                <div class="bg-white p-3 rounded">
                                    <div class="row g-4">
                                        <div class="col-xl-6 col-md-6">
                                            <div class="form-check d-flex gap-3">
                                                <form
                                                    action="{{ route('admin.third-party.storage-connection-settings.update-storage-type') }}"
                                                    method="post" id="storage-connection-local-form"
                                                    data-from="storage-connection-type">
                                                    @csrf
                                                    <input type="hidden" name="type" value="public">
                                                    <input
                                                        class="form-check-input radio--input radio--input_lg custom-modal-plugin"
                                                        type="radio"
                                                        name="status" id="storage-connection-local"
                                                        {{ $storageConnectionType == null || $storageConnectionType == 'public' ? 'checked' : '' }}
                                                        value="1"
                                                        data-modal-id="toggle-status-modal"
                                                        data-toggle-id="storage-connection-local"
                                                        data-modal-type="input-change-form"
                                                        data-modal-form="#storage-connection-local-form"
                                                        data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/local-storage.png') }}"
                                                        data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/local-storage.png') }}"
                                                        data-on-title="{{ translate('do_you_want_to_switch_3rd_party_storage_to_local_storage') }} ?"
                                                        data-off-title="{{ translate('want_To_Turn_OFF_local_Storage') }}"
                                                        data-on-message="<p>{{ translate('if_you_switch_this_newly_uploaded_created_files_&_data_will_store_to_local_storage') }}</p>"
                                                        data-off-message="<p>{{ translate('system_will_store_all_files_and_images_to_3rd_party_storage') }}</p>"
                                                        data-on-button-text="{{ translate('turn_on') }}"
                                                        data-off-button-text="{{ translate('turn_off') }}"
                                                    >
                                                </form>
                                                <div class="flex-grow-1">
                                                    <label for="verification_by_email"
                                                           class="form-label text-dark fw-semibold mb-1">
                                                        {{ translate(' Local_Storage') }}
                                                    </label>
                                                    <p class="fs-12 mb-3">
                                                        {{ translate('if_enable_this_newly_uploaded/created_files_and_data_will_store_to_local_storage.') }}
                                                    </p>

                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-xl-6 col-md-6"
                                             @if(!$storageConnectionS3Credential) data-bs-toggle="tooltip"
                                             data-bs-placement="top" aria-label="Select Business Model"
                                             data-bs-title="{{ translate('3rd_party_storage_is_currently_disabled_please_configure_following_data_first') }}" @endif>
                                            <div
                                                class="form-check d-flex gap-3 {{ !$storageConnectionS3Credential ? 'disabled' : '' }}">
                                                <form
                                                    action="{{ route('admin.third-party.storage-connection-settings.update-storage-type') }}"
                                                    method="post" id="storage-connection-s3-form"
                                                    data-from="storage-connection-type">
                                                    @csrf
                                                    <input type="hidden" name="type" value="s3">
                                                    <input
                                                        class="form-check-input radio--input radio--input_lg custom-modal-plugin"
                                                        type="radio"
                                                        name="status" id="storage-connection-local"
                                                        {{ $storageConnectionType == 's3' ? 'checked' : '' }}
                                                        value="1"
                                                        data-modal-id="toggle-status-modal"
                                                        data-toggle-id="storage-connection-local"
                                                        data-modal-type="input-change-form"
                                                        data-modal-form="#storage-connection-s3-form"
                                                        data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/3rd-party-storage-image.png') }}"
                                                        data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/3rd-party-storage-image.png') }}"
                                                        data-on-title="{{translate('do_you_want_to_switch_local_storage_to_3rd_party_storage').'?'}}"
                                                        data-off-title="{{translate('want_To_Turn_OFF_3rd_Party_Storage').'?'}}"
                                                        data-on-message="<p>{{translate('if_you_switch_this_newly_uploaded_created_files_&_data_will_store_to_3rd_party_storage')}}</p>"
                                                        data-off-message="<p>{{translate('system_will_store_all_files_and_images_to_local_storage')}}</p>"
                                                        data-on-button-text="{{ translate('turn_on') }}"
                                                        data-off-button-text="{{ translate('turn_off') }}"
                                                    >
                                                </form>
                                                <div class="flex-grow-1">
                                                    <label for="verification_by_phone"
                                                           class="form-label text-dark fw-semibold mb-1">
                                                        {{ translate(' 3rd_Party_Storage') }}
                                                    </label>
                                                    <p class="fs-12 mb-3">
                                                        {{ translate('if_enable_this_newly_uploaded') }}
                                                        /{{ translate('created_files_and_data_will_store_to_3rd_party_storage') }}
                                                        .
                                                    </p>

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
        <div class="card">
            <div class="card-body">
                <div class="mb-4">
                    <h2>
                        {{translate('S3_Credential')}}
                    </h2>
                    <p class="fs-12 mb-0">{{ translate('The_Access_Key_ID_is_a_publicly_accessible_identifier_used_to_authenticate_requests_to_S3.') }}
                        <a href="{{ 'https://drive.google.com/file/d/1vlzak2-pBD8zS-tVGRZkAUhlRT_QfRY9/view' }}"
                           target="_blank" class="text-decoration-underline fw-medium">{{ translate('Learn_More') }}</a>
                    </p>
                </div>
                <form action="{{ route('admin.third-party.storage-connection-settings.s3-credential') }}" method="POST" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation"
                      id="get-storage-connection-route" novalidate>
                    @csrf
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="p-12 p-sm-20 bg-section rounded-8">
                                <div class="row align-items-center g-3">
                                    <div class="col-xl-3 col-lg-4 col-sm-6">
                                        <div>
                                            <label class="form-label fw-semibold mb-2 text-capitalize" for="key-cred">
                                                {{ translate('access_key')}}
                                            </label>
                                            <p class="fs-12 mb-0">
                                                {{ translate('your_unique_public_key_used_to_authenticate_s3_requests.') }}
                                            </p>
                                        </div>
                                    </div>
                                    <div class="col-xl-9 col-lg-8 col-sm-6">
                                        <div>
                                            <input type="text" class="form-control"
                                                   placeholder="{{translate('enter_your_access_key')}}" id="key-cred"
                                                   name="s3_key"
                                                   data-required-msg="{{translate('s3_key_field_is_required')}}"
                                                   value="{{ $storageConnectionS3Credential['key'] ?? '' }}" required>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="p-12 p-sm-20 bg-section rounded-8">
                                <div class="row align-items-center g-3">
                                    <div class="col-xl-3 col-lg-4 col-sm-6">
                                        <div>
                                            <label class="form-label fw-semibold mb-2 text-capitalize"
                                                   for="key-secret-cred">
                                                {{ translate('secret_access_key') }}
                                            </label>
                                            <p class="fs-12 mb-0">
                                                {{ translate('the_private_key_paired_with_your_access_key_for_secure_authentication.') }}
                                            </p>
                                        </div>
                                    </div>
                                    <div class="col-xl-9 col-lg-8 col-sm-6">
                                        <div>
                                            <input type="text" class="form-control"
                                                   placeholder="{{translate('enter_your_secret_access_key')}}"
                                                   data-required-msg="{{translate('secret_key_field_is_required')}}"
                                                   id="key-secret-cred" name="s3_secret"
                                                   value="{{ $storageConnectionS3Credential['secret'] ?? '' }}" required>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="p-12 p-sm-20 bg-section rounded-8">
                                <div class="row align-items-center g-3">
                                    <div class="col-xl-3 col-lg-4 col-sm-6">
                                        <div>
                                            <label class="form-label fw-semibold mb-2"
                                                   for="key-region">{{translate('region')}}</label>
                                            <p class="fs-12 mb-0">
                                                {{ translate('the_aws_region_where_your_s3_bucket_is_hosted.') }}
                                            </p>
                                        </div>
                                    </div>
                                    <div class="col-xl-9 col-lg-8 col-sm-6">
                                        <div>
                                            <input type="text" class="form-control"
                                                   placeholder="{{translate('enter_your_region')}}" id="key-region"
                                                   name="s3_region"
                                                   data-required-msg="{{translate('region_key_field_is_required')}}"
                                                   value="{{ $storageConnectionS3Credential['region'] ?? '' }}" required>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="p-12 p-sm-20 bg-section rounded-8">
                                <div class="row align-items-center g-3">
                                    <div class="col-xl-3 col-lg-4 col-sm-6">
                                        <div>
                                            <label class="form-label fw-semibold mb-2" for="key-bucket">
                                                {{ translate('bucket') }}
                                            </label>
                                            <p class="fs-12 mb-0">
                                                {{ translate('the_name_of_the_s3_bucket_where_your_files_will_be_stored.') }}
                                            </p>
                                        </div>
                                    </div>
                                    <div class="col-xl-9 col-lg-8 col-sm-6">
                                        <div>
                                            <input type="text" class="form-control"
                                                   placeholder="{{translate('enter_your_bucket')}}" id="key-bucket"
                                                   name="s3_bucket"
                                                   data-required-msg="{{translate('bucket_key_field_is_required')}}"
                                                   value="{{ $storageConnectionS3Credential['bucket'] ?? '' }}" required>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="p-12 p-sm-20 bg-section rounded-8">
                                <div class="row align-items-center g-3">
                                    <div class="col-xl-3 col-lg-4 col-sm-6">
                                        <div>
                                            <label class="form-label fw-semibold mb-2" for="key-url">
                                                {{ translate('URL') }}
                                            </label>
                                            <p class="fs-12 mb-0">
                                                {{ translate('the_base_url_used_to_access_your_s3_bucket.') }}
                                            </p>
                                        </div>
                                    </div>
                                    <div class="col-xl-9 col-lg-8 col-sm-6">
                                        <div>
                                            <input type="text" class="form-control"
                                                   placeholder="{{translate('enter_your_url')}}" id="key-url"
                                                   name="s3_url"
                                                   data-required-msg="{{translate('url_field_is_required')}}"
                                                   value="{{ $storageConnectionS3Credential['url'] ?? '' }}" required>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="p-12 p-sm-20 bg-section rounded-8">
                                <div class="row align-items-center g-3">
                                    <div class="col-xl-3 col-lg-4 col-sm-6">
                                        <div>
                                            <label class="form-label fw-semibold mb-2" for="key-endpoint">
                                                {{ translate('endpoint') }}
                                            </label>
                                            <p class="fs-12 mb-0">
                                                {{ translate('the_custom_endpoint_for_your_s3-compatible_storage_(optional_for_aws_s3).') }}
                                            </p>
                                        </div>
                                    </div>
                                    <div class="col-xl-9 col-lg-8 col-sm-6">
                                        <div>
                                            <input type="text" class="form-control"
                                                   placeholder="{{translate('enter_your_endpoint')}}"
                                                   id="key-endpoint" name="s3_endpoint"
                                                   value="{{ $storageConnectionS3Credential['endpoint'] ?? '' }}">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="d-flex flex-wrap justify-content-end gap-3">
                                <button type="reset" class="btn btn-secondary px-3 px-sm-4 w-120">
                                    {{ translate('reset') }}
                                </button>
                                <button type="button"
                                        data-message="{{ translate('please_ensure_your_s3_credentials_are_valid.') }}"
                                        class="btn btn-primary px-3 px-sm-4 {{config('app.mode')!= 'demo'? 'form-submit' : 'call-demo-alert'}}"
                                        data-form-id="get-storage-connection-route">
                                    <i class="fi fi-sr-disk"></i>
                                    {{ translate('Save_information') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @include("layouts.admin.partials.offcanvas._3rd-party-storage-connection-setup")
@endsection
