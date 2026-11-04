@extends('layouts.admin.app')

@section('title', translate('edit_Role'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2 text-capitalize">
                <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/add-new-seller.png') }}" alt="">
                {{ translate('role_update') }}
            </h2>
        </div>
        <div class="card">
            <div class="card-body">
                <form id="submit-create-role" action="{{ route('admin.custom-role.update',[$role['id']]) }}"
                      method="post" class="text-start">
                    @csrf
                    <div class="row">
                        <div class="col-lg-6">
                            <input type="hidden" name="id" value="{{ $role['id'] }}">
                            <div class="form-group mb-4">
                                <label for="name" class="form-label">{{ translate('role_name') }}</label>
                                <input type="text" name="name" value="{{ $role['name'] }}" class="form-control"
                                       id="name"
                                       aria-describedby="emailHelp"
                                       placeholder="{{ translate('ex').':'.translate('store') }}">
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-4 flex-wrap mb-4">
                        <label for="module" class="form-label mb-0">{{ translate('module_permission').':'}}</label>
                        <div class="form-group d-flex gap-2">
                            <input type="checkbox" id="select-all-module"
                                   class="form-check-input checkbox--input checkbox--input_lg cursor-pointer">
                            <label class="form-check-label cursor-pointer text-capitalize"
                                   for="select-all-module">{{ translate('select_all') }}</label>
                        </div>
                    </div>

                    <div class="row gy-2 mb-4">
                        @foreach($employeeRolePermission as $employeeRoleKey => $employeeRole)
                            <div class="col-sm-6 col-lg-3">
                                <div class="form-group d-flex gap-2">
                                    <input type="checkbox" name="modules[]" value="{{ $employeeRoleKey }}"
                                           class="form-check-input checkbox--input module-permission"
                                           id="{{ $employeeRoleKey }}-permission"
                                        {{ in_array($employeeRoleKey, (array)json_decode($role['module_access'])) ? 'checked' : '' }}>
                                    <label class=""
                                           style="{{ session('direction') === "rtl" ? 'margin-right: 1.25rem;' : '' }};"
                                           for="{{ $employeeRoleKey }}-permission">
                                        {{ translate($employeeRole) }}
                                    </label>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    {{-- Monitoring is split finer than a single module because it exposes far more
                         than most screens: revenue as it happens, error messages quoting real data,
                         a log viewer, and settings that change how the system behaves. Holding the
                         System Settings module keeps the read-only view; the rest is deliberate. --}}
                    <h5 class="mb-2">{{ translate('monitoring') }}</h5>
                    <div class="row gy-2 mb-4">
                        @foreach (\App\Services\Monitoring\MonitoringPermissionService::all() as $monitoringKey => $monitoringLabel)
                            <div class="col-sm-6 col-lg-4">
                                <div class="form-group d-flex gap-2">
                                    <input type="checkbox" name="modules[]" value="{{ $monitoringKey }}"
                                           class="form-check-input checkbox--input module-permission"
                                           id="{{ $monitoringKey }}-permission"
                                        {{ in_array($monitoringKey, (array) json_decode($role['module_access'])) ? 'checked' : '' }}>
                                    <label class=""
                                           style="{{ session('direction') === "rtl" ? 'margin-right: 1.25rem;' : '' }};"
                                           for="{{ $monitoringKey }}-permission">
                                        {{ translate($monitoringLabel) }}
                                    </label>
                                </div>
                            </div>
                        @endforeach
                    </div>


                    {{-- The App Builder, split the same way and for the same reason: composing a
                         page is recoverable, publishing one changes what every customer sees. The
                         module grant carries everything except publishing, so an existing role
                         keeps exactly what it had. --}}
                    <h5 class="mb-2">{{ translate('app_builder') }}</h5>
                    <div class="row gy-2 mb-4">
                        @foreach (\App\Services\Theme\ThemePermissionService::all() as $themeKey => $themeLabel)
                            <div class="col-sm-6 col-lg-4">
                                <div class="form-group d-flex gap-2">
                                    <input type="checkbox" name="modules[]" value="{{ $themeKey }}"
                                           class="form-check-input checkbox--input module-permission"
                                           id="{{ $themeKey }}-permission"
                                        {{ in_array($themeKey, (array) json_decode($role['module_access'])) ? 'checked' : '' }}>
                                    <label class=""
                                           style="{{ session('direction') === "rtl" ? 'margin-right: 1.25rem;' : '' }};"
                                           for="{{ $themeKey }}-permission">
                                        {{ translate($themeLabel) }}
                                    </label>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <h5 class="mb-2">{{ translate('analytics') }}</h5>
                    <div class="row gy-2 mb-4">
                        @foreach (\App\Services\Analytics\AnalyticsPermissionService::all() as $analyticsKey => $analyticsLabel)
                            <div class="col-sm-6 col-lg-4">
                                <div class="form-group d-flex gap-2">
                                    <input type="checkbox" name="modules[]" value="{{ $analyticsKey }}"
                                           class="form-check-input checkbox--input module-permission"
                                           id="{{ $analyticsKey }}-permission"
                                        {{ in_array($analyticsKey, (array) json_decode($role['module_access'])) ? 'checked' : '' }}>
                                    <label class=""
                                           style="{{ session('direction') === "rtl" ? 'margin-right: 1.25rem;' : '' }};"
                                           for="{{ $analyticsKey }}-permission">
                                        {{ translate($analyticsLabel) }}
                                    </label>
                                </div>
                            </div>
                        @endforeach
                    </div>


                    {{-- The Developer Portal, split for the reason the console makes obvious:
                         reading what an endpoint does and firing it at this installation are not
                         the same act. The module grant carries the documentation; sending a request
                         and capturing a snapshot are asked for. --}}
                    <h5 class="mb-2">{{ translate('developer_portal') }}</h5>
                    <div class="row gy-2 mb-4">
                        @foreach (\App\Services\DeveloperPortal\DeveloperPortalPermissionService::all() as $developerKey => $developerLabel)
                            <div class="col-sm-6 col-lg-4">
                                <div class="form-group d-flex gap-2">
                                    <input type="checkbox" name="modules[]" value="{{ $developerKey }}"
                                           class="form-check-input checkbox--input module-permission"
                                           id="{{ $developerKey }}-permission"
                                        {{ in_array($developerKey, (array) json_decode($role['module_access'])) ? 'checked' : '' }}>
                                    <label class=""
                                           style="{{ session('direction') === "rtl" ? 'margin-right: 1.25rem;' : '' }};"
                                           for="{{ $developerKey }}-permission">
                                        {{ translate($developerLabel) }}
                                    </label>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="d-flex justify-content-end gap-3">
                        <button type="reset" class="btn btn-secondary">{{ translate('reset') }}</button>
                        <button type="submit" class="btn btn-primary">{{ translate('update') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/backend/admin/js/user-management/custom-role.js') }}"></script>
@endpush
