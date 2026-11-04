@extends('layouts.admin.app')

@section('title', translate('app_deep_link_setup'))

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
                            {{ translate('on_this_page_you_can_configure_app_deep_links_and_store_redirect_settings_for_android_and_ios.') }}
                            {{ translate('please_provide_correct_app_identifiers_and_store_links_for_proper_redirection.') }}
                        </span>
                    </div>
                    <ul class="m-0 ps-20 d-flex flex-column gap-1 text-body">
                        <li>
                            {{ translate('if_the_app_is_not_installed_users_will_be_redirected_to_the_respective_app_store.') }}
                        </li>
                        <li>
                            {{ translate('this_setup_ensures_smooth_app_opening_experience_through_links_on_both_platforms.') }}
                        </li>
                    </ul>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3>{{ translate('customer_app_deep_link_settings') }}</h3>
                    <p class="mb-0 fs-12">
                        {{ translate('configure_your_android_and_ios_app_identifiers_and_store_redirect_urls_here') }}
                    </p>
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.system-setup.app-deep-link-store') }}" method="post"
                          class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate"
                          novalidate="novalidate">
                        @csrf
                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <img width="24"
                                         src="{{dynamicAsset(path: 'public/assets/back-end/img/play_store.png')}}"
                                         alt="">
                                    <h3 class="mb-0 text-capitalize">{{translate('for_android')}}</h3>
                                </div>
                                <input type="hidden" name="type" value="app_deep_link">
                                <div class="bg-section rounded p-12 p-sm-20">
                                    <div class="form-group">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <label class="form-label mb-0 text-capitalize"
                                                   for="">{{ translate('android_package_name_for') }}
                                                ({{ translate('android') }})
                                                <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                      data-bs-placement="right"
                                                      aria-label="{{ translate('enter_your_android_app_package_name_to_identify_and_open_the_correct_app_via_deep_links') }}"
                                                      data-bs-title="{{ translate('enter_your_android_app_package_name_to_identify_and_open_the_correct_app_via_deep_links') }}">
                                                        <i class="fi fi-sr-info"></i>
                                                    </span>
                                            </label>
                                        </div>
                                        <input type="text" class="form-control" name="android_package_name"
                                               placeholder="{{ translate('ex').' : '.'com.example.app' }}"
                                               data-required-msg="{{ translate('android_package_name_is_required') }}"
                                               required
                                               value="{{ showDemoModeInputValue(value: $deeplink['android_package_name'] ?? '') }}">
                                    </div>
                                    <div class="form-group">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <label class="form-label mb-0 text-capitalize"
                                                   for="">{{ translate('android_sha256_fingerprint_for_user_app') }}
                                                ({{ translate('Android') }})
                                                <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                      data-bs-placement="right"
                                                      aria-label="{{ translate('provide_the_android_app_sha256_fingerprint_to_verify_and_secure_deep_link_access') }}"
                                                      data-bs-title="{{ translate('provide_the_android_app_sha256_fingerprint_to_verify_and_secure_deep_link_access') }}">
                                                        <i class="fi fi-sr-info"></i>
                                                </span>
                                            </label>
                                        </div>
                                        <input type="text" class="form-control" name="android_sha256_fingerprint"
                                               placeholder="{{ translate('ex').' : '.'AA:BB:CC:DD:EE:FF:11:22:33:44:55:66:77:88:99:AA' }}"
                                               data-required-msg="{{ translate('android_sha256_fingerprint_is_required') }}"
                                               required
                                               value="{{ showDemoModeInputValue(value: $deeplink['android_sha256_fingerprint'] ?? '') }}">
                                    </div>
                                    <div class="">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <label class="form-label mb-0 text-capitalize"
                                                   for="">{{ translate('play_store_redirect_url') }}
                                                ({{ translate('Android') }})
                                                <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                      data-bs-placement="right"
                                                      aria-label="{{ translate('add_your_google_play_store_link_to_redirect_users_when_the_app_is_not_installed_or_needs_update') }}"
                                                      data-bs-title="{{ translate('add_your_google_play_store_link_to_redirect_users_when_the_app_is_not_installed_or_needs_update') }}">
                                                        <i class="fi fi-sr-info"></i>
                                                </span>
                                            </label>
                                        </div>
                                        <input type="text" class="form-control" name="playstore_redirect_url"
                                               placeholder="{{ translate('ex').' : '.'https://play.google.com/store/apps/details?id=com.example.app' }}"
                                               data-required-msg="{{ translate('play_store_redirect_url') }}" required
                                               value="{{ showDemoModeInputValue(value: $deeplink['playstore_redirect_url'] ?? '') }}">
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <img width="24" src="{{dynamicAsset(path: 'public/assets/back-end/img/apple.png')}}"
                                         alt="">
                                    <h3 class="mb-0 text-capitalize">{{translate('for_iOS')}}</h3>
                                </div>
                                <div class="bg-section rounded p-12 p-sm-20">
                                    <div class="form-group">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <label class="form-label mb-0" for="">
                                                <span class=" text-capitalize">
                                                    {{ translate('iOS_bundle_id_for') }}
                                                </span> ({{ 'iOS' }})
                                                <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                      data-bs-placement="right"
                                                      aria-label="{{ translate('enter_your_ios_app_bundle_id_to_allow_deep_links_to_open_the_app_correctly_on_ios_devices') }}"
                                                      data-bs-title="{{ translate('enter_your_ios_app_bundle_id_to_allow_deep_links_to_open_the_app_correctly_on_ios_devices') }}">
                                                        <i class="fi fi-sr-info"></i>
                                                </span>
                                            </label>
                                        </div>
                                        <input type="text" class="form-control" name="ios_bundle_id"
                                               placeholder="{{ translate('ex').' : '.'com.example.iosapp' }}"
                                               data-required-msg="{{ translate('ios_bundle_id_is_required') }}" required
                                               value="{{ showDemoModeInputValue(value: $deeplink['ios_bundle_id'] ?? '') }}">
                                    </div>

                                    <div class="form-group">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <label class="form-label mb-0" for="">
                                                <span class="text-capitalize">
                                                {{ translate('iOS_team_id_for') }}
                                                </span> ({{ 'iOS' }})
                                                <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                      data-bs-placement="right"
                                                      aria-label="{{ translate('provide_your_apple_developer_team_id_to_verify_app_ownership_and_enable_ios_deep_linking') }}"
                                                      data-bs-title="{{ translate('provide_your_apple_developer_team_id_to_verify_app_ownership_and_enable_ios_deep_linking') }}">
                                                        <i class="fi fi-sr-info"></i>
                                                </span>
                                            </label>
                                        </div>
                                        <input type="text" class="form-control" name="ios_team_id"
                                               placeholder="{{ translate('ex').' : '.'ABCDE12345' }}"
                                               data-required-msg="{{ translate('ios_team_id_is_required') }}" required
                                               value="{{ showDemoModeInputValue(value: $deeplink['ios_team_id'] ?? '') }}">
                                    </div>
                                    <div class="">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <label class="form-label mb-0" for="">
                                                <span class="text-capitalize">
                                                    {{ translate('app_store_redirect_url') }}
                                                </span> ({{ 'iOS' }})
                                                <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                      data-bs-placement="right"
                                                      aria-label="{{ translate('add_your_apple_app_store_link_to_redirect_users_when_the_app_is_not_installed_or_requires_update') }}"
                                                      data-bs-title="{{ translate('add_your_apple_app_store_link_to_redirect_users_when_the_app_is_not_installed_or_requires_update') }}">
                                                        <i class="fi fi-sr-info"></i>
                                                </span>
                                            </label>
                                        </div>
                                        <input type="text" class="form-control" name="app_store_redirect_url"
                                               placeholder="{{ translate('ex').' : '.'https://apps.apple.com/app/id1234567890' }}"
                                               data-required-msg="{{ translate('app_store_redirect_url') }}" required
                                               value="{{ showDemoModeInputValue(value: $deeplink['app_store_redirect_url'] ?? '') }}">
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="d-flex flex-wrap justify-content-end gap-3">
                                    <button type="reset"
                                            class="btn btn-secondary px-3 px-sm-4 w-120">{{translate('reset')}}</button>
                                    <button type="{{ getDemoModeFormButton(type: 'button') }}"
                                            class="btn btn-primary px-3 px-sm-4 w-120 {{ getDemoModeFormButton(type: 'class') }}">
                                        {{translate('save')}}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            {{-- Which links open the app, and whether the phone can actually see that list.
                 The published file and the configured list drift apart the moment the list
                 changes in code, and nothing on this screen used to say so. --}}
            <div class="card">
                <div class="card-header">
                    <h3>{{ translate('links_that_open_the_app') }}</h3>
                    <p class="mb-0 fs-12">
                        {{ translate('a_tap_on_one_of_these_paths_opens_the_app_instead_of_the_browser_when_it_is_installed') }}
                    </p>
                </div>
                <div class="card-body d-flex flex-column gap-3">
                    @if ($publishedIsStale)
                        <div class="bg-danger bg-opacity-10 fs-12 px-12 py-10 text-dark rounded">
                            <div class="d-flex gap-2 align-items-center">
                                <i class="fi fi-sr-triangle-warning text-danger"></i>
                                <span>
                                    {{ translate('the_published_file_does_not_match_the_list_below_so_the_newer_paths_do_not_open_the_app_yet') }}
                                    — <code>php artisan deeplinks:publish</code>
                                </span>
                            </div>
                        </div>
                    @elseif (!$published['exists'])
                        <div class="bg-warning bg-opacity-10 fs-12 px-12 py-10 text-dark rounded">
                            <div class="d-flex gap-2 align-items-center">
                                <i class="fi fi-sr-info text-warning"></i>
                                <span>{{ translate('nothing_has_been_published_yet_save_this_form_to_write_the_association_files') }}</span>
                            </div>
                        </div>
                    @endif

                    <div class="row g-3">
                        <div class="col-md-6">
                            <h5 class="fs-14 mb-2">{{ translate('ios_universal_links') }}</h5>
                            <p class="fs-12 text-muted mb-2">
                                {{ translate('written_into_the_association_file_a_path_that_is_not_here_opens_safari') }}
                            </p>
                            <ul class="m-0 ps-20 fs-12 d-flex flex-column gap-1">
                                @foreach ($linkPaths['ios'] as $path)
                                    <li><code>{{ $path }}</code></li>
                                @endforeach
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h5 class="fs-14 mb-2">{{ translate('android_app_links') }}</h5>
                            <p class="fs-12 text-muted mb-2">
                                {{ translate('android_verifies_the_whole_domain_so_the_app_intent_filters_have_to_declare_these') }}
                            </p>
                            <ul class="m-0 ps-20 fs-12 d-flex flex-column gap-1">
                                @foreach ($linkPaths['android'] as $path)
                                    <li><code>{{ $path }}</code></li>
                                @endforeach
                            </ul>
                        </div>
                    </div>

                    <div class="bg-light fs-12 px-12 py-10 rounded d-flex flex-wrap gap-2 align-items-center">
                        <i class="fi fi-sr-link"></i>
                        <span>
                            {{ translate('campaign_short_links_are_on_this_list_so_a_poster_or_a_whatsapp_link_opens_the_app_and_still_counts_towards_its_campaign') }}
                        </span>
                        <a class="btn btn-sm btn-outline-primary ms-auto"
                           href="{{ route('admin.analytics.section', ['section' => 'campaigns']) }}">
                            {{ translate('campaigns') }}
                        </a>
                    </div>

                    <div class="fs-12 text-muted">
                        {{ translate('the_apps_read_the_same_list_over_the_api') }}:
                        <code>GET {{ rtrim(config('app.url'), '/') }}/api/v1/deep-link/config</code>
                    </div>
                </div>
            </div>
        </div>

    </div>
    @include("layouts.admin.partials.offcanvas._app-deep-link-setting")
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/vendor/swiper/swiper-bundle.min.js')}}"></script>
@endpush
