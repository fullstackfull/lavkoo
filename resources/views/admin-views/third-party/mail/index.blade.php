@php
    use App\Enums\EmailTemplateKey;
@endphp

@extends('layouts.admin.app')

@section('title', translate('mail_configuration'))

@push('css_or_js')
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 mb-sm-20">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                 {{ translate('3rd_Party') }} - {{ translate('Other_Configurations') }}
            </h2>
        </div>
        @include('admin-views.third-party._third-party-others-menu')

        <div class="card mb-3">
            <div class="card-body d-flex justify-content-between align-items-center gap-3 flex-wrap">
                <div>
                    <h2>{{ translate('mail_configuration') }}</h2>
                    <p class="fs-12 mb-0">
                        {{ translate('set_up_email_configuration_here_to_enable_sending_emails_via') }} <a href="{{route('admin.system-setup.email-templates.view',['admin',EmailTemplateKey::ADMIN_EMAIL_LIST[0]])}}" target="_blank" class="fw-semibold text-info-dark">{{ translate('Email Template') }}</a>.
                    </p>
                </div>
                <button class="btn btn-outline-primary text-capitalize" data-bs-toggle="modal"
                data-bs-target="#send-mail-confirmation-modal">
                    <i class="fi fi-sr-paper-plane"></i>
                    {{translate('send_test_mail')}}
                </button>
            </div>
        </div>
        <div class="card mb-3">
            <div class="card-body">
                @php($data_smtp=getWebConfig(name: 'mail_config'))
                <form action="{{route('admin.third-party.mail.update')}}" method="post" id="smtp-mail-config-form" class="form-advance-validation  form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate>
                    @csrf
                    @if(isset($data_smtp))

                    <div class="view-details-container">
                        <div class="d-flex justify-content-between align-items-center gap-3">
                            <div>
                                <h3>
                                    {{ translate('SMTP Mail Configuration') }}
                                </h3>
                                <p class="mb-1 fs-12">
                                    {{ translate("configure_email_sending_using_your_own_SMTP_server") }}.
                                </p>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="javascript:" class="fs-12 fw-semibold d-flex align-items-end view-btn">{{ translate('View') }} <i class="fi fi-rr-arrow-small-down fs-16 trans3"></i></a>
                                <label class="switcher">
                                    <input
                                        class="switcher_input custom-modal-plugin"
                                        type="checkbox" value="1" name="status"
                                        {{ $data_smtp['status'] == 1 ? 'checked':'' }}
                                        data-modal-type="input-change-form"
                                        data-modal-form="#smtp-mail-config-form"
                                        data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/maintenance_mode-on.png') }}"
                                        data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/maintenance_mode-off.png') }}"
                                        data-on-title="{{translate('want_to_Turn_ON_the_smtp_mail_config_option').'?'}}"
                                        data-off-title="{{translate('want_to_Turn_OFF_the_smtp_mail_config_option').'?'}}"
                                        data-on-message="<p>{{translate('enabling_mail_configuration_services_will_allow_the_system_to_send_emails').'.'.translate('please_ensure_that_you_have_correctly_configured_the_SMTP_settings_to_avoid_potential_issues_with_email_delivery')}}</p>"
                                        data-off-message="<p>{{translate('disabling_SMTP_mail_configuration_services_stops_email_sending')}}</p>">
                                    <span class="switcher_control"></span>
                                </label>
                            </div>
                        </div>
                        <div class="view-details mt-3 mt-sm-4">
                            <div class="p-12 p-sm-20 bg-section rounded">
                                <div class="row g-4">
                                    <div class="col-lg-4 col-sm-6">
                                        <div class="form-group">
                                            <label class="form-label" for="">{{translate('mailer_name')}}
                                                <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
                                                    aria-label="{{translate('enter_mailer_name')}}"
                                                    data-bs-title="{{translate('enter_mailer_name')}}">
                                                    <i class="fi fi-sr-info"></i>
                                                </span>
                                                <span class="text-danger">*</span>
                                            </label>
                                            <input type="text"
                                                   placeholder="{{translate('ex')}}:{{translate('alex')}}"
                                                   class="form-control" name="name"
                                                   data-required-msg="{{translate('mailer_name_field_is_required')}}"
                                                   value="{{config('app.mode')=='demo' ? '' :$data_smtp['name']}}" required>
                                        </div>
                                    </div>
                                    <div class="col-lg-4 col-sm-6">
                                        <div class="form-group">
                                            <label class="form-label" for="">{{translate('host')}}
                                                <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
                                                    aria-label="{{translate('enter_the_name_of_the_host_of_your_mailing_service')}}"
                                                    data-bs-title="{{translate('enter_the_name_of_the_host_of_your_mailing_service')}}">
                                                    <i class="fi fi-sr-info"></i>
                                                </span>
                                                <span class="text-danger">*</span>
                                            </label>
                                           <input type="text" class="form-control" name="host"
                                                   placeholder="{{translate('ex').':'}} {{translate('smtp.mailtrap.io')}}"
                                                  data-required-msg="{{translate('host_field_is_required')}}"
                                                   value="{{config('app.mode')=='demo'?'':$data_smtp['host']}}" required>
                                        </div>
                                    </div>
                                    <div class="col-lg-4 col-sm-6">
                                        <div class="form-group">
                                            <label class="form-label" for="">{{translate('driver')}}
                                                <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
                                                    aria-label="{{translate('enter_the_driver_for_your_mailing_service')}}"
                                                    data-bs-title="{{translate('enter_the_driver_for_your_mailing_service')}}">
                                                    <i class="fi fi-sr-info"></i>
                                                </span>
                                                <span class="text-danger">*</span>
                                            </label>
                                            <input type="text" class="form-control" name="driver"
                                                   placeholder="{{translate('ex')}}:{{translate('smtp')}}"
                                                    data-required-msg="{{translate('driver_field_is_required')}}"
                                                   value="{{config('app.mode')=='demo'?'':$data_smtp['driver']}}" required>
                                        </div>
                                    </div>
                                    <div class="col-lg-4 col-sm-6">
                                        <div class="form-group">
                                            <label class="form-label" for="">{{translate('port')}}
                                                <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
                                                    aria-label="{{translate('enter_the_port_number_for_your_mailing_service')}}"
                                                    data-bs-title="{{translate('enter_the_port_number_for_your_mailing_service')}}">
                                                    <i class="fi fi-sr-info"></i>
                                                </span>
                                                <span class="text-danger">*</span>
                                            </label>
                                           <input type="text" class="form-control" name="port"
                                                   placeholder="{{translate('ex')}}:587"
                                                    data-required-msg="{{translate('port_field_is_required')}}"
                                                   value="{{config('app.mode')=='demo'?'':$data_smtp['port']}}" required>
                                        </div>
                                    </div>
                                    <div class="col-lg-4 col-sm-6">
                                        <div class="form-group">
                                            <label class="form-label" for="">{{translate('username')}}
                                                <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
                                                    aria-label="{{translate('enter_the_username_of_your_account')}}"
                                                    data-bs-title="{{translate('enter_the_username_of_your_account')}}">
                                                    <i class="fi fi-sr-info"></i>
                                                </span>
                                                <span class="text-danger">*</span>
                                            </label>
                                            <input type="text" placeholder="{{translate('ex : yahoo')}}"
                                                   class="form-control"
                                                   name="username"
                                                   data-required-msg="{{translate('username_field_is_required')}}"
                                                   value="{{config('app.mode')=='demo'?'':$data_smtp['username']}}" required>
                                        </div>
                                    </div>
                                    <div class="col-lg-4 col-sm-6">
                                        <div class="form-group">
                                            <label class="form-label" for="">{{translate('email_ID')}}
                                                <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
                                                    aria-label="{{translate('enter_your_email_ID')}}"
                                                    data-bs-title="{{translate('enter_your_email_ID')}}">
                                                    <i class="fi fi-sr-info"></i>
                                                </span>
                                                <span class="text-danger">*</span>
                                            </label>
                                            <input type="text"
                                                   placeholder="{{translate('ex')}}:{{translate('example@example.com')}}"
                                                   class="form-control" name="email"
                                                   data-required-msg="{{translate('email_field_is_required')}}"
                                                   value="{{config('app.mode')=='demo'?'':$data_smtp['email_id']}}" required>
                                        </div>
                                    </div>
                                    <div class="col-lg-4 col-sm-6">
                                        <div class="form-group">
                                            <label class="form-label" for="">{{translate('encryption')}}
                                                <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
                                                    aria-label="{{translate('enter_the_encryption_type')}}"
                                                    data-bs-title="{{translate('enter_the_encryption_type')}}">
                                                    <i class="fi fi-sr-info"></i>
                                                </span>
                                            </label>
                                           <input type="text"
                                                   placeholder="{{translate('ex :')}}:{{translate('tls')}}"
                                                   class="form-control" name="encryption"
                                                  data-required-msg="{{translate('encryption_field_is_required')}}"
                                                   value="{{config('app.mode')=='demo'?'':$data_smtp['encryption']}}" required>
                                        </div>
                                    </div>
                                    <div class="col-lg-4 col-sm-6">
                                        <div class="form-group">
                                            <label class="form-label" for="smtpPassword">{{translate('password')}}
                                                <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
                                                    aria-label="{{translate('enter_your_password')}}"
                                                    data-bs-title="{{translate('enter_your_password')}}">
                                                    <i class="fi fi-sr-info"></i>
                                                </span>
                                            </label>
                                            <div class="input-group">
                                                <input type="password" class="form-control"
                                                    value="{{config('app.mode')=='demo'?'':$data_smtp['password']}}"
                                                    name="password" id="smtpPassword"
                                                       data-required-msg="{{translate('password_field_is_required')}}"
                                                    placeholder="{{translate('ex')}}:123456"
                                                       required
                                                >
                                                <div class="input-group-append changePassTarget">
                                                    <a class="text-body-light" href="javascript:">
                                                        <i class="fi fi-sr-eye"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        </div>

                                    </div>
                                </div>
                            </div>
                            <div class="d-flex justify-content-end flex-wrap gap-3 mt-4">
                                <button type="reset" class="btn btn-secondary w-120 px-4">
                                    {{ translate('reset') }}
                                </button>
                                <button class="btn btn-primary w-120 px-4 {{ getDemoModeFormButton(type: 'class') }}"
                                        type="{{ getDemoModeFormButton(type: 'button') }}">
                                    {{ translate('save') }}
                                </button>
                                @else
                                    <button type="submit" class="btn btn-primary px-5">
                                        {{ translate('configure') }}
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>

                </form>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <form action="{{route('admin.third-party.mail.update-sendgrid')}}" method="post" id="sendgrid-mail-config-form" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate>
                    @csrf
                    @php($data_sendgrid=getWebConfig(name: 'mail_config_sendgrid'))
                    @if(isset($data_sendgrid))

                    <div class="view-details-container">
                        <div class="d-flex justify-content-between align-items-center gap-3">
                            <div>
                                <h3>
                                    {{ translate('sendgrid_mail_configuration') }}
                                </h3>
                                <p class="mb-1 fs-12">
                                    {{ translate('send_emails_via_sendgrids_secure_cloud_email_service') }}.
                                </p>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="javascript:" class="fs-12 fw-semibold d-flex align-items-end view-btn {{ $data_sendgrid['status'] == 1 ? 'active' : '' }}">{{ translate('View') }} <i class="fi fi-rr-arrow-small-down fs-16 trans3"></i></a>
                                <label class="switcher">
                                    <input
                                        class="switcher_input custom-modal-plugin"
                                        type="checkbox" value="1" name="status"
                                        {{ $data_sendgrid['status'] == 1 ? 'checked':'' }}
                                        data-modal-type="input-change-form"
                                        data-modal-form="#sendgrid-mail-config-form"
                                        data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/maintenance_mode-on.png') }}"
                                        data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/maintenance_mode-off.png') }}"
                                        data-on-title="{{translate('want_to_Turn_ON_the_sendgrid_mail_config_option').'?'}}"
                                        data-off-title="{{translate('want_to_Turn_OFF_the_sendgrid_mail_config_option').'?'}}"
                                        data-on-message="<p>{{translate('enabling_mail_configuration_services_will_allow_the_system_to_send_emails').'.'.translate('please_ensure_that_you_have_correctly_configured_the_sendgrid_settings_to_avoid_potential_issues_with_email_delivery')}}</p>"
                                        data-off-message="<p>{{translate('disabling_sendgrid_mail_configuration_services_stops_email_sending')}}</p>">
                                    <span class="switcher_control"></span>
                                </label>
                            </div>
                        </div>
                        <div class="view-details mt-3 mt-sm-4">
                            <div class="p-12 p-sm-20 bg-section rounded">
                                <div class="row g-4">
                                    <div class="col-lg-4 col-sm-6">
                                        <div class="form-group">
                                            <label class="form-label" for="">{{translate('mailer_name')}}
                                                <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
                                                    aria-label="{{translate('enter_mailer_name')}}"
                                                    data-bs-title="{{translate('enter_mailer_name')}}">
                                                    <i class="fi fi-sr-info"></i>
                                                </span>
                                                <span class="text-danger">*</span>
                                            </label>
                                            <input type="text"
                                                           placeholder="{{translate('ex').':'}}{{translate('alex')}}"
                                                           class="form-control" name="name"
                                                            data-required-msg="{{translate('mailer_name_field_is_required')}}"
                                                           value="{{config('app.mode')=='demo' ? '' :$data_sendgrid['name']}}" required>
                                        </div>
                                    </div>
                                    <div class="col-lg-4 col-sm-6">
                                        <div class="form-group">
                                            <label class="form-label" for="">{{translate('host')}}
                                                <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
                                                    aria-label="{{translate('enter_the_name_of_the_host_of_your_mailing_service')}}"
                                                    data-bs-title="{{translate('enter_the_name_of_the_host_of_your_mailing_service')}}">
                                                    <i class="fi fi-sr-info"></i>
                                                </span>
                                                <span class="text-danger">*</span>
                                            </label>
                                            <input type="text" class="form-control" name="host"
                                                   placeholder="{{translate('ex')}}:{{translate('smtp.mailtrap.io')}}"
                                                   data-required-msg="{{translate('host_field_is_required')}}"
                                                   value="{{config('app.mode')=='demo' ? '' : $data_sendgrid['host']}}" required>
                                        </div>
                                    </div>
                                    <div class="col-lg-4 col-sm-6">
                                        <div class="form-group">
                                            <label class="form-label" for="">{{translate('driver')}}
                                                <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
                                                    aria-label="{{translate('enter_the_driver_for_your_mailing_service')}}"
                                                    data-bs-title="{{translate('enter_the_driver_for_your_mailing_service')}}">
                                                    <i class="fi fi-sr-info"></i>
                                                </span>
                                                <span class="text-danger">*</span>
                                            </label>
                                            <input type="text" class="form-control" name="driver"
                                                           placeholder="{{translate('ex')}}:{{translate('smtp')}}"
                                                   data-required-msg="{{translate('smtp_field_is_required')}}"
                                                           value="{{config('app.mode')=='demo'?'':$data_sendgrid['driver']}}" required>

                                        </div>
                                    </div>
                                    <div class="col-lg-4 col-sm-6">
                                        <div class="form-group">
                                            <label class="form-label" for="">{{translate('port')}}
                                                <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
                                                    aria-label="{{translate('enter_the_port_number_for_your_mailing_service')}}"
                                                    data-bs-title="{{translate('enter_the_port_number_for_your_mailing_service')}}">
                                                    <i class="fi fi-sr-info"></i>
                                                </span>
                                                <span class="text-danger">*</span>
                                            </label>
                                            <input type="text" class="form-control" name="port"
                                            placeholder="{{translate('ex').':'.'587'}}"
                                                   data-required-msg="{{translate('port_field_is_required')}}"
                                            value="{{config('app.mode')=='demo'?'':$data_sendgrid['port']}}" required>
                                        </div>
                                    </div>
                                    <div class="col-lg-4 col-sm-6">
                                        <div class="form-group">
                                            <label class="form-label" for="">{{translate('username')}}
                                                <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
                                                    aria-label="{{translate('enter_the_username_of_your_account')}}"
                                                    data-bs-title="{{translate('enter_the_username_of_your_account')}}">
                                                    <i class="fi fi-sr-info"></i>
                                                </span>
                                                <span class="text-danger">*</span>
                                            </label>
                                            <input type="text" placeholder="{{translate('ex').':'.'yahoo'}}"
                                                           class="form-control" name="username"
                                                   data-required-msg="{{translate('username_field_is_required')}}"
                                                           value="{{config('app.mode')=='demo'?'':$data_sendgrid['username']}}" required>
                                        </div>
                                    </div>
                                    <div class="col-lg-4 col-sm-6">
                                        <div class="form-group">
                                            <label class="form-label" for="">{{translate('email_ID')}}
                                                <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
                                                    aria-label="{{translate('enter_your_email_ID')}}"
                                                    data-bs-title="{{translate('enter_your_email_ID')}}">
                                                    <i class="fi fi-sr-info"></i>
                                                </span>
                                                <span class="text-danger">*</span>
                                            </label>
                                            <input type="text"
                                            placeholder="{{translate('ex').':'}}{{translate('example@example.com')}}"
                                            class="form-control" name="email"
                                                   data-required-msg="{{translate('email_field_is_required')}}"
                                            value="{{config('app.mode')=='demo'?'':$data_sendgrid['email_id']}}" required>
                                        </div>
                                    </div>
                                    <div class="col-lg-4 col-sm-6">
                                        <div class="form-group">
                                            <label class="form-label" for="">{{translate('encryption')}}
                                                <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
                                                    aria-label="{{translate('enter_the_encryption_type')}}"
                                                    data-bs-title="{{translate('enter_the_encryption_type')}}">
                                                    <i class="fi fi-sr-info"></i>
                                                </span>
                                            </label>
                                            <input type="text"
                                            placeholder="{{translate('ex').':'}}{{translate('tls')}}"
                                            class="form-control" name="encryption"
                                                   data-required-msg="{{translate('encryption_field_is_required')}}"
                                            value="{{config('app.mode')=='demo'?'':$data_sendgrid['encryption']}}" required>
                                        </div>
                                    </div>
                                    <div class="col-lg-4 col-sm-6">
                                        <div class="form-group">
                                            <label class="form-label" for="smtpPassword">{{translate('password')}}
                                                <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
                                                    aria-label="{{translate('enter_your_password')}}"
                                                    data-bs-title="{{translate('enter_your_password')}}">
                                                    <i class="fi fi-sr-info"></i>
                                                </span>
                                            </label>
                                            <div class="input-group">
                                                <input type="password" class="form-control"
                                                       name="password" id="sendGridPassword"
                                                       placeholder="{{translate('ex')}}:123456"
                                                       data-required-msg="{{translate('password_field_is_required')}}"
                                                       value="{{config('app.mode')=='demo'?'':$data_sendgrid['password']}}" required>

                                                <div class="input-group-append changePassTarget">
                                                    <a class="text-body-light" href="javascript:">
                                                        <i class="fi fi-sr-eye"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        </div>

                                    </div>
                                </div>
                            </div>
                            <div class="d-flex justify-content-end flex-wrap gap-3 mt-4">
                                <button type="reset" class="btn btn-secondary w-120 px-4">
                                    {{ translate('reset') }}
                                </button>
                                <button class="btn btn-primary w-120 px-4 {{ getDemoModeFormButton(type: 'class') }}"
                                        type="{{ getDemoModeFormButton(type: 'button') }}">
                                    {{ translate('save') }}
                                </button>
                                @else
                                    <button type="submit" class="btn btn-primary px-5">
                                        {{ translate('configure') }}
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>

                </form>
            </div>
        </div>
    </div>
    <div class="modal fade" id="getInformationModal" tabindex="-1" aria-labelledby="getInformationModal"
         aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                    <button type="button" class="btn-close border-0" data-bs-dismiss="modal" aria-label="Close"><i
                            class="tio-clear"></i></button>
                </div>
                <div class="modal-body px-4 px-sm-5 pt-0">
                    <div class="swiper instruction-carousel pb-3">
                        <div class="swiper-wrapper">
                            <div class="swiper-slide">
                                <div class="d-flex flex-column align-items-center gap-2">
                                    <img width="80" class="mb-3"
                                         src="{{dynamicAsset(path: 'public/assets/back-end/img/smtp-server.png')}}" loading="lazy"
                                         alt="">
                                    <h4 class="lh-md mb-3 text-capitalize">{{translate('find_SMTP_server_details')}}</h4>
                                    <ul class="d-flex flex-column px-4 gap-2 mb-4 w-100">
                                        <li>
                                            {{translate('contact_your_email_service_provider_or_IT_administrator_to_obtain_the_SMTP_server_details_such_as_hostname_port_username_and_password').'.'}}
                                        </li>
                                        <li>{{translate('note').':'}}
                                             {{translate('if_you`re_not_sure_where_to_find_these_details,_check_the_email_provider`s_documentation_or_support_resources_for_guidance').'.'}}
                                        </li>
                                    </ul>
                                </div>
                            </div>
                            <div class="swiper-slide">
                                <div class="d-flex flex-column align-items-center gap-2">
                                    <img width="80" class="mb-3"
                                         src="{{dynamicAsset(path: 'public/assets/back-end/img/config-smtp.png')}}" loading="lazy"
                                         alt="">
                                    <h4 class="lh-md mb-3 text-capitalize">{{translate('configure_SMTP_settings')}}</h4>
                                    <ul class="d-flex flex-column px-4 gap-2 mb-4 w-100">
                                        <li>{{translate('go_to_the_SMTP_mail_setup_page_in_the_admin_panel').'.'}}</li>
                                        <li>{{translate('enter_the_obtained_SMTP_server_details,_including_the_hostname,_port,_username,_and password').'.'}}</li>
                                        <li>{{translate('choose_the_appropriate_encryption_method').' '.'(e.g., SSL,TLS)'.' '.translate('if_required').'.'}}</li>
                                        <li>{{translate('save_the_settings').'.'}}</li>
                                    </ul>
                                </div>
                            </div>
                            <div class="swiper-slide">
                                <div class="d-flex flex-column align-items-center gap-2">
                                    <img width="80" class="mb-3"
                                         src="{{dynamicAsset(path: 'public/assets/back-end/img/test-smtp.png')}}" loading="lazy"
                                         alt="">
                                    <h4 class="lh-md mb-3 text-capitalize">{{translate('test_SMTP_connection')}}</h4>
                                    <ul class="d-flex flex-column px-4 gap-2 mb-4 w-100">
                                        <li>{{translate('click_on_the').'"'.translate('send_test_mail').'"'.translate('button_to_verify_the_SMTP_connection')}}
                                        </li>
                                        <li>{{translate('if_successful,_you_will_see_a_confirmation_message_indicating_that_the_connection_is_working_fine').'.'}} </li>
                                        <li>{{translate('if_not,_double-check_your_SMTP_settings_and_try_again').'.'}}</li>
                                        <li>{{translate('note').':'.translate('if_you_are_unsure_about_the_SMTP_settings,_contact_your_email_service_provider_or_IT_administrator_for_assistance').'.'}}
                                        </li>
                                    </ul>
                                </div>
                            </div>
                            <div class="swiper-slide">
                                <div class="d-flex flex-column align-items-center gap-2 mb-4">
                                    <img width="80" class="mb-3"
                                         src="{{dynamicAsset(path: 'public/assets/back-end/img/enable-mail-config.png')}}"
                                         loading="lazy" alt="">
                                    <h4 class="lh-md mb-3 text-capitalize">{{translate('enable_mail_configuration')}}</h4>
                                    <ul class="d-flex flex-column px-4 gap-2 mb-4 w-100">
                                        <li>{{ translate('if_the_SMTP_connection_test_is_successful') }} {{ translate('you_can_now_enable_the_mail_configuration_services_by_toggling_the_switch_to_ON')}}</li>
                                        <li>{{ translate('this_will_allow_the_system_to_send_emails_using_the_configured_SMTP_settings').'.' }}</li>
                                    </ul>
                                    <button class="btn btn-primary px-10 mt-3 text-capitalize"
                                            data-bs-dismiss="modal">{{ translate('got_it') }}</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="instruction-pagination-custom my-2"></div>
                </div>
            </div>
        </div>
    </div>
    <div class="modal fade" id="send-mail-confirmation-modal" tabindex="-1"
         aria-labelledby="send-mail-confirmation-modal" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered max-w-655">
            <div class="modal-content">
                <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                    <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                        data-bs-dismiss="modal" aria-label="Close">
                    </button>
                </div>
                <div class="modal-body px-20 py-0 mb-30">
                    <div class="d-flex flex-column gap-sm-20 gap-3">
                        <div>
                            <h3 class="text-capitalize">{{ translate('send_test_mail') }}</h3>
                            <p class="fs-12 mb-0">{{ translate('insert_a_valid_email_addresser_to_get_mail') }}</p>
                        </div>
                        <div class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                            <i class="fi fi-sr-lightbulb-on text-info"></i>
                            <span>
                                {{ translate('smtp_is_configured_for_mail_please_test_to_ensure_you_are_receiving_mail_correctly') }}.
                            </span>
                        </div>
                        <div class="p-12 p-sm-20 bg-section rounded d-flex flex-wrap gap-sm-20 gap-3 justify-content-end align-items-end text-capitalize">
                            <div class="flex-grow-1">
                                <label class="form-label" for="">
                                    {{ translate('type_mail_address') }}
                                    <span class="text-danger">*</span>
                                </label>
                                <input type="email" id="test-email" class="form-control"
                                    placeholder="{{translate('ex').':'.'jhon@email.com'}}">
                            </div>
                            <button type="button" id="test-mail-send"
                            class="btn btn-primary px-4 min-w-120 h-40 text-capitalize">{{translate('send_mail')}}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <span id="get-send-mail-route-text" data-action="{{route('admin.third-party.mail.send')}}"
          data-error-text="{{translate("email_configuration_error").'!!'}}"
          data-success-text="{{translate("email_configured_perfectly")}}"
          data-info-text="{{translate("email_status_is_not_active").'!'}}"
          data-invalid-text="{{translate("invalid_email_address").'!'}}">
    </span>
    @include("layouts.admin.partials.offcanvas._3rd-party-mail-setup")
@endsection

@push('script')
     <script src="{{ dynamicAsset(path: 'public/assets/new/back-end/js/mail.js')}}"></script>
@endpush
