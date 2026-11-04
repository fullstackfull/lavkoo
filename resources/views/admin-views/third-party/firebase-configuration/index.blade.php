@extends('layouts.admin.app')

@section('title', translate('Firebase_Auth'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 mb-sm-20">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                {{ translate('firebase') }}
            </h2>
        </div>
        @include('admin-views.third-party._third-party-firebase-menu')

        <div class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded mb-4">
            <div class="d-flex gap-2 align-items-center mb-2">
                <i class="fi fi-sr-lightbulb-on text-info"></i>
                <span>
                {{ translate('you_need_to_configuration_firebase') }}.
            </span>
            </div>
            <ul class="m-0 ps-20 d-flex flex-column gap-1 text-body">
                <li>{{ translate('to_send_notifications_properly_you_can_setup_notification_text_from') }}
                    <a href="{{route('admin.push-notification.index')}}" target="_blank" class="fw-semibold text-decoration-underline">
                        {{ translate('notification') }}
                    </a>{{ translate('page') }}.
                </li>
            </ul>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <div class="d-flex gap-3 justify-content-between align-items-center flex-wrap mb-3">
                    <div>
                        <h2>{{ translate('Firebase_Configuration') }}</h2>
                        <p class="mb-0 fs-12">
                            {{ translate('here_fill_up_the_following_data_&_setup_the_firebase_to_work_properly_the_notifications_of_your_system') }}.
                        </p>
                    </div>
                    <div>
                        <a data-bs-toggle="modal" href="#firebase-auth-modal" class="fs-12 text-decoration-underline">{{ translate('where_to_get_this_information') }}</a>
                    </div>
                </div>
                <form action="{{ route('admin.third-party.firebase-configuration.setup') }}" method="post"
                      style="text-align: {{Session::get('direction') === "rtl" ? 'right' : 'left'}};"
                      enctype="multipart/form-data">
                    @csrf
                    <div class="p-12 p-sm-20 bg-section rounded">
                        <div class="row g-4">
                            <div class="col-12">
                                <div class="form-group">
                                    <label class="form-label" for="">{{ translate('service_account_content') }}
                                        <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="Enter your site key" data-bs-title="Enter your site key">
                                            <i class="fi fi-sr-info"></i>
                                        </span>
                                    </label>
                                    <textarea name="push_notification_key" id="" class="form-control" placeholder="{{ translate('Type_about_the_description') }}"
                                              rows="10" required>{{ showDemoModeInputValue(value: $pushNotificationKey) }}</textarea>
                                </div>
                            </div>
                            <div class="col-lg-8 col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="">{{ translate('Api_Key') }}
                                        <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="Enter your Api Key" data-bs-title="Enter your Api Key">
                                            <i class="fi fi-sr-info"></i>
                                        </span>
                                    </label>
                                    <input type="text" class="form-control" name="apiKey"
                                           placeholder="{{ translate('Ex') }}: {{ "Smtp.mailtrap.io" }}" value="{{ showDemoModeInputValue(value: $configData?->apiKey) }}">
                                </div>
                            </div>
                            <div class="col-lg-4 col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="">{{ translate('Auth_Domain') }}
                                        <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="Enter your Auth Domain" data-bs-title="Enter your Auth Domain">
                                            <i class="fi fi-sr-info"></i>
                                        </span>
                                    </label>
                                    <input type="text" class="form-control" name="authDomain"
                                           placeholder="{{ translate('Ex') }}: {{ "Smtp" }}"
                                           value="{{ showDemoModeInputValue(value: $configData?->authDomain) }}">
                                </div>
                            </div>
                            <div class="col-lg-4 col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="">{{ translate('Project_ID') }}
                                        <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="Enter your Project ID" data-bs-title="Enter your Project ID">
                                            <i class="fi fi-sr-info"></i>
                                        </span>
                                    </label>
                                    <input type="text" class="form-control" name="projectId"
                                           placeholder="{{ translate('Ex') }}: 587"
                                           value="{{ showDemoModeInputValue(value: $configData?->projectId ?? '') }}">
                                </div>
                            </div>
                            <div class="col-lg-4 col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="">{{ translate('Storage_Bucket') }}
                                        <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="Enter your Storage Bucket" data-bs-title="Enter your Storage Bucket">
                                            <i class="fi fi-sr-info"></i>
                                        </span>
                                    </label>
                                    <input type="text" class="form-control" name="storageBucket"
                                           placeholder="{{ translate('Ex') }}: {{ "yahoo" }}"
                                           value="{{ showDemoModeInputValue(value: $configData?->storageBucket) }}">
                                </div>
                            </div>
                            <div class="col-lg-4 col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="">{{ translate('Messaging_Sender_ID') }}
                                        <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="Enter your Messaging Sender ID" data-bs-title="Enter your Messaging Sender ID">
                                            <i class="fi fi-sr-info"></i>
                                        </span>
                                    </label>
                                    <input type="text" class="form-control" name="messagingSenderId"
                                           placeholder="{{ translate('Ex') }}: {{ "example@demo.com" }}"
                                           value="{{ showDemoModeInputValue(value: $configData?->messagingSenderId) }}">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="">{{ translate('App_ID') }}
                                        <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="Enter your App ID" data-bs-title="Enter your App ID">
                                            <i class="fi fi-sr-info"></i>
                                        </span>
                                    </label>
                                    <input type="text" class="form-control" name="appId"
                                           placeholder="{{ translate('Ex') }}: {{ "Tis" }}"
                                           value="{{ showDemoModeInputValue(value: $configData?->appId) }}">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="">{{ translate('Measurement_ID') }}
                                        <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="Enter your Measurement ID" data-bs-title="Enter your Measurement ID">
                                            <i class="fi fi-sr-info"></i>
                                        </span>
                                    </label>
                                    <input type="text" class="form-control" name="measurementId"
                                           placeholder="{{ translate('Ex') }}: 123456789"
                                           value="{{ showDemoModeInputValue(value: $configData?->measurementId) }}">
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end trans3 mt-3 action-btn-wrapper-container">
                            <div class="d-flex justify-content-sm-end justify-content-center gap-3 flex-grow-1 flex-grow-sm-0 bg-white action-btn-wrapper trans3">
                                <button type="reset" class="btn btn-secondary px-3 px-sm-4 w-120">
                                    {{ translate('Reset') }}
                                </button>
                                <button type="{{ getDemoModeFormButton(type: 'button') }}"
                                        class="btn btn-primary px-3 px-sm-4 {{ getDemoModeFormButton(type: 'class') }}">
                                    <i class="fi fi-sr-disk"></i>
                                    {{ translate('Save_information') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <div class="modal fade" id="firebase-auth-modal" tabindex="-1" aria-labelledby="getInformationModal" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                        <button type="button" class="btn-close border-0 shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body px-4 px-sm-5 pt-0">
                        <div class="swiper instruction-carousel pb-3">
                            <div class="swiper-wrapper">
                                <div class="swiper-slide">
                                    <div class="d-flex flex-column align-items-center gap-2">
                                        <h3 class="modal-title my-3 text-center" id="instructionsModalLabel">{{ translate('Instructions') }}</h3>
                                        <p>
                                            {{ translate('for_configuring_otp_in_the_firebase,_you_must_create_a_firebase_project_first.') }}
                                            {{ translate('if_you_have_not_created_any_project_for_your_application_yet,_please_create_a_project_first.') }}
                                        </p>
                                        <p>{{ translate('Now_go_the') }} <a href="https://console.firebase.google.com/" target="_blank">{{ translate('Firebase_console') }}</a> {{ translate('and_follow_the_instructions_below') }} -</p>
                                        <ol class="d-flex flex-column __gap-1 __instructions">
                                            <li>{{ translate('go_to_your_firebase_project.') }}</li>
                                            <li>{{ translate('navigate_to_the_build_menu_from_the_left_sidebar_and_select_authentication.') }}</li>
                                            <li>{{ translate('get_started_the_project_and_go_to_the_sign-in_method_tab.') }}</li>
                                            <li>{{ translate('from_the_sign_in_providers_section,_select_the_phone_option.') }}</li>
                                            <li>{{ translate('ensure_to_enable_the_method_phone_and_press_save.') }}</li>
                                        </ol>
                                        <div class="d-flex justify-content-center mt-4">
                                            <button type="button" class="btn btn-primary px-5" data-bs-dismiss="modal">
                                                {{ translate('got_it') }}
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
    @include("layouts.admin.partials.offcanvas._firebase-config-setup")
@endsection
