@extends('layouts.admin.app')

@section('title', translate('environment_settings'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 mb-sm-20">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                {{ translate('system_Setup') }}
            </h2>
        </div>
        @include('admin-views.system-setup.system-settings-inline-menu')

        <div class="d-flex flex-column gap-3 gap-sm-20">
            <div class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded">
                <div class="d-flex gap-2 align-items-center mb-2">
                    <i class="fi fi-sr-lightbulb-on text-info"></i>
                    <span>
                    {{ translate('in_this_page_the_read_only_fields_data_are_the_database_&_installation_related._they_are_set_when_you_1st_install_your_system') }}.
                </span>
                </div>
                <ul class="m-0 ps-20 d-flex flex-column gap-1 text-body">
                    <li>{{ translate('the_app_debug_selection_field_is_for_debugging_your_system_technical_issue') }}.</li>
                    <li>{{ translate('when_you_on_debug_mode_to_check_your_system_issues_&_error_then_you_can_live_or_dev_mode') }}.</li>
                </ul>
            </div>

            <div>
                <div class="bg-warning bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                    <i class="fi fi-sr-info text-warning"></i>
                    <span>
                    {{ translate('be_careful_when_change_anything_in_this_page._they_are_directly_connected_to_your_system_database') }}.
                </span>
                </div>
            </div>

            <form action="{{route('admin.system-setup.environment-setup') }}" method="post"
                          enctype="multipart/form-data">
            @csrf
                <div class="card">
                    <div class="card-body">
                        <div class="mb-3 mb-sm-20">
                            <h3>{{ translate('Environment_Information') }}</h3>
                            <p class="mb-0 fs-12">
                                {{ translate('in_this_section_you_can_see_your_issue_by_changing_the_active_field_options.') }}
                            </p>
                        </div>
                            <div class="p-12 p-sm-20 bg-section rounded">
                                <div class="row g-4">
                                    <div class="col-xl-4 col-md-6">
                                        <div class="form-group">
                                            <label class="form-label text-capitalize" for="">{{ translate('app_name') }}
                                                <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                    data-bs-placement="right"
                                                    aria-label="{{ translate('app_name') }}"
                                                    data-bs-title="{{ translate('app_name') }}">
                                        </span>
                                            </label>
                                            <input type="text" value="{{ env('APP_NAME') }}"
                                                name="app_name" class="form-control"
                                                placeholder="{{ translate('ex').':'.translate('EFood') }}" required readonly
                                                data-bs-toggle="tooltip"
                                                data-bs-placement="top"
                                                aria-label="{{ translate('this_field_is_read_only_mode.') }}"
                                                data-bs-title="{{ translate('this_field_is_read_only_mode.') }}"
                                                >
                                        </div>
                                    </div>

                                    <div class="col-xl-4 col-md-6">
                                        <div class="form-group">
                                            <label class="form-label text-capitalize" for="">{{ translate('app_debug') }}</label>
                                            <select name="app_debug" class="custom-select"
                                                    data-placeholder="Select_from_dropdown">
                                                <option></option>
                                                <option value="true" {{env('APP_DEBUG')==1?'selected':''}}>
                                                    {{ translate('true') }}
                                                </option>
                                                <option value="false" {{env('APP_DEBUG')==0?'selected':''}}>
                                                    {{ translate('false') }}
                                                </option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-xl-4 col-md-6">
                                        <div class="form-group">
                                            <label class="form-label text-capitalize" for="">{{ translate('app_mode') }}</label>
                                            <select name="app_mode" class="custom-select"
                                                    data-placeholder="Select_from_dropdown">
                                                <option></option>
                                                <option value="live" {{config('app.mode')=='live'?'selected':''}}>
                                                    {{ translate('live') }}
                                                </option>
                                                <option value="dev" {{config('app.mode')=='dev'?'selected':''}}>
                                                    {{ translate('dev') }}
                                                </option>
                                            </select>
                                        </div>
                                    </div>


                                    <div class="col-xl-4 col-md-6">
                                        <div class="form-group">
                                            <label class="form-label text-capitalize" for="">
                                                {{ translate('app_url') }}
                                                <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                    data-bs-placement="right"
                                                    aria-label="{{ translate('app_url') }}"
                                                    data-bs-title="{{ translate('app_url') }}">
                                        </span>
                                            </label>
                                            <input type="text" value="{{ env('APP_URL') }}"
                                                name="app_url" class="form-control"
                                                placeholder="{{ translate('ex').':'.'http://localhost'}}" required
                                                readonly
                                                data-bs-toggle="tooltip"
                                                data-bs-placement="top"
                                                aria-label="{{ translate('this_field_is_read_only_mode.') }}"
                                                data-bs-title="{{ translate('this_field_is_read_only_mode.') }}"
                                                >
                                        </div>
                                    </div>

                                    <div class="col-xl-4 col-md-6">
                                        <div class="form-group">
                                            <label class="form-label text-capitalize" for="">{{ translate('DB_connection') }}</label>
                                            <input type="text"
                                                value="{{ config('app.mode') != 'demo' ? env('DB_CONNECTION') : '---' }}"
                                                name="db_connection" class="form-control"
                                                placeholder="{{ translate('ex').':'.'mysql' }}" required
                                                readonly
                                                data-bs-toggle="tooltip"
                                                data-bs-placement="top"
                                                aria-label="{{ translate('this_field_is_read_only_mode.') }}"
                                                data-bs-title="{{ translate('this_field_is_read_only_mode.') }}"
                                                >
                                        </div>
                                    </div>
                                    <div class="col-xl-4 col-md-6">
                                        <div class="form-group">
                                            <label class="form-label text-capitalize" for="">{{ translate('DB_host') }}</label>
                                            <input type="text" value="{{ config('app.mode') != 'demo' ? env('DB_HOST') : '---' }}"
                                                name="db_host" class="form-control"
                                                placeholder="{{ translate('ex').':'.'http://localhost/' }}" required
                                                readonly
                                                data-bs-toggle="tooltip"
                                                data-bs-placement="top"
                                                aria-label="{{ translate('this_field_is_read_only_mode.') }}"
                                                data-bs-title="{{ translate('this_field_is_read_only_mode.') }}"
                                            >
                                        </div>
                                    </div>
                                    <div class="col-xl-4 col-md-6">
                                        <div class="form-group">
                                            <label class="form-label text-capitalize" for="">{{ translate('DB_port') }}</label>
                                            <input type="text" value="{{ config('app.mode') != 'demo' ? env('DB_PORT') : '---' }}"
                                                name="db_port" class="form-control"
                                                placeholder="{{ translate('ex').':'.'3306' }}" required
                                                readonly
                                                data-bs-toggle="tooltip"
                                                data-bs-placement="top"
                                                aria-label="{{ translate('this_field_is_read_only_mode.') }}"
                                                data-bs-title="{{ translate('this_field_is_read_only_mode.') }}"
                                                >
                                        </div>
                                    </div>
                                    <div class="col-xl-4 col-md-6">
                                        <div class="form-group">
                                            <label class="form-label text-capitalize" for="">{{ translate('DB_database') }}</label>
                                            <input type="text"
                                                value="{{ config('app.mode') != 'demo' ? env('DB_DATABASE') : '---' }}"
                                                name="db_database" class="form-control"
                                                placeholder="{{ translate('ex').':'.'demo_db'}} " required
                                                readonly
                                                data-bs-toggle="tooltip"
                                                data-bs-placement="top"
                                                aria-label="{{ translate('this_field_is_read_only_mode.') }}"
                                                data-bs-title="{{ translate('this_field_is_read_only_mode.') }}"
                                                >
                                        </div>
                                    </div>
                                    <div class="col-xl-4 col-md-6">
                                        <div class="form-group">
                                            <label class="form-label text-capitalize" for="">{{ translate('DB_username') }}</label>
                                            <input type="text"
                                                value="{{ config('app.mode') != 'demo' ? env('DB_USERNAME') : '---' }}"
                                                name="db_username" class="form-control"
                                                placeholder="{{ translate('ex').':'.translate('root')  }}" required
                                                readonly
                                                data-bs-toggle="tooltip"
                                                data-bs-placement="top"
                                                aria-label="{{ translate('this_field_is_read_only_mode.') }}"
                                                data-bs-title="{{ translate('this_field_is_read_only_mode.') }}"
                                                >
                                        </div>
                                    </div>
                                    <div class="col-xl-4 col-md-6">
                                        <div class="form-group">
                                            <label class="form-label text-capitalize" for="">{{ translate('DB_password') }}</label>
                                            <input type="password"
                                                value="{{ config('app.mode') != 'demo' ? env('DB_PASSWORD') : '---' }}"
                                                name="db_password" class="form-control"
                                                placeholder="{{ translate('ex').':'.translate('password') }}"
                                                readonly
                                                data-bs-toggle="tooltip"
                                                data-bs-placement="top"
                                                aria-label="{{ translate('this_field_is_read_only_mode.') }}"
                                                data-bs-title="{{ translate('this_field_is_read_only_mode.') }}"
                                                >
                                        </div>
                                    </div>

                                    <div class="col-xl-4 col-md-6">
                                        <div class="form-group">
                                            <label class="form-label text-capitalize" for="">{{ translate('buyer_username') }}</label>
                                            <input type="text" value="{{ env('BUYER_USERNAME') }}" class="form-control"
                                                placeholder="{{ translate('6valley-admin-demo-jhisdfhisufjifjfijqw5467') }}"
                                                readonly
                                                data-bs-toggle="tooltip"
                                                data-bs-placement="top"
                                                aria-label="{{ translate('this_field_is_read_only_mode.') }}"
                                                data-bs-title="{{ translate('this_field_is_read_only_mode.') }}"
                                                >
                                        </div>
                                    </div>
                                    <div class="col-xl-4 col-md-6">
                                        <div class="form-group">
                                            <label class="form-label text-capitalize" for="">{{ translate('purchase_code') }}</label>
                                            <div class="input-icons">
                                                <input type="password" value="{{ env('PURCHASE_CODE') }}" class="form-control"
                                                    id="purchase_code" placeholder="{{ translate('00000000000000') }}"
                                                    readonly
                                                    data-bs-toggle="tooltip"
                                                    data-bs-placement="top"
                                                    aria-label="{{ translate('this_field_is_read_only_mode.') }}"
                                                    data-bs-title="{{ translate('this_field_is_read_only_mode.') }}"
                                                    >
                                            </div>
                                        </div>
                                    </div>

                                </div>

                            </div>
                    </div>
                </div>
                <div class="d-flex justify-content-end trans3 mt-4 action-btn-wrapper-container">
                    <div class="d-flex justify-content-sm-end justify-content-center gap-3 flex-grow-1 flex-grow-sm-0 bg-white action-btn-wrapper trans3">
                        <button type="reset" class="btn btn-secondary px-3 px-sm-4 w-120">{{ translate('reset') }}</button>
                        <button type="{{config('app.mode')!='demo'?'submit':'button'}}" class="btn btn-primary px-3 px-sm-4 {{config('app.mode')!='demo'?'':'call-demo-alert'}}">
                            <i class="fi fi-sr-disk"></i>
                            {{ translate('save_information') }}
                        </button>
                    </div>
                </div>
            </form>

            @if((config('app.mode') != 'demo' && config('app.mode') == 'dev'))
                <div class="row d-none">
                    <div class="col-md-4">
                        <div class="card">
                            <div class="border-bottom px-4 py-3">
                                <h5 class="mb-0 text-capitalize d-flex align-items-center gap-2">
                                    <img width="20"
                                         src="{{dynamicAsset(path: 'public/assets/back-end/img/environment.png') }}" alt="">
                                    {{ translate('Force_HTTPS') }}
                                </h5>
                            </div>
                            <div class="card-body">
                                <form action="{{route('admin.system-setup.environment-https-setup') }}"
                                      method="post"
                                      enctype="multipart/form-data">
                                    @csrf
                                    <div class="form-group mb-3">
                                        <label class="form-label" for="">{{ translate('Force_HTTPS') }}</label>
                                        <select name="force_https" class="custom-select"
                                                data-placeholder="Select from dropdown">
                                            <option></option>
                                            <option value="true" {{ env('FORCE_HTTPS') ? 'selected' : '' }}>
                                                {{ translate('true') }}
                                            </option>
                                            <option value="false" {{ !env('APP_DEBUG') ? 'selected' : '' }}>
                                                {{ translate('false') }}
                                            </option>
                                        </select>
                                    </div>
                                    <div class="d-flex justify-content-end flex-wrap gap-3">
                                        <button type="submit" class="btn btn-primary px-5">
                                            {{ translate('Update') }}
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="border-bottom px-4 py-3">
                                <h5 class="mb-0 text-capitalize d-flex align-items-center gap-2">
                                    <img width="20"
                                         src="{{dynamicAsset(path: 'public/assets/back-end/img/environment.png') }}" alt="">
                                    {{ translate('Optimize_System') }}
                                </h5>
                            </div>
                            <div class="card-body">
                                <form action="{{ route('admin.system-setup.optimize-system') }}"
                                      method="post"
                                      enctype="multipart/form-data">
                                    @csrf

                                    <div class="form-group mb-3">
                                        <label class="form-label" for="">{{ translate('Optimize_Type') }}</label>
                                        <select name="optimize_type" class="custom-select"
                                                data-placeholder="Select from dropdown">
                                            <option></option>
                                            <option value="cache">{{ translate('Clear_All_Cache') }}</option>
                                            <option value="migrate">{{ translate('Migrate_Database') }}</option>
                                            <option value="update">{{ translate('Update_Database') }}</option>
                                        </select>
                                    </div>
                                    <div class="d-flex justify-content-end flex-wrap gap-3">
                                        <button type="submit" class="btn btn-primary px-5">
                                            {{ translate('Update') }}
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card">
                            <div class="border-bottom px-4 py-3">
                                <h5 class="mb-0 text-capitalize d-flex align-items-center gap-2">
                                    <img width="20"
                                         src="{{dynamicAsset(path: 'public/assets/back-end/img/environment.png') }}" alt="">
                                    {{ translate('Install_Passport') }}
                                </h5>
                            </div>
                            <div class="card-body">
                                <form action="{{route('admin.system-setup.install-passport') }}"
                                      method="post"
                                      enctype="multipart/form-data">
                                    @csrf
                                    <div class="form-group mb-3">
                                        <label class="form-label" for="">{{ translate('Install') }}</label>
                                        <select name="status" class="custom-select"
                                                data-placeholder="Select from dropdown">
                                            <option></option>
                                            <option value="yes">{{ translate('Yes') }}</option>
                                        </select>
                                    </div>
                                    <div class="d-flex justify-content-end flex-wrap gap-3">
                                        <button type="submit" class="btn btn-primary px-5">
                                            {{ translate('Update') }}
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
    @include("layouts.admin.partials.offcanvas._environment-setup")
@endsection

@push('script')
    <script>
        'use strict';
        var swiper = new Swiper(".mySwiper", {
            navigation: {
                nextEl: ".swiper-button-next1",
                prevEl: ".swiper-button-prev1",
            },
            pagination: {
                el: ".swiper-pagination1",
            },
        });

        var swiper2 = new Swiper(".mySwiper2", {
            navigation: {
                nextEl: ".swiper-button-next2",
                prevEl: ".swiper-button-prev2",
            },
            pagination: {
                el: ".swiper-pagination2",
                type: "fraction",
            },
        });
    </script>
@endpush
