@extends('layouts.admin.app')

@section('title', translate('social_Media'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 mb-sm-20">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                {{ translate('3rd_Party') }} - {{ translate('Other_Configurations') }}
            </h2>
        </div>
        @include('admin-views.third-party._third-party-others-menu')

        <?php
        $socialLoginServices = getWebConfig(name: 'social_login');
        $appleLoginServices = getWebConfig(name: 'apple_login') ?? [];
        ?>
        @php($whatsapp = getWebConfig('whatsapp'))
        <div class="card mb-3">
            <form action="{{ route('admin.third-party.social-media-chat.update',['whatsapp']) }}" method="post">
                @csrf
                <div class="card-header px-20 py-3">
                    <h2>{{ translate('Social Media Chat') }}</h2>
                    <p class="mb-0 fs-12">
                        {{ translate('setup_social_media_chatting_feature_for_customer_easy_to_talk_to_you') }}.
                    </p>
                </div>
                <div class="card-body">
                    <div class="d-flex flex-column gap-20">
                        <div class="card card-sm shadow-1">
                            <div class="card-body">
                                <div class="view-details-container">
                                    <div class="d-flex justify-content-between align-items-center gap-3">
                                        <div>
                                            <h3 class="mb-1">
                                                {{ translate('WhatsApp') }}
                                            </h3>
                                            <p class="mb-0 fs-12">
                                                {{ translate('provide_a_whatsapp_number_where_customer_can_chat_with_you') }}
                                                .
                                            </p>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <a href="javascript:"
                                               class="fs-12 fw-semibold d-flex align-items-end view-btn">
                                                {{ translate('View') }}
                                                <i class="fi fi-rr-arrow-small-down fs-16 trans3"></i>
                                            </a>
                                            <label class="switcher" for="whatsapp-id">
                                                <input
                                                    class="switcher_input custom-modal-plugin"
                                                    type="checkbox" value="1" name="status"
                                                    id="whatsapp-id"
                                                    {{ isset($whatsapp['status']) && $whatsapp['status'] == 1 ? 'checked' : '' }}
                                                    data-modal-type="input-change"
                                                    data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/social/whatsapp-on.png') }}"
                                                    data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/social/whatsapp-off.png') }}"
                                                    data-on-title="{{ translate('want_to_turn_ON_WhatsApp_as_social_media_chat_option').'?'}}"
                                                    data-off-title="{{ translate('want_to_turn_OFF_WhatsApp_as_social_media_chat_option').'?'}}"
                                                    data-on-message="<p>{{ translate('if_enabled,WhatsApp_chatting_option_will_be_available_in_the_system') }}</p>"
                                                    data-off-message="<p>{{ translate('if_enabled,WhatsApp_chatting_option_will_be_hidden_from_the_system') }}</p>"
                                                    data-on-button-text="{{ translate('turn_on') }}"
                                                    data-off-button-text="{{ translate('turn_off') }}">
                                                <span class="switcher_control"></span>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="d--none view-details mt-3 mt-sm-4">
                                        <div
                                            class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center mb-3">
                                            <i class="fi fi-sr-lightbulb-on text-info"></i>
                                            <span>
                                            {{ translate('your_country_code_are_already_selected_based_on_your_business_country_that_you_selected_in') }} <a
                                                    href="{{ route('admin.business-settings.web-config.index') }}"
                                                    target="_blank"
                                                    class="fw-semibold text-decoration-underline">{{ translate('Business_Information') }}</a> {{ translate('page') }}.
                                        </span>
                                        </div>
                                        <div class="p-12 p-sm-20 bg-section rounded">
                                            @if($whatsapp)
                                                <div class="form-group">
                                                    <label class="form-label" for="">{{ translate('whatsapp_number') }}
                                                        <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                              data-bs-placement="top"
                                                              aria-label="{{ translate('provide_a_WhatsApp_number_without_country_code') }}"
                                                              data-bs-title="{{ translate('provide_a_WhatsApp_number_without_country_code') }}">
                                                        <i class="fi fi-sr-info"></i>
                                                    </span>
                                                    </label>
                                                    <input type="tel" id="phone" name="phone" class="form-control"
                                                           value="{{ $whatsapp['phone'] }}"
                                                           placeholder="{{ translate('Type your number') }}">
                                                </div>
                                                <div class="d-flex justify-content-end flex-wrap gap-3">
                                                    <button type="reset"
                                                            class="btn btn-secondary w-120 px-4">{{ translate('reset') }}</button>
                                                    <button type="{{config('app.mode')!='demo'?'submit':'button'}}"
                                                            class="btn btn-primary w-120 px-4 {{config('app.mode')!= 'demo'? '' : 'call-demo-alert'}}"
                                                    >{{ translate('save') }}</button>
                                                </div>
                                            @else
                                                <div class="mt-3 d-flex flex-wrap justify-content-center gap-10">
                                                    <button type="submit" class="btn btn-primary w-120 px-4 text-uppercase">
                                                        {{ translate('configure') }}
                                                    </button>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <div class="card">
            <div class="card-header px-20 py-3">
                <h2 class="text-capitalize">{{ translate('Social_Media_Login') }}</h2>
                <p class="mb-0 fs-12">
                    {{ translate('configure_social_login_options_so_customers_can_sign_in_with_their_social_media.') }}
                </p>
            </div>
            <div class="card-body">
                <div class="d-flex flex-column gap-20">
                    @if (isset($socialLoginServices))
                        @foreach ($socialLoginServices as $socialLoginService)
                            <div class="card card-sm shadow-1">
                                <div class="card-body">
                                    <form
                                        action="{{ route('admin.third-party.social-login.update',[$socialLoginService['login_medium']]) }}"
                                        method="post">
                                        @csrf
                                        <div class="view-details-container">
                                            <div class="d-flex justify-content-between align-items-center gap-3">
                                                <div>
                                                    <h3>
                                                        {{ translate($socialLoginService['login_medium']) }} {{ translate('login') }}
                                                    </h3>
                                                    <p class="mb-1 fs-12">
                                                        {{ translate('Use') }} {{ translate($socialLoginService['login_medium']) }}  {{ translate('login_as_your_customer_social_media_login_turn_the_switch_&_setup_the_required_files') }}
                                                    </p>
                                                    <a data-bs-toggle="modal"
                                                       href="#{{ $socialLoginService['login_medium'] }}-modal"
                                                       class="fs-12 text-decoration-underline">{{ translate('Get_Credential_Setup') }}</a>
                                                </div>
                                                <div class="d-flex gap-2">
                                                    <a href="javascript:"
                                                       class="fs-12 fw-semibold d-flex align-items-end view-btn">
                                                        {{ translate('View') }}
                                                        <i class="fi fi-rr-arrow-small-down fs-16 trans3"></i></a>
                                                </div>
                                            </div>
                                            <div class="d--none view-details mt-3 mt-sm-4">
                                                <div class="p-12 p-sm-20 bg-section rounded">
                                                    <div class="form-group">
                                                        <label class="form-label" for="">{{ translate('callback_URI') }}
                                                            <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                                  data-bs-placement="top"
                                                                  aria-label="{{ translate('add_the_OAuth_authorization_URL') }}"
                                                                  data-bs-title="{{ translate('add_the_OAuth_authorization_URL') }}">
                                                            <i class="fi fi-sr-info"></i>
                                                        </span>
                                                        </label>
                                                        <div
                                                            class="form-control d-flex align-items-center justify-content-between gap-2 px-3 py-2">
                                                            <span class="form-ellipsis d-flex"
                                                                  id="id_{{ $socialLoginService['login_medium'] }}">{{ route('home') }}/customer/auth/login/{{ $socialLoginService['login_medium'] }}/callback</span>
                                                            <a href="javascript:" class="copy-to-clipboard"
                                                               data-id="#id_{{ $socialLoginService['login_medium'] }}">
                                                                <i class="fi fi-rr-duplicate"></i>
                                                            </a>
                                                        </div>
                                                    </div>
                                                    <div class="form-group">
                                                        <label class="form-label"
                                                               for="">{{ translate('store_Client_ID') }}
                                                            <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                                  data-bs-placement="top"
                                                                  aria-label="{{ translate('add_the_unique_client_ID') }}"
                                                                  data-bs-title="{{ translate('add_the_unique_client_ID') }}">
                                                            <i class="fi fi-sr-info"></i>
                                                        </span>
                                                        </label>
                                                        <input type="text" class="form-control" name="client_id"
                                                               placeholder="{{ translate('ex') }}:{{ translate('client_ID') }}"
                                                               value="{{config('app.mode')!='demo'? $socialLoginService['client_id']??"":''}}">
                                                    </div>
                                                    <div class="form-group">
                                                        <label class="form-label"
                                                               for="">{{ translate('store_Client_Secret_Key') }}
                                                            <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                                  data-bs-placement="top"
                                                                  aria-label="{{ translate('store_Client_Secret_Key') }}"
                                                                  data-bs-title="{{ translate('store_Client_Secret_Key') }}">
                                                            <i class="fi fi-sr-info"></i>
                                                        </span>
                                                        </label>
                                                        <input type="text" class="form-control form-ellipsis"
                                                               name="client_secret"
                                                               placeholder="{{ translate('ex') }}:{{ translate('client_secret_key') }}"
                                                               value="{{config('app.mode')!='demo'?$socialLoginService['client_secret']??"":''}}">
                                                    </div>
                                                </div>
                                                <div class="d-flex justify-content-end flex-wrap gap-3 mt-4">
                                                    <button type="reset"
                                                            class="btn btn-secondary w-120 px-4">{{ translate('reset') }}</button>
                                                    <button type="{{config('app.mode')!='demo'?'submit':'button'}}"
                                                            class="btn btn-primary w-120 px-4 {{config('app.mode')!= 'demo'? '' : 'call-demo-alert'}}"
                                                    >{{ translate('save') }}</button>
                                                </div>
                                            </div>
                                        </div>

                                    </form>
                                </div>
                            </div>
                        @endforeach
                    @endif

                    @if(isset($appleLoginServices))
                        @foreach ($appleLoginServices as $appleLoginService)
                            <div class="card card-sm shadow-1">
                                <div class="card-body">
                                    <form
                                        action="{{ route('admin.third-party.social-login.update-apple', [$appleLoginService['login_medium']]) }}"
                                        method="post" enctype="multipart/form-data" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
                                        @csrf

                                        <div class="view-details-container">
                                            <div class="d-flex justify-content-between align-items-center gap-3">
                                                <div>
                                                    <h3>
                                                        {{ translate($appleLoginService['login_medium']) }} {{ translate('login') }}
                                                    </h3>
                                                    <p class="mb-1 fs-12">
                                                        {{ translate('Use') }} {{ translate($appleLoginService['login_medium']) }}  {{ translate('login as your customer Social Media Login turn the switch & setup the required files.') }}
                                                    </p>
                                                    <a data-bs-toggle="modal"
                                                       href="#{{ $appleLoginService['login_medium'] }}-modal"
                                                       class="fs-12 text-decoration-underline">Get Credential Setup</a>
                                                </div>
                                                <div class="d-flex gap-2">
                                                    <a href="javascript:"
                                                       class="fs-12 fw-semibold d-flex align-items-end view-btn">{{ translate('View') }}
                                                        <i class="fi fi-rr-arrow-small-down fs-16 trans3"></i></a>
                                                </div>
                                            </div>
                                            <div class="d--none view-details mt-3 mt-sm-4">
                                                <div class="p-12 p-sm-20 bg-section rounded">
                                                    <div class="form-group">
                                                        <label class="form-label" for="">{{ translate('callback_URI') }}
                                                            <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                                  data-bs-placement="top"
                                                                  aria-label="{{ translate('add_the_OAuth_authorization_URL') }}"
                                                                  data-bs-title="{{ translate('add_the_OAuth_authorization_URL') }}">
                                                            <i class="fi fi-sr-info"></i>
                                                        </span>
                                                        </label>
                                                        <div
                                                            class="form-control d-flex align-items-center justify-content-between gap-2 px-3 py-2">
                                                            <span class="form-ellipsis d-flex"
                                                                  id="id_{{ $appleLoginService['login_medium'] }}">{{ url('/') }}/customer/auth/login/{{ $appleLoginService['login_medium'] }}/callback</span>
                                                            <a href="javascript:" class="copy-to-clipboard"
                                                               data-id="#id_{{ $appleLoginService['login_medium'] }}">
                                                                <i class="fi fi-rr-duplicate"></i>
                                                            </a>
                                                        </div>
                                                    </div>
                                                    <div class="form-group">
                                                        <label class="form-label"
                                                               for="">{{ translate('store_Client_ID') }}
                                                            <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                                  data-bs-placement="top"
                                                                  aria-label="{{ translate('add_the_unique_client_ID') }}"
                                                                  data-bs-title="{{ translate('add_the_unique_client_ID') }}">
                                                            <i class="fi fi-sr-info"></i>
                                                        </span>
                                                        </label>
                                                        <input type="text" class="form-control" name="client_id"
                                                               placeholder="{{ translate('ex') }}:{{ translate('client_ID') }}"
                                                               value="{{ $appleLoginService['client_id'] }}">
                                                    </div>
                                                    <div class="form-group">
                                                        <label class="form-label" for="">{{ translate('team_id') }}
                                                            <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                                  data-bs-placement="top"
                                                                  aria-label="{{ translate('team_ID') }}"
                                                                  data-bs-title="{{ translate('team_ID') }}">
                                                            <i class="fi fi-sr-info"></i>
                                                        </span>
                                                        </label>
                                                        <input type="text" class="form-control" name="team_id"
                                                               placeholder="{{ translate('ex').':'.translate('team_id') }}"
                                                               value="{{ $appleLoginService['team_id'] }}">
                                                    </div>
                                                    <div class="form-group">
                                                        <label class="form-label"
                                                               for="">{{ translate('store_Client_Secret_Key') }}
                                                            <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                                  data-bs-placement="top"
                                                                  aria-label="{{ translate('store_Client_Secret_Key') }}"
                                                                  data-bs-title="{{ translate('store_Client_Secret_Key') }}">
                                                            <i class="fi fi-sr-info"></i>
                                                        </span>
                                                        </label>
                                                        <input type="text" class="form-control" name="key_id"
                                                               placeholder="{{ translate('ex').':'.translate('key_ID') }}"
                                                               value="{{ $appleLoginService['key_id'] }}">
                                                    </div>
                                                    <div class="form-group">
                                                        <label class="form-label" for="customFileUpload">
                                                            {{ translate('choose_updated_file') }}
                                                        </label>
                                                        <input type="file" class="form-control" name="service_file" accept=".p8"
                                                               id="customFileUpload">
                                                    </div>
                                                </div>
                                                <div class="d-flex justify-content-end flex-wrap gap-3 mt-4">
                                                    <button type="reset"
                                                            class="btn btn-secondary w-120 px-4">{{ translate('reset') }}</button>
                                                    <button type="{{config('app.mode')!='demo'?'submit':'button'}}"
                                                            class="btn btn-primary w-120 px-4 {{config('app.mode')!= 'demo'? '' : 'call-demo-alert'}}"
                                                    >{{ translate('save') }}</button>
                                                </div>
                                            </div>
                                        </div>

                                    </form>
                                </div>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
        </div>
        <div class="modal fade" id="google-modal" data-backdrop="static" data-keyboard="false" tabindex="-1"
             aria-labelledby="staticBackdropLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                        <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                                data-bs-dismiss="modal" aria-label="Close">
                        </button>
                    </div>
                    <div class="modal-body px-20 py-0 mb-30">
                        <div class="d-flex gap-3 flex-column align-items-center text-center mb-4">
                            <img width="80" src="{{ dynamicAsset(path: 'public/assets/back-end/img/google-logo.png') }}"
                                 alt="">
                            <h4 class="modal-title"
                                id="staticBackdropLabel">{{ translate('google_API_Set_up_Instructions') }}</h4>
                        </div>
                        <ol class="d-flex flex-column gap-2 max-h-40vh overflow-y-auto">
                            <li>{{ translate('go_to_the_Google_Developers_Console') }}.</li>
                            <li>{{ translate('create_a_new_project_or_select_an_existing_project').'.'}}</li>
                            <li>{{ translate('click_on_Credentials_in_the_left-hand_menu').'.'}}</li>
                            <li>{{ translate('create_an_OAuth_client_ID_for_a_web_application').'.'}}</li>
                            <li>{{ translate('enter_a_name_for_your_client ID_and_click_Create').'.'}}</li>
                            <li>{{ translate('enter_the_URL_of_your_website_as_an_authorized_JavaScript_origin').'.'}}</li>
                            <li>{{ translate('enter_the_callback_URL_as_an_authorized_redirect_URL').'.'}}</li>
                            <li>{{ translate('copy_and_paste_the_client_ID_and_client_secret_into_your_application`s_settings').'.'}}</li>
                            <li>{{ translate('enable_the_Google_login_option_in_your_application`s_settings_and_thoroughly_test_the_integration_before_deploying_it_to_a_live_environment').'.'}}</li>
                        </ol>
                        <div class="d-flex justify-content-center mt-4">
                            <button type="button" class="btn btn-primary px-5"
                                    data-bs-dismiss="modal">{{ translate('got_it') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal fade" id="facebook-modal" data-backdrop="static" data-keyboard="false" tabindex="-1"
             aria-labelledby="staticBackdropLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                        <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                                data-bs-dismiss="modal" aria-label="Close">
                        </button>
                    </div>
                    <div class="modal-body px-20 py-0 mb-30">
                        <div class="d-flex gap-3 flex-column align-items-center text-center mb-4">
                            <img width="80" src="{{ dynamicAsset(path: 'public/assets/back-end/img/facebook.png') }}"
                                 alt="">
                            <h5 class="modal-title text-capitalize"
                                id="staticBackdropLabel">{{ translate('facebook_API_set_up_instructions') }}</h5>
                        </div>

                        <ol class="d-flex flex-column gap-2">
                            <li>{{ translate('go_to_the_Facebook_Developer_website').'.'}}</li>
                            <li>{{ translate('create_a_new_app_or_select_an_existing_app').'.'}}</li>
                            <li>{{ translate('click_on_Add_a_New_App_or_select_an_existing_app_from_the_dashboard').'.'}}</li>
                            <li>{{ translate('fill_in_the_required_details,_such_as_Display_Name,_Contact_Email,_and_App_Purpose').'.'}}</li>
                            <li>{{ translate('click_Create_App_to_create_your_app').'.'}}</li>
                            <li>{{ translate('in_the_left-hand_menu,_click_on "Settings"_and_then_"Basic"_to access_your_app`s_basic_settings').'.'}}</li>
                            <li>{{ translate('scroll_down_to_the_"Facebook_Login"_section_and_click_on_"Set_Up"_to_configure_your_Facebook_login_settings').'.'}}</li>
                            <li>{{ translate('choose_the_login_behavior,_permissions,_and_other_settings_as_per_your_requirements').'.'}}</li>
                            <li>{{ translate('copy_and_paste_the_App_ID_and_App_Secret_into_your_application`s_settings').'.'}}</li>
                            <li>{{ translate('enable_the_Facebook_login_option_in_your_applications_settings_and_thoroughly_test_the_integration_before_deploying_it_to_a_live_environment').'.'}}</li>
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
        <div class="modal fade" id="apple-modal" data-backdrop="static" data-keyboard="false" tabindex="-1"
             aria-labelledby="staticBackdropLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content"
                     style="text-align: {{Session::get('direction') === "rtl" ? 'right' : 'left'}};">
                    <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                        <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                                data-bs-dismiss="modal" aria-label="Close">
                        </button>
                    </div>
                    <div class="modal-body px-20 py-0 mb-30">
                        <div class="d-flex gap-3 flex-column align-items-center text-center mb-4">
                            <img width="80" src="{{ dynamicAsset(path: 'public/assets/back-end/img/apple.png') }}" alt="">
                            <h4 class="modal-title"
                                id="staticBackdropLabel">{{ translate('apple_API_Set_up_Instructions') }}</h4>
                        </div>

                        <ol class="d-flex flex-column gap-2">
                            <li>{{ translate('go_to_apple_developer_page') }} (<a
                                    href="https://developer.apple.com/account/resources/identifiers/list"
                                    target="_blank">{{ translate('click_here') }}</a>)
                            </li>
                            <li>{{ translate('here_in_top_left_corner_you_can_see_the') }}
                                <b>{{ translate('team_ID') }}</b> [{{ translate('apple_developer_account_name') }}
                                ]{{'-'.' '. translate('team_ID') }}</li>
                            <li>{{ translate('click_plus_icon') }} -> {{ translate('select_app_IDs') }}
                                -> {{ translate('click_on_continue') }}</li>
                            <li>{{ translate('put_a_description_and_also_identifier_(identifier that used for app)_and_this_is_the') }}
                                <b>{{ translate('client_ID') }}</b></li>
                            <li>{{ translate('click_continue_and_download_the_file_in_device_named_AuthKey_ID.p8_(store_it_safely_and_it_is_used_for_push_notification)') }} </li>
                            <li>{{ translate('again_click_plus_icon') }}
                                -> {{ translate('select_service_IDs').' '.'->'.' '.translate('click_on_continue') }}</li>
                            <li>{{ translate('push_a_description_and_also_identifier_and_continue') }} </li>
                            <li>{{ translate('download_the_file_in_device_named') }}
                                <b>{{ translate('AuthKey_KeyID.p8') }}</b>
                                [{{ translate('this_is_the_service_key_ID_file_and_also_after_AuthKey_that_is_the_key_ID') }}
                                ]
                            </li>
                        </ol>
                        <div class="d-flex justify-content-center mt-4">
                            <button type="button" class="btn btn-primary px-5"
                                    data-bs-dismiss="modal">{{ translate('got_it') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal fade" id="twitter-modal" data-backdrop="static" data-keyboard="false" tabindex="-1"
             aria-labelledby="staticBackdropLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content"
                     style="text-align: {{Session::get('direction') === "rtl" ? 'right' : 'left'}};">
                    <div class="modal-header">
                        <h4 class="modal-title"
                            id="staticBackdropLabel">{{ translate('twitter_API_Set_up_Instructions') }}</h4>
                        <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                                data-bs-dismiss="modal" aria-label="Close">
                        </button>
                    </div>
                    <div class="modal-body"><b></b>
                        {{ translate('instruction_will_be_available_very_soon') }}
                        <div class="d-flex justify-content-center mt-4">
                            <button type="button" class="btn btn-primary px-5"
                                    data-bs-dismiss="modal">{{ translate('got_it') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @include("layouts.admin.partials.offcanvas._3rd-party-login-setup")
@endsection
