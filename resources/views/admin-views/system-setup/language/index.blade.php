@php
    use Illuminate\Support\Facades\File;
@endphp

@extends('layouts.admin.app')

@section('title', translate('language'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-4 pb-2">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img src="{{dynamicAsset(path: 'public/assets/back-end/img/system-setting.png') }}" alt="">
                {{ translate('system_setup') }}
            </h2>
        </div>

        @include('admin-views.system-setup.system-settings-inline-menu')

        <div class="d-flex flex-column gap-20 mb-20">
            <div>
                <div
                    class="bg-warning bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                    <i class="fi fi-sr-info text-warning"></i>
                    <span>
                        {{ translate('changing_some_settings_will_take_time_to_show_effect_please_clear_session_or_wait_for_60_minutes_else_browse_from_incognito_mode') }}
                    </span>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <div class="mb-3 mb-sm-20">
                    <h3>{{ translate('Add_New_Language') }}</h3>
                    <p class="mb-0 fs-12">
                        {{ translate('set_up_new_languages_in_your_system.') . ' ' . translate('to_make_the_order_from_versatile_customers_on_your_website_and_apps.') }}
                    </p>
                </div>

                <form action="{{ route('admin.system-setup.language.add-new') }}" method="post" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
                    @csrf
                    <div class="mb-3 mb-sm-20">
                        <div class="p-12 p-sm-20 bg-section rounded">
                            <div class="row g-4">
                                <div class="col-xl-4 col-md-6">
                                    <div class="form-group">
                                        <label class="form-label" for="">
                                            {{ translate('Language') }}
                                        </label>
                                        <input type="text" name="name" class="form-control"
                                               data-required-msg="{{ translate('Language_field_is_required') }}"
                                               placeholder="{{ translate('language_name') }}" required>
                                        <input name="type" value="add" hidden>
                                    </div>
                                </div>

                                <div class="col-xl-4 col-md-6">
                                    <div class="form-group">
                                        <label class="form-label" for="">
                                            {{ translate('country_code') }}
                                        </label>
                                        <select name="code" class="custom-select image-var-select"
                                                data-placeholder="Select from dropdown" data-required-msg="{{ translate('country_code_is_required') }}" required>
                                            <option></option>
                                            @foreach($languageLocaleList as $languageLocale)
                                                @if($languageLocale['locale'] !='en')
                                                    <option value="{{ strtolower($languageLocale['locale']) }}"
                                                        data-image-url="{{ $languageLocale['flag'] }}">
                                                        {{ ucwords($languageLocale['name']) }}
                                                        @if(isset($languageLocale['script']))
                                                            - {{ $languageLocale['script'] }}
                                                        @endif
                                                    </option>
                                                @endif
                                            @endforeach
                                        </select>
                                    </div>
                                </div>

                                <div class="col-xl-4 col-md-6">
                                    <div class="form-group">
                                        <label class="form-label" for="">
                                            {{ translate('Direction') }}
                                        </label>
                                        <div
                                            class="min-h-40 d-flex align-items-center gap-3 gap-lg-5 border rounded mb-2 px-3 py-1 bg-white">
                                            <div class="form-check d-flex gap-1">
                                                <input class="form-check-input radio--input" type="radio"
                                                       name="direction" id="direction_left"
                                                       value="ltr" checked>
                                                <label class="form-check-label" for="direction_left">
                                                    {{ translate('Left_to_Right') }}
                                                </label>
                                            </div>
                                            <div class="form-check d-flex gap-1">
                                                <input class="form-check-input radio--input" type="radio"
                                                       name="direction" id="direction_right"
                                                       value="rtl">
                                                <label class="form-check-label" for="direction_right">
                                                    {{ translate('Right_to_Left') }}
                                                </label>

                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end flex-wrap gap-3 mt-4">
                        <button type="reset"
                                class="btn btn-secondary px-3 px-sm-4 w-120">{{ translate('reset') }}</button>
                        <button type="submit" class="btn btn-primary px-3 px-sm-4">
                            {{ translate('submit') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
                    <h3 class="mb-0">
                        {{ translate('language_list') }}
                    </h3>

                    <div class="d-flex gap-10 justify-content-sm-end">
                        <form action="{{ route('admin.system-setup.language.index') }}" method="get">
                            <div class="form-group">
                                <div class="input-group">
                                    <input type="search" class="form-control" name="search"
                                           value="{{ requestString('search') }}"
                                           placeholder="{{ translate('Search_Language') }}">
                                    <div class="input-group-append search-submit">
                                        <button type="submit">
                                            <i class="fi fi-rr-search"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="table-responsive pb-3">
                    <table
                        class="k-table">
                        <thead>
                        <tr>
                            <th>{{ translate('SL') }}</th>
                            <th>{{ translate('language') }}</th>
                            <th>{{ translate('code') }}</th>
                            <th class="text-center">{{ translate('status') }}</th>
                            <th class="text-center">{{ translate('action') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($languageList as $key => $language)
                            <tr>
                                <td>{{ $key+1 }}</td>
                                <td>
                                    <div class="d-flex align-items-center gap-2 text-capitalize">
                                        {{ $language['name'] }} ({{ $language['direction'] ?? 'ltr' }})
                                        @if (array_key_exists('default', $language) && $language['default'])
                                            <span class="badge text-bg-info badge-info badge-sm">
                                                {{ translate('default') }}
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td>{{ $language['code'] }}</td>
                                <td>
                                    @if (array_key_exists('default', $language) && $language['default'])
                                        <label class="switcher mx-auto" id="default-language-status-alert"
                                               data-text="{{ translate('default_language_can_not_be_deactivate').'!' }}">
                                            <input type="checkbox" class="switcher_input" checked disabled>
                                            <span class="switcher_control"></span>
                                        </label>
                                    @else
                                        <form action="{{ route('admin.system-setup.language.update-status') }}"
                                              method="post" id="language-id-{{ $language['id'] }}-form" class="no-reload-form">
                                            @csrf
                                            <input type="hidden" name="code" value="{{ $language['code'] }}">
                                            <label class="switcher mx-auto" for="language-status-{{ $language['id'] }}">
                                                <input
                                                    class="switcher_input custom-modal-plugin"
                                                    type="checkbox" value="1" name="status"
                                                    id="language-status-{{ $language['id'] }}"
                                                    {{ $language['status'] == 1 ? 'checked' : ' '}}
                                                    data-modal-type="input-change-form"
                                                    data-modal-form="#language-id-{{ $language['id'] }}-form"
                                                    data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/language-on.png') }}"
                                                    data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/language-off.png') }}"
                                                    data-on-title="{{ translate('want_to_Turn_ON_Language_Status').'?'}}"
                                                    data-off-title="{{ translate('want_to_Turn_OFF_Language_Status').'?'}}"
                                                    data-on-message="<p>{{ translate('if_enabled_this_language_will_be_available_throughout_the_entire_system') }}</p>"
                                                    data-off-message="<p>{{ translate('if_disabled_this_language_will_be_hidden_from_the_entire_system') }}</p>"
                                                    data-on-button-text="{{ translate('turn_on') }}"
                                                    data-off-button-text="{{ translate('turn_off') }}">
                                                <span class="switcher_control"></span>
                                            </label>
                                        </form>
                                    @endif
                                </td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-3">
                                        <a class="btn btn-outline-primary btn-xs"
                                           href="{{ route('admin.system-setup.language.translate', ['lang' => $language['code']]) }}">
                                            <i class="fi fi-sr-language-exchange"></i>
                                            {{ translate('View') }}
                                        </a>
                                        <div class="dropdown">
                                            <button class="btn btn-outline-primary btn-outline-primary-dark icon-btn"
                                                    data-bs-toggle="dropdown">
                                                <i class="fi fi-sr-menu-dots-vertical"></i>
                                            </button>

                                            <div class="dropdown-menu">

                                                <button class="dropdown-item d-flex align-items-center gap-2 {{ (array_key_exists('default', $language) && $language['default'] === true) ? "mark-as-default-language-alert opacity-50" : "" }}"
                                                        @if(!(array_key_exists('default', $language) && $language['default'] === true))
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#languageDefaultModal{{ $language['id'] }}"
                                                        @endif
                                                >
                                                    <i class="fi fi-rr-check-circle mt-1"></i>
                                                    {{ translate('Mark_As_Default') }}
                                                </button>

                                                @if($language['code'] != 'en')
                                                    <a class="dropdown-item d-flex align-items-center gap-2 pointer"
                                                       data-bs-toggle="offcanvas"
                                                       data-bs-target="#languageEditModal{{ $language['id'] }}">
                                                        <i class="fi fi-rr-pen-circle mt-1"></i>
                                                        {{ translate('edit') }}
                                                    </a>
                                                @endif

                                                @if ($language['code'] == 'en' || $language['default'] === true)
                                                    <button class="dropdown-item d-flex align-items-center gap-2 default-language-delete-alert opacity-50" data-code="{{ $language['code'] }}">
                                                        <i class="fi fi-rr-trash mt-1"></i>
                                                        {{ translate('delete') }}
                                                    </button>
                                                @else
                                                    <button class="dropdown-item d-flex align-items-center gap-2"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#languageDeleteModal{{ $language['id'] }}">
                                                        <i class="fi fi-rr-trash mt-1"></i>
                                                        {{ translate('delete') }}
                                                    </button>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    @if(count($languageList) == 0)
                        @include('layouts.admin.partials._empty-state', ['text' => 'no_language_found'], ['image' => 'default'])
                    @endif
                </div>
            </div>
        </div>

        @include("admin-views.system-setup.language._language-offcanvas")

        @foreach($languageList as $key => $language)
            <form action="{{ route('admin.system-setup.language.update-default-status') }}" method="post">
                @csrf
                <input type="hidden" name="code" value="{{ $language['code'] }}">
                <div class="modal fade" id="languageDefaultModal{{ $language['id'] }}" tabindex="-1"
                     aria-labelledby="languageDefaultModal{{ $language['id'] }}" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                                <button type="button"
                                        class="btn-close border-0 btn-circle bg-section2 shadow-none"
                                        data-bs-dismiss="modal" aria-label="Close">
                                </button>
                            </div>
                            <div class="modal-body px-20 py-0 mb-30">
                                <div class="d-flex flex-column align-items-center text-center mb-30">
                                    <img
                                        src="{{ dynamicAsset(path: 'public/assets/back-end/img/modal/general-icon.png') }}"
                                        width="80" class="mb-20" id="" alt="">
                                    <h2 class="modal-title mb-3">
                                        {{ translate('want_to_change_default_language').' ?' }}
                                    </h2>
                                    <div class="text-center">
                                        {{ translate('are_you_sure_want_to_change_default_language').' ?' }}
                                    </div>
                                </div>
                                <div class="d-flex justify-content-center gap-3">
                                    <button type="button" class="btn btn-secondary max-w-120 flex-grow-1"
                                            data-bs-dismiss="modal">
                                        {{ translate('No') }}
                                    </button>
                                    <button type="{{ getDemoModeFormButton(type: 'button') }}"
                                            class="btn btn-danger max-w-120 flex-grow-1 {{ getDemoModeFormButton(type: 'class') }}"
                                            data-bs-dismiss="modal">
                                        {{ translate('Yes') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

            <form action="{{ route('admin.system-setup.language.delete', [$language['code']]) }}" method="get">
                @csrf
                <input type="hidden" name="id" value="{{ $language['id'] }}">
                <div class="modal fade" id="languageDeleteModal{{ $language['id'] }}" tabindex="-1"
                     aria-labelledby="languageDeleteModal{{ $language['id'] }}" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                                <button type="button"
                                        class="btn-close border-0 btn-circle bg-section2 shadow-none"
                                        data-bs-dismiss="modal" aria-label="Close">
                                </button>
                            </div>
                            <div class="modal-body px-20 py-0 mb-30">
                                <div class="d-flex flex-column align-items-center text-center mb-30">
                                    <img
                                        src="{{dynamicAsset(path: 'public/assets/new/back-end/img/modal/delete.png')}}"
                                        width="80" class="mb-20" id="" alt="">
                                    <h2 class="modal-title mb-3">
                                        {{ translate('want_to_delete_this_language').' ?' }}
                                    </h2>
                                    <div class="text-center">
                                        {{ translate('are_you_sure_want_to_delete_this_language').' ?' }}
                                        {{ translate('it_will_be_permanently_deleted_from_your_database') }}
                                    </div>
                                </div>
                                <div class="d-flex justify-content-center gap-3">
                                    <button type="button" class="btn btn-secondary max-w-120 flex-grow-1"
                                            data-bs-dismiss="modal">
                                        {{ translate('No') }}
                                    </button>
                                    <button type="{{ getDemoModeFormButton(type: 'button') }}"
                                            class="btn btn-danger max-w-120 flex-grow-1 {{ getDemoModeFormButton(type: 'class') }}"
                                            data-bs-dismiss="modal">
                                        {{ translate('Yes_Delete') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        @endforeach
    </div>

    <span id="get-language-warning-message"
          data-title="{{ translate('Warning') }}!"
          data-message="{{ translate('the_default_language_cannot_be_deleted').' !' }} {{ translate('to_delete_it_you_must_first_change_the_default_language.') }}"
          data-english="{{ translate('the_english_language_cannot_be_deleted').' !' }} {{ translate('there_are_system_dependencies_so_you_cannot_delete_it.') }}">
    </span>

    <span id="get-mark-default-warning-message"
          data-title="{{ translate('Warning') }}!"
          data-message="{{ translate('this_language_is_already_set_as_the_default_language.') }}">
    </span>

    @include("layouts.admin.partials.offcanvas._language-setup")
@endsection

@push('script')
    <script src="{{dynamicAsset(path: 'public/assets/backend/admin/js/system-setup/system-setup.js') }}"></script>
@endpush
