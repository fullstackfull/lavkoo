@php
    use Illuminate\Support\Facades\Session;
@endphp
@extends('layouts.admin.app')

@section('title', translate('login_Url_Setup'))

@section('content')
@php($direction = Session::get('direction'))
<div class="content container-fluid">
    <div class="mb-3 mb-sm-20">
        <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
            {{ translate('Login_Url') }}
        </h2>
    </div>
    @include('admin-views.system-setup.login-settings.partials.login-settings-menu')

    <div class="d-flex flex-column gap-3">
        <div class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
            <i class="fi fi-sr-lightbulb-on text-info"></i>
            <span>
                {{ translate('set_up_the_urls_that_users_should_follow_to_access_the_login_pages_for_the_necessary_sites') }}.
            </span>
        </div>
        <form action="{{route('admin.system-setup.login-settings.login-url-setup')}}" method="post" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
            @csrf

            <div class="card">
                <div class="card-body">
                    <div class="mb-3 mb-sm-20">
                        <h2>{{ translate('Admin_Login_Page') }}</h2>
                        <p class="mb-0 fs-12">
                            {{ translate('access_the_admin_login_page_by_this_url') }}
                        </p>
                    </div>
                    <div class="p-12 p-sm-20 bg-section rounded">
                        <div class="d-flex flex-wrap gap-sm-20 gap-3 justify-content-end align-items-center">
                            <div class="flex-grow-1">
                                @php($adminLoginUrl = getWebConfig('admin_login_url'))
                                <div class="input-group border rounded trans3">
                                    <span class="input-group-text custom flex-grow-1 flex-xl-grow-0">{{ url('/').'/login/' }}</span>
                                    <input type="text" class="form-control" name="url" value="{{ $adminLoginUrl }}"  data-required-msg="{{ translate('admin_login_url_is_required') }}" required>
                                    <input type="hidden" class="form-control" name="type" value="admin_login_url">
                                </div>
                            </div>
                            <button type="submit" id="submit" class="btn btn-primary px-4 w-120 h-40">{{translate('save')}}</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        <form action="{{route('admin.system-setup.login-settings.login-url-setup')}}" method="post" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
            @csrf
            <div class="card">
                <div class="card-body">
                    <div class="mb-3 mb-sm-20">
                        <h2>{{ translate('Employee_Login_Page') }}</h2>
                        <p class="mb-0 fs-12">
                            {{ translate('access_the_employee_login_page_by_this_url') }}
                        </p>
                    </div>
                    <div class="p-12 p-sm-20 bg-section rounded">
                        <div class="d-flex flex-wrap gap-sm-20 gap-3 justify-content-end align-items-center">
                            <div class="flex-grow-1">
                                @php($employeeLoginUrl = getWebConfig('employee_login_url'))
                                <div class="input-group border rounded trans3">
                                    <span class="input-group-text custom flex-grow-1 flex-xl-grow-0">{{ url('/').'/login/' }}</span>
                                    <input type="text" class="form-control" name="url" value="{{ $employeeLoginUrl }}" data-required-msg="{{ translate('employee_login_url_is_required') }}" required>
                                    <input type="hidden" class="form-control" name="type" value="employee_login_url">
                                </div>
                            </div>
                            <button type="submit" id="submit" class="btn btn-primary px-4 w-120 h-40">{{translate('save')}}</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
@include("layouts.admin.partials.offcanvas._login-url")
@endsection
