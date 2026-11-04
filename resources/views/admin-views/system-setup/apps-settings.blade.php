@extends('layouts.admin.app')

@section('title', translate('app_settings'))

@push('css_or_js')

@endpush

@section('content')

    <div class="content container-fluid">

        <div class="mb-3 mb-sm-20">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                {{ translate('system_Setup') }}
            </h2>
        </div>
        @include('admin-views.system-setup.system-settings-inline-menu')


        <div class="d-flex flex-column gap-3 gap-sm-20">
            <div>
                <div class="bg-warning bg-opacity-10 fs-12 px-12 py-10 text-dark rounded">
                    <div class="d-flex gap-2 align-items-center mb-2">
                        <i class="fi fi-sr-info text-warning"></i>
                        <span>
                            {{ translate('in_this_page_you_can_setup_latest_version_app_forcefully_activate_for_the_users') }}. {{ translate('please_input_proper_data_for_the_app_link_&_versions') }}.
                        </span>
                    </div>
                    <ul class="m-0 ps-20 d-flex flex-column gap-1 text-body">
                        <li>{{ translate('some_time_older_version_app_can_not_work_properly_and_crash_when_start_the_app') }}.</li>
                        <li>{{ translate('this_section_may_help_user_to_get_the_update_features_in_their_app') }}.</li>
                    </ul>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3>{{ translate('customer_app_version_control') }}</h3>
                    <p class="mb-0 fs-12">
                        {{ translate('here_you_setup_your_customer_app_version_and_app_download_url') }}
                    </p>
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.system-setup.app-settings') }}" method="post" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
                        @csrf
                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <img width="24" src="{{dynamicAsset(path: 'public/assets/back-end/img/play_store.png')}}" alt="">
                                    <h3 class="mb-0 text-capitalize">{{translate('for_android')}}</h3>
                                </div>
                                <input type="hidden" name="type" value="user_app_version_control">
                                <div class="bg-section rounded p-12 p-sm-20">

                                    {{-- The switch the data model always had and this form never
                                         offered: `status` was a hidden 1, so a merchant whose
                                         customers were being held at the update screen had no way
                                         to stop it but to edit the version number. The hidden 0
                                         below it is what an unticked checkbox posts, since a
                                         checkbox that is off sends nothing at all. --}}
                                    <div class="d-flex align-items-center justify-content-between gap-2 mb-20">
                                        <label class="form-label mb-0 text-capitalize" for="for_android-force-update">
                                            {{ translate('force_customers_to_update') }} ({{ translate('android') }})
                                        </label>
                                        <input type="hidden" name="for_android[status]" value="0">
                                        <label class="switcher" for="for_android-force-update">
                                            <input class="switcher_input" type="checkbox" value="1"
                                                   name="for_android[status]" id="for_android-force-update"
                                                   {{ ($userAppVersionControl['for_android']['status'] ?? 1) ? 'checked' : '' }}>
                                            <span class="switcher_control"></span>
                                        </label>
                                    </div>
                                    <div class="form-group">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <label class="form-label mb-0 text-capitalize" for="">{{ translate('minimum_user_app_version_for_force_update') }} ({{ translate('android') }})
                                                <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                      data-bs-placement="right"
                                                      aria-label="{{ translate('define_the_minimum_Android_app_version_for_best_user_experience.') .' '. translate('if_a_user_still_do_not_have_it,_they_will_be_requested_a_force_app_update_when_they_opens_the_app.') }}"
                                                      data-bs-title="{{ translate('define_the_minimum_Android_app_version_for_best_user_experience.') .' '. translate('if_a_user_still_do_not_have_it,_they_will_be_requested_a_force_app_update_when_they_opens_the_app.') }}">
                                                        <i class="fi fi-sr-info"></i>
                                                    </span>
                                            </label>
                                        </div>
                                        <input type="text" class="form-control" name="for_android[version]"
                                               placeholder="{{translate('ex').':'.'2.1'}}" data-required-msg="{{ translate('android_version_is_required') }}" required
                                               value="{{ $userAppVersionControl['for_android']['version'] ?? '' }}">
                                    </div>
                                    <div class="">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <label class="form-label mb-0 text-capitalize" for="">{{ translate('download_URL_for_user_app') }} ({{ translate('Android') }})
                                                <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                      data-bs-placement="right"
                                                      aria-label="{{ translate('add_the_Android_app_download_URL_that_will_redirect_users_when_they_agree_to_update_the_app.') }}"
                                                      data-bs-title="{{ translate('add_the_Android_app_download_URL_that_will_redirect_users_when_they_agree_to_update_the_app.') }}">
                                                        <i class="fi fi-sr-info"></i>
                                                </span>
                                            </label>
                                        </div>
                                        <input type="url" class="form-control" name="for_android[link]"
                                               placeholder="{{translate('ex').':'.'https://play.google.com/store/apps'}}" data-required-msg="{{ translate('download_URL_for_user_app_is_required') }}" required
                                               value="{{ $userAppVersionControl['for_android']['link'] ?? '' }}">
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <img width="24" src="{{dynamicAsset(path: 'public/assets/back-end/img/apple.png')}}" alt="">
                                    <h3 class="mb-0 text-capitalize">{{translate('for_iOS')}}</h3>
                                </div>
                                <div class="bg-section rounded p-12 p-sm-20">

                                    {{-- The switch the data model always had and this form never
                                         offered: `status` was a hidden 1, so a merchant whose
                                         customers were being held at the update screen had no way
                                         to stop it but to edit the version number. The hidden 0
                                         below it is what an unticked checkbox posts, since a
                                         checkbox that is off sends nothing at all. --}}
                                    <div class="d-flex align-items-center justify-content-between gap-2 mb-20">
                                        <label class="form-label mb-0 text-capitalize" for="for_ios-force-update">
                                            {{ translate('force_customers_to_update') }} ({{ translate('ios') }})
                                        </label>
                                        <input type="hidden" name="for_ios[status]" value="0">
                                        <label class="switcher" for="for_ios-force-update">
                                            <input class="switcher_input" type="checkbox" value="1"
                                                   name="for_ios[status]" id="for_ios-force-update"
                                                   {{ ($userAppVersionControl['for_ios']['status'] ?? 1) ? 'checked' : '' }}>
                                            <span class="switcher_control"></span>
                                        </label>
                                    </div>
                                    <div class="form-group">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <label class="form-label mb-0 text-capitalize" for="">
                                                {{ translate('minimum_user_app_version_for_force_update') }} ({{ translate('ios') }})
                                                <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                      data-bs-placement="right"
                                                      aria-label="{{ translate('define_the_minimum_iOS_app_version_for_best_user_experience.').' '. translate('if_a_user_still_do_not_have_it,_they_will_be_requested_a_force_app_update_when_they_opens_the_app.') }}"
                                                      data-bs-title="{{ translate('define_the_minimum_iOS_app_version_for_best_user_experience.').' '. translate('if_a_user_still_do_not_have_it,_they_will_be_requested_a_force_app_update_when_they_opens_the_app.') }}">
                                                        <i class="fi fi-sr-info"></i>
                                                </span>
                                            </label>
                                        </div>
                                        <input type="text" class="form-control" name="for_ios[version]"
                                               placeholder="{{translate('ex').':'.'2.1'}}" data-required-msg="{{ translate('ios_version_is_required') }}" required
                                               value="{{ $userAppVersionControl['for_ios']['version'] ?? '' }}">
                                    </div>

                                    <div class="">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <label class="form-label mb-0 text-capitalize" for="">
                                                {{ translate('download_URL_For_user_App') }} ({{ translate('ios') }})
                                                <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                      data-bs-placement="right"
                                                      aria-label="{{ translate('add_the_iOS_app_download_URL_that_will_redirect_users_when_they_agree_to_update_the_app.') }}"
                                                      data-bs-title="{{ translate('add_the_iOS_app_download_URL_that_will_redirect_users_when_they_agree_to_update_the_app.') }}">
                                                        <i class="fi fi-sr-info"></i>
                                                </span>
                                            </label>
                                        </div>
                                        <input type="url" class="form-control" name="for_ios[link]"
                                               placeholder="{{translate('ex').':'.'https://www.apple.com/app-store/'}}" data-required-msg="{{ translate('download_URL_For_user_App_ios_is_required') }}" required
                                               value="{{ $userAppVersionControl['for_ios']['link'] ?? '' }}">
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="d-flex flex-wrap justify-content-end gap-3">
                                    <button type="reset" class="btn btn-secondary px-3 px-sm-4 w-120">{{translate('reset')}}</button>
                                    <button type="submit" class="btn btn-primary px-3 px-sm-4 w-120">{{translate('save')}}</button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3>{{ translate('vendor_app_version_control') }}</h3>
                    <p class="mb-0 fs-12">
                        {{ translate('here_you_setup_your_vendor_app_version_&_app_download_url') }}
                    </p>
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.system-setup.app-settings') }}" method="post" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
                        @csrf
                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <img width="24" src="{{dynamicAsset(path: 'public/assets/back-end/img/play_store.png')}}" alt="">
                                    <h3 class="mb-0 text-capitalize">{{translate('for_android')}}</h3>
                                </div>

                                <input type="hidden" name="type" value="seller_app_version_control">

                                <div class="bg-section rounded p-12 p-sm-20">
                                    <div class="form-group">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <label class="form-label mb-0 text-capitalize" for="">{{ translate('minimum_Vendor_app_version') }} ({{ translate('android') }})
                                                <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                      data-bs-placement="right"
                                                      aria-label="{{translate('define_the_minimum_Android_app_version_for_best_user_experience').'.'.translate('if_a_user_still_do_not_have_it,_they_will_be_requested_a_force_app_update_when_they_opens_the_app').'.'}}"
                                                      data-bs-title="{{translate('define_the_minimum_Android_app_version_for_best_user_experience').'.'.translate('if_a_user_still_do_not_have_it,_they_will_be_requested_a_force_app_update_when_they_opens_the_app').'.'}}">
                                                        <i class="fi fi-sr-info"></i>
                                                    </span>
                                            </label>
                                        </div>
                                        <input type="hidden" name="for_android[status]" value="1">
                                        <input type="text" class="form-control" name="for_android[version]"
                                               placeholder="{{translate('ex: 2.1')}}" data-required-msg="{{ translate('Vendor_app_version_android_is_required') }}" required
                                               value="{{ $sellerAppVersionControl['for_android']['version'] ?? '' }}">
                                    </div>

                                    <div class="">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <label class="form-label mb-0 text-capitalize" for="">{{ translate('download_URL_For_Vendor_App') }}({{ translate('android') }})
                                                <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                      data-bs-placement="right"
                                                      aria-label="{{ translate('add_the_Android_app_download_URL_that_will_redirect_users_when_they_agree_to_update_the_app').'.' }}"
                                                      data-bs-title="{{ translate('add_the_Android_app_download_URL_that_will_redirect_users_when_they_agree_to_update_the_app').'.' }}">
                                                        <i class="fi fi-sr-info"></i>
                                                    </span>
                                            </label>
                                        </div>
                                        <input type="url" class="form-control" name="for_android[link]"
                                               placeholder="{{translate('ex').'https://play.google.com/store/apps'}}"
                                               data-required-msg="{{ translate('download_URL_For_Vendor_App_android_is_required') }}"
                                               required
                                               value="{{ $sellerAppVersionControl['for_android']['link'] ?? '' }}">
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <img width="24" src="{{dynamicAsset(path: 'public/assets/back-end/img/apple.png')}}" alt="">
                                    <h3 class="mb-0 text-capitalize">{{translate('for_iOS')}}</h3>
                                </div>
                                <div class="bg-section rounded p-12 p-sm-20">
                                    <div class="form-group">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <label class="form-label mb-0" for="">{{ translate('minimum_Vendor_app_version') }} ({{ translate('ios') }})
                                                <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                      data-bs-placement="right"
                                                      aria-label="{{ translate('define_the_minimum_iOS_app_version_for_best_user_experience').'.'. translate('if_a_user_still_do_not_have_it,_they_will_be_requested_a_force_app_update_when_they_opens_the_app').'.' }}"
                                                      data-bs-title="{{ translate('define_the_minimum_iOS_app_version_for_best_user_experience').'.'. translate('if_a_user_still_do_not_have_it,_they_will_be_requested_a_force_app_update_when_they_opens_the_app').'.' }}">
                                                        <i class="fi fi-sr-info"></i>
                                                    </span>
                                            </label>
                                        </div>
                                        <input type="hidden" name="for_ios[status]" value="1">
                                        <input type="text" class="form-control" name="for_ios[version]"
                                               placeholder="{{translate('ex').':'.'2.1'}}" data-required-msg="{{ translate('minimum_Vendor_app_version_ios_is_required') }}"  required
                                               value="{{ $sellerAppVersionControl['for_ios']['version'] ?? '' }}">
                                    </div>

                                    <div class="">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <label class="form-label mb-0" for="">{{ translate('download_URL_For_Vendor_App') }} ({{ translate('ios') }})
                                                <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                      data-bs-placement="right"
                                                      aria-label="{{ translate('add_the_iOS_app_download_URL_that_will_redirect_users_when_they_agree_to_update_the_app').'.'}}"
                                                      data-bs-title="{{ translate('add_the_iOS_app_download_URL_that_will_redirect_users_when_they_agree_to_update_the_app').'.'}}">
                                                        <i class="fi fi-sr-info"></i>
                                                    </span>
                                            </label>
                                        </div>
                                        <input type="url" class="form-control" name="for_ios[link]"
                                               placeholder="{{translate('ex').':'.' https://www.apple.com/app-store/'}}" data-required-msg="{{ translate('download_URL_For_Vendor_App_ios_is_required') }}" required
                                               value="{{ $sellerAppVersionControl['for_ios']['link'] ?? '' }}">
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="d-flex flex-wrap justify-content-end gap-3">
                                    <button type="reset" class="btn btn-secondary px-3 px-sm-4 w-120">{{translate('reset')}}</button>
                                    <button type="submit" class="btn btn-primary px-3 px-sm-4 w-120">{{translate('save')}}</button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3>{{ translate('delivery_man_app_version_control') }}</h3>
                    <p class="mb-0 fs-12">
                        {{ translate('here_you_setup_your_delivery_man_app_version_&_app_download_url&_app_download_url') }}
                    </p>
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.system-setup.app-settings') }}" method="post" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate" >
                        @csrf
                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <img width="24" src="{{dynamicAsset(path: 'public/assets/back-end/img/play_store.png')}}" alt="">
                                    <h3 class="mb-0 text-capitalize">{{translate('for_android')}}</h3>
                                </div>
                                <input type="hidden" name="type" value="delivery_man_app_version_control">
                                <div class="bg-section rounded p-12 p-sm-20">
                                    <div class="form-group">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <label class="form-label mb-0" for="">{{ translate('minimum_Deliveryman_App_Version') }} ({{ translate('android') }})
                                                <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                      data-bs-placement="right"
                                                      aria-label="{{ translate('define_the_minimum_Android_app_version_for_best_user_experience').'.'.translate('if_a_user_still_do_not_have_it,_they_will_be_requested_a_force_app_update_when_they_opens_the_app').'.' }}"
                                                      data-bs-title="{{ translate('define_the_minimum_Android_app_version_for_best_user_experience').'.'.translate('if_a_user_still_do_not_have_it,_they_will_be_requested_a_force_app_update_when_they_opens_the_app').'.'}}">
                                                        <i class="fi fi-sr-info"></i>
                                                    </span>
                                            </label>
                                        </div>
                                        <input type="hidden" name="for_android[status]" value="1">
                                        <input type="text" class="form-control" name="for_android[version]"
                                               placeholder="{{translate('ex').':'.'2.1'}}" data-required-msg="{{ translate('minimum_Deliveryman_android_version_is_required') }}" required
                                               value="{{ $deliverymanAppVersionControl['for_android']['version'] ?? '' }}">
                                    </div>
                                    <div class="">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <label class="form-label mb-0" for="">{{ translate('download_URL_For_Deliveryman_App') }} ({{ translate('android') }})
                                                <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                      data-bs-placement="right"
                                                      aria-label="{{translate('add_the_Android_app_download_URL_that_will_redirect_users_when_they_agree_to_update_the_app').'.' }}"
                                                      data-bs-title="{{translate('add_the_Android_app_download_URL_that_will_redirect_users_when_they_agree_to_update_the_app').'.' }}">
                                                        <i class="fi fi-sr-info"></i>
                                                    </span>
                                            </label>
                                        </div>
                                        <input type="url" class="form-control" name="for_android[link]"
                                               placeholder="{{translate('ex').':'.'https://play.google.com/store/apps'}}" data-required-msg="{{ translate('deliveryman_app_download_url_android_version_is_required') }}" required
                                               value="{{ $deliverymanAppVersionControl['for_android']['link'] ?? '' }}">
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <img width="24" src="{{dynamicAsset(path: 'public/assets/back-end/img/apple.png')}}" alt="">
                                    <h3 class="mb-0 text-capitalize">{{translate('for_iOS')}}</h3>
                                </div>
                                <div class="bg-section rounded p-12 p-sm-20">
                                    <div class="form-group">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <label class="form-label mb-0 text-capitalize" for="">{{ translate('minimum_Deliveryman_App_Version') }} ({{ translate('ios') }})
                                                <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                      data-bs-placement="right"
                                                      aria-label="{{translate('define_the_minimum_iOS_app_version_for_best_user_experience').'.'. translate('if_a_user_still_do_not_have_it,_they_will_be_requested_a_force_app_update_when_they_opens_the_app').'.'}}"
                                                      data-bs-title="{{translate('define_the_minimum_iOS_app_version_for_best_user_experience').'.'. translate('if_a_user_still_do_not_have_it,_they_will_be_requested_a_force_app_update_when_they_opens_the_app').'.'}}">
                                                        <i class="fi fi-sr-info"></i>
                                                    </span>
                                            </label>
                                        </div>
                                        <input type="hidden" name="for_android[status]" value="1">
                                        <input type="text" class="form-control" name="for_ios[version]"
                                               placeholder="{{translate('ex').':'.'2.1'}}"
                                               data-required-msg="{{ translate('minimum_Deliveryman_App_ios_version_is_required') }}"
                                               required
                                               value="{{ $deliverymanAppVersionControl['for_ios']['version'] ?? '' }}">
                                    </div>

                                    <div class="">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <label class="form-label mb-0 text-capitalize" for="">{{ translate('download_URL_For_Deliveryman_App') }} ({{ translate('ios') }})
                                                <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                      data-bs-placement="right"
                                                      aria-label="{{translate('add_the_iOS_app_download_URL_that_will_redirect_users_when_they_agree_to_update_the_app').'.'}}"
                                                      data-bs-title="{{translate('add_the_iOS_app_download_URL_that_will_redirect_users_when_they_agree_to_update_the_app').'.'}}">
                                                        <i class="fi fi-sr-info"></i>
                                                    </span>
                                            </label>
                                        </div>
                                        <input type="url" class="form-control" name="for_ios[link]"
                                               placeholder="{{translate('ex').':'.'https://www.apple.com/app-store/'}}" data-required-msg="{{ translate('download_URL_For_Deliveryman_App_ios_version_is_required') }}" required
                                               value="{{ $deliverymanAppVersionControl['for_ios']['link'] ?? '' }}">
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="d-flex flex-wrap justify-content-end gap-3">
                                    <button type="reset" class="btn btn-secondary px-3 px-sm-4 w-120">{{translate('reset')}}</button>
                                    <button type="submit" class="btn btn-primary px-3 px-sm-4 w-120">{{translate('save')}}</button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

        </div>

    </div>
    <div class="modal fade" id="readInstructionsModal" tabindex="-1" aria-labelledby="readInstructionsModal"
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
                                         src="{{dynamicAsset(path: 'public/assets/back-end/img/what_app_version.png')}}"
                                         loading="lazy" alt="">
                                    <h4 class="lh-md mb-3 text-capitalize">{{ translate('what_is_app_version').'?' }}</h4>
                                    <ul class="d-flex flex-column px-4 gap-2 mb-4">
                                        <li>{{ translate('this_app_version_means_the_minimum_version_of_Vendor_Deliveryman_and_Customer_apps_that_are_required_for_the_update') }}</li>
                                        <li>{{ translate('it_does_not_represent_the_Play_Store_or_App_Store_version') }}</li>
                                    </ul>
                                </div>
                            </div>
                            <div class="swiper-slide">
                                <div class="d-flex flex-column align-items-center gap-2">
                                    <img width="80" class="mb-3"
                                         src="{{dynamicAsset(path: 'public/assets/back-end/img/what_app_version.png')}}"
                                         loading="lazy" alt="">
                                    <h4 class="lh-md mb-3 text-capitalize">{{ translate('app_download_link') }}</h4>
                                    <ul class="d-flex flex-column px-4 gap-2 mb-4">
                                        <li>{{ translate('the_app_download_link_is_the_URL_that_allows_users_to_update_the_app_by_clicking_the_Update_App_button_within_the_app_itself') }} </li>
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
    @include("layouts.admin.partials.offcanvas._app-settings")
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/vendor/swiper/swiper-bundle.min.js')}}"></script>
@endpush
